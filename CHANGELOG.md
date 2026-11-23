# Changelog — luany/framework

All notable changes to this package are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.14.0] — 2026-11-23

### Added
- **Dates in the app's locale**: `format_date($value, $style)` (`DateFormatter`). Styles `short`, `medium`, `long`, `full`, `time`, `datetime` — in Portuguese `23/11/2026`, `23 nov 2026`, `23 de novembro de 2026`, `segunda-feira, 23 de novembro de 2026`, `14:05`, `23/11/2026 14:05`. Portuguese and English use built-in tables (same result on every server); other locales use ext-intl when installed. Accepts `DateTimeInterface`, date strings and timestamps; uses the app timezone; `null`/`''` give `''`.

## [1.13.0] — 2026-11-23

### Added
- **Translated validation messages**: every message is a `validation.*` key (`validation.required`, `validation.min.string`, `validation.between.numeric`…) looked up in the app's locale, with the English text as default. The framework ships Portuguese tables (`resources/lang/pt.php`, `pt_BR.php`); the app's `lang/{locale}.php` overrides any line. `Validator::setTranslator()` for other setups.
- **Locale chain**: a regional locale falls back to its language, then to the fallback locale — `pt_AO` → `pt` → `en` — so `lang/pt_AO.php` only holds what differs. `Translator::has()`, `Translator::chain()`; packages can ship default tables (`$defaultPaths`).

### Security
- `Translator` only loads files for well-formed locale names (`pt`, `pt_AO`, `pt-BR`…).

## [1.12.1] — 2026-11-23

### Fixed
- `AfterResponse`: the session is saved and closed before deferred work runs, so its lock no longer holds the user's other requests during an SMTP send.
- `AfterResponse`: a failed job is logged as class and message only — never the exception object, whose stack trace can carry the job's arguments (such as a password reset token).

## [1.12.0] — 2026-11-23

### Added
- `AfterResponse::run($job)`: work that runs after the response is sent (under PHP-FPM the connection is closed first with `fastcgi_finish_request()`; failures are logged).
- `TRUSTED_PROXIES` (or `config('app.trusted_proxies')`): IPs / CIDR ranges whose `X-Forwarded-For` is honoured by `$request->ip()` — so rate limits count the real client behind a load balancer. Empty by default: the connecting address is used and the header is ignored.

### Changed
- Password reset: when the account exists, the token and the email are made after the response (`AfterResponse`), so the response time no longer depends on whether the email exists. Without PHP-FPM the work still runs after the script, but the client may wait for it.

## [1.11.0] — 2026-11-23

### Added
- **Password reset** — `app('auth.passwords')` (`PasswordBroker`): `sendResetLink(['email' => …], $ip, fn ($user, $token) => …)` and `reset(['email' => …, 'token' => …, 'password' => …])`. Tokens are 64 random hex characters stored only as SHA-256 (`password_reset_tokens` table, `DatabaseTokenRepository::createTableSql()`), compared with `hash_equals()`, single use, valid 60 minutes, one per user, and void once the password changes by any other route. The request answer never reveals whether the email exists; requests are limited per email (3 / 15 min) and per IP (10 / 15 min).
- **Signed URLs** — `app('url.signer')` (`SignedUrl`): HMAC-SHA256 with a sub-key of the `APP_KEY`, covering the path, every parameter and the expiry. Rotating the `APP_KEY` invalidates links already sent.
- **Email verification** — `app('auth.verification')` (`EmailVerification`): `url($userId, $email)` / `verify($request, $userId, $currentEmail)`; links expire in 1 hour and are bound to the user id and a hash of the current email.
- Middleware `ValidateSignature` (403 on a bad or expired signature; always sends `Referrer-Policy: no-referrer`) and `EnsureEmailIsVerified` (`email_verified_at`; 403 JSON or redirect to `config('auth.verify_notice')`).

## [1.10.0] — 2026-11-23

