<?php

declare(strict_types=1);

/**
 * Scheduled platform audit.
 *
 *   php audit.php                 run every check and print a report
 *   php audit.php --fix           also repair what can be repaired
 *                                 (re-queue failures, retry Krayin pushes)
 *   php audit.php --listen        run the SocialListeningAgent first
 *   php audit.php --evergreen     run the EvergreenRecycler first
 *   php audit.php --json          machine readable output
 *
 * Exit codes: 0 = healthy, 1 = warnings, 2 = critical problems.
 *
 * Cron:
 *   *\/30 * * * * cd /var/www/social-bot && php audit.php >> /var/log/bot-audit.log 2>&1
 */

use SocialBot\Agents\EvergreenRecycler;
use SocialBot\Agents\SocialListeningAgent;
use SocialBot\CRM\KrayinClient;
use SocialBot\Social\MixpostManager;
use SocialBot\Support\App;
use SocialBot\Support\AuditLog;
use SocialBot\Support\Config;
use SocialBot\Support\Logger;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('audit.php is a CLI entry point.');
}

/** @var string $root */
$root = require __DIR__ . '/bootstrap.php';

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--')) {
        $options[substr($argument, 2)] = true;
    }
}

$asJson = isset($options['json']);
$fix = isset($options['fix']);
$auditLog = new AuditLog(App::db());
$checks = [];

if (isset($options['listen'])) {
    $listening = SocialListeningAgent::make()->run();
    $checks[] = check('agent.social_listening', true, 'info', sprintf(
        'scanned %d media, queued %d comment(s), %d keyword mention(s)',
        $listening['scanned'],
        $listening['queued'],
        $listening['mentions']
    ), $listening);
}

if (isset($options['evergreen'])) {
    $evergreen = EvergreenRecycler::make()->run();
    $checks[] = check('agent.evergreen', true, 'info', sprintf(
        'considered %d post(s), queued %d for approval',
        $evergreen['considered'],
        count($evergreen['queued'])
    ), $evergreen);
}

// --- infrastructure ---------------------------------------------------------

$checks[] = check('database.connection', App::db()->ping(), 'critical', 'MySQL reachable via ' . (App::db()->usingIlluminate() ? 'illuminate/database' : 'PDO'));

$missingTables = missingTables();
$checks[] = check(
    'database.schema',
    $missingTables === [],
    'critical',
    $missingTables === [] ? 'all bot tables present' : 'missing tables: ' . implode(', ', $missingTables),
    ['missing' => $missingTables]
);

$krayin = App::krayin();
$checks[] = check(
    'integration.krayin',
    $krayin->configured() && ($krayin->ping()['ok'] ?? false),
    $krayin->configured() ? 'warning' : 'warning',
    $krayin->configured()
        ? 'Krayin responded at ' . $krayin->baseUrl()
        : 'credentials pending: KRAYIN_API_TOKEN is empty',
    $krayin->configured() ? [] : ['pending' => ['KRAYIN_API_TOKEN']]
);

$mixpost = App::mixpost();
$mixpostPing = $mixpost->configured() ? $mixpost->ping() : ['ok' => false, 'error' => 'not configured'];
$checks[] = check(
    'integration.mixpost',
    (bool) ($mixpostPing['ok'] ?? false),
    'warning',
    (bool) ($mixpostPing['ok'] ?? false)
        ? 'Mixpost responded with the workspace account list'
        : 'Mixpost unavailable: ' . (string) ($mixpostPing['error'] ?? 'unknown'),
    ['pending' => $mixpost->configured() ? [] : ['MIXPOST_API_TOKEN', 'MIXPOST_WORKSPACE_UUID']]
);

$pendingCredentials = App::pendingCredentials();
$checks[] = check(
    'credentials.pending',
    $pendingCredentials === [],
    'warning',
    $pendingCredentials === []
        ? 'every credential is configured'
        : 'credentials pending: ' . implode(', ', $pendingCredentials),
    ['pending' => $pendingCredentials]
);

// --- LLM budget -------------------------------------------------------------

$usage = App::usage();
$summary = $usage->summary(7);
$todayTokens = $usage->todayTokens();
$budget = (int) Config::get('deepseek.daily_token_budget', 0);
$overBudget = $usage->overBudget();

