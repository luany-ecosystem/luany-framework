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
