<?php

namespace Luany\Framework\Contracts;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;

/**
 * KernelInterface
 *
 * Contract for the HTTP Kernel.
 * Defines the three phases of the request lifecycle:
 *
 *   boot()      → register services, load routes, connect LTE
 *   handle()    → run middleware pipeline + dispatch → Response
 *   terminate() → post-send cleanup (logging, sessions, etc.)
 */
interface KernelInterface
{
    /**
     * Bootstrap the kernel.
     * Called once at application startup before handle().
     */
    public function boot(): void;

    /**
     * Handle an incoming HTTP request.
     * Returns a Response — does not send.
     */
    public function handle(Request $request): Response;

    /**
     * Perform post-send tasks.
     * Called after Response::send() — safe for cleanup work.
     */
    public function terminate(Request $request, Response $response): void;
}