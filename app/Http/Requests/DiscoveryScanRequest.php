<?php

namespace App\Http\Requests;

use App\Services\DiscoveryScanner;
use App\Support\Roles;
use Illuminate\Foundation\Http\FormRequest;

class DiscoveryScanRequest extends FormRequest
{
    /** Widest prefix a scan may sweep — a /16 is already 65,536 addresses. */
    private const MIN_PREFIX = 16;

    /** Ranges that never hold a managed device, refused for everyone (SSRF-03). */
    private const NEVER = [
        '0.0.0.0/8',          // "this" network
        '127.0.0.0/8',        // loopback — the app's own container
        '169.254.0.0/16',     // link-local, incl. the 169.254.169.254 cloud metadata endpoint
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved + 255.255.255.255 broadcast
    ];

    /** Private / shared address space a NOC actually scans. Anything else is public. */
    private const PRIVATE = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'snmp_credential_id' => ['required', 'exists:snmp_credentials,id'],
            'subnets' => ['required', 'array', 'min:1', function ($attribute, $value, $fail) {
                // The whole scan, not each prefix, is bounded (MISC-07): two /16s are
                // still an internet-scale sweep.
                $total = 0;
                foreach ((array) $value as $cidr) {
                    if (is_string($cidr) && self::isCidr($cidr)) {
                        $total += DiscoveryScanner::countHosts([$cidr]);
                    }
                }
                if ($total > DiscoveryScanner::MAX_HOSTS) {
                    $fail('A scan may cover at most '.number_format(DiscoveryScanner::MAX_HOSTS).' addresses in total.');
                }
            }],
            'subnets.*' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! self::isCidr($value)) {
                    $fail("The {$attribute} field must be a valid IPv4 CIDR (e.g. 10.15.0.0/22).");

                    return;
                }

                [$network, $prefix] = explode('/', $value, 2);

                if ((int) $prefix < self::MIN_PREFIX) {
                    $fail("The {$attribute} field may not be wider than a /".self::MIN_PREFIX.'.');

                    return;
                }

                foreach (self::NEVER as $block) {
                    if (self::inBlock($network, $block)) {
                        $fail("The {$attribute} field is a reserved range that cannot hold a device.");

                        return;
                    }
                }

                // Public space is an opt-in for super_admin only — `allow_public` is
                // read here, not declared as a rule, so it never reaches validated()
                // and the scan row.
                $isPrivate = false;
                foreach (self::PRIVATE as $block) {
                    if (self::inBlock($network, $block)) {
                        $isPrivate = true;
                        break;
                    }
                }
                if (! $isPrivate) {
                    $optedIn = $this->boolean('allow_public')
                        && Roles::rank($this->user()?->role) >= Roles::rank('super_admin');
                    if (! $optedIn) {
                        $fail("The {$attribute} field is a public range. Only a super_admin may scan it, with allow_public set.");
                    }
                }
            }],
        ];
    }

    private static function isCidr(string $value): bool
    {
        if (! str_contains($value, '/')) {
            return false;
        }

        [$network, $prefix] = explode('/', $value, 2);

        return filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && ctype_digit($prefix)
            && (int) $prefix >= 0
            && (int) $prefix <= 32;
    }

    /** Whether an IPv4 address falls inside a CIDR block. */
    private static function inBlock(string $ip, string $block): bool
    {
        [$net, $bits] = explode('/', $block, 2);
        $mask = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

        return ((ip2long($ip) & $mask) === (ip2long($net) & $mask));
    }
}
