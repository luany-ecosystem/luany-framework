<?php

namespace Luany\Framework\Auth;

/**
 * Password reset tokens in a database table:
 *
 *   CREATE TABLE password_reset_tokens (
 *       user_id              VARCHAR(64) PRIMARY KEY,
 *       token_hash           CHAR(64)    NOT NULL,
 *       password_fingerprint CHAR(64)    NOT NULL,
 *       created_at           INTEGER     NOT NULL
 *   );
 *
 * (DatabaseTokenRepository::createTableSql() returns this statement.)
 */
final class DatabaseTokenRepository implements TokenRepository
{
    public function __construct(private \PDO $pdo, private string $table = 'password_reset_tokens')
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException("Invalid table name [{$table}].");
        }
    }

    public static function createTableSql(string $table = 'password_reset_tokens'): string
    {
        return "CREATE TABLE {$table} (user_id VARCHAR(64) PRIMARY KEY, token_hash CHAR(64) NOT NULL, "
            . 'password_fingerprint CHAR(64) NOT NULL, created_at INTEGER NOT NULL)';
    }

    public function save(string $userId, string $tokenHash, string $passwordFingerprint, int $createdAt): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->delete($userId);
            $this->pdo->prepare("INSERT INTO {$this->table} (user_id, token_hash, password_fingerprint, created_at) VALUES (?, ?, ?, ?)")
                ->execute([$userId, $tokenHash, $passwordFingerprint, $createdAt]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function find(string $userId): ?array
    {
        $statement = $this->pdo->prepare("SELECT token_hash, password_fingerprint, created_at FROM {$this->table} WHERE user_id = ?");
        $statement->execute([$userId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? [
            'token_hash'           => (string) $row['token_hash'],
            'password_fingerprint' => (string) $row['password_fingerprint'],
            'created_at'           => (int) $row['created_at'],
        ] : null;
    }

    public function delete(string $userId): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE user_id = ?")->execute([$userId]);
    }
}
