<?php

namespace Tests\Feature;

use App\Models\OsintCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OsintCaseControllerTest extends TestCase
{
    use RefreshDatabase;

    /** INJ-03: an IoC that starts with a formula trigger is exported as text, not a formula. */
    public function test_inj03_csv_export_neutralises_spreadsheet_formulas(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $case = OsintCase::create([
            'case_number' => OsintCase::nextCaseNumber(), 'title' => 'Phish', 'severity' => 'high',
            'status' => 'investigating', 'owner_id' => $user->id, 'mitre' => [],
        ]);
        $payloads = ['=HYPERLINK("http://x","click")', '+cmd|calc', '-2+3', '@SUM(A1)', "\tTAB", "\rCR"];
        foreach ($payloads as $v) {
            $case->iocs()->create(['type' => 'url', 'value' => $v, 'confidence' => 'high', 'source' => '=evil()', 'added_by' => $user->id]);
        }
        $case->iocs()->create(['type' => 'domain', 'value' => 'evil.example', 'confidence' => 'high', 'source' => 'whois', 'added_by' => $user->id]);

        $csv = $this->actingAs($user)->get("/api/osint/cases/{$case->id}/export")->assertOk()->streamedContent();
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        fgetcsv($fh, escape: '\\'); // header
        $rows = [];
        while (($r = fgetcsv($fh, escape: '\\')) !== false) {
            $rows[] = $r;
        }
        $this->assertCount(count($payloads) + 1, $rows);

        $cells = array_merge(...array_map(fn ($r) => [$r[1], $r[3]], $rows));
        foreach ($cells as $cell) {
            $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell, "cell would evaluate as a formula: $cell");
        }
        $this->assertContains("'=HYPERLINK(\"http://x\",\"click\")", $cells);
        $this->assertContains('evil.example', $cells, 'ordinary values are untouched');
    }
}
