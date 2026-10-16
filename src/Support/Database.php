<?php

declare(strict_types=1);

namespace SocialBot\Support;

use PDO;
use Throwable;

/**
 * Database access layer.
 *
 * Connection management is delegated to illuminate/database when it is
 * installed (the stack requirement); every statement this class issues is
 * portable SQL executed through that connection, so the same code path runs on
 * plain PDO as well.  Keeping one path means one set of semantics in
 * production and in the local fallback.
 */
final class Database
{
    private ?PDO $pdo = null;

    private mixed $connection = null;

    private readonly string $driver;

    private readonly string $prefix;

    /** @param array<string, mixed>|null $config */
    public function __construct(?array $config = null)
    {
        $config ??= (array) Config::get('db', []);

        $this->driver = (string) ($config['driver'] ?? 'mysql');
        $this->prefix = (string) ($config['prefix'] ?? '');

        // SQLite is only ever used by the test suite, where a predictable
        // single PDO connection matters more than the query-builder bridge.
        if ($this->driver === 'sqlite') {
            $this->pdo = $this->createPdo($config);

            return;
        }

        if (class_exists(\Illuminate\Database\Capsule\Manager::class)) {
            try {
                $capsule = new \Illuminate\Database\Capsule\Manager();
                $capsule->addConnection([
                    'driver' => $this->driver,
                    'host' => $config['host'] ?? 'localhost',
                    'port' => (int) ($config['port'] ?? 3306),
                    'database' => $config['database'] ?? '',
                    'username' => $config['username'] ?? '',
                    'password' => $config['password'] ?? '',
                    'charset' => $config['charset'] ?? 'utf8mb4',
                    'collation' => $config['collation'] ?? 'utf8mb4_unicode_ci',
                    'prefix' => $this->prefix,
                    'options' => [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_STRINGIFY_FETCHES => false,
                    ],
                ]);
                $capsule->setAsGlobal();
                $capsule->bootEloquent();
                $this->connection = $capsule->getConnection();

                return;
            } catch (Throwable $e) {
                Logger::warning('illuminate/database unavailable, falling back to PDO', ['error' => $e->getMessage()]);
            }
        }

        $this->pdo = $this->createPdo($config);
    }

