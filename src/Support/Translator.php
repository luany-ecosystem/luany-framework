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
 *   3. config('app.locale')         — application default (APP_LOCALE when unset)
 *   4. 'en'                         — hardcoded fallback
 *
 * Regional locales fall back to their language, then to the fallback
 * locale: pt_AO → pt → en. Only what differs goes in lang/pt_AO.php.
 * Packages may ship default tables (the framework ships validation
 * messages in pt and pt_BR); the application's files override them.
 */
class Translator
{
    /** @var array<string, array<string, string>> Loaded translation tables keyed by locale. */
    private array $tables = [];

    private string $locale;
    private readonly string $fallback;
    private readonly string $langPath;

    /** Supported locales — locales outside this list are rejected. */
    /** @var array<int, string> */
    private array $supported;

    /** @var array<int, string> Directories with default tables, loaded before lang/. */
    private array $defaultPaths;

    /**
     * When $locale is not in $supported, the initial locale is $fallback if it
     * is supported, otherwise the first supported locale. An empty $supported
     * list leaves $locale active (and setLocale() then rejects every change).
     *
     * @param array<int, string> $supported
     * @param array<int, string> $defaultPaths Directories with default tables (overridden by lang/)
     */
    public function __construct(
        string $langPath,
        string $locale    = 'en',
        string $fallback  = 'en',
        array  $supported = ['en'],
        array  $defaultPaths = [],
    ) {
        $this->defaultPaths = $defaultPaths;
        $this->langPath  = rtrim($langPath, '/\\');
        $this->fallback  = $fallback;
        $this->supported = $supported;

        $this->activate(match (true) {
            $supported === [] || in_array($locale, $supported, true) => $locale,
            in_array($fallback, $supported, true)                     => $fallback,
            default                                                   => (string) reset($supported),
        });
    }

    // ── Public API ────────────────────────────────────────────

    /**
     * Translate a key, with optional placeholder replacement.
     *
     * Returns the key itself when neither the current locale
     * nor the fallback locale have a translation — so pages
     * never silently show empty strings.
     */
    /** @param array<string, string> $replace */
    public function get(string $key, array $replace = []): string
    {
        $value = $key;
        foreach ($this->chain() as $locale) {
            if (isset($this->tables[$locale][$key])) {
                $value = $this->tables[$locale][$key];
                break;
            }
        }

        foreach ($replace as $placeholder => $replacement) {
            $value = str_replace(':' . $placeholder, (string) $replacement, $value);
        }

        return $value;
    }

    /** Whether a translation exists for $key along the locale chain. */
    public function has(string $key): bool
    {
        foreach ($this->chain() as $locale) {
            if (isset($this->tables[$locale][$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Locales searched, in order: pt_AO → pt → fallback (→ its language).
     *
     * @return list<string>
     */
    public function chain(): array
    {
        $chain = [];
        foreach ([$this->locale, $this->fallback] as $locale) {
            $chain[] = $locale;
            $language = strtolower((string) preg_split('/[_-]/', $locale)[0]);
            $chain[] = $language;
        }

        return array_values(array_unique($chain));
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

        $this->activate($locale);
    }

    private function activate(string $locale): void
    {
        $this->locale = $locale;

        // Keep the whole chain loaded so get() never needs a guard.
        foreach ($this->chain() as $each) {
            $this->load($each);
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

    /** @return array<int, string> */
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

        if (preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/', $locale) !== 1) {
            $this->tables[$locale] = [];
            return;
        }

        $table = [];
        foreach ([...$this->defaultPaths, $this->langPath] as $dir) {
            $path = rtrim($dir, '/\\') . '/' . $locale . '.php';
            if (is_file($path)) {
                $data = require $path;
                if (is_array($data)) {
                    $table = array_merge($table, $data);
                }
            }
        }

        $this->tables[$locale] = $table;
    }
}