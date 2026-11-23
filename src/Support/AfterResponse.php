<?php

namespace Luany\Framework\Support;

/**
 * Run work after the response has been sent.
 *
 * Under PHP-FPM, fastcgi_finish_request() closes the connection first, so
 * the client gets its answer at once and the work (an SMTP send, for
 * example) happens afterwards — it can no longer be measured from the
 * response time. Without FPM the work still runs after the script, but the
 * client may wait for it.
 */
final class AfterResponse
{
    /** @var list<callable(): void> */
    private static array $jobs = [];

    private static bool $registered = false;

    /** @param callable(): void $job */
    public static function run(callable $job): void
    {
        self::$jobs[] = $job;

        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /**
     * Save and close the session (its lock must not wait for an SMTP send),
     * close the connection when PHP-FPM allows it, then run the jobs.
     */
    public static function flush(): void
    {
        if (self::$jobs === []) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        while (($job = array_shift(self::$jobs)) !== null) {
            try {
                $job();
            } catch (\Throwable $e) {
                // The response is gone: the error can only be logged. Only the
                // class and message — never the exception itself, whose trace
                // carries the job's arguments (a reset token, for example).
                $line = 'Deferred job failed: ' . get_class($e) . ': ' . $e->getMessage();
                try {
                    $logger = app('log');
                    if ($logger instanceof \Psr\Log\LoggerInterface) {
                        $logger->error($line);
                        continue;
                    }
                } catch (\Throwable) {
                }
                error_log('Luany ' . $line);
            }
        }
    }

    public static function supported(): bool
    {
        return function_exists('fastcgi_finish_request');
    }
}
