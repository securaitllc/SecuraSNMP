<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MapSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The dashboard basemap's provider key. The key is never returned — the client
 * only learns whether one is set, and the last four characters to confirm which.
 */
class MapSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->present(MapSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Plain string + in: — never a DB enum (SQLite ignores them, MySQL 500s).
            'provider' => ['nullable', 'in:carto'],
            'api_key' => ['nullable', 'string', 'max:512'],
            'clear_key' => ['boolean'],
        ]);

        $setting = MapSetting::current();

        // A blank field means "keep the existing key", not "clear it" — otherwise
        // saving any other field would silently wipe the key and bring the watermark
        // back. Removing a key is therefore explicit: clear_key = true.
        //
        // NOTE the `?? ''`: Laravel's ConvertEmptyStringsToNull turns a submitted ""
        // into null before it reaches here, so comparing against '' only works when
        // the null is coalesced first.
        if ($request->boolean('clear_key')) {
            $data['api_key'] = null;
        } elseif (($data['api_key'] ?? '') === '') {
            unset($data['api_key']);
        }
        unset($data['clear_key']);

        $setting->update($data);

        return response()->json($this->present($setting->fresh()));
    }

    /**
     * Fetch one tile with the saved key and report whether it came back clean.
     *
     * CARTO answers 200 with a WATERMARKED tile when the key is missing or wrong,
     * so status alone proves nothing. The watermarked and clean tiles differ in
     * size, so compare the keyed fetch against a deliberately keyless one: if they
     * are byte-identical, the key is not being honoured.
     */
    public function test(): JsonResponse
    {
        $setting = MapSetting::current();
        $key = MapSetting::tileKey();

        if ($key === null) {
            return response()->json(['ok' => false, 'message' => 'No API key saved — tiles will carry the CARTO watermark.']);
        }

        $base = 'https://basemaps.cartocdn.com/rastertiles/dark_all/6/17/25.png';
        try {
            $keyed = Http::timeout(10)->get($base.'?key='.urlencode($key));
            $plain = Http::timeout(10)->get($base);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Could not reach CARTO from the server: '.$e->getMessage()]);
        }

        if (! $keyed->successful()) {
            return response()->json(['ok' => false, 'message' => 'CARTO rejected the request (HTTP '.$keyed->status().').']);
        }

        if ($plain->successful() && $keyed->body() === $plain->body()) {
            return response()->json([
                'ok' => false,
                'message' => 'CARTO returned the same tile as an unkeyed request — the key is not being accepted, so the watermark will remain.',
            ]);
        }

        return response()->json(['ok' => true, 'message' => 'Key accepted — CARTO returned a clean, unwatermarked tile.']);
    }

    /** Drop every cached tile so a key change takes effect immediately. */
    public function flush(): JsonResponse
    {
        // The cache is already namespaced by key fingerprint, so a rotation is picked
        // up on its own; this is the manual escape hatch for a stuck/poisoned cache.
        Cache::store('file')->flush();

        return response()->json(['ok' => true, 'message' => 'Tile cache cleared.']);
    }

    private function present(MapSetting $s): array
    {
        return [
            'provider' => $s->provider,
            'has_key' => $s->hasKey(),
            'masked_key' => $s->maskedKey(),
            'updated_at' => $s->updated_at,
        ];
    }
}