    /** @param array<string, mixed> $config */
    private function createPdo(array $config): PDO
    {
        $dsn = $this->driver === 'sqlite'
            ? 'sqlite:' . (string) ($config['database'] ?? ':memory:')
            : sprintf(
                '%s:host=%s;port=%d;dbname=%s;charset=%s',
                $this->driver,
                $config['host'] ?? 'localhost',
                (int) ($config['port'] ?? 3306),
                $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4'
            );

        return new PDO(
            $dsn,
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => (int) ($config['timeout'] ?? 5),
            ]
        );
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        if ($this->connection !== null) {
            $rows = $this->connection->select($sql, array_values($bindings));

            return array_map(static fn (mixed $row): array => (array) $row, $rows);
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_values($bindings));

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $bindings = []): ?array
    {
        $rows = $this->select($sql, $bindings);

        return $rows[0] ?? null;
    }

    /**
     * Run a write statement and return the affected row count.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        if ($this->connection !== null) {
            return (int) $this->connection->affectingStatement($sql, array_values($bindings));
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_values($bindings));

        return $statement->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(string $table, array $row): int
    {
        $table = $this->table($table);
        $columns = array_keys($row);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );

        $this->execute($sql, array_values($row));

        return (int) $this->rawPdo()->lastInsertId();
    }

    /**
     * Insert ignoring duplicate-key errors. Returns the new id, or null when the
     * row already existed.  Used to make webhook ingestion idempotent.
     *
     * @param array<string, mixed> $row
     */
    public function insertIgnore(string $table, array $row): ?int
    {
        $table = $this->table($table);
        $columns = array_keys($row);
        $keyword = $this->driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT';

        $sql = sprintf(
            '%s INTO `%s` (%s) VALUES (%s)',
            $keyword,
            $table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );

        try {
            $affected = $this->execute($sql, array_values($row));
        } catch (Throwable $e) {
            if ($this->isDuplicateKey($e)) {
                return null;
            }

            throw $e;
        }

        if ($affected === 0) {
            return null;
        }

        return (int) $this->rawPdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $row, array $where): int
    {
        if ($row === [] || $where === []) {
            return 0;
        }

        $bindings = [];
        $assignments = [];

        foreach ($row as $column => $value) {
            $assignments[] = '`' . $column . '` = ?';
            $bindings[] = $value;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $this->table($table),
            implode(', ', $assignments),
            $this->buildWhere($where, $bindings)
        );

        return $this->execute($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        $bindings = [];
        $clause = $this->buildWhere($where, $bindings);

        return $this->execute(sprintf('DELETE FROM `%s` WHERE %s', $this->table($table), $clause), $bindings);
    }

    /**
     * @param array<string, mixed> $where
     */
    public function count(string $table, array $where = []): int
    {
        $bindings = [];
        $clause = $where === [] ? '1' : $this->buildWhere($where, $bindings);

        $row = $this->first(sprintf('SELECT COUNT(*) AS aggregate FROM `%s` WHERE %s', $this->table($table), $clause), $bindings);

        return (int) ($row['aggregate'] ?? 0);
    }

    /**
     * @param array<string, mixed> $where
     * @return array<string, mixed>|null
     */
    public function find(string $table, int $id): ?array
    {
        return $this->first(sprintf('SELECT * FROM `%s` WHERE `id` = ? LIMIT 1', $this->table($table)), [$id]);
    }

    /**
     * @param array<string, mixed> $where
     * @return array<string, mixed>|null
     */
    public function findWhere(string $table, array $where): ?array
    {
        $bindings = [];
        $clause = $this->buildWhere($where, $bindings);

        return $this->first(sprintf('SELECT * FROM `%s` WHERE %s LIMIT 1', $this->table($table), $clause), $bindings);
    }

    /**
     * @param array<string, mixed> $where
     */
    public function exists(string $table, array $where): bool
    {
        return $this->findWhere($table, $where) !== null;
    }

    /**
     * @param array<string, mixed> $where
     * @return array<int, array<string, mixed>>
     */
    public function all(
        string $table,
        array $where = [],
        string $orderBy = '`id` DESC',
        int $limit = 0,
        int $offset = 0,
        string $columns = '*'
    ): array {
        $bindings = [];
        $clause = $where === [] ? '1' : $this->buildWhere($where, $bindings);

        $sql = sprintf('SELECT %s FROM `%s` WHERE %s', $columns, $this->table($table), $clause);

        if ($orderBy !== '') {
            $sql .= ' ORDER BY ' . $orderBy;
        }

        if ($limit > 0) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', $limit, max(0, $offset));
        }

        return $this->select($sql, $bindings);
    }

    /**
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }

        return $result;
    }

    /**
     * Transactions are driven through the underlying PDO handle on both paths.
     *
     * This is deliberate: illuminate/database keeps its own nesting counter, and
     * MySQL/MariaDB DDL (CREATE TABLE ...) performs an *implicit commit*, which
     * leaves that counter out of sync with the real connection state (the next
     * beginTransaction() would then emit a SAVEPOINT instead of a transaction).
     * PDO is always the source of truth, so DDL-heavy work such as migrations
     * cannot corrupt the transaction state.
     *
     * Nested transaction() calls are flattened to a single level.
     */
    public function beginTransaction(): void
    {
        $pdo = $this->rawPdo();

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        $pdo = $this->rawPdo();

        // No-op when a DDL statement already committed implicitly.
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    public function rollBack(): void
    {
        $pdo = $this->rawPdo();

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    public function ping(): bool
    {
        try {
            $this->select('SELECT 1 AS ok');

            return true;
        } catch (Throwable $e) {
            Logger::error('database ping failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function table(string $table): string
    {
        return $this->prefix . $table;
    }

    public function rawPdo(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection->getPdo();
        }

        return $this->pdo;
    }

    /**
     * Escape hatch for callers that prefer the illuminate query builder.
     */
    public function connection(): mixed
    {
        return $this->connection;
    }

    public function usingIlluminate(): bool
    {
        return $this->connection !== null;
    }

    /**
     * Build a WHERE clause. Supported forms:
     *   ['status' => 'new']            -> `status` = ?
     *   ['status' => null]             -> `status` IS NULL
     *   ['status !=' => 'new']         -> `status` != ?
     *   ['id IN' => [1, 2]]            -> `id` IN (?, ?)
     *   ['deleted_at IS' => null]      -> `deleted_at` IS NULL
     *   ['`raw` = 1' => null]          -> raw fragment (key used verbatim)
     *
     * @param array<string, mixed> $where
     * @param array<int, mixed> $bindings
     */
    private function buildWhere(array $where, array &$bindings): string
    {
        $clauses = [];

        foreach ($where as $key => $value) {
            if ($value === null && preg_match('/[^A-Za-z0-9_`. ]/', (string) $key) === 1) {
                // Raw fragment, e.g. '`JSON_EXTRACT(x, "$.y")` is not null'.
                $clauses[] = (string) $key;

                continue;
            }

            $column = $key;
            $operator = '=';

            if (preg_match('/^(.+?)\s+(=|!=|<>|>|<|>=|<=|LIKE|NOT LIKE|IN|NOT IN|IS|IS NOT)$/i', (string) $key, $m) === 1) {
                $column = trim($m[1]);
                $operator = strtoupper(trim($m[2]));
            }

            $identifier = str_contains($column, '`') ? $column : '`' . trim($column, '`') . '`';

            if ($operator === 'IS' || $operator === 'IS NOT') {
                $clauses[] = $identifier . ' ' . $operator . ' ' . ($value === null ? 'NULL' : (string) $value);

                continue;
            }

            if ($value === null) {
                $clauses[] = $identifier . ' IS NULL';

                continue;
            }

            if ($operator === 'IN' || $operator === 'NOT IN') {
                $values = is_array($value) ? array_values($value) : [$value];

                if ($values === []) {
                    $clauses[] = $operator === 'IN' ? '0 = 1' : '1 = 1';

                    continue;
                }

                foreach ($values as $item) {
                    $bindings[] = $item;
                }

                $clauses[] = $identifier . ' ' . $operator . ' (' . implode(', ', array_fill(0, count($values), '?')) . ')';

                continue;
            }

            $bindings[] = $value;
            $clauses[] = $identifier . ' ' . $operator . ' ?';
        }

        return $clauses === [] ? '1' : implode(' AND ', $clauses);
    }

    private function isDuplicateKey(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, '1062');
    }
}
