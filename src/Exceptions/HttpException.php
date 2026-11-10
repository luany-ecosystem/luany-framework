<?php

namespace Luany\Framework\Exceptions;

/**
 * HttpException
 *
 * Thrown by the abort() helper to signal an HTTP error response.
 * The Kernel catches this and returns the appropriate Response. The message
 * is HTML-escaped for HTML responses and returned as {"message": ...} when
 * the request expects JSON.
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
    /**
     * @param array<string, string> $headers Extra response headers (e.g. Retry-After)
     */
    public function __construct(
        private int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
        private array $headers = [],
    ) {
        parent::__construct($message ?: $this->defaultMessage($statusCode), $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Extra headers to send with the error response.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
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
