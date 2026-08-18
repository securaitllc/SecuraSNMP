<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Services\Flow\FlowClassifier;
use App\Services\Flow\FlowIngestor;
use Illuminate\Console\Command;

/**
 * Drains goflow2's decoded-flow file into classified FlowRecords.
 *
 * goflow2 (the sidecar) appends one JSON flow per line to a file on the shared volume.
 * Each pass reads everything appended since last time and truncates the file, so it
 * stays bounded. Atomic line-appends mean a truncate only ever drops whole lines (a
 * negligible loss on sampled data), never a partial record.
 */
class IngestFlows extends Command
{
    use RunsPollLoop;

    protected $signature = 'flows:ingest';

    protected $description = 'Ingest decoded NetFlow/sFlow records from the goflow2 sidecar into flow_records.';

    public function handle(): int
    {
        $file = (string) env('FLOW_GOFLOW_FILE', '/flows/flows.ndjson');
        $interval = max(2, (int) env('FLOW_INGEST_SECONDS', 5));
        $ingestor = new FlowIngestor(new FlowClassifier);

        $this->info("Flow ingest started, draining {$file} every {$interval}s.");

        $this->pollForever('flows', $interval, function () use ($file, $ingestor): void {
            $lines = $this->drain($file);
            if ($lines !== []) {
                $ingestor->ingest($lines);
            }
        });
    }

    /**
     * Read every line appended since last pass and truncate the file, under an
     * advisory lock. goflow2 appends (O_APPEND), so after truncate its next write
     * lands at the new EOF — the file never grows without bound.
     *
     * @return array<int, string>
     */
    private function drain(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }
        $fp = @fopen($file, 'c+');
        if (! $fp) {
            return [];
        }
        $content = '';
        try {
            if (! flock($fp, LOCK_EX)) {
                return [];
            }
            $content = (string) stream_get_contents($fp);
            ftruncate($fp, 0);
            rewind($fp);
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        return array_values(array_filter(explode("\n", trim($content)), fn ($l) => $l !== ''));
    }
}
