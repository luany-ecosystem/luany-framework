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
