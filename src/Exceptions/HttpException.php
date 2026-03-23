<?php

namespace Luany\Framework\Exceptions;

/**
 * HttpException
 *
 * Thrown by the abort() helper to signal an HTTP error response.
 * The Kernel catches this and returns the appropriate Response.
 *
 * Usage (via helper):
 *   abort(404);
 *   abort(403, 'Forbidden');
 *
 * Usage (direct):
 *   throw new HttpException(422, 'Unprocessable content');
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        private int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?: $this->defaultMessage($statusCode), $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    private function defaultMessage(int $code): string
    {
        return match ($code) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            default => 'HTTP Error',
        };
    }
}
