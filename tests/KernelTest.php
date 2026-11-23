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

class ThrowingMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        throw new \RuntimeException('Middleware exploded');
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

    public function test_view_engine_uses_the_configured_syntax(): void
    {
        $views = '/tmp/luany-kernel-test/views';
        @mkdir($views, 0777, true);
        file_put_contents($views . '/pt.lte', '@se($ok)sim@senao não@fimse');

        $_ENV['LUANY_SYNTAX'] = 'pt';
        putenv('LUANY_SYNTAX=pt');
        try {
            (new TestKernel($this->app))->boot();
            $this->assertSame('sim', trim($this->app->make('view')->render('pt', ['ok' => true])));
        } finally {
            unset($_ENV['LUANY_SYNTAX']);
            putenv('LUANY_SYNTAX');
            @unlink($views . '/pt.lte');
        }
    }

    public function test_boot_registers_auth_and_hash(): void
    {
        (new TestKernel($this->app))->boot();

        $this->assertInstanceOf(\Luany\Framework\Auth\AuthManager::class, $this->app->make('auth'));
        $this->assertInstanceOf(\Luany\Framework\Auth\Hasher::class, $this->app->make('hash'));
        $this->assertSame($this->app->make('auth'), auth());
    }

    public function test_boot_registers_the_mailer_from_env(): void
    {
        foreach (['MAIL_MAILER' => 'array', 'MAIL_FROM_ADDRESS' => 'ola@luany.dev'] as $k => $v) {
            $_ENV[$k] = $v;
            putenv("{$k}={$v}");
        }
        try {
            (new TestKernel($this->app))->boot();
            $mailer = mailer();
            $this->assertInstanceOf(\Luany\Framework\Mail\Transport\ArrayTransport::class, $mailer->transport());

            $mailer->to('ana@example.com')->subject('Oi')->sendText('Olá');
            $this->assertSame('ola@luany.dev', $mailer->transport()->messages[0]->from?->email);
        } finally {
            foreach (['MAIL_MAILER', 'MAIL_FROM_ADDRESS'] as $k) {
                unset($_ENV[$k]);
                putenv($k);
            }
        }
    }

    public function test_boot_registers_signed_urls_and_email_verification(): void
    {
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(random_bytes(32));
        putenv('APP_KEY=' . $_ENV['APP_KEY']);
        try {
            (new TestKernel($this->app))->boot();
            $this->assertInstanceOf(\Luany\Framework\Security\SignedUrl::class, $this->app->make('url.signer'));
            $url = $this->app->make('auth.verification')->url(7, 'a@b.co');
            $this->assertStringStartsWith('/email/verify?', $url);
        } finally {
            unset($_ENV['APP_KEY']);
            putenv('APP_KEY');
        }
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

    // ── middleware exceptions are caught by Kernel ────────────────────────────

    public function test_middleware_exception_is_caught_and_returns_error_response(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->setMiddleware([ThrowingMiddleware::class]);
        $kernel->boot();

        $request  = new Request('GET', '/any-route');
        $response = $kernel->handle($request);

        // The exception must NOT escape — Kernel must return a valid Response
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(500, $response->getStatusCode());
    }

    // ── terminate ──────────────────────────────────────────────────────

    public function test_terminate_does_not_throw(): void
    {
        $kernel   = new TestKernel($this->app);
        $request  = new Request('GET', '/');
        $response = Response::make('ok');
        $kernel->terminate($request, $response);
        $this->assertTrue(true);
    }
}
