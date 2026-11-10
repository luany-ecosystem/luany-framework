<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Application;
use Luany\Framework\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for issue #9 — config() helper with optional key.
 */
class ConfigHelperTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $app = new Application(sys_get_temp_dir() . '/luany-config-helper-' . uniqid());
        $this->config = new Config($app->configPath());
        $app->instance('config', $this->config);
    }

    public function test_config_without_key_returns_repository(): void
    {
        $this->assertSame($this->config, config());
    }

    public function test_config_set_through_repository(): void
    {
        config()->set('app.debug', true);
        $this->assertTrue(config('app.debug'));
    }

    public function test_config_get_with_default(): void
    {
        $this->assertSame('fallback', config('app.missing', 'fallback'));
    }

    public function test_config_array_sets_values(): void
    {
        config(['app.name' => 'Luany', 'app.env' => 'testing']);
        $this->assertSame('Luany', config('app.name'));
        $this->assertSame('testing', config('app.env'));
    }
}
