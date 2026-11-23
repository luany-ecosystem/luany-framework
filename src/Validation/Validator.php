<?php

namespace Luany\Framework\Validation;

/**
 * Validator
 *
 * Zero-dependency validation engine.
 * Validates data arrays against declarative rule sets.
 *
 * Supported rules:
 *   required               — field must be present and non-empty
 *   sometimes              — only validate the field when it is present in the input
 *   nullable               — null or '' is accepted (other rules are skipped; '' becomes null)
 *   string                 — field must be a string
 *   email                  — field must be a valid email
 *   integer                — integer or integer string ("42", "-3")
 *   numeric                — field must be numeric
 *   boolean                — true/false/1/0/"1"/"0"/"true"/"false"/"on"/"off"/"yes"/"no";
 *                            the validated value is cast to bool, and an ABSENT field
 *                            becomes false (unchecked checkbox) unless 'sometimes' is used
 *   array                  — field must be an array
 *   date                   — parseable date (strtotime)
 *   after:{date|field}     — date after the given date or other field's value
 *   before:{date|field}    — date before the given date or other field's value
 *   after_or_equal / before_or_equal
 *   min:{n} / max:{n}      — string length, array count, file size in kilobytes, or the
 *                            value of an int / float — and of a numeric string only when
 *                            the field also has the numeric or integer rule ("300" with
 *                            'string|max:255' is 3 characters long, so it passes)
 *   between:{min},{max}    — same semantics as min/max, inclusive
 *   file                   — a successful upload ($_FILES-style array: name, type,
 *                            tmp_name, error, size). "No file selected"
 *                            (UPLOAD_ERR_NO_FILE) counts as empty
 *   image                  — uploaded jpeg, png, gif, bmp or webp (SVG excluded)
 *   mimes:{ext},{ext}      — uploaded file whose detected type matches an extension
 *   digits:{n}             — exactly n digits
 *   in:{a},{b},{c}         — field must be one of the listed values
 *   not_in:{a},{b}         — field must not be one of the listed values
 *   regex:{pattern}        — must match (use array syntax when the pattern contains '|')
 *   not_regex:{pattern}    — must not match
 *   url                    — absolute http(s) URL
 *   confirmed              — field must have a matching {field}_confirmation
 *   same:{field}           — must equal another field
 *   different:{field}      — must differ from another field
 *   unique:{table},{column},{ignore},{ignoreColumn}
 *                          — no row in {table} has {column} = value (column defaults
 *                            to the field name). {ignore} skips the current record when
 *                            updating: unique:users,email,42,id
 *   exists:{table},{column} — a row in {table} has {column} = value; for arrays every
 *                            element must exist
 *
 * unique / exists need a PresenceVerifierInterface. luany/framework's Kernel
 * registers one automatically from the 'db' (PDO) container binding; use
 * Validator::setPresenceVerifier() otherwise. Without a verifier these rules
 * throw a LogicException — they never pass silently.
 *
 * File rules detect the type from the file contents with finfo when the
 * fileinfo extension is loaded, otherwise from the client file name. The
 * tmp_name must pass is_uploaded_file(), so a form field shaped like an
 * upload cannot point the validator at arbitrary server files; override with
 * Validator::setUploadedFileVerifier() for PSR-7 servers or tests.
 *
 * Portuguese names are accepted for every rule (PORTUGUESE_RULES):
 * 'obrigatorio|texto|max:255', 'inteiro|entre:18,99', 'opcional|data|depois:hoje'…
 *
 * Unknown rule names throw \InvalidArgumentException (a typo must never
 * silently disable validation). Register extra named rules with extend().
 *
 * Except for required, confirmed and boolean, rules skip null/'' values —
 * combine with 'required' to make a field mandatory.
 *
 * Custom rules (array syntax only):
 *   - Rule objects:  new MyRule()
 *   - Closures:      fn (string $field, mixed $value, array $data) => bool|string|null
 *                    (true/null = pass, false = generic message, string = error message)
 *   Custom rules are skipped for null/'' values, like built-in rules.
 *
 * Usage:
 *   $v = Validator::make($request->body(), [
 *       'name'     => 'required|string|min:2|max:255',
 *       'email'    => 'required|email|unique:users,email',
 *       'password' => 'required|string|min:8|confirmed',
 *       'role'     => 'required|in:admin,editor,viewer',
 *       'active'   => 'boolean',
 *       'code'     => ['required', 'regex:/^[A-Z]{3}|[0-9]{3}$/'],
 *   ]);
 *
 *   if ($v->fails()) {
 *       $errors = $v->errors();
 *   }
 *
 *   $validated = $v->validated(); // only validated fields
 */
