<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Application;
use Luany\Framework\Contracts\SessionInterface;
use Luany\Framework\Exceptions\ValidationException;
use Luany\Framework\Support\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for issue #4 — sensitive old input and open redirects.
 */
class ValidateHelperSecurityTest extends TestCase
{
    private array $flashed = [];
    private array $server;

    protected function setUp(): void
    {
        $this->server  = $_SERVER;
        $this->flashed = [];

        $session = $this->createMock(SessionInterface::class);
        $session->method('flash')->willReturnCallback(function (string $key, mixed $value) {
            $this->flashed[$key] = $value;
        });

        $app = new Application('/tmp/luany-validate-test');
        $app->instance('session', $session);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private function failValidation(array $data): ValidationException
    {
        try {
            validate($data, ['email' => 'required|email']);
        } catch (ValidationException $e) {
            return $e;
        }
        $this->fail('Expected ValidationException');
    }

    public function test_old_input_excludes_sensitive_fields(): void
    {
        $this->failValidation([
            'name'                  => 'Ana',
            'email'                 => 'not-an-email',
            'password'              => 'secret',
            'password_confirmation' => 'secret',
            'Password_New'          => 'secret',
            '_token'                => 'tok',
            'csrf_token'            => 'tok',
            '_method'               => 'PUT',
        ]);

        $this->assertSame(['name' => 'Ana', 'email' => 'not-an-email'], $this->flashed['_old_input']);
    }

    public function test_dont_flash_is_extendable(): void
    {
        $original = ValidationException::$dontFlash;
        ValidationException::$dontFlash[] = 'card_number';

        try {
            $this->assertSame(['a' => 1], ValidationException::filterOldInput(['a' => 1, 'card_number' => '4111']));
        } finally {
            ValidationException::$dontFlash = $original;
        }
    }

    public function test_redirect_uses_same_host_referer(): void
    {
        $_SERVER['HTTP_HOST']    = 'app.test';
        $_SERVER['HTTP_REFERER'] = 'https://app.test/users/create?x=1';

        $this->assertSame('https://app.test/users/create?x=1', $this->failValidation([])->getRedirectTo());
    }

    public function test_redirect_ignores_foreign_referer(): void
    {
        $_SERVER['HTTP_HOST']    = 'app.test';
        $_SERVER['HTTP_REFERER'] = 'https://evil.test/phish';

        $this->assertSame('/', $this->failValidation([])->getRedirectTo());
    }

    public function test_redirect_without_referer_falls_back(): void
    {
        unset($_SERVER['HTTP_REFERER']);

        $this->assertSame('/', $this->failValidation([])->getRedirectTo());
    }

    public function test_explicit_redirect_is_kept(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://evil.test/';

        try {
            validate([], ['email' => 'required'], '/signup');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('/signup', $e->getRedirectTo());
        }
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function urlCases(): array
    {
        return [
            'relative path'        => ['/users?page=2', '/users?page=2'],
            'same host'            => ['http://app.test/a', 'http://app.test/a'],
            'same host uppercase'  => ['https://APP.test/a', 'https://APP.test/a'],
            'other host'           => ['https://evil.test/a', '/'],
            'protocol relative'    => ['//evil.test/a', '/'],
            'backslash trick'      => ['/\\evil.test', '/'],
            'userinfo trick'       => ['https://app.test@evil.test/', '/'],
            'javascript scheme'    => ['javascript:alert(1)', '/'],
            'different port'       => ['https://app.test:8443/', '/'],
            'no leading slash'     => ['users', '/'],
            'control character'    => ["/a\r\nLocation: x", '/'],
            'null'                 => [null, '/'],
        ];
    }

    #[DataProvider('urlCases')]
    public function test_same_host_or_fallback(?string $url, string $expected): void
    {
        $this->assertSame($expected, Url::sameHostOrFallback($url, 'app.test'));
    }

    public function test_same_host_with_port(): void
    {
        $this->assertSame('http://localhost:8000/x', Url::sameHostOrFallback('http://localhost:8000/x', 'localhost:8000'));
        $this->assertSame('/', Url::sameHostOrFallback('http://localhost:8000/x', null));
    }
}
