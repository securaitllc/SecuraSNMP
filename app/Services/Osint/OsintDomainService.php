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

        $whois = $this->whois($base);
        $dns = $this->dns($host);
        $subdomains = $this->subdomains($base);
        $tls = $this->tls($host);
        $risk = $this->score($whois, $dns, $subdomains);
        $sub_sources = $this->subSources;

        return compact('host', 'base', 'whois', 'dns', 'tls', 'subdomains', 'sub_sources', 'risk');
    }

    /** Per-source status for subdomain enum, so the UI can say "crt.sh unavailable" honestly. */
    private array $subSources = [];

    private function whois(string $base): array
    {
        $out = ($this->runner)(['whois', $base], 20);
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
        $rec = fn (string $type) => array_values(array_filter(array_map('trim',
            explode("\n", ($this->runner)(['dig', '+short', $host, $type], 8)))));

        return [
            'a' => $rec('A'),
            'aaaa' => $rec('AAAA'),
            'mx' => $rec('MX'),
            'ns' => $rec('NS'),
            'txt' => $rec('TXT'),
        ];
    }

    /** Subdomains from crt.sh (CT logs) + subfinder, merged and de-duped. */
    private function subdomains(string $base): array
    {
        $found = [];
        $add = function (string $name, string $src) use (&$found, $base) {
            $name = ltrim(strtolower(trim($name)), '*.');
            if ($name !== '' && str_ends_with($name, $base)) {
                $found[$name] = $found[$name] ?? $src;
            }
        };

        // crt.sh has the fullest CT data but is chronically flaky (502s) — retry, then fall
        // back to certspotter so a crt.sh outage doesn't zero out subdomains silently.
        try {
            $resp = Http::retry(2, 800, throw: false)->timeout(20)->acceptJson()
                ->get('https://crt.sh/', ['q' => '%.'.$base, 'output' => 'json']);
            if ($resp->ok() && is_array($resp->json())) {
                foreach ($resp->json() as $row) {
                    foreach (preg_split('/\s+/', (string) ($row['name_value'] ?? '')) as $name) {
                        $add($name, 'crt.sh');
                    }
                }
                $this->subSources['crt.sh'] = 'ok';
            } else {
                $this->subSources['crt.sh'] = 'unavailable ('.$resp->status().')';
            }
        } catch (\Throwable $e) {
            $this->subSources['crt.sh'] = 'unavailable';
            Log::info('osint: crt.sh lookup failed', ['base' => $base]);
        }

        try {
            $cs = Http::retry(1, 500, throw: false)->timeout(15)->acceptJson()
                ->get('https://api.certspotter.com/v1/issuances', ['domain' => $base, 'include_subdomains' => 'true', 'expand' => 'dns_names']);
            if ($cs->ok() && is_array($cs->json())) {
                foreach ($cs->json() as $row) {
                    foreach ((array) ($row['dns_names'] ?? []) as $name) {
                        $add($name, 'certspotter');
                    }
                }
                $this->subSources['certspotter'] = 'ok';
            } else {
                $this->subSources['certspotter'] = 'unavailable ('.$cs->status().')';
            }
        } catch (\Throwable $e) {
            $this->subSources['certspotter'] = 'unavailable';
        }

        foreach (array_filter(array_map('trim', explode("\n", ($this->runner)(['subfinder', '-silent', '-d', $base], 15)))) as $name) {
            $add($name, 'subfinder');
        }

        // VirusTotal passive DNS — the ONLY source that sees subdomains hidden behind a
        // Cloudflare wildcard cert. crt.sh/certspotter/subfinder are Certificate-Transparency
        // based, so a phishing platform that fronts every victim subdomain with one *.domain
        // wildcard cert (e.g. nowsso.com) shows ZERO in CT but every subdomain in passive DNS.
        // Needs a configured VirusTotal key (Settings → VirusTotal); reported honestly when absent.
        $vtKey = OsintIntegration::keyFor('virustotal');
        if ($vtKey === null) {
            $this->subSources['virustotal'] = 'no key';
        } else {
            try {
                $params = ['limit' => 40];
                $pages = 0;
                do {
                    $vt = Http::withHeaders(['x-apikey' => $vtKey])->timeout(20)->acceptJson()
                        ->get("https://www.virustotal.com/api/v3/domains/{$base}/subdomains", $params);
                    if (! $vt->ok()) {
                        $this->subSources['virustotal'] = 'unavailable ('.$vt->status().')';
                        break;
                    }
                    foreach ((array) $vt->json('data', []) as $row) {
                        $add((string) ($row['id'] ?? ''), 'virustotal');
                    }
                    $this->subSources['virustotal'] = 'ok';
                    $cursor = $vt->json('meta.cursor');
                    $params['cursor'] = $cursor;
                    $pages++;
                } while ($cursor && $pages < 3); // bounded: up to 120 subdomains
            } catch (\Throwable $e) {
                $this->subSources['virustotal'] = 'unavailable';
            }
        }

        // Dedicated enumeration sidecar (enum/ container): subfinder -all (passive, 50+
        // sources — given the VT key so it aggregates VirusTotal too) + dnsx wildcard-
        // filtered brute-force. Optional: only when the URL is configured. Bounded; the
        // sidecar caps each tool internally, we cap the whole call here.
        $enum = config('services.osint_enum');
        // Token from env, else the 'osint_enum' provider key in Settings (so it can be
        // configured in the UI without an app-service YAML edit). No token → feature off.
        $enumToken = $enum['token'] ?: OsintIntegration::keyFor('osint_enum');
        if (empty($enum['url']) || empty($enumToken)) {
            $this->subSources['enum'] = 'not configured';
        } else {
            try {
                $resp = Http::withHeaders(['x-enum-token' => (string) $enumToken])
                    ->timeout(100)
                    ->post(rtrim($enum['url'], '/').'/enum', array_filter([
                        'domain' => $base,
                        'keys' => array_filter(['virustotal' => $vtKey]),
                    ]));
                if ($resp->ok()) {
                    foreach ((array) $resp->json('subdomains', []) as $name) {
                        $add((string) $name, 'enum');
                    }
                    $this->subSources['enum'] = 'ok';
                } else {
                    $this->subSources['enum'] = 'unavailable ('.$resp->status().')';
                }
            } catch (\Throwable $e) {
                $this->subSources['enum'] = 'unavailable';
            }
        }

        ksort($found);

        return array_map(fn ($src, $name) => ['name' => $name, 'source' => $src], $found, array_keys($found));
    }

    private function tls(string $host): array
    {
        // openssl on already-validated host; connect:host:443 built from the validated value.
        $out = ($this->runner)(['openssl', 's_client', '-connect', $host.':443', '-servername', $host], 10);
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
