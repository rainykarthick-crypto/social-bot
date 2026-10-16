<?php

declare(strict_types=1);

namespace SocialBot\Support;

use SocialBot\AI\BrandVoiceRAG;
use SocialBot\AI\CommandParser;
use SocialBot\AI\DeepSeekClassifier;
use SocialBot\CRM\KrayinClient;
use SocialBot\Leads\LeadScorer;
use SocialBot\Leads\SmartRouter;
use SocialBot\Social\MixpostManager;
use SocialBot\Support\Messages\ProcessCommentBatchMessage;
use SocialBot\Workflow\CommentPipeline;
use SocialBot\Workflow\ConditionalApproval;
use SocialBot\Workflow\PostApprovalWorkflow;

/**
 * Tiny service container.
 *
 * Everything is built lazily so that a missing credential or a missing optional
 * package degrades one integration instead of taking the whole application down.
 */
final class App
{
    private static string $root = '';

    private static bool $booted = false;

    /** @var array<string, mixed> */
    private static array $instances = [];

    public static function boot(string $root): string
    {
        self::$root = $root;
        self::$booted = true;

        return $root;
    }

    public static function root(): string
    {
        if (self::$root === '') {
            self::$root = dirname(__DIR__, 2);
        }

        return self::$root;
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }

    public static function path(string $relative = ''): string
    {
        return self::root() . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
    }

    public static function log(string $channel = 'app'): Logger
    {
        return Logger::channel($channel);
    }

    /**
     * @template T
     * @param callable():T $factory
     * @return T
     */
    public static function get(string $id, ?callable $factory = null): mixed
    {
        if (array_key_exists($id, self::$instances)) {
            return self::$instances[$id];
        }

        if ($factory === null) {
            throw new \RuntimeException('Unknown container entry: ' . $id);
        }

        return self::$instances[$id] = $factory();
    }

    public static function has(string $id): bool
    {
        return array_key_exists($id, self::$instances);
    }

    public static function set(string $id, mixed $instance): void
    {
        self::$instances[$id] = $instance;
    }

    public static function forget(string $id): void
    {
        unset(self::$instances[$id]);
    }

    public static function db(): Database
    {
        return self::get('db', static fn (): Database => new Database());
    }

    public static function cache(): Cache
    {
        return self::get('cache', static fn (): Cache => new Cache());
    }

    public static function usage(): LlmUsage
    {
        return self::get('usage', static fn (): LlmUsage => new LlmUsage(self::db()));
    }

    public static function sentiment(): SentimentFilter
    {
        return self::get('sentiment', static fn (): SentimentFilter => new SentimentFilter());
    }

    public static function bus(): Bus
    {
        return self::get('bus', static function (): Bus {
            $bus = new Bus();
            $bus->register(ProcessCommentBatchMessage::class, static function (ProcessCommentBatchMessage $message): array {
                return self::pipeline()->processBatch($message->limit, $message->dryRun);
            });

            return $bus;
        });
    }

    public static function classifier(): DeepSeekClassifier
    {
        return self::get('classifier', static fn (): DeepSeekClassifier => DeepSeekClassifier::fromConfig());
    }

    public static function commandParser(): CommandParser
    {
        return self::get('command_parser', static fn (): CommandParser => CommandParser::fromConfig());
    }

    public static function brandVoice(): BrandVoiceRAG
    {
        return self::get('brand_voice', static fn (): BrandVoiceRAG => new BrandVoiceRAG(
            (string) Config::get('brand_voice.path'),
            (int) Config::get('brand_voice.top_k', 3)
        ));
    }

    public static function scorer(): LeadScorer
    {
        return self::get('scorer', static fn (): LeadScorer => new LeadScorer());
    }

    public static function router(): SmartRouter
    {
        return self::get('router', static fn (): SmartRouter => new SmartRouter());
    }

    public static function krayin(): KrayinClient
    {
        return self::get('krayin', static fn (): KrayinClient => new KrayinClient(self::db()));
    }

    public static function mixpost(): MixpostManager
    {
        return self::get('mixpost', static fn (): MixpostManager => new MixpostManager(self::db()));
    }

    public static function approvals(): ConditionalApproval
    {
        return self::get('conditional_approval', static fn (): ConditionalApproval => new ConditionalApproval());
    }

    public static function workflow(): PostApprovalWorkflow
    {
        return self::get('workflow', static fn (): PostApprovalWorkflow => new PostApprovalWorkflow(
            self::db(),
            self::krayin(),
            self::mixpost(),
            self::approvals()
        ));
    }

    public static function pipeline(): CommentPipeline
    {
        return self::get('comment_pipeline', static fn (): CommentPipeline => new CommentPipeline(
            self::db(),
            self::sentiment(),
            self::classifier(),
            self::scorer(),
            self::router(),
            self::krayin()
        ));
    }

    /**
     * Credentials that still have to be supplied by an operator.  Used by
     * audit.php, the dashboard banner and the README "credentials pending" list.
     *
     * @return array<int, string>
     */
    public static function pendingCredentials(): array
    {
        $checks = [
            'DEEPSEEK_API_KEY' => (string) Config::get('deepseek.api_key', ''),
            'KRAYIN_API_TOKEN' => (string) Config::get('krayin.token', ''),
            'MIXPOST_API_TOKEN' => (string) Config::get('mixpost.token', ''),
            'MIXPOST_WORKSPACE_UUID' => (string) Config::get('mixpost.workspace_uuid', ''),
            'META_VERIFY_TOKEN' => (string) Config::get('meta.verify_token', ''),
            'META_PAGE_ACCESS_TOKEN' => (string) Config::get('meta.page_access_token', ''),
            'INSTAGRAM_ACCESS_TOKEN' => (string) Config::get('instagram.access_token', ''),
            'GOOGLE_ADS_WEBHOOK_KEY' => (string) Config::get('google_ads.webhook_key', ''),
            'WHATSAPP_TOKEN' => (string) Config::get('whatsapp.token', ''),
            'DIGEST_TO' => (string) Config::get('digest.to', ''),
        ];

        $missing = [];

        foreach ($checks as $name => $value) {
            if (trim($value) === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, bool|string>
     */
    public static function readiness(): array
    {
        return [
            'database' => self::db()->ping(),
            'deepseek' => (string) Config::get('deepseek.api_key', '') !== '',
            'ollama_fallback_configured' => (string) Config::get('ollama.url', '') !== '',
            'krayin' => (string) Config::get('krayin.token', '') !== '',
            'mixpost' => (string) Config::get('mixpost.token', '') !== '',
            'vader' => self::sentiment()->usingVader(),
            'messenger' => self::bus()->usesMessenger(),
            'illuminate_database' => self::db()->usingIlluminate(),
            'cache_backend' => self::cache()->usingRedis() ? 'redis' : 'files',
        ];
    }
}
