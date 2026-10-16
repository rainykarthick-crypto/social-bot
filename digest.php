<?php

declare(strict_types=1);

/**
 * Daily email digest.
 *
 *   php digest.php                 build + email the digest (when DIGEST_TO is set)
 *   php digest.php --days=7        widen the reporting window
 *   php digest.php --preview       print the HTML instead of sending
 *   php digest.php --no-send       write the HTML file only
 *
 * The HTML is always written to storage/digests/digest-YYYY-MM-DD.html, so the
 * digest is available even on a box with no MTA.
 *
 * Cron:
 *   0 8 * * * cd /var/www/social-bot && php digest.php >> /var/log/bot-digest.log 2>&1
 */

use SocialBot\AI\AnalyticsSummarizer;
use SocialBot\Agents\EvergreenRecycler;
use SocialBot\Agents\SocialListeningAgent;
use SocialBot\Support\App;
use SocialBot\Support\AuditLog;
use SocialBot\Support\Config;
use SocialBot\Support\Logger;
use SocialBot\Workflow\CommentPipeline;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('digest.php is a CLI entry point.');
}

/** @var string $root */
$root = require __DIR__ . '/bootstrap.php';

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

$days = (int) ($options['days'] ?? 1);
$preview = isset($options['preview']);
$send = !isset($options['no-send']) && !$preview;

$db = App::db();
$summarizer = AnalyticsSummarizer::fromConfig();
$snapshot = $summarizer->snapshot($days);
$summary = $summarizer->summarize($days);
$pipeline = new CommentPipeline($db, App::sentiment(), App::classifier(), App::scorer(), App::router(), App::krayin());
$usage = App::usage()->summary(7);

$topLeads = $db->select(
    'SELECT `id`, `platform`, `author`, `priority`, `intent`, `lead_score`, `summary`, `krayin_lead_id`, `created_at`
     FROM `' . $db->table('leads') . '`
     WHERE `created_at` >= ? AND `is_qualified` = 1
     ORDER BY CASE `priority` WHEN \'P1\' THEN 1 WHEN \'P2\' THEN 2 ELSE 3 END, `lead_score` DESC
     LIMIT 10',
    [date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' days'))]
);

$pendingPosts = $db->select(
    'SELECT `id`, `content`, `requested_by`, `created_at`
     FROM `' . $db->table('social_posts') . '`
     WHERE `status` = ? ORDER BY `id` ASC LIMIT 10',
    ['pending_approval']
);

$slaRows = $db->select(
    'SELECT `social_post_id`, `level_name`, `remarks`, `created_at`
     FROM `' . $db->table('approval_audit') . '`
     WHERE `action` = ? AND `created_at` >= ? ORDER BY `id` DESC LIMIT 10',
    ['sla_breached', date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' days'))]
);

$listening = SocialListeningAgent::make()->mentionVolume(max(24, $days * 24));
$evergreen = EvergreenRecycler::make()->candidates(null, 5);
$routing = $pipeline->routingBreakdown($days);

$html = renderDigest([
    'generated_at' => date('Y-m-d H:i:s'),
    'days' => $days,
    'snapshot' => $snapshot,
    'summary' => $summary,
    'usage' => $usage,
    'top_leads' => $topLeads,
    'pending_posts' => $pendingPosts,
    'sla' => $slaRows,
    'listening' => $listening,
    'evergreen' => $evergreen,
    'routing' => $routing,
    'queue' => $pipeline->queueDepth(),
    'skip_rate' => $pipeline->skipRateReport(max(1, $days * 24)),
]);

$directory = (string) Config::get('paths.digests');
$file = $directory . '/digest-' . date('Y-m-d') . '.html';

if (!is_dir($directory)) {
    @mkdir($directory, 0775, true);
}

file_put_contents($file, $html);

if ($preview) {
    echo $html . PHP_EOL;
    exit(0);
}

$recipients = array_values(array_filter(array_map('trim', explode(',', (string) Config::get('digest.to', '')))));

