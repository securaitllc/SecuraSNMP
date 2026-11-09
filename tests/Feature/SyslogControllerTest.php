<?php

namespace Tests\Feature;

use App\Models\SyslogMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyslogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function message(string $text): void
    {
        SyslogMessage::create(['source_ip' => '10.0.0.1', 'facility' => 1, 'severity' => 6, 'hostname' => 'sw1', 'message' => $text, 'received_at' => now()]);
    }

    /** INJ-01: a bare `%` is a literal, not a match-everything wildcard (full scan). */
    public function test_inj01_wildcard_search_term_does_not_match_every_row(): void
    {
        $this->message('link up on ge-0/0/1');
        $this->message('link down on ge-0/0/2');

        $rows = $this->actingAs(User::factory()->create())->getJson('/api/syslog?q=%25')->assertOk()->json();

        $this->assertCount(0, $rows, 'a literal % must not act as a wildcard');
    }

    /** INJ-02: a term with % or _ still finds the rows that literally contain it. */
    public function test_inj02_escaped_terms_still_match_literally(): void
    {
        $this->message('CPU at 50% on fw1');
        $this->message('CPU at 500 on fw1');
        $this->message('port ge_0 flapped');
        $this->message('port gex0 flapped');

        $user = User::factory()->create();

        $this->assertSame(['CPU at 50% on fw1'], array_column($this->actingAs($user)->getJson('/api/syslog?q='.urlencode('50%'))->json(), 'message'));
        $this->assertSame(['port ge_0 flapped'], array_column($this->actingAs($user)->getJson('/api/syslog?q=ge_0')->json(), 'message'));
    }
}
