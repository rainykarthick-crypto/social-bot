<?php

declare(strict_types=1);

namespace SocialBot\Support;

/**
 * Small channel-aware JSON-line logger (PSR-3 shaped, no hard dependency on
 * psr/log).  One file per channel per day under storage/logs.
 */
final class Logger
{
    private const LEVELS = [
        'debug' => 10,
        'info' => 20,
        'notice' => 25,
        'warning' => 30,
        'error' => 40,
        'critical' => 50,
    ];

    /** @var array<string, self> */
    private static array $channels = [];

    private string $logFile;

    private int $minLevel;

    private function __construct(
        private readonly string $channel,
        private readonly bool $mirrorToStderr,
    ) {
        $dir = (string) Config::get('paths.logs', sys_get_temp_dir());
        $this->logFile = $dir . '/' . $this->channel . '-' . date('Y-m-d') . '.log';
        $this->minLevel = self::LEVELS[(string) Config::get('app.log_level', 'debug')] ?? 10;
    }

    public static function channel(string $channel = 'app'): self
    {
        return self::$channels[$channel] ??= new self($channel, PHP_SAPI === 'cli');
    }

    public static function debug(string $message, array $context = []): void
    {
        self::channel()->write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::channel()->write('info', $message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        self::channel()->write('notice', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::channel()->write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::channel()->write('error', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::channel()->write('critical', $message, $context);
    }

    public function write(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);

        if ((self::LEVELS[$level] ?? 20) < $this->minLevel) {
            return;
        }

        $record = [
            'ts' => date('c'),
            'channel' => $this->channel,
            'level' => strtoupper($level),
            'message' => $message,
        ];

        if ($context !== []) {
            $record['context'] = $this->normalise($context);
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($line === false) {
            $line = json_encode(['ts' => date('c'), 'channel' => $this->channel, 'level' => 'ERROR', 'message' => $message]);
        }

        @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($this->mirrorToStderr && (self::LEVELS[$level] ?? 20) >= self::LEVELS['info']) {
            fwrite(STDERR, $line . PHP_EOL);
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normalise(array $context): array
    {
        array_walk_recursive($context, static function (&$value): void {
            if ($value instanceof \Throwable) {
                $value = $value::class . ': ' . $value->getMessage();
            } elseif (is_object($value)) {
                $value = method_exists($value, '__toString') ? (string) $value : $value::class;
            } elseif (is_string($value) && strlen($value) > 4000) {
                $value = substr($value, 0, 4000) . '...[truncated]';
            }
        });

        return $context;
    }

    public function file(): string
    {
        return $this->logFile;
    }
}
