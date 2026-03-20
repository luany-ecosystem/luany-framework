<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Contracts\SessionInterface;
use Luany\Framework\Security\CsrfToken;
use PHPUnit\Framework\TestCase;

/**
 */
class CsrfTokenTest extends TestCase
{
    private array $sessionData = [];
    private SessionInterface $session;
    private CsrfToken $csrf;

    protected function setUp(): void
    {
        $this->sessionData = [];
        $this->session = $this->createMockSession();
        $this->csrf = new CsrfToken($this->session);
    }

    private function createMockSession(): SessionInterface
    {
        $data = &$this->sessionData;

        $session = $this->createMock(SessionInterface::class);

        $session->method('get')
            ->willReturnCallback(function (string $key, mixed $default = null) use (&$data) {
                return $data[$key] ?? $default;
            });

        $session->method('set')
            ->willReturnCallback(function (string $key, mixed $value) use (&$data) {
                $data[$key] = $value;
            });

        $session->method('has')
            ->willReturnCallback(function (string $key) use (&$data) {
                return array_key_exists($key, $data);
            });

        return $session;
    }

    // ── token() ──────────────────────────────────────────────────────────────

    public function testTokenGeneratesTokenWhenNoneExists(): void
    {
        $token = $this->csrf->token();

        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    public function testTokenReturnsSameTokenOnSubsequentCalls(): void
    {
        $first = $this->csrf->token();
        $second = $this->csrf->token();

        $this->assertSame($first, $second);
    }

    public function testTokenReturnsExistingSessionToken(): void
    {
        $this->sessionData['_csrf_token'] = 'existing-token';

        $this->assertSame('existing-token', $this->csrf->token());
    }

    // ── validate() ───────────────────────────────────────────────────────────

    public function testValidateReturnsTrueForMatchingToken(): void
    {
        $token = $this->csrf->token();

        $this->assertTrue($this->csrf->validate($token));
    }

    public function testValidateReturnsFalseForMismatchedToken(): void
    {
        $this->csrf->token();

        $this->assertFalse($this->csrf->validate('wrong-token'));
    }

    public function testValidateReturnsFalseForEmptyToken(): void
    {
        $this->csrf->token();

        $this->assertFalse($this->csrf->validate(''));
    }

    public function testValidateReturnsFalseWhenNoTokenStored(): void
    {
        $this->assertFalse($this->csrf->validate('any-token'));
    }

    // ── regenerate() ─────────────────────────────────────────────────────────

    public function testRegenerateCreatesNewToken(): void
    {
        $first = $this->csrf->token();
        $second = $this->csrf->regenerate();

        $this->assertNotSame($first, $second);
        $this->assertSame(64, strlen($second));
    }

    public function testRegenerateInvalidatesPreviousToken(): void
    {
        $old = $this->csrf->token();
        $this->csrf->regenerate();

        $this->assertFalse($this->csrf->validate($old));
    }

    public function testRegenerateNewTokenIsValid(): void
    {
        $this->csrf->token();
        $new = $this->csrf->regenerate();

        $this->assertTrue($this->csrf->validate($new));
    }
}
