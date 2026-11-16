<?php

namespace Luany\Framework\Exceptions;

use Luany\Core\Exceptions\MethodNotAllowedException;
use Luany\Core\Exceptions\RouteNotFoundException;
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
 *
 * HTTP exceptions keep their status code: HttpException (abort()) uses its
 * own status, RouteNotFoundException → 404, MethodNotAllowedException → 405
 * (with an Allow header), ValidationException → 422, luany/database's
 * ModelNotFoundException (findOrFail() / firstOrFail()) → 404. Everything
 * else is a 500. Subclasses that override render() can call
 * parent::render($e) as a fallback or use statusCodeFor()/headersFor() directly.
 *
 * Requests that expect JSON are rendered with renderJson() instead of render().
 */
abstract class Handler
{
    /**
     * luany/database's "no row matched" exception. Referenced by name so the
     * framework does not depend on luany/database.
     */
    public const MODEL_NOT_FOUND = 'Luany\\Database\\ModelNotFoundException';

    /**
     * Exception types that are never reported (expected HTTP outcomes).
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected array $dontReport = [
        HttpException::class,
        ValidationException::class,
        RouteNotFoundException::class,
        MethodNotAllowedException::class,
    ];

    public function __construct(protected bool $debug = false) {}

    /**
     * Report the exception — log, notify external services, etc.
     * Called before render(). Failures here are silently swallowed.
     *
     * Uses the container's PSR-3 logger ('log') when available,
     * otherwise PHP's error_log().
     */
    public function report(\Throwable $e): void
    {
        if (!$this->shouldReport($e)) {
            return;
        }

        $logger = $this->logger();

        if ($logger !== null) {
            $logger->error(sprintf('%s: %s', get_class($e), $e->getMessage()), ['exception' => $e]);
            return;
        }

        error_log(sprintf(
            '[%s] %s in %s on line %d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    /**
     * Determine if the exception should be reported.
     */
    public function shouldReport(\Throwable $e): bool
    {
        if (is_a($e, self::MODEL_NOT_FOUND)) {
            return false;
        }

        foreach ($this->dontReport as $type) {
            if ($e instanceof $type) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render the exception into an HTTP Response.
     * Override to return custom views per exception type.
     */
    public function render(\Throwable $e): Response
    {
        $status  = $this->statusCodeFor($e);
        $headers = $this->headersFor($e);

        if ($status >= 500) {
            $response = $this->debug
                ? Response::make($this->debugPage($e), $status)
                : Response::serverError();

            return $response->status($status)->withHeaders($headers);
        }

        return Response::make($this->httpErrorPage($e, $status), $status)
            ->withHeaders(['Content-Type' => 'text/html; charset=UTF-8'] + $headers);
    }

    /**
     * Render the exception as JSON, for requests that expect JSON.
     *
     * Body: {"message": ...}. 4xx messages are the abort() message or the
     * reason phrase; 5xx messages are "Server Error" unless debug mode is on,
     * which adds the real message, exception class, file, line and trace.
     */
    public function renderJson(\Throwable $e): Response
    {
        $status = $this->statusCodeFor($e);

        if ($status >= 500) {
            $payload = ['message' => $this->debug ? $e->getMessage() : 'Server Error'];
        } else {
            $payload = ['message' => $e instanceof HttpException
                ? $e->getMessage()
                : (new HttpException($status))->getMessage()];
        }

        if ($this->debug) {
            $payload += [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => explode("\n", $e->getTraceAsString()),
            ];
        }

        return Response::json($payload, $status, $this->headersFor($e));
    }

    /**
     * The HTTP status code an exception maps to.
     */
    public function statusCodeFor(\Throwable $e): int
    {
        return match (true) {
            $e instanceof HttpException             => $e->getStatusCode(),
            $e instanceof ValidationException       => 422,
            $e instanceof RouteNotFoundException    => 404,
            $e instanceof MethodNotAllowedException => 405,
            is_a($e, self::MODEL_NOT_FOUND)         => 404,
            default                                 => 500,
        };
    }

    /**
     * Extra response headers an exception requires (e.g. Allow on 405).
     *
     * @return array<string, string>
     */
    public function headersFor(\Throwable $e): array
    {
        if ($e instanceof MethodNotAllowedException) {
            return ['Allow' => $e->getAllowHeaderValue()];
        }

        if ($e instanceof HttpException) {
            return $e->getHeaders();
        }

        return [];
    }

    /**
     * Minimal HTML page for 4xx errors. The message is HTML-escaped.
     * Only HttpException messages (set by the developer via abort()) are shown;
     * router messages contain the request URI and are replaced by the reason.
     */
    protected function httpErrorPage(\Throwable $e, int $status): string
    {
        $message = $e instanceof HttpException
            ? $e->getMessage()
            : (new HttpException($status))->getMessage();

        return sprintf(
            '<h1>%d — %s</h1>',
            $status,
            htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    /**
     * The PSR-3 logger bound in the container, if any.
     */
    protected function logger(): ?\Psr\Log\LoggerInterface
    {
        try {
            $app = \Luany\Framework\Application::getInstance();
            if ($app->bound('log')) {
                $logger = $app->make('log');
                return $logger instanceof \Psr\Log\LoggerInterface ? $logger : null;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function debugPage(\Throwable $e): string
    {
        $class   = get_class($e);
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file    = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line    = $e->getLine();
        $trace   = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
        $time    = date('H:i:s');
        $uri     = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8');
        $method  = htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'GET', ENT_QUOTES, 'UTF-8');

        // Short class name for the large display
        $parts     = explode('\\', $class);
        $shortName = end($parts);
        $namespace = implode('\\', array_slice($parts, 0, -1));

        // Placeholder SVG — replace <svg>…</svg> with the real Luany icon
        // Ícone oficial do Luany
        // Loaded only when a debug page is rendered (the traced logo is ~320 KB).
        $iconSvg = trim((string) @file_get_contents(__DIR__ . '/resources/luany-icon.svg'));

        $iconDataUri = 'data:image/svg+xml;base64,' . base64_encode($iconSvg);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$shortName} — Luany Debug</title>
            <link rel="icon" type="image/svg+xml" href="{$iconDataUri}">
            <style>
                @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&display=swap');

                *, *::before, *::after {
                    margin: 0; padding: 0; box-sizing: border-box;
                }

                :root {
                    --purple:  #5B3171;
                    --orange:  #E6874A;
                    --red:     #F2441D;
                    --bg:      #010213;
                    --surface: #0A0B1E;
                    --border:  rgba(91, 49, 113, 0.3);
                    --text:    rgba(255, 255, 255, 0.9);
                    --muted:   rgba(255, 255, 255, 0.4);
                    --mono:    'JetBrains Mono', 'Fira Code', monospace;
                }

                html, body {
                    min-height: 100vh;
                    background: var(--bg);
                    color: var(--text);
                    font-family: var(--mono);
                    overflow-x: hidden;
                }

                /* ── Animated background ── */
                .debug-bg {
                    position: fixed; inset: 0; z-index: 0;
                    pointer-events: none; overflow: hidden;
                }

                .debug-bg::before {
                    content: '';
                    position: absolute;
                    width: 70vw; height: 70vw;
                    top: -30vw; left: -20vw;
                    background: radial-gradient(ellipse, rgba(91,49,113,0.22) 0%, transparent 70%);
                    animation: drift-a 18s ease-in-out infinite alternate;
                }

                .debug-bg::after {
                    content: '';
                    position: absolute;
                    width: 60vw; height: 60vw;
                    bottom: -25vw; right: -15vw;
                    background: radial-gradient(ellipse, rgba(242,68,29,0.16) 0%, transparent 70%);
                    animation: drift-b 22s ease-in-out infinite alternate;
                }

                .debug-bg-mid {
                    position: absolute;
                    width: 50vw; height: 50vw;
                    top: 30%; left: 40%;
                    background: radial-gradient(ellipse, rgba(230,135,74,0.10) 0%, transparent 70%);
                    animation: drift-c 26s ease-in-out infinite alternate;
                }

                @keyframes drift-a { from { transform: translate(0,0) scale(1); } to { transform: translate(4vw,6vh) scale(1.08); } }
                @keyframes drift-b { from { transform: translate(0,0) scale(1); } to { transform: translate(-5vw,-4vh) scale(1.12); } }
                @keyframes drift-c { from { transform: translate(0,0) scale(1); } to { transform: translate(3vw,5vh) scale(0.92); } }

                /* Grid overlay */
                .debug-grid {
                    position: fixed; inset: 0; z-index: 0; pointer-events: none;
                    background-image:
                        linear-gradient(rgba(91,49,113,0.04) 1px, transparent 1px),
                        linear-gradient(90deg, rgba(91,49,113,0.04) 1px, transparent 1px);
                    background-size: 48px 48px;
                }

                /* ── Layout ── */
                .debug-wrap {
                    position: relative; z-index: 1;
                    max-width: 1100px;
                    margin: 0 auto;
                    padding: 3rem 2.5rem 5rem;
                }

                /* ── Header ── */
                .debug-header {
                    display: flex;
                    align-items: center;
                    gap: 1rem;
                    margin-bottom: 3.5rem;
                    padding-bottom: 1.5rem;
                    border-bottom: 1px solid var(--border);
                }

                .debug-logo {
                    display: flex;
                    align-items: center;
                    gap: 0.6rem;
                    font-size: 0.75rem;
                    font-weight: 700;
                    letter-spacing: 0.12em;
                    text-transform: uppercase;
                    color: var(--muted);
                }

                .debug-logo svg { flex-shrink: 0; }

                .debug-header-sep { flex: 1; }

                .debug-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.4rem;
                    background: rgba(242,68,29,0.12);
                    border: 1px solid rgba(242,68,29,0.3);
                    border-radius: 4px;
                    padding: 0.3rem 0.75rem;
                    font-size: 0.65rem;
                    font-weight: 700;
                    letter-spacing: 0.1em;
                    text-transform: uppercase;
                    color: #F2441D;
                }

                .debug-badge::before {
                    content: '';
                    width: 5px; height: 5px;
                    border-radius: 50%;
                    background: #F2441D;
                    animation: pulse-dot 1.5s ease-in-out infinite;
                }

                @keyframes pulse-dot {
                    0%, 100% { opacity: 1; transform: scale(1); }
                    50%       { opacity: 0.4; transform: scale(0.7); }
                }

                /* ── Exception type ── */
                .debug-type {
                    margin-bottom: 0.6rem;
                    font-size: 0.72rem;
                    font-weight: 500;
                    letter-spacing: 0.08em;
                    color: var(--orange);
                    opacity: 0.8;
                }

                /* ── Large exception name ── */
                .debug-exception {
                    font-size: clamp(2.2rem, 6vw, 4.5rem);
                    font-weight: 700;
                    line-height: 1.05;
                    letter-spacing: -0.02em;
                    color: #ffffff;
                    margin-bottom: 1.5rem;
                    word-break: break-word;
                }

                .debug-exception span {
                    color: var(--red);
                }

                /* ── Message ── */
                .debug-message {
                    font-size: 1rem;
                    line-height: 1.7;
                    color: rgba(255,255,255,0.75);
                    background: rgba(242,68,29,0.07);
                    border-left: 3px solid var(--red);
                    padding: 1rem 1.25rem;
                    border-radius: 0 6px 6px 0;
                    margin-bottom: 2rem;
                    word-break: break-word;
                }

                /* ── Meta row ── */
                .debug-meta {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 1rem 2rem;
                    margin-bottom: 2.5rem;
                    font-size: 0.72rem;
                    color: var(--muted);
                }

                .debug-meta-item { display: flex; align-items: center; gap: 0.4rem; }
                .debug-meta-label { text-transform: uppercase; letter-spacing: 0.08em; }
                .debug-meta-value { color: var(--orange); font-weight: 500; }

                /* ── Section header ── */
                .debug-section-title {
                    font-size: 0.65rem;
                    font-weight: 700;
                    letter-spacing: 0.12em;
                    text-transform: uppercase;
                    color: var(--muted);
                    margin-bottom: 0.75rem;
                    display: flex;
                    align-items: center;
                    gap: 0.6rem;
                }

                .debug-section-title::after {
                    content: '';
                    flex: 1;
                    height: 1px;
                    background: var(--border);
                }

                /* ── Stack trace ── */
                .debug-trace {
                    background: var(--surface);
                    border: 1px solid var(--border);
                    border-radius: 8px;
                    padding: 1.5rem;
                    font-size: 0.72rem;
                    line-height: 1.8;
                    color: rgba(255,255,255,0.5);
                    overflow-x: auto;
                    white-space: pre;
                    tab-size: 2;
                }

                .debug-trace::-webkit-scrollbar { height: 4px; }
                .debug-trace::-webkit-scrollbar-track { background: transparent; }
                .debug-trace::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

                /* ── Responsive ── */
                @media (max-width: 640px) {
                    .debug-wrap { padding: 2rem 1.25rem 4rem; }
                    .debug-exception { font-size: 1.8rem; }
                }
            </style>
        </head>
        <body>
            <div class="debug-bg">
                <div class="debug-bg-mid"></div>
            </div>
            <div class="debug-grid"></div>

            <div class="debug-wrap">

                <header class="debug-header">
                    <div class="debug-logo">
                        {$iconSvg}
                        Luany Framework
                    </div>
                    <div class="debug-header-sep"></div>
                    <div class="debug-badge">Debug Mode</div>
                </header>

                <p class="debug-type">{$namespace}\\</p>
                <h1 class="debug-exception"><span>{$shortName}</span></h1>
                <p class="debug-message">{$message}</p>

                <div class="debug-meta">
                    <div class="debug-meta-item">
                        <span class="debug-meta-label">File</span>
                        <span class="debug-meta-value">{$file}</span>
                    </div>
                    <div class="debug-meta-item">
                        <span class="debug-meta-label">Line</span>
                        <span class="debug-meta-value">{$line}</span>
                    </div>
                    <div class="debug-meta-item">
                        <span class="debug-meta-label">Request</span>
                        <span class="debug-meta-value">{$method} {$uri}</span>
                    </div>
                    <div class="debug-meta-item">
                        <span class="debug-meta-label">Time</span>
                        <span class="debug-meta-value">{$time}</span>
                    </div>
                </div>

                <p class="debug-section-title">Stack Trace</p>
                <pre class="debug-trace">{$trace}</pre>

            </div>
        </body>
        </html>
        HTML;
    }
}