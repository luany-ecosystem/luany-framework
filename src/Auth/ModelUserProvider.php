<?php

namespace Luany\Framework\Auth;

/**
 * Users from a luany/database model (default App\Models\User):
 * find($id), firstWhere('email = ?', [...]), the `password` attribute.
 */
class ModelUserProvider implements UserProvider
{
    /**
     * @param class-string $model
     */
    public function __construct(
        private string $model,
        private string $passwordColumn = 'password',
    ) {
    }

    public function retrieveById(int|string $id): ?object
    {
        $user = $this->call('find', $id);

        return is_object($user) ? $user : null;
    }

    public function retrieveByCredentials(array $credentials): ?object
    {
        unset($credentials['password']);
        if ($credentials === []) {
            return null;
        }

        $conditions = [];
        $bindings   = [];
        foreach ($credentials as $column => $value) {
            if (!is_string($column) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
                throw new \InvalidArgumentException("Invalid credential column [{$column}].");
            }
            if (!is_scalar($value)) {
                return null; // email[]=… never matches
            }
            $conditions[] = "{$column} = ?";
            $bindings[]   = $value;
        }

        $user = $this->call('firstWhere', implode(' AND ', $conditions), $bindings);

        return is_object($user) ? $user : null;
    }

    public function passwordOf(object $user): string
    {
        $hash = method_exists($user, 'getAttribute') ? $user->getAttribute($this->passwordColumn) : ($user->{$this->passwordColumn} ?? '');

        return is_string($hash) ? $hash : '';
    }

    public function idOf(object $user): int|string
    {
        $id = method_exists($user, 'getKey') ? $user->getKey() : ($user->id ?? null);
        if (!is_int($id) && !is_string($id)) {
            throw new \LogicException('The user has no id.');
        }

        return $id;
    }

    public function updatePassword(object $user, string $hash): void
    {
        if (method_exists($user, 'forceFill') && method_exists($user, 'save')) {
            $user->forceFill([$this->passwordColumn => $hash]);
            $user->save();
        }
    }

    private function call(string $method, mixed ...$arguments): mixed
    {
        if (!class_exists($this->model) || !method_exists($this->model, $method)) {
            throw new \LogicException(
                "Auth user model [{$this->model}] not found or without {$method}(). Set 'model' in config/auth.php."
            );
        }

        return [$this->model, $method](...$arguments);
    }
}
