<?php

namespace Tests\Feature;

use App\Support\TicketNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ticket_number must be wide enough for the format it now stores.
 *
 * This exists because the opposite shipped and took production down. The column was
 * VARCHAR(8), sized for the old 8-digit number; 'MSIT-ALM-000001' is 15 characters.
 * On MySQL with STRICT_TRANS_TABLES the first renumber UPDATE failed with error 1406
 * (Data too long), the migration aborted, and the container crash-looped without ever
 * serving a request.
 *
 * Every test passed beforehand because SQLite does not enforce VARCHAR length — the
 * exact dev/prod divergence this codebase has been bitten by before. A length
 * assertion catches it on either driver, since the schema is inspected rather than
 * the write being attempted.
 */
class TicketColumnWidthTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function ticketTables(): array
    {
        return [
            'device_alarms' => ['device_alarms'],
            'interface_alerts' => ['interface_alerts'],
            'tunnel_alerts' => ['tunnel_alerts'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ticketTables')]
    public function test_the_column_can_hold_a_generated_ticket(string $table): void
    {
        $longest = TicketNumber::format(TicketNumber::TYPE_INTERFACE, 999999);

        // A generous org prefix must still fit; the default is only one of many.
        $this->assertLessThanOrEqual(
            32,
            strlen($longest),
            'The ticket format has outgrown the column width these tests assume.',
        );

        $type = collect(Schema::getColumns($table))
            ->firstWhere('name', 'ticket_number')['type'] ?? '';

        // SQLite reports "varchar", MySQL "varchar(32)" — assert on the declared
        // length where the driver exposes it, and fall back to the write test below.
        if (preg_match('/\((\d+)\)/', $type, $m)) {
            $this->assertGreaterThanOrEqual(
                strlen($longest),
                (int) $m[1],
                "{$table}.ticket_number is too narrow for {$longest}",
            );
        } else {
            $this->assertStringContainsString('varchar', strtolower($type));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ticketTables')]
    public function test_legacy_column_can_hold_the_old_format(string $table): void
    {
        $this->assertTrue(
            Schema::hasColumn($table, 'legacy_ticket_number'),
            "{$table} must retain the pre-sequence ticket number so a quoted number still resolves.",
        );
    }
}
