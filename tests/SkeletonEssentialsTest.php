<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Routing\Route;
use Luany\Framework\Application;
use Luany\Framework\Http\Middleware\LiveReloadMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Helpers, live reload and timezone moved from the skeleton into the framework.
 */
class SkeletonEssentialsTest extends TestCase
{
    private string $base;
    private string $tz;

    protected function setUp(): void
    {
        Route::reset();
        $this->base = sys_get_temp_dir() . '/luany-ess-' . uniqid();
        mkdir($this->base . '/config', 0777, true);
        $this->tz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        Route::reset();
        date_default_timezone_set($this->tz);
        putenv('APP_ENV');
        putenv('LDE_CLIENT_PATH');
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
    }

    private function boot(array $appConfig = []): Application
    {
        file_put_contents($this->base . '/config/app.php', '<?php return ' . var_export($appConfig, true) . ';');
        $app = new Application($this->base);
        (new TestKernel($app))->boot();
        return $app;
    }

    public function test_url_asset_route_and_e_helpers(): void
    {
        $this->boot(['url' => 'https://luany.dev/']);
        Route::get('/posts/{id}', fn () => '')->name('posts.show');

        $this->assertSame('https://luany.dev', url());
        $this->assertSame('https://luany.dev/about', url('about'));
        $this->assertSame('https://luany.dev/assets/css/app.css', asset('/css/app.css'));
        $this->assertSame('https://luany.dev/posts/7', route('posts.show', ['id' => 7]));
        $this->assertSame('&lt;b&gt; &quot;x&quot;', e('<b> "x"'));

        $this->expectException(\RuntimeException::class);
        route('missing');
    }

    public function test_timezone_from_config(): void
    {
        $this->boot(['timezone' => 'Africa/Luanda']);
        $this->assertSame('Africa/Luanda', date_default_timezone_get());
    }

    public function test_invalid_timezone_is_ignored(): void
    {
        date_default_timezone_set('UTC');
        $this->boot(['timezone' => 'Mars/Olympus']);
        $this->assertSame('UTC', date_default_timezone_get());
    }

    // ── Live reload ──────────────────────────────────────────────────────────

    private function html(): \Closure
    {
        return fn () => Response::make('<html><body><p>x</p></body></html>');
    }

    public function test_live_reload_injects_only_in_local_development(): void
    {
        $mw = new LiveReloadMiddleware();
        $local = new Request('GET', '/', server: ['REMOTE_ADDR' => '127.0.0.1']);

        putenv('APP_ENV=production');
        $this->assertStringNotContainsString('__luany_dev', $mw->handle($local, $this->html())->getBody());

        putenv('APP_ENV=development');
        $body = $mw->handle($local, $this->html())->getBody();
        $this->assertStringContainsString('<script src="/__luany_dev/client.js"></script></body>', $body);
        $this->assertSame(1, substr_count($body, 'client.js'));

        $remote = new Request('GET', '/', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $this->assertStringNotContainsString('__luany_dev', $mw->handle($remote, $this->html())->getBody());
    }

    public function test_live_reload_skips_json_and_errors_and_serves_client(): void
    {
        putenv('APP_ENV=development');
        $mw    = new LiveReloadMiddleware();
        $local = new Request('GET', '/', server: ['REMOTE_ADDR' => '::1']);

        $json = $mw->handle($local, fn () => Response::json(['a' => '</body>']));
        $this->assertStringNotContainsString('client.js', $json->getBody());

        $error = $mw->handle($local, fn () => Response::make('<body></body>', 500));
        $this->assertStringNotContainsString('client.js', $error->getBody());

        $client = tempnam(sys_get_temp_dir(), 'lde');
        file_put_contents($client, 'console.log("lde")');
        putenv("LDE_CLIENT_PATH={$client}");

        $served = $mw->handle(new Request('GET', LiveReloadMiddleware::CLIENT_URI, server: ['REMOTE_ADDR' => '127.0.0.1']), fn () => Response::make('route'));
        $this->assertSame('console.log("lde")', $served->getBody());
        $this->assertStringContainsString('javascript', $served->getHeaders()['Content-Type']);
        @unlink($client);
    }
}
