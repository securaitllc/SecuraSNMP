<?php

namespace Tests\Unit;

use App\Support\MetricDownsampler;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class MetricDownsamplerTest extends TestCase
{
    private function series(int $n, callable $rt): Collection
    {
        return collect(range(0, $n - 1))->map(fn ($i) => (object) ['device_interface_id' => 1, 'response_time_ms' => $rt($i)]);
    }

    public function test_cap_leaves_small_series_untouched(): void
    {
        $s = $this->series(100, fn () => 5);
        $this->assertCount(100, MetricDownsampler::cap($s, 800));
    }

    public function test_cap_bounds_a_large_series(): void
    {
        $capped = MetricDownsampler::cap($this->series(5000, fn () => 5), 800);
        $this->assertLessThanOrEqual(900, $capped->count()); // ~cap + kept nulls
        $this->assertGreaterThan(400, $capped->count());
    }

    public function test_cap_keeps_every_null_so_outages_stay_exact(): void
    {
        // 3000 points, every 100th is a timeout (null) → 30 nulls, all must survive.
        $capped = MetricDownsampler::cap($this->series(3000, fn ($i) => $i % 100 === 0 ? null : 5), 500);
        $this->assertSame(30, $capped->filter(fn ($m) => $m->response_time_ms === null)->count());
    }

    public function test_decimate_caps_each_series_independently(): void
    {
        $a = collect(range(0, 1999))->map(fn ($i) => (object) ['device_interface_id' => 1, 'v' => $i]);
        $b = collect(range(0, 1999))->map(fn ($i) => (object) ['device_interface_id' => 2, 'v' => $i]);
        $out = MetricDownsampler::decimate($a->concat($b), 'device_interface_id', 400);
        $this->assertLessThanOrEqual(400, $out->where('device_interface_id', 1)->count());
        $this->assertLessThanOrEqual(400, $out->where('device_interface_id', 2)->count());
    }
}
