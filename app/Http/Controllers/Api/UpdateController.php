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
            // The latest shipped version is simply the VERSION file on the repo's
            // default branch — ONE request, always correct. The old approach listed
            // /tags, which GitHub paginates at 30: once the release history grew past
            // 30 tags the newest ones fell off page 1, so the check reported an old
            // version as "latest" and the app looked up-to-date when it wasn't.
            $response = Http::withToken($token)->acceptJson()->timeout(8)
                ->get("https://api.github.com/repos/{$repo}/contents/VERSION");
        } catch (\Throwable) {
            return response()->json(['current' => $current, 'latest' => $current, 'update_available' => false, 'configured' => true]);
        }

        if (! $response->successful()) {
            return response()->json(['current' => $current, 'latest' => $current, 'update_available' => false, 'configured' => true]);
        }

        // Contents API returns the file base64-encoded (with newlines base64_decode ignores).
        $latest = trim(base64_decode((string) $response->json('content')));
        if (! preg_match('/^\d+\.\d+\.\d+$/', $latest)) {
            $latest = $current;
        }

        return response()->json([
            'current' => $current,
            'latest' => $latest,
            'update_available' => version_compare($latest, $current, '>'),
            'configured' => true,
        ]);
    }
}
