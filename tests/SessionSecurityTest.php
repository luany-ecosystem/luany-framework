<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Session\FileSession;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for issue #4 — session cookie hardening.
 */
class SessionSecurityTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function test_default_cookie_params_are_hardened(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
        $params = (new FileSession())->cookieParams();

        $this->assertTrue($params['httponly']);
        $this->assertSame('Lax', $params['samesite']);
        $this->assertFalse($params['secure']);
        $this->assertSame('/', $params['path']);
    }

    public function test_secure_is_automatic_on_https(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue((new FileSession())->cookieParams()['secure']);

        $_SERVER['HTTPS'] = 'off';
        $_SERVER['SERVER_PORT'] = '443';
        $this->assertTrue((new FileSession())->cookieParams()['secure']);
    }

    public function test_options_override_defaults_and_ignore_unknown_keys(): void
    {
        $session = new FileSession('', [
            'secure'   => true,
            'samesite' => 'Strict',
            'lifetime' => 3600,
            'bogus'    => 'x',
        ]);

        $params = $session->cookieParams();
        $this->assertTrue($params['secure']);
        $this->assertSame('Strict', $params['samesite']);
        $this->assertSame(3600, $params['lifetime']);
        $this->assertArrayNotHasKey('bogus', $session->getOptions());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_start_applies_cookie_params_and_strict_mode(): void
    {
        if (headers_sent()) {
            $this->markTestSkipped('Headers already sent in this process.');
        }

        $dir = sys_get_temp_dir() . '/luany-sess-' . uniqid();
        $session = new FileSession($dir, ['name' => 'luany_session']);
        $session->start();

        $params = session_get_cookie_params();
        $this->assertTrue($params['httponly']);
        $this->assertSame('Lax', $params['samesite']);
        $this->assertSame('1', ini_get('session.use_strict_mode'));
        $this->assertSame('1', ini_get('session.use_only_cookies'));
        $this->assertSame('luany_session', session_name());

        $session->destroy();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_regenerate_changes_session_id_and_keeps_data(): void
    {
        if (headers_sent()) {
            $this->markTestSkipped('Headers already sent in this process.');
        }

        $session = new FileSession(sys_get_temp_dir() . '/luany-sess-' . uniqid());
        $session->start();
        $session->set('user_id', 7);
        $before = $session->getId();

        $session->regenerate();

        $this->assertNotSame($before, $session->getId());
        $this->assertSame(7, $session->get('user_id'));

        $session->destroy();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_kernel_reads_config_session(): void
    {
        if (headers_sent()) {
            $this->markTestSkipped('Headers already sent in this process.');
        }

        $base = sys_get_temp_dir() . '/luany-sess-cfg-' . uniqid();
        mkdir($base . '/config', 0777, true);
        file_put_contents(
            $base . '/config/session.php',
            "<?php return ['name' => 'cfg_session', 'secure' => true, 'samesite' => 'Strict', 'save_path' => '{$base}/sess'];"
        );

        $app    = new \Luany\Framework\Application($base);
        $kernel = new \Luany\Framework\Http\Kernel($app);
        $kernel->boot();

        $session = $app->make('session');
        $this->assertInstanceOf(FileSession::class, $session);
        $this->assertSame('cfg_session', session_name());
        $params = session_get_cookie_params();
        $this->assertTrue($params['secure']);
        $this->assertSame('Strict', $params['samesite']);
        $this->assertDirectoryExists($base . '/sess');

        $session->destroy();
    }
}
