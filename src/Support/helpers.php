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