class Validator
{
    /** @var array<string, array<string>> Collected error messages per field */
    private array $errors = [];

    /** @var array<string, mixed> Data that passed validation */
    private array $validatedData = [];

    private bool $ran = false;

    /**
     * Callback for 'unique' rule. Set via setUniqueChecker().
     * Signature: fn(string $table, string $column, mixed $value): bool
     * Returns true if the value already exists (i.e. NOT unique).
     *
     * @var callable|null
     */
    protected static $uniqueChecker = null;

    /** Presence verifier for the unique / exists rules. */
    protected static ?PresenceVerifierInterface $presenceVerifier = null;

    /**
     * Named custom rules registered via extend().
     *
     * @var array<string, array{callback: callable, message: ?string}>
     */
    protected static array $extensions = [];

    /** @var (\Closure(string, array<string, string>): ?string)|null Translates validation.{key}; null = untranslated. */
    private static ?\Closure $translator = null;

    /**
     * Translate the messages: the callback receives 'validation.required'
     * (and the replacements) and returns the text, or null to keep English.
     * The Kernel connects it to the app's Translator (lang/{locale}.php).
     *
     * @param (callable(string, array<string, string>): ?string)|null $translator
     */
    public static function setTranslator(?callable $translator): void
    {
        self::$translator = $translator !== null ? \Closure::fromCallable($translator) : null;
    }

