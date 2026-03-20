<?php

namespace Luany\Framework\Support;

/**
 * Config
 *
 * Loads PHP configuration files from a directory and provides
 * dot-notation access to nested values.
 *
 * Each file in the config directory becomes a top-level key:
 *   config/app.php  → Config::get('app.name')
 *   config/database.php → Config::get('database.default')
 *
 * Usage:
 *   $config = new Config('/path/to/config');
 *   $config->get('app.name');              // value
 *   $config->get('app.missing', 'default'); // fallback
 *   $config->set('app.debug', true);        // runtime override
 *   $config->has('app.name');               // true
 *   $config->all();                         // full array
 */
class Config
{
    /** Loaded configuration items */
    /** @var array<string, mixed> */
    private array $items = [];

    /**
     * @param string $configPath Absolute path to the config directory
     */
    public function __construct(string $configPath)
    {
        $this->loadFrom($configPath);
    }

    /**
     * Get a configuration value using dot-notation.
     *
     * @param string $key     Dot-separated key (e.g. 'app.name')
     * @param mixed  $default Fallback if key does not exist
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Set a configuration value at runtime using dot-notation.
     *
     * @param string $key   Dot-separated key
     * @param mixed  $value Value to set
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $target[$segment] = $value;
            } else {
                if (!isset($target[$segment]) || !is_array($target[$segment])) {
                    $target[$segment] = [];
                }
                $target = &$target[$segment];
            }
        }
    }

    /**
     * Determine if a configuration key exists.
     */
    public function has(string $key): bool
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    /**
     * Get all configuration items.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Load all PHP files from the config directory.
     * Each file's basename (without extension) becomes a top-level key.
     */
    private function loadFrom(string $path): void
    {
        $path = rtrim($path, '/\\');

        if (!is_dir($path)) {
            return;
        }

        $files = glob($path . DIRECTORY_SEPARATOR . '*.php');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $key = basename($file, '.php');
            $data = require $file;

            if (is_array($data)) {
                $this->items[$key] = $data;
            }
        }
    }
}