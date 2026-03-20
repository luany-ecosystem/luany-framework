<?php

use Luany\Framework\Application;
use Luany\Framework\Support\Env;
use Luany\Core\Http\Response;

if (!function_exists('app')) {
    /**
     * Get the Application instance or resolve a binding from the container.
     *
     * Usage:
     *   app()                  → Application instance
     *   app('db')              → resolved 'db' binding
     *   app(MyService::class)  → resolved MyService
     */
    function app(?string $abstract = null): mixed
    {
        $instance = Application::getInstance();

        if ($abstract === null) {
            return $instance;
        }

        return $instance->make($abstract);
    }
}

if (!function_exists('env')) {
    /**
     * Get an environment variable value.
     *
     * Usage:
     *   env('APP_NAME', 'Luany')
     *   env('DB_PORT', 3306)
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('base_path')) {
    /**
     * Get the absolute path to the application root.
     *
     * Usage:
     *   base_path()              → /var/www/my-app
     *   base_path('config')      → /var/www/my-app/config
     *   base_path('routes/http.php')
     */
    function base_path(string $path = ''): string
    {
        return Application::getInstance()->basePath($path);
    }
}

if (!function_exists('view')) {
    /**
     * Render a view using the LTE engine.
     * The engine must be registered as 'view' in the container.
     *
     * Usage:
     *   view('pages.home', ['user' => $user])
     *   return Response::make(view('pages.dashboard', $data));
     */
    /** @param array<string, mixed> $data */
    function view(string $name, array $data = []): string
    {
        /** @var \Luany\Lte\Engine $engine */
        $engine = app('view');
        return $engine->render($name, $data);
    }
}

if (!function_exists('redirect')) {
    /**
     * Create a redirect Response.
     *
     * Usage:
     *   return redirect('/dashboard');
     *   return redirect('/login', 301);
     */
    function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }
}

if (!function_exists('response')) {
    /**
     * Create a Response instance.
     *
     * Usage:
     *   return response('<h1>Hello</h1>');
     *   return response('<h1>Created</h1>', 201);
     */
    function response(string $body = '', int $status = 200): Response
    {
        return Response::make($body, $status);
    }
}

if (!function_exists('__')) {
    /**
     * Translate a key using the bound Translator instance.
     *
     * Usage:
     *   __('nav.home')
     *   __('footer.copyright', ['year' => date('Y'), 'name' => 'Luany'])
     */
    /** @param array<string, string> $replace */
    function __(string $key, array $replace = []): string
    {
        /** @var \Luany\Framework\Support\Translator $translator */
        $translator = app('translator');
        return $translator->get($key, $replace);
    }
}

if (!function_exists('locale')) {
    /**
     * Return the currently active locale code.
     *
     * Usage:
     *   locale()           → 'en' | 'pt'
     *   locale() === 'pt'  → true
     */
    function locale(): string
    {
        return app('translator')->getLocale();
    }
}

if (!function_exists('config')) {
    /**
     * Get a configuration value using dot-notation.
     *
     * Usage:
     *   config('app.name')              // value
     *   config('app.missing', 'default') // fallback
     */
    function config(string $key, mixed $default = null): mixed
    {
        /** @var \Luany\Framework\Support\Config $config */
        $config = app('config');
        return $config->get($key, $default);
    }
}

if (!function_exists('session')) {
    /**
     * Get the session instance, or get/set a session value.
     *
     * Usage:
     *   session()                    // SessionInterface instance
     *   session('user_id')           // get value
     *   session('user_id', 'default') // get with fallback
     */
    function session(?string $key = null, mixed $default = null): mixed
    {
        /** @var \Luany\Framework\Contracts\SessionInterface $session */
        $session = app('session');

        if ($key === null) {
            return $session;
        }

        return $session->get($key, $default);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Get the current CSRF token.
     *
     * Usage:
     *   <input type="hidden" name="_token" value="<?= csrf_token() ?>">
     */
    function csrf_token(): string
    {
        /** @var \Luany\Framework\Security\CsrfToken $csrf */
        $csrf = app('csrf');
        return $csrf->token();
    }
}

if (!function_exists('old')) {
    /**
     * Get flashed old input from the previous request.
     *
     * Usage:
     *   <input name="email" value="<?= old('email') ?>">
     *   old('name', 'default')
     */
    function old(string $key, mixed $default = null): mixed
    {
        /** @var \Luany\Framework\Contracts\SessionInterface $session */
        $session = app('session');
        $oldInput = $session->get('_old_input', []);

        return $oldInput[$key] ?? $default;
    }
}

if (!function_exists('validate')) {
    /**
     * Validate data against a rule set.
     *
     * On success — returns the validated data array (only validated fields).
     * On failure — flashes errors + old input to session, then throws
     *              ValidationException which the Kernel converts to a redirect.
     *
     * Usage:
     *   $data = validate($request->body(), [
     *       'name'  => 'required|string|min:2|max:255',
     *       'email' => 'required|email|unique:users,email',
     *   ], '/users/create');
     *
     *   // $data contains only the validated fields — safe to use directly.
     *   User::create($data);
     *
     * @param  array<string, mixed>  $data       Data to validate (e.g. $request->body())
     * @param  array<string, string> $rules       Validation rules per field
     * @param  string                $redirectTo  URL to redirect back to on failure.
     *                                            Defaults to HTTP_REFERER or '/'.
     * @return array<string, mixed>  Validated data
     * @throws \Luany\Framework\Exceptions\ValidationException on failure
     */
    function validate(array $data, array $rules, string $redirectTo = ''): array
    {
        $v = \Luany\Framework\Validation\Validator::make($data, $rules);

        if ($v->passes()) {
            return $v->validated();
        }

        /** @var \Luany\Framework\Contracts\SessionInterface $session */
        $session = app('session');
        $session->flash('errors', $v->errors());
        $session->flash('_old_input', $data);

        $url = $redirectTo !== '' ? $redirectTo : ($_SERVER['HTTP_REFERER'] ?? '/');

        throw new \Luany\Framework\Exceptions\ValidationException($v->errors(), $url);
    }
}

if (!function_exists('abort')) {
    /**
     * Abort the request with an HTTP error response.
     *
     * Throws an HttpException which the Kernel catches and converts
     * into the appropriate HTTP Response (404, 403, 500, etc.).
     *
     * Usage:
     *   abort(404);
     *   abort(403, 'Forbidden');
     *   abort(422, 'Unprocessable content');
     *
     * @throws \Luany\Framework\Exceptions\HttpException
     */
    function abort(int $code, string $message = ''): never
    {
        throw new \Luany\Framework\Exceptions\HttpException($code, $message);
    }
}