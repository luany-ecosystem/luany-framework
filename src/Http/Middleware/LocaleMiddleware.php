<?php

namespace Luany\Framework\Http\Middleware;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Framework\Support\Translator;

/**
 * LocaleMiddleware
 *
 * Detects the active locale on every request and sets it on the
 * Translator instance bound in the container.
 *
 * Detection order (highest to lowest priority):
 *   1. Cookie 'app_locale'          — explicit user preference
 *   2. Accept-Language HTTP header  — browser preference
 *   3. APP_LOCALE env variable      — application default
 *   4. 'en'                         — hardcoded fallback
 */
class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ?Translator $translator = null
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $translator = $this->translator ?? app('translator');

        $locale = $this->detect($request, $translator->getSupported());
        $translator->setLocale($locale);

        return $next($request);
    }

    // ── Private ───────────────────────────────────────────────

    /** @param array<int, string> $supported */
    private function detect(Request $request, array $supported): string
    {
        // 1. Cookie — explicit user preference
        $cookie = $request->cookie('app_locale');
        if ($cookie && in_array($cookie, $supported, true)) {
            return $cookie;
        }

        // 2. Accept-Language header — browser preference
        $header = $request->header('Accept-Language', '');
        if ($header) {
            $detected = $this->parseAcceptLanguage($header, $supported);
            if ($detected) {
                return $detected;
            }
        }

        // 3. APP_LOCALE env
        $env = env('APP_LOCALE', 'en');
        if (in_array($env, $supported, true)) {
            return $env;
        }

        // 4. Hardcoded fallback
        return 'en';
    }

    /**
     * Parse Accept-Language header and return the best supported locale.
     * Handles: 'pt-PT,pt;q=0.9,en;q=0.8' → 'pt'
     * Languages with q=0 are "not acceptable" (RFC 9110 §12.4.2) and ignored.
     *
     * @param array<int, string> $supported
     */
    private function parseAcceptLanguage(string $header, array $supported): ?string
    {
        $parsed = [];
        $order  = 0;

        foreach (explode(',', $header) as $part) {
            $segments = array_map('trim', explode(';', $part));
            $tag      = array_shift($segments);
            $quality  = 1.0;

            foreach ($segments as $param) {
                if (preg_match('/^q\s*=\s*([0-9.]+)$/i', $param, $m)) {
                    $quality = (float) $m[1];
                } elseif (stripos($param, 'q') === 0) {
                    $quality = 0.0; // malformed q-value — treat as not acceptable
                }
            }

            if ($tag === '' || $tag === null || $quality <= 0.0) {
                continue;
            }

            $lang = strtolower(explode('-', $tag)[0]);

            if (!isset($parsed[$lang]) || $quality > $parsed[$lang]['q']) {
                $parsed[$lang] = ['q' => min($quality, 1.0), 'order' => $parsed[$lang]['order'] ?? $order++];
            }
        }

        // Highest quality first; ties keep header order.
        uasort($parsed, fn ($a, $b) => [$b['q'], $a['order']] <=> [$a['q'], $b['order']]);

        foreach (array_keys($parsed) as $lang) {
            if (in_array((string) $lang, $supported, true)) {
                return (string) $lang;
            }
        }

        return null;
    }
}