<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Support\Translator;
use PHPUnit\Framework\TestCase;

class TranslatorTest extends TestCase
{
    private string $langPath;

    protected function setUp(): void
    {
        $this->langPath = sys_get_temp_dir() . '/luany_translator_test_' . uniqid();
        mkdir($this->langPath);

        file_put_contents($this->langPath . '/en.php', '<?php return [
            "nav.home"         => "Home",
            "nav.docs"         => "Docs",
            "footer.copyright" => "© :year :name",
        ];');

        file_put_contents($this->langPath . '/pt.php', '<?php return [
            "nav.home" => "Início",
            "nav.docs" => "Documentação",
        ];');
    }

    protected function tearDown(): void
    {
        @unlink($this->langPath . '/en.php');
        @unlink($this->langPath . '/pt.php');
        @rmdir($this->langPath);
    }

    // ── Constructor ───────────────────────────────────────────────────────────

    public function test_default_locale_is_set(): void
    {
        $t = new Translator($this->langPath, 'en', 'en', ['en', 'pt']);
        $this->assertSame('en', $t->getLocale());
    }

    public function test_fallback_is_set(): void
    {
        $t = new Translator($this->langPath, 'en', 'en', ['en', 'pt']);
        $this->assertSame('en', $t->getFallback());
    }

    public function test_supported_locales_are_set(): void
    {
        $t = new Translator($this->langPath, 'en', 'en', ['en', 'pt']);
        $this->assertSame(['en', 'pt'], $t->getSupported());
    }

    // ── get() ─────────────────────────────────────────────────────────────────

    public function test_get_returns_translation(): void
    {
        $t = new Translator($this->langPath, 'en', 'en', ['en', 'pt']);
        $this->assertSame('Home', $t->get('nav.home'));
    }

    public function test_get_returns_key_when_not_found(): void
    {
        $t = new Translator($this->langPath, 'en', 'en', ['en', 'pt']);
        $this->assertSame('missing.key', $t->get('missing.key'));
    }

    public function test_get_replaces_placeholders(): void
    {
        $t = new Translator($this->langPath, 'en', 'en', ['en', 'pt']);
        $result = $t->get('footer.copyright', ['year' => '2025', 'name' => 'Luany']);
        $this->assertSame('© 2025 Luany', $result);
    }

    public function test_get_falls_back_to_fallback_locale(): void
    {
        $t = new Translator($this->langPath, 'pt', 'en', ['en', 'pt']);
        // footer.copyright only exists in en — should fallback
        $result = $t->get('footer.copyright', ['year' => '2025', 'name' => 'Luany']);
        $this->assertSame('© 2025 Luany', $result);
    }

    // ── setLocale() ───────────────────────────────────────────────────────────

    public function test_set_locale_changes_active_locale(): void
    {
        $t = new Translator($this->langPath, 'en', 'en', ['en', 'pt']);
        $t->setLocale('pt');
        $this->assertSame('pt', $t->getLocale());
    }

    public function test_set_locale_changes_translation_output(): void
    {
        $t = new Translator($this->langPath, 'en', 'en', ['en', 'pt']);
        $t->setLocale('pt');
        $this->assertSame('Início', $t->get('nav.home'));
    }

    public function test_set_locale_ignores_unsupported_locale(): void
    {
        $t = new Translator($this->langPath, 'en', 'en', ['en', 'pt']);
        $t->setLocale('fr');
        $this->assertSame('en', $t->getLocale());
    }

    // ── isSupported() ─────────────────────────────────────────────────────────

    public function test_is_supported_returns_true_for_known_locale(): void
    {
        $t = new Translator($this->langPath, 'en', 'en', ['en', 'pt']);
        $this->assertTrue($t->isSupported('pt'));
    }

    public function test_is_supported_returns_false_for_unknown_locale(): void
    {
        $t = new Translator($this->langPath, 'en', 'en', ['en', 'pt']);
        $this->assertFalse($t->isSupported('fr'));
    }

    // ── Missing lang file ─────────────────────────────────────────────────────

    public function test_missing_lang_file_returns_key(): void
    {
        $t = new Translator($this->langPath, 'en', 'en', ['en', 'fr']);
        $t->setLocale('fr'); // fr.php does not exist
        // key doesn't exist in en either — should return the key itself
        $this->assertSame('missing.unknown', $t->get('missing.unknown'));
    }
}