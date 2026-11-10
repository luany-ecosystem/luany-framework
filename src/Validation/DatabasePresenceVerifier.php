<?php

namespace Luany\Framework\Validation;

/**
 * PDO-backed presence verifier for the unique / exists rules.
 *
 * Table and column names come from rule strings written by developers, but
 * they are still validated (letters, digits, underscore, optional
 * "schema.table") and quoted for the driver, so a rule can never become an
 * SQL injection vector. Values are always bound.
 */
final class DatabasePresenceVerifier implements PresenceVerifierInterface
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var \PDO|\Closure(): \PDO */
    private \PDO|\Closure $pdo;

    /**
     * @param \PDO|\Closure(): \PDO $pdo A connection, or a factory resolved on first use
     */
    public function __construct(\PDO|\Closure $pdo)
    {
        $this->pdo = $pdo;
    }

    public function count(
        string $table,
        string $column,
        array $values,
        ?string $ignoreColumn = null,
        mixed $ignoreValue = null,
    ): int {
        if ($values === []) {
            return 0;
        }

        $pdo   = $this->pdo();
        $quote = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql' ? '"' : '`';

        $wrappedColumn = $this->wrap($column, $quote);

        // DISTINCT: duplicated rows must not make a partial match look complete.
        $sql = sprintf(
            'SELECT COUNT(DISTINCT %s) FROM %s WHERE %s IN (%s)',
            $wrappedColumn,
            $this->wrap($table, $quote, true),
            $wrappedColumn,
            implode(', ', array_fill(0, count($values), '?'))
        );
        $bindings = array_values($values);

        if ($ignoreColumn !== null && $ignoreValue !== null) {
            $sql       .= ' AND ' . $this->wrap($ignoreColumn, $quote) . ' <> ?';
            $bindings[] = $ignoreValue;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);

        return (int) $stmt->fetchColumn();
    }

    private function pdo(): \PDO
    {
        if ($this->pdo instanceof \Closure) {
            $resolved = ($this->pdo)();
            if (!$resolved instanceof \PDO) {
                throw new \LogicException('The presence verifier factory must return a PDO instance.');
            }
            $this->pdo = $resolved;
        }

        return $this->pdo;
    }

    private function wrap(string $identifier, string $quote, bool $allowSchema = false): string
    {
        $parts = $allowSchema ? explode('.', $identifier) : [$identifier];

        if (count($parts) > 2) {
            throw new \InvalidArgumentException("Invalid table name [{$identifier}] in validation rule.");
        }

        foreach ($parts as $part) {
            if (preg_match(self::IDENTIFIER, $part) !== 1) {
                throw new \InvalidArgumentException("Invalid identifier [{$identifier}] in validation rule.");
            }
        }

        return implode('.', array_map(fn (string $p) => $quote . $p . $quote, $parts));
    }
}