if ($send && $recipients !== []) {
    $subject = (string) Config::get('digest.subject', 'Social Bot daily digest') . ' - ' . date('Y-m-d');
    $from = (string) Config::get('digest.from', 'bot@localhost');

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from,
        'Reply-To: ' . $from,
        'X-Mailer: social-bot-digest',
    ]);

    $sent = @mail(implode(',', $recipients), $subject, $html, $headers);

    (new AuditLog($db))->record(
        'digest.sent',
        $sent ? 'info' : 'warning',
        null,
        null,
        $sent ? 'Digest emailed to ' . implode(', ', $recipients) : 'Digest email failed (written to ' . $file . ')',
        ['recipients' => $recipients]
    );

    echo $sent
        ? 'Digest emailed to ' . implode(', ', $recipients) . PHP_EOL
        : 'mail() failed; digest written to ' . $file . PHP_EOL;
} else {
    echo 'Digest written to ' . $file . ($send ? ' (DIGEST_TO is empty, nothing emailed)' : '') . PHP_EOL;
}

Logger::channel('digest')->write('info', 'digest built', [
    'file' => $file,
    'leads' => $snapshot['total_leads'],
    'qualified' => $snapshot['qualified'],
    'pending_posts' => count($pendingPosts),
]);

exit(0);

/**
 * @param array<string, mixed> $data
 */
