<?php

declare(strict_types=1);

/**
 * End-to-end smoke test - no MySQL, no Redis, no API keys required.
 *
 *   php tests/smoke.php
 *   composer smoke
 *
 * It boots the real application, swaps the MySQL connection for an in-memory
 * SQLite database (tests/schema.sqlite.sql) and the LLM for a fake client, then
 * exercises the whole chain: pre-filter -> classification -> scoring -> routing
 * -> lead persistence -> Krayin push -> approval workflow -> Mixpost publish.
 *
 * Exit code 0 = every check passed, 1 = at least one failure.
 */

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use SocialBot\AI\AnalyticsSummarizer;
use SocialBot\AI\DeepSeekClassifier;
use SocialBot\AI\LlmClient;
use SocialBot\CRM\KrayinClient;
use SocialBot\Ingestion\GoogleAdsWebhook;
use SocialBot\Ingestion\MetaWebhook;
use SocialBot\Ingestion\WhatsAppWebhook;
use SocialBot\Leads\LeadScorer;
use SocialBot\Leads\SmartRouter;
use SocialBot\Social\MixpostManager;
use SocialBot\Support\App;
use SocialBot\Support\Cache;
use SocialBot\Support\Config;
use SocialBot\Support\Database;
use SocialBot\Support\Json;
use SocialBot\Support\LlmUsage;
use SocialBot\Support\Messages\ProcessCommentBatchMessage;
use SocialBot\Support\Request;
use SocialBot\Support\SentimentFilter;
use SocialBot\Workflow\CommentPipeline;
use SocialBot\Workflow\ConditionalApproval;
use SocialBot\Workflow\PostApprovalWorkflow;

/** @var string $root */
$root = require __DIR__ . '/../bootstrap.php';

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/** Returns a canned JSON payload without any network call. */
final class FakeLlmClient implements LlmClient
{
    public int $calls = 0;

    /** @var array<int, string> */
    public array $systemPrompts = [];

    /** @var array<int, string> */
    public array $userMessages = [];

    /** @var array<int, array<string, mixed>> */
    public array $options = [];

    public function __construct(
        private readonly string $providerName = 'fake',
        private readonly ?Closure $responder = null,
        private readonly bool $isAvailable = true,
    ) {
    }

    public function chat(string $systemPrompt, string $userMessage, array $options = []): array
    {
        $this->calls++;
        $this->systemPrompts[] = $systemPrompt;
        $this->userMessages[] = $userMessage;
        $this->options[] = $options;

        $content = $this->responder !== null
            ? ($this->responder)($userMessage, $options)
            : self::defaultPayload($userMessage);

        return [
            'content' => $content,
            'provider' => $this->providerName,
            'model' => 'fake-1',
            'latency_ms' => 3,
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40, 'cached_tokens' => 96],
        ];
    }

    public function provider(): string
    {
        return $this->providerName;
    }

    public function model(): string
    {
        return 'fake-1';
    }

    public function available(): bool
    {
        return $this->isAvailable;
    }

    /**
     * One result per "[n]" block in the user message: first purchase, second
     * complaint, everything else "none".
     */
    public static function defaultPayload(string $userMessage): string
    {
        $count = preg_match_all('/^\[\d+\]/m', $userMessage);

        return self::payloadForCount($count);
    }

    public static function payloadForCount(int $count): string
    {
        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $items[] = match ($i) {
                0 => ['intent' => 'purchase', 'score' => 88, 'sentiment' => 'positive', 'is_lead' => true, 'priority' => 'P1', 'summary' => 'wants to buy now', 'reply' => 'Send us a DM and we will confirm.'],
                1 => ['intent' => 'complaint', 'score' => 46, 'sentiment' => 'negative', 'is_lead' => true, 'priority' => 'P2', 'summary' => 'unhappy with the last order', 'reply' => 'Sorry about that - our support team will reach out today.'],
                default => ['intent' => 'none', 'score' => 5, 'sentiment' => 'neutral', 'is_lead' => false, 'priority' => 'P3', 'summary' => 'general comment', 'reply' => ''],
            };
        }

        return (string) json_encode($items);
    }
}

/** A provider that is never reachable (drives the local-rule fallback). */
final class UnavailableLlmClient implements LlmClient
{
    public function chat(string $systemPrompt, string $userMessage, array $options = []): array
    {
        throw new RuntimeException('provider unavailable');
    }

    public function provider(): string
    {
        return 'unavailable';
    }

    public function model(): string
    {
        return 'none';
    }

