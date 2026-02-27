<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Support\Env;
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
        // Clean up any test variables
        unset($_ENV['TEST_KEY'], $_ENV['TEST_BOOL_TRUE'], $_ENV['TEST_BOOL_FALSE'],
              $_ENV['TEST_NULL'], $_ENV['TEST_EMPTY'], $_ENV['REQUIRED_A'], $_ENV['REQUIRED_B']);
    }

    protected function tearDown(): void
    {
        Env::reset();
        unset($_ENV['TEST_KEY'], $_ENV['TEST_BOOL_TRUE'], $_ENV['TEST_BOOL_FALSE'],
              $_ENV['TEST_NULL'], $_ENV['TEST_EMPTY'], $_ENV['REQUIRED_A'], $_ENV['REQUIRED_B']);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function test_get_returns_default_when_missing(): void
    {
        $this->assertNull(Env::get('NONEXISTENT'));
        $this->assertSame('fallback', Env::get('NONEXISTENT', 'fallback'));
        $this->assertSame(42, Env::get('NONEXISTENT', 42));
    }

    public function test_get_reads_env_variable(): void
    {
        $_ENV['TEST_KEY'] = 'hello';
        $this->assertSame('hello', Env::get('TEST_KEY'));
    }

    public function test_get_casts_true_string(): void
    {
        $_ENV['TEST_BOOL_TRUE'] = 'true';
        $this->assertTrue(Env::get('TEST_BOOL_TRUE'));
    }

    public function test_get_casts_false_string(): void
    {
        $_ENV['TEST_BOOL_FALSE'] = 'false';
        $this->assertFalse(Env::get('TEST_BOOL_FALSE'));
    }

    public function test_get_casts_null_string(): void
    {
        $_ENV['TEST_NULL'] = 'null';
        $this->assertNull(Env::get('TEST_NULL'));
    }

    public function test_get_casts_empty_string(): void
    {
        $_ENV['TEST_EMPTY'] = 'empty';
        $this->assertSame('', Env::get('TEST_EMPTY'));
    }

    public function test_get_casts_parenthesised_true(): void
    {
        $_ENV['TEST_BOOL_TRUE'] = '(true)';
        $this->assertTrue(Env::get('TEST_BOOL_TRUE'));
    }

    public function test_get_casts_parenthesised_false(): void
    {
        $_ENV['TEST_BOOL_FALSE'] = '(false)';
        $this->assertFalse(Env::get('TEST_BOOL_FALSE'));
    }

    public function test_get_casts_parenthesised_null(): void
    {
        $_ENV['TEST_NULL'] = '(null)';
        $this->assertNull(Env::get('TEST_NULL'));
    }

    // ── required ──────────────────────────────────────────────────────────────

    public function test_required_passes_when_all_set(): void
    {
        $_ENV['REQUIRED_A'] = 'value_a';
        $_ENV['REQUIRED_B'] = 'value_b';
        // Should not throw
        Env::required(['REQUIRED_A', 'REQUIRED_B']);
        $this->assertTrue(true);
    }

    public function test_required_throws_when_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        Env::required(['REQUIRED_A']);
    }

    public function test_required_reports_all_missing_keys(): void
    {
        try {
            Env::required(['MISSING_X', 'MISSING_Y']);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MISSING_X', $e->getMessage());
            $this->assertStringContainsString('MISSING_Y', $e->getMessage());
        }
    }

    // ── load idempotency ──────────────────────────────────────────────────────

    public function test_load_is_idempotent(): void
    {
        // Load from a non-existent path — should not throw
        Env::load('/tmp/nonexistent-luany-test-dir');
        Env::load('/tmp/nonexistent-luany-test-dir');
        $this->assertTrue(true);
    }

    public function test_load_does_not_throw_when_env_missing(): void
    {
        Env::load('/tmp');
        $this->assertTrue(true);
    }
}