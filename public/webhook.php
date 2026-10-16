<?php

declare(strict_types=1);

/**
 * Web-server front controller for the webhook routes.
 *
 * Point the platform webhooks at:
 *   https://your-host/webhook/meta
 *   https://your-host/webhook/google-ads
 *   https://your-host/webhook/whatsapp
 *   https://your-host/webhook/krayin
 *
 * All routing logic lives in ../webhook.php.
 */

require __DIR__ . '/../webhook.php';