    public function available(): bool
    {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
/** @var array<int, array{0:string, 1:string}> $failures */
$failures = [];

function section(string $title): void
{
    echo "\n== {$title} ==\n";
}

function test(string $name, callable $fn): void
{
    global $passed, $failed, $failures;

    try {
        $fn();
        $passed++;
        echo "  ok    {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        $failures[] = [$name, $e->getMessage()];
        echo "  FAIL  {$name}\n        " . $e->getMessage() . "\n";
    }
}

function assertTrue(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(trim($message . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)));
    }
}

function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
{
    if ($unexpected === $actual) {
        throw new RuntimeException(trim($message . ' did not expect ' . var_export($actual, true)));
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(trim($message . ' "' . $needle . '" not found in "' . mb_substr($haystack, 0, 200) . '"'));
    }
}

function assertGreaterThan(int|float $expected, int|float $actual, string $message = ''): void
{
    if ($actual <= $expected) {
        throw new RuntimeException(trim($message . ' expected > ' . $expected . ', got ' . $actual));
    }
}

// ---------------------------------------------------------------------------
// Environment: in-memory SQLite instead of MySQL
// ---------------------------------------------------------------------------

$db = new Database(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

$schema = (string) file_get_contents($root . '/tests/schema.sqlite.sql');

foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
    $db->execute($statement);
}

App::set('db', $db);
App::set('cache', new Cache());

Config::set('krayin.token', 'test-krayin-token');
Config::set('krayin.api_url', 'http://krayin.test');
Config::set('mixpost.token', 'test-mixpost-token');
Config::set('mixpost.workspace_uuid', 'ws-test-uuid');
Config::set('mixpost.url', 'http://mixpost.test');
Config::set('google_ads.webhook_key', 'gads-key');
Config::set('meta.verify_token', 'meta-verify');
Config::set('whatsapp.verify_token', 'wa-verify');

$usage = new LlmUsage($db);

echo "social-bot smoke test\n";
echo 'PHP ' . PHP_VERSION . ' | sqlite in-memory | vendor ' . (is_file($root . '/vendor/autoload.php') ? 'installed' : 'missing (using PSR-4 fallback)') . "\n";

// ---------------------------------------------------------------------------
section('Bootstrap + config');

test('bootstrap returns the project root and boots the container', function () use ($root): void {
    assertSame($root, App::root());
    assertTrue(App::isBooted(), 'App::boot() was not called');
});

test('prompt files are loaded verbatim and contain no dates', function (): void {
    $classify = DeepSeekClassifier::loadPrompt('classify.txt');

    assertContains('Return ONLY valid JSON array', $classify);
    assertSame(0, preg_match('/\d{4}-\d{2}-\d{2}/', $classify), 'system prompt must not contain dates (breaks prompt caching)');
    assertNotSame('', DeepSeekClassifier::loadPrompt('command.txt'));
    assertNotSame('', DeepSeekClassifier::loadPrompt('analytics.txt'));
});

test('Json::extractArray tolerates fenced and prose-wrapped payloads', function (): void {
    assertSame(2, count((array) Json::extractArray("```json\n[{\"a\":1},{\"a\":2}]\n```")));
    assertSame(1, count((array) Json::extractArray('Sure! Here is the JSON: [{"a":1}] hope that helps')));
    assertSame(1, count((array) Json::extractArray('{"a":1}')));
    assertSame(['a' => 1], Json::extractArray('{"items":[{"a":1}]}')[0]);
});

// ---------------------------------------------------------------------------
section('Support: sentiment pre-filter');

test('pre-filter drops low-value comments before any LLM call', function (): void {
    $filter = new SentimentFilter();

    assertSame('too_short', $filter->skipReason('yes'));
    assertSame(null, $filter->skipReason('What is the price for the pro plan?'));
    assertNotSame(null, $filter->skipReason('nice'));
    assertNotSame(null, $filter->skipReason('Great!'));
    assertNotSame(null, $filter->skipReason('please follow me for free stuff'));
    assertSame(null, $filter->skipReason('Is this available in Pune? I want to order two units.'));
});

test('VADER (or the built-in lexicon) scores sentiment in the right direction', function (): void {
    $filter = new SentimentFilter();

    assertGreaterThan(0.05, $filter->compound('I love this, it is absolutely brilliant and fast'));
    assertTrue($filter->compound('This is terrible and I want a refund now') < -0.05, 'negative text should score negative');
    assertSame('positive', $filter->sentiment('This is great, thank you so much'));
    assertSame('negative', $filter->sentiment('worst service ever, total waste of money'));
});

test('filter() partitions a batch and reports the skip rate', function (): void {
    $filter = new SentimentFilter();

    $result = $filter->filter([
        ['text' => 'nice'],
        ['text' => 'What does the enterprise plan cost?'],
        ['text' => 'hi'],
        ['text' => 'Can you ship to Bangalore this week?'],
    ]);

    assertSame(2, count($result['keep']));
    assertSame(2, count($result['skipped']));
    assertSame(0.5, $filter->stats()['skip_rate']);
});

