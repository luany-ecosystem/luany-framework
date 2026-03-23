<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 */
class ValidatorTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset unique checker between tests
        Validator::setUniqueChecker(fn() => false);
    }

    // ── Factory & basic API ──────────────────────────────────────────────────

    public function testMakeReturnsValidatorInstance(): void
    {
        $v = Validator::make(['name' => 'Luany'], ['name' => 'required']);
        $this->assertInstanceOf(Validator::class, $v);
    }

    public function testPassesWhenAllRulesPass(): void
    {
        $v = Validator::make(
            ['name' => 'Luany', 'email' => 'test@example.com'],
            ['name' => 'required|string', 'email' => 'required|email']
        );

        $this->assertTrue($v->passes());
        $this->assertFalse($v->fails());
        $this->assertEmpty($v->errors());
    }

    public function testValidatedReturnsOnlyValidatedFields(): void
    {
        $v = Validator::make(
            ['name' => 'Luany', 'extra' => 'ignored'],
            ['name' => 'required|string']
        );

        $this->assertSame(['name' => 'Luany'], $v->validated());
    }

    // ── required ─────────────────────────────────────────────────────────────

    public function testRequiredFailsWhenFieldMissing(): void
    {
        $v = Validator::make([], ['name' => 'required']);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('name', $v->errors());
    }

    public function testRequiredFailsWhenFieldIsEmptyString(): void
    {
        $v = Validator::make(['name' => ''], ['name' => 'required']);

        $this->assertTrue($v->fails());
    }

    public function testRequiredFailsWhenFieldIsEmptyArray(): void
    {
        $v = Validator::make(['tags' => []], ['tags' => 'required']);

        $this->assertTrue($v->fails());
    }

    public function testRequiredPassesWhenFieldIsPresent(): void
    {
        $v = Validator::make(['name' => 'Luany'], ['name' => 'required']);

        $this->assertTrue($v->passes());
    }

    public function testRequiredPassesWhenFieldIsZero(): void
    {
        $v = Validator::make(['count' => 0], ['count' => 'required']);

        $this->assertTrue($v->passes());
    }

    // ── string ───────────────────────────────────────────────────────────────

    public function testStringPassesForStringValue(): void
    {
        $v = Validator::make(['name' => 'Luany'], ['name' => 'string']);
        $this->assertTrue($v->passes());
    }

    public function testStringFailsForIntegerValue(): void
    {
        $v = Validator::make(['name' => 123], ['name' => 'string']);
        $this->assertTrue($v->fails());
    }

    public function testStringSkipsNullValue(): void
    {
        $v = Validator::make(['name' => null], ['name' => 'string']);
        $this->assertTrue($v->passes());
    }

    // ── email ────────────────────────────────────────────────────────────────

    public function testEmailPassesForValidEmail(): void
    {
        $v = Validator::make(['email' => 'user@example.com'], ['email' => 'email']);
        $this->assertTrue($v->passes());
    }

    public function testEmailFailsForInvalidEmail(): void
    {
        $v = Validator::make(['email' => 'not-an-email'], ['email' => 'email']);
        $this->assertTrue($v->fails());
    }

    public function testEmailFailsForEmailWithoutDomain(): void
    {
        $v = Validator::make(['email' => 'user@'], ['email' => 'email']);
        $this->assertTrue($v->fails());
    }

    public function testEmailSkipsEmptyValue(): void
    {
        $v = Validator::make(['email' => ''], ['email' => 'email']);
        $this->assertTrue($v->passes());
    }

    // ── numeric ──────────────────────────────────────────────────────────────

    public function testNumericPassesForInteger(): void
    {
        $v = Validator::make(['age' => 25], ['age' => 'numeric']);
        $this->assertTrue($v->passes());
    }

    public function testNumericPassesForFloat(): void
    {
        $v = Validator::make(['price' => 19.99], ['price' => 'numeric']);
        $this->assertTrue($v->passes());
    }

    public function testNumericPassesForNumericString(): void
    {
        $v = Validator::make(['age' => '25'], ['age' => 'numeric']);
        $this->assertTrue($v->passes());
    }

    public function testNumericFailsForNonNumericString(): void
    {
        $v = Validator::make(['age' => 'twenty'], ['age' => 'numeric']);
        $this->assertTrue($v->fails());
    }

    // ── min ──────────────────────────────────────────────────────────────────

    public function testMinStringLengthPasses(): void
    {
        $v = Validator::make(['name' => 'Luany'], ['name' => 'string|min:3']);
        $this->assertTrue($v->passes());
    }

    public function testMinStringLengthFails(): void
    {
        $v = Validator::make(['name' => 'Lu'], ['name' => 'string|min:3']);
        $this->assertTrue($v->fails());
    }

    public function testMinNumericValuePasses(): void
    {
        $v = Validator::make(['age' => 18], ['age' => 'numeric|min:18']);
        $this->assertTrue($v->passes());
    }

    public function testMinNumericValueFails(): void
    {
        $v = Validator::make(['age' => 15], ['age' => 'numeric|min:18']);
        $this->assertTrue($v->fails());
    }

    // ── max ──────────────────────────────────────────────────────────────────

    public function testMaxStringLengthPasses(): void
    {
        $v = Validator::make(['name' => 'Luany'], ['name' => 'string|max:10']);
        $this->assertTrue($v->passes());
    }

    public function testMaxStringLengthFails(): void
    {
        $v = Validator::make(['name' => 'A very long name indeed'], ['name' => 'string|max:10']);
        $this->assertTrue($v->fails());
    }

    public function testMaxNumericValuePasses(): void
    {
        $v = Validator::make(['score' => 95], ['score' => 'numeric|max:100']);
        $this->assertTrue($v->passes());
    }

    public function testMaxNumericValueFails(): void
    {
        $v = Validator::make(['score' => 150], ['score' => 'numeric|max:100']);
        $this->assertTrue($v->fails());
    }

    // ── in ───────────────────────────────────────────────────────────────────

    public function testInPassesWhenValueIsInList(): void
    {
        $v = Validator::make(['role' => 'admin'], ['role' => 'in:admin,editor,viewer']);
        $this->assertTrue($v->passes());
    }

    public function testInFailsWhenValueNotInList(): void
    {
        $v = Validator::make(['role' => 'superuser'], ['role' => 'in:admin,editor,viewer']);
        $this->assertTrue($v->fails());
    }

    public function testInSkipsEmptyValue(): void
    {
        $v = Validator::make(['role' => ''], ['role' => 'in:admin,editor']);
        $this->assertTrue($v->passes());
    }

    // ── confirmed ────────────────────────────────────────────────────────────

    public function testConfirmedPassesWhenConfirmationMatches(): void
    {
        $v = Validator::make(
            ['password' => 'secret123', 'password_confirmation' => 'secret123'],
            ['password' => 'confirmed']
        );
        $this->assertTrue($v->passes());
    }

    public function testConfirmedFailsWhenConfirmationMissing(): void
    {
        $v = Validator::make(
            ['password' => 'secret123'],
            ['password' => 'confirmed']
        );
        $this->assertTrue($v->fails());
    }

    public function testConfirmedFailsWhenConfirmationDoesNotMatch(): void
    {
        $v = Validator::make(
            ['password' => 'secret123', 'password_confirmation' => 'different'],
            ['password' => 'confirmed']
        );
        $this->assertTrue($v->fails());
    }

    // ── unique ───────────────────────────────────────────────────────────────

    public function testUniquePassesWhenValueDoesNotExist(): void
    {
        Validator::setUniqueChecker(fn(string $table, string $col, mixed $val) => false);

        $v = Validator::make(
            ['email' => 'new@example.com'],
            ['email' => 'unique:users,email']
        );
        $this->assertTrue($v->passes());
    }

    public function testUniqueFailsWhenValueExists(): void
    {
        Validator::setUniqueChecker(fn(string $table, string $col, mixed $val) => true);

        $v = Validator::make(
            ['email' => 'taken@example.com'],
            ['email' => 'unique:users,email']
        );
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('already been taken', $v->errors()['email'][0]);
    }

    public function testUniqueCheckerReceivesCorrectArguments(): void
    {
        $receivedArgs = [];
        Validator::setUniqueChecker(function (string $table, string $col, mixed $val) use (&$receivedArgs) {
            $receivedArgs = compact('table', 'col', 'val');
            return false;
        });

        Validator::make(
            ['email' => 'test@example.com'],
            ['email' => 'unique:users,email']
        );

        $this->assertSame('users', $receivedArgs['table']);
        $this->assertSame('email', $receivedArgs['col']);
        $this->assertSame('test@example.com', $receivedArgs['val']);
    }

    public function testUniqueDefaultsColumnToFieldName(): void
    {
        $receivedCol = null;
        Validator::setUniqueChecker(function (string $table, string $col, mixed $val) use (&$receivedCol) {
            $receivedCol = $col;
            return false;
        });

        Validator::make(['email' => 'x@example.com'], ['email' => 'unique:users']);

        $this->assertSame('email', $receivedCol);
    }

    public function testUniqueSkipsWhenNoCheckerRegistered(): void
    {
        // Reset to null
        Validator::setUniqueChecker(fn() => false);
        // Use reflection to set to null
        $ref = new \ReflectionClass(Validator::class);
        $prop = $ref->getProperty('uniqueChecker');
        $prop->setValue(null, null);

        $v = Validator::make(
            ['email' => 'test@example.com'],
            ['email' => 'unique:users,email']
        );
        $this->assertTrue($v->passes());
    }

    // ── Combined rules ───────────────────────────────────────────────────────

    public function testMultipleRulesCombined(): void
    {
        $v = Validator::make(
            ['name' => 'Ng', 'email' => 'bad', 'age' => 'not-a-number'],
            [
                'name'  => 'required|string|min:3',
                'email' => 'required|email',
                'age'   => 'required|numeric',
            ]
        );

        $this->assertTrue($v->fails());
        $errors = $v->errors();
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('age', $errors);
    }

    public function testFullRegistrationValidation(): void
    {
        $v = Validator::make(
            [
                'name'                  => 'António Ngola',
                'email'                 => 'antonio@luany.dev',
                'password'              => 'secure-pass-123',
                'password_confirmation' => 'secure-pass-123',
                'role'                  => 'admin',
            ],
            [
                'name'     => 'required|string|min:2|max:255',
                'email'    => 'required|email',
                'password' => 'required|string|min:8|confirmed',
                'role'     => 'required|in:admin,editor,viewer',
            ]
        );

        $this->assertTrue($v->passes());
        $validated = $v->validated();
        $this->assertSame('António Ngola', $validated['name']);
        $this->assertSame('antonio@luany.dev', $validated['email']);
        $this->assertArrayHasKey('password', $validated);
        $this->assertArrayHasKey('role', $validated);
    }

    // ── Error messages ───────────────────────────────────────────────────────

    public function testErrorMessagesContainFieldName(): void
    {
        $v = Validator::make([], ['username' => 'required']);

        $this->assertStringContainsString('username', $v->errors()['username'][0]);
    }

    public function testMultipleErrorsPerField(): void
    {
        $v = Validator::make(
            ['password' => ''],
            ['password' => 'required|string|min:8|confirmed']
        );

        $errors = $v->errors()['password'];
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    // ── Edge cases ───────────────────────────────────────────────────────────

    public function testEmptyRulesReturnsNoErrors(): void
    {
        $v = Validator::make(['name' => 'test'], []);
        $this->assertTrue($v->passes());
        $this->assertEmpty($v->validated());
    }

    public function testUnknownRuleIsIgnored(): void
    {
        $v = Validator::make(['name' => 'test'], ['name' => 'required|unknown_rule']);
        $this->assertTrue($v->passes());
    }

    public function testMultibyteStringMinMax(): void
    {
        // "António" is 7 characters (multibyte)
        $v = Validator::make(['name' => 'António'], ['name' => 'string|min:5|max:10']);
        $this->assertTrue($v->passes());
    }
}
