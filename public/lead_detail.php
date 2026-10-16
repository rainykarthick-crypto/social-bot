<?php

declare(strict_types=1);

use SocialBot\Support\App;
use SocialBot\Support\Config;
use SocialBot\Support\Json;

/**
 * Single lead detail page.
 *
 * GET  : id
 * POST : action (status|create_task|reply|reclassify|approve_post|reject_post)
 *        + csrf token, redirect-after-post with a flash notice.
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
            'pending_approval' => 'badge badge-p2',
            'approved' => 'badge badge-ok',
            'rejected' => 'badge badge-danger',
            'published' => 'badge badge-ok',
            'failed' => 'badge badge-danger',
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

if (!function_exists('flash_set')) {
    /**
     * @param array<string, mixed> $payload
     */
    function flash_set(string $type, string $message, array $payload = []): void
    {
        $_SESSION['lead_flash'] = ['type' => $type, 'message' => $message, 'extra' => $payload];
    }
}

// ---------------------------------------------------------------------------
// Optional HTTP Basic auth.
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
            . '<p style="color:#94a3b8">This dashboard is protected.</p></body></html>';

        exit;
    }
}

// ---------------------------------------------------------------------------
// Session / CSRF.
// ---------------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['csrf_token'];

$db = App::db();
$dbError = '';

$leadsTable = $db->table('leads');
$postsTable = $db->table('social_posts');
$auditTable = $db->table('approval_audit');

$leadId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

/**
 * Redirect to this lead with a flash message (POST-redirect-GET).
 */
$redirectToLead = static function (int $id): void {
    $target = 'lead_detail.php?id=' . $id;

    if (!headers_sent()) {
        header('Location: ' . $target, true, 303);
    } else {
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '">';
    }

    exit;
};

$currentLead = null;