function renderDigest(array $data): string
{
    $snapshot = (array) $data['snapshot'];
    $summary = (array) $data['summary'];
    $totals = (array) ($data['usage']['totals'] ?? []);
    $queue = (array) $data['queue'];
    $skipRate = (array) $data['skip_rate'];

    $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Social Bot digest</title>';
    $html .= '<style>
        body{background:#0f172a;color:#e2e8f0;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;padding:24px}
        .card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px;margin-bottom:16px}
        h1{font-size:20px;margin:0 0 4px;color:#38bdf8}
        h2{font-size:15px;margin:0 0 10px;color:#38bdf8;text-transform:uppercase;letter-spacing:.05em}
        table{width:100%;border-collapse:collapse;font-size:13px}
        th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #334155}
        th{color:#94a3b8;font-weight:600}
        .muted{color:#94a3b8;font-size:12px}
        .p1{color:#ef4444;font-weight:700}.p2{color:#f59e0b;font-weight:700}.p3{color:#94a3b8}
        .grid{display:flex;flex-wrap:wrap;gap:12px}
        .metric{flex:1 1 140px;background:#0f172a;border:1px solid #334155;border-radius:8px;padding:10px}
        .metric b{display:block;font-size:22px;color:#38bdf8}
    </style></head><body>';

    $html .= '<h1>Social Bot digest</h1><div class="muted">Generated ' . $e($data['generated_at'])
        . ' &middot; window: last ' . (int) $data['days'] . ' day(s)</div>';

    $html .= '<div class="card"><h2>Headline</h2><div class="grid">';
    $metrics = [
        'Total leads' => (int) $snapshot['total_leads'],
        'Qualified' => (int) $snapshot['qualified'],
        'Booked' => (int) $snapshot['booked'],
        'Conversion %' => (float) $snapshot['conversion_rate'],
        'Top platform' => (string) $snapshot['top_platform'],
        'Top intent' => (string) $snapshot['top_intent'],
    ];

    foreach ($metrics as $label => $value) {
        $html .= '<div class="metric"><span class="muted">' . $e($label) . '</span><b>' . $e($value) . '</b></div>';
    }

    $html .= '</div><p class="muted">Recommendation: ' . $e($summary['recommendation'] ?? '') . '</p></div>';

    $html .= '<div class="card"><h2>Top qualified leads</h2>';
    $html .= '<table><tr><th>#</th><th>Author</th><th>Platform</th><th>Priority</th><th>Intent</th><th>Score</th><th>Summary</th></tr>';

    if (($data['top_leads'] ?? []) === []) {
        $html .= '<tr><td colspan="7" class="muted">No qualified leads in this window.</td></tr>';
    }

    foreach ((array) $data['top_leads'] as $lead) {
        $priority = strtolower((string) $lead['priority']);
        $html .= '<tr><td>' . (int) $lead['id'] . '</td><td>' . $e($lead['author']) . '</td><td>' . $e($lead['platform'])
            . '</td><td class="' . $e($priority) . '">' . $e($lead['priority']) . '</td><td>' . $e($lead['intent'])
            . '</td><td>' . (int) $lead['lead_score'] . '</td><td>' . $e(mb_substr((string) $lead['summary'], 0, 120)) . '</td></tr>';
    }

    $html .= '</table></div>';

    $html .= '<div class="card"><h2>Pipeline health</h2><table>';
    $html .= '<tr><th>Queue</th><th>Pending</th><th>Processing</th><th>Processed</th><th>Skipped</th><th>Failed</th><th>Pre-filter skip rate</th></tr>';
    $html .= '<tr><td>comment_queue</td><td>' . (int) $queue['pending'] . '</td><td>' . (int) $queue['processing'] . '</td><td>'
        . (int) $queue['processed'] . '</td><td>' . (int) $queue['skipped'] . '</td><td>' . (int) $queue['failed'] . '</td><td>'
        . $e(round(((float) $skipRate['rate']) * 100, 1)) . '%</td></tr>';
    $html .= '</table>';

    if (($data['routing'] ?? []) !== []) {
        $html .= '<table style="margin-top:10px"><tr><th>Routing queue</th><th>Priority</th><th>Leads</th></tr>';

        foreach ((array) $data['routing'] as $row) {
            $html .= '<tr><td>' . $e($row['routing_queue']) . '</td><td>' . $e($row['priority']) . '</td><td>' . (int) $row['total'] . '</td></tr>';
        }

        $html .= '</table>';
    }

    $html .= '</div>';

    $html .= '<div class="card"><h2>Approvals</h2>';
    $html .= '<table><tr><th>Post</th><th>Requested by</th><th>Waiting since</th><th>Content</th></tr>';

    if (($data['pending_posts'] ?? []) === []) {
        $html .= '<tr><td colspan="4" class="muted">Nothing pending approval.</td></tr>';
    }

    foreach ((array) $data['pending_posts'] as $post) {
        $html .= '<tr><td>#' . (int) $post['id'] . '</td><td>' . $e($post['requested_by']) . '</td><td>' . $e($post['created_at'])
            . '</td><td>' . $e(mb_substr((string) $post['content'], 0, 120)) . '</td></tr>';
    }

    $html .= '</table>';

    if (($data['sla'] ?? []) !== []) {
        $html .= '<p class="muted">SLA breaches:</p><table><tr><th>Post</th><th>Level</th><th>When</th></tr>';

        foreach ((array) $data['sla'] as $row) {
            $html .= '<tr><td>#' . (int) $row['social_post_id'] . '</td><td>' . $e($row['level_name']) . '</td><td>' . $e($row['created_at']) . '</td></tr>';
        }

        $html .= '</table>';
    }

    $html .= '</div>';

    $html .= '<div class="card"><h2>LLM spend (7 days)</h2><table>';
    $html .= '<tr><th>Calls</th><th>Total tokens</th><th>Cached prompt tokens</th><th>Cache hit rate</th><th>Cost estimate (USD)</th></tr>';
    $html .= '<tr><td>' . (int) ($totals['calls'] ?? 0) . '</td><td>' . (int) ($totals['total_tokens'] ?? 0) . '</td><td>'
        . (int) ($totals['prompt_cached_tokens'] ?? 0) . '</td><td>'
        . $e(round(((float) ($totals['cache_hit_rate'] ?? 0)) * 100, 1)) . '%</td><td>'
        . $e(number_format((float) ($totals['cost'] ?? 0), 4)) . '</td></tr>';
    $html .= '</table></div>';

    $html .= '<div class="card"><h2>Agents</h2>';
    $html .= '<p class="muted">Listening keywords (24h+): ' . $e($data['listening'] === [] ? 'none configured' : json_encode($data['listening'])) . '</p>';
    $html .= '<p class="muted">Evergreen candidates ready: ' . count((array) $data['evergreen']) . '</p>';
    $html .= '</div>';

    $html .= '<p class="muted">Sentiment breakdown: ' . $e(json_encode($snapshot['sentiment_breakdown'] ?? [])) . '</p>';
    $html .= '</body></html>';

    return $html;
}
