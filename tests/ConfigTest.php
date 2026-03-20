<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 */
class ConfigTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'luany_config_test_' . uniqid();
        mkdir($this->configDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up config files
        $files = glob($this->configDir . DIRECTORY_SEPARATOR . '*.php');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->configDir)) {
            rmdir($this->configDir);
        }
    }

    private function writeConfig(string $filename, array $data): void
    {
        $content = '<?php return ' . var_export($data, true) . ';';
        file_put_contents($this->configDir . DIRECTORY_SEPARATOR . $filename, $content);
    }

    // ── get() ────────────────────────────────────────────────────────────────

    public function testGetTopLevelKey(): void
    {
        $this->writeConfig('app.php', ['name' => 'Luany', 'debug' => true]);
        $config = new Config($this->configDir);

        $this->assertSame(['name' => 'Luany', 'debug' => true], $config->get('app'));
    }

    public function testGetNestedKey(): void
    {
        $this->writeConfig('app.php', ['name' => 'Luany', 'debug' => true]);
        $config = new Config($this->configDir);

        $this->assertSame('Luany', $config->get('app.name'));
        $this->assertTrue($config->get('app.debug'));
    }

    public function testGetDeeplyNestedKey(): void
    {
        $this->writeConfig('database.php', [
            'connections' => [
                'mysql' => [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                ],
            ],
        ]);
        $config = new Config($this->configDir);

        $this->assertSame('127.0.0.1', $config->get('database.connections.mysql.host'));
        $this->assertSame(3306, $config->get('database.connections.mysql.port'));
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $this->writeConfig('app.php', ['name' => 'Luany']);
        $config = new Config($this->configDir);

        $this->assertNull($config->get('app.missing'));
        $this->assertSame('fallback', $config->get('app.missing', 'fallback'));
    }

    public function testGetReturnsDefaultWhenFileDoesNotExist(): void
    {
        $config = new Config($this->configDir);
        $this->assertSame('default', $config->get('nonexistent.key', 'default'));
    }

    public function testGetReturnsDefaultWhenTraversingNonArray(): void
    {
        $this->writeConfig('app.php', ['name' => 'Luany']);
        $config = new Config($this->configDir);

        $this->assertNull($config->get('app.name.nested'));
    }

    // ── set() ────────────────────────────────────────────────────────────────

    public function testSetOverridesExistingValue(): void
    {
        $this->writeConfig('app.php', ['name' => 'Luany']);
        $config = new Config($this->configDir);

        $config->set('app.name', 'MyApp');
        $this->assertSame('MyApp', $config->get('app.name'));
    }

    public function testSetCreatesNestedKeys(): void
    {
        $config = new Config($this->configDir);
        $config->set('cache.driver', 'file');

        $this->assertSame('file', $config->get('cache.driver'));
    }

    public function testSetDeeplyNestedValue(): void
    {
        $config = new Config($this->configDir);
        $config->set('a.b.c.d', 'deep');

        $this->assertSame('deep', $config->get('a.b.c.d'));
    }

    // ── has() ────────────────────────────────────────────────────────────────

    public function testHasReturnsTrueForExistingKey(): void
    {
        $this->writeConfig('app.php', ['name' => 'Luany', 'debug' => false]);
        $config = new Config($this->configDir);

        $this->assertTrue($config->has('app'));
        $this->assertTrue($config->has('app.name'));
        $this->assertTrue($config->has('app.debug'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $this->writeConfig('app.php', ['name' => 'Luany']);
        $config = new Config($this->configDir);

        $this->assertFalse($config->has('app.missing'));
        $this->assertFalse($config->has('nonexistent'));
    }

    public function testHasReturnsTrueForNullValue(): void
    {
        $this->writeConfig('app.php', ['name' => null]);
        $config = new Config($this->configDir);

        $this->assertTrue($config->has('app.name'));
    }

    // ── all() ────────────────────────────────────────────────────────────────

    public function testAllReturnsFullConfigArray(): void
    {
        $this->writeConfig('app.php', ['name' => 'Luany']);
        $this->writeConfig('database.php', ['default' => 'mysql']);
        $config = new Config($this->configDir);

        $all = $config->all();
        $this->assertArrayHasKey('app', $all);
        $this->assertArrayHasKey('database', $all);
        $this->assertSame('Luany', $all['app']['name']);
        $this->assertSame('mysql', $all['database']['default']);
    }

    // ── Edge cases ───────────────────────────────────────────────────────────

    public function testLoadFromNonExistentDirectory(): void
    {
        $config = new Config('/nonexistent/path');
        $this->assertSame([], $config->all());
        $this->assertNull($config->get('any.key'));
    }

    public function testIgnoresNonArrayReturns(): void
    {
        // Write a PHP file that returns a string, not an array
        file_put_contents(
            $this->configDir . DIRECTORY_SEPARATOR . 'bad.php',
            '<?php return "not an array";'
        );
        $config = new Config($this->configDir);

        $this->assertFalse($config->has('bad'));
    }

    public function testMultipleConfigFiles(): void
    {
        $this->writeConfig('app.php', ['name' => 'Luany', 'version' => '1.0']);
        $this->writeConfig('mail.php', ['driver' => 'smtp', 'host' => 'localhost']);
        $this->writeConfig('cache.php', ['driver' => 'file', 'ttl' => 3600]);
        $config = new Config($this->configDir);

        $this->assertSame('Luany', $config->get('app.name'));
        $this->assertSame('smtp', $config->get('mail.driver'));
        $this->assertSame(3600, $config->get('cache.ttl'));
    }
}
