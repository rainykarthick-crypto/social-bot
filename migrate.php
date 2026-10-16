<?php

declare(strict_types=1);

/**
 * Migration runner.
 *
 *   php migrate.php              apply every pending migration
 *   php migrate.php --status     show applied / pending
 *   php migrate.php --fresh      drop all bot tables, then re-apply
 *   php migrate.php --pretend    print the SQL that would run
 *
 * Plain SQL files in migrations/ are applied in filename order and recorded in
 * `schema_migrations`, so re-running is always safe.
 */

use SocialBot\Support\App;
use SocialBot\Support\Config;

/** @var string $root */
$root = require __DIR__ . '/bootstrap.php';

$arguments = array_slice($argv, 1);
$status = in_array('--status', $arguments, true);
$fresh = in_array('--fresh', $arguments, true);
$pretend = in_array('--pretend', $arguments, true);

$db = App::db();

echo "== social-bot migrate ==\n";
echo 'driver : ' . $db->driver() . ' (' . ($db->usingIlluminate() ? 'illuminate/database' : 'pdo') . ")\n";
echo 'target : ' . Config::get('db.username') . '@' . Config::get('db.host') . ':' . Config::get('db.port') . '/' . Config::get('db.database') . "\n";

if (!$db->ping()) {
    fwrite(STDERR, "[fail] cannot connect to the database. Start MySQL and check .env (DB_HOST/DB_NAME/DB_USER/DB_PASS).\n");
    exit(2);
}

$db->execute(
    'CREATE TABLE IF NOT EXISTS `' . $db->table('schema_migrations') . '` (
        `migration` VARCHAR(191) NOT NULL,
        `batch` INT UNSIGNED NOT NULL DEFAULT 1,
        `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`migration`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = array_column($db->select('SELECT `migration` FROM `' . $db->table('schema_migrations') . '` ORDER BY `migration`'), 'migration');
$batch = ((int) ($db->first('SELECT MAX(`batch`) AS aggregate FROM `' . $db->table('schema_migrations') . '`')['aggregate'] ?? 0)) + 1;

$files = glob(Config::path('migrations') . '/*.sql') ?: [];
sort($files);

if ($fresh) {
    if (!in_array('--force', $arguments, true)) {
        fwrite(STDERR, "[abort] --fresh drops every bot table. Re-run with --fresh --force if that is really intended.\n");
        exit(1);
    }

    $tables = ['approval_audit', 'google_ads_leads', 'social_posts', 'comment_queue', 'leads', 'account_workspaces', 'audit_log', 'llm_usage', 'schema_migrations'];
    echo "-- dropping tables\n";

    foreach (['approval_audit', 'google_ads_leads', 'social_posts', 'comment_queue', 'leads', 'account_workspaces', 'audit_log', 'llm_usage'] as $table) {
        $db->execute('DROP TABLE IF EXISTS `' . $db->table($table) . '`');
    }

    $applied = [];
    unset($tables);
}

if ($status) {
    printf("%-40s %s\n", 'MIGRATION', 'STATUS');

    foreach ($files as $file) {
        $name = basename($file);
        printf("%-40s %s\n", $name, in_array($name, $applied, true) ? 'applied' : 'pending');
    }

    echo count($files) . ' migration file(s), ' . count($applied) . " applied.\n";
    exit(0);
}

$pending = array_values(array_filter($files, static fn (string $f): bool => !in_array(basename($f), $applied, true)));

if ($pending === []) {
    echo "Nothing to migrate - schema is up to date.\n";
    exit(0);
}

foreach ($pending as $file) {
    $name = basename($file);
    $sql = (string) file_get_contents($file);
    $statements = splitSqlStatements($sql);

    echo "-- applying {$name} (" . count($statements) . " statement(s))\n";

    if ($pretend) {
        foreach ($statements as $statement) {
            echo '   ' . preg_replace('/\s+/', ' ', substr($statement, 0, 160)) . "\n";
        }

        continue;
    }

    try {
        $db->transaction(function () use ($db, $statements): void {
            foreach ($statements as $statement) {
                $db->execute($statement);
            }
        });

        $db->insert('schema_migrations', ['migration' => $name, 'batch' => $batch]);
        echo "   ok\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "   [fail] {$name}: " . $e->getMessage() . "\n");
        exit(3);
    }
}

echo "Done. " . count($pending) . " migration(s) applied in batch {$batch}.\n";

/**
 * Split a .sql file into individual statements, ignoring semicolons inside
 * quoted strings, backtick identifiers and comments.
 *
 * @return array<int, string>
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $quote = null;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
                $buffer .= $char;
            }

            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }

            continue;
        }

        if ($quote === null && $char === '-' && $next === '-') {
            $inLineComment = true;
            $i++;

            continue;
        }

        if ($quote === null && $char === '/' && $next === '*') {
            $inBlockComment = true;
            $i++;

            continue;
        }

        if ($quote !== null) {
            $buffer .= $char;

            if ($char === '\\' && $quote !== '`') {
                $buffer .= $next;
                $i++;

                continue;
            }

            if ($char === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $buffer .= $char;

            continue;
        }

        if ($char === ';') {
            $statement = trim($buffer);

            if ($statement !== '') {
                $statements[] = $statement;
            }

            $buffer = '';

            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);

    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}
