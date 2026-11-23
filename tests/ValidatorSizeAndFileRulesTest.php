<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * min / max / between size semantics (Laravel-like) and file upload rules.
 */
class ValidatorSizeAndFileRulesTest extends TestCase
{
    /** @var array<int, string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        \Luany\Framework\Validation\Validator::setTranslator(null);
        // Test fixtures are not real HTTP uploads.
        Validator::setUploadedFileVerifier(fn (string $path) => is_file($path));
    }

    protected function tearDown(): void
    {
        Validator::setUploadedFileVerifier(null);
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
    }

    // ── #10: size semantics ──────────────────────────────────────────────────

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, string>, 2: bool}> */
    public static function sizeCases(): array
    {
        return [
            'numeric string, no numeric rule → length'  => [['code' => '300'], ['code' => 'string|max:255'], true],
            'numeric string, no rule → length (min)'    => [['code' => '1234'], ['code' => 'min:4'], true],
            'numeric string + numeric → value'          => [['n' => '300'], ['n' => 'numeric|max:255'], false],
            'numeric string + integer → value'          => [['n' => '300'], ['n' => 'integer|max:255'], false],
            'numeric string + integer → value ok'       => [['n' => '200'], ['n' => 'integer|max:255'], true],
            'int value → value'                         => [['n' => 300], ['n' => 'max:255'], false],
            'float value → value'                       => [['n' => 2.5], ['n' => 'min:3'], false],
            'between numeric string, no rule → length'  => [['zip' => '12345'], ['zip' => 'between:5,5'], true],
            'between numeric string + numeric → value'  => [['zip' => '12345'], ['zip' => 'numeric|between:5,5'], false],
            'array → count'                             => [['tags' => ['a', 'b', 'c']], ['tags' => 'array|max:2'], false],
            'multibyte string length'                   => [['name' => 'Ação'], ['name' => 'max:4'], true],
        ];
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     */
    #[DataProvider('sizeCases')]
    public function test_size_semantics(array $data, array $rules, bool $passes): void
    {
        $this->assertSame($passes, Validator::make($data, $rules)->passes());
    }

    public function test_length_message_for_numeric_string_without_numeric_rule(): void
    {
        $v = Validator::make(['code' => '123456'], ['code' => 'max:5']);

        $this->assertSame(['code' => ['The code field must not exceed 5 characters.']], $v->errors());
    }

    // ── #11: file rules ──────────────────────────────────────────────────────

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function upload(string $name, string $contents, int $error = UPLOAD_ERR_OK): array
    {
        $path = tempnam(sys_get_temp_dir(), 'luany-upload-');
        $this->assertIsString($path);
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;

        return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => $error, 'size' => strlen($contents)];
    }

    private function png(): string
    {
        // 1×1 transparent PNG
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    }

    public function test_file_rule_accepts_an_upload(): void
    {
        $v = Validator::make(['doc' => $this->upload('a.txt', 'hello')], ['doc' => 'required|file']);
        $this->assertTrue($v->passes(), json_encode($v->errors()) ?: '');
    }

    public function test_file_rule_rejects_non_files(): void
    {
        $v = Validator::make(['doc' => 'not a file'], ['doc' => 'file']);
        $this->assertSame(['doc' => ['The doc field must be a file.']], $v->errors());
    }

    public function test_file_rule_rejects_failed_upload(): void
    {
        $v = Validator::make(['doc' => $this->upload('a.txt', 'x', UPLOAD_ERR_PARTIAL)], ['doc' => 'file']);
        $this->assertSame(['doc' => ['The doc failed to upload.']], $v->errors());
    }

    public function test_spoofed_upload_is_rejected_by_default(): void
    {
        Validator::setUploadedFileVerifier(null); // default: is_uploaded_file()

        $v = Validator::make(['doc' => $this->upload('a.txt', 'x')], ['doc' => 'file']);
        $this->assertTrue($v->fails());
    }

    public function test_no_file_selected_counts_as_empty(): void
    {
        $empty = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];

        $this->assertTrue(Validator::make(['doc' => $empty], ['doc' => 'nullable|file'])->passes());
        $this->assertSame(
            ['doc' => ['The doc field is required.']],
            Validator::make(['doc' => $empty], ['doc' => 'required|file'])->errors()
        );
    }

    public function test_image_rule_uses_file_contents(): void
    {
        $this->assertTrue(Validator::make(['img' => $this->upload('a.png', $this->png())], ['img' => 'image'])->passes());

        $fake = Validator::make(['img' => $this->upload('evil.png', '<?php echo 1;')], ['img' => 'image']);
        $this->assertSame(['img' => ['The img field must be an image.']], $fake->errors());
    }

    public function test_mimes_rule(): void
    {
        $png = $this->upload('photo.png', $this->png());

        $this->assertTrue(Validator::make(['f' => $png], ['f' => 'mimes:jpg,png'])->passes());
        $this->assertSame(
            ['f' => ['The f field must be a file of type: pdf.']],
            Validator::make(['f' => $png], ['f' => 'mimes:pdf'])->errors()
        );
    }

    public function test_mimes_rule_checks_contents_not_name(): void
    {
        if (!class_exists(\finfo::class)) {
            $this->markTestSkipped('fileinfo extension not available.');
        }

        $v = Validator::make(['f' => $this->upload('shell.png', '<?php echo 1;')], ['f' => 'mimes:png']);
        $this->assertTrue($v->fails());
    }

    public function test_max_for_files_is_in_kilobytes(): void
    {
        $file = $this->upload('big.txt', str_repeat('x', 3 * 1024));

        $this->assertTrue(Validator::make(['f' => $file], ['f' => 'file|max:3'])->passes());
        $this->assertSame(
            ['f' => ['The f field must not be greater than 2 kilobytes.']],
            Validator::make(['f' => $file], ['f' => 'file|max:2'])->errors()
        );
        $this->assertTrue(Validator::make(['f' => $file], ['f' => 'file|min:4'])->fails());
        $this->assertTrue(Validator::make(['f' => $file], ['f' => 'file|between:1,4'])->passes());
    }
}
