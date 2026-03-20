# Changelog — luany/framework

All notable changes to this package are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased] — next/v1

### Added
- `HttpException` — thrown by `abort()`, carries HTTP status code and a default message per code (400–500). Caught by `Kernel::handleException()`.
- `ValidationException` — thrown by `validate()` on failure. Carries validation errors and a redirect URL. Errors are pre-flashed to session before the exception is thrown.
- `helpers.php: abort(int $code, string $message = ''): never` — abort the current request with an HTTP error. Throws `HttpException`.
- `helpers.php: validate(array $data, array $rules, string $back = ''): array` — validate data against rules. On success returns validated fields. On failure flashes `errors` and `_old_input` to session and throws `ValidationException`. Falls back to `HTTP_REFERER` if `$back` is omitted.
- `Kernel::loadRoutes()` — **route auto-discovery**: loads `routes/http.php` first (primary file, backward compatible), then all other `*.php` files in `routes/` alphabetically. Enables `make:feature` to generate per-feature route files without manual registration.

### Changed
- `Kernel::handleException()` now handles three additional exception types before delegating to the custom `Handler`:
  - `ValidationException` → redirect to `$e->getRedirectTo()` (session already has errors)
  - `HttpException` → `Response::make($message, $statusCode)`
  - `MethodNotAllowedException` → 405 response with `Allow` header set to `$e->getAllowHeaderValue()`

### Breaking Changes
- None. All changes are additive.

---

## [0.4.0] — Phase 2

### Added
- `Application` IoC container — `bind()`, `singleton()`, `instance()`, `make()`, `has()`. Auto-resolves concrete classes with no constructor dependencies. Global singleton via `Application::getInstance()` / `app()`.
- `Kernel` — HTTP lifecycle controller. `boot()` registers Config, Session, CSRF, LTE engine, routes. `handle()` runs global middleware pipeline then `Route::handle()`. `terminate()` hook for post-send tasks.
- `FileSession` — file-backed session driver implementing `SessionInterface`. Flash data lifecycle (new → old → purged). `start()`, `get()`, `set()`, `has()`, `forget()`, `flash()`, `regenerate()`, `save()`, `destroy()`.
- `SessionInterface` — contract for session drivers (`FileSession`, Redis, DB).
- `Config` — loads `config/*.php` files, dot-notation access (`get()`, `set()`, `has()`, `all()`).
- `Env` — loads `.env` file, type-casts `true/false/null/empty`, `required()` validation.
- `CsrfToken` — generates and validates CSRF tokens stored in session.
- `CsrfMiddleware` — validates `_token` field or `X-CSRF-Token` header on state-changing requests. Configurable `$except` list.
- `Validator` — zero-dependency validation engine. Rules: `required`, `string`, `email`, `numeric`, `min:{n}`, `max:{n}`, `in:{a},{b}`, `confirmed`, `unique:{table},{col}` (callback-based). `make()`, `fails()`, `passes()`, `errors()`, `validated()`.
- `Translator` — loads `lang/{locale}.php` files, dot-notation keys, `:placeholder` replacement, `setLocale()`, `isSupported()`, `getFallback()`.
- `LocaleMiddleware` — detects locale from cookie `app_locale` then `Accept-Language` header. Falls back to configured default.
- `ServiceProvider` — base class with `register()` and `boot()` lifecycle. `bootProviders()` deferred until `Kernel::boot()`.
- `helpers.php` — `app()`, `env()`, `base_path()`, `view()`, `redirect()`, `response()`, `config()`, `session()`, `csrf_token()`, `old()`, `__()`, `locale()`.

---

## [0.3.1]

### Fixed
- Restored inner `try/catch` in `Kernel::handle()` pipeline. Exceptions thrown inside the route action (after global middleware) were not being caught, causing unhandled errors instead of delegating to `Handler::render()`.

---

## [0.3.0]

### Added
- `Translator` — i18n as a native framework feature. Dot-notation keys, placeholder replacement, locale fallback.
- `LocaleMiddleware` — automatic locale detection from cookie and `Accept-Language` header.
- `helpers.php: __()` — translate a key using the bound `Translator` instance.
- `helpers.php: locale()` — get the currently active locale code.

---

## [0.2.2]

### Changed
- `Handler::debugPage()` — redesigned to use full-screen Luany design system (dark theme, gradient, monospace font). Replaces plain HTML dump.

---

## [0.2.1]

### Added
- `Exceptions/Handler` — base exception handler. `report()` writes to `error_log`. `render()` returns debug page (with stack trace) in development, or `serverError()` in production. Override `render()` in `app/Exceptions/Handler.php` for custom error views.
- `Kernel` now delegates unhandled exceptions to `Handler::render()` via the container, instead of always returning a plain 500.

---

## [0.2.0]

### Added
- `Application` IoC container with `bind()`, `singleton()`, `instance()`, `make()`, path helpers.
- `Kernel` HTTP lifecycle with global middleware pipeline and `boot()` sequence.
- `Env` loader with type casting.
- `ServiceProvider` base class with register/boot lifecycle.
- Contracts: `ApplicationInterface`, `KernelInterface`, `ServiceProviderInterface`, `SessionInterface`.

---

## [0.1.0] — Initial release

### Added
- Package scaffolding and initial integration layer.