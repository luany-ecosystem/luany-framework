<?php

namespace Luany\Framework\Support;

/**
 * Translator
 *
 * Lightweight translation engine. Zero external dependencies.
 *
 * Translation files live in lang/{locale}.php and return a flat
 * associative array of key => string pairs.
 *
 * Keys use dot-group notation:   'nav.home', 'hero.title'
 * Replacements use :placeholder: __('footer.copyright', ['year' => date('Y')])
 *
 * Detection order (highest to lowest priority):
 *   1. Cookie 'app_locale'          — explicit user preference
 *   2. Accept-Language HTTP header  — browser preference
 *   3. APP_LOCALE env variable      — application default
 *   4. 'en'                         — hardcoded fallback
 */
class Translator
{
    /** Loaded translation tables keyed by locale. */
    private array $tables = [];

    private string $locale;
    private readonly string $fallback;
    private readonly string $langPath;

    /** Supported locales — locales outside this list are rejected. */
    private array $supported;

    public function __construct(
        string $langPath,
        string $locale    = 'en',
        string $fallback  = 'en',
        array  $supported = ['en'],
    ) {
        $this->langPath  = rtrim($langPath, '/\\');
        $this->fallback  = $fallback;
        $this->supported = $supported;

        $this->setLocale($locale);
    }

    // ── Public API ────────────────────────────────────────────

    /**
     * Translate a key, with optional placeholder replacement.
     *
     * Returns the key itself when neither the current locale
     * nor the fallback locale have a translation — so pages
     * never silently show empty strings.
     */
    public function get(string $key, array $replace = []): string
    {
        $value = $this->tables[$this->locale][$key]
              ?? $this->tables[$this->fallback][$key]
              ?? $key;

        foreach ($replace as $placeholder => $replacement) {
            $value = str_replace(':' . $placeholder, (string) $replacement, $value);
        }

        return $value;
    }

    /**
     * Change the active locale at runtime.
     * Silently ignores unsupported locales.
     */
    public function setLocale(string $locale): void
    {
        if (!in_array($locale, $this->supported, true)) {
            return;
        }

        $this->locale = $locale;
        $this->load($locale);

        // Always keep fallback loaded so get() never needs a guard.
        if ($locale !== $this->fallback) {
            $this->load($this->fallback);
        }
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getFallback(): string
    {
        return $this->fallback;
    }

    public function getSupported(): array
    {
        return $this->supported;
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->supported, true);
    }

    // ── Private ───────────────────────────────────────────────

    /** Load a locale file into memory — idempotent. */
    private function load(string $locale): void
    {
        if (isset($this->tables[$locale])) {
            return;
        }

        $path = $this->langPath . '/' . $locale . '.php';

        if (!file_exists($path)) {
            $this->tables[$locale] = [];
            return;
        }

        $data = require $path;

        $this->tables[$locale] = is_array($data) ? $data : [];
    }
}