<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Framework\Application;
use Luany\Framework\Contracts\SessionInterface;
use Luany\Framework\Http\Middleware\CsrfMiddleware;
use Luany\Framework\Security\CsrfToken;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the CSRF convention (_token / _csrf_token / X-CSRF-TOKEN).
 */
class CsrfHardeningTest extends TestCase
{
    private array $sessionData = [];
    private CsrfToken $csrf;
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->sessionData = [];
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null) => $this->sessionData[$key] ?? $default
        );
        $session->method('set')->willReturnCallback(function (string $key, mixed $value) {
            $this->sessionData[$key] = $value;
        });
        $this->session = $session;
        $this->csrf = new CsrfToken($session);
    }

    private function run_(CsrfMiddleware $mw, Request $request): Response
    {
        return $mw->handle($request, fn () => Response::make('OK'));
    }

    public function test_constants_follow_ecosystem_convention(): void
    {
        $this->assertSame('_csrf_token', CsrfToken::SESSION_KEY);
        $this->assertSame('_token', CsrfToken::FIELD);
        $this->assertSame('csrf_token', CsrfToken::LEGACY_FIELD);
        $this->assertSame('X-CSRF-TOKEN', CsrfToken::HEADER);
    }

    public function test_token_is_stored_under_session_key_and_not_rotated(): void
    {
        $token = $this->csrf->token();

        $this->assertSame($token, $this->sessionData['_csrf_token']);
        $this->assertSame($token, $this->csrf->token());

        $response = $this->run_(new CsrfMiddleware($this->csrf), new Request('POST', '/f', body: ['_token' => $token]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($token, $this->csrf->token(), 'token must not rotate per request');
    }

    public function test_regenerate_issues_new_token(): void
    {
        $old = $this->csrf->token();
        $new = $this->csrf->regenerate();

        $this->assertNotSame($old, $new);
        $this->assertFalse($this->csrf->validate($old));
        $this->assertTrue($this->csrf->validate($new));
    }

    public function test_legacy_csrf_token_field_is_accepted(): void
    {
        $token = $this->csrf->token();

        $response = $this->run_(new CsrfMiddleware($this->csrf), new Request('POST', '/f', body: ['csrf_token' => $token]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_array_token_returns_403_not_500(): void
    {
        $this->csrf->token();

        foreach ([['_token' => ['x']], ['csrf_token' => ['a' => 'b']], ['_token' => 123]] as $body) {
            $response = $this->run_(new CsrfMiddleware($this->csrf), new Request('POST', '/f', body: $body));
            $this->assertSame(403, $response->getStatusCode());
        }
    }

    public function test_array_token_falls_back_to_valid_header(): void
    {
        $token = $this->csrf->token();

        $response = $this->run_(
            new CsrfMiddleware($this->csrf),
            new Request('POST', '/f', body: ['_token' => ['x']], headers: ['X-CSRF-TOKEN' => $token])
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_missing_session_token_rejects(): void
    {
        $response = $this->run_(new CsrfMiddleware($this->csrf), new Request('POST', '/f', body: ['_token' => '']));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->csrf->validate('anything'));
    }

    public function test_non_string_session_token_rejects(): void
    {
        $this->sessionData['_csrf_token'] = ['tampered'];
        $this->assertFalse($this->csrf->validate('tampered'));
    }

    public function test_json_request_gets_json_error(): void
    {
        $this->csrf->token();

        $response = $this->run_(
            new CsrfMiddleware($this->csrf),
            new Request('POST', '/api', headers: ['Accept' => 'application/json'])
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'CSRF token mismatch.'], json_decode($response->getBody(), true));
    }

    public function test_middleware_without_constructor_argument_uses_container(): void
    {
        $app = new Application('/tmp/luany-csrf-test');
        $app->instance('csrf', $this->csrf);
        $token = $this->csrf->token();

        $response = $this->run_(new CsrfMiddleware(), new Request('POST', '/f', body: ['_token' => $token]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_middleware_is_autowired_by_container(): void
    {
        $app = new Application('/tmp/luany-csrf-test');
        $app->instance(CsrfToken::class, $this->csrf);

        $this->assertInstanceOf(CsrfMiddleware::class, $app->make(CsrfMiddleware::class));
    }

    public function test_csrf_field_helper(): void
    {
        $app = new Application('/tmp/luany-csrf-test');
        $app->instance('csrf', $this->csrf);

        $this->assertSame(
            '<input type="hidden" name="_token" value="' . $this->csrf->token() . '">',
            csrf_field()
        );
    }
}