// ---------------------------------------------------------------------------
section('Leads: scorer + router');

test('LeadScorer produces the documented composite score', function (): void {
    $scorer = new LeadScorer();

    $top = $scorer->score([
        'intent' => 'purchase',
        'sentiment' => 'positive',
        'author_verified' => true,
        'author_followers' => 5000,
        'likes' => 10,
        'hours_old' => 0,
    ]);

    assertSame(100, $top['lead_score'], 'purchase + positive + authority + engagement + recency');
    assertSame('P1', $top['priority']);
    assertTrue($top['is_qualified']);

    $mid = $scorer->score(['intent' => 'availability', 'sentiment' => 'neutral', 'hours_old' => 0]);

    assertSame(50, $mid['lead_score'], 'availability(30) + neutral(10) + fresh(10)');
    assertSame('P2', $mid['priority']);
    assertTrue($mid['is_qualified'], 'score 50 with a buying intent qualifies');

    $spam = $scorer->score(['intent' => 'spam', 'sentiment' => 'negative', 'hours_old' => 0]);

    assertSame(0, $spam['lead_score'], 'negative totals clamp at 0');
    assertSame('P3', $spam['priority']);
    assertTrue($spam['is_qualified'] === false, 'spam is never qualified');

    $stale = $scorer->score(['intent' => 'pricing', 'sentiment' => 'neutral', 'hours_old' => 10]);
    assertSame(5, $stale['breakdown']['recency_weight'], 'recency decays by 1 per 2 hours');
});

test('SmartRouter follows the priority rules', function (): void {
    $router = new SmartRouter();

    assertSame('support_queue', $router->queueFor('complaint', 'neutral', 'P1'));
    assertSame('support_queue', $router->queueFor('pricing', 'negative', 'P1'));
    assertSame('sales_priority', $router->queueFor('purchase', 'positive', 'P1'));
    assertSame('sales_queue', $router->queueFor('demo', 'neutral', 'P2'));
    assertSame('archive', $router->queueFor('spam', 'neutral', 'P3'));
    assertSame('general_followup', $router->queueFor('none', 'neutral', 'P3'));

    $route = $router->route(['intent' => 'purchase', 'sentiment' => 'positive', 'priority' => 'P1'], ['priority' => 'P1']);
    assertSame('Sales', $route['department']);
    assertSame('New Business', $route['area']);
});

// ---------------------------------------------------------------------------
section('Workflow: conditional approval');

test('ConditionalApproval builds the chain from the content', function (): void {
    $approval = new ConditionalApproval();

    assertSame([ConditionalApproval::LEVEL_MANAGER, ConditionalApproval::LEVEL_BRAND], $approval->determineLevels('We are live in Pune today.'));
    assertSame(
        [ConditionalApproval::LEVEL_MANAGER, ConditionalApproval::LEVEL_FINANCE, ConditionalApproval::LEVEL_BRAND],
        $approval->determineLevels('New plan at 20% discount for the first 100 customers')
    );
    assertSame(
        [ConditionalApproval::LEVEL_MANAGER, ConditionalApproval::LEVEL_LEGAL, ConditionalApproval::LEVEL_BRAND],
        $approval->determineLevels('How we compare vs the market alternative')
    );
    assertTrue($approval->requiresFinance('Save $500 this week'), 'currency amounts must trigger finance');
    assertTrue($approval->requiresFinance('Price starts at ₹999'));

    $progress = $approval->progress($approval->allLevels(), [ConditionalApproval::LEVEL_MANAGER]);
    assertSame(ConditionalApproval::LEVEL_FINANCE, $progress['next']);
    assertSame(25, $progress['percent']);
    assertSame(false, $progress['complete']);
});

// ---------------------------------------------------------------------------
section('AI: classifier batching, JSON mode and fallbacks');

test('classifyBatch sends one call per 10-20 comments and keeps the system prompt byte-identical', function () use ($usage): void {
    $client = new FakeLlmClient('fake');
    $classifier = new DeepSeekClassifier($client, null, $usage, DeepSeekClassifier::loadPrompt('classify.txt'), 15, 400);

    $comments = [];

    for ($i = 1; $i <= 25; $i++) {
        $comments[] = ['comment_id' => 'c' . $i, 'platform' => 'instagram', 'author' => '@user' . $i, 'likes' => $i, 'text' => 'Can I order the pro plan today? Number ' . $i];
    }

    $results = $classifier->classifyBatch($comments);

    assertSame(25, count($results));
    assertSame(2, $client->calls, '25 comments at batch size 15 must be 2 calls');
    assertSame($client->systemPrompts[0], $client->systemPrompts[1], 'system prompt must be byte-identical for prompt caching');
    assertSame(400, $client->options[0]['max_tokens']);
    assertSame(true, $client->options[0]['json']);
    assertSame(false, $client->options[0]['thinking'], 'thinking mode must be off for classification');
    assertSame('purchase', $results[0]['intent']);
    assertSame('P1', $results[0]['priority']);
    assertTrue($results[0]['is_lead']);
});

