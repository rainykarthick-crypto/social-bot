<?php

declare(strict_types=1);

namespace SocialBot\Support;

/**
 * Immutable-ish configuration store assembled from the environment.
 *
 * Values are addressed with dot notation: Config::get('db.host').
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    private static string $root = '';

    public static function boot(string $root): void
    {
        self::$root = $root;

        self::$items = [
            'app' => [
                'name' => Env::get('APP_NAME', 'social-bot'),
                'env' => Env::get('APP_ENV', 'production'),
                'debug' => Env::bool('APP_DEBUG', false),
                'url' => Env::get('APP_URL', 'http://localhost:8080'),
                'timezone' => Env::get('APP_TIMEZONE', 'Asia/Kolkata'),
            ],
            'paths' => [
                'root' => $root,
                'src' => $root . '/src',
                'storage' => $root . '/storage',
                'logs' => $root . '/storage/logs',
                'cache' => $root . '/storage/cache',
                'digests' => $root . '/storage/digests',
                'prompts' => $root . '/prompts',
                'migrations' => $root . '/migrations',
                'public' => $root . '/public',
            ],
            'db' => [
                'driver' => 'mysql',
                'host' => Env::get('DB_HOST', 'localhost'),
                'port' => Env::int('DB_PORT', 3306),
                'database' => Env::get('DB_NAME', 'social_bot'),
                'username' => Env::get('DB_USER', 'bot'),
                'password' => Env::get('DB_PASS', ''),
                'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => Env::get('DB_PREFIX', ''),
                'timeout' => 5,
            ],
            'redis' => [
                'host' => Env::get('REDIS_HOST', '127.0.0.1'),
                'port' => Env::int('REDIS_PORT', 6379),
                'password' => Env::get('REDIS_PASSWORD', ''),
                'database' => Env::int('REDIS_DB', 0),
            ],
            'deepseek' => [
                'api_key' => Env::get('DEEPSEEK_API_KEY', ''),
                'base_url' => Env::get('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
                'transport' => Env::get('DEEPSEEK_TRANSPORT', 'http'),
                'model_fast' => Env::get('DEEPSEEK_MODEL_FAST', 'deepseek-v4-flash'),
                'model_reasoning' => Env::get('DEEPSEEK_MODEL_REASONING', 'deepseek-v4-pro'),
                'timeout' => Env::int('DEEPSEEK_TIMEOUT', 60),
                'max_tokens' => Env::int('DEEPSEEK_MAX_TOKENS', 400),
                'temperature' => 1.0,
                'batch_size' => max(1, Env::int('DEEPSEEK_BATCH_SIZE', 15)),
                'daily_token_budget' => Env::int('DEEPSEEK_DAILY_TOKEN_BUDGET', 2000000),
                // USD per 1M tokens; used for the cost_estimate audit column only.
                'price_in' => Env::float('DEEPSEEK_PRICE_IN', 0.28),
                'price_cached_in' => Env::float('DEEPSEEK_PRICE_CACHED_IN', 0.028),
                'price_out' => Env::float('DEEPSEEK_PRICE_OUT', 0.42),
            ],
            'ollama' => [
                'url' => rtrim((string) Env::get('OLLAMA_URL', 'http://localhost:11434'), '/'),
                'model' => Env::get('OLLAMA_MODEL', 'llama3.2:3b'),
                'timeout' => Env::int('OLLAMA_TIMEOUT', 120),
            ],
            'krayin' => [
                'api_url' => rtrim((string) Env::get('KRAYIN_API_URL', 'http://localhost/krayin/public'), '/'),
                'token' => Env::get('KRAYIN_API_TOKEN', ''),
                'default_approver_id' => Env::int('KRAYIN_DEFAULT_APPROVER_ID', 1),
                'default_user_id' => Env::int('KRAYIN_DEFAULT_USER_ID', 1),
                'pipeline_id' => Env::int('KRAYIN_PIPELINE_ID', 1),
                'webhook_secret' => Env::get('KRAYIN_WEBHOOK_SECRET', ''),
                'timeout' => 30,
            ],
            'mixpost' => [
                'url' => rtrim((string) Env::get('MIXPOST_URL', 'http://localhost/mixpost'), '/'),
                // Mixpost is a Laravel app: the API lives under this prefix.
                'api_prefix' => '/' . trim((string) Env::get('MIXPOST_API_PREFIX', '/mixpost/api'), '/'),
                'token' => Env::get('MIXPOST_API_TOKEN', ''),
                'workspace_uuid' => Env::get('MIXPOST_WORKSPACE_UUID', ''),
                'timezone' => Env::get('MIXPOST_TIMEZONE', 'Asia/Kolkata'),
                'timeout' => 30,
            ],
            'meta' => [
                'verify_token' => Env::get('META_VERIFY_TOKEN', ''),
                'page_access_token' => Env::get('META_PAGE_ACCESS_TOKEN', ''),
                'app_id' => Env::get('META_APP_ID', ''),
                'app_secret' => Env::get('META_APP_SECRET', ''),
                'graph_version' => Env::get('META_GRAPH_VERSION', 'v21.0'),
            ],
            'instagram' => [
                'access_token' => Env::get('INSTAGRAM_ACCESS_TOKEN', ''),
                'app_id' => Env::get('INSTAGRAM_APP_ID', ''),
                'app_secret' => Env::get('INSTAGRAM_APP_SECRET', ''),
                'business_account_id' => Env::get('INSTAGRAM_BUSINESS_ACCOUNT_ID', ''),
                'graph_version' => Env::get('META_GRAPH_VERSION', 'v21.0'),
                // graph.facebook.com for Facebook-Login tokens,
                // graph.instagram.com for Instagram-Login tokens.
                'graph_base' => Env::get('INSTAGRAM_GRAPH_BASE', 'https://graph.facebook.com'),
                'timeout' => 30,
            ],
            'whatsapp' => [
                'phone_id' => Env::get('WHATSAPP_PHONE_ID', ''),
                'token' => Env::get('WHATSAPP_TOKEN', ''),
                'verify_token' => Env::get('WHATSAPP_VERIFY_TOKEN', ''),
                'graph_version' => Env::get('META_GRAPH_VERSION', 'v21.0'),
            ],
            'google_ads' => [
                'webhook_key' => Env::get('GOOGLE_ADS_WEBHOOK_KEY', ''),
                'developer_token' => Env::get('GOOGLE_ADS_DEVELOPER_TOKEN', ''),
                'customer_id' => Env::get('GOOGLE_ADS_CUSTOMER_ID', ''),
                'login_customer_id' => Env::get('GOOGLE_ADS_LOGIN_CUSTOMER_ID', ''),
            ],
            'twitter' => [
                'consumer_key' => Env::get('TWITTER_CONSUMER_KEY', ''),
                'consumer_secret' => Env::get('TWITTER_CONSUMER_SECRET', ''),
                'access_token' => Env::get('TWITTER_ACCESS_TOKEN', ''),
                'access_secret' => Env::get('TWITTER_ACCESS_SECRET', ''),
                'bearer_token' => Env::get('TWITTER_BEARER_TOKEN', ''),
            ],
            'platform_accounts' => Env::json('PLATFORM_ACCOUNT_MAP', [
                'instagram' => 1,
                'linkedin' => 2,
                'twitter' => 3,
                'facebook' => 4,
            ]),
            'dashboard' => [
                'user' => Env::get('DASHBOARD_USER', ''),
                'pass' => Env::get('DASHBOARD_PASS', ''),
                'page_size' => max(10, Env::int('DASHBOARD_PAGE_SIZE', 50)),
            ],
            'digest' => [
                'to' => Env::get('DIGEST_TO', ''),
                'from' => Env::get('DIGEST_FROM', 'bot@localhost'),
                'subject' => Env::get('DIGEST_SUBJECT', 'Social Bot daily digest'),
            ],
            'worker' => [
                'batch_size' => max(1, Env::int('WORKER_BATCH_SIZE', 20)),
                'sleep_seconds' => max(1, Env::int('WORKER_SLEEP_SECONDS', 5)),
                'offpeak_only' => Env::bool('WORKER_OFFPEAK_ONLY', false),
                'offpeak_windows' => Env::list('DEEPSEEK_OFFPEAK_WINDOWS', ['04:00-06:00', '10:00-01:00']),
                'offpeak_all_weekend' => Env::bool('DEEPSEEK_OFFPEAK_ALL_WEEKEND', true),
            ],
            'approval' => [
                'sla_hours' => max(1, Env::int('APPROVAL_SLA_HOURS', 24)),
            ],
            'listening' => [
                'keywords' => Env::list('LISTENING_KEYWORDS', []),
                'max_posts' => max(1, Env::int('LISTENING_MAX_POSTS', 10)),
                'lookback_hours' => max(1, Env::int('LISTENING_LOOKBACK_HOURS', 24)),
            ],
            'evergreen' => [
                'min_age_days' => max(1, Env::int('EVERGREEN_MIN_AGE_DAYS', 30)),
                'max_per_run' => max(1, Env::int('EVERGREEN_MAX_PER_RUN', 3)),
                'min_leads' => max(1, Env::int('EVERGREEN_MIN_LEADS', 1)),
            ],
            'brand_voice' => [
                'path' => $root . '/prompts/brand_voice',
                'top_k' => 3,
            ],
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &self::$items;

        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }

            $ref = &$ref[$segment];
        }

        $ref = $value;
    }

    public static function root(): string
    {
        return self::$root;
    }

    public static function path(string $relative = ''): string
    {
        return self::$root . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$items;
    }
}
