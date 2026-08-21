<?php

namespace Tests\Unit\Flow;

use App\Services\Flow\KqlFlowQuery;
use InvalidArgumentException;
use Tests\TestCase;

class KqlFlowQueryTest extends TestCase
{
    private KqlFlowQuery $kql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kql = new KqlFlowQuery;
    }

    public function test_parses_a_full_where_predicate(): void
    {
        $clauses = $this->kql->parseWhere('Flows | where SrcIP in (cidr("10.86.10.0/24")) and Port == 443 and App == "Microsoft 365" and Bytes > 1M');

        $this->assertCount(4, $clauses);
        $this->assertSame('src_ip', $clauses[0]['field']);
        $this->assertSame(['__cidr' => '10.86.10.0/24'], $clauses[0]['value']);
        $this->assertSame('__port', $clauses[1]['field']);
        $this->assertSame(443, $clauses[1]['value']);
        $this->assertSame('app', $clauses[2]['field']);
        $this->assertSame('Microsoft 365', $clauses[2]['value']);
        $this->assertSame('bytes', $clauses[3]['field']);
        $this->assertSame(1_000_000, $clauses[3]['value'], 'the 1M suffix expands');
    }

    public function test_parses_barewords_and_in_lists(): void
    {
        $clauses = $this->kql->parseWhere('Direction == outbound and Protocol in (tcp, udp)');
        $this->assertSame('outbound', $clauses[0]['value']);
        $this->assertSame(['tcp', 'udp'], $clauses[1]['value']);
    }

    public function test_an_unknown_field_or_operator_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->kql->parseWhere('Nonsense == "x"');
    }

    public function test_parses_the_summarize_top_pipeline(): void
    {
        $p = $this->kql->parsePipeline('Flows | summarize sum(Bytes) by App | top 10 by Bytes');
        $this->assertSame('app', $p['by']);
        $this->assertSame('bytes', $p['metric']);
        $this->assertSame(10, $p['top']);

        $this->assertNull($this->kql->parsePipeline('where App == "DNS"'), 'a plain filter has no pipeline');
    }
}
