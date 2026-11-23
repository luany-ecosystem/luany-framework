<?php

namespace Luany\Framework\Tests\Auth;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Framework\Auth\AuthManager;
use Luany\Framework\Auth\Hasher;
use Luany\Framework\Auth\ModelUserProvider;
use Luany\Framework\Contracts\SessionInterface;
use Luany\Framework\Http\Middleware\Authenticate;
use Luany\Framework\Http\Middleware\RedirectIfAuthenticated;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    private ArraySession $session;
    private AuthManager $auth;
    private Hasher $hasher;

    protected function setUp(): void
    {
        FakeUser::$rows = [];
        $this->hasher  = new Hasher(PASSWORD_BCRYPT, ['cost' => 4]);
        $this->session = new ArraySession();
        $this->auth    = new AuthManager($this->session, new ModelUserProvider(FakeUser::class), $this->hasher);

        FakeUser::$rows[1] = ['id' => 1, 'email' => 'ana@luany.dev', 'password' => $this->hasher->make('segredo')];
    }

    public function test_attempt_logs_in_and_regenerates_the_session(): void
    {
        $changes = 0;
        $this->auth->onSessionChange(function () use (&$changes) { $changes++; });

        $this->assertFalse($this->auth->check());
        $this->assertTrue($this->auth->attempt(['email' => 'ana@luany.dev', 'password' => 'segredo']));

        $this->assertTrue($this->auth->check());
        $this->assertSame(1, $this->auth->id());
        $this->assertSame('ana@luany.dev', $this->auth->user()?->email);
        $this->assertSame(1, $this->session->regenerated);
        $this->assertSame(1, $changes, 'CSRF token rotated');
    }

    public function test_wrong_credentials_fail_without_logging_in(): void
    {
        $this->assertFalse($this->auth->attempt(['email' => 'ana@luany.dev', 'password' => 'errada']));
        $this->assertFalse($this->auth->attempt(['email' => 'ninguem@luany.dev', 'password' => 'segredo']));
        $this->assertFalse($this->auth->attempt(['email' => 'ana@luany.dev']));
        $this->assertFalse($this->auth->attempt(['email' => ['ana@luany.dev'], 'password' => 'segredo']), 'array input never matches');
        $this->assertFalse($this->auth->check());
        $this->assertSame(0, $this->session->regenerated);
    }

    public function test_user_is_restored_from_the_session(): void
    {
        $this->session->set(AuthManager::SESSION_KEY, 1);
        $auth = new AuthManager($this->session, new ModelUserProvider(FakeUser::class), $this->hasher);

        $this->assertTrue($auth->check());
        $this->assertSame('ana@luany.dev', $auth->user()?->email);
    }

    public function test_logout(): void
    {
        $this->auth->attempt(['email' => 'ana@luany.dev', 'password' => 'segredo']);
        $this->auth->logout();

        $this->assertTrue($this->auth->guest());
        $this->assertNull($this->session->get(AuthManager::SESSION_KEY));
        $this->assertSame(2, $this->session->regenerated);
    }

    public function test_old_hashes_are_upgraded_on_login(): void
    {
        FakeUser::$rows[1]['password'] = password_hash('segredo', PASSWORD_BCRYPT, ['cost' => 5]);

        $this->assertTrue($this->auth->attempt(['email' => 'ana@luany.dev', 'password' => 'segredo']));
        $this->assertFalse($this->hasher->needsRehash(FakeUser::$rows[1]['password']));
    }

    public function test_intended_url_is_local_only(): void
    {
        $this->auth->setIntended('/painel?x=1');
        $this->assertSame('/painel?x=1', $this->auth->intended());
        $this->assertSame('/', $this->auth->intended(), 'used once');

        $this->auth->setIntended('//evil.example');
        $this->assertSame('/home', $this->auth->intended('/home'));
    }

    public function test_authenticate_middleware(): void
    {
        $next = fn (Request $r): Response => Response::make('ok');
        $middleware = new Authenticate($this->auth);

        $response = $middleware->handle(new Request('GET', '/painel'), $next);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/painel', $this->auth->intended());

        $json = $middleware->handle(new Request('GET', '/api/eu', [], [], [], ['Accept' => 'application/json']), $next);
        $this->assertSame(401, $json->getStatusCode());

        $this->auth->attempt(['email' => 'ana@luany.dev', 'password' => 'segredo']);
        $this->assertSame('ok', $middleware->handle(new Request('GET', '/painel'), $next)->getBody());
    }

    public function test_redirect_if_authenticated_middleware(): void
    {
        $next = fn (Request $r): Response => Response::make('form');
        $middleware = new RedirectIfAuthenticated($this->auth);

        $this->assertSame('form', $middleware->handle(new Request('GET', '/login'), $next)->getBody());

        $this->auth->attempt(['email' => 'ana@luany.dev', 'password' => 'segredo']);
        $this->assertSame(302, $middleware->handle(new Request('GET', '/login'), $next)->getStatusCode());
    }

    public function test_provider_rejects_unsafe_columns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ModelUserProvider(FakeUser::class))->retrieveByCredentials(['email = 1 OR 1' => 'x']);
    }

    public function test_hasher_defaults(): void
    {
        $hasher = new Hasher();
        $hash   = $hasher->make('x');

        $this->assertTrue($hasher->check('x', $hash));
        $this->assertFalse($hasher->check('y', $hash));
        $this->assertFalse($hasher->check('x', ''));
        $this->assertFalse($hasher->needsRehash($hash));
    }
}
