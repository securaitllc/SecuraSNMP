<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    public function export(): StreamedResponse
    {
        $devices = Device::with('site')->orderBy('name')->get();

        $columns = ['Site Name', 'Site Address', 'Device Name', 'Vendor', 'Model', 'OS Version', 'IP Address', 'Serial Number', 'Admin Status'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Inventory');

        foreach ($columns as $i => $column) {
            $sheet->setCellValueExplicit([$i + 1, 1], $column, DataType::TYPE_STRING);
        }
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);

        $row = 2;
        foreach ($devices as $device) {
            $values = [
                optional($device->site)->name,
                optional($device->site)->address,
                $device->name,
                $device->vendor,
                $device->model,
                $device->os_version,
                $device->ip_address,
                $device->serial_number,
                $device->status,
            ];
            foreach ($values as $i => $value) {
                // Explicit string typing prevents a value like "=cmd" from being
                // treated as a live formula (CSV/XLSX formula-injection defense).
                $sheet->setCellValueExplicit([$i + 1, $row], (string) ($value ?? ''), DataType::TYPE_STRING);
            }
            $row++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'nodus-inventory.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
