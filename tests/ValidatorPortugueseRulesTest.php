<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Portuguese rule names behave exactly like the English ones.
 */
class ValidatorPortugueseRulesTest extends TestCase
{
    protected function tearDown(): void
    {
        Validator::flushExtensions();
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function pairs(): array
    {
        return [
            'obrigatorio texto max' => [['nome' => ''], 'obrigatorio|texto|max:5', 'required|string|max:5'],
            'texto longo'           => [['nome' => 'abcdefgh'], 'obrigatorio|texto|max:5', 'required|string|max:5'],
            'inteiro entre'         => [['idade' => '17'], 'inteiro|entre:18,99', 'integer|between:18,99'],
            'numerico'              => [['preco' => 'x'], 'numerico', 'numeric'],
            'booleano'              => [['ativo' => 'talvez'], 'booleano', 'boolean'],
            'lista'                 => [['tags' => 'a'], 'lista', 'array'],
            'data depois'           => [['fim' => '2020-01-01'], 'data|depois:2021-01-01', 'date|after:2021-01-01'],
            'antes_ou_igual'        => [['fim' => '2030-01-01'], 'antes_ou_igual:2029-01-01', 'before_or_equal:2029-01-01'],
            'em / nao_em'           => [['cor' => 'roxo'], 'em:azul,verde|nao_em:roxo', 'in:azul,verde|not_in:roxo'],
            'digitos'               => [['pin' => '12'], 'digitos:4', 'digits:4'],
            'confirmado'            => [['senha' => 'a', 'senha_confirmation' => 'b'], 'confirmado', 'confirmed'],
            'igual / diferente'     => [['a' => '1', 'b' => '2'], 'igual:b', 'same:b'],
            'opcional'              => [['nota' => ''], 'opcional|inteiro', 'nullable|integer'],
            'nao_regex'             => [['codigo' => 'ABC'], 'nao_regex:/^[A-Z]+$/', 'not_regex:/^[A-Z]+$/'],
            'ficheiro / arquivo'    => [['doc' => 'x'], 'arquivo', 'file'],
            'imagem'                => [['foto' => 'x'], 'imagem', 'image'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pairs')]
    public function test_same_result_as_english(array $data, string $portuguese, string $english): void
    {
        $field = (string) array_key_first($data);
        $pt = Validator::make($data, [$field => $portuguese]);
        $en = Validator::make($data, [$field => $english]);

        $this->assertSame($en->errors(), $pt->errors());
        $this->assertSame($en->passes() ? $en->validated() : [], $pt->passes() ? $pt->validated() : []);
    }

    public function test_mixing_languages(): void
    {
        $v = Validator::make(['email' => 'a@b.co', 'idade' => '30'], ['email' => 'obrigatorio|email', 'idade' => 'required|inteiro|min:18']);

        $this->assertTrue($v->passes());
        $this->assertSame(['email' => 'a@b.co', 'idade' => '30'], $v->validated());
    }

    public function test_custom_rule_with_a_portuguese_name_wins(): void
    {
        Validator::extend('texto', fn ($field, $value) => $value === 'sim', 'O campo :field tem de ser sim.');

        $this->assertTrue(Validator::make(['x' => 'sim'], ['x' => 'texto'])->passes());
        $this->assertFalse(Validator::make(['x' => 'abc'], ['x' => 'texto'])->passes());
    }

    public function test_unknown_rule_still_fails_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['x' => 1], ['x' => 'obrigatoria'])->passes();
    }
}