### Added
- **Email** (`Luany\Framework\Mail`): `app('mailer')` / `mailer()`:
  `mailer()->to($email, $name)->subject('Bem-vindo')->send('emails.bem-vindo', [...])`.
  - Emails are LTE views (`views/emails/x.lte`; `x_text.lte` for the plain-text part, otherwise derived from the HTML). Variables are escaped as in any view; English or Portuguese directives both work.
  - Transports: `log` (default — writes to the log, nothing to configure), `smtp` and `array` (tests). Settings in `config/mail.php`, falling back to `MAIL_MAILER`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` (`tls` STARTTLS required, `ssl`, `none`).
  - SMTP runs on symfony/mailer (new dependency), kept out of Luany's API: `Mailer`, `Message`, `Address`, `Transport`, `MailException`.
  - Addresses validated; line breaks rejected in every header value; passwords never appear in exceptions or logs. Failures are `MailException` with stable codes `LUANY-MAIL-001`…`006`.

## [1.9.0] — 2026-11-23

### Added
- **Session authentication** (`Luany\Framework\Auth`):
  - `auth()` → `AuthManager`: `attempt(['email' => …, 'password' => …])`, `validate()`, `login($user)`, `logout()`, `user()`, `id()`, `check()`, `guest()`, `intended($default)`.
  - The session id is regenerated and the CSRF token rotated on login and logout; unknown users are checked against a dummy hash (no user enumeration by timing); array input never matches; old hashes are upgraded on login.
  - `Hasher` (`app('hash')`): Argon2id when available, bcrypt otherwise; `make()`, `check()`, `needsRehash()`.
  - `ModelUserProvider`: users from a luany/database model (`config('auth.model')`, default `App\Models\User`) with a `password` column; `UserProvider` interface for other stores.
  - Middleware `Authenticate` (guests → 401 JSON or redirect to `config('auth.login')`, default `/login`, remembering the page) and `RedirectIfAuthenticated` (→ `config('auth.home')`, default `/`).
  - LTE `@auth` / `@guest` (and `@autenticado` / `@visitante`) now follow `auth()->check()`.

## [1.8.0] — 2026-11-22

### Added
- **Portuguese validation rule names**, always accepted (no package needed): `obrigatorio`, `opcional`, `quando_presente`, `texto`, `inteiro`, `numerico`, `booleano`, `lista`, `data`, `depois`, `antes`, `depois_ou_igual`, `antes_ou_igual`, `entre`, `digitos`, `em`, `nao_em`, `nao_regex`, `confirmado`, `igual`, `diferente`, `unico`, `existe`, `ficheiro` / `arquivo`, `imagem`, `tipos`. Same behaviour as the English names (`Validator::PORTUGUESE_RULES`); `email`, `url`, `min`, `max`, `regex` are the same in both. A rule registered with `extend()` under one of these names keeps its meaning.

## [1.7.0] — 2026-11-22

### Added
- **Template syntax**: the view engine reads `config('app.syntax')` (fallback `LUANY_SYNTAX`, default `en`). With `pt`, LTE accepts the Portuguese directive names (`@se`, `@paracada`, `@secao`, …) alongside the English ones. Requires luany/lte 1.4.

## [1.6.0] — 2026-11-20

### Added
- **Encrypted environment files** — `Support\EnvironmentCipher` (libsodium XChaCha20-Poly1305, environment name as associated data, random nonce, `LUANY-ENV v1` text format). When there is no `.env`, `Env::load()` decrypts `.env.{APP_ENV}.encrypted` (then `.env.encrypted`) in memory with `LUANY_ENV_KEY_{ENV}` / `LUANY_ENV_KEY`; real environment variables still win; a missing or wrong key fails the boot with a clear message. Managed with the `luany env:*` commands (luany/cli).

## [1.5.0] — 2026-11-19

### Changed
- **`asset()` returns a root-relative path** (`/assets/css/app.css`, keeping `APP_URL`'s sub-folder: `/app/assets/…`) instead of an absolute URL on `APP_URL`. Pages no longer lose their CSS/JS when served on another host or port than `APP_URL` (a second `luany serve` port, a LAN IP, a proxy). For a CDN or absolute asset URLs (e.g. in e-mails) set `ASSET_URL` or `app.asset_url`. `url()` and `route()` stay absolute.

### Fixed
- `LiveReloadMiddleware` injects the reload client only while `luany dev` is running (`LDE_WS_PORT` set). Under `luany serve`, `php -S` or a local web server it injected a script that answered 404 and retried a WebSocket.

## [1.4.0] — 2026-11-16

### Added
- Validation rules **`file`**, **`image`** (jpeg, png, gif, bmp, webp — no SVG) and **`mimes:jpg,png`** for `$_FILES`-style arrays; the type is detected from the contents with finfo (client file name without fileinfo). `min` / `max` / `between` measure uploads in kilobytes; an empty file input (`UPLOAD_ERR_NO_FILE`) counts as no value. `tmp_name` must pass `is_uploaded_file()` — `Validator::setUploadedFileVerifier()` replaces the check (PSR-7 servers, tests).
- `Handler::renderJson()` — JSON rendering of any exception (`{"message"}`; class, file, line and trace only in debug mode).
- The Kernel loads routes from the route cache `storage/cache/routes.php` when that file exists (`$routeCacheFile`, `routeCachePath()`); generate it with `Route::cache($kernel->routeCachePath())`.
- Global (Kernel) middleware accepts PSR-15 middleware, like route middleware (requires luany/core ≥ 1.3).

### Changed
- `min` / `max` / `between` compare a numeric string by value only when the field also has the `numeric` or `integer` rule; otherwise they measure its length (`"300"` passes `string|max:255`). Ints and floats are still compared by value, arrays by count.

### Fixed
- Requests that expect JSON received the HTML error page for non-HTTP exceptions when an exception Handler was bound; they now get JSON via `Handler::renderJson()`.
- luany/database's `ModelNotFoundException` (`findOrFail()` / `firstOrFail()`) now maps to 404 (HTML and JSON) and is not reported. Detected by class name — luany/database stays optional.
- `Translator` no longer fails with an uninitialised locale when the default locale is not in `supported_locales`: it starts with the fallback locale when supported, otherwise the first supported locale.
- `Exceptions/Handler.php` embedded the traced Luany logo (~320 KB) as a string, so PHP compiled it on every request; it now lives in `Exceptions/resources/luany-icon.svg` and is read only when a debug page is rendered.
- `LocaleMiddleware` uses `config('app.locale')` (then `APP_LOCALE`) as the default locale, like the Kernel's translator.

---

## [1.3.0] — 2026-11-11

### Added
- Helpers `url()`, `route()`, `asset()`, `e()`, `flash()`, `get_flash()` (previously defined by the skeleton; existing app definitions guarded by `function_exists` keep working).
- `Http\Middleware\LiveReloadMiddleware` — `luany dev` live reload (previously the skeleton's `DevMiddleware`): development + loopback only, injects before the last `</body>`, skips non-HTML and non-2xx responses.
- Kernel applies `config('app.timezone')` / `APP_TIMEZONE`.

---

## [1.2.0] — 2026-11-11

### Added
- **Method injection** — `Application::call()` resolves parameters by name (route parameters), by type (request / container services) and by position. With luany/core ≥ 1.2 the Kernel uses it for every route action: controller methods and closures can type-hint services, route parameters arrive by name in any order, and existing `($request, $id)` signatures keep working. (#11)
- A route-model binding that returns `null` for a non-nullable parameter now yields **404** instead of a `TypeError`. (#11)
- **`exists:table,column`** rule (arrays: every element must exist) and **`unique:table,column,ignore,ignoreColumn`** with update support. (#12)
- `PresenceVerifierInterface`, `DatabasePresenceVerifier` and `Validator::setPresenceVerifier()`. The Kernel registers a verifier from the `db` PDO binding automatically (lazy). (#12)

### Changed
- **`unique` no longer passes silently without a database.** Without a presence verifier (or the legacy `setUniqueChecker()`), `unique` and `exists` throw a `LogicException`. (#12)
- Requires luany/core ^1.2.

### Deprecated
- `Validator::setUniqueChecker()` — use `setPresenceVerifier()`.

---

## [1.1.1] — 2026-11-10

### Fixed
- `abort()` / `HttpException` and 405 responses now go through the application `Handler`, so custom error pages apply to them as they already did to unmatched routes. JSON clients keep the Kernel's `{message}` response; without a bound Handler the built-in responses are unchanged. HTTP exceptions are still not reported (`Handler::$dontReport`). (#13)

---

## [1.1.0] — 2026-11-10

### Added
- Container autowiring: constructor injection via reflection (class/interface types, defaults, nullable, named overrides via `make($id, $params)`); `bind()`/`singleton()` accept a class name (interface → implementation) or no concrete (self-binding); circular dependency detection (`Container\CircularDependencyException`) (#7).
- PSR-11: `Application` implements `Psr\Container\ContainerInterface` (`get()`/`has()`), plus `bound()` and `build()`. New `Container\NotFoundException` / `ContainerException` (PSR exception interfaces, still `RuntimeException`). New dependency `psr/container ^2.0` (#7).
- Kernel registers the container as luany/core's class resolver (`Pipeline::setDefaultResolver()` / `Router::setResolver()`, luany/core >= 1.1, guarded for 1.0) and resolves global middleware through the container (#7).
- Kernel forwards debug mode to `Luany\Lte\Runtime::setDebug()` (luany/lte >= 1.1, guarded for 1.0) so `@dump`/`@dd` are no-ops outside debug.
- Default `translator` (`Translator::class`) from `config/app.php` or `APP_LOCALE` / `APP_FALLBACK_LOCALE` / `APP_SUPPORTED_LOCALES`; `Application::langPath()` (#6).
- PSR-3 `Log\FileLogger` (daily files in `storage/logs`) bound as `log` / `LoggerInterface`, configurable via `config/logging.php` / `LOG_LEVEL`; `logger()` helper; unhandled exceptions are logged (#8).
- Validator rules: `integer`, `boolean`, `array`, `date`, `after`, `before`, `after_or_equal`, `before_or_equal`, `not_in`, `regex`, `not_regex`, `url`, `same`, `different`, `digits`, `between`, `nullable`, `sometimes`; `Validation\Rule` objects, closures and `Validator::extend()` (#5).
- `FileSession` security options (`$options` / `config/session.php`): httponly, SameSite=Lax, secure auto on HTTPS, `use_strict_mode`, `use_only_cookies` (#4).
- `CsrfToken::SESSION_KEY`, `FIELD`, `LEGACY_FIELD`, `HEADER` constants; `csrf_field()` helper; `CsrfMiddleware::$failureStatus`.
- `Support\Url::sameHostOrFallback()`; `ValidationException::$dontFlash` / `filterOldInput()`.
- `HttpException` and `abort()` accept extra response headers; `Handler::statusCodeFor()`, `headersFor()`, `shouldReport()`, `$dontReport`.

### Changed
- `Kernel::boot()` only registers `config`, `session`, `csrf`, `view`, `translator` and `log` when no provider bound them (key or class alias); provider bindings are no longer overwritten (#2).
- `Handler::render()` keeps HTTP statuses (HttpException status, 404, 405 + `Allow`, 422) instead of always 500; `report()` skips expected HTTP exceptions and logs via the PSR-3 logger (#3).
- `ValidationException` → `422` JSON `{message, errors}` when the request expects JSON (#3).
- `abort()` messages are HTML-escaped (JSON for API requests) (#3).
- `validate()` no longer flashes `password*`, `_token`, `csrf_token`, `_method` as old input, and only redirects to a same-host Referer (fallback `/`) (#4).
- `CsrfMiddleware` accepts the legacy `csrf_token` field, rejects non-string tokens with 403 (no 500), answers JSON requests with JSON, and can be constructed without arguments (falls back to `app('csrf')`).
- `LocaleMiddleware` ignores `Accept-Language` entries with `q=0` (#6).
- `config()` without a key returns the repository; an array argument sets values (#9).

### Breaking Changes
- Unknown validation rules throw `InvalidArgumentException` instead of being ignored (#5).
- A field with the `boolean` rule that is absent from the input now appears in `validated()` as `false`; boolean values are cast to `bool` (#5).
- Kernel defaults no longer override provider bindings registered before `boot()` (#2).

---

## [1.0.0] — 2026-03-23

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