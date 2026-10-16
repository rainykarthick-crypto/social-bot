<?php

declare(strict_types=1);

use SocialBot\Support\App;
use SocialBot\Support\Config;

/**
 * Entry point: the lead inbox is the dashboard's front page.
 *
 * Kept intentionally tiny - it only boots the application (so the same
 * configuration / auth rules apply), then hands over to inbox.php.
 */

$root = require __DIR__ . '/../bootstrap.php';

$appName = (string) Config::get('app.name', 'social-bot');

// $root is the project root returned by bootstrap.php; App::root() must agree
// with it. Handy for the fallback page title below.
$rootPath = App::root();

if (!headers_sent()) {
    header('Location: inbox.php', true, 302);
    header('Content-Type: text/html; charset=UTF-8');
}

echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
    . '<title>' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . '</title></head>'
    . '<body style="background:#0f172a;color:#e2e8f0;font-family:system-ui,sans-serif;padding:24px">'
    . '<p>Redirecting to the lead inbox&hellip;</p>'
    . '<p><a style="color:#38bdf8" href="inbox.php">Open the lead inbox</a></p>'
    . '<p style="color:#94a3b8;font-size:12px">root: '
    . htmlspecialchars($rootPath, ENT_QUOTES, 'UTF-8') . '</p>'
    . '</body></html>';
