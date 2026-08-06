<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Live reports. Availability is infrastructure-based (circuits / device
 * reachability / SD-WAN tunnels), NOT access-port interface flapping.
 */
class ReportController extends Controller
{
    /** The report catalogue for the picker: each type + its selectable fields. */
    public function catalog(): JsonResponse
    {
        $reports = [];
        foreach (ReportService::TYPES as $key => $label) {
            $reports[] = [
                'type' => $key,
                'label' => $label,
                'time_scoped' => ! in_array($key, ['device-inventory', 'circuit-contracts'], true), // snapshots, not windows
                'supports_role' => in_array($key, ['device-availability', 'device-inventory'], true),
                'fields' => ReportService::fieldsFor($key),
            ];
        }

        return response()->json(['reports' => $reports]);
    }

    public function generate(Request $request, string $type): JsonResponse
    {
        $this->assertType($type);
        [$start, $end] = $this->window($request);

        return response()->json(
            (new ReportService)->generate($type, $start, $end, $this->filters($request)) + [
                'from' => $start->toIso8601String(),
                'to' => $end->toIso8601String(),
            ]
        );
    }

    public function export(Request $request, string $type): StreamedResponse
    {
        $this->assertType($type);
        [$start, $end] = $this->window($request);
        $report = (new ReportService)->generate($type, $start, $end, $this->filters($request));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr(preg_replace('/[^A-Za-z0-9 ]/', '', $report['title']), 0, 31) ?: 'Report');

        foreach ($report['columns'] as $i => $col) {
            $sheet->setCellValueExplicit([$i + 1, 1], $col['label'], DataType::TYPE_STRING);
        }
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);

        $r = 2;
        foreach ($report['rows'] as $row) {
            foreach ($report['columns'] as $i => $col) {
                // Explicit string typing = CSV/XLSX formula-injection defence.
                $sheet->setCellValueExplicit([$i + 1, $r], (string) ($row[$col['key']] ?? ''), DataType::TYPE_STRING);
            }
            $r++;
        }
        foreach (range('A', $sheet->getHighestColumn()) as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $name = 'nodus-'.$type.'-'.now()->format('Ymd').'.xlsx';

        return response()->streamDownload(fn () => $writer->save('php://output'), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function assertType(string $type): void
    {
        abort_unless(array_key_exists($type, ReportService::TYPES), 404, 'Unknown report.');
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function window(Request $request): array
    {
        $end = $request->filled('to') ? CarbonImmutable::parse($request->query('to')) : CarbonImmutable::now();
        if ($request->filled('from')) {
            return [CarbonImmutable::parse($request->query('from')), $end];
        }
        $hours = match ($request->query('range')) {
            '24h' => 24, '7d' => 168, '90d' => 2160, default => 720, // 30d default
        };

        return [$end->subHours($hours), $end];
    }

    private function filters(Request $request): array
    {
        return array_filter([
            'site_id' => $request->query('site_id'),
            'role' => $request->query('role'),
            'fields' => $request->query('fields'),   // array of column keys
        ], fn ($v) => $v !== null && $v !== '');
    }
}
