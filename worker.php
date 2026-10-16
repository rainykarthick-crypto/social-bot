<?php

declare(strict_types=1);

/**
 * Queue processor.
 *
 *   php worker.php                one batch, off-peak aware (cron friendly: the
 *                                 default run exits well inside a minute)
 *   php worker.php --daemon       loop forever, sleeping WORKER_SLEEP_SECONDS
 *   php worker.php --limit=40     batch size for this run
 *   php worker.php --dry-run      pre-filter + classify, write nothing
 *   php worker.php --once         explicit single batch (same as default)
 *   php worker.php --retry-publish retry failed Mixpost publications
 *   php worker.php --requeue-failed put failed queue rows back to pending
 *   php worker.php --status       print queue/usage status and exit
 *
 * Cron (every minute; the process self-limits so runs never stack):
 *   * * * * * cd /var/www/social-bot && php worker.php >> /var/log/bot-worker.log 2>&1
 */

use SocialBot\Support\App;
use SocialBot\Support\Config;
use SocialBot\Support\Logger;
use SocialBot\Support\Messages\ProcessCommentBatchMessage;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('worker.php is a CLI entry point.');
}

/** @var string $root */
$root = require __DIR__ . '/bootstrap.php';

$options = parseOptions($argv);
$limit = (int) ($options['limit'] ?? Config::get('worker.batch_size', 20));
$sleep = (int) Config::get('worker.sleep_seconds', 5);
$maxRuntime = (int) ($options['max-runtime'] ?? 55);
$offpeakOnly = Config::get('worker.offpeak_only', false) && !isset($options['ignore-offpeak']);

if (isset($options['help'])) {
    echo <<<TXT
    social-bot worker

      --daemon           loop forever (default: one batch then exit)
      --limit=N          comments per batch (default 20)
      --dry-run          classify but do not persist
      --max-runtime=SEC  stop after SEC seconds (default 55, keeps cron runs short)
      --retry-publish    retry failed Mixpost publications
      --requeue-failed   move failed queue rows (attempts < 3) back to pending
      --ignore-offpeak   ignore WORKER_OFFPEAK_ONLY for this run
      --status           print queue, skip-rate and token usage, then exit

    TXT;
    exit(0);
}

Logger::channel('worker')->write('info', 'worker started', [
    'limit' => $limit,
    'daemon' => isset($options['daemon']),
    'dry_run' => isset($options['dry-run']),
    'offpeak_only' => $offpeakOnly,
]);

if (isset($options['status'])) {
    printStatus();
    exit(0);
}

if (isset($options['requeue-failed'])) {
    $requeued = App::pipeline()->requeueFailed();
    Logger::channel('worker')->write('info', 'failed rows requeued', ['count' => $requeued]);
    echo "Requeued {$requeued} failed comment(s).\n";
}

if (isset($options['retry-publish'])) {
    retryFailedPublications();
}

$stop = false;

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop): void {
        $stop = true;
    });
    pcntl_signal(SIGINT, static function () use (&$stop): void {
        $stop = true;
    });
}

$startedAt = time();
$iterations = 0;

do {
    $iterations++;

    if ($offpeakOnly && !isOffPeak(new DateTimeImmutable('now', new DateTimeZone('UTC')))) {
        Logger::channel('worker')->write('info', 'outside DeepSeek off-peak window, sleeping', [
            'windows' => Config::get('worker.offpeak_windows', []),
        ]);
    } else {
        // One bad batch must never kill the worker: the row is released by the
        // stale-claim sweep, and the loop keeps draining the queue.
        try {
            $stats = App::bus()->dispatch(new ProcessCommentBatchMessage($limit, isset($options['dry-run'])));
            $stats = is_array($stats) ? $stats : [];

            Logger::channel('worker')->write('info', 'batch processed', $stats);

            if (isset($options['daemon']) || isset($options['verbose'])) {
                echo '[' . date('H:i:s') . '] ' . json_encode($stats, JSON_UNESCAPED_SLASHES) . PHP_EOL;
            }
        } catch (Throwable $e) {
            Logger::channel('worker')->write('error', 'batch failed, worker continues', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            if (isset($options['daemon']) || isset($options['verbose'])) {
                echo '[' . date('H:i:s') . '] batch failed: ' . $e->getMessage() . PHP_EOL;
            }
        }

        // Publishing retries are cheap and keep failed posts from rotting.
        try {
            retryFailedPublications(3, 5);
        } catch (Throwable $e) {
            Logger::channel('worker')->write('error', 'publish retry sweep failed', ['error' => $e->getMessage()]);
        }
    }

    if (!isset($options['daemon'])) {
        break;
    }

    if ($stop) {
        break;
    }

    if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
        Logger::channel('worker')->write('info', 'max runtime reached, exiting cleanly', [
            'seconds' => time() - $startedAt,
            'iterations' => $iterations,
        ]);

        break;
    }

    sleep($sleep);
} while (!$stop);

