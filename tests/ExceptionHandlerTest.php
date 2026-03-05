<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Exceptions\Handler;
use Luany\Core\Http\Response;
use PHPUnit\Framework\TestCase;

class ExceptionHandlerTest extends TestCase
{
    // ── Concrete stub for testing the abstract Handler ────────────────────────

    private function makeHandler(bool $debug = false): Handler
    {
        return new class($debug) extends Handler {};
    }

    // ── report() ─────────────────────────────────────────────────────────────

    public function test_report_does_not_throw(): void
    {
        $handler = $this->makeHandler();

        // Redirect error_log output to a temp file — suppress stderr noise
        $tmp = tempnam(sys_get_temp_dir(), 'luany_test_');
        ini_set('error_log', $tmp);

        $handler->report(new \RuntimeException('test'));

        ini_restore('error_log');
        @unlink($tmp);

        $this->assertTrue(true);
    }
    // ── render() — production ─────────────────────────────────────────────────

    public function test_render_returns_500_in_production(): void
    {
        $handler  = $this->makeHandler(debug: false);
        $response = $handler->render(new \RuntimeException('oops'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(500, $response->getStatusCode());
    }

    public function test_render_production_body_is_not_empty(): void
    {
        $handler  = $this->makeHandler(debug: false);
        $response = $handler->render(new \RuntimeException('oops'));

        $this->assertNotEmpty($response->getBody());
    }

    // ── render() — debug ─────────────────────────────────────────────────────

    public function test_render_returns_500_in_debug(): void
    {
        $handler  = $this->makeHandler(debug: true);
        $response = $handler->render(new \RuntimeException('debug error'));

        $this->assertSame(500, $response->getStatusCode());
    }

    public function test_render_debug_page_contains_exception_class(): void
    {
        $handler  = $this->makeHandler(debug: true);
        $response = $handler->render(new \InvalidArgumentException('bad arg'));

        $this->assertStringContainsString('InvalidArgumentException', $response->getBody());
    }

    public function test_render_debug_page_contains_message(): void
    {
        $handler  = $this->makeHandler(debug: true);
        $response = $handler->render(new \RuntimeException('something went wrong'));

        $this->assertStringContainsString('something went wrong', $response->getBody());
    }

    public function test_render_debug_page_contains_file_and_line(): void
    {
        $e        = new \RuntimeException('trace test');
        $handler  = $this->makeHandler(debug: true);
        $response = $handler->render($e);

        $this->assertStringContainsString($e->getFile(), $response->getBody());
        $this->assertStringContainsString((string) $e->getLine(), $response->getBody());
    }
}