<?php

namespace Tests\Unit;

use App\Services\Create\{DiskSpace, DiskCapacityException};
use Tests\TestCase;

class CreateDiskSpaceTest extends TestCase
{
    private function space(int|false $free, int|false $total): DiskSpace
    {
        return new class($free, $total) extends DiskSpace {
            public function __construct(private int|false $free, private int|false $total) {}
            protected function capacity(string $path): array { return [$this->free, $this->total]; }
        };
    }

    public function test_reserve_uses_the_larger_fixed_or_percentage_value_plus_the_allocation(): void
    {
        config(['create.disk_min_free_bytes' => 200, 'create.disk_min_free_ratio' => .1]);
        $report = $this->space(299, 1000)->inspect('/tmp', 100);
        $this->assertFalse($report['ok']); $this->assertSame(300, $report['required_bytes']);
        $this->assertTrue($this->space(300, 1000)->inspect('/tmp', 100)['ok']);
        $this->assertSame(1100, $this->space(5000, 10000)->inspect('/tmp', 100)['required_bytes']);
    }

    public function test_unknown_space_fails_closed_with_a_recoverable_message(): void
    {
        $space = $this->space(false, false);
        $this->assertFalse($space->inspect('/tmp')['ok']);
        $this->expectException(DiskCapacityException::class);
        $this->expectExceptionMessage('saved work is safe');
        $space->requireSpace('/tmp');
    }
}
