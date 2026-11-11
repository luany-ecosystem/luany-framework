<?php

namespace Luany\Framework\Validation;

/**
 * Answers "how many rows match?" for the database-backed validation rules
 * (unique, exists).
 */
interface PresenceVerifierInterface
{
    /**
     * Count the distinct $values found in $table.$column.
     *
     * @param array<int, mixed> $values         One value, or several for an IN (...) check
     * @param string|null       $ignoreColumn   Exclude rows whose $ignoreColumn equals $ignoreValue
     *                                          (e.g. the current record when updating)
     */
    public function count(
        string $table,
        string $column,
        array $values,
        ?string $ignoreColumn = null,
        mixed $ignoreValue = null,
    ): int;
}
