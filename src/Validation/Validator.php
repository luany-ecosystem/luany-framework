<?php

namespace Luany\Framework\Validation;

/**
 * Validator
 *
 * Zero-dependency validation engine.
 * Validates data arrays against declarative rule sets.
 *
 * Supported rules:
 *   required          — field must be present and non-empty
 *   string            — field must be a string
 *   email             — field must be a valid email
 *   numeric           — field must be numeric
 *   min:{n}           — minimum length (string) or value (numeric)
 *   max:{n}           — maximum length (string) or value (numeric)
 *   in:{a},{b},{c}    — field must be one of the listed values
 *   confirmed         — field must have a matching {field}_confirmation
 *   unique:{table},{column} — (callback-based) field must be unique
 *
 * Usage:
 *   $v = Validator::make($request->body(), [
 *       'name'     => 'required|string|min:2|max:255',
 *       'email'    => 'required|email|unique:users,email',
 *       'password' => 'required|string|min:8|confirmed',
 *       'role'     => 'required|in:admin,editor,viewer',
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

    /**
     * @param array<string, mixed>  $data  Input data to validate
     * @param array<string, string> $rules Rule definitions per field
     */
    public function __construct(
        private array $data,
        private array $rules,
    ) {
    }

    /**
     * Static factory — idiomatic entry point.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     */
    public static function make(array $data, array $rules): static
    {
        $instance = new static($data, $rules);
        $instance->run();

        return $instance;
    }

    /**
     * Register a callback for the 'unique' rule.
     *
     * @param callable $checker fn(string $table, string $column, mixed $value): bool
     */
    public static function setUniqueChecker(callable $checker): void
    {
        static::$uniqueChecker = $checker;
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

        foreach ($this->rules as $field => $ruleString) {
            $rules = $this->parseRules($ruleString);
            $value = $this->data[$field] ?? null;
            $fieldErrors = [];

            foreach ($rules as $rule) {
                $error = $this->validateRule($field, $value, $rule);
                if ($error !== null) {
                    $fieldErrors[] = $error;
                }
            }

            if (empty($fieldErrors)) {
                if (array_key_exists($field, $this->data)) {
                    $this->validatedData[$field] = $value;
                }
            } else {
                $this->errors[$field] = $fieldErrors;
            }
        }
    }

    /**
     * Parse a pipe-separated rule string into individual rules.
     *
     * @return array<array{name: string, params: array<string>}>
     */
    private function parseRules(string|array $ruleString): array
    {
        if (is_array($ruleString)) {
            // Array format: ['required', 'string', 'max:255']
            $rules = [];
            foreach ($ruleString as $rule) {
                $rule = trim((string) $rule);
                if ($rule === '') continue;
                if (str_contains($rule, ':')) {
                    [$name, $paramString] = explode(':', $rule, 2);
                    $params = explode(',', $paramString);
                } else {
                    $name = $rule;
                    $params = [];
                }
                $rules[] = ['name' => $name, 'params' => $params];
            }
            return $rules;
        }

        // Existing pipe-separated string logic
        $rules = [];
        foreach (explode('|', $ruleString) as $rule) {
            $rule = trim($rule);
            if ($rule === '') continue;
            if (str_contains($rule, ':')) {
                [$name, $paramString] = explode(':', $rule, 2);
                $params = explode(',', $paramString);
            } else {
                $name = $rule;
                $params = [];
            }
            $rules[] = ['name' => $name, 'params' => $params];
        }
        return $rules;
    }
    /**
     * Validate a single rule against a field value.
     *
     * @return string|null Error message or null if valid
     */
    /** @param array{name: string, params: array<string>} $rule */
    private function validateRule(string $field, mixed $value, array $rule): ?string
    {
        $name = $rule['name'];
        $params = $rule['params'];

        return match ($name) {
            'required'  => $this->validateRequired($field, $value),
            'string'    => $this->validateString($field, $value),
            'email'     => $this->validateEmail($field, $value),
            'numeric'   => $this->validateNumeric($field, $value),
            'min'       => $this->validateMin($field, $value, $params),
            'max'       => $this->validateMax($field, $value, $params),
            'in'        => $this->validateIn($field, $value, $params),
            'confirmed' => $this->validateConfirmed($field, $value),
            'unique'    => $this->validateUnique($field, $value, $params),
            default     => null, // Unknown rules are silently ignored
        };
    }

    private function validateRequired(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return "The {$field} field is required.";
        }

        return null;
    }

    private function validateString(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null; // Not present — let 'required' handle it
        }

        if (!is_string($value)) {
            return "The {$field} field must be a string.";
        }

        return null;
    }

    private function validateEmail(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return "The {$field} field must be a valid email address.";
        }

        return null;
    }

    private function validateNumeric(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return "The {$field} field must be numeric.";
        }

        return null;
    }

    /** @param array<string> $params */
    private function validateMin(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $min = (float) ($params[0] ?? 0);

        if (is_numeric($value)) {
            if ((float) $value < $min) {
                return "The {$field} field must be at least {$min}.";
            }
        } elseif (is_string($value)) {
            if (mb_strlen($value) < (int) $min) {
                return "The {$field} field must be at least " . (int) $min . " characters.";
            }
        }

        return null;
    }

    /** @param array<string> $params */
    private function validateMax(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $max = (float) ($params[0] ?? 0);

        if (is_numeric($value)) {
            if ((float) $value > $max) {
                return "The {$field} field must not exceed {$max}.";
            }
        } elseif (is_string($value)) {
            if (mb_strlen($value) > (int) $max) {
                return "The {$field} field must not exceed " . (int) $max . " characters.";
            }
        }

        return null;
    }

    /** @param array<string> $params */
    private function validateIn(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!in_array((string) $value, $params, true)) {
            $allowed = implode(', ', $params);
            return "The {$field} field must be one of: {$allowed}.";
        }

        return null;
    }

    private function validateConfirmed(string $field, mixed $value): ?string
    {
        $confirmation = $this->data["{$field}_confirmation"] ?? null;

        if ($value !== $confirmation) {
            return "The {$field} confirmation does not match.";
        }

        return null;
    }

    /** @param array<string> $params */
    private function validateUnique(string $field, mixed $value, array $params): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (static::$uniqueChecker === null) {
            // No checker registered — cannot validate, skip silently
            return null;
        }

        $table = $params[0] ?? '';
        $column = $params[1] ?? $field;

        if ($table === '') {
            return null;
        }

        $exists = (static::$uniqueChecker)($table, $column, $value);

        if ($exists) {
            return "The {$field} has already been taken.";
        }

        return null;
    }
}