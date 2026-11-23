<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Application;
use Luany\Framework\Mail\MailException;
use Luany\Framework\Support\AfterResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

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

    public function test_the_log_never_carries_the_job_arguments(): void
    {
        $logger = new class extends AbstractLogger {
            public string $written = '';
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->written .= $message . ' ' . print_r($context, true);
            }
        };
        $app = new Application(sys_get_temp_dir() . '/luany-after-response');
        $app->instance('log', $logger);

        // Development setting: stack traces keep the function arguments.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $token = bin2hex(random_bytes(32));
        $send  = function (string $token): void {
            throw new MailException('LUANY-MAIL-003', 'Could not send the email via SMTP (smtp.test:587): connection refused');
        };
        try {
            AfterResponse::run(fn () => $send($token));
            AfterResponse::flush();
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }

        $this->assertStringContainsString('LUANY-MAIL-003', $logger->written);
        $this->assertStringNotContainsString($token, $logger->written);
    }

    public function test_an_open_session_is_closed_before_the_jobs(): void
    {
        if (headers_sent()) {
            $this->markTestSkipped('headers already sent: cannot start a session');
        }
        $status = null;
        @session_start();
        AfterResponse::run(function () use (&$status) { $status = session_status(); });
        AfterResponse::flush();

        $this->assertSame(PHP_SESSION_NONE, $status);
    }
}
