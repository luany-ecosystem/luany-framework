<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Core\Middleware\Pipeline;
use Luany\Core\Routing\Route;
use Luany\Framework\Application;
use PHPUnit\Framework\TestCase;

class KrHeaderValue
{
    public string $value = 'injected';
}

class KrInjectedMiddleware implements MiddlewareInterface
{
    public function __construct(private KrHeaderValue $header)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        return $next($request)->header('X-Injected', $this->header->value);
    }
}

/**
 * Regression tests for issue #7 — the Kernel resolves middleware through the container.
 */
class KernelResolverTest extends TestCase
{
    protected function setUp(): void
    {
        Route::reset();
    }

    protected function tearDown(): void
    {
        $reset = [Pipeline::class, 'setDefaultResolver'];
        if (method_exists(Pipeline::class, 'setDefaultResolver') && is_callable($reset)) {
            $reset(null);
        }
        Route::reset();
    }

    public function test_global_middleware_gets_constructor_dependencies(): void
    {
        $app = new Application('/tmp/luany-kernel-resolver-test');
        $app->singleton(KrHeaderValue::class, function () {
            $v = new KrHeaderValue();
            $v->value = 'from-container';
            return $v;
        });

        $kernel = new TestKernel($app);
        $kernel->setMiddleware([KrInjectedMiddleware::class]);
        $kernel->boot();

        Route::get('/hello', fn () => 'hi');

        $response = $kernel->handle(new Request('GET', '/hello'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('from-container', $response->getHeaders()['X-Injected']);
    }

    public function test_resolve_class_uses_container(): void
    {
        $app    = new Application('/tmp/luany-kernel-resolver-test');
        $kernel = new TestKernel($app);

        $this->assertInstanceOf(KrInjectedMiddleware::class, $kernel->resolveClass(KrInjectedMiddleware::class));
    }

    public function test_core_resolver_hook_is_wired_when_available(): void
    {
        if (!method_exists(Pipeline::class, 'setDefaultResolver')) {
            $this->markTestSkipped('Installed luany/core has no resolver hook (< 1.1).');
        }

        $app    = new Application('/tmp/luany-kernel-resolver-test');
        $kernel = new TestKernel($app);
        $kernel->boot();

        Route::get('/route-mw', fn () => 'ok')->middleware(KrInjectedMiddleware::class);

        $response = $kernel->handle(new Request('GET', '/route-mw'));

        $this->assertSame('injected', $response->getHeaders()['X-Injected'] ?? null);
    }
}
