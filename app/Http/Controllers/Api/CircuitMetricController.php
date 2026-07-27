<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CircuitMetricHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CircuitMetricController extends Controller
{
    private const RANGE_HOURS = [
        '1h' => 1,
        '6h' => 6,
        '24h' => 24,
        '7d' => 168,
        '30d' => 720,
    ];

    /** Points kept per circuit sparkline — enough to read a trend, small enough to scale. */
    private const SPARK_POINTS = 40;

    /** Window the sparkline query covers. */
    private const SPARK_MINUTES = 60;

    public function index(Request $request): JsonResponse
    {
        // circuit_id is REQUIRED. Unscoped this returns every circuit's history for
        // the range; it only looks survivable today because a poller outage wiped
        // the table, and it grows back with retention. The table view uses
        // summary(); this endpoint graphs one circuit.
        $request->validate(['circuit_id' => ['required', 'integer', 'exists:circuits,id']]);

        $hours = self::RANGE_HOURS[$request->query('range', '24h')] ?? self::RANGE_HOURS['24h'];

        $metrics = CircuitMetricHistory::query()
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->where('circuit_id', $request->query('circuit_id'))
            ->orderBy('recorded_at')
            ->get();

        return response()->json($metrics);
    }

    /**
     * Every circuit's sparkline in ONE request, keyed by circuit id.
     *
     * The circuits page used to call index() once per circuit. At 100 rows that is
     * 100 parallel requests and 100 result sets for a 72px graph, which was enough
     * to hang the browser tab. This mirrors the device summary endpoint, which
     * exists for exactly the same reason.
     *
     * Bounded on both axes deliberately: a 60-minute window so the query does not
     * grow with retention, and a per-circuit point cap so a chatty circuit cannot
     * bloat the payload for everyone.
     */
    public function summary(): JsonResponse
    {
        $rows = CircuitMetricHistory::where('recorded_at', '>=', now()->subMinutes(self::SPARK_MINUTES))
            ->orderBy('recorded_at')
            ->get(['circuit_id', 'response_time_ms']);

        $byCircuit = [];
        foreach ($rows as $row) {
            $byCircuit[$row->circuit_id][] = $row->response_time_ms;
        }

        $out = [];
        foreach ($byCircuit as $id => $points) {
            $points = array_slice($points, -self::SPARK_POINTS);
            // end() on an all-null tail is still null, which the UI renders as
            // "no reading" rather than a bogus 0 ms.
            $out[$id] = ['points' => $points, 'latest' => end($points)];
        }

        return response()->json($out);
    }
}
