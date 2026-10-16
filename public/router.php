<?php

declare(strict_types=1);

/**
 * Router for the built-in PHP development server:
 *
 *   php -S 127.0.0.1:8080 -t public public/router.php
 *   composer serve
 *
 * Sends /webhook/* to the unified webhook entry point and serves everything else
 * from public/ (returning false lets the built-in server handle real files).
 */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/webhook')) {
    require __DIR__ . '/../webhook.php';

    return true;
}

$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';

    return true;
}

if (is_file(__DIR__ . $path . '.php')) {
    require __DIR__ . $path . '.php';

    return true;
}

http_response_code(404);
header('Content-Type: text/plain');
echo 'Not found: ' . $path . PHP_EOL;

return true;
