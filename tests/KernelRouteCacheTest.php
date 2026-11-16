<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Routing\Route;
use Luany\Core\Routing\Router;
use Luany\Framework\Application;
use Luany\Framework\Http\Kernel;
use PHPUnit\Framework\TestCase;

final class RouteCacheTestController
{
    public function index(): string
    {
        return 'from-cache';
    }
}

class RouteCacheTestKernel extends Kernel
{
    public function disableRouteCache(): void
    {
        $this->routeCacheFile = '';
    }
}

/**
 * The Kernel loads routes from storage/cache/routes.php when it exists.
 */
class KernelRouteCacheTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        Route::reset();
        $this->base = sys_get_temp_dir() . '/luany-route-cache-' . uniqid();
        mkdir($this->base . '/routes', 0777, true);
        file_put_contents(
            $this->base . '/routes/http.php',
            "<?php\n\\Luany\\Core\\Routing\\Route::get('/', fn () => 'from-files');\n"
        );
    }

    protected function tearDown(): void
    {
        Route::reset();
        @unlink($this->base . '/routes/http.php');
        @unlink($this->base . '/storage/cache/routes.php');
        @rmdir($this->base . '/storage/cache');
        @rmdir($this->base . '/storage');
        @rmdir($this->base . '/routes');
        @rmdir($this->base);
    }

    private function writeCache(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/', [RouteCacheTestController::class, 'index'], 'home');
        $router->saveToCache($this->base . '/storage/cache/routes.php');
    }

    public function test_routes_load_from_files_without_a_cache(): void
    {
        $kernel = new Kernel(new Application($this->base));
        $kernel->boot();

        $this->assertSame('from-files', $kernel->handle(new Request('GET', '/'))->getBody());
    }

    public function test_routes_load_from_the_cache_when_present(): void
    {
        $this->writeCache();

        $kernel = new Kernel(new Application($this->base));
        $kernel->boot();

        $this->assertSame('from-cache', $kernel->handle(new Request('GET', '/'))->getBody());
        $this->assertSame('/', Route::router()->getNamedRoute('home'));
    }

    public function test_route_cache_path_defaults_to_storage_cache(): void
    {
        $kernel = new Kernel(new Application($this->base));

        $this->assertSame(
            $this->base . DIRECTORY_SEPARATOR . 'storage/cache/routes.php',
            $kernel->routeCachePath()
        );
    }

    public function test_route_cache_can_be_disabled(): void
    {
        $this->writeCache();

        $kernel = new RouteCacheTestKernel(new Application($this->base));
        $kernel->disableRouteCache();
        $kernel->boot();

        $this->assertNull($kernel->routeCachePath());
        $this->assertSame('from-files', $kernel->handle(new Request('GET', '/'))->getBody());
    }

    public function test_invalid_cache_file_falls_back_to_route_files(): void
    {
        mkdir($this->base . '/storage/cache', 0777, true);
        file_put_contents($this->base . '/storage/cache/routes.php', "<?php return 'garbage';\n");

        $kernel = new Kernel(new Application($this->base));
        $kernel->boot();

        $this->assertSame('from-files', $kernel->handle(new Request('GET', '/'))->getBody());
    }
}