    /**
     * A message: validation.{key} from the translator, else the English text.
     *
     * @param array<string, scalar> $replace
     */
    private function msg(string $key, string $field, array $replace, string $english): string
    {
        $replace = ['field' => $field] + array_map('strval', $replace);

        $text = self::$translator !== null ? (self::$translator)('validation.' . $key, $replace) : null;
        if ($text === null) {
            $text = $english;
            uksort($replace, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            foreach ($replace as $name => $value) {
                $text = str_replace(':' . $name, $value, $text);
            }
        }

        return $text;
    }

    /** Built-in rule names. */
    private const RULES = [
        'required', 'sometimes', 'nullable', 'string', 'email', 'integer', 'numeric',
        'boolean', 'array', 'date', 'after', 'before', 'after_or_equal', 'before_or_equal',
        'min', 'max', 'between', 'digits', 'in', 'not_in', 'regex', 'not_regex', 'url',
        'confirmed', 'same', 'different', 'unique', 'exists', 'file', 'image', 'mimes',
    ];

    /**
     * Portuguese rule names ("Luany em Português"), always accepted: rules
     * are data, not API, so 'obrigatorio|texto|max:255' works without luany/pt.
     * A custom rule registered with extend() under the same name wins.
     */
    public const PORTUGUESE_RULES = [
        'obrigatorio'     => 'required',
        'opcional'        => 'nullable',
        'quando_presente' => 'sometimes',
        'texto'           => 'string',
        'inteiro'         => 'integer',
        'numerico'        => 'numeric',
        'booleano'        => 'boolean',
        'lista'           => 'array',
        'data'            => 'date',
        'depois'          => 'after',
        'antes'           => 'before',
        'depois_ou_igual' => 'after_or_equal',
        'antes_ou_igual'  => 'before_or_equal',
        'entre'           => 'between',
        'digitos'         => 'digits',
        'em'              => 'in',
        'nao_em'          => 'not_in',
        'nao_regex'       => 'not_regex',
        'confirmado'      => 'confirmed',
        'igual'           => 'same',
        'diferente'       => 'different',
        'unico'           => 'unique',
        'existe'          => 'exists',
        'ficheiro'        => 'file',
        'arquivo'         => 'file',
        'imagem'          => 'image',
        'tipos'           => 'mimes',
    ];

    /** Rules that make min / max / between compare a numeric string by value. */
    private const NUMERIC_RULES = ['numeric', 'integer'];

    /** MIME types accepted by the image rule. */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/x-ms-bmp', 'image/webp'];

    /** Extension → MIME types, for the mimes rule (and image without fileinfo). */
    private const MIME_TYPES = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
        'webp' => ['image/webp'],
        'svg'  => ['image/svg+xml'],
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'json' => ['application/json', 'text/plain'],
        'xml'  => ['application/xml', 'text/xml'],
        'zip'  => ['application/zip'],
        'mp3'  => ['audio/mpeg'],
        'mp4'  => ['video/mp4'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ];

    /**
     * Decides whether an upload's tmp_name is a genuine upload
     * (default: is_uploaded_file()).
     *
     * @var (callable(string): bool)|null
     */
    protected static $uploadedFileVerifier = null;

    /** @var array<int, string> Rule names of the field being validated. */
    private array $fieldRules = [];

    /** Rules whose parameter is taken verbatim (not split on commas). */
    private const RAW_PARAM_RULES = ['regex', 'not_regex'];

    private const BOOL_TRUE  = [true, 1, '1', 'true', 'on', 'yes'];
    private const BOOL_FALSE = [false, 0, '0', 'false', 'off', 'no'];

    /**
     * @param array<string, mixed> $data  Input data to validate
     * @param array<string, mixed> $rules Rule definitions per field: a pipe string,
     *                                    or an array of rule strings / Rule objects / closures
     */
    public function __construct(
        private array $data,
        private array $rules,
    ) {
    }

    /**
     * Static factory — idiomatic entry point.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     */
    public static function make(array $data, array $rules): static
    {
        $instance = new static($data, $rules);
        $instance->run();

        return $instance;
    }

    /**
     * Set the presence verifier used by the unique / exists rules
     * (null removes it).
     */
    public static function setPresenceVerifier(?PresenceVerifierInterface $verifier): void
    {
        static::$presenceVerifier = $verifier;
    }

    public static function getPresenceVerifier(): ?PresenceVerifierInterface
    {
        return static::$presenceVerifier;
    }

    /**
     * Replace the is_uploaded_file() check used by the file / image / mimes
     * rules — e.g. for PSR-7 servers whose uploads are not PHP uploads, or for
     * tests. null restores the default.
     *
     * @param (callable(string): bool)|null $verifier Receives the tmp_name path.
     */
    public static function setUploadedFileVerifier(?callable $verifier): void
    {
        static::$uploadedFileVerifier = $verifier;
    }

    /**
     * Register a callback for the 'unique' rule.
     *
     * @deprecated Use setPresenceVerifier(); a registered verifier takes precedence.
     * @param callable $checker fn(string $table, string $column, mixed $value): bool
     */
    public static function setUniqueChecker(callable $checker): void
    {
        static::$uniqueChecker = $checker;
    }

    /**
     * Register a named custom rule usable in rule strings.
     *
     *   Validator::extend('slug', fn ($field, $value, $params, $data) => (bool) preg_match('/^[a-z0-9-]+$/', $value));
     *   Validator::make($data, ['handle' => 'required|slug']);
     *
     * The callback receives (string $field, mixed $value, array $params, array $data)
     * and returns bool|string|null (string = custom error message).
     *
     * @param callable(string, mixed, array<int, string>, array<string, mixed>): (bool|string|null) $callback
     */
    public static function extend(string $name, callable $callback, ?string $message = null): void
    {
        static::$extensions[$name] = ['callback' => $callback, 'message' => $message];
    }

    /**
     * Remove all rules registered via extend() (useful in tests).
     */
    public static function flushExtensions(): void
    {
        static::$extensions = [];
    }

    /**
     * Determine if validation failed.
     */
    public function fails(): bool
    {
        if (!$this->ran) {
            $this->run();
        }

        return !empty($this->errors);
    }

    /**
     * Determine if validation passed.
     */
    public function passes(): bool
    {
        return !$this->fails();
    }

    /**
     * Get all error messages grouped by field.
     *
     * @return array<string, array<string>>
     */
    public function errors(): array
    {
        if (!$this->ran) {
            $this->run();
        }

        return $this->errors;
    }

    /**
     * Get only the data that passed validation.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        if (!$this->ran) {
            $this->run();
        }

        return $this->validatedData;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function run(): void
    {
        $this->errors = [];
        $this->validatedData = [];
        $this->ran = true;

        foreach ($this->rules as $field => $definition) {
            $field   = (string) $field;
            $rules   = $this->parseRules($definition, $field);
            $names   = array_column(array_filter($rules, 'is_array'), 'name');
            $present = array_key_exists($field, $this->data);
            $value   = $this->data[$field] ?? null;

            // A file input left empty is submitted as UPLOAD_ERR_NO_FILE — treat it as no value.
            if (self::isFileArray($value) && (int) $value['error'] === UPLOAD_ERR_NO_FILE) {
                $value = null;
            }

            $this->fieldRules = $names;

            // 'sometimes' — absent fields are not validated at all.
            if (!$present && in_array('sometimes', $names, true)) {
                continue;
            }

            // 'nullable' — empty values are accepted as null without running other rules.
            if (in_array('nullable', $names, true) && ($value === null || $value === '')) {
                if ($present) {
                    $this->validatedData[$field] = null;
                }
                continue;
            }

            $fieldErrors = [];

            foreach ($rules as $rule) {
                $error = is_array($rule)
                    ? $this->validateRule($field, $value, $rule)
                    : $this->validateCustom($field, $value, $rule);

                if ($error !== null) {
                    $fieldErrors[] = $error;
                }
            }

            if (!empty($fieldErrors)) {
                $this->errors[$field] = $fieldErrors;
                continue;
            }

            if (in_array('boolean', $names, true)) {
                // Absent or empty boolean field (e.g. unchecked checkbox) → false.
                $this->validatedData[$field] = $this->toBool($value);
            } elseif ($present) {
                $this->validatedData[$field] = $value;
            }
        }
    }

    /**
     * Parse a rule definition into individual rules.
     *
     * @param  mixed $definition Pipe string or array of strings / Rule objects / closures
     * @return array<int, array{name: string, params: array<int, string>}|Rule|\Closure>
     * @throws \InvalidArgumentException on unknown rule names or invalid entries
     */
    private function parseRules(mixed $definition, string $field): array
    {
        if (is_string($definition)) {
            $definition = explode('|', $definition);
        } elseif ($definition instanceof Rule || $definition instanceof \Closure) {
            $definition = [$definition];
        }

        if (!is_array($definition)) {
            throw new \InvalidArgumentException("Invalid rule definition for field [{$field}].");
        }

        $rules = [];
        foreach ($definition as $rule) {
            if ($rule instanceof Rule || $rule instanceof \Closure) {
                $rules[] = $rule;
                continue;
            }

            if (!is_string($rule)) {
                throw new \InvalidArgumentException(
                    "Invalid rule for field [{$field}]: expected a string, Rule object or Closure, got " . get_debug_type($rule) . '.'
                );
            }

            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }

            [$name, $paramString] = str_contains($rule, ':') ? explode(':', $rule, 2) : [$rule, null];
            if (!isset(static::$extensions[$name])) {
                $name = self::PORTUGUESE_RULES[$name] ?? $name;
            }

            if ($paramString !== null) {
                $params = in_array($name, self::RAW_PARAM_RULES, true) ? [$paramString] : explode(',', $paramString);
            } else {
                $params = [];
            }

            if (!in_array($name, self::RULES, true) && !isset(static::$extensions[$name])) {
                throw new \InvalidArgumentException("Unknown validation rule [{$name}] for field [{$field}].");
            }

            $rules[] = ['name' => $name, 'params' => $params];
        }

        return $rules;
    }

