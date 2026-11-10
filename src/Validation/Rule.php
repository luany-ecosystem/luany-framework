<?php

namespace Luany\Framework\Validation;

/**
 * Rule
 *
 * Contract for custom validation rule objects.
 *
 * Usage:
 *   class Uppercase implements Rule
 *   {
 *       public function passes(string $field, mixed $value, array $data): bool
 *       {
 *           return is_string($value) && strtoupper($value) === $value;
 *       }
 *
 *       public function message(string $field): string
 *       {
 *           return "The {$field} field must be uppercase.";
 *       }
 *   }
 *
 *   Validator::make($data, ['code' => ['required', new Uppercase()]]);
 */
interface Rule
{
    /**
     * @param array<string, mixed> $data All input data
     */
    public function passes(string $field, mixed $value, array $data): bool;

    public function message(string $field): string;
}