if ($leadId > 0) {
    try {
        $currentLead = $db->find('leads', $leadId);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// POST actions.
// ---------------------------------------------------------------------------
if ($requestMethod === 'POST') {
    $postAction = isset($_POST['action']) && is_string($_POST['action']) ? strtolower(trim($_POST['action'])) : '';
    $postedId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $postedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';

    $actor = 'dashboard';

    if (isset($_SERVER['PHP_AUTH_USER']) && (string) $_SERVER['PHP_AUTH_USER'] !== '') {
        $actor = (string) $_SERVER['PHP_AUTH_USER'];
    }

    if ($postedId > 0) {
        $leadId = $postedId;
    }

    if (!hash_equals($csrfToken, $postedToken)) {
        flash_set('error', 'Invalid or expired CSRF token - please retry.');
        $redirectToLead($leadId);
    }

    if ($dbError !== '') {
        flash_set('error', 'Database unavailable - check .env (' . $dbError . ')');
        $redirectToLead($leadId);
    }

    if ($currentLead === null) {
        try {
            $currentLead = $db->find('leads', $leadId);
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
        }
    }

    if (!is_array($currentLead)) {
        flash_set('error', 'Lead #' . $leadId . ' could not be loaded.');
        $redirectToLead($leadId);
    }

    $remarks = isset($_POST['remarks']) && is_string($_POST['remarks']) ? trim((string) $_POST['remarks']) : '';

    try {
        switch ($postAction) {
            case 'status':
                $newStatus = isset($_POST['status']) && is_string($_POST['status']) ? strtolower(trim((string) $_POST['status'])) : '';
                $allowedStatuses = ['new', 'contacted', 'follow_up', 'booked', 'not_interested', 'completed'];

                if (!in_array($newStatus, $allowedStatuses, true)) {
                    flash_set('error', 'Unsupported status transition requested.');
                } else {
                    $db->update('leads', ['status' => $newStatus], ['id' => $leadId]);
                    flash_set('success', 'Lead status updated to "' . $newStatus . '".');
                }

                break;

            case 'create_task':
                $krayinLeadId = (int) ($currentLead['krayin_lead_id'] ?? 0);

                if ($krayinLeadId <= 0) {
                    flash_set('error', 'No Krayin lead is linked yet - run the Krayin sync before creating a task.');
                } else {
                    $author = trim((string) ($currentLead['author'] ?? ''));
                    $platform = trim((string) ($currentLead['platform'] ?? ''));

                    $result = App::krayin()->createActivity($krayinLeadId, [
                        'title' => 'Follow up: ' . ($author !== '' ? $author : $platform),
                        'type' => 'task',
                        'comment' => (string) ($currentLead['summary'] ?? ''),
                        'schedule_from' => date('Y-m-d H:i:s'),
                    ]);

                    if (($result['ok'] ?? false) === true) {
                        flash_set('success', 'Krayin task created (activity #' . (string) ($result['id'] ?? '?') . ').');
                    } else {
                        flash_set('error', 'Krayin task failed: ' . (string) ($result['error'] ?? 'unknown error'));
                    }
                }

                break;

            case 'reply':
                $reply = isset($_POST['reply']) && is_string($_POST['reply']) ? trim((string) $_POST['reply']) : '';

                if ($reply === '') {
                    flash_set('error', 'Write a reply before sending.');
                    break;
                }

                if (strtolower((string) ($currentLead['platform'] ?? '')) === 'instagram') {
                    $result = \SocialBot\Ingestion\InstagramIngestor::make()->replyToComment(
                        (string) ($currentLead['comment_id'] ?? ''),
                        $reply
                    );

                    if (($result['ok'] ?? false) === true) {
                        $db->update('leads', ['suggested_reply' => $reply], ['id' => $leadId]);
                        flash_set('success', 'Reply published on Instagram: ' . (string) ($result['message'] ?? 'ok'));
                    } else {
                        flash_set('error', 'Instagram reply failed: ' . (string) ($result['message'] ?? 'unknown error'));
                    }
                } else {
                    $db->update('leads', ['suggested_reply' => $reply], ['id' => $leadId]);
                    flash_set('success', 'Reply queued for manual send (platform: ' . (string) ($currentLead['platform'] ?? 'unknown') . ').');
                }

                break;

            case 'reclassify':
                $commentText = (string) ($currentLead['comment_text'] ?? '');

                $result = App::classifier()->classifyOne([
                    'text' => $commentText,
                    'platform' => (string) ($currentLead['platform'] ?? ''),
                    'author' => (string) ($currentLead['author'] ?? ''),
                    'likes' => 0,
                    'post_context' => (string) ($currentLead['post_id'] ?? ''),
                    'comment_id' => (string) ($currentLead['comment_id'] ?? ''),
                ]);

                $newScore = max(0, min(100, (int) ($result['score'] ?? 0)));

                $db->update('leads', [
                    'intent' => (string) ($result['intent'] ?? 'none'),
                    'intent_score' => $newScore,
                    'sentiment' => (string) ($result['sentiment'] ?? 'neutral'),
                    'priority' => (string) ($result['priority'] ?? 'P3'),
                    'summary' => (string) ($result['summary'] ?? ''),
                    'suggested_reply' => (string) ($result['reply'] ?? ''),
                    'lead_score' => $newScore,
                    'is_qualified' => (($result['is_lead'] ?? false) === true) ? 1 : 0,
                ], ['id' => $leadId]);

                $provider = (string) ($result['provider'] ?? 'unknown');
                $degraded = ($result['degraded'] ?? false) === true;

                flash_set(
                    $degraded ? 'warning' : 'success',
                    'Re-classified by ' . $provider . ' (score ' . $newScore . ', intent ' . (string) ($result['intent'] ?? 'none') . ').'
                    . ($degraded ? ' Provider was degraded - the local/heuristic fallback answered.' : '')
                );

                break;

            case 'approve_post':
                $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
                $level = isset($_POST['level']) && is_string($_POST['level']) && trim((string) $_POST['level']) !== ''
                    ? trim((string) $_POST['level'])
                    : null;

                if ($postId <= 0) {
                    flash_set('error', 'Missing social post id.');
                    break;
                }

                $result = App::workflow()->approvePost($postId, $actor, $remarks !== '' ? $remarks : null, $level);

                flash_set(
                    (($result['ok'] ?? false) === true) ? 'success' : 'error',
                    'Post #' . $postId . ': ' . (string) ($result['message'] ?? 'no message returned')
                    . ' (status: ' . (string) ($result['status'] ?? 'unknown') . ')'
                );

                break;

            case 'reject_post':
                $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

                if ($postId <= 0) {
                    flash_set('error', 'Missing social post id.');
                    break;
                }

                $result = App::workflow()->rejectPost($postId, $actor, $remarks !== '' ? $remarks : null);

                flash_set(
                    (($result['ok'] ?? false) === true) ? 'success' : 'error',
                    'Post #' . $postId . ': ' . (string) ($result['message'] ?? 'no message returned')
                    . ' (status: ' . (string) ($result['status'] ?? 'unknown') . ')'
                );

                break;

            case 'publish_post':
                $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

                if ($postId <= 0) {
                    flash_set('error', 'Missing social post id.');
                    break;
                }

                $result = App::workflow()->publishPostById($postId);

                flash_set(
                    (($result['ok'] ?? false) === true) ? 'success' : 'error',
                    'Post #' . $postId . ': ' . (string) ($result['message'] ?? 'no message returned')
                    . ' (status: ' . (string) ($result['status'] ?? 'unknown') . ')'
                );

                break;

            default:
                flash_set('error', 'Unknown action: ' . ($postAction === '' ? '(empty)' : $postAction));
        }
    } catch (Throwable $e) {
        flash_set('error', 'Action failed: ' . $e->getMessage());
    }

    $redirectToLead($leadId);
}

// ---------------------------------------------------------------------------
// Flash (read once).
// ---------------------------------------------------------------------------
$flash = null;

if (isset($_SESSION['lead_flash']) && is_array($_SESSION['lead_flash'])) {
    $flash = $_SESSION['lead_flash'];
    unset($_SESSION['lead_flash']);
}

$flashType = is_array($flash) ? (string) ($flash['type'] ?? 'info') : 'info';
$flashMessage = is_array($flash) ? (string) ($flash['message'] ?? '') : '';

// ---------------------------------------------------------------------------
// Reload the lead (it may have been mutated by the action above).
// ---------------------------------------------------------------------------
if ($dbError === '' && $leadId > 0) {
    try {
        $currentLead = $db->find('leads', $leadId);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$notFound = ($dbError === '' && !is_array($currentLead));

// ---------------------------------------------------------------------------
// Related records.
// ---------------------------------------------------------------------------
$socialPosts = [];

if ($dbError === '' && is_array($currentLead)) {
    try {
        $socialPosts = $db->select(
            'SELECT * FROM `' . $postsTable . '` WHERE `lead_id` = ? ORDER BY `id` DESC LIMIT 50',
            [$leadId]
        );
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$auditTrail = [];

if ($dbError === '') {
    foreach ($socialPosts as $postRow) {
        $rowPostId = (int) ($postRow['id'] ?? 0);

        try {
            $auditTrail[$rowPostId] = $db->select(
                'SELECT * FROM `' . $auditTable . '` WHERE `social_post_id` = ? ORDER BY `id` ASC',
                [$rowPostId]
            );
        } catch (Throwable $e) {
            $auditTrail[$rowPostId] = [];
        }
    }
}

// ---------------------------------------------------------------------------
// View data.
// ---------------------------------------------------------------------------
$author = is_array($currentLead) ? trim((string) ($currentLead['author'] ?? '')) : '';
$commentText = is_array($currentLead) ? (string) ($currentLead['comment_text'] ?? '') : '';
$platform = is_array($currentLead) ? strtolower((string) ($currentLead['platform'] ?? '')) : '';
$leadStatus = is_array($currentLead) ? strtolower((string) ($currentLead['status'] ?? 'new')) : 'new';
$priority = is_array($currentLead) ? strtoupper((string) ($currentLead['priority'] ?? 'P3')) : 'P3';
$sentiment = is_array($currentLead) ? strtolower((string) ($currentLead['sentiment'] ?? 'neutral')) : 'neutral';
$intent = is_array($currentLead) ? (string) ($currentLead['intent'] ?? 'none') : 'none';
$intentScore = is_array($currentLead) ? max(0, min(100, (int) ($currentLead['intent_score'] ?? 0))) : 0;
$leadScore = is_array($currentLead) ? max(0, min(100, (int) ($currentLead['lead_score'] ?? 0))) : 0;
$sentimentScore = is_array($currentLead) ? (float) ($currentLead['sentiment_score'] ?? 0) : 0.0;
$suggestedReply = is_array($currentLead) ? (string) ($currentLead['suggested_reply'] ?? '') : '';
$profileUrl = is_array($currentLead) ? trim((string) ($currentLead['author_profile_url'] ?? '')) : '';
$krayinLeadId = is_array($currentLead) ? (int) ($currentLead['krayin_lead_id'] ?? 0) : 0;

$krayinBase = rtrim((string) Config::get('krayin.api_url', ''), '/');
$mixpostUrl = rtrim((string) Config::get('mixpost.url', ''), '/');
$mixpostWorkspace = (string) Config::get('mixpost.workspace_uuid', '');

$krayinLeadUrl = $krayinLeadId > 0 && $krayinBase !== ''
    ? $krayinBase . '/admin/leads/view/' . $krayinLeadId
    : '';

$mixpostPostsUrl = ($mixpostUrl !== '' && $mixpostWorkspace !== '')
    ? $mixpostUrl . '/mixpost/' . $mixpostWorkspace . '/posts'
    : '';

$rawClassification = [];

if (is_array($currentLead)) {
    foreach ([
        'id', 'platform', 'comment_id', 'post_id', 'author', 'author_profile_url', 'comment_text',
        'intent', 'intent_score', 'sentiment', 'sentiment_score', 'lead_score', 'priority',
        'is_qualified', 'status', 'summary', 'suggested_reply', 'department', 'area',
        'follow_up_date', 'appointment_slot', 'routing_queue', 'google_ads_lead_id',
        'krayin_lead_id', 'created_at', 'updated_at',
    ] as $column) {
        if (array_key_exists($column, $currentLead)) {
            $rawClassification[$column] = $currentLead[$column];
        }
    }
}

$rawJson = Json::encode($rawClassification);

$priorityCounts = ['P1' => 0, 'P2' => 0, 'P3' => 0];
$statusCounts = [];
$firstPostId = 0;

$leadQuery = 'lead_detail.php?id=' . $leadId;
$relatedLeads = [];

if ($author !== '') {
    try {
        $relatedLeads = $db->select(
            'SELECT `id`, `platform`, `status`, `lead_score`, `created_at`
             FROM `' . $leadsTable . '`
             WHERE `author` = ? AND `id` <> ?
             ORDER BY `id` DESC LIMIT 6',
            [$author, $leadId]
        );
    } catch (Throwable $e) {
        $relatedLeads = [];
    }

    try {
        $priorityRows = $db->select(
            'SELECT `priority`, COUNT(*) AS `aggregate` FROM `' . $leadsTable . '` WHERE `author` = ? GROUP BY `priority`',
            [$author]
        );

        foreach ($priorityRows as $priorityRow) {
            $key = strtoupper((string) ($priorityRow['priority'] ?? ''));

            if (isset($priorityCounts[$key])) {
                $priorityCounts[$key] = (int) ($priorityRow['aggregate'] ?? 0);
            }
        }

        $statusRows = $db->select(
            'SELECT `status`, COUNT(*) AS `aggregate` FROM `' . $leadsTable . '` WHERE `author` = ? GROUP BY `status`',
            [$author]
        );

        foreach ($statusRows as $statusRow) {
            $key = strtolower((string) ($statusRow['status'] ?? ''));

            if ($key !== '') {
                $statusCounts[$key] = (int) ($statusRow['aggregate'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // Author rollup is best-effort only.
    }
}

$statusButtons = [
    ['label' => 'Mark Contacted', 'status' => 'contacted', 'style' => ''],
    ['label' => 'Mark Booked', 'status' => 'booked', 'style' => 'primary'],
    ['label' => 'Mark Not Interested', 'status' => 'not_interested', 'style' => 'danger'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lead #<?= e((string) $leadId) ?> - <?= e((string) Config::get('app.name', 'social-bot')) ?></title>
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

    .wrap { max-width: 1200px; margin: 0 auto; padding: 20px 18px 60px; }

    header.top {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
        justify-content: space-between;
        margin-bottom: 16px;
    }

    header.top h1 { font-size: 19px; margin: 0; }

    .layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 18px; align-items: start; }
    @media (max-width: 1000px) { .layout { grid-template-columns: minmax(0, 1fr); } }

    .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 16px; }
    .panel h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .7px; color: var(--muted); margin: 0; }
    .panel .head { padding: 12px 14px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; gap: 10px; align-items: center; flex-wrap: wrap; }
    .panel .body { padding: 14px; }

    .banner { border: 1px solid var(--border); border-left-width: 4px; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px; }
    .banner.success { border-left-color: var(--ok); background: rgba(34, 197, 94, .08); }
    .banner.error { border-left-color: var(--danger); background: rgba(239, 68, 68, .08); }
    .banner.warning { border-left-color: var(--p2); background: rgba(245, 158, 11, .08); }
    .banner.info { border-left-color: var(--accent); background: rgba(56, 189, 248, .08); }

    .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; border: 1px solid transparent; white-space: nowrap; }
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

    .meta { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; color: var(--muted); font-size: 12px; }

    .comment {
        white-space: pre-wrap;
        word-wrap: break-word;
        background: #0b1220;
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 12px;
        font-size: 14px;
        line-height: 1.55;
    }

    .kv { display: grid; grid-template-columns: 190px minmax(0, 1fr); gap: 7px 12px; }
    .kv .k { color: var(--muted); }
    .kv .v { word-wrap: break-word; }

    .score-bar { height: 9px; border-radius: 5px; background: #0b1220; border: 1px solid var(--border); overflow: hidden; min-width: 120px; }
    .score-bar > span { display: block; height: 100%; background: linear-gradient(90deg, #38bdf8, #22c55e); }

    textarea { background: #0b1220; color: var(--text); border: 1px solid var(--border); border-radius: 7px; padding: 9px; font: inherit; width: 100%; }
    textarea:focus { outline: 1px solid var(--accent); border-color: var(--accent); }

    input[type="text"] { background: #0b1220; color: var(--text); border: 1px solid var(--border); border-radius: 7px; padding: 7px 9px; font: inherit; width: 100%; }

    pre {
        background: #0b1220;
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 12px;
        overflow: auto;
        font-size: 12px;
        color: #cbd5e1;
        margin: 0;
        max-height: 460px;
    }

    button, .btn {
        background: #0b1220;
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: 7px;
        padding: 7px 12px;
        font: inherit;
        cursor: pointer;
        display: inline-block;
    }

    button:hover, .btn:hover { border-color: var(--accent); color: var(--accent); text-decoration: none; }
    button.primary { background: var(--accent); color: #04121f; border-color: var(--accent); font-weight: 600; }
    button.primary:hover { color: #04121f; filter: brightness(1.08); }
    button.danger { border-color: rgba(239, 68, 68, .6); color: var(--danger); }
    button.danger:hover { background: rgba(239, 68, 68, .12); color: var(--danger); }
    button.ok { border-color: rgba(34, 197, 94, .6); color: var(--ok); }

    .actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .actions form { display: inline; }

    .post { border: 1px solid var(--border); border-radius: 9px; padding: 12px; margin-bottom: 12px; background: #182234; }
    .post .content { white-space: pre-wrap; word-wrap: break-word; background: #0b1220; border: 1px solid var(--border); border-radius: 7px; padding: 10px; margin: 8px 0; }
    .post .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }

    .timeline { list-style: none; margin: 10px 0 0; padding: 0 0 0 14px; border-left: 2px solid var(--border); }
    .timeline li { position: relative; padding: 0 0 10px 12px; font-size: 12px; }
    .timeline li::before { content: ""; position: absolute; left: -19px; top: 5px; width: 8px; height: 8px; border-radius: 50%; background: var(--muted); }
    .timeline li.act-approved::before { background: var(--ok); }
    .timeline li.act-rejected::before { background: var(--danger); }
    .timeline li.act-sla_breached::before { background: var(--p2); }
    .timeline li.act-submitted::before { background: var(--accent); }

    .muted { color: var(--muted); }
    .small { font-size: 12px; }
    .nowrap { white-space: nowrap; }
    .empty { padding: 22px 14px; text-align: center; color: var(--muted); }

    .notice-center { max-width: 560px; margin: 80px auto; text-align: center; }
</style>
</head>
<body>
<div class="wrap">

    <header class="top">
        <div>
            <h1>Lead #<?= e((string) $leadId !== '0' ? (string) $leadId : '-') ?></h1>
            <div class="meta">
                <a href="inbox.php">&larr; Back to inbox</a>
                <?php if (is_array($currentLead)): ?>
                    <span>&middot;</span>
                    <span><?= e((string) $platform) ?></span>
                    <span>&middot;</span>
                    <span><?= e((string) ($currentLead['created_at'] ?? '')) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <?php if ($krayinLeadUrl !== ''): ?>
                <a class="btn" href="<?= e($krayinLeadUrl) ?>" target="_blank" rel="noopener">Krayin lead &nearr;</a>
            <?php endif; ?>
            <?php if ($mixpostPostsUrl !== ''): ?>
                <a class="btn" href="<?= e($mixpostPostsUrl) ?>" target="_blank" rel="noopener">Mixpost &nearr;</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($flashMessage !== ''): ?>
        <div class="banner <?= e(in_array($flashType, ['success', 'error', 'warning', 'info'], true) ? $flashType : 'info') ?>">
            <strong><?= e(ucfirst($flashType === '' ? 'info' : $flashType)) ?>:</strong> <?= e($flashMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($dbError !== ''): ?>
        <div class="banner error">
            <strong>Database unavailable &mdash; check .env</strong>
            <div class="small" style="margin-top:4px">
                Verify <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code> and run
                <code>php migrate.php</code>.
            </div>
            <div class="small muted" style="margin-top:6px"><?= e($dbError) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($notFound): ?>
        <div class="panel">
            <div class="body notice-center">
                <h2 style="color: var(--text); font-size: 18px; text-transform: none; letter-spacing: 0">404 &mdash; lead not found</h2>
                <p class="muted">No lead exists with id <code><?= e((string) $leadId) ?></code>.</p>
                <p><a class="btn" href="inbox.php">Back to the inbox</a></p>
            </div>
        </div>
    <?php elseif (is_array($currentLead)): ?>

        <div class="layout">
            <main>
                <section class="panel">
                    <div class="head">
                        <h2>Lead</h2>
                        <div class="meta">
                            <span class="<?= e(priority_class($priority)) ?>"><?= e($priority) ?></span>
                            <span class="<?= e(status_class($leadStatus)) ?>"><?= e(status_label($leadStatus)) ?></span>
                            <?php if (!empty($currentLead['is_qualified'])): ?>
                                <span class="badge badge-ok">qualified</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="body">
                        <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:baseline;margin-bottom:10px">
                            <div style="font-size:18px;font-weight:650">
                                <?php if ($profileUrl !== ''): ?>
                                    <a href="<?= e($profileUrl) ?>" target="_blank" rel="noopener"><?= e($author !== '' ? $author : 'unknown') ?></a>
                                <?php else: ?>
                                    <?= e($author !== '' ? $author : 'unknown') ?>
                                <?php endif; ?>
                            </div>
                            <span class="badge badge-info"><?= e($platform !== '' ? $platform : 'unknown') ?></span>
                            <span class="muted small">captured <?= e((string) ($currentLead['created_at'] ?? '')) ?></span>
                            <span class="muted small">updated <?= e((string) ($currentLead['updated_at'] ?? '')) ?></span>
                        </div>

                        <div class="comment"><?= e($commentText !== '' ? $commentText : '(no comment text stored)') ?></div>

                        <div class="meta" style="margin-top:10px">
                            <span>comment_id: <code><?= e((string) ($currentLead['comment_id'] ?? '')) ?></code></span>
                            <span>post_id: <code><?= e((string) ($currentLead['post_id'] ?? '')) ?></code></span>
                            <?php if (!empty($currentLead['google_ads_lead_id'])): ?>
                                <span>google_ads_lead_id: <code><?= e((string) $currentLead['google_ads_lead_id']) ?></code></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <div class="head"><h2>Classification</h2></div>
                    <div class="body">
                        <div class="kv">
                            <span class="k">Intent</span>
                            <span class="v"><?= e($intent) ?> <span class="muted small">(score <?= e((string) $intentScore) ?>/100)</span></span>

                            <span class="k">Sentiment</span>
                            <span class="v">
                                <span class="<?= e(sentiment_class($sentiment)) ?>"><?= e($sentiment) ?></span>
                                <span class="muted small">(<?= e(number_format($sentimentScore, 4)) ?>)</span>
                            </span>

                            <span class="k">Lead score</span>
                            <span class="v">
                                <strong><?= e((string) $leadScore) ?>/100</strong>
                                <div class="score-bar" style="margin-top:5px"><span style="width: <?= e((string) $leadScore) ?>%"></span></div>
                            </span>

                            <span class="k">Priority</span>
                            <span class="v"><span class="<?= e(priority_class($priority)) ?>"><?= e($priority) ?></span></span>

                            <span class="k">Qualified</span>
                            <span class="v"><?= e(!empty($currentLead['is_qualified']) ? 'yes' : 'no') ?></span>

                            <span class="k">Routing queue</span>
                            <span class="v"><?= e((string) ($currentLead['routing_queue'] ?? '') !== '' ? (string) $currentLead['routing_queue'] : '-') ?></span>

                            <span class="k">Department</span>
                            <span class="v"><?= e((string) ($currentLead['department'] ?? '') !== '' ? (string) $currentLead['department'] : '-') ?></span>

                            <span class="k">Area</span>
                            <span class="v"><?= e((string) ($currentLead['area'] ?? '') !== '' ? (string) $currentLead['area'] : '-') ?></span>

                            <span class="k">Follow-up date</span>
                            <span class="v"><?= e((string) ($currentLead['follow_up_date'] ?? '') !== '' ? (string) $currentLead['follow_up_date'] : '-') ?></span>

                            <span class="k">Appointment slot</span>
                            <span class="v"><?= e((string) ($currentLead['appointment_slot'] ?? '') !== '' ? (string) $currentLead['appointment_slot'] : '-') ?></span>

                            <span class="k">Summary</span>
                            <span class="v"><?= e((string) ($currentLead['summary'] ?? '') !== '' ? (string) $currentLead['summary'] : '-') ?></span>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <div class="head">
                        <h2>Suggested reply</h2>
                        <button type="button" class="btn" onclick="copyReply(this)">Copy</button>
                    </div>
                    <div class="body">
                        <textarea id="suggested-reply" rows="6" readonly><?= e($suggestedReply) ?></textarea>
                        <p class="small muted" style="margin:8px 0 0">
                            Read-only copy of <code>leads.suggested_reply</code>. Use &ldquo;Reply via platform&rdquo; below to
                            send it (Instagram) or queue it for a manual send.
                        </p>
                    </div>
                </section>

                <section class="panel">
                    <div class="head">
                        <h2>Raw classification</h2>
                        <span class="small muted">leads row as JSON</span>
                    </div>
                    <div class="body">
                        <pre><?= e($rawJson) ?></pre>
                    </div>
                </section>

                <section class="panel">
                    <div class="head">
                        <h2>Social posts &amp; approvals</h2>
                        <span class="small muted"><?= e((string) count($socialPosts)) ?> post(s) linked via lead_id</span>
                    </div>
                    <div class="body">
                        <?php if ($socialPosts === []): ?>
                            <div class="empty">No outbound posts are linked to this lead yet.</div>
                        <?php else: ?>
                            <?php foreach ($socialPosts as $postRow): ?>
                                <?php
                                $postId = (int) ($postRow['id'] ?? 0);
                                $postStatus = strtolower((string) ($postRow['status'] ?? 'draft'));
                                $postPlatforms = Json::fromColumn($postRow['platforms'] ?? null);
                                $postTargets = Json::fromColumn($postRow['target_accounts'] ?? null);
                                $postTimeline = $auditTrail[$postId] ?? [];
                                ?>
                                <div class="post">
                                    <div class="row">
                                        <strong>Post #<?= e((string) $postId) ?></strong>
                                        <span class="<?= e(status_class($postStatus)) ?>"><?= e(status_label($postStatus)) ?></span>
                                        <?php if (!empty($postRow['mixpost_post_uuid'])): ?>
                                            <span class="badge badge-muted">mixpost <?= e((string) $postRow['mixpost_post_uuid']) ?></span>
                                        <?php endif; ?>
                                        <span class="small muted">requested by <?= e((string) ($postRow['requested_by'] ?? '-')) ?></span>
                                        <span class="small muted">created <?= e((string) ($postRow['created_at'] ?? '')) ?></span>
                                    </div>

                                    <?php if ($postPlatforms !== []): ?>
                                        <div class="small muted" style="margin-top:4px">
                                            platforms: <?= e(implode(', ', array_map('strval', $postPlatforms))) ?>
                                            <?php if ($postTargets !== []): ?>
                                                &middot; accounts: <?= e(Json::encode($postTargets)) ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="content"><?= e((string) ($postRow['content'] ?? '')) ?></div>

                                    <div class="small muted">
                                        <div>attempts: <?= e((string) (int) ($postRow['attempts'] ?? 0)) ?>
                                            <?php if (!empty($postRow['scheduled_for'])): ?>
                                                &middot; scheduled: <?= e((string) $postRow['scheduled_for']) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($postRow['approved_by'])): ?>
                                                &middot; approved by <?= e((string) $postRow['approved_by']) ?>
                                                at <?= e((string) ($postRow['approved_at'] ?? '')) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($postRow['published_at'])): ?>
                                                &middot; published <?= e((string) $postRow['published_at']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($postRow['rejection_reason'])): ?>
                                            <div style="color: var(--danger)">rejected: <?= e((string) $postRow['rejection_reason']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($postRow['last_error'])): ?>
                                            <div style="color: var(--danger)">last error: <?= e((string) $postRow['last_error']) ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($postTimeline !== []): ?>
                                        <ul class="timeline">
                                            <?php foreach ($postTimeline as $auditRow): ?>
                                                <?php $auditAction = strtolower((string) ($auditRow['action'] ?? '')); ?>
                                                <li class="act-<?= e($auditAction) ?>">
                                                    <strong><?= e($auditAction !== '' ? $auditAction : 'event') ?></strong>
                                                    by <?= e((string) ($auditRow['actor'] ?? 'unknown')) ?>
                                                    <span class="muted">(<?= e((string) ($auditRow['level_name'] ?? 'level')) ?>)</span>
                                                    <span class="muted">&middot; <?= e((string) ($auditRow['created_at'] ?? '')) ?></span>
                                                    <?php if (trim((string) ($auditRow['remarks'] ?? '')) !== ''): ?>
                                                        <div class="muted"><?= e((string) $auditRow['remarks']) ?></div>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <div class="small muted" style="margin-top:6px">No approval audit rows recorded yet.</div>
                                    <?php endif; ?>

                                    <div class="actions" style="margin-top:10px">
                                        <?php if (in_array($postStatus, ['pending_approval', 'draft', 'failed'], true)): ?>
                                            <form method="post" action="<?= e($leadQuery) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="id" value="<?= e((string) $leadId) ?>">
                                                <input type="hidden" name="action" value="approve_post">
                                                <input type="hidden" name="post_id" value="<?= e((string) $postId) ?>">
                                                <input type="hidden" name="remarks" value="">
                                                <button type="submit" class="ok">Approve</button>
                                            </form>
                                            <form method="post" action="<?= e($leadQuery) ?>"
                                                  onsubmit="var r = window.prompt('Reason for rejection (optional):', ''); if (r === null) { return false; } this.remarks.value = r; return true;">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="id" value="<?= e((string) $leadId) ?>">
                                                <input type="hidden" name="action" value="reject_post">
                                                <input type="hidden" name="post_id" value="<?= e((string) $postId) ?>">
                                                <input type="hidden" name="remarks" value="">
                                                <button type="submit" class="danger">Reject</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($postStatus === 'approved'): ?>
                                            <form method="post" action="<?= e($leadQuery) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="id" value="<?= e((string) $leadId) ?>">
                                                <input type="hidden" name="action" value="publish_post">
                                                <input type="hidden" name="post_id" value="<?= e((string) $postId) ?>">
                                                <button type="submit" class="primary">Publish via Mixpost</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </main>

            <aside>
                <section class="panel">
                    <div class="head"><h2>Actions</h2></div>
                    <div class="body">
                        <form method="post" action="<?= e($leadQuery) ?>" id="lead-actions">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= e((string) $leadId) ?>">
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="status" value="">

                            <div class="actions" style="margin-bottom:12px">
                                <?php foreach ($statusButtons as $button): ?>
                                    <button type="submit"
                                            class="<?= e($button['style']) ?>"
                                            formaction="<?= e($leadQuery) ?>"
                                            onclick="this.form.elements['action'].value = 'status'; this.form.elements['status'].value = <?= e(Json::encode($button['status'])) ?>">
                                        <?= e($button['label']) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <hr style="border:0;border-top:1px solid var(--border);margin:14px 0">

                            <button type="submit" class="primary" style="width:100%"
                                    formaction="<?= e($leadQuery) ?>"
                                    onclick="this.form.elements['action'].value = 'create_task'">
                                Create Task<?= $krayinLeadId > 0 ? ' in Krayin' : ' (needs Krayin lead)' ?>
                            </button>

                            <hr style="border:0;border-top:1px solid var(--border);margin:14px 0">

                            <label class="small muted" for="reply">Reply via platform</label>
                            <textarea id="reply" name="reply" rows="4" placeholder="Write the public reply..."></textarea>
                            <div class="small muted" style="margin:6px 0 8px">
                                <?= $platform === 'instagram'
                                    ? 'Instagram: sent immediately through InstagramIngestor::replyToComment().'
                                    : 'Non-Instagram: stored in leads.suggested_reply and flagged for a manual send.' ?>
                            </div>
                            <button type="submit"
                                    formaction="<?= e($leadQuery) ?>"
                                    onclick="this.form.elements[&#39;action&#39;].value = &#39;reply&#39;">Send / queue reply</button>

                            <hr style="border:0;border-top:1px solid var(--border);margin:14px 0">

                            <button type="submit"
                                    formaction="<?= e($leadQuery) ?>"
                                    onclick="this.form.elements[&#39;action&#39;].value = &#39;reclassify&#39;; this.form.elements[&#39;reply&#39;].value = &#39;&#39;;">
                                Re-classify with DeepSeek
                            </button>
                        </form>
                    </div>
                </section>

                <section class="panel">
                    <div class="head"><h2>CRM &amp; publishing</h2></div>
                    <div class="body small">
                        <div class="kv" style="grid-template-columns: 130px minmax(0,1fr)">
                            <span class="k">Krayin lead id</span>
                            <span class="v"><?= e($krayinLeadId > 0 ? (string) $krayinLeadId : 'not linked') ?></span>

                            <span class="k">Krayin lead</span>
                            <span class="v">
                                <?php if ($krayinLeadUrl !== ''): ?>
                                    <a href="<?= e($krayinLeadUrl) ?>" target="_blank" rel="noopener">open in Krayin &nearr;</a>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </span>

                            <span class="k">Mixpost</span>
                            <span class="v">
                                <?php if ($mixpostPostsUrl !== ''): ?>
                                    <a href="<?= e($mixpostPostsUrl) ?>" target="_blank" rel="noopener">workspace posts &nearr;</a>
                                <?php else: ?>
                                    <span class="muted">not configured</span>
                                <?php endif; ?>
                            </span>

                            <span class="k">Approval SLA</span>
                            <span class="v"><?= e((string) (int) Config::get('approval.sla_hours', 24)) ?>h</span>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <div class="head"><h2>Same author</h2></div>
                    <div class="body small">
                        <?php if ($author === ''): ?>
                            <span class="muted">No author recorded.</span>
                        <?php else: ?>
                            <div class="kv" style="grid-template-columns: 90px minmax(0,1fr)">
                                <span class="k">P1 / P2 / P3</span>
                                <span class="v"><?= e((string) $priorityCounts['P1']) ?> / <?= e((string) $priorityCounts['P2']) ?> / <?= e((string) $priorityCounts['P3']) ?></span>
                                <span class="k">Statuses</span>
                                <span class="v">
                                    <?php if ($statusCounts === []): ?>
                                        <span class="muted">-</span>
                                    <?php else: ?>
                                        <?php foreach ($statusCounts as $statusKey => $statusCount): ?>
                                            <span class="badge badge-muted" style="margin:0 3px 3px 0"><?= e($statusKey) ?>: <?= e((string) $statusCount) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if ($relatedLeads !== []): ?>
                                <ul style="list-style:none;padding:0;margin:10px 0 0">
                                    <?php foreach ($relatedLeads as $relatedLead): ?>
                                        <li style="display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-bottom:1px dashed rgba(51,65,85,.8)">
                                            <a href="lead_detail.php?id=<?= e((string) (int) ($relatedLead['id'] ?? 0)) ?>">
                                                #<?= e((string) (int) ($relatedLead['id'] ?? 0)) ?>
                                            </a>
                                            <span class="muted">
                                                <?= e((string) ($relatedLead['status'] ?? '')) ?>
                                                &middot; <?= e((string) (int) ($relatedLead['lead_score'] ?? 0)) ?>
                                                &middot; <?= e((string) ($relatedLead['created_at'] ?? '')) ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="muted" style="margin:8px 0 0">No other leads from this author.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </aside>
        </div>
    <?php endif; ?>
</div>

<script>
    function copyReply(button) {
        var field = document.getElementById('suggested-reply');

        if (!field) {
            return;
        }

        var done = function () {
            var original = button.textContent;
            button.textContent = 'Copied';
            window.setTimeout(function () { button.textContent = original; }, 1400);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(field.value).then(done, function () { field.select(); document.execCommand('copy'); done(); });
            return;
        }

        field.removeAttribute('readonly');
        field.select();
        try { document.execCommand('copy'); } catch (err) { /* clipboard blocked */ }
        field.setAttribute('readonly', 'readonly');
        done();
    }
</script>
</body>
</html>
