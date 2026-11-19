<?php

use App\Models\Circuit;
use App\Models\CircuitIspTicket;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Give the tickets that already exist a row in the trail.
     *
     * The trail started recording when it was built. Every ticket entered before that
     * lives only in circuits.isp_ticket — still the current number, still open with the
     * carrier, and absent from the history list and the ISP Tickets report, which is
     * exactly the "no track of it" this was meant to fix. On Massey that is four
     * circuits, one of them #037's DIA.
     *
     * What is NOT known for these is who raised them and when: nothing recorded it. The
     * row says so rather than inventing an operator or a date — opened_by stays null and
     * the reason names the backfill, so nobody reads a guess as a fact. The opened_at
     * has to be something, and the circuit's own updated_at is the closest defensible
     * marker for when the number was last written.
     */
    public function up(): void
    {
        Circuit::whereNotNull('isp_ticket')->where('isp_ticket', '!=', '')
            ->get(['id', 'isp_ticket', 'updated_at'])
            ->each(function (Circuit $circuit) {
                $exists = CircuitIspTicket::where('circuit_id', $circuit->id)
                    ->where('ticket_number', $circuit->isp_ticket)
                    ->exists();

                if ($exists) {
                    return;
                }

                CircuitIspTicket::create([
                    'circuit_id' => $circuit->id,
                    'ticket_number' => $circuit->isp_ticket,
                    'reason' => 'Recorded before ticket history existed — origin not captured',
                    'opened_at' => $circuit->updated_at ?? now(),
                    'opened_by' => null,
                ]);
            });
    }

    public function down(): void
    {
        CircuitIspTicket::whereNull('opened_by')
            ->where('reason', 'Recorded before ticket history existed — origin not captured')
            ->delete();
    }
};
