<?php

namespace Luany\Framework\Exceptions;

/**
 * ValidationException
 *
 * Thrown by the validate() helper when validation fails.
 * The Kernel catches this, flashes errors to the session,
 * and returns a redirect response automatically.
 *
 * This means controllers never need to write the
 * flash/redirect boilerplate manually:
 *
 *   // Before (manual):
 *   $v = Validator::make($request->body(), $rules);
 *   if ($v->fails()) {
 *       session()->flash('errors', $v->errors());
 *       session()->flash('_old_input', $request->body());
 *       return redirect('/users/create');
 *   }
 *   $data = $v->validated();
 *
 *   // After (via helper):
 *   $data = validate($request->body(), $rules, '/users/create');
 *
 * The exception carries the validation errors and the redirect URL.
 * Kernel::handleException() resolves it without any controller code.
 */
class ValidationException extends \RuntimeException
{
    /**
     * Input keys never flashed as old input by validate().
     * A trailing '*' matches any key with that prefix (case-insensitive),
     * so 'password*' covers password, password_confirmation, password_new...
     *
     * Applications may extend this list:
     *   ValidationException::$dontFlash[] = 'credit_card';
     *
     * @var array<int, string>
     */
    public static array $dontFlash = ['password*', '_token', 'csrf_token', '_method'];

    /**
     * Remove sensitive keys (see $dontFlash) from input before flashing it.
     *
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function filterOldInput(array $input): array
    {
        return array_filter($input, static function ($key): bool {
            $key = strtolower((string) $key);
            foreach (static::$dontFlash as $pattern) {
                $pattern = strtolower($pattern);
                if (str_ends_with($pattern, '*')
                    ? str_starts_with($key, substr($pattern, 0, -1))
                    : $key === $pattern
                ) {
                    return false;
                }
            }
            return true;
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array<string, array<string>> $errors    Validation errors per field
     * @param string                       $redirectTo URL to redirect back to on failure
     */
    public function __construct(
        private array $errors,
        private string $redirectTo,
    ) {
        parent::__construct('The given data was invalid.', 422);
    }

    /**
     * Validation error messages grouped by field.
     *
     * @return array<string, array<string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * The URL to redirect to after flashing errors.
     */
    public function getRedirectTo(): string
    {
        return $this->redirectTo;
    }
}
