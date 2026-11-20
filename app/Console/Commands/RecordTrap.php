<?php

namespace App\Console\Commands;

use App\Services\TrapRecorder;
use Illuminate\Console\Command;

class RecordTrap extends Command
{
    protected $signature = 'traps:record';

    protected $description = 'Records one SNMP trap read from STDIN (invoked by snmptrapd traphandle).';

    public function handle(TrapRecorder $recorder): int
    {
        $raw = file_get_contents('php://stdin');

        if ($raw === false || trim($raw) === '') {
            return self::SUCCESS;
        }

        $recorder->record($raw);

        return self::SUCCESS;
    }
}
