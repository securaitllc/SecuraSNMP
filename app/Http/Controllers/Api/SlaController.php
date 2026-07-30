<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SlaReportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SlaController extends Controller
{
    private function hours(Request $request): int
    {
        return match ($request->query('range')) {
            '24h' => 24,
            '7d' => 168,
            '30d' => 720,
            default => 720,
        };
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'range' => $request->query('range', '30d'),
            'rows' => (new SlaReportService)->report($this->hours($request)),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = (new SlaReportService)->report($this->hours($request));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('SLA');

        $columns = ['Type', 'Name', 'Device / Site', 'Uptime %', 'Downtime (min)', 'Incidents', 'MTTR (min)'];
        foreach ($columns as $i => $column) {
            $sheet->setCellValueExplicit([$i + 1, 1], $column, DataType::TYPE_STRING);
        }
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);

        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit([1, $r], $row['type'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], $row['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], (string) $row['device'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) $row['uptime_pct'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([5, $r], (string) round($row['downtime_seconds'] / 60, 1), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([6, $r], (string) $row['incidents'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([7, $r], $row['mttr_seconds'] !== null ? (string) round($row['mttr_seconds'] / 60, 1) : '', DataType::TYPE_STRING);
            $r++;
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            'nodus-sla-report.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
