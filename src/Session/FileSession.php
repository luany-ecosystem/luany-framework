<?php

namespace Luany\Framework\Session;

use Luany\Framework\Contracts\SessionInterface;

/**
 * FileSession
 *
 * File-based session driver using PHP's native session handling.
 * Implements SessionInterface for the framework's session abstraction.
 *
 * Flash data is stored under a reserved '_flash' key and automatically
 * aged: "new" flash data becomes "old" on the next start(), and "old"
 * data is purged.
 *
 * Usage:
 *   $session = new FileSession('/path/to/sessions');
 *   $session->start();
 *   $session->set('user_id', 42);
 *   $session->flash('status', 'Profile updated.');
 *   $session->save();
 *
 *   // Next request:
 *   $session->start();
 *   $session->get('status'); // 'Profile updated.'
 *   // After another start(), 'status' is gone.
 */
class FileSession implements SessionInterface
{
    private bool $started = false;

    /**
     * @param string $savePath Directory where session files are stored
     */
    public function __construct(private string $savePath = '')
    {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if ($this->savePath !== '') {
            if (!is_dir($this->savePath)) {
                mkdir($this->savePath, 0700, true);
            }
            if (session_status() === PHP_SESSION_NONE) {
                session_save_path($this->savePath);
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->started = true;
        $this->ageFlashData();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        // Check flash old data first (available for current request)
        $flash = $_SESSION['_flash'] ?? [];
        if (isset($flash['old'][$key])) {
            return $flash['old'][$key];
        }

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();

        $flash = $_SESSION['_flash'] ?? [];
        if (isset($flash['old'][$key])) {
            return true;
        }

        return array_key_exists($key, $_SESSION) && $key !== '_flash';
    }

    public function forget(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $this->ensureStarted();

        if (!isset($_SESSION['_flash'])) {
            $_SESSION['_flash'] = ['new' => [], 'old' => []];
        }

        $_SESSION['_flash']['new'][$key] = $value;
    }

    public function regenerate(): void
    {
        $this->ensureStarted();
        session_regenerate_id(true);
    }

    public function save(): void
    {
        if ($this->started && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->started = false;
    }

    public function getId(): string
    {
        return session_id() ?: '';
    }

    public function all(): array
    {
        $this->ensureStarted();

        $data = $_SESSION;
        unset($data['_flash']);

        // Merge old flash data (visible to current request)
        $flash = $_SESSION['_flash'] ?? [];
        if (isset($flash['old'])) {
            $data = array_merge($data, $flash['old']);
        }

        return $data;
    }

    public function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        $this->started = false;
    }

    /**
     * Age flash data: move "new" to "old", purge previous "old".
     * Called at the beginning of each request (start()).
     */
    private function ageFlashData(): void
    {
        $flash = $_SESSION['_flash'] ?? ['new' => [], 'old' => []];

        // Previous "old" is purged, current "new" becomes "old"
        $_SESSION['_flash'] = [
            'new' => [],
            'old' => $flash['new'] ?? [],
        ];
    }

    private function ensureStarted(): void
    {
        if (!$this->started) {
            throw new \RuntimeException(
                'Session has not been started. Call start() before accessing session data.'
            );
        }
    }
}
