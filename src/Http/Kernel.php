<?php

namespace Luany\Framework\Http;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\Pipeline;
use Luany\Core\Routing\Route;
use Luany\Framework\Application;
use Luany\Framework\Contracts\KernelInterface;
use Luany\Lte\Engine;

/**
 * Kernel
 *
 * The HTTP request lifecycle controller.
 *
 * Responsibilities:
 *   boot()     → register LTE engine, load routes file
 *   handle()   → run global middleware pipeline → Route::handle()
 *   terminate()→ post-send cleanup
 *
 * Global middleware is applied to every request before routing.
 * Route-level middleware is applied inside Router::handle().
 *
 * Usage (public/index.php):
 *   $app    = new Application(__DIR__ . '/..');
 *   $kernel = $app->make(Kernel::class);
 *   $kernel->boot();
 *   $request  = Request::fromGlobals();
 *   $response = $kernel->handle($request);
 *   $response->send();
 *   $kernel->terminate($request, $response);
 */
class Kernel implements KernelInterface
{
    /**
     * Global middleware — applied to every request.
     * Override in your application's Kernel extension.
     *
     * @var array<class-string>
     */
    protected array $middleware = [];

    /**
     * Routes file path.
     * Defaults to routes/http.php relative to base path.
     */
    protected string $routesFile = 'routes/http.php';

    private bool $booted = false;

    public function __construct(private Application $app) {}

    // ── KernelInterface ───────────────────────────────────────────────────────

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->registerLte();
        $this->loadRoutes();
        $this->app->bootProviders();

        $this->booted = true;
    }

    public function handle(Request $request): Response
    {
        if (!$this->booted) {
            $this->boot();
        }

        if (empty($this->middleware)) {
            try {
                return Route::handle($request);
            } catch (\Throwable $e) {
                return $this->handleException($e);
            }
        }

        return (new Pipeline())
            ->send($request)
            ->through($this->middleware)
            ->then(function (Request $req) {
                try {
                    return Route::handle($req);
                } catch (\Throwable $e) {
                    return $this->handleException($e);
                }
            });
    }

    private function handleException(\Throwable $e): Response
    {
        try {
            $handler = $this->app->make(\Luany\Framework\Exceptions\Handler::class);
            try {
                $handler->report($e);
            } catch (\Throwable) {}
            return $handler->render($e);
        } catch (\Throwable) {
            if ($e instanceof \Luany\Core\Exceptions\RouteNotFoundException) {
                return Response::notFound();
            }
            return Response::serverError();
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        // Hook for post-send tasks: session save, logging, metrics, etc.
        // Override in application kernel to add behaviour.
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function registerLte(): void
    {
        $app = $this->app;

        $app->singleton('view', function () use ($app) {
            return new Engine(
                viewsPath:  $app->viewsPath(),
                cachePath:  $app->cachePath('views'),
                autoReload: (bool) env('APP_DEBUG', false),
            );
        });

        // Connect LTE engine to Route::view()
        Route::setViewRenderer(function (string $name, array $data) {
            return app('view')->render($name, $data);
        });
    }

    private function loadRoutes(): void
    {
        $path = $this->app->routesPath(
            basename($this->routesFile)
        );

        if (!file_exists($path)) {
            // No routes file — not fatal. App may register routes programmatically.
            return;
        }

        require $path;
    }
}