<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Support\AfterResponse;
use PHPUnit\Framework\TestCase;

class AfterResponseTest extends TestCase
{
    public function test_jobs_run_in_order_and_a_failure_does_not_stop_the_rest(): void
    {
        $ran = [];
        AfterResponse::run(function () use (&$ran) { $ran[] = 1; });
        AfterResponse::run(function () { throw new \RuntimeException('smtp down'); });
        AfterResponse::run(function () use (&$ran) { $ran[] = 3; });

        $log = ini_set('error_log', '/dev/null');
        try {
            AfterResponse::flush();
        } finally {
            ini_set('error_log', (string) $log);
        }

        $this->assertSame([1, 3], $ran);
        AfterResponse::flush(); // nothing left
        $this->assertSame([1, 3], $ran);
    }
}