$checks[] = check(
    'llm.budget',
    !$overBudget,
    'warning',
    $overBudget
        ? sprintf('daily token budget exhausted (%d/%d) - falling back to Ollama', $todayTokens, $budget)
        : sprintf('%d of %d tokens used today (%.1f%%), 7d cost estimate $%.4f', $todayTokens, $budget, $budget > 0 ? ($todayTokens / $budget) * 100 : 0.0, (float) $summary['totals']['cost']),
    ['totals' => $summary['totals'], 'tokens_today' => $todayTokens, 'budget' => $budget]
);

$cacheMisses = $usage->cacheMisses(20);
$checks[] = check(
    'llm.prompt_cache',
    $cacheMisses === [],
    'info',
    $cacheMisses === []
        ? sprintf('no cache misses; cache hit rate %.1f%%', ((float) $summary['totals']['cache_hit_rate']) * 100)
        : count($cacheMisses) . ' call(s) had no cached prompt tokens - check that prompts/ files were not modified at runtime',
    ['misses' => array_slice($cacheMisses, 0, 10)]
);

if ((string) Config::get('deepseek.api_key', '') === '') {
    $ollama = new \SocialBot\AI\OllamaClient();
    $checks[] = check(
        'llm.fallback_ollama',
        $ollama->available(),
        'warning',
        $ollama->available()
            ? 'DEEPSEEK_API_KEY missing; Ollama fallback is reachable at ' . Config::get('ollama.url')
            : 'DEEPSEEK_API_KEY missing AND Ollama is not reachable - classification will fall back to local rules',
        ['model' => Config::get('ollama.model')]
    );
}

// --- queue ------------------------------------------------------------------

$pipeline = App::pipeline();
$depth = $pipeline->queueDepth();
$requeued = 0;

if ($fix) {
    $requeued = $pipeline->requeueFailed(3, 100);
}

$checks[] = check(
    'queue.backlog',
    $depth['failed'] === 0,
    'warning',
    sprintf(
        'pending %d, processing %d, processed %d, skipped %d, failed %d%s',
        $depth['pending'],
        $depth['processing'],
        $depth['processed'],
        $depth['skipped'],
        $depth['failed'],
        $fix ? ' (requeued ' . $requeued . ')' : ''
    ),
    $depth
);

$staleClaims = $pipeline->closeStaleClaims(30);
$checks[] = check('queue.stale_claims', true, 'info', $staleClaims . ' stale claim(s) released', ['released' => $staleClaims]);

$skipRate = $pipeline->skipRateReport(24);
$checks[] = check(
    'queue.prefilter_rate',
    (bool) $skipRate['target_met'],
    'info',
    sprintf(
        'pre-filter skipped %.1f%% of comments in 24h (target 40-60%%): %d skipped / %d processed',
        $skipRate['rate'] * 100,
        $skipRate['skipped'],
        $skipRate['processed']
    ),
    $skipRate
);

// --- CRM sync ---------------------------------------------------------------

$unpushed = App::db()->select(
    'SELECT `id`, `platform`, `author`, `priority`, `lead_score`
     FROM `' . App::db()->table('leads') . '`
     WHERE `is_qualified` = 1 AND (`krayin_lead_id` IS NULL OR `krayin_lead_id` = 0)
     ORDER BY `lead_score` DESC LIMIT 25'
);

$pushed = 0;

if ($fix && $krayin->configured() && $unpushed !== []) {
    foreach ($unpushed as $lead) {
        $full = App::db()->find('leads', (int) $lead['id']);

        if ($full === null) {
            continue;
        }

        $response = $krayin->createLead($krayin->leadPayloadFromLead($full));

        if (($response['ok'] ?? false) && $response['id'] !== null) {
            App::db()->update('leads', ['krayin_lead_id' => $response['id'], 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $lead['id']]);
            $pushed++;
        }
    }
}

$checks[] = check(
    'crm.unpushed_qualified_leads',
    $unpushed === [],
    'warning',
    count($unpushed) . ' qualified lead(s) are not in Krayin yet' . ($fix ? ' (' . $pushed . ' pushed now)' : ' (run with --fix to retry)'),
    ['leads' => array_slice($unpushed, 0, 10), 'pushed' => $pushed]
);

$unprocessedAds = App::db()->count('google_ads_leads', ['processed' => 0]);
$checks[] = check(
    'ingestion.google_ads_backlog',
    $unprocessedAds === 0,
    'warning',
    $unprocessedAds . ' Google Ads lead row(s) not yet linked to a lead',
    ['unprocessed' => $unprocessedAds]
);

$failedPosts = App::db()->all('social_posts', ['status' => 'failed'], '`id` ASC', 25);
$retriedPosts = 0;

