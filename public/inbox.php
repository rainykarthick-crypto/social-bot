<?php

declare(strict_types=1);

use SocialBot\Support\App;
use SocialBot\Support\Config;
use SocialBot\Support\Json;

/**
 * Lead inbox dashboard.
 *
 * Filters (GET): platform, priority, q, page.
 * Everything is read through Database::select() with bound parameters and every
 * value that reaches the HTML goes through e().
 */

$root = require __DIR__ . '/../bootstrap.php';

/**
 * HTML-escape any value for output in an HTML context.
 */
if (!function_exists('e')) {
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return htmlspecialchars($encoded === false ? '' : $encoded, ENT_QUOTES, 'UTF-8');
    }
}

// ---------------------------------------------------------------------------
// Optional HTTP Basic auth (only when DASHBOARD_USER is configured).
// ---------------------------------------------------------------------------
$authUser = Config::get('dashboard.user', '');
$authUser = is_string($authUser) ? trim($authUser) : '';

if ($authUser !== '') {
    $authPass = Config::get('dashboard.pass', '');
    $authPass = is_string($authPass) ? $authPass : '';

    $givenUser = isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
    $givenPass = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '';

    if (!hash_equals($authUser, $givenUser) || !hash_equals($authPass, $givenPass)) {
        if (!headers_sent()) {
            http_response_code(401);
            header('WWW-Authenticate: Basic realm="social-bot"');
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>401</title></head>'
            . '<body style="background:#0f172a;color:#e2e8f0;font-family:system-ui,sans-serif;padding:40px">'
            . '<h1 style="margin:0 0 8px">401 - Authentication required</h1>'
            . '<p style="color:#94a3b8">This dashboard is protected. Supply DASHBOARD_USER / DASHBOARD_PASS credentials.</p>'
            . '</body></html>';

        exit;
    }
}

// ---------------------------------------------------------------------------
// Filter parsing / whitelisting.
// ---------------------------------------------------------------------------
$allowedPriorities = ['all', 'P1', 'P2', 'P3'];
$allowedStatuses = ['new', 'contacted', 'follow_up', 'booked', 'not_interested', 'completed'];

$platformFilter = isset($_GET['platform']) && is_string($_GET['platform']) ? trim($_GET['platform']) : '';
$priorityFilter = isset($_GET['priority']) && is_string($_GET['priority']) ? strtoupper(trim($_GET['priority'])) : 'all';
$searchQuery = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? strtolower(trim($_GET['status'])) : '';

if (!in_array($priorityFilter, $allowedPriorities, true)) {
    $priorityFilter = 'all';
}

if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$platformFilter = mb_substr($platformFilter, 0, 64);
$searchQuery = mb_substr($searchQuery, 0, 120);

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, $page);

$pageSize = (int) Config::get('dashboard.page_size', 50);
$pageSize = max(10, min(500, $pageSize));

// ---------------------------------------------------------------------------
// Queries (all guarded: a missing MySQL/Redis must degrade to a notice).
// ---------------------------------------------------------------------------
$db = App::db();
$dbError = '';

$leadsTable = $db->table('leads');
$queueTable = $db->table('comment_queue');
$postsTable = $db->table('social_posts');

$platformOptions = [];
$leads = [];
$filteredTotal = 0;
$stats = ['total' => 0, 'qualified' => 0, 'booked' => 0, 'conversion' => 0.0];
$pages = 1;
$queueBacklog = ['pending' => 0, 'failed' => 0, 'skipped' => 0, 'processed' => 0, 'processing' => 0];
$pendingApprovals = 0;
$usageTotals = ['calls' => 0, 'total_tokens' => 0, 'prompt_cached_tokens' => 0, 'cost' => 0.0, 'cache_hit_rate' => 0.0];
$usageRows = [];

