<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Framework\Application;
use Luany\Framework\Http\Middleware\LocaleMiddleware;
use Luany\Framework\ServiceProvider;
use Luany\Framework\Support\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for issue #6 — default translator and q=0 handling.
 */
class TranslatorDefaultTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/luany-i18n-' . uniqid();
        mkdir($this->base . '/lang', 0777, true);
        mkdir($this->base . '/config', 0777, true);
        file_put_contents($this->base . '/lang/en.php', '<?php return ["nav.home" => "Home"];');
        file_put_contents($this->base . '/lang/pt.php', '<?php return ["nav.home" => "Início"];');
    }

    private function bootApp(): Application
    {
        $app = new Application($this->base);
        (new TestKernel($app))->boot();
        return $app;
    }

    public function test_translator_is_registered_by_default(): void
    {
        $app = $this->bootApp();

        $this->assertInstanceOf(Translator::class, $app->make('translator'));
        $this->assertSame($app->make('translator'), $app->make(Translator::class));
        $this->assertSame('Home', __('nav.home'));
        $this->assertSame('en', locale());
    }

    public function test_translator_reads_config_app(): void
    {
        file_put_contents(
            $this->base . '/config/app.php',
            "<?php return ['locale' => 'pt', 'fallback_locale' => 'en', 'supported_locales' => ['en', 'pt']];"
        );

        $app = $this->bootApp();
        /** @var Translator $translator */
        $translator = $app->make('translator');

        $this->assertSame('pt', $translator->getLocale());
        $this->assertSame(['en', 'pt'], $translator->getSupported());
        $this->assertSame('Início', __('nav.home'));
    }

    public function test_provider_translator_is_kept(): void
    {
        $custom = new Translator($this->base . '/lang', 'pt', 'en', ['en', 'pt']);
        $app = new Application($this->base);
        $app->register(new class($custom) extends ServiceProvider {
            public function __construct(private Translator $t) {}
            public function register(Application $app): void
            {
                $app->singleton('translator', fn () => $this->t);
            }
        });
        (new TestKernel($app))->boot();

        $this->assertSame($custom, $app->make('translator'));
        $this->assertSame($custom, $app->make(Translator::class));
    }

    public function test_locale_middleware_works_out_of_the_box(): void
    {
        file_put_contents(
            $this->base . '/config/app.php',
            "<?php return ['supported_locales' => ['en', 'pt']];"
        );
        $app = $this->bootApp();

        $mw = $app->make(LocaleMiddleware::class);
        $mw->handle(new Request('GET', '/', headers: ['Accept-Language' => 'pt-PT,pt;q=0.9']), fn () => Response::make());

        $this->assertSame('pt', locale());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function acceptLanguageCases(): array
    {
        return [
            'q=0 is ignored'                => ['pt;q=0, en;q=0.5', 'en'],
            'q=0.0 is ignored'              => ['pt;q=0.0', 'en'],
            'only rejected → default'       => ['pt;q=0', 'en'],
            'highest q wins'                => ['en;q=0.4, pt;q=0.8', 'pt'],
            'region tag maps to language'   => ['pt-BR', 'pt'],
            'spaces around q'               => ['en ; q = 0.1, pt ; q = 0.9', 'pt'],
            'ties keep header order'        => ['pt, en', 'pt'],
            'rejection overrides earlier'   => ['pt-PT;q=0, en', 'en'],
        ];
    }

    #[DataProvider('acceptLanguageCases')]
    public function test_accept_language_parsing(string $header, string $expected): void
    {
        $translator = new Translator($this->base . '/lang', 'en', 'en', ['en', 'pt']);
        new Application($this->base); // env()/app() need an instance

        (new LocaleMiddleware($translator))->handle(
            new Request('GET', '/', headers: ['Accept-Language' => $header]),
            fn () => Response::make()
        );

        $this->assertSame($expected, $translator->getLocale());
    }
}