    /**
     * Validate a single rule against a field value.
     *
     * @param  array{name: string, params: array<int, string>} $rule
     * @return string|null Error message or null if valid
     */
    private function validateRule(string $field, mixed $value, array $rule): ?string
    {
        $name = $rule['name'];
        $params = $rule['params'];

        if (isset(static::$extensions[$name]) && !in_array($name, self::RULES, true)) {
            return $this->validateExtension($field, $value, $name, $params);
        }

        return match ($name) {
            'required'        => $this->validateRequired($field, $value),
            'sometimes',
            'nullable'        => null, // flags, handled in run()
            'string'          => $this->validateString($field, $value),
            'email'           => $this->validateEmail($field, $value),
            'integer'         => $this->validateInteger($field, $value),
            'numeric'         => $this->validateNumeric($field, $value),
            'boolean'         => $this->validateBoolean($field, $value),
            'array'           => $this->validateArray($field, $value),
            'date'            => $this->validateDate($field, $value),
            'after'           => $this->validateDateComparison($field, $value, $params, 'after'),
            'before'          => $this->validateDateComparison($field, $value, $params, 'before'),
            'after_or_equal'  => $this->validateDateComparison($field, $value, $params, 'after_or_equal'),
            'before_or_equal' => $this->validateDateComparison($field, $value, $params, 'before_or_equal'),
            'min'             => $this->validateMin($field, $value, $params),
            'max'             => $this->validateMax($field, $value, $params),
            'between'         => $this->validateBetween($field, $value, $params),
            'digits'          => $this->validateDigits($field, $value, $params),
            'in'              => $this->validateIn($field, $value, $params),
            'not_in'          => $this->validateNotIn($field, $value, $params),
            'regex'           => $this->validateRegex($field, $value, $params, true),
            'not_regex'       => $this->validateRegex($field, $value, $params, false),
            'url'             => $this->validateUrl($field, $value),
            'confirmed'       => $this->validateConfirmed($field, $value),
            'same'            => $this->validateSame($field, $value, $params, true),
            'different'       => $this->validateSame($field, $value, $params, false),
            'unique'          => $this->validateUnique($field, $value, $params),
            'exists'          => $this->validateExists($field, $value, $params),
            'file'            => $this->validateFile($field, $value),
            'image'           => $this->validateImage($field, $value),
            'mimes'           => $this->validateMimes($field, $value, $params),
            default           => throw new \InvalidArgumentException("Unknown validation rule [{$name}] for field [{$field}]."),
        };
    }

