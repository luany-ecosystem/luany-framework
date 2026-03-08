# luany-framework

> Compiler-grade PHP MVC framework. Integrates luany/core and luany/lte.

## Why Luany?

- **Clean separation** — runtime (core) is independent of the framework layer
- **AST-driven templates** — LTE compiles views into optimised PHP via AST transformation, no regex parsing, deterministic output
- **Explicit lifecycle** — boot / handle / terminate, nothing hidden
- **Extensible without touching the kernel** — Service Providers as the official extension mechanism
- **Minimal surface area** — only what is differential is built; generic infrastructure is delegated

> ⚠️ Luany is currently in `v0.x`. Core contracts are stable; higher-level APIs may evolve before `v1.0`.

## Installation

```bash
composer require luany/framework
```

Or start a new project from the official skeleton:

```bash
composer create-project luany/luany my-project
```

## Architecture

```
Request::fromGlobals()
    └─ Kernel::handle()
        └─ Global Middleware Pipeline
            └─ Route::handle()
                └─ Route Middleware Pipeline
                    └─ Controller → Response
                        └─ Response::send()
        └─ Kernel::terminate()
```

## Design Principles

- Build only what is differential
- Keep core independent of the framework
- Framework orchestrates — never owns business logic
- Explicit lifecycle over magic
- Extensible without modifying the kernel

## Bootstrap (public/index.php)

```php
require __DIR__ . '/../vendor/autoload.php';

use Luany\Framework\Application;
use Luany\Framework\Support\Env;
use Luany\Core\Http\Request;

$app = new Application(__DIR__ . '/..');

Env::load($app->basePath());

$app->register(new DatabaseServiceProvider());

$kernel   = $app->make(\Luany\Framework\Http\Kernel::class);
$kernel->boot();

$request  = Request::fromGlobals();
$response = $kernel->handle($request);
$response->send();

$kernel->terminate($request, $response);
```

## Application (DI Container)

```php
use Luany\Framework\Application;

$app = new Application(__DIR__);

// Transient — new instance on every make()
$app->bind('mailer', fn($app) => new Mailer(env('MAIL_HOST')));

// Shared — same instance every make()
$app->singleton('db', fn($app) => new Database(env('DB_DSN')));

// Pre-built instance
$app->instance('config', $config);

// Resolve
$db = $app->make('db');

// Global helpers
app('db');                       // resolve from container
app();                           // Application instance
base_path('config/app.php');     // absolute path
```

## Service Providers

Service Providers are the official extension mechanism of the Luany framework.
Register services in `register()`, use them in `boot()`.

```php
use Luany\Framework\ServiceProvider;
use Luany\Framework\Application;

class DatabaseServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $app->singleton('db', fn() => new Database(
            dsn:      env('DB_DSN'),
            username: env('DB_USER'),
            password: env('DB_PASS'),
        ));
    }

    public function boot(Application $app): void
    {
        // All providers are registered — safe to call make() here
    }
}
```

Register providers before `$kernel->boot()`:

```php
$app->register(new DatabaseServiceProvider());
$app->register(new MailServiceProvider());

$kernel->boot(); // calls boot() on all providers internally
```

**Lifecycle guarantee:** all `register()` calls complete before any `boot()` runs — cross-provider dependencies are always safe in `boot()`.

## HTTP Kernel

Extend the Kernel to add global middleware and customise the routes file:

```php
namespace App\Http;

use Luany\Framework\Http\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    // Applied to every request before routing
    protected array $middleware = [
        App\Middleware\CsrfMiddleware::class,
    ];

    protected string $routesFile = 'routes/http.php';
}
```

## Routes (routes/http.php)

```php
use Luany\Core\Routing\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PostController;

// Basic verbs
Route::get('/',        [HomeController::class, 'index']);
Route::post('/users',  [UserController::class, 'store']);
Route::put('/users/{id}',    [UserController::class, 'update']);
Route::patch('/users/{id}',  [UserController::class, 'update']);
Route::delete('/users/{id}', [UserController::class, 'destroy']);
Route::any('/webhook', [WebhookController::class, 'handle']);

// Named routes
Route::get('/users/{id}', [UserController::class, 'show'])->name('users.show');

// Resource routes — generates full RESTful CRUD (8 routes)
Route::resource('posts', PostController::class);

// API resource — excludes create/edit form routes (6 routes)
Route::apiResource('posts', PostController::class);

// View route — no controller needed
Route::view('/welcome', 'pages.welcome', ['name' => 'World']);

// Groups — prefix and middleware applied to all routes inside
Route::prefix('admin')->middleware(App\Middleware\AuthMiddleware::class)->group(function () {
    Route::get('/dashboard', [AdminController::class, 'index']);
    Route::get('/users',     [AdminController::class, 'users']);
});

Route::prefix('api/v1')->group(function () {
    Route::get('/users',  [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
});
```

## Controllers & Views

Three ways to return a view — all valid:

```php
use Luany\Core\Http\Request;
use Luany\Core\Http\Response;

class HomeController
{
    // 1. Explicit — full control over status code and headers
    public function index(Request $request): Response
    {
        return Response::make(view('pages.home', ['title' => 'Welcome']));
    }

    // 2. Concise — Router normalises string return to Response automatically
    public function about(Request $request): string
    {
        return view('pages.about', ['title' => 'About']);
    }

    // 3. JSON — return array, Router converts to JSON Response automatically
    public function api(Request $request): array
    {
        return ['users' => $this->users];
    }
}
```

