<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Framework\Application;
use Luany\Framework\Http\Kernel;
use Luany\Framework\Http\Middleware\LocaleMiddleware;
use Luany\Framework\Support\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Translator with a default locale outside supported_locales, and
 * LocaleMiddleware's default taken from config('app.locale').
 */
class LocaleDefaultsTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/luany-locale-' . uniqid();
        mkdir($this->base . '/lang', 0777, true);
        mkdir($this->base . '/config', 0777, true);
        file_put_contents($this->base . '/lang/en.php', '<?php return ["hi" => "Hello"];');
        file_put_contents($this->base . '/lang/pt.php', '<?php return ["hi" => "Olá"];');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/*/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->base . '/lang');
        @rmdir($this->base . '/config');
        @rmdir($this->base);
    }

    // ── #9: Translator ──────────────────────────────────────────────────────

    public function test_unsupported_default_locale_falls_back_to_fallback(): void
    {
        $translator = new Translator($this->base . '/lang', 'fr', 'en', ['en', 'pt']);

        $this->assertSame('en', $translator->getLocale());
        $this->assertSame('Hello', $translator->get('hi'));
    }

    public function test_unsupported_default_and_fallback_use_first_supported(): void
    {
        $translator = new Translator($this->base . '/lang', 'fr', 'de', ['pt', 'en']);

        $this->assertSame('pt', $translator->getLocale());
        $this->assertSame('Olá', $translator->get('hi'));
    }

    public function test_empty_supported_list_keeps_the_given_locale(): void
    {
        $translator = new Translator($this->base . '/lang', 'pt', 'en', []);

        $this->assertSame('pt', $translator->getLocale());
        $this->assertSame('Olá', $translator->get('hi'));
    }

    public function test_supported_default_is_unchanged(): void
    {
        $translator = new Translator($this->base . '/lang', 'pt', 'en', ['en', 'pt']);

        $this->assertSame('pt', $translator->getLocale());
    }

    // ── #12: LocaleMiddleware default from config ───────────────────────────

    public function test_middleware_default_comes_from_config_app_locale(): void
    {
        file_put_contents(
            $this->base . '/config/app.php',
            "<?php return ['locale' => 'pt', 'fallback_locale' => 'en', 'supported_locales' => ['en', 'pt']];"
        );
        $app = new Application($this->base);
        (new Kernel($app))->boot();

        /** @var Translator $translator */
        $translator = $app->make('translator');
        $translator->setLocale('en'); // e.g. left over from a previous request

        $previous = $_ENV['APP_LOCALE'] ?? null;
        $_ENV['APP_LOCALE'] = 'en';
        try {
            (new LocaleMiddleware($translator))->handle(new Request('GET', '/'), fn () => Response::make('ok'));
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_LOCALE']);
            } else {
                $_ENV['APP_LOCALE'] = $previous;
            }
        }

        $this->assertSame('pt', $translator->getLocale());
    }
}
