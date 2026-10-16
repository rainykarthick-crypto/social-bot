# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `tools/local-stack.ps1`: starts/stops/inspects the whole stack natively — MariaDB (or
  MySQL), the PHP web server and the queue worker — with no Docker, no Windows service and
  no administrator rights. Reads the database settings and `WEB_PORT` from `.env`, records
  PIDs in `storage/run/` and logs to `storage/logs/`.
- README section "Portable runtime — no installer, no admin, no Docker": the exact
  zip-based PHP 8.3 + MariaDB 10.11 + Composer bootstrap this project is validated on.

### Planned

- Vector-embedding retrieval behind `BrandVoiceRAG` (the lexical retriever is isolated behind `retrieve()`).
- Async transport for the message bus (Symfony Messenger transports) instead of in-process handling.
- Krayin/Mixpost API contract tests against a recorded fixture per supported version.

## [1.0.0] - 2026-10-04

### Added

**Ingestion**

- `MetaWebhook` for Facebook Page and Instagram comments plus Instagram DMs, with
  `hub.*` verification and `X-Hub-Signature-256` validation.
- `WhatsAppWebhook` for Cloud API messages (text, button and interactive replies).
- `GoogleAdsWebhook` for lead-form submissions, authenticated with `google_key`,
  storing the raw payload and a flattened queue row.
- `KrayinWebhookReceiver` for `lead.created`, `lead.updated` and `quote.won`, with HMAC
  signature verification and auto-assignment of qualified leads to a sales task.
- `InstagramIngestor` working through `amirsarhang/instagram-php-sdk` when installed and
  falling back to the Graph API, with subscribe/reply/comment-fetch/enqueue helpers.
- Idempotent ingestion: unique `(platform, comment_id)` plus a short-lived dedupe cache.

**AI and cost control**

- `DeepSeekClassifier` with 10–20 comments per request, JSON mode, `max_tokens=400` and
  thinking mode off (V4_FLASH), returning intent/score/sentiment/priority/summary/reply.
- Byte-identical system prompts loaded once from `prompts/`, so DeepSeek's prompt cache hits.
- `OllamaClient` fallback for a missing key, an exhausted daily budget or an API failure,
  and a deterministic local rule engine as the final fallback.
- `LlmUsage` ledger recording model, prompt/completion/cached tokens, latency, cost estimate
  and fallback flag for every call, plus a hard daily token budget guard.
- `CommandParser`/`CommandHandler` (create/schedule/list/approve/analytics) and
  `AnalyticsSummarizer` (SQL snapshot + LLM recommendation).
- `BrandVoiceRAG`: offline TF-IDF retrieval injected into user messages only.

**Leads and CRM**

- `SentimentFilter` (VADER, with a built-in lexicon fallback) that drops 40–60 % of comments
  before any LLM call, and reports the skip rate.
- `LeadScorer`: explainable 0–100 score (intent ≤40, sentiment ≤20, authority ≤20,
  engagement ≤10, recency ≤10) with P1/P2/P3 priorities.
- `SmartRouter`: `sales_priority`, `sales_queue`, `support_queue`, `general_followup`,
  `archive`, with department/area and per-queue SLA.
- `KrayinClient` for leads, activities and status updates, with retries, caching-based rate
  limits and a durable audit trail.

**Publishing and approvals**

- `ConditionalApproval`: Social Media Manager → (+Finance when price/cost/discount/currency)
  → (+Legal on competitor/comparison) → Brand Manager.
- `PostApprovalWorkflow`: Krayin lead + review task, approval audit, partial approvals,
  rejection, SLA-breach detection and `publishToMixpost()`.
- `MixpostManager`: create/approve/get/list posts and accounts with per-account versions.

**Agents**

- `SocialListeningAgent` for keyword mentions across every ingested platform.
- `EvergreenRecycler` for re-submitting proven posts through the approval workflow.

**Operations**

- `worker.php` queue processor: claim, pre-filter, batch, score, route, persist, push, with
  off-peak gating, publishing retries, stale-claim recovery and a resilient daemon loop.
- `audit.php`: 18-check platform audit with `--fix`, `--listen`, `--evergreen` and `--json`.
- `digest.php`: daily HTML email digest written to disk even without an MTA.
- Dark-theme dashboard (`public/inbox.php`, `public/lead_detail.php`) with stats, filters,
  priority badges, approval actions, CSRF protection and optional Basic auth.
- `migrate.php` runner with `--status`, `--fresh` and `--pretend`.
- `tools/lint.php` syntax gate and a 29-check end-to-end smoke suite on in-memory SQLite.
- Docker stack (worker, web, MySQL 8, Redis 7, optional Ollama) plus a GitHub Actions CI job.

[Unreleased]: https://github.com/rainykarthick-crypto/social-bot/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/rainykarthick-crypto/social-bot/releases/tag/v1.0.0