test('malformed model output is normalised, not trusted', function () use ($usage): void {
    $client = new FakeLlmClient('fake', static fn (): string => json_encode([
        ['intent' => 'banana', 'score' => 5000, 'sentiment' => 'ecstatic', 'is_lead' => 'maybe', 'priority' => 'P9', 'summary' => '', 'reply' => 'ok'],
    ]));

    $classifier = new DeepSeekClassifier($client, null, $usage, DeepSeekClassifier::loadPrompt('classify.txt'));
    $result = $classifier->classifyOne(['comment_id' => 'x1', 'text' => 'Do you deliver to Chennai?']);

    assertSame('none', $result['intent'], 'unknown intents collapse to none');
    assertSame(100, $result['score'], 'scores are clamped to 0-100');
    assertTrue(in_array($result['sentiment'], ['positive', 'neutral', 'negative'], true), 'sentiment must be from the whitelist');
    assertTrue(in_array($result['priority'], ['P1', 'P2', 'P3'], true), 'priority must be from the whitelist');
    assertNotSame('', $result['summary'], 'an empty summary falls back to the comment text');
});

test('provider failure degrades to the local rule engine instead of throwing', function () use ($usage): void {
    $classifier = new DeepSeekClassifier(new UnavailableLlmClient(), null, $usage, DeepSeekClassifier::loadPrompt('classify.txt'));
    $result = $classifier->classifyOne(['comment_id' => 'x2', 'text' => 'I want to buy two units, please dm me the price']);

    assertSame(true, $result['degraded']);
    assertSame('heuristic', $classifier->lastProvider());
    assertTrue(in_array($result['intent'], ['purchase', 'pricing'], true), 'the rule engine detects an explicit buying signal');
    assertTrue($result['is_lead'], 'the rule engine still qualifies an explicit buying signal');

    $complaint = $classifier->classifyOne(['comment_id' => 'x4', 'text' => 'My order is still not delivered and nobody replies, I want a refund']);
    assertSame('complaint', $complaint['intent']);
    assertSame('negative', $complaint['sentiment'], 'a keyword-identified complaint is never scored positive');
});

test('exhausted daily token budget routes to the fallback provider', function () use ($usage, $db): void {
    Config::set('deepseek.daily_token_budget', 10);
    $db->insert('llm_usage', [
        'provider' => 'deepseek',
        'model' => 'deepseek-v4-flash',
        'purpose' => 'classification',
        'prompt_tokens' => 900,
        'completion_tokens' => 100,
        'cached_tokens' => 100,
        'total_tokens' => 1000,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    assertTrue($usage->overBudget(), 'overBudget() must be true once the budget is spent');

    $primary = new FakeLlmClient('deepseek');
    $fallback = new FakeLlmClient('ollama-fallback');
    $classifier = new DeepSeekClassifier($primary, $fallback, $usage, DeepSeekClassifier::loadPrompt('classify.txt'));

    $result = $classifier->classifyOne(['comment_id' => 'x3', 'text' => 'What is the price of the annual plan?']);

    assertSame(0, $primary->calls, 'the paid provider must not be called over budget');
    assertSame(1, $fallback->calls, 'the fallback must be used');
    assertSame('ollama-fallback', $result['provider']);

    Config::set('deepseek.daily_token_budget', 2000000);
    $db->execute('DELETE FROM `llm_usage`');
});

// ---------------------------------------------------------------------------
section('Pipeline: queue -> leads -> Krayin');

test('processBatch filters, classifies, scores, routes and pushes to Krayin', function () use ($db, $usage): void {
    $db->execute('DELETE FROM `comment_queue`');
    $db->execute('DELETE FROM `leads`');

    $rows = [
        ['platform' => 'instagram', 'comment_id' => 'q1', 'author' => '@spammer', 'text' => 'please follow me and check my page for free followers', 'likes' => 0],
        ['platform' => 'instagram', 'comment_id' => 'q2', 'author' => '@rahul', 'text' => 'I want to buy the pro plan, please DM me the payment link', 'likes' => 6],
        ['platform' => 'facebook', 'comment_id' => 'q3', 'author' => 'Priya', 'text' => 'My order from last week is still not delivered and nobody is responding', 'likes' => 2],
    ];

    foreach ($rows as $row) {
        $db->insert('comment_queue', $row + [
            'post_id' => 'post-1',
            'post_context' => 'launch post',
            'author_followers' => 0,
            'author_verified' => 0,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $krayinHttp = new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['id' => 501]])),
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['id' => 601]])),
    ])), 'http_errors' => false]);

    $krayin = new KrayinClient($db, $krayinHttp);
    $sentiment = new SentimentFilter();
    $classifier = new DeepSeekClassifier(new FakeLlmClient('fake'), null, $usage, DeepSeekClassifier::loadPrompt('classify.txt'), 15, 400);

    $pipeline = new CommentPipeline($db, $sentiment, $classifier, new LeadScorer(), new SmartRouter(), $krayin);
    $stats = $pipeline->processBatch(20);

    assertSame(3, $stats['claimed']);
    assertSame(1, $stats['skipped'], 'the promo-spam comment must be filtered locally');
    assertSame(2, $stats['classified']);
    assertSame(2, $stats['leads_created']);
    assertSame(1, $stats['krayin_created'], 'only the qualified lead is pushed to Krayin');

    $queue = $db->select('SELECT `comment_id`, `status`, `skip_reason` FROM `comment_queue` ORDER BY `id`');
    assertSame('skipped', $queue[0]['status']);
    assertSame('low_value_reaction', $queue[0]['skip_reason']);
    assertSame('processed', $queue[1]['status']);
    assertSame('processed', $queue[2]['status']);

    $purchase = $db->findWhere('leads', ['comment_id' => 'q2']);
    assertSame('purchase', $purchase['intent']);
    assertSame('P1', $purchase['priority']);
    assertSame(1, (int) $purchase['is_qualified']);
    assertSame('sales_priority', $purchase['routing_queue']);
    assertSame('Sales', $purchase['department']);
    assertSame(501, (int) $purchase['krayin_lead_id']);

    $complaint = $db->findWhere('leads', ['comment_id' => 'q3']);
    assertSame('support_queue', $complaint['routing_queue']);
    assertSame('Support', $complaint['department']);
    assertSame(0, (int) $complaint['is_qualified'], 'complaint at score 0 is routed but not qualified');

    Config::set('krayin.token', 'test-krayin-token');
});

