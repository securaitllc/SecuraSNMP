<?php

namespace Tests\Unit;

use App\Models\SyslogMessage;
use PHPUnit\Framework\TestCase;

class SyslogParseTest extends TestCase
{
    public function test_parses_priority_host_and_message(): void
    {
        $p = SyslogMessage::parse('<134>Jul 21 10:00:00 sw1 interface ge-0/0/0 down');

        $this->assertSame(16, $p['facility']); // 134 >> 3
        $this->assertSame(6, $p['severity']);  // 134 & 7 = info
        $this->assertSame('sw1', $p['hostname']);
        $this->assertSame('interface ge-0/0/0 down', $p['message']);
    }

    public function test_message_without_pri_is_kept_verbatim(): void
    {
        $p = SyslogMessage::parse('plain message');

        $this->assertNull($p['severity']);
        $this->assertSame('plain message', $p['message']);
    }
}