try {
    $platformRows = $db->select(
        'SELECT `platform`, COUNT(*) AS `aggregate` FROM `' . $leadsTable . '` GROUP BY `platform` ORDER BY `aggregate` DESC'
    );

    foreach ($platformRows as $platformRow) {
        $name = (string) ($platformRow['platform'] ?? '');

        if ($name !== '') {
            $platformOptions[$name] = (int) ($platformRow['aggregate'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

if ($dbError === '') {
    // Shared WHERE clause for list, count and stats so the stat cards always
    // describe exactly the rows shown below them.
    $whereSql = ' WHERE 1 = 1';
    $bindings = [];

    if ($platformFilter !== '') {
        $whereSql .= ' AND `platform` = ?';
        $bindings[] = $platformFilter;
    }

    if ($priorityFilter !== 'all') {
        $whereSql .= ' AND `priority` = ?';
        $bindings[] = $priorityFilter;
    }

    if ($statusFilter !== '') {
        $whereSql .= ' AND `status` = ?';
        $bindings[] = $statusFilter;
    }

    if ($searchQuery !== '') {
        $whereSql .= ' AND (`author` LIKE ? OR `summary` LIKE ? OR `comment_text` LIKE ?)';
        $like = '%' . $searchQuery . '%';
        $bindings[] = $like;
        $bindings[] = $like;
        $bindings[] = $like;
    }

    try {
        $statsRow = $db->first(
            'SELECT COUNT(*) AS `total`,
                    COALESCE(SUM(CASE WHEN `is_qualified` = 1 THEN 1 ELSE 0 END), 0) AS `qualified`,
                    COALESCE(SUM(CASE WHEN `status` = \'booked\' THEN 1 ELSE 0 END), 0) AS `booked`
             FROM (SELECT * FROM `' . $leadsTable . '`' . $whereSql . ') AS `filtered_leads`',
            $bindings
        );

        if (is_array($statsRow)) {
            $stats['total'] = (int) ($statsRow['total'] ?? 0);
            $stats['qualified'] = (int) ($statsRow['qualified'] ?? 0);
            $stats['booked'] = (int) ($statsRow['booked'] ?? 0);
            $stats['conversion'] = $stats['total'] > 0
                ? round(($stats['booked'] / $stats['total']) * 100, 1)
                : 0.0;
        }

        $filteredTotal = $stats['total'];
        $pages = max(1, (int) ceil($filteredTotal / $pageSize));

        if ($page > $pages) {
            $page = $pages;
        }

        $offset = ($page - 1) * $pageSize;

        $leads = $db->select(
            'SELECT * FROM `' . $leadsTable . '`' . $whereSql
            . ' ORDER BY `lead_score` DESC, `id` DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
            $bindings
        );
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

if ($dbError === '') {
    try {
        $backlogRows = $db->select(
            'SELECT `status`, COUNT(*) AS `aggregate` FROM `' . $queueTable . '` GROUP BY `status`'
        );

        foreach ($backlogRows as $backlogRow) {
            $status = (string) ($backlogRow['status'] ?? '');

            if ($status !== '') {
                $queueBacklog[$status] = (int) ($backlogRow['aggregate'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

if ($dbError === '') {
    try {
        $pendingApprovals = $db->count('social_posts', ['status' => 'pending_approval']);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// Token usage is a separate subsystem: a missing llm_usage table only hides the
// numbers, it must not blank the whole dashboard.
try {
    $usage = App::usage()->summary(7);
    $usageRows = is_array($usage['rows'] ?? null) ? $usage['rows'] : [];
    $usageTotals = array_merge($usageTotals, is_array($usage['totals'] ?? null) ? $usage['totals'] : []);
} catch (Throwable $e) {
    $usageRows = [];
}

$pendingCredentials = [];
$approvalSlaHours = (int) Config::get('approval.sla_hours', 24);

try {
    $pendingCredentials = App::pendingCredentials();
} catch (Throwable $e) {
    $pendingCredentials = [];
}

$krayinBase = rtrim((string) Config::get('krayin.api_url', ''), '/');
$mixpostUrl = rtrim((string) Config::get('mixpost.url', ''), '/');
$mixpostWorkspace = (string) Config::get('mixpost.workspace_uuid', '');

$isFiltered = $platformFilter !== '' || $priorityFilter !== 'all' || $searchQuery !== '' || $statusFilter !== '';

// ---------------------------------------------------------------------------
// Small view helpers.
// ---------------------------------------------------------------------------
if (!function_exists('priority_class')) {
    function priority_class(string $priority): string
    {
        return match (strtoupper($priority)) {
            'P1' => 'badge badge-p1',
            'P2' => 'badge badge-p2',
            default => 'badge badge-p3',
        };
    }
}

if (!function_exists('status_class')) {
    function status_class(string $status): string
    {
        return match (strtolower($status)) {
            'booked', 'completed' => 'badge badge-ok',
            'not_interested' => 'badge badge-danger',
            'contacted' => 'badge badge-info',
            'follow_up' => 'badge badge-p2',
            default => 'badge badge-muted',
        };
    }
}

if (!function_exists('sentiment_class')) {
    function sentiment_class(string $sentiment): string
    {
        return match (strtolower($sentiment)) {
            'positive' => 'sent-pos',
            'negative' => 'sent-neg',
            default => 'sent-neutral',
        };
    }
}

if (!function_exists('status_label')) {
    function status_label(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status === '' ? 'new' : $status));
    }
}

/**
 * Build a query string for the pagination links, preserving every filter.
 *
 * @param array<string, string|int> $params
 */
if (!function_exists('inbox_query')) {
    function inbox_query(array $params): string
    {
        $filtered = [];

        foreach ($params as $key => $value) {
            if ((string) $value !== '') {
                $filtered[$key] = (string) $value;
            }
        }

        return http_build_query($filtered);
    }
}

$pageUrl = static function (int $target) use ($pageSize, $platformFilter, $priorityFilter, $searchQuery, $statusFilter): string {
    return 'inbox.php?' . inbox_query([
        'platform' => $platformFilter,
        'priority' => $priorityFilter,
        'status' => $statusFilter,
        'q' => $searchQuery,
        'page' => $target,
    ]);
};

$currentParams = [
    'platform' => $platformFilter,
    'priority' => $priorityFilter,
    'status' => $statusFilter,
    'q' => $searchQuery,
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lead inbox - <?= e((string) Config::get('app.name', 'social-bot')) ?></title>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
    :root {
        --bg: #0f172a;
        --panel: #1e293b;
        --border: #334155;
        --accent: #38bdf8;
        --text: #e2e8f0;
        --muted: #94a3b8;
        --p1: #ef4444;
        --p2: #f59e0b;
        --p3: #94a3b8;
        --ok: #22c55e;
        --danger: #ef4444;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        font-size: 14px;
        line-height: 1.45;
    }

    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }

    .wrap { max-width: 1360px; margin: 0 auto; padding: 20px 18px 60px; }

    header.top {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
        justify-content: space-between;
        margin-bottom: 18px;
    }

    header.top h1 { font-size: 20px; margin: 0; letter-spacing: .2px; }
    header.top .sub { color: var(--muted); font-size: 12px; }

    .pill {
        display: inline-block;
        padding: 2px 9px;
        border: 1px solid var(--border);
        border-radius: 999px;
        font-size: 11px;
        color: var(--muted);
    }

    .banner {
        border: 1px solid var(--p2);
        border-left-width: 4px;
        background: rgba(245, 158, 11, .08);
        border-radius: 8px;
        padding: 10px 14px;
        margin-bottom: 16px;
        font-size: 13px;
    }

    .banner.error { border-color: var(--danger); background: rgba(239, 68, 68, .08); }
    .banner ul { margin: 6px 0 0; padding-left: 20px; color: var(--muted); }

    .layout { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 18px; align-items: start; }

    @media (max-width: 1080px) { .layout { grid-template-columns: minmax(0, 1fr); } }

    .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
    @media (max-width: 760px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

    .card {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 14px 16px;
    }

    .stat .label { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .6px; }
    .stat .value { font-size: 26px; font-weight: 650; margin-top: 4px; }
    .stat .value.accent { color: var(--accent); }
    .stat .value.ok { color: var(--ok); }

    .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; }
    .panel h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .7px; color: var(--muted); margin: 0; }
    .panel .head { padding: 12px 14px; border-bottom: 1px solid var(--border); }
    .panel .body { padding: 12px 14px; }

    form.filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; padding: 12px 14px; }

    .field { display: flex; flex-direction: column; gap: 4px; }
    .field label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }

    input[type="text"], input[type="search"], select, textarea {
        background: #0b1220;
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: 7px;
        padding: 7px 9px;
        font: inherit;
        min-width: 150px;
    }

    input:focus, select:focus, textarea:focus { outline: 1px solid var(--accent); border-color: var(--accent); }

    button, .btn {
        background: #0b1220;
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: 7px;
        padding: 7px 12px;
        font: inherit;
        cursor: pointer;
    }

    button:hover, .btn:hover { border-color: var(--accent); color: var(--accent); text-decoration: none; }

    button.primary { background: var(--accent); color: #04121f; border-color: var(--accent); font-weight: 600; }
    button.primary:hover { color: #04121f; filter: brightness(1.08); }

    .table-scroll { overflow-x: auto; }

    table { width: 100%; border-collapse: collapse; }

    thead th {
        text-align: left;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: var(--muted);
        padding: 10px 12px;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    tbody td { padding: 10px 12px; border-bottom: 1px solid rgba(51, 65, 85, .6); vertical-align: top; }

    tbody tr.lead-row { cursor: pointer; }
    tbody tr.lead-row:hover { background: #24334a; }
    tbody tr.lead-row.is-selected { background: #1d3a52; }

    .author { font-weight: 600; }
    .snippet { color: var(--muted); font-size: 12px; margin-top: 2px; max-width: 260px; }
    .clip-1 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .clip-2 { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }

    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .badge-p1 { background: rgba(239, 68, 68, .16); color: var(--p1); border-color: rgba(239, 68, 68, .5); }
    .badge-p2 { background: rgba(245, 158, 11, .16); color: var(--p2); border-color: rgba(245, 158, 11, .5); }
    .badge-p3 { background: rgba(148, 163, 184, .16); color: var(--p3); border-color: rgba(148, 163, 184, .45); }
    .badge-ok { background: rgba(34, 197, 94, .16); color: var(--ok); border-color: rgba(34, 197, 94, .5); }
    .badge-danger { background: rgba(239, 68, 68, .16); color: var(--danger); border-color: rgba(239, 68, 68, .5); }
    .badge-info { background: rgba(56, 189, 248, .16); color: var(--accent); border-color: rgba(56, 189, 248, .5); }
    .badge-muted { background: rgba(148, 163, 184, .12); color: var(--muted); border-color: var(--border); }

    .sent-pos { color: var(--ok); }
    .sent-neg { color: var(--danger); }
    .sent-neutral { color: var(--muted); }

    .score { font-weight: 650; }
    .score-bar { height: 5px; border-radius: 3px; background: #0b1220; border: 1px solid var(--border); margin-top: 5px; width: 76px; }
    .score-bar > span { display: block; height: 100%; background: var(--accent); border-radius: 2px; }

    .muted { color: var(--muted); }
    .small { font-size: 12px; }
    .nowrap { white-space: nowrap; }
    .right { text-align: right; }

    .empty { padding: 34px 16px; text-align: center; color: var(--muted); }

    .pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; flex-wrap: wrap; }
    .pager .links { display: flex; gap: 8px; align-items: center; }
    .pager .disabled { color: #475569; border-color: #263449; pointer-events: none; }

    .ops-list { list-style: none; margin: 0; padding: 0; }
    .ops-list li { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; border-bottom: 1px dashed rgba(51, 65, 85, .8); }
    .ops-list li:last-child { border-bottom: 0; }
    .ops-list .name { color: var(--muted); }
    .ops-list .num { font-weight: 650; }

    .kv { display: grid; grid-template-columns: 1fr auto; gap: 4px 10px; }
    .kv .k { color: var(--muted); }
    .kv .v { font-weight: 600; text-align: right; }

    code { background: #0b1220; border: 1px solid var(--border); border-radius: 5px; padding: 1px 5px; font-size: 12px; }

    [x-cloak] { display: none !important; }
</style>
</head>
<body>
<div class="wrap"
     x-data="{ selected: 0, platform: '', priority: '', status: '', q: '' }"
     x-init="var form = $refs.filters;
             platform = form.elements['platform'].value;
             priority = form.elements['priority'].value;
             status = form.elements['status'].value;
             q = form.elements['q'].value;">

    <header class="top">
        <div>
            <h1>Lead inbox</h1>
            <div class="sub">
                <?= e((string) Config::get('app.name', 'social-bot')) ?>
                &middot; <?= e(date('Y-m-d H:i')) ?>
                &middot; <?= e((string) Config::get('app.timezone', 'UTC')) ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <?php if ($krayinBase !== ''): ?>
                <a class="btn" href="<?= e($krayinBase . '/admin/leads') ?>" target="_blank" rel="noopener">Krayin CRM</a>
            <?php endif; ?>
            <?php if ($mixpostUrl !== '' && $mixpostWorkspace !== ''): ?>
                <a class="btn" href="<?= e($mixpostUrl . '/mixpost/' . $mixpostWorkspace . '/posts') ?>" target="_blank" rel="noopener">Mixpost</a>
            <?php endif; ?>
            <span class="pill">approval SLA <?= e((string) $approvalSlaHours) ?>h</span>
        </div>
    </header>

    <?php if ($pendingCredentials !== []): ?>
        <div class="banner">
            <strong>Pending credentials</strong> &mdash; <?= e((string) count($pendingCredentials)) ?> environment value(s) still empty,
            the matching integration stays disabled until they are set in <code>.env</code>.
            <ul>
                <?php foreach ($pendingCredentials as $credential): ?>
                    <li><?= e($credential) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($dbError !== ''): ?>
        <div class="banner error">
            <strong>Database unavailable &mdash; check .env</strong>
            <div class="small" style="margin-top:4px">
                The dashboard could not read the <code>leads</code> table. Verify <code>DB_HOST</code>, <code>DB_NAME</code>,
                <code>DB_USER</code> and <code>DB_PASS</code>, then run <code>php migrate.php</code>.
            </div>
            <div class="small muted" style="margin-top:6px"><?= e($dbError) ?></div>
        </div>
    <?php endif; ?>

    <div class="layout">
        <main>
            <section class="stats">
                <div class="card stat">
                    <div class="label">Total Leads</div>
                    <div class="value"><?= e((string) $stats['total']) ?></div>
                    <div class="small muted"><?= $isFiltered ? 'matching filters' : 'all captured leads' ?></div>
                </div>
                <div class="card stat">
                    <div class="label">Qualified</div>
                    <div class="value accent"><?= e((string) $stats['qualified']) ?></div>
                    <div class="small muted">is_qualified = 1</div>
                </div>
                <div class="card stat">
                    <div class="label">Booked</div>
                    <div class="value ok"><?= e((string) $stats['booked']) ?></div>
                    <div class="small muted">status = booked</div>
                </div>
                <div class="card stat">
                    <div class="label">Conversion %</div>
                    <div class="value"><?= e(number_format((float) $stats['conversion'], 1)) ?>%</div>
                    <div class="small muted">booked / total</div>
                </div>
            </section>

            <section class="panel">
                <form class="filters" method="get" action="inbox.php" x-ref="filters"
                      @change="if ($event.target.name !== 'q') { $refs.filters.submit() }"
                      @submit="/* normal GET submit */">
                    <div class="field">
                        <label for="platform">Platform</label>
                        <select id="platform" name="platform" x-model="platform">
                            <option value="">All platforms</option>
                            <?php foreach ($platformOptions as $platformName => $platformCount): ?>
                                <option value="<?= e($platformName) ?>"><?= e($platformName) ?> (<?= e((string) $platformCount) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="priority">Priority</label>
                        <select id="priority" name="priority" x-model="priority">
                            <option value="all">All priorities</option>
                            <option value="P1">P1 - hot</option>
                            <option value="P2">P2 - warm</option>
                            <option value="P3">P3 - cold</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status" x-model="status">
                            <option value="">All statuses</option>
                            <?php foreach ($allowedStatuses as $statusOption): ?>
                                <option value="<?= e($statusOption) ?>"><?= e(status_label($statusOption)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" style="flex:1 1 240px">
                        <label for="q">Search author / summary</label>
                        <input type="search" id="q" name="q" x-ref="q" x-model="q"
                               @keydown.enter="$refs.filters.submit()"
                               placeholder="e.g. pricing, delivery, Priya">
                    </div>
                    <input type="hidden" name="page" value="1">
                    <button type="submit" class="primary">Apply</button>
                    <?php if ($isFiltered): ?>
                        <a class="btn" href="inbox.php">Reset</a>
                    <?php endif; ?>
                    <span class="small muted" x-show="q !== ''" x-cloak>
                        <a href="#" @click.prevent="q = ''; $refs.q.value = ''; $nextTick(() => $refs.filters.submit())">clear search</a>
                    </span>
                </form>
            </section>

            <section class="panel" style="margin-top:16px" @keydown.window.escape="selected = 0">
                <div class="head" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                    <h2>Results</h2>
                    <span class="small muted">
                        <?= e((string) $filteredTotal) ?> lead(s) &middot; page <?= e((string) $page) ?> of <?= e((string) $pages) ?>
                        <span x-show="selected !== 0" x-cloak> &middot; selected #<span x-text="selected"></span></span>
                    </span>
                </div>

                <?php if ($leads === []): ?>
                    <div class="empty">
                        <?php if ($dbError !== ''): ?>
                            No data &mdash; the database could not be reached.
                        <?php elseif ($isFiltered): ?>
                            No leads match the current filters.
                        <?php else: ?>
                            No leads captured yet. Ingested comments land here once the worker drains <code>comment_queue</code>.
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:26%">Lead / Author</th>
                                    <th>Platform</th>
                                    <th style="width:26%">Intent &amp; Summary</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Sentiment</th>
                                    <th>Score /100</th>
                                    <th class="nowrap">Captured</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leads as $lead): ?>
                                    <?php
                                    $leadId = (int) ($lead['id'] ?? 0);
                                    $leadUrl = 'lead_detail.php?id=' . $leadId;
                                    $author = trim((string) ($lead['author'] ?? ''));
                                    $comment = (string) ($lead['comment_text'] ?? '');
                                    $leadScore = max(0, min(100, (int) ($lead['lead_score'] ?? 0)));
                                    $priority = strtoupper((string) ($lead['priority'] ?? 'P3'));
                                    $status = strtolower((string) ($lead['status'] ?? 'new'));
                                    $sentiment = strtolower((string) ($lead['sentiment'] ?? 'neutral'));
                                    $platform = (string) ($lead['platform'] ?? '');
                                    ?>
                                    <tr class="lead-row"
                                        data-href="<?= e($leadUrl) ?>"
                                        @click="selected = <?= e((string) $leadId) ?>; window.location = $el.dataset.href"
                                        :class="selected === <?= e((string) $leadId) ?> ? 'is-selected' : ''">
                                        <td>
                                            <a class="author" href="<?= e($leadUrl) ?>" @click.stop><?= e($author !== '' ? $author : 'unknown') ?></a>
                                            <?php if (trim($comment) !== ''): ?>
                                                <div class="snippet clip-1"><?= e($comment) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($lead['is_qualified'])): ?>
                                                <span class="badge badge-ok" style="margin-top:4px">qualified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="nowrap"><?= e($platform) ?></td>
                                        <td>
                                            <div><?= e(ucfirst((string) ($lead['intent'] ?? 'none'))) ?>
                                                <span class="muted small">/ <?= e((string) (int) ($lead['intent_score'] ?? 0)) ?></span>
                                            </div>
                                            <?php if (trim((string) ($lead['summary'] ?? '')) !== ''): ?>
                                                <div class="snippet clip-1"><?= e((string) $lead['summary']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="<?= e(priority_class($priority)) ?>"><?= e($priority) ?></span></td>
                                        <td><span class="<?= e(status_class($status)) ?>"><?= e(status_label($status)) ?></span></td>
                                        <td class="nowrap"><span class="<?= e(sentiment_class($sentiment)) ?>"><?= e($sentiment) ?></span></td>
                                        <td>
                                            <span class="score"><?= e((string) $leadScore) ?></span>
                                            <div class="score-bar"><span style="width: <?= e((string) $leadScore) ?>%"></span></div>
                                        </td>
                                        <td class="nowrap small muted"><?= e((string) ($lead['created_at'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="pager">
                        <span class="small muted">
                            Showing <?= e((string) (($page - 1) * $pageSize + 1)) ?>&ndash;<?= e((string) min($page * $pageSize, $filteredTotal)) ?>
                            of <?= e((string) $filteredTotal) ?>
                        </span>
                        <span class="links">
                            <?php if ($page > 1): ?>
                                <a class="btn" href="<?= e($pageUrl(1)) ?>">&laquo; First</a>
                                <a class="btn" href="<?= e($pageUrl($page - 1)) ?>">&lsaquo; Prev</a>
                            <?php else: ?>
                                <span class="btn disabled">&laquo; First</span>
                                <span class="btn disabled">&lsaquo; Prev</span>
                            <?php endif; ?>
                            <?php if ($page < $pages): ?>
                                <a class="btn" href="<?= e($pageUrl($page + 1)) ?>">Next &rsaquo;</a>
                                <a class="btn" href="<?= e($pageUrl($pages)) ?>">Last &raquo;</a>
                            <?php else: ?>
                                <span class="btn disabled">Next &rsaquo;</span>
                                <span class="btn disabled">Last &raquo;</span>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
            </section>
        </main>

        <aside>
            <section class="panel">
                <div class="head"><h2>Operations</h2></div>
                <div class="body">
                    <ul class="ops-list">
                        <li>
                            <span class="name">Pending approvals</span>
                            <span class="num <?= $pendingApprovals > 0 ? '' : 'muted' ?>"><?= e((string) $pendingApprovals) ?></span>
                        </li>
                        <li>
                            <span class="name">Queue &middot; pending</span>
                            <span class="num"><?= e((string) $queueBacklog['pending']) ?></span>
                        </li>
                        <li>
                            <span class="name">Queue &middot; processing</span>
                            <span class="num"><?= e((string) $queueBacklog['processing']) ?></span>
                        </li>
                        <li>
                            <span class="name">Queue &middot; processed</span>
                            <span class="num"><?= e((string) $queueBacklog['processed']) ?></span>
                        </li>
                        <li>
                            <span class="name">Queue &middot; skipped</span>
                            <span class="num"><?= e((string) $queueBacklog['skipped']) ?></span>
                        </li>
                        <li>
                            <span class="name">Queue &middot; failed</span>
                            <span class="num" style="color: <?= $queueBacklog['failed'] > 0 ? 'var(--danger)' : 'inherit' ?>">
                                <?= e((string) $queueBacklog['failed']) ?>
                            </span>
                        </li>
                    </ul>

                    <div style="margin-top:14px">
                        <div class="small muted" style="margin-bottom:6px">LLM usage &middot; last 7 days</div>
                        <div class="kv small">
                            <span class="k">Calls</span>
                            <span class="v"><?= e((string) (int) ($usageTotals['calls'] ?? 0)) ?></span>
                            <span class="k">Total tokens</span>
                            <span class="v"><?= e(number_format((float) ($usageTotals['total_tokens'] ?? 0))) ?></span>
                            <span class="k">Cached prompt tokens</span>
                            <span class="v"><?= e(number_format((float) ($usageTotals['prompt_cached_tokens'] ?? 0))) ?></span>
                            <span class="k">Cache hit rate</span>
                            <span class="v"><?= e(number_format(((float) ($usageTotals['cache_hit_rate'] ?? 0)) * 100, 1)) ?>%</span>
                            <span class="k">Est. cost (USD)</span>
                            <span class="v">$<?= e(number_format((float) ($usageTotals['cost'] ?? 0), 4)) ?></span>
                        </div>
                    </div>

                    <?php if ($usageRows !== []): ?>
                        <details style="margin-top:12px">
                            <summary class="small muted" style="cursor:pointer">Per-model breakdown</summary>
                            <div class="small" style="margin-top:8px">
                                <?php foreach ($usageRows as $usageRow): ?>
                                    <div class="kv" style="margin-bottom:6px">
                                        <span class="k"><?= e((string) ($usageRow['day'] ?? '')) ?></span>
                                        <span class="v"><?= e((string) ($usageRow['model'] ?? 'unknown')) ?></span>
                                        <span class="k"><?= e((string) ($usageRow['provider'] ?? '')) ?></span>
                                        <span class="v"><?= e(number_format((float) ($usageRow['total_tokens'] ?? 0))) ?> tok</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel" style="margin-top:16px">
                <div class="head"><h2>Filters in effect</h2></div>
                <div class="body small">
                    <div class="kv">
                        <span class="k">Platform</span>
                        <span class="v"><?= e($platformFilter !== '' ? $platformFilter : 'all') ?></span>
                        <span class="k">Priority</span>
                        <span class="v"><?= e($priorityFilter) ?></span>
                        <span class="k">Status</span>
                        <span class="v"><?= e($statusFilter !== '' ? $statusFilter : 'all') ?></span>
                        <span class="k">Search</span>
                        <span class="v"><?= e($searchQuery !== '' ? $searchQuery : '-') ?></span>
                        <span class="k">Page size</span>
                        <span class="v"><?= e((string) $pageSize) ?></span>
                    </div>
                    <?php if ($krayinBase === '' && $mixpostUrl === ''): ?>
                        <p class="muted" style="margin:10px 0 0">Krayin / Mixpost URLs are not configured yet.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel" style="margin-top:16px">
                <div class="head"><h2>Credentials</h2></div>
                <div class="body small">
                    <?php if ($pendingCredentials === []): ?>
                        <span style="color: var(--ok)">All tracked credentials are present.</span>
                    <?php else: ?>
                        <span class="muted"><?= e((string) count($pendingCredentials)) ?> missing:
                            <?= e(implode(', ', $pendingCredentials)) ?></span>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>
</body>
</html>
