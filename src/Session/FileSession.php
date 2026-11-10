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
 *
 * Security defaults (applied before session_start(), when headers have not
 * been sent yet):
 *   - cookie httponly, SameSite=Lax, secure automatically on HTTPS
 *   - session.use_strict_mode=1 (uninitialised IDs are rejected)
 *   - session.use_only_cookies=1, session.use_trans_sid=0
 *
 * All of them can be changed through the $options array (see DEFAULTS),
 * typically from config/session.php via the Kernel.
 */
class FileSession implements SessionInterface
{
    /**
     * Default options.
     *
     *   name         Cookie name ('' keeps PHP's session.name)
     *   lifetime     Cookie lifetime in seconds (0 = until browser closes)
     *   path         Cookie path
     *   domain       Cookie domain ('' = current host only)
     *   secure       true/false, or null to enable automatically on HTTPS
     *   httponly     Hide the cookie from JavaScript
     *   samesite     'Lax' | 'Strict' | 'None'
     *   strict_mode  session.use_strict_mode
     *   only_cookies session.use_only_cookies (and disables trans_sid)
     */
    public const DEFAULTS = [
        'name'         => '',
        'lifetime'     => 0,
        'path'         => '/',
        'domain'       => '',
        'secure'       => null,
        'httponly'     => true,
        'samesite'     => 'Lax',
        'strict_mode'  => true,
        'only_cookies' => true,
    ];

    private bool $started = false;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param string               $savePath Directory where session files are stored
     * @param array<string, mixed> $options  Cookie/security options (see DEFAULTS)
     */
    public function __construct(private string $savePath = '', array $options = [])
    {
        $this->options = array_merge(self::DEFAULTS, array_intersect_key($options, self::DEFAULTS));
    }

    /**
     * The effective session options (defaults merged with overrides).
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Cookie parameters passed to session_set_cookie_params().
     *
     * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string}
     */
    public function cookieParams(): array
    {
        $secure = $this->options['secure'];

        return [
            'lifetime' => (int) $this->options['lifetime'],
            'path'     => (string) $this->options['path'],
            'domain'   => (string) $this->options['domain'],
            'secure'   => $secure === null ? self::isHttps() : (bool) $secure,
            'httponly' => (bool) $this->options['httponly'],
            'samesite' => (string) $this->options['samesite'],
        ];
    }

    /**
     * Whether the current request arrived over HTTPS.
     *
     * Only the server's own view is trusted (HTTPS / port 443). Behind a
     * TLS-terminating proxy, set the 'secure' option to true explicitly.
     */
    public static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
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
            $this->configure();
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

    /**
     * Regenerate the session ID, deleting the old session file.
     * Call after login, logout and privilege changes (prevents fixation).
     */
    public function regenerate(): void
    {
        $this->ensureStarted();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
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
     * Apply the security ini settings and cookie parameters.
     * Must run before session_start(); skipped once headers are sent
     * (PHP refuses to change them at that point).
     */
    private function configure(): void
    {
        if (headers_sent()) {
            return;
        }

        ini_set('session.use_strict_mode', $this->options['strict_mode'] ? '1' : '0');
        ini_set('session.use_only_cookies', $this->options['only_cookies'] ? '1' : '0');
        if ($this->options['only_cookies']) {
            ini_set('session.use_trans_sid', '0');
        }

        session_set_cookie_params($this->cookieParams());

        $name = (string) $this->options['name'];
        if ($name !== '') {
            session_name($name);
        }
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
