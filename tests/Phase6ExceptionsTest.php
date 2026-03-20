<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Exceptions\HttpException;
use Luany\Framework\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Phase 6 additions: HttpException, ValidationException, abort()
 */
class Phase6ExceptionsTest extends TestCase
{
    // ── HttpException ─────────────────────────────────────────────────────────

    public function test_http_exception_stores_status_code(): void
    {
        $e = new HttpException(404);
        $this->assertSame(404, $e->getStatusCode());
    }

    public function test_http_exception_uses_default_message(): void
    {
        $e = new HttpException(404);
        $this->assertSame('Not Found', $e->getMessage());
    }

    public function test_http_exception_uses_custom_message(): void
    {
        $e = new HttpException(403, 'Access denied');
        $this->assertSame('Access denied', $e->getMessage());
    }

    public function test_http_exception_default_messages_for_common_codes(): void
    {
        $cases = [400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
                  404 => 'Not Found', 405 => 'Method Not Allowed', 422 => 'Unprocessable Content',
                  429 => 'Too Many Requests', 500 => 'Internal Server Error'];

        foreach ($cases as $code => $message) {
            $e = new HttpException($code);
            $this->assertSame($message, $e->getMessage(), "Code {$code}");
        }
    }

    public function test_http_exception_unknown_code_uses_fallback(): void
    {
        $e = new HttpException(418);
        $this->assertSame('HTTP Error', $e->getMessage());
        $this->assertSame(418, $e->getStatusCode());
    }

    public function test_http_exception_extends_runtime_exception(): void
    {
        $e = new HttpException(500);
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    // ── ValidationException ───────────────────────────────────────────────────

    public function test_validation_exception_stores_errors(): void
    {
        $errors = ['name' => ['The name field is required.']];
        $e = new ValidationException($errors, '/users/create');
        $this->assertSame($errors, $e->getErrors());
    }

    public function test_validation_exception_stores_redirect_url(): void
    {
        $e = new ValidationException([], '/users/create');
        $this->assertSame('/users/create', $e->getRedirectTo());
    }

    public function test_validation_exception_has_422_code(): void
    {
        $e = new ValidationException([], '/');
        $this->assertSame(422, $e->getCode());
    }

    public function test_validation_exception_extends_runtime_exception(): void
    {
        $e = new ValidationException([], '/');
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }
}