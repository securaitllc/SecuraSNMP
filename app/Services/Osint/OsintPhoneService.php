<?php

namespace App\Services\Osint;

use App\Models\OsintIntegration;
use Illuminate\Support\Facades\Http;

/**
 * Reverse phone lookup for smishing/vishing numbers via IPQualityScore (default) — line
 * type + VoIP flag, carrier, fraud score, active/recent-abuse. Needs a provider key.
 */
class OsintPhoneService
{
    public function lookup(string $rawPhone): array
    {
        $phone = OsintValidator::phone($rawPhone);
        $key = OsintIntegration::keyFor('phone');
        if (! $key) {
            return ['phone' => $phone, 'configured' => false];
        }

        $digits = ltrim($phone, '+');
        try {
            $r = Http::timeout(12)->acceptJson()->get("https://ipqualityscore.com/api/json/phone/{$key}/{$digits}");
        } catch (\Throwable $e) {
            return ['phone' => $phone, 'configured' => true, 'error' => 'phone provider request failed'];
        }
        if (! $r->ok()) {
            return ['phone' => $phone, 'configured' => true, 'error' => "provider {$r->status()}"];
        }
        $d = $r->json();
        $fraud = (int) ($d['fraud_score'] ?? 0);

        return [
            'phone' => $phone,
            'configured' => true,
            'valid' => (bool) ($d['valid'] ?? false),
            'line_type' => $d['line_type'] ?? null,
            'is_voip' => ($d['line_type'] ?? null) === 'VOIP' || (bool) ($d['VOIP'] ?? false),
            'carrier' => $d['carrier'] ?? null,
            'country' => $d['country'] ?? null,
            'region' => $d['region'] ?? null,
            'active' => $d['active'] ?? null,
            'recent_abuse' => (bool) ($d['recent_abuse'] ?? false),
            'fraud_score' => $fraud,
            'verdict' => $fraud >= 75 ? 'malicious' : ($fraud >= 40 ? 'suspicious' : 'clean'),
        ];
    }
}
