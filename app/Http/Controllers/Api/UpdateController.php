<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class UpdateController extends Controller
{
    /** The running application version (from the VERSION file baked into the image). */
    public function version(): JsonResponse
    {
        return response()->json([
            'version' => trim(@file_get_contents(base_path('VERSION'))) ?: 'unknown',
        ]);
    }

    public function check(): JsonResponse
    {
        $token = config('services.github.token');
        $repo = config('services.github.repo');
        $current = trim(@file_get_contents(base_path('VERSION'))) ?: 'unknown';

        // Update checking is optional — degrade gracefully (no 500s spamming the
        // console / update badge) when it isn't configured or GitHub is unreachable.
        if (! $token || ! $repo) {
            return response()->json(['current' => $current, 'latest' => $current, 'update_available' => false, 'configured' => false]);
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(8)
                ->get("https://api.github.com/repos/{$repo}/tags");
        } catch (\Throwable) {
            return response()->json(['current' => $current, 'latest' => $current, 'update_available' => false]);
        }

        if (! $response->successful()) {
            return response()->json(['current' => $current, 'latest' => $current, 'update_available' => false]);
        }

        $versions = collect($response->json())
            ->map(fn (array $tag) => ltrim($tag['name'], 'v'))
            ->filter(fn (string $version) => preg_match('/^\d+\.\d+\.\d+$/', $version))
            ->sort(fn ($a, $b) => version_compare($b, $a))
            ->values();

        $latest = $versions->first() ?? $current;

        return response()->json([
            'current' => $current,
            'latest' => $latest,
            'update_available' => version_compare($latest, $current, '>'),
        ]);
    }
}
