<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Routing\Route;
use Luany\Framework\Application;
use Luany\Framework\Exceptions\Handler;
use Luany\Framework\Log\FileLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for issue #8 — PSR-3 logging.
 */
class LoggingTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        Route::reset();
        $this->base = sys_get_temp_dir() . '/luany-log-' . uniqid();
    }

    protected function tearDown(): void
    {
        Route::reset();
    }

    private function logContents(): string
    {
        $file = $this->base . '/storage/logs/luany-' . date('Y-m-d') . '.log';
        $this->assertFileExists($file);
        return (string) file_get_contents($file);
    }

    public function test_file_logger_writes_daily_file_with_interpolation(): void
    {
        $logger = new FileLogger($this->base . '/storage/logs');
        $logger->info('User {id} logged in from {ip}', ['id' => 7, 'ip' => '10.0.0.1']);

        $this->assertStringEndsWith('luany-' . date('Y-m-d') . '.log', $logger->path());
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\] luany\.INFO: User 7 logged in from 10\.0\.0\.1 \{"id":7,"ip":"10\.0\.0\.1"\}$/m',
            $this->logContents()
        );
    }

    public function test_min_level_filters_records(): void
    {
        $logger = new FileLogger($this->base . '/storage/logs', minLevel: 'warning');
        $logger->debug('hidden');
        $logger->error('shown');

        $contents = $this->logContents();
        $this->assertStringNotContainsString('hidden', $contents);
        $this->assertStringContainsString('luany.ERROR: shown', $contents);
    }

    public function test_non_daily_file(): void
    {
        $logger = new FileLogger($this->base, 'app', daily: false);
        $this->assertSame($this->base . DIRECTORY_SEPARATOR . 'app.log', $logger->path());
    }

    public function test_exception_context_is_rendered(): void
    {
        $logger = new FileLogger($this->base . '/storage/logs');
        $logger->error('failed', ['exception' => new \LogicException('kaboom')]);

        $contents = $this->logContents();
        $this->assertStringContainsString('[exception] LogicException: kaboom', $contents);
    }

    public function test_newlines_in_message_cannot_forge_records(): void
    {
        $logger = new FileLogger($this->base . '/storage/logs');
        $logger->warning("bad\n[2026-01-01 00:00:00] luany.EMERGENCY: forged");

        $this->assertSame(1, substr_count(trim($this->logContents()), "\n") + 1);
    }

    public function test_invalid_level_throws_psr_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FileLogger($this->base))->log('loud', 'x');
    }

    public function test_kernel_registers_logger_and_helper(): void
    {
        $app = new Application($this->base);
        (new TestKernel($app))->boot();

        $this->assertInstanceOf(FileLogger::class, $app->make('log'));
        $this->assertSame($app->make('log'), $app->make(LoggerInterface::class));
        $this->assertSame($app->make('log'), logger());

        logger('helper {x}', ['x' => 'works'], 'notice');
        $this->assertStringContainsString('luany.NOTICE: helper works', $this->logContents());
    }

    public function test_provider_logger_is_kept(): void
    {
        $app = new Application($this->base);
        $custom = new \Psr\Log\NullLogger();
        $app->instance(LoggerInterface::class, $custom);
        (new TestKernel($app))->boot();

        $this->assertSame($custom, $app->make('log'));
    }

    public function test_kernel_logs_unhandled_exception_without_handler(): void
    {
        $app = new Application($this->base);
        $kernel = new TestKernel($app);
        $kernel->boot();
        Route::get('/boom', fn () => throw new \DomainException('kernel boom'));

        $response = $kernel->handle(new Request('GET', '/boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('Unhandled DomainException: kernel boom', $this->logContents());
    }

    public function test_base_handler_reports_through_logger(): void
    {
        $app = new Application($this->base);
        $app->instance(Handler::class, new class extends Handler {});
        $kernel = new TestKernel($app);
        $kernel->boot();
        Route::get('/boom', fn () => throw new \DomainException('handler boom'));

        $kernel->handle(new Request('GET', '/boom'));

        $this->assertStringContainsString('luany.ERROR: DomainException: handler boom', $this->logContents());
    }

    public function test_not_found_is_not_logged(): void
    {
        $app = new Application($this->base);
        $kernel = new TestKernel($app);
        $kernel->boot();

        $kernel->handle(new Request('GET', '/missing'));

        $this->assertFileDoesNotExist($this->base . '/storage/logs/luany-' . date('Y-m-d') . '.log');
    }
}
