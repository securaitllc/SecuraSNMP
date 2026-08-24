<?php

namespace App\Services\Osint;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Passive domain enrichment for a phishing investigation: WHOIS, DNS, TLS cert and
 * subdomains (crt.sh certificate-transparency + subfinder). All local tools run through
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
            try {
                $p->run();
            } catch (\Throwable $e) {
                return '';
            }

            return $p->isSuccessful() ? $p->getOutput() : '';
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

        return compact('host', 'base', 'whois', 'dns', 'tls', 'subdomains', 'risk');
    }

    private function whois(string $base): array
    {
        $out = ($this->runner)(['whois', $base], 15);
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

        try {
            $resp = Http::timeout(12)->acceptJson()->get('https://crt.sh/', ['q' => '%.'.$base, 'output' => 'json']);
            if ($resp->ok()) {
                foreach ($resp->json() ?? [] as $row) {
                    foreach (preg_split('/\s+/', (string) ($row['name_value'] ?? '')) as $name) {
                        $name = ltrim(strtolower(trim($name)), '*.');
                        if ($name !== '' && str_ends_with($name, $base)) {
                            $found[$name] = 'crt.sh';
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('osint: crt.sh lookup failed', ['base' => $base]);
        }

        foreach (array_filter(array_map('trim', explode("\n", ($this->runner)(['subfinder', '-silent', '-d', $base], 15)))) as $name) {
            $name = strtolower($name);
            if (str_ends_with($name, $base)) {
                $found[$name] = $found[$name] ?? 'subfinder';
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