test('Google Ads lead forms are stored raw, queued, and linked back after processing', function () use ($db, $usage): void {
    $db->execute('DELETE FROM `google_ads_leads`');
    $db->execute('DELETE FROM `comment_queue`');
    $db->execute('DELETE FROM `leads`');

    $webhook = new GoogleAdsWebhook($db, new Cache());
    $result = $webhook->handle([
        'campaign_id' => '12345',
        'campaign_name' => 'Search - Pune',
        'gclid' => 'CjwKC',
        'user_column_data' => [
            ['column_name' => 'FULL_NAME', 'string_value' => 'Aarti Sharma'],
            ['column_name' => 'PHONE_NUMBER', 'string_value' => '+919000000000'],
            ['column_name' => 'EMAIL', 'string_value' => 'aarti@example.com'],
            ['column_name' => 'CITY', 'string_value' => 'Pune'],
        ],
    ], ['google_key' => 'gads-key']);

    assertSame(true, $result['ok']);
    $queued = $db->findWhere('comment_queue', ['comment_id' => $result['comment_id']]);
    assertSame(
        'Name: Aarti Sharma | Phone: +919000000000 | Email: aarti@example.com | City: Pune',
        (string) $queued['text'],
        'the queue row carries the flattened lead-form fields'
    );
    assertSame('gads-' . $result['google_ads_lead_id'], $result['comment_id']);
    assertSame(1, $db->count('google_ads_leads'));

    $rejected = $webhook->handle(['user_column_data' => []], ['google_key' => 'wrong']);
    assertSame(false, $rejected['ok'], 'a wrong google_key must be rejected');
    assertSame(403, $rejected['status']);

    $krayin = new KrayinClient($db, new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['id' => 777]])),
    ])), 'http_errors' => false]));

    $classifier = new DeepSeekClassifier(new FakeLlmClient('fake', static fn (): string => (string) json_encode([
        ['intent' => 'purchase', 'score' => 90, 'sentiment' => 'positive', 'is_lead' => true, 'priority' => 'P1', 'summary' => 'form fill', 'reply' => ''],
    ])), null, $usage, DeepSeekClassifier::loadPrompt('classify.txt'));

    $pipeline = new CommentPipeline($db, new SentimentFilter(), $classifier, new LeadScorer(), new SmartRouter(), $krayin);
    $pipeline->processBatch(10);

    $raw = $db->find('google_ads_leads', (int) $result['google_ads_lead_id']);
    $lead = $db->findWhere('leads', ['comment_id' => $result['comment_id']]);

    assertSame(1, (int) $raw['processed'], 'the raw Google Ads row is marked processed');
    assertSame((int) $lead['id'], (int) $raw['lead_id'], 'the raw row points at the created lead');
    assertSame((int) $result['google_ads_lead_id'], (int) $lead['google_ads_lead_id'], 'the lead points back at the raw row');
});

