<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolves a US street address to latitude/longitude. Tries the US Census
 * geocoder first (free, keyless, US-only); if it can't match — it often chokes
 * on a suite/unit line — falls back to OpenStreetMap Nominatim. Returns null
 * only when both fail, so callers degrade to manual entry.
 */
class GeocodingService
{
    private const CENSUS = 'https://geocoding.geo.census.gov/geocoder/locations/onelineaddress';
    private const NOMINATIM = 'https://nominatim.openstreetmap.org/search';

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        // Census matches better without a secondary unit ("Ste 1", "Apt 2", "#3").
        $clean = $this->stripUnit($address);

        return $this->viaCensus($clean)
            ?? $this->viaCensus($address)
            ?? $this->viaNominatim($clean)
            ?? $this->viaNominatim($address);
    }

    /** Drop suite/unit/apt/floor designators that trip up the Census matcher. */
    private function stripUnit(string $address): string
    {
        $address = preg_replace(
            '/,?\s*\b(ste|suite|unit|apt|apartment|bldg|building|fl|floor|rm|room|#)\b\.?\s*[\w-]*/i',
            '',
            $address
        );

        return trim(preg_replace('/\s{2,}/', ' ', $address));
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    private function viaCensus(string $address): ?array
    {
        try {
            $matches = Http::timeout(8)->get(self::CENSUS, [
                'address' => $address,
                'benchmark' => 'Public_AR_Current',
                'format' => 'json',
            ])->json('result.addressMatches') ?? [];

            // Census returns x = longitude, y = latitude.
            $coords = $matches[0]['coordinates'] ?? null;
            if (isset($coords['x'], $coords['y'])) {
                return ['latitude' => (float) $coords['y'], 'longitude' => (float) $coords['x']];
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    private function viaNominatim(string $address): ?array
    {
        try {
            $results = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Nodus-NMS/1.0 (network monitoring)'])
                ->get(self::NOMINATIM, [
                    'q' => $address,
                    'format' => 'json',
                    'limit' => 1,
                    'countrycodes' => 'us',
                ])->json() ?? [];

            $hit = $results[0] ?? null;
            if (isset($hit['lat'], $hit['lon'])) {
                return ['latitude' => (float) $hit['lat'], 'longitude' => (float) $hit['lon']];
            }
        } catch (Throwable) {
        }

        return null;
    }
}
