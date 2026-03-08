<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Application;
use Luany\Framework\Http\Middleware\LocaleMiddleware;
use Luany\Framework\Support\Translator;
use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use PHPUnit\Framework\TestCase;

class LocaleMiddlewareTest extends TestCase
{
    private string $langPath;
    private Application $app;

    protected function setUp(): void
    {
        $this->langPath = sys_get_temp_dir() . '/luany_locale_test_' . uniqid();
        mkdir($this->langPath);

        file_put_contents($this->langPath . '/en.php', '<?php return ["nav.home" => "Home"];');
        file_put_contents($this->langPath . '/pt.php', '<?php return ["nav.home" => "Início"];');

        $this->app = new Application('/tmp/luany-locale-test');
        $this->app->singleton('translator', fn() => new Translator(
            $this->langPath, 'en', 'en', ['en', 'pt']
        ));
    }

    protected function tearDown(): void
    {
        @unlink($this->langPath . '/en.php');
        @unlink($this->langPath . '/pt.php');
        @rmdir($this->langPath);
    }

    private function makeMiddleware(): LocaleMiddleware
    {
        return new LocaleMiddleware($this->app->make('translator'));
    }

    private function makeRequest(array $cookies = [], array $headers = []): Request
    {
        return new Request('GET', '/', [], [], [], $headers, [], $cookies);
    }

    private function next(): callable
    {
        return fn(Request $req) => Response::make('ok');
    }

    // ── Cookie detection ──────────────────────────────────────────────────────

    public function test_locale_set_from_cookie(): void
    {
        $request = $this->makeRequest(cookies: ['app_locale' => 'pt']);

        $this->makeMiddleware()->handle($request, $this->next());

        $this->assertSame('pt', $this->app->make('translator')->getLocale());
    }

    public function test_unsupported_cookie_locale_is_ignored(): void
    {
        $request = $this->makeRequest(cookies: ['app_locale' => 'fr']);

        $this->makeMiddleware()->handle($request, $this->next());

        $this->assertSame('en', $this->app->make('translator')->getLocale());
    }

    // ── Accept-Language detection ─────────────────────────────────────────────

    public function test_locale_set_from_accept_language_header(): void
    {
        $request = $this->makeRequest(headers: ['Accept-Language' => 'pt-PT,pt;q=0.9,en;q=0.8']);

        $this->makeMiddleware()->handle($request, $this->next());

        $this->assertSame('pt', $this->app->make('translator')->getLocale());
    }

    // ── Middleware returns response ───────────────────────────────────────────

    public function test_middleware_calls_next_and_returns_response(): void
    {
        $request  = $this->makeRequest();
        $response = $this->makeMiddleware()->handle($request, $this->next());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('ok', $response->getBody());
    }
}