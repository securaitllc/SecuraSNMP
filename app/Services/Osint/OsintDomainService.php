<?php

namespace App\Services\Osint;

use App\Models\OsintIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Passive domain enrichment for a phishing investigation: WHOIS, DNS, TLS cert and
 * subdomains (crt.sh + certspotter CT, subfinder, and VirusTotal passive DNS for
 * wildcard-fronted hosts CT can't see). All local tools run through
 * Process with ARRAY args (no shell) on already-validated input; upstream calls go through
 * the Http client so tests can fake them. Read-only — it never touches the target.
 */
class OsintDomainService
{
    /** @var callable(array,int):string runs a command, returns stdout ('' on failure) */
    private $runner;

    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner ?? function (array $cmd, int $timeout): string {
            $p = new Process($cmd);
            $p->setTimeout($timeout);
            // Keep partial output on timeout: whois hands back the registrar + creation date
            // early, then the registrar referral hangs — killing it must not discard the data.
            try {
                $p->run();
            } catch (\Throwable $e) {
                // fall through to whatever was captured before the kill
            }

            return $p->getOutput();
        };
    }

    /** @return array structured enrichment for the host + its base domain */
    public function enrich(string $rawInput): array
    {
        $host = OsintValidator::domain($rawInput);
        $base = OsintValidator::baseDomain($rawInput);

        $vtKey = OsintIntegration::keyFor('virustotal');
        $whois = $this->whois($base);
        $dns = $this->dns($host);
        $subdomains = $this->subdomains($base, $vtKey);
        $tls = $this->tls($host);
        $risk = $this->score($whois, $dns, $subdomains);
        $sub_sources = $this->subSources;

        // The host IP to enrich (ipdata). A wildcard-only domain — e.g. a phishing
        // platform — has NO apex A record (only *.domain resolves), so dns.a is empty.
        // Fall back to a discovered subdomain (or www) so the Hosting panel still shows
        // where the domain is actually served instead of a misleading "add a key" prompt.
        $hosting_ip = $dns['a'][0] ?? null;
        if (! $hosting_ip) {
            // Prefer www (resolves via the wildcard), then any discovered subdomain that
            // ISN'T the apex itself (CT sources list the base domain among "subdomains").
            $probes = array_merge(
                ['www.'.$host],
                array_values(array_filter(
                    array_column($subdomains, 'name'),
                    fn ($n) => $n !== $host && $n !== $base
                ))
            );
            foreach ($probes as $probe) {
                if ($hosting_ip = ($this->dns($probe)['a'][0] ?? null)) {
                    break;
                }
            }
        }

        return compact('host', 'base', 'whois', 'dns', 'tls', 'subdomains', 'sub_sources', 'risk', 'hosting_ip');
    }

    /** Per-source status for subdomain enum, so the UI can say "crt.sh unavailable" honestly. */
    private array $subSources = [];

    private function whois(string $base): array
    {
        $out = ($this->runner)(['whois', $base], 10);
        $get = function (array $keys) use ($out): ?string {
            foreach (explode("\n", $out) as $line) {
                foreach ($keys as $k) {
                    if (stripos($line, $k) === 0 || preg_match('/^\s*'.preg_quote($k, '/').'\s*:/i', $line)) {
                        $v = trim(substr($line, strpos($line, ':') + 1));
                        if ($v !== '') {
                            return $v;
                        }
                    }
                }
            }

            return null;
        };
        $created = $get(['Creation Date', 'created', 'Registered On', 'Registration Time']);

        return [
            'registrar' => $get(['Registrar:', 'Registrar', 'Sponsoring Registrar']),
            'created' => $created,
            'created_days' => $this->daysSince($created),
            'nameservers' => $this->allValues($out, ['Name Server', 'nserver']),
        ];
    }

    private function dns(string $host): array
    {
        // Resolve via a PUBLIC resolver (@1.1.1.1), not the container's default. On a
        // secured network the local resolver often sinkholes/RPZ-blocks the very domains
        // OSINT investigates (e.g. a known phishing domain), returning empty A records +
        // internal NS — which then breaks host-IP enrichment. Public DNS returns the real
        // records regardless of the local security filter.
        $rec = fn (string $type) => array_values(array_filter(array_map('trim',
            explode("\n", ($this->runner)(['dig', '@1.1.1.1', '+short', $host, $type], 8)))));

        return [
            'a' => $rec('A'),
            'aaaa' => $rec('AAAA'),
            'mx' => $rec('MX'),
            'ns' => $rec('NS'),
            'txt' => $rec('TXT'),
        ];
    }

    /**
     * Subdomains from crt.sh + certspotter (CT), VirusTotal passive DNS, and the enum
     * sidecar (subfinder -all + dnsx). ALL run CONCURRENTLY via one HTTP pool so the block
     * costs the slowest single source, not their sum — the old sequential chain hit ~50s on
     * an established domain and blew the UI's 60s abort. Local subfinder is dropped: the enum
     * sidecar runs subfinder -all (keyed + bounded) far better, and it was the 15-20s hog.
     */
    private function subdomains(string $base, ?string $vtKey): array
    {
        $found = [];
        $add = function (string $name, string $src) use (&$found, $base) {
            $name = ltrim(strtolower(trim($name)), '*.');
            if ($name !== '' && str_ends_with($name, $base)) {
                $found[$name] = $found[$name] ?? $src;
            }
        };

        $enum = config('services.osint_enum');
        $enumToken = $enum['token'] ?: OsintIntegration::keyFor('osint_enum');
        $enumOn = ! empty($enum['url']) && ! empty($enumToken);

        $responses = Http::pool(function ($pool) use ($base, $vtKey, $enum, $enumToken, $enumOn) {
            $reqs = [
                $pool->as('crtsh')->timeout(8)->acceptJson()
                    ->get('https://crt.sh/', ['q' => '%.'.$base, 'output' => 'json']),
                $pool->as('certspotter')->timeout(8)->acceptJson()
                    ->get('https://api.certspotter.com/v1/issuances', ['domain' => $base, 'include_subdomains' => 'true', 'expand' => 'dns_names']),
            ];
            if ($vtKey) {
                $reqs[] = $pool->as('virustotal')->timeout(10)->acceptJson()->withHeaders(['x-apikey' => $vtKey])
                    ->get("https://www.virustotal.com/api/v3/domains/{$base}/subdomains", ['limit' => 40]);
            }
            if ($enumOn) {
                $reqs[] = $pool->as('enum')->timeout(20)->withHeaders(['x-enum-token' => (string) $enumToken])
                    ->post(rtrim($enum['url'], '/').'/enum', array_filter(['domain' => $base, 'keys' => array_filter(['virustotal' => $vtKey])]));
            }

            return $reqs;
        });

        $resp = fn (string $k) => ($responses[$k] ?? null) instanceof \Illuminate\Http\Client\Response ? $responses[$k] : null;

        // crt.sh (chronically flaky — certspotter is the CT fallback when it 502s).
        if (($r = $resp('crtsh')) && $r->ok() && is_array($r->json())) {
            foreach ($r->json() as $row) {
                foreach (preg_split('/\s+/', (string) ($row['name_value'] ?? '')) as $name) {
                    $add($name, 'crt.sh');
                }
            }
            $this->subSources['crt.sh'] = 'ok';
        } else {
            $this->subSources['crt.sh'] = 'unavailable'.(($r = $resp('crtsh')) ? ' ('.$r->status().')' : '');
        }

        // certspotter.
        if (($r = $resp('certspotter')) && $r->ok() && is_array($r->json())) {
            foreach ($r->json() as $row) {
                foreach ((array) ($row['dns_names'] ?? []) as $name) {
                    $add($name, 'certspotter');
                }
            }
            $this->subSources['certspotter'] = 'ok';
        } else {
            $this->subSources['certspotter'] = 'unavailable';
        }

        // VirusTotal passive DNS — the only source that sees subdomains hidden behind a
        // Cloudflare wildcard cert. Page 1 came from the pool; paginate the rest only when a
        // cursor is present (a domain with >40 subs — uncommon), bounded to keep it quick.
        if (! $vtKey) {
            $this->subSources['virustotal'] = 'no key';
        } elseif (($r = $resp('virustotal')) && $r->ok()) {
            foreach ((array) $r->json('data', []) as $row) {
                $add((string) ($row['id'] ?? ''), 'virustotal');
            }
            $this->subSources['virustotal'] = 'ok';
            $cursor = $r->json('meta.cursor');
            $pages = 1;
            while ($cursor && $pages < 3) {
                try {
                    $vt = Http::withHeaders(['x-apikey' => $vtKey])->timeout(8)->acceptJson()
                        ->get("https://www.virustotal.com/api/v3/domains/{$base}/subdomains", ['limit' => 40, 'cursor' => $cursor]);
                    if (! $vt->ok()) {
                        break;
                    }
                    foreach ((array) $vt->json('data', []) as $row) {
                        $add((string) ($row['id'] ?? ''), 'virustotal');
                    }
                    $cursor = $vt->json('meta.cursor');
                    $pages++;
                } catch (\Throwable $e) {
                    break;
                }
            }
        } else {
            $this->subSources['virustotal'] = 'unavailable';
        }

        // Dedicated enumeration sidecar (enum/ container).
        if (! $enumOn) {
            $this->subSources['enum'] = 'not configured';
        } elseif (($r = $resp('enum')) && $r->ok()) {
            foreach ((array) $r->json('subdomains', []) as $name) {
                $add((string) $name, 'enum');
            }
            $this->subSources['enum'] = 'ok';
        } else {
            $this->subSources['enum'] = 'unavailable';
        }

        ksort($found);

        return array_map(fn ($src, $name) => ['name' => $name, 'source' => $src], $found, array_keys($found));
    }

    private function tls(string $host): array
    {
        // openssl on already-validated host; connect:host:443 built from the validated value.
        $out = ($this->runner)(['openssl', 's_client', '-connect', $host.':443', '-servername', $host], 5);
        $issuer = preg_match('/issuer=.*?O\s*=\s*([^,\/\n]+)/', $out, $m) ? trim($m[1]) : null;
        $notBefore = preg_match('/NotBefore:\s*(.+)/', $out, $m2) ? trim($m2[1]) : null;

        return [
            'issuer' => $issuer,
            'not_before' => $notBefore,
            'has_cert' => str_contains($out, 'BEGIN CERTIFICATE'),
        ];
    }

    private function score(array $whois, array $dns, array $subdomains): array
    {
        $score = 0;
        $reasons = [];
        $age = $whois['created_days'];
        if ($age !== null && $age <= 7) {
            $score += 60;  // sub-week registration is a strong phishing signal
            $reasons[] = "Registered {$age} days ago";
        } elseif ($age !== null && $age <= 30) {
            $score += 45;
            $reasons[] = "Registered {$age} days ago";
        }
        $hasDmarc = collect($dns['txt'])->contains(fn ($t) => stripos($t, 'DMARC') !== false);
        if (! $hasDmarc) {
            $score += 10;
            $reasons[] = 'No DMARC record';
        }
        if (count($subdomains) >= 10) {
            $score += 15;
            $reasons[] = count($subdomains).' subdomains — possible kit';
        }
        $score = min(100, $score);
        $verdict = $score >= 70 ? 'malicious' : ($score >= 35 ? 'suspicious' : 'clean');

        return compact('score', 'verdict', 'reasons');
    }

    private function allValues(string $out, array $keys): array
    {
        $vals = [];
        foreach (explode("\n", $out) as $line) {
            foreach ($keys as $k) {
                if (preg_match('/^\s*'.preg_quote($k, '/').'\s*:\s*(.+)$/i', $line, $m)) {
                    $vals[strtolower(trim($m[1]))] = trim($m[1]);
                }
            }
        }

        return array_values($vals);
    }

    private function daysSince(?string $date): ?int
    {
        if (! $date) {
            return null;
        }
        try {
            return (int) now()->diffInDays(\Illuminate\Support\Carbon::parse($date), true);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