For static pages with no controller logic, use route-level view registration:

```php
Route::view('/welcome', 'pages.welcome', ['title' => 'Welcome']);
```

## Exception Handling

Override the base `Handler` in your application to customise error responses per exception type:

```php
namespace App\Exceptions;

use Luany\Core\Http\Response;
use Luany\Core\Exceptions\RouteNotFoundException;
use Luany\Framework\Exceptions\Handler as BaseHandler;

class Handler extends BaseHandler
{
    protected array $dontReport = [
        // RouteNotFoundException::class,
    ];

    public function render(\Throwable $e): Response
    {
        // 404 — always show the styled view
        if ($e instanceof RouteNotFoundException) {
            return Response::make(view('pages.errors.404'), 404);
        }

        // 500 — styled view in production, framework debug page in development
        if (!$this->debug) {
            return Response::make(view('pages.errors.500'), 500);
        }

        return parent::render($e);
    }
}
```

Bind the handler in `bootstrap/app.php`:

```php
$app->singleton(
    \Luany\Framework\Exceptions\Handler::class,
    fn() => new App\Exceptions\Handler((bool) Env::get('APP_DEBUG', false))
);
```

When `APP_DEBUG=true`, uncaught exceptions render a full-screen branded debug page — self-contained, no external assets, works regardless of app boot state.

## Environment

```php
use Luany\Framework\Support\Env;

Env::load($app->basePath());                    // loads .env — idempotent
Env::get('APP_NAME', 'Luany');                  // with default
Env::get('APP_DEBUG', false);                   // auto-casts true/false/null
Env::required(['DB_HOST', 'DB_NAME']);          // throws if missing

env('APP_NAME', 'Luany');                       // global helper
```

## What's included

- **luany/core** — HTTP Request/Response, middleware pipeline, router
- **luany/lte** — AST-based template engine, zero regex parsing
- **vlucas/phpdotenv** — Environment variable loading (encapsulated in `Env`)
- **psr/log** — Logger interface (PSR-3)

## Requirements

- PHP 8.1+
- Composer 2.0+

## Testing

```bash
composer install
vendor/bin/phpunit
```

80 tests, 91 assertions.

## Changelog

### v0.3.0
- `Translator` — migrated from skeleton to `Luany\Framework\Support\Translator`; zero external dependencies, flat key/value files, `:placeholder` replacements, fallback locale, idempotent file loading
- `LocaleMiddleware` — migrated to `Luany\Framework\Http\Middleware\LocaleMiddleware`; detects locale via cookie → Accept-Language → APP_LOCALE env → fallback; uses `Request::cookie()` and `Request::header()`, no superglobals
- `__()` helper — added to `helpers.php`; resolves via `app('translator')`
- `locale()` helper — added to `helpers.php`; returns active locale code
- `Kernel::handle()` — exception handling moved inside `then()` callback; global middleware now wraps the full dispatch including error responses
- `Kernel::handleException()` — explicit `RouteNotFoundException` fallback when Handler is not bound in container
- `luany/core` bumped to `^0.2.3`
- 80 tests, 91 assertions

### v0.2.2
- `Handler::debugPage()` — full redesign: full-screen layout, animated radial gradients, 48px grid overlay
- Debug page — large exception name with namespace/shortName split, meta row (file, line, method, URI, time)
- Debug page — branded SVG favicon as inline base64 data URI, no external asset dependencies
- Debug page — "Debug Mode" badge with animated pulse dot, self-contained regardless of app boot state

### v0.2.1
- `Exceptions/Handler` — abstract base exception handler with `report()` and `render()`
- `Kernel::handle()` — now wraps dispatch in try/catch, delegates to `Handler` via container
- `Kernel::handleException()` — private method, calls `report()` then `render()`
- Debug page — branded Luany error page with stack trace (only in `APP_DEBUG=true`)
- 80 tests, 91 assertions — `ExceptionHandlerTest` added

### v0.2.0
- README: Why Luany, Design Principles, compiler-grade explained, status notice
- Routes section: full routing API documented (all verbs, resource, apiResource, view, groups, named)
- Views section: all three return patterns documented (Response::make, string, array)
- `helpers.php`: fixed PHP 8.4 nullable deprecation (`?string $abstract`)

### v0.1.0
- `Application` — DI container with bind, singleton, instance, make, auto-resolve
- `ServiceProviderInterface` / `ServiceProvider` — two-phase lifecycle (register → boot)
- `Application::register()` / `Application::bootProviders()` — provider management
- `Kernel` — HTTP kernel with boot/handle/terminate; calls `bootProviders()` internally
- `KernelInterface` / `ApplicationInterface` / `ServiceProviderInterface` — public contracts
- `Env` — encapsulated phpdotenv wrapper with value casting and `required()` validation
- Global middleware pipeline — applied before routing, full short-circuit support
- LTE engine registered automatically at boot via `Route::setViewRenderer()`
- `helpers.php` — `app()`, `env()`, `base_path()`, `view()`, `redirect()`, `response()`
- 56 unit tests — `ApplicationTest`, `EnvTest`, `KernelTest`, `ServiceProviderTest`

## License

MIT — see [LICENSE](LICENSE) for details.