Logger::channel('worker')->write('info', 'worker finished', [
    'iterations' => $iterations,
    'seconds' => time() - $startedAt,
]);

exit(0);

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function parseOptions(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }

        $argument = substr($argument, 2);

        if (str_contains($argument, '=')) {
            [$key, $value] = explode('=', $argument, 2);
            $options[$key] = is_numeric($value) ? (int) $value : $value;

            continue;
        }

        $options[$argument] = true;
    }

    return $options;
}

/**
 * Off-peak check for DeepSeek spend: configured UTC windows (which may wrap past
 * midnight) plus an optional all-weekend rule.
 */
function isOffPeak(DateTimeImmutable $nowUtc): bool
{
    $weekendAllowed = (bool) Config::get('worker.offpeak_all_weekend', true);

    if ($weekendAllowed && in_array((int) $nowUtc->format('N'), [6, 7], true)) {
        return true;
    }

    $current = (int) $nowUtc->format('H') * 60 + (int) $nowUtc->format('i');

    foreach ((array) Config::get('worker.offpeak_windows', []) as $window) {
        if (!is_string($window) || !str_contains($window, '-')) {
            continue;
        }

        [$start, $end] = array_map('trim', explode('-', $window, 2));

        $startMinutes = toMinutes($start);
        $endMinutes = toMinutes($end);

        if ($startMinutes === null || $endMinutes === null) {
            continue;
        }

        if ($startMinutes <= $endMinutes) {
            if ($current >= $startMinutes && $current <= $endMinutes) {
                return true;
            }

            continue;
        }

        // Wraps past midnight (e.g. 10:00-01:00).
        if ($current >= $startMinutes || $current <= $endMinutes) {
            return true;
        }
    }

    return false;
}

function toMinutes(string $clock): ?int
{
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $clock, $matches) !== 1) {
        return null;
    }

    return ((int) $matches[1]) * 60 + (int) $matches[2];
}

function retryFailedPublications(int $maxAttempts = 3, int $limit = 10): int
{
    $db = App::db();

    $rows = $db->select(
        'SELECT `id`, `attempts` FROM `' . $db->table('social_posts') . '`
         WHERE `status` = ? AND `attempts` < ?
         ORDER BY `id` ASC LIMIT ' . max(1, $limit),
        ['failed', max(1, $maxAttempts)]
    );

    $retried = 0;

    foreach ($rows as $row) {
        $result = App::workflow()->publishPostById((int) $row['id']);

        Logger::channel('worker')->write('info', 'publish retry', [
            'post_id' => (int) $row['id'],
            'ok' => (bool) ($result['ok'] ?? false),
            'status' => $result['status'] ?? null,
        ]);

        $retried++;
    }

    return $retried;
}

function printStatus(): void
{
    $pipeline = App::pipeline();
    $usage = App::usage();

    echo "== queue ==\n";
    echo json_encode($pipeline->queueDepth(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    echo "== pre-filter (24h) ==\n";
    echo json_encode($pipeline->skipRateReport(24), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    echo "== llm usage (7d) ==\n";
    echo json_encode($usage->summary(7)['totals'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    echo "== today ==\n";
    echo json_encode([
        'tokens_today' => $usage->todayTokens(),
        'budget' => (int) Config::get('deepseek.daily_token_budget'),
        'over_budget' => $usage->overBudget(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    echo "== readiness ==\n";
    echo json_encode(App::readiness(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