if ($fix) {
    foreach ($failedPosts as $post) {
        $result = App::workflow()->publishPostById((int) $post['id']);

        if (($result['ok'] ?? false) === true) {
            $retriedPosts++;
        }
    }
}

$checks[] = check(
    'publishing.failed_posts',
    $failedPosts === [],
    'warning',
    count($failedPosts) . ' social post(s) failed to publish' . ($fix ? ' (' . $retriedPosts . ' recovered)' : ''),
    ['failed' => array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'error' => $p['last_error']], $failedPosts)]
);

$breaches = App::workflow()->markSlaBreaches();
$checks[] = check(
    'approval.sla',
    $breaches === 0,
    'warning',
    $breaches === 0 ? 'no new approval SLA breaches' : $breaches . ' approval level(s) breached the SLA',
    ['new_breaches' => $breaches, 'sla_hours' => (int) Config::get('approval.sla_hours', 24)]
);

$pendingApprovals = App::db()->count('social_posts', ['status' => 'pending_approval']);
$checks[] = check('approval.pending', true, 'info', $pendingApprovals . ' post(s) waiting for approval', ['pending' => $pendingApprovals]);

// --- agents -----------------------------------------------------------------

$evergreenCandidates = EvergreenRecycler::make()->candidates(null, 5);
$checks[] = check(
    'agent.evergreen_candidates',
    true,
    'info',
    count($evergreenCandidates) . ' evergreen candidate(s) available for recycling',
    ['candidates' => array_map(static fn (array $c): array => [
        'post_id' => (int) $c['id'],
        'leads' => (int) $c['evergreen_leads'],
        'method' => $c['attribution_method'],
    ], $evergreenCandidates)]
);

$listeningVolume = SocialListeningAgent::make()->mentionVolume(24);
$checks[] = check(
    'agent.listening_volume',
    true,
    'info',
    $listeningVolume === [] ? 'no tracking keywords configured (LISTENING_KEYWORDS)' : 'mentions in 24h: ' . json_encode($listeningVolume),
    ['volume' => $listeningVolume]
);

// --- report -----------------------------------------------------------------

$severityRank = ['info' => 0, 'warning' => 1, 'critical' => 2];
$worst = 'info';

foreach ($checks as $entry) {
    if (!$entry['ok'] && $severityRank[$entry['severity']] > $severityRank[$worst]) {
        $worst = $entry['severity'];
    }

    if (!$entry['ok']) {
        $auditLog->record(
            'audit.' . $entry['name'],
            $entry['severity'],
            null,
            null,
            $entry['message'],
            is_array($entry['data']) ? $entry['data'] : []
        );
    }
}

$report = [
    'generated_at' => date('c'),
    'status' => $worst,
    'checks' => $checks,
    'counts' => [
        'total' => count($checks),
        'failing' => count(array_filter($checks, static fn (array $c): bool => !$c['ok'])),
    ],
];

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo '== social-bot platform audit (' . date('Y-m-d H:i:s') . ") ==\n";

    foreach ($checks as $entry) {
        $marker = $entry['ok'] ? ' OK ' : strtoupper(substr($entry['severity'], 0, 4));

        printf("[%s] %-36s %s\n", $marker, $entry['name'], $entry['message']);
    }

    printf(
        "\n%d checks, %d needing attention. Overall: %s\n",
        $report['counts']['total'],
        $report['counts']['failing'],
        strtoupper($worst)
    );
}

Logger::channel('audit')->write('info', 'platform audit completed', $report['counts'] + ['status' => $worst]);

exit(match ($worst) {
    'critical' => 2,
    'warning' => 1,
    default => 0,
});

/**
 * @param array<string, mixed> $data
 * @return array{name:string, ok:bool, severity:string, message:string, data:array<string, mixed>}
 */
function check(string $name, bool $ok, string $severity, string $message, array $data = []): array
{
    return ['name' => $name, 'ok' => $ok, 'severity' => $severity, 'message' => $message, 'data' => $data];
}

/**
 * @return array<int, string>
 */
function missingTables(): array
{
    $required = ['leads', 'comment_queue', 'social_posts', 'google_ads_leads', 'account_workspaces', 'approval_audit', 'audit_log', 'llm_usage'];
    $missing = [];

    foreach ($required as $table) {
        try {
            App::db()->select('SELECT 1 FROM `' . App::db()->table($table) . '` LIMIT 1');
        } catch (Throwable) {
            $missing[] = $table;
        }
    }

    return $missing;
}
