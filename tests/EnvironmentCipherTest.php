<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Support\Env;
use Luany\Framework\Support\EnvironmentCipher;
use PHPUnit\Framework\TestCase;

class EnvironmentCipherTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $vars = ['LUANY_ENV_KEY', 'LUANY_ENV_KEY_PRODUCTION', 'APP_ENV', 'SECRET_TOKEN', 'DB_PASS', 'FROM_PLAIN'];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/luany-envcipher-' . uniqid();
        mkdir($this->dir);
        $this->clearVars();
        Env::reset();
    }

    protected function tearDown(): void
    {
        $this->clearVars();
        Env::reset();
        array_map('unlink', array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->dir);
    }

    private function clearVars(): void
    {
        foreach ($this->vars as $v) {
            putenv($v);
            unset($_ENV[$v], $_SERVER[$v]);
        }
    }

    private function setVar(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_SERVER[$name] = $value;
    }

    public function test_round_trip_and_format(): void
    {
        $key = EnvironmentCipher::generateKey();
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $key);

        $file = EnvironmentCipher::encrypt("DB_PASS=s3cret\nSECRET_TOKEN=\"a b\"\n", $key, 'production');
        $this->assertStringStartsWith("LUANY-ENV v1\n", $file);
        $this->assertStringNotContainsString('s3cret', $file);
        $this->assertSame("DB_PASS=s3cret\nSECRET_TOKEN=\"a b\"\n", EnvironmentCipher::decrypt($file, $key, 'production'));

        $this->assertNotSame($file, EnvironmentCipher::encrypt("DB_PASS=s3cret\nSECRET_TOKEN=\"a b\"\n", $key, 'production'), 'random nonce');
    }

    public function test_wrong_key_wrong_environment_and_tampering_fail(): void
    {
        $key  = EnvironmentCipher::generateKey();
        $file = EnvironmentCipher::encrypt('A=1', $key, 'production');

        foreach ([
            fn () => EnvironmentCipher::decrypt($file, EnvironmentCipher::generateKey(), 'production'),
            fn () => EnvironmentCipher::decrypt($file, $key, 'staging'),
            fn () => EnvironmentCipher::decrypt(substr($file, 0, -6) . 'AAAA' . "\n", $key, 'production'),
            fn () => EnvironmentCipher::decrypt('A=1', $key, 'production'),
            fn () => EnvironmentCipher::decrypt($file, 'not-a-key', 'production'),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("attempt {$i} should fail");
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_names(): void
    {
        $this->assertSame('.env.encrypted', EnvironmentCipher::fileName());
        $this->assertSame('.env.production.encrypted', EnvironmentCipher::fileName('Production'));
        $this->assertSame('LUANY_ENV_KEY_STAGING_EU', EnvironmentCipher::keyVariable('staging-eu'));

        $this->expectException(\InvalidArgumentException::class);
        EnvironmentCipher::fileName('../prod');
    }

    public function test_env_load_decrypts_the_app_env_file_in_memory(): void
    {
        $key = EnvironmentCipher::generateKey();
        file_put_contents($this->dir . '/.env.production.encrypted', EnvironmentCipher::encrypt("DB_PASS=s3cret\nSECRET_TOKEN=\"a b\"\n", $key, 'production'));
        $this->setVar('APP_ENV', 'production');
        $this->setVar('LUANY_ENV_KEY_PRODUCTION', $key);
        $this->setVar('LUANY_ENV_KEY', EnvironmentCipher::generateKey());   // the specific key wins

        Env::load($this->dir);

        $this->assertSame('s3cret', Env::get('DB_PASS'));
        $this->assertSame('a b', Env::get('SECRET_TOKEN'));
        $this->assertSame(['.env.production.encrypted'], array_map('basename', glob($this->dir . '/.env*') ?: []), 'nothing written to disk');
    }

    public function test_real_environment_variables_win(): void
    {
        $key = EnvironmentCipher::generateKey();
        file_put_contents($this->dir . '/.env.encrypted', EnvironmentCipher::encrypt("DB_PASS=from-file\n", $key));
        $this->setVar('LUANY_ENV_KEY', $key);
        $this->setVar('DB_PASS', 'from-server');

        Env::load($this->dir);

        $this->assertSame('from-server', Env::get('DB_PASS'));
    }

    public function test_plain_env_wins_over_encrypted_file(): void
    {
        file_put_contents($this->dir . '/.env', "FROM_PLAIN=yes\n");
        file_put_contents($this->dir . '/.env.encrypted', 'garbage that must not be read');

        Env::load($this->dir);

        $this->assertSame('yes', Env::get('FROM_PLAIN'));
    }

    public function test_missing_key_is_a_clear_error(): void
    {
        file_put_contents($this->dir . '/.env.production.encrypted', EnvironmentCipher::encrypt('A=1', EnvironmentCipher::generateKey(), 'production'));
        $this->setVar('APP_ENV', 'production');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LUANY_ENV_KEY_PRODUCTION is not set');
        Env::load($this->dir);
    }
}
