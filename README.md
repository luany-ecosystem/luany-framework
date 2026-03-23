# luany/framework

**Application framework for Luany. IoC container, HTTP kernel, sessions, validation, config, CSRF, i18n.**

**Version**: v1.0.0 &nbsp;|&nbsp; **PHP**: >= 8.2 &nbsp;|&nbsp; **License**: MIT
**Author**: António Ambrósio Ngola &nbsp;|&nbsp; **Org**: [luany-ecosystem](https://github.com/luany-ecosystem)

---

## Table of Contents

1. [Overview](#1-overview)
2. [Installation](#2-installation)
3. [Application Container](#3-application-container)
4. [HTTP Kernel](#4-http-kernel)
5. [Config](#5-config)
6. [Session](#6-session)
7. [Validation](#7-validation)
8. [CSRF Protection](#8-csrf-protection)
9. [Exception Handling](#9-exception-handling)
10. [Helpers](#10-helpers)
11. [Service Providers](#11-service-providers)
12. [Changelog](#12-changelog)

---

## 1. Overview

`luany/framework` is the application layer of the Luany ecosystem. It wires together the IoC container, HTTP lifecycle, session management, validation, configuration, and i18n into a coherent application runtime.

It depends on `luany/core` for routing, request and response primitives, and on `luany/lte` for the template engine.

---

## 2. Installation

```bash
composer require luany/framework
```

The framework is typically used through the application skeleton (`luany/luany`), which already configures everything correctly.

---

## 3. Application Container

The `Application` class is the IoC container and global registry.

```php
use Luany\Framework\Application;

$app = new Application(__DIR__); // pass the application root path

// Bind a factory (new instance every call)
$app->bind('mailer', fn($app) => new Mailer(env('MAIL_HOST')));

// Bind a singleton (resolved once, cached)
$app->singleton('cache', fn($app) => new Cache(env('CACHE_DRIVER')));

// Store a pre-built instance
$app->instance('db', $existingConnection);

// Resolve from the container
$cache = $app->make('cache');
$cache = app('cache'); // via helper
```

The `Application` instance is accessible globally via `app()`.

### Auto-resolution

Classes with no constructor dependencies are auto-resolved:

```php
$handler = $app->make(SomeHandler::class);
```

### Service Provider lifecycle

```php
$app->register(new DatabaseServiceProvider());
// register() calls provider->register() immediately
// boot() is deferred until $app->bootProviders() (called by Kernel)
```

### Path helpers

```php
$app->basePath()             // /var/www/my-app
$app->basePath('config')     // /var/www/my-app/config
$app->configPath()           // /var/www/my-app/config
$app->storagePath('logs')    // /var/www/my-app/storage/logs
$app->cachePath('views')     // /var/www/my-app/storage/cache/views
$app->viewsPath()            // /var/www/my-app/views
$app->routesPath()           // /var/www/my-app/routes
```

---

## 4. HTTP Kernel

The Kernel orchestrates the full HTTP request lifecycle.

```php
// public/index.php
$app    = new Application(__DIR__ . '/..');
$kernel = $app->make(Kernel::class);
$kernel->boot();
$request  = Request::fromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
```

### Boot sequence

`boot()` runs in order:

1. `registerConfig()` — loads `config/*.php`
2. `registerSession()` — starts `FileSession`
3. `registerCsrf()` — binds `CsrfToken`
4. `registerLte()` — binds the LTE view engine
5. `loadRoutes()` — requires `routes/http.php`
6. `bootProviders()` — calls `boot()` on all registered providers

### Global middleware

Override in your application kernel:

```php
// app/Http/Kernel.php
class Kernel extends \Luany\Framework\Http\Kernel
{
    protected array $middleware = [
        LocaleMiddleware::class,
        CsrfMiddleware::class,
    ];
}
```

Middleware runs on every request, before routing.

---

## 5. Config

Loads PHP files from `config/` and provides dot-notation access.

```php
// config/app.php
return ['name' => 'My App', 'debug' => false];

// Usage
config('app.name')           // 'My App'
config('app.missing', 'def') // 'def'
config('app.debug')          // false
```

Direct usage:

```php
$config = app('config');
$config->get('app.name');
$config->set('app.debug', true);  // runtime override
$config->has('app.name');         // true
$config->all();                   // full array
```

---

## 6. Session

Cookie-based sessions backed by the filesystem (file-per-session in `storage/sessions/`).

```php
// Via helper
session()                    // SessionInterface instance
session('user_id')           // get value
session('user_id', 0)        // get with fallback

// Via instance
$session = app('session');
$session->set('user_id', 42);
$session->get('user_id');
$session->has('user_id');
$session->forget('user_id');
$session->flash('success', 'Saved!');   // lives for one request
$session->regenerate();                  // new session ID
$session->destroy();
```

Flash data is available on the next request only:

```php
// In controller
session()->flash('success', 'Record saved.');

// In view (next request)
{{ session('success') }}
```

Old input is automatically available via `old()` after a failed `validate()`:

```php
<input name="email" value="{{ old('email') }}">
```

---

## 7. Validation

### Validator directly

```php
use Luany\Framework\Validation\Validator;

$v = Validator::make($request->body(), [
    'name'     => 'required|string|min:2|max:255',
    'email'    => 'required|email|unique:users,email',
    'password' => 'required|string|min:8|confirmed',
    'role'     => 'required|in:admin,editor,viewer',
]);

if ($v->fails()) {
    $errors = $v->errors();    // ['name' => ['The name field is required.']]
}

$data = $v->validated();       // only validated fields
```

### validate() helper — recommended

The `validate()` helper removes all boilerplate from controllers. On failure it automatically flashes errors and old input to the session, then throws a `ValidationException` which the Kernel resolves as a redirect.

```php
public function store(Request $request): Response
{
    $data = validate($request->body(), [
        'name'  => 'required|string|min:2|max:255',
        'email' => 'required|email',
    ], '/users/create');   // redirect URL on failure

    User::create($data);
    return redirect('/users');
}
```

On failure the user is redirected to `/users/create` with errors and old input in session. The third argument defaults to `$_SERVER['HTTP_REFERER']` if omitted.

In the view:

```html
@ifempty(session('errors'))
    {{-- no errors --}}
@else
    @foreach(session('errors') as $field => $messages)
        @foreach($messages as $message)
            <p class="error">{{ $message }}</p>
        @endforeach
    @endforeach
@endisset
```

### Available rules

| Rule | Description |
|---|---|
| `required` | Field must be present and non-empty |
| `string` | Must be a string |
| `email` | Must be a valid email address |
| `numeric` | Must be numeric |
| `min:N` | Minimum length (string) or value (numeric) |
| `max:N` | Maximum length (string) or value (numeric) |
| `in:a,b,c` | Must be one of the listed values |
| `confirmed` | Must match `{field}_confirmation` |
| `unique:table,col` | Must not already exist (requires `setUniqueChecker`) |

### unique rule with database

```php
Validator::setUniqueChecker(function (string $table, string $column, mixed $value): bool {
    return (bool) app('db')->table($table)->where($column, $value)->exists();
});
```

---

## 8. CSRF Protection

`CsrfToken` generates and validates tokens stored in the session.

```php
$csrf = app('csrf');
$token = $csrf->token();    // get or generate token
$csrf->validate($token);    // throws if invalid
```

In LTE templates, use `@csrf`:

```html
<form method="POST" action="/users">
    @csrf
    ...
</form>
```

The `CsrfMiddleware` automatically validates tokens on `POST`, `PUT`, `PATCH`, `DELETE` requests. It skips validation for `GET`, `HEAD`, `OPTIONS`.

---

## 9. Exception Handling

### abort()

Abort the current request with an HTTP status code:

```php
abort(404);
abort(403, 'Forbidden');
abort(422, 'Unprocessable content');
```

Throws `HttpException` which the Kernel converts to the appropriate response.

### Custom handler

```php
// app/Exceptions/Handler.php
class Handler extends \Luany\Framework\Exceptions\Handler
{
    public function render(\Throwable $e): Response
    {
        if ($e instanceof SomeCustomException) {
            return Response::make(view('errors.custom'), 400);
        }
        return parent::render($e);
    }
}
```

Register in a service provider:

```php
$app->singleton(\Luany\Framework\Exceptions\Handler::class, fn() => new Handler(
    debug: (bool) env('APP_DEBUG', false)
));
```

### Exception priority in Kernel

1. `ValidationException` → flash is already done → redirect to `$e->getRedirectTo()`
2. `HttpException` → `Response::make($message, $statusCode)`
3. `RouteNotFoundException` → `Response::notFound()`
4. Everything else → custom `Handler::render()` or `Response::serverError()`

---

## 10. Helpers

All helpers are in `src/Support/helpers.php` and auto-loaded via Composer.

| Helper | Description |
|---|---|
| `app(?string $abstract)` | Resolve from container |
| `env(string $key, mixed $default)` | Get environment variable |
| `base_path(string $path)` | Absolute path from app root |
| `view(string $name, array $data)` | Render a view via LTE |
| `redirect(string $url, int $status)` | Create redirect Response |
| `response(string $body, int $status)` | Create Response |
| `config(string $key, mixed $default)` | Get config value |
| `session(?string $key, mixed $default)` | Get session or value |
| `csrf_token()` | Get current CSRF token |
| `old(string $key, mixed $default)` | Get previous request input |
| `validate(array $data, array $rules, string $back)` | Validate or throw |
| `abort(int $code, string $message)` | Abort with HTTP error |
| `__(string $key, array $replace)` | Translate a key |
| `locale()` | Get current locale |

---

## 11. Service Providers

Service providers are the recommended way to register application bindings.

```php
use Luany\Framework\ServiceProvider;
use Luany\Framework\Contracts\ApplicationInterface;

class DatabaseServiceProvider extends ServiceProvider
{
    public function register(ApplicationInterface $app): void
    {
        $app->singleton('db', fn() => new Connection(
            host:     env('DB_HOST', '127.0.0.1'),
            database: env('DB_NAME', 'luany'),
            username: env('DB_USER', 'root'),
            password: env('DB_PASS', ''),
        ));
    }

    public function boot(ApplicationInterface $app): void
    {
        // Called after all providers are registered
        // Safe to resolve other bindings here
    }
}
```

Register in `bootstrap/app.php`:

```php
$app->register(new DatabaseServiceProvider());
```
**Total: OK (174 tests, 223 assertions)**
---

## 12. Changelog

### next/v1 — Phase 6

**New:**
- `src/Exceptions/HttpException.php` — thrown by `abort()`, carries status code and message
- `src/Exceptions/ValidationException.php` — thrown by `validate()` on failure, carries errors + redirect URL
- `helpers.php` — added `abort(int $code, string $message = ''): never`
- `helpers.php` — added `validate(array $data, array $rules, string $back = ''): array`

**Modified:**
- `src/Http/Kernel.php` — `handleException()` handles `ValidationException` and `HttpException` before custom handler

### v0.4.0 — Phase 2

IoC container (`Application`), HTTP Kernel, `FileSession`, `Config`, `CsrfToken`, `CsrfMiddleware`, `Validator` (9 rules), `Translator`, `LocaleMiddleware`. Full `helpers.php`: `app()`, `env()`, `base_path()`, `view()`, `redirect()`, `response()`, `config()`, `session()`, `csrf_token()`, `old()`, `__()`, `locale()`.