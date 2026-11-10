<?php

namespace Luany\Framework\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * FileLogger
 *
 * Minimal PSR-3 logger writing one line per record to storage/logs.
 *
 * Daily mode (default) writes to {directory}/{channel}-YYYY-MM-DD.log;
 * otherwise to {directory}/{channel}.log.
 *
 * Line format:
 *   [2026-11-10 14:03:22] luany.ERROR: Something failed {"user":7}
 *
 * - {placeholders} in the message are replaced from $context (PSR-3).
 * - $context['exception'] (Throwable) is rendered with class, file:line and trace.
 * - Records below $minLevel are dropped.
 * - Write failures never throw (logging must not break a request);
 *   they fall back to error_log().
 */
class FileLogger extends AbstractLogger
{
    /** Severity order (higher = more severe). */
    private const LEVELS = [
        LogLevel::DEBUG     => 100,
        LogLevel::INFO      => 200,
        LogLevel::NOTICE    => 250,
        LogLevel::WARNING   => 300,
        LogLevel::ERROR     => 400,
        LogLevel::CRITICAL  => 500,
        LogLevel::ALERT     => 550,
        LogLevel::EMERGENCY => 600,
    ];

    private string $directory;

    public function __construct(
        string $directory,
        private string $channel = 'luany',
        private string $minLevel = LogLevel::DEBUG,
        private bool $daily = true,
    ) {
        $this->directory = rtrim($directory, '/\\');

        if (!isset(self::LEVELS[$this->minLevel])) {
            throw new InvalidArgumentException("Invalid minimum log level [{$this->minLevel}].");
        }
    }

    /**
     * Path of the file the next record will be written to.
     */
    public function path(): string
    {
        $name = $this->daily ? $this->channel . '-' . date('Y-m-d') : $this->channel;

        return $this->directory . DIRECTORY_SEPARATOR . $name . '.log';
    }

    /**
     * @param mixed                $level
     * @param array<mixed, mixed>  $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!is_string($level) || !isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException('Invalid log level [' . (is_scalar($level) ? $level : get_debug_type($level)) . '].');
        }

        if (self::LEVELS[$level] < self::LEVELS[$this->minLevel]) {
            return;
        }

        $line = $this->format($level, (string) $message, $context);

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        if (@file_put_contents($this->path(), $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }

    /**
     * @param array<mixed, mixed> $context
     */
    private function format(string $level, string $message, array $context): string
    {
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        // Neutralise CR/LF so user input cannot forge extra log records.
        $message = str_replace(["\r", "\n"], ['\\r', '\\n'], $this->interpolate($message, $context));

        $line = sprintf('[%s] %s.%s: %s', date('Y-m-d H:i:s'), $this->channel, strtoupper($level), $message);

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $line .= ' ' . ($json === false ? '{}' : $json);
        }

        if ($exception instanceof \Throwable) {
            $line .= sprintf(
                "\n[exception] %s: %s in %s:%d\n%s",
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            );
        }

        return $line . PHP_EOL;
    }

    /**
     * @param array<mixed, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof \Stringable) {
                $replace['{' . $key . '}'] = (string) $value;
            } elseif ($value instanceof \DateTimeInterface) {
                $replace['{' . $key . '}'] = $value->format(\DateTimeInterface::RFC3339);
            } elseif (is_object($value)) {
                $replace['{' . $key . '}'] = '[object ' . get_class($value) . ']';
            } else {
                $replace['{' . $key . '}'] = '[' . gettype($value) . ']';
            }
        }

        return strtr($message, $replace);
    }
}
