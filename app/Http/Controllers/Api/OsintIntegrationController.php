<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OsintIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class OsintIntegrationController extends Controller
{
    /** Providers the tool knows about — the panel renders one row per entry. */
    public const PROVIDERS = [
        'ipdata' => ['label' => 'ipdata.com', 'purpose' => 'IP geolocation, ASN, proxy/VPN/threat flags', 'needs_key' => true],
        'virustotal' => ['label' => 'VirusTotal', 'purpose' => 'domain / IP / URL reputation', 'needs_key' => false],
        'phone' => ['label' => 'Reverse phone', 'purpose' => 'line type, carrier, fraud score', 'needs_key' => true],
        'urlscan' => ['label' => 'urlscan.io', 'purpose' => 'private URL scans', 'needs_key' => false],
    ];

    public function index(): JsonResponse
    {
        $rows = OsintIntegration::all()->keyBy('provider');
        $out = [];
        foreach (self::PROVIDERS as $key => $meta) {
            $row = $rows->get($key);
            $out[] = array_merge(['provider' => $key], $meta, [
                'configured' => (bool) ($row?->hasKey()),
                'masked' => $row?->maskedKey(),
                'enabled' => (bool) ($row?->enabled ?? true),
                'meta' => $row?->meta,
            ]);
        }

        return response()->json(['data' => $out]);
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        abort_unless(array_key_exists($provider, self::PROVIDERS), 404);
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:512'],
            'enabled' => ['boolean'],
            'meta' => ['nullable', 'array'],
        ]);

        $row = OsintIntegration::firstOrNew(['provider' => $provider]);
        // Empty string clears the key; absent leaves it as-is.
        if ($request->exists('api_key')) {
            $row->api_key = $data['api_key'] !== '' ? $data['api_key'] : null;
        }
        $row->enabled = $data['enabled'] ?? $row->enabled ?? true;
        $row->meta = $data['meta'] ?? $row->meta;
        $row->updated_by = $request->user()->id;
        $row->save();

        return response()->json(['provider' => $provider, 'configured' => $row->hasKey(), 'masked' => $row->maskedKey()]);
    }

    /** Live-validate a key against the provider (uses the posted key, else the stored one). */
    public function test(Request $request, string $provider): JsonResponse
    {
        abort_unless(array_key_exists($provider, self::PROVIDERS), 404);
        $key = $request->input('api_key') ?: OsintIntegration::keyFor($provider);
        if (! $key) {
            return response()->json(['ok' => false, 'message' => 'No key to test.']);
        }

        try {
            [$ok, $message] = match ($provider) {
                'ipdata' => $this->probe(Http::timeout(8)->get('https://api.ipdata.co/8.8.8.8', ['api-key' => $key]), 'ipdata'),
                'virustotal' => $this->probe(Http::timeout(8)->withHeaders(['x-apikey' => $key])->get('https://www.virustotal.com/api/v3/domains/google.com'), 'VirusTotal'),
                'urlscan' => $this->probe(Http::timeout(8)->withHeaders(['API-Key' => $key])->get('https://urlscan.io/user/quota/'), 'urlscan'),
                'phone' => $this->probe(Http::timeout(10)->get("https://ipqualityscore.com/api/json/phone/{$key}/18005551212"), 'phone provider'),
                default => [false, 'Unknown provider'],
            };
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Request failed — check the key and network.']);
        }

        return response()->json(['ok' => $ok, 'message' => $message]);
    }

    private function probe($resp, string $name): array
    {
        if ($resp->status() === 401 || $resp->status() === 403) {
            return [false, "{$name}: key rejected."];
        }
        // IPQS returns 200 with success:false on a bad key.
        if ($name === 'phone' && ($resp->json('success') === false) && str_contains((string) $resp->json('message'), 'key')) {
            return [false, 'phone provider: key rejected.'];
        }

        return $resp->ok() ? [true, "{$name}: key valid."] : [false, "{$name}: unexpected {$resp->status()}."];
    }
}
