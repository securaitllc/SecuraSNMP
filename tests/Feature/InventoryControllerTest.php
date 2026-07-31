<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class InventoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private function loadExport(User $user)
    {
        $response = $this->actingAs($user)->get('/api/inventory/export');
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('content-type'));

        $tmp = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);

        return $sheet;
    }

    public function test_viewer_can_export_the_inventory_as_xlsx(): void
    {
        $site = Site::factory()->create(['name' => 'HQ Orlando', 'address' => '3210 Clay Ave, Orlando']);
        Device::factory()->create([
            'site_id' => $site->id,
            'name' => 'core-sw01',
            'vendor' => 'juniper',
            'model' => 'EX3400',
            'os_version' => '20.4R3-S4.8',
            'ip_address' => '10.10.1.1',
            'serial_number' => 'JN1234ABCD',
            'status' => 'active',
        ]);

        $sheet = $this->loadExport(User::factory()->create());

        $this->assertSame('Site Name', $sheet->getCell('A1')->getValue());
        $this->assertSame('OS Version', $sheet->getCell('F1')->getValue());
        $this->assertSame('Serial Number', $sheet->getCell('H1')->getValue());
        $this->assertSame('HQ Orlando', $sheet->getCell('A2')->getValue());
        $this->assertSame('core-sw01', $sheet->getCell('C2')->getValue());
        $this->assertSame('EX3400', $sheet->getCell('E2')->getValue());
        $this->assertSame('20.4R3-S4.8', $sheet->getCell('F2')->getValue());
        $this->assertSame('JN1234ABCD', $sheet->getCell('H2')->getValue());
    }

    public function test_formula_injection_is_stored_as_text_not_a_formula(): void
    {
        $site = Site::factory()->create(['name' => '=HYPERLINK("http://evil","x")']);
        Device::factory()->create(['site_id' => $site->id, 'name' => '+SUM(A1:A9)']);

        $sheet = $this->loadExport(User::factory()->create());

        // The value is preserved literally and typed as a string, so a
        // spreadsheet never evaluates it as a formula.
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        $this->assertSame('=HYPERLINK("http://evil","x")', $sheet->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C2')->getDataType());
    }

    public function test_guest_cannot_export_the_inventory(): void
    {
        $this->getJson('/api/inventory/export')->assertStatus(401);
    }
}