// ---------------------------------------------------------------------------
section('Workflow: Krayin request -> task -> approve -> publish');

test('requestPost creates the Krayin lead, the review task and the audit row', function () use ($db): void {
    $db->execute('DELETE FROM `social_posts`');
    $db->execute('DELETE FROM `approval_audit`');

    $krayin = new KrayinClient($db, new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['id' => 900]])),
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['id' => 901]])),
    ])), 'http_errors' => false]));

    $mixpost = new MixpostManager($db, new Client(['handler' => HandlerStack::create(new MockHandler([])), 'http_errors' => false]));

    $workflow = new PostApprovalWorkflow($db, $krayin, $mixpost, new ConditionalApproval());
    $result = $workflow->requestPost(
        'Launch week: our new plan is live with a 20% discount (price starts at ₹999)',
        [1, 2],
        'tester'
    );

    assertSame(true, $result['ok']);
    assertSame('pending_approval', $result['status']);
    assertSame(900, $result['krayin_lead_id']);
    assertSame(901, $result['krayin_activity_id']);
    assertSame([ConditionalApproval::LEVEL_MANAGER, ConditionalApproval::LEVEL_FINANCE, ConditionalApproval::LEVEL_BRAND], $result['approval_levels']);

    $post = $db->find('social_posts', (int) $result['post_id']);
    assertSame('pending_approval', $post['status']);
    assertSame(900, (int) $post['krayin_lead_id']);
    assertSame([1, 2], Json::fromColumn($post['target_accounts']));

    $audit = $db->select('SELECT * FROM `approval_audit` WHERE `social_post_id` = ? ORDER BY `id`', [(int) $result['post_id']]);
    assertSame(1, count($audit));
    assertSame('submitted', $audit[0]['action']);
    assertSame(ConditionalApproval::LEVEL_MANAGER, $audit[0]['level_name']);
});

test('approvePost walks the chain and publishes through Mixpost on the final sign-off', function () use ($db): void {
    $krayin = new KrayinClient($db, new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['id' => 902]])),  // activity for next approver
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['id' => 903]])),  // activity
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['id' => 904]])),  // activity
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['id' => 905]])),  // krayin lead update
    ])), 'http_errors' => false]));

    $mixpost = new MixpostManager($db, new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['uuid' => 'mp-1', 'status' => 'needs_approval']])),
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['status' => 'scheduled']])),
    ])), 'http_errors' => false]));

    $workflow = new PostApprovalWorkflow($db, $krayin, $mixpost, new ConditionalApproval());

    $postId = $db->insert('social_posts', [
        'content' => 'Our new plan costs ₹999 with a 20% discount this week',
        'target_accounts' => Json::encode([1, 2]),
        'platforms' => Json::encode(['instagram', 'facebook']),
        'requested_by' => 'tester',
        'status' => 'pending_approval',
        'krayin_lead_id' => 900,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $first = $workflow->approvePost($postId, 'manager@example.com', 'looks good');
    assertSame('partially_approved', $first['status']);
    assertSame(ConditionalApproval::LEVEL_FINANCE, $first['progress']['next']);

    $second = $workflow->approvePost($postId, 'finance@example.com');
    assertSame('partially_approved', $second['status']);
    assertSame(ConditionalApproval::LEVEL_BRAND, $second['progress']['next']);

    $final = $workflow->approvePost($postId, 'brand@example.com', 'ship it');
    assertSame(true, $final['ok']);
    assertSame('published', $final['status']);
    assertSame('mp-1', $final['publish']['mixpost_post_uuid']);

    $post = $db->find('social_posts', $postId);
    assertSame('published', $post['status']);
    assertSame('mp-1', $post['mixpost_post_uuid']);
    assertSame('brand@example.com', $post['approved_by']);
    assertNotSame(null, $post['published_at']);

    $actions = array_column($db->select('SELECT `action` FROM `approval_audit` WHERE `social_post_id` = ? ORDER BY `id`', [$postId]), 'action');
    assertSame(['approved', 'approved', 'approved'], $actions, 'three approvals recorded (the submitted row belongs to the earlier post)');

    $again = $workflow->approvePost($postId, 'manager@example.com');
    assertSame(false, $again['ok'], 'a published post cannot be approved again');
});

