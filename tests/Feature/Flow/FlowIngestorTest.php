<?php

namespace Tests\Feature\Flow;

use App\Models\Device;
use App\Models\FlowRecord;
use App\Models\Site;
use App\Services\Flow\FlowClassifier;
use App\Services\Flow\FlowIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowIngestorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_classifies_and_scales_a_goflow2_line(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'ip_address' => '10.86.0.1']);

        $line = json_encode([
            'sampler_address' => '10.86.0.1', 'in_if' => 7,
            'src_addr' => '10.86.10.42', 'dst_addr' => '52.96.7.44',
            'src_port' => 51000, 'dst_port' => 443, 'proto' => 6,
            'bytes' => 1000, 'packets' => 10, 'sampling_rate' => 1000,
            'time_flow_start_ns' => 1787000000_000000000,
        ]);

        $n = (new FlowIngestor(new FlowClassifier))->ingest([$line]);

        $this->assertSame(1, $n);
        $f = FlowRecord::first();
        $this->assertSame($device->id, $f->device_id, 'exporter matched by sampler_address');
        $this->assertSame(7, $f->if_index);
        $this->assertSame('tcp', $f->protocol);
        $this->assertSame('Microsoft 365', $f->app);
        $this->assertSame('outbound', $f->direction, 'private src → public dst');
        $this->assertSame(1_000_000, $f->bytes, 'bytes scaled by sampling_rate 1000');
        $this->assertSame(10_000, $f->packets);
    }

    public function test_it_skips_malformed_lines_and_east_west_direction(): void
    {
        $lines = [
            'not json',
            json_encode(['src_addr' => '10.1.1.1']),                 // missing dst → skipped
            json_encode(['src_addr' => '10.86.10.1', 'dst_addr' => '10.86.10.2', 'proto' => 17, 'bytes' => 5, 'packets' => 1]),
        ];

        $n = (new FlowIngestor(new FlowClassifier))->ingest($lines);

        $this->assertSame(1, $n, 'only the one complete flow is ingested');
        $this->assertSame('east-west', FlowRecord::first()->direction, 'private → private is LAN');
    }
}
