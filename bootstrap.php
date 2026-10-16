<?php

declare(strict_types=1);

/**
 * Social Bot bootstrap.
 *
 * Every entry point (worker.php, webhook.php, audit.php, digest.php,
 * public/*.php) requires this file first.  It is deliberately dependency
 * tolerant: when `vendor/` is missing a small PSR-4 fallback autoloader keeps
 * the CLI tooling and the dashboard working, and the optional SDKs are only
 * used when their classes actually exist.
 */

use SocialBot\Support\App;
use SocialBot\Support\Config;
use SocialBot\Support\Env;
use SocialBot\Support\Logger;

$socialBotRoot = __DIR__;

if (!defined('SOCIAL_BOT_ROOT')) {
    define('SOCIAL_BOT_ROOT', $socialBotRoot);
}

$socialBotAutoload = $socialBotRoot . '/vendor/autoload.php';

if (is_file($socialBotAutoload)) {
    require $socialBotAutoload;
} else {
    spl_autoload_register(static function (string $class) use ($socialBotRoot): void {
        $prefix = 'SocialBot\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $socialBotRoot . '/src/' . $relative . '.php';

        if (is_file($file)) {
            require $file;
        }
    });
}

Env::load($socialBotRoot . '/.env');
Config::boot($socialBotRoot);

date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Kolkata'));
mb_internal_encoding('UTF-8');

foreach (['storage', 'storage/logs', 'storage/cache', 'storage/digests'] as $dir) {
    $path = $socialBotRoot . '/' . $dir;

    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

set_exception_handler(static function (Throwable $e) use ($socialBotRoot): void {
    $line = sprintf(
        '[fatal] %s: %s in %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );

    @file_put_contents(
        $socialBotRoot . '/storage/logs/fatal-' . date('Y-m-d') . '.log',
        $line . PHP_EOL . $e->getTraceAsString() . PHP_EOL,
        FILE_APPEND
    );

    Logger::critical($e->getMessage(), [
        'exception' => $e::class,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $line . PHP_EOL);
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    echo json_encode(['ok' => false, 'error' => 'internal_error'], JSON_UNESCAPED_SLASHES);
});

return App::boot($socialBotRoot);
