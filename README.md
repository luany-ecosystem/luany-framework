# luany-framework

> Compiler-grade PHP MVC framework. Integrates luany/core and luany/lte.

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

## Bootstrap (public/index.php)

```php
require __DIR__ . '/../vendor/autoload.php';

use Luany\Framework\Application;
use Luany\Framework\Support\Env;
use Luany\Core\Http\Request;

$app = new Application(__DIR__ . '/..');

Env::load($app->basePath());

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

// Helpers
app('db');          // same as $app->make('db')
app();              // Application instance
base_path('config/app.php');
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
        // All providers are registered — safe to call make()
        // Run migrations check, register observers, etc.
    }
}
```

Register providers before calling `$kernel->boot()`:

```php
$app = new Application(__DIR__ . '/..');
$app->register(new DatabaseServiceProvider());
$app->register(new MailServiceProvider());

$kernel = $app->make(\Luany\Framework\Http\Kernel::class);
$kernel->boot(); // calls bootProviders() internally
```

**Lifecycle:**
1. `$app->register($provider)` — calls `register()` immediately
2. `$kernel->boot()` — calls `boot()` on all registered providers

All `register()` calls complete before any `boot()` runs — safe for cross-provider dependencies.

## HTTP Kernel

Extend the Kernel to add global middleware and customise the routes file:

```php
namespace App\Http;

use Luany\Framework\Http\Kernel as BaseKernel;
use App\Middleware\AuthMiddleware;

class Kernel extends BaseKernel
{
    // Applied to every request before routing
    protected array $middleware = [
        // AuthMiddleware::class,
    ];

    // Path relative to routes/ directory
    protected string $routesFile = 'routes/http.php';
}
```

## Routes (routes/http.php)

```php
use Luany\Core\Routing\Route;
use App\Http\Controllers\HomeController;

Route::get('/', [HomeController::class, 'index']);
Route::get('/about', [HomeController::class, 'about']);

Route::prefix('api')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
});
```

## Environment

```php
use Luany\Framework\Support\Env;

Env::load($app->basePath()); // loads .env

Env::get('APP_NAME', 'Luany');
Env::get('APP_DEBUG', false);
Env::required(['DB_HOST', 'DB_NAME']); // throws if missing

// Or via helper:
env('APP_NAME', 'Luany');
```

## Views (LTE)

The LTE engine is registered automatically at boot. Use the `view()` helper in controllers:

```php
use Luany\Core\Http\Request;
use Luany\Core\Http\Response;

class HomeController
{
    public function index(Request $request): Response
    {
        return Response::make(view('pages.home', [
            'title' => 'Welcome',
        ]));
    }
}
```

## What's included

- **luany/core** — HTTP Request/Response, middleware pipeline, router
- **luany/lte** — AST-based template engine with zero regex parsing
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

## Changelog

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