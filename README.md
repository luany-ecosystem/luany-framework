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
12. [Localisation](#12-localisation)
13. [Logging](#13-logging)
14. [Changelog](#14-changelog)

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

### Autowiring

Unbound concrete classes are built via reflection. Constructor parameters are
resolved from their class/interface type; scalar parameters use their default
value and nullable parameters fall back to `null`:

```php
class ReportService
{
    public function __construct(Mailer $mailer, LoggerInterface $log, int $year = 2026) {}
}

$service = $app->make(ReportService::class);                  // autowired
$service = $app->make(ReportService::class, ['year' => 2025]); // named override

// Interface → implementation
$app->bind(PaymentGateway::class, StripeGateway::class);
$app->singleton(Mailer::class);   // shared, autowired
```

Circular dependencies (`A → B → A`) throw `Container\CircularDependencyException`
with the full chain.

### PSR-11

`Application` implements `Psr\Container\ContainerInterface`:

```php
$app->get(Mailer::class);   // same as make()
$app->has('db');            // true only for explicit bind/singleton/instance
```

`get()`/`make()` throw `Container\NotFoundException` (PSR `NotFoundExceptionInterface`)
when nothing is bound and the class does not exist, and `Container\ContainerException`
when an entry cannot be built. Both extend `RuntimeException`.

### Controllers and middleware

During `boot()` the Kernel registers the container as luany/core's class
resolver (`Pipeline::setDefaultResolver()` / `Router::setResolver()`, luany/core
>= 1.1), so controllers and route middleware get constructor injection. Global
Kernel middleware is always resolved through the container, also on luany/core 1.0.

With luany/core >= 1.2 the Kernel also registers `Application::call()` as the
router's action invoker, so **controller methods and closure routes** get
arguments injected:

```php
Route::get('/users/{user}/posts/{post}', [PostController::class, 'show']);

class PostController
{
    // Services by type, route parameters by name — in any order.
    public function show(Request $request, PostRepository $posts, User $user, string $post): Response
    {
        // ...
    }
}
```

- route parameters are matched **by name** (after `Route::bind()` / `Route::model()`);
- class-typed parameters receive the `Request` or a container-built service;
- anything else is filled **by position**, so `show($request, $id)` keeps working;
- a route-model binding that returns `null` for a non-nullable parameter is a **404**
  (declare `?User $user` to accept `null`).

`Application::call(callable, array $parameters)` is also available directly.

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
$app->langPath()             // /var/www/my-app/lang
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

`boot()` registers the framework defaults, then loads routes and boots providers.
Every default is registered **only if a service provider has not already bound
it** (under either the string key or the class name), so providers can replace
any of them:

| Key | Class alias | Default |
|---|---|---|
| `config` | `Support\Config` | loads `config/*.php` |
| `session` | `Contracts\SessionInterface` | `FileSession` (options from `config/session.php`) |
| `csrf` | `Security\CsrfToken` | session-backed token |
| `view` | `Luany\Lte\Engine` | LTE engine (also tells `Luany\Lte\Runtime` about debug mode, lte >= 1.1) |
| `translator` | `Support\Translator` | `lang/{locale}.php` (see §12) |
| `log` | `Psr\Log\LoggerInterface` | `Log\FileLogger` (see §13) |

Then: wire the container as luany/core resolver → load `routes/*.php` → `bootProviders()`.

### Global middleware

Override in your application kernel:

```php
// app/Http/Kernel.php
class Kernel extends \Luany\Framework\Http\Kernel
{
    protected array $middleware = [
        \Luany\Framework\Http\Middleware\LocaleMiddleware::class,
        \Luany\Framework\Http\Middleware\CsrfMiddleware::class,
    ];
}
```

Middleware runs on every request, before routing. Entries are resolved through
the container, so constructor dependencies are injected.

---

## 5. Config

Loads PHP files from `config/` and provides dot-notation access.

```php
// config/app.php
return ['name' => 'My App', 'debug' => false];

// Usage
config('app.name')              // 'My App'
config('app.missing', 'def')    // 'def'
config('app.debug')             // false
config()                        // the Config repository
config()->set('app.debug', true)
config(['app.debug' => true])   // set one or more values
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
$session->regenerate();                  // new session ID (call on login/logout)
$session->destroy();
```

### Security defaults

`FileSession` configures PHP before `session_start()`:

| Option | Default |
|---|---|
| `httponly` | `true` |
| `samesite` | `'Lax'` |
| `secure` | `null` = automatic (on when the request is HTTPS) |
| `strict_mode` | `true` (`session.use_strict_mode`) |
| `only_cookies` | `true` (`session.use_only_cookies`, no trans-sid) |
| `name`, `lifetime`, `path`, `domain` | PHP defaults / `0` / `'/'` / `''` |

Override them in an optional `config/session.php` (plus `save_path`):

```php
return [
    'name'     => 'luany_session',
    'secure'   => true,   // behind a TLS-terminating proxy
    'samesite' => 'Strict',
];
```

On login and logout, rotate both the session ID and the CSRF token:

```php
session()->regenerate();
app('csrf')->regenerate();
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

Sensitive fields are never flashed: `password*`, `_token`, `csrf_token`, `_method`
(extend with `ValidationException::$dontFlash[] = 'card_number';`).

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

On failure the user is redirected to `/users/create` with errors and old input in session. If the third argument is omitted, the `Referer` is used **only when it points to the current host**; otherwise the redirect goes to `/` (prevents open redirects).

When the request expects JSON (`Accept: application/json` or `X-Requested-With: XMLHttpRequest`) the Kernel returns `422` instead of redirecting:

```json
{"message": "The given data was invalid.", "errors": {"email": ["The email field is required."]}}
```

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
| `sometimes` | Validate only when the field is present |
| `nullable` | `null`/`''` accepted (other rules skipped, `''` becomes `null`) |
| `string` | Must be a string |
| `email` | Must be a valid email address |
| `integer` | Integer or integer string (`"42"`, `"-3"`) |
| `numeric` | Must be numeric |
| `boolean` | `true/false/1/0/"1"/"0"/"true"/"false"/"on"/"off"/"yes"/"no"`, cast to `bool` |
| `array` | Must be an array |
| `date` | Parseable date |
| `after:date\|field`, `before:…` | Date after/before a date or another field |
| `after_or_equal:…`, `before_or_equal:…` | Inclusive variants |
| `min:N` / `max:N` | Length (string), value (numeric) or item count (array) |
| `between:min,max` | Inclusive, same semantics as min/max |
| `digits:N` | Exactly N digits |
| `in:a,b,c` / `not_in:a,b` | Must (not) be one of the listed values |
| `regex:/…/` / `not_regex:/…/` | Must (not) match — use array syntax if the pattern contains `\|` |
| `url` | Absolute `http`/`https` URL |
| `confirmed` | Must match `{field}_confirmation` |
| `same:field` / `different:field` | Must equal / differ from another field |
| `unique:table,col,ignore,ignoreCol` | No row has `col` = value (`col` defaults to the field; `ignore` skips the current record, `ignoreCol` defaults to `id`) |
| `exists:table,col` | A row has `col` = value; for arrays, every element must exist |

**Unknown rules throw `InvalidArgumentException`** — a typo never silently disables validation.

Except for `required`, `confirmed` and `boolean`, rules skip `null`/`''` values.

**Absent fields** are still processed. A field with the `boolean` rule that is
missing from the input (an unchecked checkbox) validates to `false` and is
included in `validated()`; add `sometimes` to leave it out instead. Other absent
fields are not included in `validated()`.

### Custom rules

```php
use Luany\Framework\Validation\Rule;

class Uppercase implements Rule
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        return strtoupper($value) === $value;
    }

    public function message(string $field): string
    {
        return "The {$field} must be uppercase.";
    }
}

Validator::make($data, [
    'code' => ['required', new Uppercase()],
    'qty'  => ['integer', fn ($field, $value, $data) => $value % 2 === 0 ?: "The {$field} must be even."],
]);

// Named rule usable in strings
Validator::extend('slug', fn ($field, $value, $params, $data) => (bool) preg_match('/^[a-z0-9-]+$/', $value),
    'The :field must be a slug.');
Validator::make($data, ['handle' => 'required|slug']);
```

Closures return `true`/`null` (pass), `false` (generic message) or a string (error message).
Custom rules are skipped for `null`/`''` values.

### unique / exists — database rules

```php
validate($request->body(), [
    'email'       => 'required|email|unique:users,email',          // create
    'email'       => "required|email|unique:users,email,{$user->id}", // update: ignore own row
    'category_id' => 'required|integer|exists:categories,id',
    'tags'        => 'array|exists:tags,id',                          // every id must exist
]);
```

The Kernel wires these rules to the application database automatically when a
`db` binding (PDO) exists — the skeleton's `DatabaseServiceProvider` registers it.
Nothing is queried until a rule runs. Table and column names in rules are
validated and quoted; values are always bound.

Elsewhere (scripts, tests), register a verifier yourself:

```php
use Luany\Framework\Validation\DatabasePresenceVerifier;

Validator::setPresenceVerifier(new DatabasePresenceVerifier($pdo));
```

**Without a verifier, `unique` and `exists` throw a `LogicException`** — they never
pass silently. `setUniqueChecker()` still works but is deprecated.

---

## 8. CSRF Protection

Ecosystem convention:

| | Name |
|---|---|
| Form field | `_token` (`CsrfToken::FIELD`) — legacy `csrf_token` also accepted |
| Session key | `_csrf_token` (`CsrfToken::SESSION_KEY`) |
| Header | `X-CSRF-TOKEN` (`CsrfToken::HEADER`) |

```php
$csrf = app('csrf');
$token = $csrf->token();      // get or generate the per-session token
$csrf->validate($token);      // bool, constant-time (hash_equals)
$csrf->regenerate();          // new token — call on login/logout
```

The token is per session and is **not** rotated on every request.

In forms:

```html
<form method="POST" action="/users">
    @csrf                      {{-- or {!! csrf_field() !!} --}}
    ...
</form>
```

Register `Luany\Framework\Http\Middleware\CsrfMiddleware` in the Kernel's
`$middleware` (or as route middleware). It validates `POST`, `PUT`, `PATCH`,
`DELETE` and skips `GET`, `HEAD`, `OPTIONS`. On mismatch it returns `403`
(JSON `{"message": "CSRF token mismatch."}` for JSON requests); non-string
input such as `_token[]=x` is treated as missing, never a 500.

```php
class VerifyCsrfToken extends \Luany\Framework\Http\Middleware\CsrfMiddleware
{
    protected array $except = ['/webhooks/*'];
    protected int $failureStatus = 419;   // optional
}
```

---

## 9. Exception Handling

### abort()

Abort the current request with an HTTP status code:

```php
abort(404);
abort(403, 'Forbidden');
abort(422, 'Unprocessable content');
abort(429, 'Slow down', ['Retry-After' => '60']);
```

Throws `HttpException` which the Kernel converts to the matching status. The
message is HTML-escaped (safe with user input); JSON requests get `{"message": …}`.

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

The base `Handler::render()` keeps HTTP statuses: `HttpException` → its status,
`RouteNotFoundException` → 404, `MethodNotAllowedException` → 405 (with `Allow`),
`ValidationException` → 422; everything else → 500 (debug page when `debug` is on).
`Handler::report()` logs through the container logger and skips those expected
HTTP exceptions (`$dontReport`).

### Exception priority in Kernel

1. `ValidationException` → 422 JSON when the request expects JSON, else redirect to `$e->getRedirectTo()`
2. `HttpException` → its status and headers (escaped HTML or JSON)
3. `MethodNotAllowedException` → 405 with `Allow` header
4. Everything else → bound `Handler` (`report()` + `render()`); without one,
   `RouteNotFoundException` → 404 and other exceptions are logged and → 500

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
| `config(string\|array\|null $key, mixed $default)` | Repository, value, or set values |
| `session(?string $key, mixed $default)` | Get session or value |
| `csrf_token()` | Get current CSRF token |
| `csrf_field()` | Hidden `_token` input |
| `logger(?string $message, array $context, string $level = 'debug')` | PSR-3 logger, or write a record |
| `old(string $key, mixed $default)` | Get previous request input |
| `validate(array $data, array $rules, string $back)` | Validate or throw |
| `abort(int $code, string $message, array $headers)` | Abort with HTTP error |
| `__(string $key, array $replace)` | Translate a key |
| `locale()` | Get current locale |
| `url(string $path = '')` | Absolute URL (from `app.url` / `APP_URL`) |
| `route(string $name, array $params)` | URL of a named route |
| `asset(string $path)` | URL of a file in `public/assets` |
| `e(mixed $value)` | HTML-escape a value |
| `flash(string $type, string $message)` / `get_flash()` | One-request flash message |

### Live reload

`Luany\Framework\Http\Middleware\LiveReloadMiddleware` serves the `luany dev`
browser client and injects it into HTML responses — only when `APP_ENV=development`
(or `local`) **and** the request comes from the loopback interface. Register it first in
the application Kernel.

### Timezone

`config('app.timezone')` (or `APP_TIMEZONE`) is applied with `date_default_timezone_set()`
during boot; invalid identifiers are ignored.

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
---

## 12. Localisation

A default translator is registered by the Kernel, so `__()`, `locale()` and
`LocaleMiddleware` work without a custom provider. Translation files live in
`lang/{locale}.php`. Settings come from `config/app.php`, then `.env`:

| config/app.php | .env | Default |
|---|---|---|
| `locale` | `APP_LOCALE` | `en` |
| `fallback_locale` | `APP_FALLBACK_LOCALE` | `en` |
| `supported_locales` | `APP_SUPPORTED_LOCALES` (comma list) | `[locale, fallback]` |

`LocaleMiddleware` picks the locale from the `app_locale` cookie, then
`Accept-Language` (languages with `q=0` are ignored), then `APP_LOCALE`.

---

## 13. Logging

The Kernel binds a PSR-3 `Log\FileLogger` as `log` / `Psr\Log\LoggerInterface`,
writing daily files to `storage/logs/luany-YYYY-MM-DD.log`:

```php
logger('User {id} logged in', ['id' => 7]);       // debug
logger()->error('Payment failed', ['exception' => $e]);
app(\Psr\Log\LoggerInterface::class)->warning('Disk almost full');
```

Optional `config/logging.php`: `path`, `channel` (`luany`), `level` (`LOG_LEVEL`,
default `debug`), `daily` (`true`). Unhandled exceptions are logged by the Kernel
(or by `Handler::report()` when an app handler is bound). The helper is named
`logger()` because PHP already defines a global `log()` function.

---

## 14. Changelog

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