<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Routing\Route;
use Luany\Framework\Application;
use Luany\Framework\Validation\DatabasePresenceVerifier;
use Luany\Framework\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * unique / exists rules backed by a real database (issue #12).
 */
class PresenceRulesTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required.');
        }

        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        $this->pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec("INSERT INTO users (id, email) VALUES (1, 'ana@x.ao'), (2, 'rui@x.ao')");
        $this->pdo->exec("INSERT INTO tags (id, name) VALUES (1, 'php'), (2, 'php'), (3, 'js')");

        Validator::setPresenceVerifier(new DatabasePresenceVerifier($this->pdo));
    }

    protected function tearDown(): void
    {
        Validator::setPresenceVerifier(null);
        Route::reset();
    }

    public function test_unique_fails_when_value_is_taken(): void
    {
        $v = Validator::make(['email' => 'ana@x.ao'], ['email' => 'unique:users,email']);

        $this->assertTrue($v->fails());
        $this->assertSame(['The email has already been taken.'], $v->errors()['email']);
    }

    public function test_unique_passes_for_new_value_and_defaults_column_to_field(): void
    {
        $this->assertTrue(Validator::make(['email' => 'new@x.ao'], ['email' => 'unique:users'])->passes());
    }

    public function test_unique_ignores_current_record_on_update(): void
    {
        $this->assertTrue(Validator::make(['email' => 'ana@x.ao'], ['email' => 'unique:users,email,1'])->passes());
        $this->assertTrue(Validator::make(['email' => 'ana@x.ao'], ['email' => 'unique:users,email,1,id'])->passes());
        $this->assertTrue(Validator::make(['email' => 'ana@x.ao'], ['email' => 'unique:users,email,2'])->fails());
    }

    public function test_exists_single_value(): void
    {
        $this->assertTrue(Validator::make(['user_id' => '2'], ['user_id' => 'exists:users,id'])->passes());

        $v = Validator::make(['user_id' => '99'], ['user_id' => 'exists:users,id']);
        $this->assertSame(['The selected user_id is invalid.'], $v->errors()['user_id']);
    }

    public function test_exists_array_requires_every_element(): void
    {
        $this->assertTrue(Validator::make(['tags' => ['php', 'js']], ['tags' => 'exists:tags,name'])->passes());
        // 'php' appears twice in the table: duplicates must not hide the missing 'go'
        $this->assertTrue(Validator::make(['tags' => ['php', 'go']], ['tags' => 'exists:tags,name'])->fails());
    }

    public function test_empty_values_are_skipped_unless_required(): void
    {
        $this->assertTrue(Validator::make(['user_id' => ''], ['user_id' => 'exists:users,id'])->passes());
        $this->assertTrue(Validator::make([], ['user_id' => 'required|exists:users,id'])->fails());
    }

    public function test_identifiers_in_rules_are_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['email' => 'x'], ['email' => 'unique:users;DROP TABLE users,email']);
    }

    public function test_missing_table_is_a_configuration_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['email' => 'x'], ['email' => 'exists']);
    }

    public function test_kernel_wires_verifier_from_db_binding(): void
    {
        Validator::setPresenceVerifier(null);
        Route::reset();

        $app = new Application(sys_get_temp_dir() . '/luany-presence-' . uniqid());
        $app->instance('db', $this->pdo);
        (new TestKernel($app))->boot();

        $this->assertInstanceOf(DatabasePresenceVerifier::class, Validator::getPresenceVerifier());
        $this->assertTrue(Validator::make(['email' => 'ana@x.ao'], ['email' => 'unique:users,email'])->fails());
    }

    public function test_kernel_without_database_leaves_verifier_unset(): void
    {
        Validator::setPresenceVerifier(null);
        Route::reset();

        (new TestKernel(new Application(sys_get_temp_dir() . '/luany-presence-' . uniqid())))->boot();

        $this->assertNull(Validator::getPresenceVerifier());
    }
}
