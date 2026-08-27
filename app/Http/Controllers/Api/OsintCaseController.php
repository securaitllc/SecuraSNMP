<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OsintCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OsintCaseController extends Controller
{
    public function index(): JsonResponse
    {
        $cases = OsintCase::withCount('iocs')->with('owner:id,name')->latest()->get();

        return response()->json(['data' => $cases]);
    }

    public function show(OsintCase $case): JsonResponse
    {
        $case->load(['iocs' => fn ($q) => $q->latest(), 'owner:id,name']);

        return response()->json($case);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'severity' => ['required', 'in:low,medium,high,critical'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'mitre' => ['nullable', 'array'],
            'mitre.*' => ['string', 'max:20'],
            'iocs' => ['array'],
            'iocs.*.type' => ['required', 'in:domain,host,ip,url,email,phone,asn,cert,hash'],
            'iocs.*.value' => ['required', 'string', 'max:255'],
            'iocs.*.confidence' => ['nullable', 'in:low,medium,high'],
            'iocs.*.source' => ['nullable', 'string', 'max:60'],
        ]);

        $case = OsintCase::create([
            'case_number' => OsintCase::nextCaseNumber(),
            'title' => $data['title'],
            'severity' => $data['severity'],
            'status' => 'investigating',
            'summary' => $data['summary'] ?? null,
            'mitre' => $data['mitre'] ?? [],
            'owner_id' => $request->user()->id,
        ]);

        foreach ($data['iocs'] ?? [] as $ioc) {
            $case->iocs()->firstOrCreate(
                ['type' => $ioc['type'], 'value' => $ioc['value']],
                ['confidence' => $ioc['confidence'] ?? 'medium', 'source' => $ioc['source'] ?? null,
                    'first_seen' => now(), 'added_by' => $request->user()->id],
            );
        }

        return response()->json($case->load('iocs'), 201);
    }

    public function addIoc(Request $request, OsintCase $case): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:domain,host,ip,url,email,phone,asn,cert,hash'],
            'value' => ['required', 'string', 'max:255'],
            'confidence' => ['nullable', 'in:low,medium,high'],
            'source' => ['nullable', 'string', 'max:60'],
        ]);
        $ioc = $case->iocs()->firstOrCreate(
            ['type' => $data['type'], 'value' => $data['value']],
            ['confidence' => $data['confidence'] ?? 'medium', 'source' => $data['source'] ?? 'manual',
                'first_seen' => now(), 'added_by' => $request->user()->id],
        );

        return response()->json($ioc, 201);
    }

    public function updateStatus(Request $request, OsintCase $case): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,investigating,contained,closed'],
            'severity' => ['nullable', 'in:low,medium,high,critical'],
        ]);
        $case->status = $data['status'];
        if (isset($data['severity'])) {
            $case->severity = $data['severity'];
        }
        $case->closed_at = $data['status'] === 'closed' ? now() : null;
        $case->save();

        return response()->json($case);
    }

    /** Export the case IoCs as CSV (?format=csv, default) or a STIX 2.1 bundle (?format=stix). */
    public function export(Request $request, OsintCase $case): StreamedResponse|JsonResponse
    {
        $case->load('iocs');
        if ($request->query('format') === 'stix') {
            return response()->json($this->stixBundle($case));
        }

        $name = "{$case->case_number}-iocs.csv";

        return response()->streamDownload(function () use ($case) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['type', 'value', 'confidence', 'source', 'first_seen']);
            foreach ($case->iocs as $i) {
                fputcsv($out, [$i->type, $i->value, $i->confidence, $i->source, optional($i->first_seen)->toIso8601String()]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    private function stixBundle(OsintCase $case): array
    {
        $pattern = [
            'domain' => "[domain-name:value = '%s']",
            'host' => "[domain-name:value = '%s']",
            'ip' => "[ipv4-addr:value = '%s']",
            'url' => "[url:value = '%s']",
            'email' => "[email-addr:value = '%s']",
            'phone' => "[x-phone:value = '%s']",
        ];
        $objects = [];
        foreach ($case->iocs as $i) {
            $tpl = $pattern[$i->type] ?? "[x-ioc:value = '%s']";
            $objects[] = [
                'type' => 'indicator',
                'spec_version' => '2.1',
                'id' => 'indicator--'.\Illuminate\Support\Str::uuid(),
                'created' => optional($i->first_seen ?? $i->created_at)->toIso8601ZuluString(),
                'name' => "{$i->type}: {$i->value}",
                'pattern' => sprintf($tpl, addslashes($i->value)),
                'pattern_type' => 'stix',
                'confidence' => ['low' => 15, 'medium' => 50, 'high' => 85][$i->confidence] ?? 50,
            ];
        }

        return ['type' => 'bundle', 'id' => 'bundle--'.\Illuminate\Support\Str::uuid(), 'objects' => $objects];
    }
}