test('rejectPost stops the chain and records the reason', function () use ($db): void {
    $krayin = new KrayinClient($db, new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['id' => 910]])),
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['id' => 911]])),
    ])), 'http_errors' => false]));

    $mixpost = new MixpostManager($db, new Client(['handler' => HandlerStack::create(new MockHandler([])), 'http_errors' => false]));
    $workflow = new PostApprovalWorkflow($db, $krayin, $mixpost, new ConditionalApproval());

    $postId = $db->insert('social_posts', [
        'content' => 'Weekly tip: three ways to cut your onboarding time',
        'target_accounts' => Json::encode([1]),
        'requested_by' => 'tester',
        'status' => 'pending_approval',
        'krayin_lead_id' => 910,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $result = $workflow->rejectPost($postId, 'brand@example.com', 'tone is off-brand');

    assertSame(true, $result['ok']);
    assertSame('rejected', $result['status']);

    $post = $db->find('social_posts', $postId);
    assertSame('rejected', $post['status']);
    assertSame('tone is off-brand', $post['rejection_reason']);

    $rejections = $db->count('approval_audit', ['social_post_id' => $postId, 'action' => 'rejected']);
    assertSame(1, $rejections);
});

test('markSlaBreaches flags overdue approvals exactly once per level', function () use ($db): void {
    $krayin = new KrayinClient($db, new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['data' => ['id' => 920]])),
    ])), 'http_errors' => false]));

    $mixpost = new MixpostManager($db, new Client(['handler' => HandlerStack::create(new MockHandler([])), 'http_errors' => false]));
    $workflow = new PostApprovalWorkflow($db, $krayin, $mixpost, new ConditionalApproval());

    Config::set('approval.sla_hours', 1);

    $postId = $db->insert('social_posts', [
        'content' => 'Small announcement about our support hours',
        'target_accounts' => Json::encode([1]),
        'requested_by' => 'tester',
        'status' => 'pending_approval',
        'krayin_lead_id' => 920,
        'created_at' => date('Y-m-d H:i:s', strtotime('-3 hours')),
    ]);

    assertSame(1, $workflow->markSlaBreaches(), 'one breach detected');
    assertSame(1, $db->count('approval_audit', ['social_post_id' => $postId, 'action' => 'sla_breached']));
    assertSame(0, $workflow->markSlaBreaches(), 'the same breach is not recorded twice');
});

// ---------------------------------------------------------------------------
section('Integrations: payload builders + webhook parsing');

test('Mixpost payload carries schedule, timezone, accounts and per-account versions', function () use ($db): void {
    $manager = new MixpostManager($db);

    $payload = $manager->buildPayload([
        'content' => 'Hello world',
        'accounts' => [1, 4],
        'schedule' => true,
        'date' => '2026-03-01',
        'time' => '10:30',
    ]);

    assertSame(true, $payload['schedule']);
    assertSame('2026-03-01', $payload['date']);
    assertSame('10:30', $payload['time']);
    assertSame('Asia/Kolkata', $payload['timezone']);
    assertSame([1, 4], $payload['accounts']);
    assertSame(2, count($payload['versions']));
    assertSame(1, $payload['versions'][0]['account_id']);
    assertSame(true, $payload['versions'][0]['is_original']);
    assertSame('Hello world', $payload['versions'][1]['content'][0]['body']);
});

test('Krayin lead payload summarises the scored lead', function () use ($db): void {
    $client = new KrayinClient($db);

    $payload = $client->leadPayloadFromLead([
        'platform' => 'instagram',
        'author' => '@rahul',
        'intent' => 'purchase',
        'intent_score' => 88,
        'lead_score' => 96,
        'priority' => 'P1',
        'sentiment' => 'positive',
        'summary' => 'wants to buy',
        'comment_text' => 'I want to buy the pro plan',
        'suggested_reply' => 'DM us',
        'post_id' => 'post-1',
    ]);

    assertContains('[P1]', (string) $payload['title']);
    assertContains('@rahul', (string) $payload['title']);
    assertContains('Lead score: 96 / 100 (P1)', (string) $payload['description']);
});

test('Meta webhook verification and comment parsing', function () use ($db): void {
    $webhook = new MetaWebhook($db, new Cache());

    $ok = $webhook->verify(['hub_mode' => 'subscribe', 'hub_verify_token' => 'meta-verify', 'hub_challenge' => '12345']);
    assertSame(true, $ok['ok']);
    assertSame('12345', $ok['challenge']);

    $bad = $webhook->verify(['hub_mode' => 'subscribe', 'hub_verify_token' => 'nope', 'hub_challenge' => 'x']);
    assertSame(false, $bad['ok']);
    assertSame(403, $bad['status']);

    $comments = $webhook->parse(['entry' => [['id' => 'page-1', 'changes' => [[
        'field' => 'comments',
        'value' => [
            'id' => 'ig-comment-1',
            'text' => 'Do you ship to Mumbai? I want to order one',
            'from' => ['id' => 'u1', 'username' => 'mumbai_buyer'],
            'media' => ['id' => 'media-1'],
            'like_count' => 3,
        ],
    ]]]]]);

    assertSame(1, count($comments));
    assertSame('instagram', $comments[0]['platform']);
    assertSame('ig-comment-1', $comments[0]['comment_id']);
    assertSame('mumbai_buyer', $comments[0]['author']);
});

