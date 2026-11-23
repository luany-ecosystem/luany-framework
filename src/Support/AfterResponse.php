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

    /** Close the connection (when PHP-FPM allows it), then run the jobs. */
    public static function flush(): void
    {
        if (self::$jobs !== [] && function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        while (($job = array_shift(self::$jobs)) !== null) {
            try {
                $job();
            } catch (\Throwable $e) {
                // The response is gone: the error can only be logged.
                try {
                    $logger = app('log');
                    if ($logger instanceof \Psr\Log\LoggerInterface) {
                        $logger->error('Deferred job failed: ' . $e->getMessage(), ['exception' => $e]);
                        continue;
                    }
                } catch (\Throwable) {
                }
                error_log('Luany deferred job failed: ' . $e->getMessage());
            }
        }
    }

    public static function supported(): bool
    {
        return function_exists('fastcgi_finish_request');
    }
}
