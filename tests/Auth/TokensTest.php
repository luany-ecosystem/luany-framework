<?php

namespace Luany\Framework\Tests\Auth;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\RateLimit\FileRateLimiter;
use Luany\Framework\Auth\DatabaseTokenRepository;
use Luany\Framework\Auth\EmailVerification;
use Luany\Framework\Auth\Hasher;
use Luany\Framework\Auth\ModelUserProvider;
use Luany\Framework\Auth\PasswordBroker;
use Luany\Framework\Http\Middleware\ValidateSignature;
use Luany\Framework\Security\SignedUrl;
use PHPUnit\Framework\TestCase;

class TokensTest extends TestCase
{
    private const KEY = 'base64:' . 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    private \PDO $pdo;
    private Hasher $hasher;
    private DatabaseTokenRepository $repo;
    private string $limits;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec(DatabaseTokenRepository::createTableSql());
        $this->repo   = new DatabaseTokenRepository($this->pdo);
        $this->hasher = new Hasher(PASSWORD_BCRYPT, ['cost' => 4]);
        $this->limits = sys_get_temp_dir() . '/luany-limits-' . bin2hex(random_bytes(4));

        FakeUser::$rows = [
            1 => ['id' => 1, 'email' => 'ana@luany.dev', 'password' => $this->hasher->make('antiga')],
            2 => ['id' => 2, 'email' => 'rui@luany.dev', 'password' => $this->hasher->make('outra')],
        ];
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->limits));
    }

    private function broker(bool $limited = false): PasswordBroker
    {
        return new PasswordBroker(
            new ModelUserProvider(FakeUser::class),
            $this->hasher,
            $this->repo,
            $limited ? new FileRateLimiter($this->limits) : null,
        );
    }

    /** @return array{string, ?string} status and token sent */
    private function requestLink(PasswordBroker $broker, string $email, string $ip = '10.0.0.1'): array
    {
        $sent = null;
        $status = $broker->sendResetLink(['email' => $email], $ip, function (object $user, string $token) use (&$sent) {
            $sent = $token;
        });

        return [$status, $sent];
    }

    public function test_reset_flow_and_single_use(): void
    {
        $broker = $this->broker();
        [$status, $token] = $this->requestLink($broker, 'ana@luany.dev');

        $this->assertSame(PasswordBroker::RESET_LINK_SENT, $status);
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));
        $row = $this->repo->find('1');
        $this->assertSame(hash('sha256', $token), $row['token_hash'], 'only the hash is stored');
        $this->assertStringNotContainsString($token, json_encode($row));

        $this->assertSame(PasswordBroker::PASSWORD_RESET, $broker->reset(['email' => 'ana@luany.dev', 'token' => $token, 'password' => 'nova']));
        $this->assertTrue($this->hasher->check('nova', FakeUser::$rows[1]['password']));
        $this->assertSame(PasswordBroker::INVALID_TOKEN, $broker->reset(['email' => 'ana@luany.dev', 'token' => $token, 'password' => 'outra']), 'single use');
    }

    public function test_unknown_email_gets_the_same_answer(): void
    {
        [$status, $token] = $this->requestLink($this->broker(), 'ninguem@luany.dev');

        $this->assertSame(PasswordBroker::RESET_LINK_SENT, $status);
        $this->assertNull($token);
    }

    public function test_invalid_tokens(): void
    {
        $broker = $this->broker();
        [, $token] = $this->requestLink($broker, 'ana@luany.dev');

        foreach ([
            ['email' => 'ana@luany.dev', 'token' => str_repeat('0', 64), 'password' => 'x'],   // wrong token
            ['email' => 'rui@luany.dev', 'token' => $token, 'password' => 'x'],                  // another user's token
            ['email' => 'ana@luany.dev', 'token' => [$token], 'password' => 'x'],                // array
            ['email' => 'ana@luany.dev', 'token' => $token, 'password' => ''],                   // empty password
        ] as $i => $credentials) {
            $this->assertSame(PasswordBroker::INVALID_TOKEN, $broker->reset($credentials), (string) $i);
        }
        $this->assertTrue($this->hasher->check('antiga', FakeUser::$rows[1]['password']));
    }

    public function test_a_new_token_replaces_the_old_one(): void
    {
        $broker = $this->broker();
        [, $first]  = $this->requestLink($broker, 'ana@luany.dev');
        [, $second] = $this->requestLink($broker, 'ana@luany.dev');

        $this->assertSame(PasswordBroker::INVALID_TOKEN, $broker->reset(['email' => 'ana@luany.dev', 'token' => $first, 'password' => 'x']));
        $this->assertSame(PasswordBroker::PASSWORD_RESET, $broker->reset(['email' => 'ana@luany.dev', 'token' => $second, 'password' => 'x']));
    }

    public function test_expired_token(): void
    {
        $broker = $this->broker();
        [, $token] = $this->requestLink($broker, 'ana@luany.dev');
        $this->pdo->exec('UPDATE password_reset_tokens SET created_at = created_at - 3601');

        $this->assertSame(PasswordBroker::INVALID_TOKEN, $broker->reset(['email' => 'ana@luany.dev', 'token' => $token, 'password' => 'x']));
    }

    public function test_changing_the_password_another_way_voids_the_token(): void
    {
        $broker = $this->broker();
        [, $token] = $this->requestLink($broker, 'ana@luany.dev');
        FakeUser::$rows[1]['password'] = $this->hasher->make('mudada-no-perfil');

        $this->assertSame(PasswordBroker::INVALID_TOKEN, $broker->reset(['email' => 'ana@luany.dev', 'token' => $token, 'password' => 'x']));
    }

    public function test_requests_are_limited_per_email_and_per_ip(): void
    {
        $broker = $this->broker(true);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(PasswordBroker::RESET_LINK_SENT, $this->requestLink($broker, 'ana@luany.dev', "10.0.0.{$i}")[0]);
        }
        $this->assertSame(PasswordBroker::THROTTLED, $this->requestLink($broker, 'ANA@luany.dev', '10.0.0.9')[0], 'per email, case-insensitive');

        for ($i = 0; $i < 10; $i++) {
            $this->requestLink($broker, "u{$i}@luany.dev", '10.9.9.9');
        }
        $this->assertSame(PasswordBroker::THROTTLED, $this->requestLink($broker, 'novo@luany.dev', '10.9.9.9')[0], 'per IP');
    }

    public function test_signed_urls(): void
    {
        $signer = new SignedUrl(self::KEY);
        $url = $signer->sign('/download', ['file' => 'a b.pdf'], 60);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertTrue($signer->verify('/download', $query));
        $this->assertFalse($signer->verify('/other', $query), 'path is signed');
        $this->assertFalse($signer->verify('/download', ['file' => 'b.pdf'] + $query), 'params are signed');
        $this->assertFalse($signer->verify('/download', ['expires' => time() + 9999] + $query), 'expiry is signed');
        $this->assertFalse($signer->verify('/download', ['file' => ['x']] + $query));
        $this->assertFalse((new SignedUrl('base64:' . base64_encode(random_bytes(32))))->verify('/download', $query), 'another APP_KEY');

        $expired = $signer->sign('/download', [], -1);
        parse_str((string) parse_url($expired, PHP_URL_QUERY), $old);
        $this->assertFalse($signer->verify('/download', $old));
    }

    public function test_email_verification_link_is_bound_to_user_and_email(): void
    {
        $verification = new EmailVerification(new SignedUrl(self::KEY));
        $url = $verification->url(1, 'Ana@Luany.dev');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $request = new Request('GET', '/email/verify', $query);

        $this->assertTrue($verification->verify($request, 1, 'ana@luany.dev'));
        $this->assertFalse($verification->verify($request, 2, 'ana@luany.dev'), 'other user');
        $this->assertFalse($verification->verify($request, 1, 'nova@luany.dev'), 'email changed');
    }

    public function test_validate_signature_middleware_sets_no_referrer(): void
    {
        $signer = new SignedUrl(self::KEY);
        parse_str((string) parse_url($signer->sign('/email/verify', ['id' => '1'], 60), PHP_URL_QUERY), $query);
        $middleware = new ValidateSignature($signer);
        $next = fn (Request $r): Response => Response::make('ok');

        $ok = $middleware->handle(new Request('GET', '/email/verify', $query), $next);
        $this->assertSame('ok', $ok->getBody());
        $this->assertSame('no-referrer', $ok->getHeaders()['Referrer-Policy'] ?? null);

        $bad = $middleware->handle(new Request('GET', '/email/verify', ['id' => '2'] + $query), $next);
        $this->assertSame(403, $bad->getStatusCode());
        $this->assertSame('no-referrer', $bad->getHeaders()['Referrer-Policy'] ?? null);
    }

    public function test_short_app_key_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        new SignedUrl('');
    }
}
