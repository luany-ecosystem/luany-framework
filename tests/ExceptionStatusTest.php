<?php

namespace Luany\Framework\Tests;

use Luany\Core\Exceptions\MethodNotAllowedException;
use Luany\Core\Exceptions\RouteNotFoundException;
use Luany\Core\Http\Request;
use Luany\Core\Routing\Route;
use Luany\Framework\Application;
use Luany\Framework\Exceptions\Handler;
use Luany\Framework\Exceptions\HttpException;
use Luany\Framework\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class EsAppHandler extends Handler
{
    /** @var array<int, \Throwable> */
    public array $reported = [];

    public function report(\Throwable $e): void
    {
        if ($this->shouldReport($e)) {
            $this->reported[] = $e;
        }
    }
}

/**
 * Regression tests for issue #3 — HTTP status preservation, JSON validation
 * errors and escaped abort() messages.
 */
class ExceptionStatusTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        Route::reset();
        $this->app = new Application(sys_get_temp_dir() . '/luany-exc-' . uniqid());
    }

    protected function tearDown(): void
    {
        Route::reset();
    }

    private function handler(bool $debug = false): Handler
    {
        return new class($debug) extends Handler {};
    }

    // ── Handler::render() ────────────────────────────────────────────────────

    public function test_handler_renders_route_not_found_as_404(): void
    {
        $response = $this->handler()->render(new RouteNotFoundException('GET', '/missing'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_handler_renders_404_in_debug_mode(): void
    {
        $response = $this->handler(true)->render(new RouteNotFoundException('GET', '/missing'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_handler_renders_405_with_allow_header(): void
    {
        $response = $this->handler()->render(new MethodNotAllowedException('DELETE', '/users', ['GET', 'POST']));
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->getHeaders()['Allow']);
    }

    public function test_handler_keeps_http_exception_status_and_escapes_message(): void
    {
        $response = $this->handler()->render(new HttpException(403, '<script>alert(1)</script>'));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('<script>', $response->getBody());
        $this->assertStringContainsString('&lt;script&gt;', $response->getBody());
    }

    public function test_handler_route_not_found_does_not_echo_uri(): void
    {
        $response = $this->handler()->render(new RouteNotFoundException('GET', '/<img src=x>'));
        $this->assertStringNotContainsString('<img', $response->getBody());
    }

    public function test_handler_does_not_report_http_exceptions(): void
    {
        $handler = $this->handler();
        $this->assertFalse($handler->shouldReport(new RouteNotFoundException('GET', '/x')));
        $this->assertFalse($handler->shouldReport(new HttpException(404)));
        $this->assertTrue($handler->shouldReport(new \RuntimeException('boom')));
    }

    // ── Kernel with a custom handler ────────────────────────────────────────

    public function test_kernel_with_custom_handler_returns_404_not_500(): void
    {
        $handler = new EsAppHandler();
        $this->app->instance(Handler::class, $handler);

        $kernel = new TestKernel($this->app);
        $kernel->boot();

        $response = $kernel->handle(new Request('GET', '/does-not-exist'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $handler->reported);
    }

    public function test_kernel_with_custom_handler_reports_server_errors(): void
    {
        $handler = new EsAppHandler();
        $this->app->instance(Handler::class, $handler);

        $kernel = new TestKernel($this->app);
        $kernel->boot();
        Route::get('/boom', fn () => throw new \LogicException('boom'));

        $response = $kernel->handle(new Request('GET', '/boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertCount(1, $handler->reported);
    }

    public function test_kernel_returns_405_with_allow_header(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        Route::post('/users', fn () => 'created');

        $response = $kernel->handle(new Request('DELETE', '/users'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaders()['Allow']);
    }

    // ── abort() ──────────────────────────────────────────────────────────────

    public function test_abort_message_is_html_escaped(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        Route::get('/abort', fn () => abort(403, '<b onclick="x">no</b>'));

        $response = $kernel->handle(new Request('GET', '/abort'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('&lt;b onclick=&quot;x&quot;&gt;no&lt;/b&gt;', $response->getBody());
    }

    public function test_abort_with_headers(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        Route::get('/limited', fn () => abort(429, 'Slow down', ['Retry-After' => '60']));

        $response = $kernel->handle(new Request('GET', '/limited'));

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->getHeaders()['Retry-After']);
    }

    public function test_abort_returns_json_when_expected(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        Route::get('/api/abort', fn () => abort(404, 'Missing <thing>'));

        $response = $kernel->handle(new Request('GET', '/api/abort', headers: ['Accept' => 'application/json']));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['message' => 'Missing <thing>'], json_decode($response->getBody(), true));
    }

    // ── ValidationException ─────────────────────────────────────────────────

    public function test_validation_exception_returns_422_json_when_expected(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        $errors = ['email' => ['The email field is required.']];
        Route::post('/api/users', fn () => throw new ValidationException($errors, '/users/create'));

        $response = $kernel->handle(new Request('POST', '/api/users', headers: ['Accept' => 'application/json']));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['message' => 'The given data was invalid.', 'errors' => $errors],
            json_decode($response->getBody(), true)
        );
    }

    public function test_validation_exception_returns_422_json_for_xhr(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        Route::post('/xhr', fn () => throw new ValidationException(['a' => ['bad']], '/'));

        $response = $kernel->handle(new Request('POST', '/xhr', headers: ['X-Requested-With' => 'XMLHttpRequest']));

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_validation_exception_redirects_for_html(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        Route::post('/form', fn () => throw new ValidationException(['a' => ['bad']], '/form'));

        $response = $kernel->handle(new Request('POST', '/form'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/form', $response->getHeaders()['Location']);
    }

    // ── #13: HTTP exceptions go through the application Handler ──────────────

    private function customPageHandler(): Handler
    {
        return new class extends Handler {
            /** @var array<int, \Throwable> */
            public array $reported = [];

            public function report(\Throwable $e): void
            {
                if ($this->shouldReport($e)) {
                    $this->reported[] = $e;
                }
            }

            public function render(\Throwable $e): \Luany\Core\Http\Response
            {
                $response = parent::render($e);
                return $response->body('custom:' . $response->getStatusCode());
            }
        };
    }

    public function test_abort_uses_application_handler_for_custom_pages(): void
    {
        $handler = $this->customPageHandler();
        $this->app->instance(Handler::class, $handler);
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        Route::get('/secret', fn () => throw new HttpException(403, 'nope', headers: ['X-Reason' => 'policy']));

        $response = $kernel->handle(new Request('GET', '/secret'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('custom:403', $response->getBody());
        $this->assertSame('policy', $response->getHeaders()['X-Reason']);
        $this->assertSame([], $handler->reported, 'HTTP exceptions are not reported');
    }

    public function test_method_not_allowed_uses_application_handler_and_keeps_allow(): void
    {
        $this->app->instance(Handler::class, $this->customPageHandler());
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        Route::get('/only-get', fn () => 'ok');

        $response = $kernel->handle(new Request('POST', '/only-get'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('custom:405', $response->getBody());
        $this->assertSame('GET', $response->getHeaders()['Allow']);
    }

    public function test_abort_for_json_clients_stays_json_even_with_handler(): void
    {
        $this->app->instance(Handler::class, $this->customPageHandler());
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        Route::get('/api/x', fn () => throw new HttpException(404, 'missing'));

        $response = $kernel->handle(new Request('GET', '/api/x', headers: ['Accept' => 'application/json']));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('{"message":"missing"}', $response->getBody());
    }

    public function test_abort_without_handler_keeps_builtin_response(): void
    {
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        Route::get('/gone', fn () => throw new HttpException(410, '<b>gone</b>'));

        $response = $kernel->handle(new Request('GET', '/gone'));

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame('&lt;b&gt;gone&lt;/b&gt;', $response->getBody());
    }
}
