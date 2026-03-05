<?php

namespace Luany\Framework\Exceptions;

use Luany\Core\Http\Response;

/**
 * Handler
 *
 * Base exception handler for Luany applications.
 *
 * Override in app/Exceptions/Handler.php:
 *   - report() → send to Sentry, Slack, log file, etc.
 *   - render() → return custom Response per exception type
 *
 * The Kernel resolves this from the container on every uncaught exception.
 */
abstract class Handler
{
    public function __construct(protected bool $debug = false) {}

    /**
     * Report the exception — log, notify external services, etc.
     * Called before render(). Failures here are silently swallowed.
     */
    public function report(\Throwable $e): void
    {
        error_log(sprintf(
            '[%s] %s in %s on line %d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    /**
     * Render the exception into an HTTP Response.
     * Override to return custom views per exception type.
     */
    public function render(\Throwable $e): Response
    {
        if ($this->debug) {
            return Response::make($this->debugPage($e), 500);
        }

        return Response::serverError();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function debugPage(\Throwable $e): string
    {
        $class   = get_class($e);
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file    = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line    = $e->getLine();
        $trace   = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>500 — {$class}</title>
            <style>
                *{margin:0;padding:0;box-sizing:border-box}
                body{font-family:'JetBrains Mono',monospace;background:#010213;color:rgba(255,255,255,.9);padding:2rem;min-height:100vh}
                .card{background:#0D0D26;border:1px solid rgba(242,68,29,.35);border-radius:12px;padding:2rem;max-width:960px;margin:0 auto}
                .card::before{content:'';display:block;height:2px;background:linear-gradient(90deg,#F2441D,#E6874A);border-radius:12px 12px 0 0;margin:-2rem -2rem 2rem}
                h1{font-size:1rem;font-weight:700;color:#F2441D;text-transform:uppercase;letter-spacing:.1em;margin-bottom:1rem}
                .message{font-size:1.1rem;color:rgba(255,255,255,.95);margin-bottom:1.5rem;line-height:1.6}
                .meta{font-size:.8rem;color:rgba(255,255,255,.45);margin-bottom:1.5rem}
                .meta span{color:rgba(230,135,74,.8)}
                pre{background:#080B1F;border:1px solid rgba(91,49,113,.25);border-radius:8px;padding:1.5rem;font-size:.75rem;overflow-x:auto;line-height:1.6;color:rgba(255,255,255,.6)}
            </style>
        </head>
        <body>
            <div class="card">
                <h1>{$class}</h1>
                <p class="message">{$message}</p>
                <p class="meta"><span>{$file}</span> on line <span>{$line}</span></p>
                <pre>{$trace}</pre>
            </div>
        </body>
        </html>
        HTML;
    }
}