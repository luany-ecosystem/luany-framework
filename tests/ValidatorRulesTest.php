<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Validation\Rule;
use Luany\Framework\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for issue #5 — new rules, unknown rules, absent fields, custom rules.
 */
class ValidatorRulesTest extends TestCase
{
    protected function tearDown(): void
    {
        Validator::flushExtensions();
    }

    private function passes(array $data, array $rules): bool
    {
        return Validator::make($data, $rules)->passes();
    }

    /** @return array<string, array{0: array<string,mixed>, 1: array<string,mixed>, 2: bool}> */
    public static function ruleCases(): array
    {
        return [
            // integer
            'integer int'            => [['n' => 5], ['n' => 'integer'], true],
            'integer string'         => [['n' => '-42'], ['n' => 'integer'], true],
            'integer float string'   => [['n' => '4.2'], ['n' => 'integer'], false],
            'integer word'           => [['n' => 'abc'], ['n' => 'integer'], false],
            'integer padded'         => [['n' => ' 4'], ['n' => 'integer'], false],
            'integer bool'           => [['n' => true], ['n' => 'integer'], false],
            'integer empty skipped'  => [['n' => ''], ['n' => 'integer'], true],
            // numeric (existing)
            'numeric float'          => [['n' => '4.5'], ['n' => 'numeric'], true],
            'numeric word'           => [['n' => 'x'], ['n' => 'numeric'], false],
            // boolean
            'boolean on'             => [['b' => 'on'], ['b' => 'boolean'], true],
            'boolean 0'              => [['b' => '0'], ['b' => 'boolean'], true],
            'boolean false'          => [['b' => false], ['b' => 'boolean'], true],
            'boolean junk'           => [['b' => 'maybe'], ['b' => 'boolean'], false],
            'boolean array'          => [['b' => [1]], ['b' => 'boolean'], false],
            // array
            'array ok'               => [['a' => [1, 2]], ['a' => 'array'], true],
            'array string'           => [['a' => 'x'], ['a' => 'array'], false],
            // date
            'date ok'                => [['d' => '2026-01-31'], ['d' => 'date'], true],
            'date bad'               => [['d' => 'not a date'], ['d' => 'date'], false],
            'date array'             => [['d' => ['x']], ['d' => 'date'], false],
            'after literal ok'       => [['d' => '2026-02-01'], ['d' => 'after:2026-01-31'], true],
            'after literal equal'    => [['d' => '2026-01-31'], ['d' => 'after:2026-01-31'], false],
            'after_or_equal equal'   => [['d' => '2026-01-31'], ['d' => 'after_or_equal:2026-01-31'], true],
            'before literal ok'      => [['d' => '2026-01-01'], ['d' => 'before:2026-01-31'], true],
            'before literal fail'    => [['d' => '2026-03-01'], ['d' => 'before:2026-01-31'], false],
            'before_or_equal equal'  => [['d' => '2026-01-31'], ['d' => 'before_or_equal:2026-01-31'], true],
            'after field ok'         => [['start' => '2026-01-01', 'end' => '2026-01-05'], ['end' => 'after:start'], true],
            'after field fail'       => [['start' => '2026-01-10', 'end' => '2026-01-05'], ['end' => 'after:start'], false],
            // in / not_in
            'in ok'                  => [['r' => 'admin'], ['r' => 'in:admin,editor'], true],
            'in array value'         => [['r' => ['admin']], ['r' => 'in:admin,editor'], false],
            'not_in ok'              => [['r' => 'viewer'], ['r' => 'not_in:admin,root'], true],
            'not_in fail'            => [['r' => 'root'], ['r' => 'not_in:admin,root'], false],
            // regex
            'regex ok'               => [['c' => 'ABC'], ['c' => 'regex:/^[A-Z]{3}$/'], true],
            'regex fail'             => [['c' => 'abc'], ['c' => 'regex:/^[A-Z]{3}$/'], false],
            'regex with comma'       => [['c' => 'aa'], ['c' => 'regex:/^a{1,3}$/'], true],
            'regex with pipe array'  => [['c' => 'dog'], ['c' => ['regex:/^(cat|dog)$/']], true],
            'not_regex ok'           => [['c' => 'abc'], ['c' => 'not_regex:/\d/'], true],
            'not_regex fail'         => [['c' => 'a1'], ['c' => 'not_regex:/\d/'], false],
            // url
            'url ok'                 => [['u' => 'https://luany.dev/docs?x=1'], ['u' => 'url'], true],
            'url javascript'         => [['u' => 'javascript://alert(1)'], ['u' => 'url'], false],
            'url relative'           => [['u' => '/docs'], ['u' => 'url'], false],
            // confirmed (existing)
            'confirmed ok'           => [['p' => 'x', 'p_confirmation' => 'x'], ['p' => 'confirmed'], true],
            'confirmed fail'         => [['p' => 'x', 'p_confirmation' => 'y'], ['p' => 'confirmed'], false],
            // same / different
            'same ok'                => [['a' => 'x', 'b' => 'x'], ['a' => 'same:b'], true],
            'same fail'              => [['a' => 'x', 'b' => 'y'], ['a' => 'same:b'], false],
            'different ok'           => [['a' => 'x', 'b' => 'y'], ['a' => 'different:b'], true],
            'different fail'         => [['a' => 'x', 'b' => 'x'], ['a' => 'different:b'], false],
            // digits
            'digits ok'              => [['pin' => '0123'], ['pin' => 'digits:4'], true],
            'digits int'             => [['pin' => 1234], ['pin' => 'digits:4'], true],
            'digits length'          => [['pin' => '123'], ['pin' => 'digits:4'], false],
            'digits letters'         => [['pin' => '12a4'], ['pin' => 'digits:4'], false],
            // between
            'between number ok'      => [['n' => '5'], ['n' => 'between:1,10'], true],
            'between number fail'    => [['n' => 11], ['n' => 'between:1,10'], false],
            'between string ok'      => [['s' => 'abc'], ['s' => 'between:2,4'], true],
            'between string fail'    => [['s' => 'abcde'], ['s' => 'between:2,4'], false],
            'between array ok'       => [['a' => [1, 2]], ['a' => 'between:1,3'], true],
            // min / max on arrays
            'min array fail'         => [['a' => [1]], ['a' => 'array|min:2'], false],
            'max array fail'         => [['a' => [1, 2, 3]], ['a' => 'array|max:2'], false],
            // nullable
            'nullable null'          => [['d' => null], ['d' => 'nullable|date'], true],
            'nullable empty'         => [['d' => ''], ['d' => 'nullable|date'], true],
            'nullable invalid'       => [['d' => 'nope'], ['d' => 'nullable|date'], false],
            // sometimes
            'sometimes absent'       => [[], ['e' => 'sometimes|required|email'], true],
            'sometimes present bad'  => [['e' => ''], ['e' => 'sometimes|required|email'], false],
        ];
    }

