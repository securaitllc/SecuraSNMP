<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IspProviderRequest;
use App\Models\IspProvider;
use Illuminate\Http\JsonResponse;

class IspProviderController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            IspProvider::withCount('circuits')
                ->withCount(['circuits as circuits_down_count' => fn ($q) => $q->where('status', 'down')])
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * A NOC view of one ISP: the escalation contacts to call, a posture summary,
     * and the sites this provider serves with each site's circuits (status +
     * open ISP ticket). Powers the expandable ISP Providers rows.
     */
    public function overview(IspProvider $ispProvider): JsonResponse
    {
        $ispProvider->load([
            'circuits.site:id,name',
            'circuits.alerts' => fn ($q) => $q->whereNull('ended_at')->latest('started_at'),
        ]);

        $circuits = $ispProvider->circuits;

        // Circuits grouped by the site they land at, since a NOC reasons about an
        // ISP outage per location.
        $sites = $circuits->groupBy('site_id')->map(function ($group) {
            $first = $group->first();

            return [
                'site_id' => $first->site_id,
                'site_name' => optional($first->site)->name ?? '—',
                'circuits' => $group->map(fn ($c) => [
                    'id' => $c->id,
                    'circuit_id' => $c->circuit_id,
                    'circuit_type' => $c->circuit_type,
                    'monitored_ip' => $c->monitored_ip,
                    'status' => $c->status,
                    'ticket_number' => optional($c->alerts->first())->ticket_number,
                ])->values(),
            ];
        })->sortBy('site_name')->values();

        return response()->json([
            'summary' => [
                'circuits' => $circuits->count(),
                'circuits_down' => $circuits->where('status', 'down')->count(),
                'sites_served' => $circuits->pluck('site_id')->unique()->count(),
                'fiber' => $circuits->where('circuit_type', 'fiber')->count(),
                'cable' => $circuits->where('circuit_type', 'cable')->count(),
            ],
            'contact' => [
                'support_phone' => $ispProvider->support_phone,
                'account_rep_name' => $ispProvider->account_rep_name,
                'account_rep_mobile' => $ispProvider->account_rep_mobile,
                'account_rep_phone' => $ispProvider->account_rep_phone,
                'account_rep_email' => $ispProvider->account_rep_email,
            ],
            'sites' => $sites,
        ]);
    }

    public function store(IspProviderRequest $request): JsonResponse
    {
        return response()->json(IspProvider::create($request->validated()), 201);
    }

    public function update(IspProviderRequest $request, IspProvider $ispProvider): JsonResponse
    {
        $ispProvider->update($request->validated());

        return response()->json($ispProvider);
    }

    public function destroy(IspProvider $ispProvider): JsonResponse
    {
        $ispProvider->delete();

        return response()->json(null, 204);
    }
}
