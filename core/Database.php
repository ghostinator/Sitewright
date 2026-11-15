<?php
declare(strict_types=1);

namespace RankMathWebapp\Core;

use PDO;
use PDOException;
use PDOStatement;

/** PDO wrapper. Table/column identifiers are validated; values are always bound. */
final class Database
{
    private static ?self $instance = null;
    private PDO $pdo;
    private string $prefix;

    private function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $c = Config::get('db');
            self::$instance = new self(self::connect($c), (string)$c['prefix']);
        }
        return self::$instance;
    }

    /** Test seam / installer: drop the cached connection. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /** @param array{host:string,port:int,name:?string,user:string,pass:string} $c */
    public static function connect(array $c, bool $withDb = true): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], (int)$c['port'])
             . ($withDb && !empty($c['name']) ? ';dbname=' . $c['name'] : '');
        try {
            return new PDO($dsn, (string)$c['user'], (string)$c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        } catch (PDOException $e) {
            // Message may contain host/user: log it, never return it to a client.
            error_log('Database connection failed: ' . $e->getMessage());
            throw new DatabaseException('Database connection failed', 0, $e);
        }
    }

    /** True when $e wraps a duplicate-key violation (MySQL 1062). */
    public static function isDuplicate(\Throwable $e): bool
    {
        $prev = $e->getPrevious();
        return $prev instanceof PDOException && (int)($prev->errorInfo[1] ?? 0) === 1062;
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /** Prefixed table name, e.g. t('seo_analysis') => rm_seo_analysis. */
    public function t(string $table): string
    {
        // Table names may start with a digit (404_monitor); the validated prefix keeps the full name a legal identifier.
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new DatabaseException('Invalid SQL identifier');
        }
        return $this->prefix . $table;
    }

    public static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new DatabaseException('Invalid SQL identifier');
        }
    }

    /**
     * Prepare + execute. PHP ints bind as PARAM_INT (required for LIMIT/OFFSET with native prepares),
     * null as NULL, everything else as string. Named placeholders must be unique within a statement.
     *
     * @param array<string,mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $name = is_int($k) ? $k + 1 : ':' . ltrim((string)$k, ':');
                $type = is_int($v) ? PDO::PARAM_INT : (is_bool($v) ? PDO::PARAM_BOOL : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
                $stmt->bindValue($name, $v, $type);
            }
            $stmt->execute();
            return $stmt;
        } catch (PDOException $e) {
            error_log('Database query error: ' . $e->getMessage());
            throw new DatabaseException('Database query failed', 0, $e);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function insert(string $table, array $data): string
    {
        $cols = $this->columns($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->t($table),
            implode(', ', array_map(static fn ($c) => "`$c`", $cols)),
            implode(', ', array_map(static fn ($c) => ":$c", $cols))
        );
        $this->query($sql, $data);
        return $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data  column => value
     * @param array<string,mixed> $where column => value (AND-ed equality; null => IS NULL)
     */
    public function update(string $table, array $data, array $where): int
    {
        if (!$where) {
            throw new DatabaseException('update() requires a WHERE condition');
        }
        $set = []; $params = [];
        foreach ($this->columns($data) as $c) { $set[] = "`$c` = :s_$c"; $params["s_$c"] = $data[$c]; }
        [$cond, $wp] = $this->where($where);
        return $this->query(sprintf('UPDATE `%s` SET %s WHERE %s', $this->t($table), implode(', ', $set), $cond), $params + $wp)->rowCount();
    }

    /** @param array<string,mixed> $where */
    public function delete(string $table, array $where): int
    {
        if (!$where) {
            throw new DatabaseException('delete() requires a WHERE condition');
        }
        [$cond, $wp] = $this->where($where);
        return $this->query(sprintf('DELETE FROM `%s` WHERE %s', $this->t($table), $cond), $wp)->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    /** @return string[] */
    private function columns(array $data): array
    {
        if (!$data) {
            throw new DatabaseException('No columns supplied');
        }
        $cols = array_keys($data);
        foreach ($cols as $c) { self::assertIdentifier((string)$c); }
        return array_map('strval', $cols);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function where(array $where): array
    {
        $parts = []; $params = [];
        foreach ($this->columns($where) as $c) {
            if ($where[$c] === null) { $parts[] = "`$c` IS NULL"; }
            else { $parts[] = "`$c` = :w_$c"; $params["w_$c"] = $where[$c]; }
        }
        return [implode(' AND ', $parts), $params];
    }
}
