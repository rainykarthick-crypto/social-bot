<?php

declare(strict_types=1);

/**
 * Unified webhook entry point.
 *
 *   /webhook/meta        -> MetaWebhook        (Facebook + Instagram comments)
 *   /webhook/google-ads  -> GoogleAdsWebhook   (lead form submissions)
 *   /webhook/whatsapp    -> WhatsAppWebhook    (Cloud API messages)
 *   /webhook/krayin      -> KrayinWebhookReceiver
 *
 * Every handler writes to a queue table (or syncs CRM state) and answers within
 * milliseconds: no LLM call, no synchronous processing.
 *
 * Deploy as the document root target for /webhook/*.  public/webhook.php simply
 * requires this file.
 */

use SocialBot\CRM\KrayinWebhookReceiver;
use SocialBot\Ingestion\GoogleAdsWebhook;
use SocialBot\Ingestion\MetaWebhook;
use SocialBot\Ingestion\WhatsAppWebhook;
use SocialBot\Support\App;
use SocialBot\Support\Logger;
use SocialBot\Support\Request;

/** @var string $root */
$root = require __DIR__ . '/bootstrap.php';

$request = Request::capture();
$route = $request->route('/webhook');

if ($route === '' && PHP_SAPI === 'cli') {
    $route = (string) ($argv[1] ?? '');
}

sendJsonHeaders();

try {
    $response = match ($route) {
        'meta' => handleMeta($request),
        'google-ads', 'google_ads', 'googleads' => handleGoogleAds($request),
        'whatsapp' => handleWhatsApp($request),
        'krayin' => handleKrayin($request),
        default => ['ok' => false, 'message' => 'Unknown webhook route: ' . ($route === '' ? '(none)' : $route), 'status' => 404],
    };
} catch (Throwable $e) {
    Logger::error('webhook handler threw', ['route' => $route, 'error' => $e->getMessage()]);
    $response = ['ok' => false, 'message' => 'internal_error', 'status' => 500];
}

$status = (int) ($response['status'] ?? 200);
unset($response['status']);

if (PHP_SAPI === 'cli') {
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($status >= 400 ? 1 : 0);
}

http_response_code($status);
echo json_encode($response, JSON_UNESCAPED_SLASHES);

exit(0);

function sendJsonHeaders(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
}

/**
 * @return array<string, mixed>
 */
function handleMeta(Request $request): array
{
    $webhook = MetaWebhook::make();

    // 1. Subscription verification.
    if ($request->isGet()) {
        $verification = $webhook->verify($request->query());

        if ($verification['ok'] && PHP_SAPI !== 'cli') {
            header('Content-Type: text/plain');
            echo (string) $verification['challenge'];

            exit(0);
        }

        return [
            'ok' => $verification['ok'],
            'status' => $verification['status'],
            'message' => $verification['reason'],
            'challenge' => $verification['challenge'],
        ];
    }

    // 2. Optional payload signature check (enabled as soon as META_APP_SECRET is set).
    $secret = trim((string) App::config('meta.app_secret', ''));

    if ($secret !== '' && !$webhook->verifySignature($request->raw(), $request->header('x-hub-signature-256'))) {
        Logger::warning('meta webhook signature rejected');

        return ['ok' => false, 'status' => 403, 'message' => 'invalid signature'];
    }

    $payload = $request->json();

    if ($payload === []) {
        return ['ok' => true, 'status' => 200, 'message' => 'ignored (empty payload)'];
    }

    $result = $webhook->handle($payload);

    return ['ok' => true, 'status' => 200, 'message' => 'queued', 'data' => $result];
}

/**
 * @return array<string, mixed>
 */
function handleGoogleAds(Request $request): array
{
    $webhook = GoogleAdsWebhook::make();
    $payload = $request->json();
    $query = $request->query();

    // Google validates the endpoint with a GET probe before sending leads.
    if ($request->isGet() && $payload === []) {
        return ['ok' => true, 'status' => 200, 'message' => 'google ads webhook endpoint ready'];
    }

    $result = $webhook->handle($payload, $query);

    return [
        'ok' => (bool) $result['ok'],
        'status' => (int) $result['status'],
        'message' => (string) $result['message'],
        'data' => [
            'google_ads_lead_id' => $result['google_ads_lead_id'],
            'comment_id' => $result['comment_id'],
            'fields' => $result['fields'],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function handleWhatsApp(Request $request): array
{
    $webhook = WhatsAppWebhook::make();

    if ($request->isGet()) {
        $verification = $webhook->verify($request->query());

        if ($verification['ok'] && PHP_SAPI !== 'cli') {
            header('Content-Type: text/plain');
            echo (string) $verification['challenge'];

            exit(0);
        }

        return [
            'ok' => $verification['ok'],
            'status' => $verification['status'],
            'message' => $verification['reason'],
        ];
    }

    $payload = $request->json();

    if ($payload === []) {
        return ['ok' => true, 'status' => 200, 'message' => 'ignored (empty payload)'];
    }

    return ['ok' => true, 'status' => 200, 'message' => 'queued', 'data' => $webhook->handle($payload)];
}

/**
 * @return array<string, mixed>
 */
function handleKrayin(Request $request): array
{
    $receiver = KrayinWebhookReceiver::make();

    if (!$receiver->verifySignature($request->raw(), $request->header('x-krayin-signature'))) {
        return ['ok' => false, 'status' => 403, 'message' => 'invalid signature'];
    }

    $payload = $request->json();

    if ($payload === []) {
        return ['ok' => true, 'status' => 200, 'message' => 'ignored (empty payload)'];
    }

    $result = $receiver->handle($payload, $request->headers());

    return [
        'ok' => (bool) $result['ok'],
        'status' => 200,
        'message' => (string) $result['message'],
        'data' => $result['data'] + ['event' => $result['event'], 'handled' => $result['handled']],
    ];
}
