<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Framework\Application;
use Luany\Framework\Contracts\KernelInterface;
use Luany\Framework\Http\Kernel;
use PHPUnit\Framework\TestCase;

// ── Test kernel subclass ───────────────────────────────────────────────────────

class TestKernel extends Kernel
{
    protected array $middleware = [];

    public function setMiddleware(array $middleware): void
    {
        $this->middleware = $middleware;
    }
}

// ── Test middleware ────────────────────────────────────────────────────────────

class AddHeaderMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        return $response->header('X-Framework', 'luany');
    }
}

class ShortCircuitMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        return Response::make('short-circuited', 403);
    }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

class KernelTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application('/tmp/luany-kernel-test');
    }

    public function test_kernel_boot_calls_boot_providers(): void
    {
        $booted = false;

        $provider = new class($booted) extends \Luany\Framework\ServiceProvider {
            public function __construct(private bool &$flag) {}
            public function boot(\Luany\Framework\Application $app): void { $this->flag = true; }
        };

        $this->app->register($provider);
        $kernel = new TestKernel($this->app);
        $kernel->boot();

        $this->assertTrue($booted);
    }

    // ── Implements interface ──────────────────────────────────────────────────

    public function test_kernel_implements_interface(): void
    {
        $kernel = new TestKernel($this->app);
        $this->assertInstanceOf(KernelInterface::class, $kernel);
    }

    // ── boot idempotency ──────────────────────────────────────────────────────

    public function test_boot_is_idempotent(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        $kernel->boot(); // second call — should not throw
        $this->assertTrue(true);
    }

    // ── LTE registration ──────────────────────────────────────────────────────

    public function test_boot_registers_view_engine(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        $this->assertTrue($this->app->has('view'));
    }

    // ── handle — no routes ───────────────────────────────────────────────────

    public function test_handle_returns_404_for_unregistered_route(): void
    {
        $kernel  = new TestKernel($this->app);
        $kernel->boot();
        $request  = new Request('GET', '/nonexistent-route-xyz');
        $response = $kernel->handle($request);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_handle_returns_response_instance(): void
    {
        $kernel  = new TestKernel($this->app);
        $kernel->boot();
        $request  = new Request('GET', '/');
        $response = $kernel->handle($request);
        $this->assertInstanceOf(Response::class, $response);
    }

    // ── global middleware ─────────────────────────────────────────────────────

    public function test_global_middleware_is_applied(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->setMiddleware([AddHeaderMiddleware::class]);
        $kernel->boot();

        $request  = new Request('GET', '/nonexistent-for-middleware-test');
        $response = $kernel->handle($request);

        $this->assertSame('luany', $response->getHeaders()['X-Framework']);
    }

    public function test_global_middleware_short_circuit(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->setMiddleware([ShortCircuitMiddleware::class]);
        $kernel->boot();

        $request  = new Request('GET', '/any');
        $response = $kernel->handle($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('short-circuited', $response->getBody());
    }

    // ── terminate ────────────────────────────────────────────────────────────

    public function test_terminate_does_not_throw(): void
    {
        $kernel   = new TestKernel($this->app);
        $request  = new Request('GET', '/');
        $response = Response::make('ok');
        $kernel->terminate($request, $response);
        $this->assertTrue(true);
    }
}