    private function validateCustom(string $field, mixed $value, Rule|\Closure $rule): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        if ($rule instanceof Rule) {
            return $rule->passes($field, $value, $this->data) ? null : $rule->message($field);
        }

        return $this->customResult($field, $rule($field, $value, $this->data), null);
    }

    /** @param array<int, string> $params */
    private function validateExtension(string $field, mixed $value, string $name, array $params): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $extension = static::$extensions[$name];

        return $this->customResult(
            $field,
            ($extension['callback'])($field, $value, $params, $this->data),
            $extension['message']
        );
    }

    private function customResult(string $field, mixed $result, ?string $message): ?string
    {
        if ($result === true || $result === null) {
            return null;
        }

        if (is_string($result)) {
            return $result;
        }

        return $message !== null
            ? str_replace(':field', $field, $message)
            : $this->msg('invalid', $field, [], 'The :field field is invalid.');
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            $value = strtolower($value);
        }

        return in_array($value, self::BOOL_TRUE, true);
    }

    /**
     * How min / max / between measure a value: 'file' (kilobytes), 'array'
     * (count), 'numeric' (value: ints, floats, and numeric strings when the
     * field has the numeric or integer rule), 'string' (length); null otherwise.
     */
    private function sizeKind(mixed $value): ?string
    {
        return match (true) {
            self::isFileArray($value)        => 'file',
            is_array($value)                 => 'array',
            is_int($value), is_float($value) => 'numeric',
            is_numeric($value) && array_intersect(self::NUMERIC_RULES, $this->fieldRules) !== [] => 'numeric',
            is_string($value)                => 'string',
            default                          => null,
        };
    }

    private function sizeOf(mixed $value): ?float
    {
        return match ($this->sizeKind($value)) {
            'file'    => (float) $value['size'] / 1024,
            'array'   => (float) count($value),
            'numeric' => (float) $value,
            'string'  => (float) mb_strlen($value),
            default   => null,
        };
    }

    private function sizeUnit(mixed $value): string
    {
        return match ($this->sizeKind($value)) {
            'file'    => ' kilobytes',
            'array'   => ' items',
            'string'  => ' characters',
            default   => '',
        };
    }

    /** @param array<int, string> $params */
    private function requireParams(string $rule, array $params, int $count): void
    {
        if (count(array_filter($params, fn ($p) => $p !== '')) < $count) {
            throw new \InvalidArgumentException("Validation rule [{$rule}] requires at least {$count} parameter(s).");
        }
    }

    private function validateRequired(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return $this->msg('required', $field, [], 'The :field field is required.');
        }

        return null;
    }

    private function validateString(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null; // Not present — let 'required' handle it
        }

        if (!is_string($value)) {
            return $this->msg('string', $field, [], 'The :field field must be a string.');
        }

        return null;
    }

    private function validateEmail(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return $this->msg('email', $field, [], 'The :field field must be a valid email address.');
        }

        return null;
    }

    private function validateNumeric(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return $this->msg('numeric', $field, [], 'The :field field must be numeric.');
        }

        return null;
    }

    /** @param array<int, string> $params */
    private function validateMin(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $min  = (float) ($params[0] ?? 0);
        $size = $this->sizeOf($value);

        return match ($this->sizeKind($value)) {
            'numeric' => $size < $min ? $this->msg('min.numeric', $field, ['min' => $min], 'The :field field must be at least :min.') : null,
            'file'    => $size < $min ? $this->msg('min.file', $field, ['min' => $min], 'The :field field must be at least :min kilobytes.') : null,
            'string'  => $size < (int) $min ? $this->msg('min.string', $field, ['min' => (int) $min], 'The :field field must be at least :min characters.') : null,
            'array'   => $size < (int) $min ? $this->msg('min.array', $field, ['min' => (int) $min], 'The :field field must have at least :min items.') : null,
            default   => null,
        };
    }

    /** @param array<int, string> $params */
    private function validateMax(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $max  = (float) ($params[0] ?? 0);
        $size = $this->sizeOf($value);

        return match ($this->sizeKind($value)) {
            'numeric' => $size > $max ? $this->msg('max.numeric', $field, ['max' => $max], 'The :field field must not exceed :max.') : null,
            'file'    => $size > $max ? $this->msg('max.file', $field, ['max' => $max], 'The :field field must not be greater than :max kilobytes.') : null,
            'string'  => $size > (int) $max ? $this->msg('max.string', $field, ['max' => (int) $max], 'The :field field must not exceed :max characters.') : null,
            'array'   => $size > (int) $max ? $this->msg('max.array', $field, ['max' => (int) $max], 'The :field field must not have more than :max items.') : null,
            default   => null,
        };
    }

    /** @param array<int, string> $params */
    private function validateBetween(string $field, mixed $value, array $params): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $this->requireParams('between', $params, 2);
        $min  = (float) $params[0];
        $max  = (float) $params[1];
        $size = $this->sizeOf($value);

        if ($size === null || $size < $min || $size > $max) {
            $kind = $this->sizeKind($value) ?? 'numeric';
            return $this->msg("between.{$kind}", $field, ['min' => $params[0], 'max' => $params[1]], 'The :field field must be between :min and :max' . $this->sizeUnit($value) . '.');
        }

        return null;
    }

    /** @param array<int, string> $params */
    private function validateDigits(string $field, mixed $value, array $params): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $this->requireParams('digits', $params, 1);
        $length = (int) $params[0];
        $string = is_int($value) ? (string) $value : $value;

        if (!is_string($string) || !ctype_digit($string) || strlen($string) !== $length) {
            return $this->msg('digits', $field, ['digits' => $length], 'The :field field must be :digits digits.');
        }

        return null;
    }

    private function validateInteger(string $field, mixed $value): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        if (is_int($value)) {
            return null;
        }

        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false || trim($value) !== $value) {
            return $this->msg('integer', $field, [], 'The :field field must be an integer.');
        }

        return null;
    }

    private function validateBoolean(string $field, mixed $value): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $normalized = is_string($value) ? strtolower($value) : $value;

        if (!in_array($normalized, self::BOOL_TRUE, true) && !in_array($normalized, self::BOOL_FALSE, true)) {
            return $this->msg('boolean', $field, [], 'The :field field must be true or false.');
        }

        return null;
    }

    private function validateArray(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_array($value) ? null : $this->msg('array', $field, [], 'The :field field must be an array.');
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    private function validateDate(string $field, mixed $value): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        return $this->timestamp($value) === null ? $this->msg('date', $field, [], 'The :field field must be a valid date.') : null;
    }

    /** @param array<int, string> $params */
    private function validateDateComparison(string $field, mixed $value, array $params, string $operator): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $this->requireParams($operator, $params, 1);
        $reference = $params[0];

        // Parameter may name another input field.
        $other = array_key_exists($reference, $this->data) ? $this->data[$reference] : $reference;

        $time      = $this->timestamp($value);
        $threshold = $this->timestamp($other);

        $label = str_replace('_', ' ', $operator);

        $error = $this->msg($operator, $field, ['date' => $reference], 'The :field field must be a date ' . $label . ' :date.');

        if ($time === null || $threshold === null) {
            return $error;
        }

        $ok = match ($operator) {
            'after'           => $time > $threshold,
            'after_or_equal'  => $time >= $threshold,
            'before'          => $time < $threshold,
            default           => $time <= $threshold,
        };

        return $ok ? null : $error;
    }

    /** @param array<int, string> $params */
    private function validateIn(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_scalar($value) || !in_array((string) $value, $params, true)) {
            $allowed = implode(', ', $params);
            return $this->msg('in', $field, ['values' => $allowed], 'The :field field must be one of: :values.');
        }

        return null;
    }

    /** @param array<int, string> $params */
    private function validateNotIn(string $field, mixed $value, array $params): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        if (!is_scalar($value) || in_array((string) $value, $params, true)) {
            return $this->msg('selected', $field, [], 'The selected :field is invalid.');
        }

        return null;
    }

    /** @param array<int, string> $params */
    private function validateRegex(string $field, mixed $value, array $params, bool $mustMatch): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $this->requireParams($mustMatch ? 'regex' : 'not_regex', $params, 1);

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return $this->msg('regex', $field, [], 'The :field field format is invalid.');
        }

        $result = @preg_match($params[0], (string) $value);

        if ($result === false) {
            throw new \InvalidArgumentException("Invalid regular expression [{$params[0]}] for field [{$field}].");
        }

        return ($result === 1) === $mustMatch ? null : $this->msg('regex', $field, [], 'The :field field format is invalid.');
    }

    private function validateUrl(string $field, mixed $value): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return $this->msg('url', $field, [], 'The :field field must be a valid URL.');
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? null : $this->msg('url', $field, [], 'The :field field must be a valid URL.');
    }

    /** @param array<int, string> $params */
    private function validateSame(string $field, mixed $value, array $params, bool $same): ?string
    {
        if ($value === null) {
            return null;
        }

        $this->requireParams($same ? 'same' : 'different', $params, 1);
        $other = $this->data[$params[0]] ?? null;

        if ($same && $value !== $other) {
            return $this->msg('same', $field, ['other' => $params[0]], 'The :field field must match :other.');
        }

        if (!$same && $value === $other) {
            return $this->msg('different', $field, ['other' => $params[0]], 'The :field field and :other must be different.');
        }

        return null;
    }

    private function validateConfirmed(string $field, mixed $value): ?string
    {
        $confirmation = $this->data["{$field}_confirmation"] ?? null;

        if ($value !== $confirmation) {
            return $this->msg('confirmed', $field, [], 'The :field confirmation does not match.');
        }

        return null;
    }

    /** @param array<int, string> $params */
    private function validateUnique(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $table  = $params[0] ?? '';
        $column = ($params[1] ?? '') !== '' ? $params[1] : $field;

        if ($table === '') {
            throw new \InvalidArgumentException("Rule [unique] on field [{$field}] needs a table: unique:table,column.");
        }

        $ignore       = $params[2] ?? null;
        $ignoreColumn = ($params[3] ?? '') !== '' ? $params[3] : 'id';
        if ($ignore === '' || $ignore === null || strtoupper($ignore) === 'NULL') {
            $ignore = null;
        }

        if (static::$presenceVerifier === null && static::$uniqueChecker !== null) {
            // Legacy callback (no ignore support).
            return (static::$uniqueChecker)($table, $column, $value)
                ? $this->msg('unique', $field, [], 'The :field has already been taken.')
                : null;
        }

        $count = $this->verifier('unique')->count($table, $column, [$value], $ignore === null ? null : $ignoreColumn, $ignore);

        return $count > 0 ? $this->msg('unique', $field, [], 'The :field has already been taken.') : null;
    }

    /** @param array<int, string> $params */
    private function validateExists(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $table  = $params[0] ?? '';
        $column = ($params[1] ?? '') !== '' ? $params[1] : $field;

        if ($table === '') {
            throw new \InvalidArgumentException("Rule [exists] on field [{$field}] needs a table: exists:table,column.");
        }

        $values = is_array($value) ? array_values(array_unique(array_map(
            fn ($v) => is_scalar($v) ? (string) $v : '',
            $value
        ))) : [$value];

        if (is_array($value) && in_array('', $values, true)) {
            return $this->msg('selected', $field, [], 'The selected :field is invalid.');
        }

        $count = $this->verifier('exists')->count($table, $column, $values);

        return $count >= count($values) ? null : $this->msg('selected', $field, [], 'The selected :field is invalid.');
    }

    private function validateFile(string $field, mixed $value): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        if (self::isFileArray($value) && (int) $value['error'] !== UPLOAD_ERR_OK) {
            return $this->msg('uploaded', $field, [], 'The :field failed to upload.');
        }

        return $this->isValidUpload($value) ? null : $this->msg('file', $field, [], 'The :field field must be a file.');
    }

    private function validateImage(string $field, mixed $value): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $ok = $this->isValidUpload($value) && $this->fileMatches(
            $value,
            self::IMAGE_MIMES,
            ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']
        );

        return $ok ? null : $this->msg('image', $field, [], 'The :field field must be an image.');
    }

    /** @param array<int, string> $params */
    private function validateMimes(string $field, mixed $value, array $params): ?string
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $this->requireParams('mimes', $params, 1);
        $extensions = array_values(array_filter(array_map(fn ($e) => strtolower(trim($e)), $params), fn ($e) => $e !== ''));

        $mimes = [];
        foreach ($extensions as $extension) {
            $mimes = array_merge($mimes, self::MIME_TYPES[$extension] ?? []);
        }

        $ok = $this->isValidUpload($value) && $this->fileMatches($value, $mimes, $extensions);

        return $ok ? null : $this->msg('mimes', $field, ['values' => implode(', ', $extensions)], 'The :field field must be a file of type: :values.');
    }

    /**
     * Whether $value has the shape of a $_FILES entry for a single file.
     *
     * @phpstan-assert-if-true array{name: mixed, type: mixed, tmp_name: mixed, error: mixed, size: mixed} $value
     */
    private static function isFileArray(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
            if (!array_key_exists($key, $value) || !is_scalar($value[$key])) {
                return false;
            }
        }

        return true;
    }

    /** A completed upload whose tmp_name passes the uploaded-file verifier. */
    private function isValidUpload(mixed $value): bool
    {
        if (!self::isFileArray($value) || (int) $value['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        $path = (string) $value['tmp_name'];
        if ($path === '') {
            return false;
        }

        $verifier = static::$uploadedFileVerifier ?? 'is_uploaded_file';

        return (bool) $verifier($path);
    }

    /**
     * Whether an upload's type is one of $mimes — detected from its contents
     * with finfo — or, without fileinfo, has one of $extensions. Extensions
     * with no known MIME type are matched against the client file name.
     *
     * @param array{name: mixed, type: mixed, tmp_name: mixed, error: mixed, size: mixed} $file
     * @param array<int, string> $mimes
     * @param array<int, string> $extensions
     */
    private function fileMatches(array $file, array $mimes, array $extensions): bool
    {
        $clientExtension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $unmapped        = array_diff($extensions, array_keys(self::MIME_TYPES));

        if (in_array($clientExtension, $unmapped, true)) {
            return true;
        }

        if (!class_exists(\finfo::class)) {
            return in_array($clientExtension, $extensions, true);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);

        return is_string($mime) && in_array(strtolower($mime), $mimes, true);
    }

    private function verifier(string $rule): PresenceVerifierInterface
    {
        if (static::$presenceVerifier === null) {
            throw new \LogicException(
                "The [{$rule}] validation rule needs a database: register one with "
                . 'Validator::setPresenceVerifier() (luany/framework does it automatically when a "db" PDO binding exists).'
            );
        }

        return static::$presenceVerifier;
    }
}