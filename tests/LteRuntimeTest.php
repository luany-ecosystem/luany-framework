<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Application;
use Luany\Framework\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * The Kernel forwards the debug flag to luany/lte's Runtime (lte >= 1.1).
 */
class LteRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        if (class_exists(\Luany\Lte\Runtime::class)) {
            \Luany\Lte\Runtime::setDebug(false);
        }
    }

    public function test_boot_works_with_and_without_lte_runtime(): void
    {
        $app = new Application(sys_get_temp_dir() . '/luany-lte-' . uniqid());
        (new TestKernel($app))->boot();

        $this->assertTrue($app->bound('view'));
    }

    public function test_debug_flag_is_forwarded_to_lte_runtime(): void
    {
        if (!class_exists(\Luany\Lte\Runtime::class)) {
            $this->markTestSkipped('Installed luany/lte has no Runtime (< 1.1).');
        }

        $app = new Application(sys_get_temp_dir() . '/luany-lte-' . uniqid());
        $config = new Config('/nonexistent');
        $config->set('app.debug', true);
        $app->instance('config', $config);

        (new TestKernel($app))->boot();

        $prop = new \ReflectionProperty(\Luany\Lte\Runtime::class, 'debug');
        $this->assertTrue($prop->getValue());
    }
}
