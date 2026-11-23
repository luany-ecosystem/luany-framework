<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Support\Translator;
use Luany\Framework\Validation\Validator;
use PHPUnit\Framework\TestCase;

class TranslatedValidationTest extends TestCase
{
    private string $lang;

    protected function setUp(): void
    {
        $this->lang = sys_get_temp_dir() . '/luany-lang-' . bin2hex(random_bytes(4));
        mkdir($this->lang);
    }

    protected function tearDown(): void
    {
        Validator::setTranslator(null);
        exec('rm -rf ' . escapeshellarg($this->lang));
    }

    private function translator(string $locale): Translator
    {
        return new Translator($this->lang, $locale, 'en', ['en', 'pt', 'pt_AO', 'pt_BR'], [dirname(__DIR__) . '/resources/lang']);
    }

    private function useTranslator(Translator $t): void
    {
        Validator::setTranslator(fn (string $key, array $replace): ?string => $t->has($key) ? $t->get($key, $replace) : null);
    }

    public function test_regional_locale_falls_back_to_language_then_fallback(): void
    {
        file_put_contents($this->lang . '/pt.php', "<?php return ['ola' => 'Olá', 'so.pt' => 'só pt'];");
        file_put_contents($this->lang . '/pt_AO.php', "<?php return ['ola' => 'Olá, mano'];");
        file_put_contents($this->lang . '/en.php', "<?php return ['so.en' => 'only en'];");

        $t = $this->translator('pt_AO');
        $this->assertSame(['pt_AO', 'pt', 'en'], $t->chain());
        $this->assertSame('Olá, mano', $t->get('ola'));
        $this->assertSame('só pt', $t->get('so.pt'));
        $this->assertSame('only en', $t->get('so.en'));
        $this->assertSame('nada', $t->get('nada'));
    }

    public function test_validation_messages_in_portuguese(): void
    {
        $this->useTranslator($this->translator('pt_AO'));

        $v = Validator::make(['nome' => '', 'idade' => '15', 'bio' => str_repeat('x', 10)], [
            'nome'  => 'obrigatorio',
            'idade' => 'inteiro|entre:18,99',
            'bio'   => 'texto|max:5',
        ]);

        $this->assertSame([
            'nome'  => ['O campo nome é obrigatório.'],
            'idade' => ['O campo idade tem de estar entre 18 e 99.'],
            'bio'   => ['O campo bio não pode ter mais de 5 caracteres.'],
        ], $v->errors());
    }

    public function test_app_files_override_the_framework_and_pt_br_differs(): void
    {
        file_put_contents($this->lang . '/pt.php', "<?php return ['validation.required' => 'Preencha :field.'];");
        $this->useTranslator($this->translator('pt'));
        $this->assertSame(['Preencha nome.'], Validator::make([], ['nome' => 'required'])->errors()['nome']);

        $this->useTranslator($this->translator('pt_BR'));
        $this->assertSame(['O campo doc tem de ser um arquivo.'], Validator::make(['doc' => 'x'], ['doc' => 'file'])->errors()['doc']);
    }

    public function test_english_without_a_translation(): void
    {
        $this->useTranslator($this->translator('en'));
        $this->assertSame(['The nome field is required.'], Validator::make([], ['nome' => 'required'])->errors()['nome']);

        Validator::setTranslator(null);
        $this->assertSame(['The fim field must be a date after 2030-01-01.'], Validator::make(['fim' => '2020-01-01'], ['fim' => 'after:2030-01-01'])->errors()['fim']);
    }

    public function test_every_english_message_has_a_portuguese_line(): void
    {
        $pt = require dirname(__DIR__) . '/resources/lang/pt.php';
        preg_match_all("/\\\$this->msg\\(\\s*['\"]([a-z_.]+)(\\{\\\$kind\\})?/", (string) file_get_contents(dirname(__DIR__) . '/src/Validation/Validator.php'), $m, PREG_SET_ORDER);
        $keys = [];
        foreach ($m as $match) {
            if (!empty($match[2])) {
                foreach (['numeric', 'string', 'array', 'file'] as $kind) {
                    $keys[] = 'validation.' . $match[1] . $kind;
                }
            } else {
                $keys[] = 'validation.' . $match[1];
            }
        }
        foreach (['after', 'before', 'after_or_equal', 'before_or_equal'] as $op) {
            $keys[] = 'validation.' . $op;
        }
        foreach (array_unique($keys) as $key) {
            if ($key === 'validation.' . '$operator' || str_contains($key, '$')) {
                continue;
            }
            $this->assertArrayHasKey($key, $pt, $key);
        }
    }

    public function test_locale_names_cannot_escape_the_lang_directory(): void
    {
        $t = new Translator($this->lang, '../../etc/passwd', 'en', []);
        $this->assertSame('x', $t->get('x'));
    }
}
