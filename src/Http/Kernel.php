<?php

namespace Luany\Framework\Http;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\Pipeline;
use Luany\Core\Routing\Route;
use Luany\Framework\Application;
use Luany\Framework\Contracts\KernelInterface;
use Luany\Framework\Contracts\SessionInterface;
use Luany\Framework\Security\CsrfToken;
use Luany\Framework\Session\FileSession;
use Luany\Framework\Support\Config;
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

        $this->registerConfig();
        $this->registerSession();
        $this->registerCsrf();
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

        try {
            if (empty($this->middleware)) {
                return Route::handle($request);
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
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    private function handleException(\Throwable $e): Response
    {
        // validate() throws ValidationException on failure:
        // errors are already flashed to session — just redirect back.
        if ($e instanceof \Luany\Framework\Exceptions\ValidationException) {
            return Response::redirect($e->getRedirectTo());
        }

        // abort() throws HttpException — convert directly to the matching response.
        if ($e instanceof \Luany\Framework\Exceptions\HttpException) {
            return Response::make($e->getMessage(), $e->getStatusCode());
        }

        // Router throws MethodNotAllowedException when URI matches but method doesn't.
        // RFC 7231 §6.5.5 requires a 405 response with an Allow header.
        if ($e instanceof \Luany\Core\Exceptions\MethodNotAllowedException) {
            return Response::make('Method Not Allowed', 405)
                ->header('Allow', $e->getAllowHeaderValue());
        }

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

    // ── Private ───────────────────────────────────────────────────────────────────────

    private function registerConfig(): void
    {
        $app = $this->app;

        $app->singleton('config', function () use ($app) {
            return new Config($app->configPath());
        });
    }

    private function registerSession(): void
    {
        $app = $this->app;

        $app->singleton('session', function () use ($app) {
            $savePath = $app->storagePath('sessions');
            $session = new FileSession($savePath);
            $session->start();
            return $session;
        });

        $app->singleton(SessionInterface::class, function () use ($app) {
            return $app->make('session');
        });
    }

    private function registerCsrf(): void
    {
        $app = $this->app;

        $app->singleton('csrf', function () use ($app) {
            return new CsrfToken($app->make('session'));
        });

        $app->singleton(CsrfToken::class, function () use ($app) {
            return $app->make('csrf');
        });
    }

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

    /**
     * Load route files.
     *
     * Strategy — auto-discovery with deterministic order:
     *
     *   1. Load routes/http.php first (the "core" routes file — backward compatible).
     *      This file defines global routes: home, locale, error handling, etc.
     *
     *   2. Load every other *.php file in the routes/ directory, sorted alphabetically.
     *      Files are skipped if already loaded (http.php exclusion prevents double-loading).
     *
     * This means `luany make:feature Product` can generate routes/products.php
     * instead of appending to http.php — the Kernel picks it up automatically.
     *
     * Example routes/ directory in a scaled application:
     *   routes/
     *     http.php          ← core / global routes
     *     api.php           ← API routes
     *     auth.php          ← authentication routes
     *     products.php      ← generated by: luany make:feature Product
     *     users.php         ← generated by: luany make:feature User
     *
     * Override $routesFile in your application Kernel to change the primary file name.
     * Set $routesFile to '' to skip the primary file and rely solely on auto-discovery.
     */
    private function loadRoutes(): void
    {
        $routesDir = $this->app->routesPath();

        if (!is_dir($routesDir)) {
            return;
        }

        $loaded = [];

        // 1. Load the primary routes file first (default: routes/http.php)
        if ($this->routesFile !== '') {
            $primary = $routesDir . DIRECTORY_SEPARATOR . basename($this->routesFile);
            if (file_exists($primary)) {
                require $primary;
                $loaded[realpath($primary)] = true;
            }
        }

        // 2. Auto-discover and load remaining *.php files alphabetically
        $files = glob($routesDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files); // deterministic order across all platforms

        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false && !isset($loaded[$real])) {
                require $file;
                $loaded[$real] = true;
            }
        }
    }
}