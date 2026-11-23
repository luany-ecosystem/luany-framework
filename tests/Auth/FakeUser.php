<?php

namespace Luany\Framework\Tests\Auth;

/** Minimal model with the luany/database methods the provider uses. */
class FakeUser
{
    /** @var array<int, array<string, mixed>> */
    public static array $rows = [];

    /** @param array<string, mixed> $attributes */
    public function __construct(public array $attributes)
    {
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public static function find(int|string $id): ?self
    {
        return isset(self::$rows[(int) $id]) ? new self(self::$rows[(int) $id]) : null;
    }

    /** @param array<int, mixed> $bindings */
    public static function firstWhere(string $conditions, array $bindings): ?self
    {
        preg_match_all('/(\w+) = \?/', $conditions, $m);
        foreach (self::$rows as $row) {
            if (array_values(array_map(fn ($c) => $row[$c] ?? null, $m[1])) === $bindings) {
                return new self($row);
            }
        }

        return null;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function getKey(): mixed
    {
        return $this->attributes['id'];
    }

    /** @param array<string, mixed> $values */
    public function forceFill(array $values): static
    {
        $this->attributes = array_merge($this->attributes, $values);
        return $this;
    }

    public function save(): bool
    {
        self::$rows[$this->attributes['id']] = $this->attributes;
        return true;
    }
}