test('WhatsApp webhook verification and message parsing', function () use ($db): void {
    $webhook = new WhatsAppWebhook($db, new Cache());

    $ok = $webhook->verify(['hub_mode' => 'subscribe', 'hub_verify_token' => 'wa-verify', 'hub_challenge' => 'abc']);
    assertSame(true, $ok['ok']);

    $rows = $webhook->parse(['entry' => [['changes' => [[
        'value' => [
            'metadata' => ['phone_number_id' => '555'],
            'contacts' => [['wa_id' => '919000000000', 'profile' => ['name' => 'Aarti']]],
            'messages' => [[
                'from' => '919000000000',
                'id' => 'wamid.1',
                'timestamp' => '1700000000',
                'type' => 'text',
                'text' => ['body' => 'Please share the pricing for 10 seats'],
            ]],
        ],
    ]]]]]);

    assertSame(1, count($rows));
    assertSame('Aarti', $rows[0]['author']);
    assertSame('Please share the pricing for 10 seats', $rows[0]['text']);
    assertSame('whatsapp:555', $rows[0]['post_id']);
});

test('Request extracts the webhook route and JSON body', function (): void {
    $request = Request::make('POST', '{"entry":[]}', ['X-Krayin-Signature' => 'abc'], ['__uri' => '/webhook/krayin']);

    assertSame('krayin', $request->route('/webhook'));
    assertSame('abc', $request->header('x-krayin-signature'));
    assertSame(['entry' => []], $request->json());
});

test('Cache once()/allows() work on the file fallback', function (): void {
    $cache = new Cache();
    $key = 'smoke:' . bin2hex(random_bytes(6));

    assertSame(true, $cache->once($key, 5));
    assertSame(false, $cache->once($key, 5), 'the second call within the window is a duplicate');

    $limitKey = 'smoke-limit:' . bin2hex(random_bytes(6));
    assertSame(true, $cache->allows($limitKey, 2, 5));
    assertSame(true, $cache->allows($limitKey, 2, 5));
    assertSame(false, $cache->allows($limitKey, 2, 5), 'the third call exceeds the window');

    $cache->forget($key);
});

test('the message bus dispatches to the registered handler', function (): void {
    $received = null;
    $bus = new \SocialBot\Support\Bus();
    $bus->register(ProcessCommentBatchMessage::class, static function (ProcessCommentBatchMessage $message) use (&$received): array {
        $received = $message->limit;

        return ['handled' => true];
    });

    $result = $bus->dispatch(new ProcessCommentBatchMessage(7));

    assertSame(7, $received);
    assertSame(['handled' => true], $result);
});

// ---------------------------------------------------------------------------
section('Analytics + audit trail');

test('AnalyticsSummarizer aggregates the lead table and produces a recommendation', function () use ($db, $usage): void {
    $summarizer = new AnalyticsSummarizer(new UnavailableLlmClient(), null, $db, $usage);
    $snapshot = $summarizer->snapshot(7);

    assertGreaterThan(0, $snapshot['total_leads'], 'leads created by the pipeline must be visible');
    assertGreaterThan(0, $snapshot['qualified']);
    assertTrue(array_key_exists('recommendation', $summarizer->summarize(7)), 'summarize() always returns a recommendation');
    assertNotSame('', (string) $summarizer->deterministicRecommendation($snapshot));
});

test('AuditLog persists events and they are queryable', function () use ($db): void {
    $audit = new \SocialBot\Support\AuditLog($db);
    $audit->info('smoke.test', 'audit write works', ['k' => 'v'], 'test', '1');

    $rows = $audit->forSubject('test', '1');
    assertSame(1, count($rows));
    assertSame('smoke.test', $rows[0]['event']);
});

test('App readiness and pending-credential reporting work without any credentials', function (): void {
    $readiness = App::readiness();
    assertTrue(array_key_exists('database', $readiness));
    assertTrue(array_key_exists('vader', $readiness));

    $pending = App::pendingCredentials();
    assertTrue(in_array('META_VERIFY_TOKEN', $pending, true) === false, 'test config sets the meta verify token');
});

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 68) . "\n";
printf("passed: %d   failed: %d\n", $passed, $failed);

if ($failures !== []) {
    echo "\nfailures:\n";

    foreach ($failures as [$name, $message]) {
        echo " - {$name}: {$message}\n";
    }
}

exit($failed === 0 ? 0 : 1);
