<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Routing\Route;
use Luany\Database\ModelNotFoundException;
use Luany\Framework\Application;
use Luany\Framework\Exceptions\Handler;
use Luany\Framework\Http\Kernel;
use PHPUnit\Framework\TestCase;

if (!class_exists(ModelNotFoundException::class)) {
    require __DIR__ . '/Fixtures/ModelNotFoundException.php';
}

/**
 * JSON clients get JSON errors even when an application Handler is bound,
 * and luany/database's ModelNotFoundException maps to 404.
 */
class JsonErrorResponseTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        Route::reset();
        $this->app = new Application(sys_get_temp_dir() . '/luany-json-' . uniqid());
    }

    protected function tearDown(): void
    {
        Route::reset();
    }

    private function kernelWithHandler(bool $debug): Kernel
    {
        $handler = new class($debug) extends Handler {
            /** @var array<int, \Throwable> */
            public array $reported = [];

            public function report(\Throwable $e): void
            {
                if ($this->shouldReport($e)) {
                    $this->reported[] = $e;
                }
            }
        };
        $this->app->instance(Handler::class, $handler);

        $kernel = new Kernel($this->app);
        $kernel->boot();
        return $kernel;
    }

    private function json(string $uri): Request
    {
        return new Request('GET', $uri, headers: ['Accept' => 'application/json']);
    }

    // ── #5: server errors for JSON clients ──────────────────────────────────

    public function test_server_error_is_json_without_details_in_production(): void
    {
        $kernel = $this->kernelWithHandler(debug: false);
        Route::get('/api/boom', fn () => throw new \LogicException('secret detail'));

        $response = $kernel->handle($this->json('/api/boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaders()['Content-Type'] ?? '');
        $this->assertSame(['message' => 'Server Error'], json_decode($response->getBody(), true));
        $this->assertStringNotContainsString('secret detail', $response->getBody());
    }

    public function test_server_error_json_includes_details_in_debug(): void
    {
        $kernel = $this->kernelWithHandler(debug: true);
        Route::get('/api/boom', fn () => throw new \LogicException('secret detail'));

        $response = $kernel->handle($this->json('/api/boom'));
        $body     = json_decode($response->getBody(), true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertIsArray($body);
        $this->assertSame('secret detail', $body['message']);
        $this->assertSame(\LogicException::class, $body['exception']);
        $this->assertArrayHasKey('file', $body);
        $this->assertArrayHasKey('line', $body);
        $this->assertIsArray($body['trace']);
    }

    public function test_server_error_is_still_reported_for_json_clients(): void
    {
        $kernel = $this->kernelWithHandler(debug: false);
        Route::get('/api/boom', fn () => throw new \LogicException('boom'));

        $kernel->handle($this->json('/api/boom'));

        $handler = $this->app->make(Handler::class);
        $this->assertCount(1, $handler->reported); // @phpstan-ignore property.notFound
    }

    public function test_html_clients_still_get_the_handler_page(): void
    {
        $kernel = $this->kernelWithHandler(debug: false);
        Route::get('/boom', fn () => throw new \LogicException('boom'));

        $response = $kernel->handle(new Request('GET', '/boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertNull(json_decode($response->getBody(), true));
    }

    public function test_handler_render_json_keeps_http_status(): void
    {
        $handler  = new class extends Handler {};
        $response = $handler->renderJson(new \Luany\Framework\Exceptions\HttpException(403, 'Nope'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'Nope'], json_decode($response->getBody(), true));
    }

    // ── #6: ModelNotFoundException → 404 ────────────────────────────────────

    public function test_model_not_found_is_404_for_html(): void
    {
        $kernel = $this->kernelWithHandler(debug: false);
        Route::get('/users/{id}', fn () => throw new ModelNotFoundException('No [User] found with key [9].'));

        $response = $kernel->handle(new Request('GET', '/users/9'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('User', $response->getBody());
        $handler = $this->app->make(Handler::class);
        $this->assertSame([], $handler->reported); // @phpstan-ignore property.notFound
    }

    public function test_model_not_found_is_404_for_json(): void
    {
        $kernel = $this->kernelWithHandler(debug: false);
        Route::get('/api/users/{id}', fn () => throw new ModelNotFoundException('No [User] found with key [9].'));

        $response = $kernel->handle($this->json('/api/users/9'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['message' => 'Not Found'], json_decode($response->getBody(), true));
    }

    public function test_model_not_found_is_404_without_a_handler(): void
    {
        $kernel = new Kernel($this->app);
        $kernel->boot();
        Route::get('/users/{id}', fn () => throw new ModelNotFoundException('missing'));

        $this->assertSame(404, $kernel->handle(new Request('GET', '/users/9'))->getStatusCode());
    }

    public function test_handler_maps_model_not_found_to_404(): void
    {
        $handler = new class extends Handler {};

        $this->assertSame(404, $handler->statusCodeFor(new ModelNotFoundException('x')));
        $this->assertFalse($handler->shouldReport(new ModelNotFoundException('x')));
        $this->assertSame(404, $handler->render(new ModelNotFoundException('x'))->getStatusCode());
    }
}
