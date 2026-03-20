<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Framework\Contracts\SessionInterface;
use Luany\Framework\Http\Middleware\CsrfMiddleware;
use Luany\Framework\Security\CsrfToken;
use PHPUnit\Framework\TestCase;

/**
 */
class CsrfMiddlewareTest extends TestCase
{
    private array $sessionData = [];
    private CsrfToken $csrf;
    private CsrfMiddleware $middleware;

    protected function setUp(): void
    {
        $this->sessionData = [];

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')
            ->willReturnCallback(function (string $key, mixed $default = null) {
                return $this->sessionData[$key] ?? $default;
            });
        $session->method('set')
            ->willReturnCallback(function (string $key, mixed $value) {
                $this->sessionData[$key] = $value;
            });

        $this->csrf = new CsrfToken($session);
        $this->middleware = new CsrfMiddleware($this->csrf);
    }

    private function nextHandler(): callable
    {
        return fn(Request $req) => Response::make('OK', 200);
    }

    // ── GET requests pass through ────────────────────────────────────────────

    public function testGetRequestPassesWithoutToken(): void
    {
        $request = new Request('GET', '/form');
        $response = $this->middleware->handle($request, $this->nextHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testHeadRequestPassesWithoutToken(): void
    {
        $request = new Request('HEAD', '/form');
        $response = $this->middleware->handle($request, $this->nextHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testOptionsRequestPassesWithoutToken(): void
    {
        $request = new Request('OPTIONS', '/api');
        $response = $this->middleware->handle($request, $this->nextHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    // ── POST requests require token ──────────────────────────────────────────

    public function testPostWithoutTokenReturns403(): void
    {
        $this->csrf->token(); // generate a session token
        $request = new Request('POST', '/submit');
        $response = $this->middleware->handle($request, $this->nextHandler());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPostWithValidTokenInBodyPasses(): void
    {
        $token = $this->csrf->token();
        $request = new Request('POST', '/submit', body: ['_token' => $token]);
        $response = $this->middleware->handle($request, $this->nextHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPostWithInvalidTokenReturns403(): void
    {
        $this->csrf->token();
        $request = new Request('POST', '/submit', body: ['_token' => 'wrong']);
        $response = $this->middleware->handle($request, $this->nextHandler());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPostWithValidTokenInHeaderPasses(): void
    {
        $token = $this->csrf->token();
        $request = new Request('POST', '/submit', headers: ['X-CSRF-TOKEN' => $token]);
        $response = $this->middleware->handle($request, $this->nextHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    // ── PUT, PATCH, DELETE ───────────────────────────────────────────────────

    public function testPutWithoutTokenReturns403(): void
    {
        $this->csrf->token();
        $request = new Request('PUT', '/resource/1');
        $response = $this->middleware->handle($request, $this->nextHandler());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDeleteWithValidTokenPasses(): void
    {
        $token = $this->csrf->token();
        $request = new Request('DELETE', '/resource/1', body: ['_token' => $token]);
        $response = $this->middleware->handle($request, $this->nextHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    // ── Exclusions ───────────────────────────────────────────────────────────

    public function testExcludedExactUriSkipsVerification(): void
    {
        $middleware = new class($this->csrf) extends CsrfMiddleware {
            protected array $except = ['/api/webhook'];
        };

        $this->csrf->token();
        $request = new Request('POST', '/api/webhook');
        $response = $middleware->handle($request, $this->nextHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testExcludedWildcardUriSkipsVerification(): void
    {
        $middleware = new class($this->csrf) extends CsrfMiddleware {
            protected array $except = ['/api/*'];
        };

        $this->csrf->token();
        $request = new Request('POST', '/api/external/callback');
        $response = $middleware->handle($request, $this->nextHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNonExcludedUriStillRequiresToken(): void
    {
        $middleware = new class($this->csrf) extends CsrfMiddleware {
            protected array $except = ['/api/*'];
        };

        $this->csrf->token();
        $request = new Request('POST', '/submit');
        $response = $middleware->handle($request, $this->nextHandler());

        $this->assertSame(403, $response->getStatusCode());
    }
}
