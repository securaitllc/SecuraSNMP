<?php

namespace Tests\Unit;

use App\Support\LineDiff;
use PHPUnit\Framework\TestCase;

class LineDiffTest extends TestCase
{
    public function test_diff_marks_added_removed_and_context(): void
    {
        $diff = LineDiff::diff("a\nb\nc", "a\nx\nc");

        $this->assertContains(['op' => ' ', 'text' => 'a'], $diff);
        $this->assertContains(['op' => '-', 'text' => 'b'], $diff);
        $this->assertContains(['op' => '+', 'text' => 'x'], $diff);
        $this->assertContains(['op' => ' ', 'text' => 'c'], $diff);
    }

    public function test_identical_input_is_all_context(): void
    {
        $diff = LineDiff::diff("a\nb", "a\nb");
        $this->assertSame([['op' => ' ', 'text' => 'a'], ['op' => ' ', 'text' => 'b']], $diff);
    }
}