    #[DataProvider('ruleCases')]
    public function test_rule(array $data, array $rules, bool $expected): void
    {
        $this->assertSame($expected, $this->passes($data, $rules));
    }

    // ── Unknown / malformed rules ────────────────────────────────────────────

    public function test_unknown_rule_throws_even_when_field_absent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make([], ['age' => 'sometimes|intger']);
    }

    public function test_unknown_rule_in_array_syntax_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['a' => 1], ['a' => ['required', 'bogus']]);
    }

    public function test_invalid_regex_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['a' => 'x'], ['a' => 'regex:/[unclosed/']);
    }

    public function test_between_without_params_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['a' => '5'], ['a' => 'between:1']);
    }

    public function test_non_string_rule_entry_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['a' => '5'], ['a' => ['required', 42]]);
    }

    // ── Absent fields / booleans ─────────────────────────────────────────────

    public function test_absent_boolean_field_becomes_false(): void
    {
        $v = Validator::make(['name' => 'x'], ['name' => 'required', 'newsletter' => 'boolean']);

        $this->assertTrue($v->passes());
        $this->assertSame(['name' => 'x', 'newsletter' => false], $v->validated());
    }

    public function test_boolean_values_are_cast(): void
    {
        $v = Validator::make(
            ['a' => 'on', 'b' => '0', 'c' => 'TRUE', 'd' => 'no', 'e' => ''],
            ['a' => 'boolean', 'b' => 'boolean', 'c' => 'boolean', 'd' => 'boolean', 'e' => 'boolean']
        );

        $this->assertSame(['a' => true, 'b' => false, 'c' => true, 'd' => false, 'e' => false], $v->validated());
    }

    public function test_absent_boolean_with_sometimes_is_omitted(): void
    {
        $v = Validator::make([], ['flag' => 'sometimes|boolean']);
        $this->assertSame([], $v->validated());
    }

    public function test_required_boolean_still_requires_presence(): void
    {
        $v = Validator::make([], ['terms' => 'required|boolean']);
        $this->assertArrayHasKey('terms', $v->errors());
    }

    public function test_absent_required_field_is_reported(): void
    {
        $v = Validator::make([], ['email' => 'required|email']);
        $this->assertArrayHasKey('email', $v->errors());
    }

    public function test_absent_optional_field_is_not_in_validated(): void
    {
        $v = Validator::make([], ['nickname' => 'string|max:20']);
        $this->assertTrue($v->passes());
        $this->assertSame([], $v->validated());
    }

    public function test_nullable_empty_string_becomes_null(): void
    {
        $v = Validator::make(['birthday' => ''], ['birthday' => 'nullable|date']);
        $this->assertSame(['birthday' => null], $v->validated());
    }

    // ── Custom rules ─────────────────────────────────────────────────────────

    public function test_rule_object(): void
    {
        $rule = new class implements Rule {
            public function passes(string $field, mixed $value, array $data): bool
            {
                return strtoupper((string) $value) === $value;
            }

            public function message(string $field): string
            {
                return "The {$field} must be uppercase.";
            }
        };

        $this->assertTrue($this->passes(['code' => 'ABC'], ['code' => ['required', $rule]]));

        $v = Validator::make(['code' => 'abc'], ['code' => ['required', $rule]]);
        $this->assertSame(['code' => ['The code must be uppercase.']], $v->errors());
    }

    public function test_closure_rule(): void
    {
        $even = fn (string $field, mixed $value) => (int) $value % 2 === 0 ? true : "The {$field} must be even.";

        $this->assertTrue($this->passes(['n' => '4'], ['n' => ['integer', $even]]));
        $this->assertSame(['n' => ['The n must be even.']], Validator::make(['n' => '3'], ['n' => [$even]])->errors());
    }

    public function test_closure_rule_false_uses_generic_message(): void
    {
        $v = Validator::make(['n' => 'x'], ['n' => [fn () => false]]);
        $this->assertSame(['n' => ['The n field is invalid.']], $v->errors());
    }

    public function test_closure_receives_all_data(): void
    {
        $rule = fn (string $field, mixed $value, array $data) => $value !== $data['other'];

        $this->assertFalse($this->passes(['a' => 'x', 'other' => 'x'], ['a' => [$rule]]));
    }

    public function test_extend_registers_named_rule(): void
    {
        Validator::extend('slug', fn ($field, $value) => (bool) preg_match('/^[a-z0-9-]+$/', $value), 'The :field must be a slug.');

        $this->assertTrue($this->passes(['handle' => 'my-post'], ['handle' => 'required|slug']));
        $this->assertSame(
            ['handle' => ['The handle must be a slug.']],
            Validator::make(['handle' => 'My Post'], ['handle' => 'required|slug'])->errors()
        );
    }

    public function test_extend_receives_params(): void
    {
        Validator::extend('starts_with', fn ($field, $value, array $params) => str_starts_with($value, $params[0]));

        $this->assertTrue($this->passes(['s' => 'lu-1'], ['s' => 'starts_with:lu']));
        $this->assertFalse($this->passes(['s' => 'xx-1'], ['s' => 'starts_with:lu']));
    }

    public function test_cli_generated_rules_exist(): void
    {
        // luany CLI generates integer, boolean and date rules.
        $v = Validator::make(
            ['qty' => '3', 'active' => '1', 'published_at' => '2026-05-01'],
            ['qty' => 'required|integer', 'active' => 'boolean', 'published_at' => 'nullable|date']
        );

        $this->assertTrue($v->passes());
        $this->assertSame(['qty' => '3', 'active' => true, 'published_at' => '2026-05-01'], $v->validated());
    }
}
