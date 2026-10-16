<?php

declare(strict_types=1);

/**
 * Syntax gate: runs `php -l` over every PHP file in the project.
 *
 *   php tools/lint.php            lint everything (vendor/ excluded)
 *   php tools/lint.php --all      include vendor/
 *   php tools/lint.php src public lint only the given paths
 *
 * Exit code 0 = clean, 1 = at least one file failed to parse.
 * Run this in CI, or via `composer lint`.
 */

$root = dirname(__DIR__);
$arguments = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$includeVendor = in_array('--all', $argv, true);

$excluded = $includeVendor ? [] : ['/vendor/', '/storage/', '/.git/', '/node_modules/', '/.tmp-tests/'];

$paths = $arguments === [] ? [$root] : array_map(static fn (string $p): string => str_starts_with($p, '/') || preg_match('#^[A-Za-z]:#', $p) === 1 ? $p : $root . '/' . $p, $arguments);

$files = [];

foreach ($paths as $path) {
    if (is_file($path)) {
        $files[] = $path;

        continue;
    }

    if (!is_dir($path)) {
        fwrite(STDERR, "[lint] not found: {$path}\n");

        continue;
    }

    $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);

    // Prune excluded directories *before* descending, so a busy vendor/ (or a
    // concurrent composer install) can never break the walk.
    $filtered = new RecursiveCallbackFilterIterator(
        $directory,
        static function (SplFileInfo $current) use ($excluded): bool {
            if (!$current->isDir()) {
                return true;
            }

            $normalised = str_replace('\\', '/', $current->getPathname());

            foreach ($excluded as $needle) {
                if (str_contains($normalised . '/', $needle)) {
                    return false;
                }
            }

            return true;
        }
    );

    $iterator = new RecursiveIteratorIterator($filtered);

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $files[] = $file->getPathname();
    }
}

sort($files);

if ($files === []) {
    fwrite(STDERR, "[lint] no PHP files found\n");
    exit(1);
}

$binary = PHP_BINARY;
$failures = [];
$checked = 0;

echo '[lint] PHP ' . PHP_VERSION . ' - checking ' . count($files) . " file(s)\n";

foreach ($files as $file) {
    $output = [];
    $exitCode = 0;

    if (function_exists('exec')) {
        exec(escapeshellarg($binary) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
    } else {
        // exec() disabled: fall back to an in-process token check.
        $exitCode = lintWithoutExec($file);
        $output = $exitCode === 0 ? [] : ['token_get_all() could not parse the file'];
    }

    $checked++;

    if ($exitCode !== 0) {
        $failures[$file] = implode("\n", $output);
        echo '  FAIL ' . relative($file, $root) . "\n";
    }
}

echo "[lint] checked {$checked} file(s), " . count($failures) . " failure(s)\n";

foreach ($failures as $file => $message) {
    echo "\n--- " . relative($file, $root) . " ---\n" . $message . "\n";
}

exit($failures === [] ? 0 : 1);

/**
 * Crude fallback: token_get_all() with TOKEN_PARSE throws on a syntax error.
 */
function lintWithoutExec(string $file): int
{
    try {
        token_get_all((string) file_get_contents($file), TOKEN_PARSE);

        return 0;
    } catch (Throwable) {
        return 1;
    }
}

function relative(string $path, string $root): string
{
    return ltrim(str_replace('\\', '/', $path), '/') === ltrim(str_replace('\\', '/', $root), '/')
        ? $path
        : str_replace(str_replace('\\', '/', $root) . '/', '', str_replace('\\', '/', $path));
}
