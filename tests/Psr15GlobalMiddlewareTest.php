<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Routing\Route;
use Luany\Framework\Application;
use Luany\Framework\Http\Kernel;
use PHPUnit\Framework\TestCase;

class Psr15TestKernel extends Kernel
{
    /** @param array<int, class-string|object> $middleware */
    public function setMiddleware(array $middleware): void
    {
        $this->middleware = $middleware;
    }
}

/**
 * Global (Kernel) middleware accepts PSR-15 middleware, like route middleware.
 */
class Psr15GlobalMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Route::reset();
    }

    protected function tearDown(): void
    {
        Route::reset();
    }

    private function kernel(): Psr15TestKernel
    {
        $kernel = new Psr15TestKernel(new Application(sys_get_temp_dir() . '/luany-psr15-' . uniqid()));
        $kernel->boot();
        Route::get('/ping', fn () => 'pong');
        return $kernel;
    }

    public function test_psr15_global_middleware_runs(): void
    {
        if (!class_exists('Luany\Core\Psr\Psr15Middleware') || !interface_exists('Psr\Http\Server\MiddlewareInterface')) {
            $this->markTestSkipped('Requires luany/core 1.3+ (PSR-15 support).');
        }
        if (!class_exists('Nyholm\Psr7\Factory\Psr17Factory') && !class_exists('GuzzleHttp\Psr7\HttpFactory')) {
            $this->markTestSkipped('Requires a PSR-7 implementation (nyholm/psr7 or guzzlehttp/psr7).');
        }

        $psr15 = new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(
                \Psr\Http\Message\ServerRequestInterface $request,
                \Psr\Http\Server\RequestHandlerInterface $handler
            ): \Psr\Http\Message\ResponseInterface {
                return $handler->handle($request)->withHeader('X-Psr15', 'yes');
            }
        };

        $kernel = $this->kernel();
        $kernel->setMiddleware([$psr15]);

        $response = $kernel->handle(new Request('GET', '/ping'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pong', $response->getBody());
        $this->assertSame('yes', $response->getHeaders()['X-Psr15'] ?? null);
    }

    public function test_invalid_global_middleware_is_still_rejected(): void
    {
        $kernel = $this->kernel();
        $kernel->setMiddleware([new \stdClass()]);

        $response = $kernel->handle(new Request('GET', '/ping'));

        $this->assertSame(500, $response->getStatusCode());
    }
}
