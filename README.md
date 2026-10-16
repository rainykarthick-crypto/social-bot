# Social Media Lead Bot + Multi-Account Manager + Krayin CRM Orchestration

[![CI](https://github.com/rainykarthick-crypto/social-bot/actions/workflows/ci.yml/badge.svg)](https://github.com/rainykarthick-crypto/social-bot/actions/workflows/ci.yml)
[![PHP 8.3+](https://img.shields.io/badge/php-8.3%2B-777bb4.svg)](https://www.php.net/)
[![MySQL 8](https://img.shields.io/badge/MySQL-8.0-4479a1.svg)](https://dev.mysql.com/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/smoke%20suite-29%20checks-brightgreen.svg)](tests/smoke.php)
[![Docker optional](https://img.shields.io/badge/docker-optional-blue.svg)](docker/docker-compose.yml)

Production PHP 8.3 application that turns social comments and paid-search lead forms into
scored, routed, CRM-tracked leads — and runs outbound posting through an
"ask CRM → task → approve → publish" workflow.

```
 Facebook / Instagram / WhatsApp / Google Ads lead forms
                     │  (webhooks, <10 ms, insert-only)
                     ▼
        ┌────────────────────────────┐
        │  comment_queue (MySQL 8)   │
        └────────────┬───────────────┘
                     │  worker.php (batching, off-peak aware)
        ┌────────────▼───────────────┐
        │ 1. VADER pre-filter        │  ~40-60% dropped, zero tokens
        │ 2. DeepSeek V4_FLASH batch │  JSON mode, thinking OFF, 10-20/call
        │ 3. LeadScorer (0-100)      │  deterministic, explainable
        │ 4. SmartRouter             │  sales_priority / support_queue / ...
        └────────────┬───────────────┘
                     ▼
        leads ──────────────► Krayin CRM  ◄──── Krayin webhooks (status sync,
                     ▲                        qualified → auto sales task)
                     │
        social_posts ─┴──► PostApprovalWorkflow ──► Mixpost (multi-account publish)
                (conditional chain: Manager → Finance? → Legal? → Brand)
```

Self-hosted only — Krayin CRM, Mixpost Lite and Ollama are open source; the only paid
dependency is the DeepSeek API key, and the app degrades to Ollama and then to a local
rule engine without it.

---

## 1. Quick start

### 1.1 Requirements

| Component | Version | Notes |
|---|---|---|
| PHP | 8.3+ | extensions: `pdo_mysql`, `mbstring`, `curl`, `json` (recommended: `bcmath`, `intl`, `zip`) |
| MySQL | 8.0+ | MariaDB 10.5+ also works |
| Redis | 6+ | optional at runtime (falls back to a file cache) |
| Composer | 2.x | |
| Krayin CRM | latest | central hub (separate Laravel app) |
| Mixpost (Lite) | latest | multi-account publisher (separate Laravel app) |
| Ollama | latest | local LLM fallback (`llama3.2:3b`) |

### 1.2 Install — plain PHP, no Docker required

Nothing in the application depends on a container. `docker/` is an optional
convenience stack; the runtime needs only PHP 8.3+, MySQL 8 and the Composer
dependencies.

```bash
git clone https://github.com/rainykarthick-crypto/social-bot.git social-bot && cd social-bot
composer install
cp .env.example .env          # then fill in credentials (section 4)
mysql -u root -p -e "CREATE DATABASE social_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php migrate.php               # creates every table (idempotent)
php migrate.php --status      # verify
php audit.php                 # prints a health report, exit 0/1/2
php worker.php --once         # drain the queue once
composer serve                # dashboard + webhook routes on http://127.0.0.1:8080
```

> If port 8080 is already taken, run
> `php -S 127.0.0.1:8081 -t public public/router.php` instead (the Docker stack uses 8081
> by default).

Windows (PowerShell) equivalent:

```powershell
winget install --id PHP.PHP.8.3 -e          # or: choco install php --version=8.3
winget install --id Composer.Composer -e
winget install --id Oracle.MySQL -e         # or reuse XAMPP / Laragon / an existing MySQL
php -m | Select-String 'pdo_mysql|mbstring|curl|json'   # required extensions
composer install
Copy-Item .env.example .env                 # then edit it
php migrate.php
php worker.php --once
php -S 127.0.0.1:8080 -t public public/router.php
```

Scheduling without Docker is plain cron (7.2) or Task Scheduler on Windows.

`composer.lock` is committed and `config.platform.php` is pinned to `8.3.0`, so the
resolved dependency set is always installable on the PHP 8.3 target (without the pin,
resolving on a PHP 8.4 machine silently pulls Symfony 8.x components that refuse to run
on 8.3).

On Windows/macOS bind mounts a full `composer install` (54 packages) can be slow enough
to hit Composer's per-process timeout while unzipping; raise it if needed:

```bash
COMPOSER_PROCESS_TIMEOUT=1800 composer install
```

Validate without a database or any API key:

```bash
composer smoke                # end-to-end suite on in-memory SQLite (see section 12)
composer lint                 # php -l over every file
```

### 1.3 Docker (optional, fully self-hosted stack)

```bash
docker compose -f docker/docker-compose.yml build
docker compose -f docker/docker-compose.yml up -d            # worker + web + mysql + redis
docker compose -f docker/docker-compose.yml run --rm app composer install
docker compose -f docker/docker-compose.yml run --rm app php migrate.php
docker compose -f docker/docker-compose.yml run --rm app php tests/smoke.php
docker compose -f docker/docker-compose.yml logs -f app      # worker output
```

Ports (override with `WEB_PORT_FORWARD` / `DB_PORT_FORWARD` / `REDIS_PORT_FORWARD`, or pass
`--env-file .env` so the values in `.env` are used):

| Service | Host URL |
|---|---|
| Dashboard + webhooks | `http://127.0.0.1:8081` |
| MySQL 8 | `127.0.0.1:3307` |
| Redis 7 | `127.0.0.1:6380` |

The `app` container runs `worker.php --daemon`; `web` serves `public/` with four PHP
workers. `vendor/` lives in a named volume (bind-mounted vendor trees are very slow on
Windows/macOS) — run `composer install` on the host instead if you want it on disk.

The `ollama` service is behind a profile: `docker compose -f docker/docker-compose.yml --profile llm up -d ollama`, then `docker compose -f docker/docker-compose.yml exec ollama ollama pull llama3.2:3b`.

### 1.4 Dashboard

```bash
composer serve                # php -S 127.0.0.1:8080 -t public public/router.php
# or point your vhost document root at public/
```

* `http://127.0.0.1:8080/` (`:8081` for the Docker stack) → lead inbox: dark theme, stats
  bar, filters, priority badges, pagination, operations panel.
* `lead_detail.php?id=N` → full comment, classification, Krayin/Mixpost links, action
  buttons (mark contacted/booked/not interested, create CRM task, reply via platform,
  re-classify).

Set `DASHBOARD_USER` / `DASHBOARD_PASS` to put HTTP Basic auth in front of both pages.

---

## 2. Directory layout

```
social-bot/
├── public/                     web root: inbox.php, lead_detail.php, index.php,
│                               webhook.php (front controller), router.php (dev server)
├── src/
│   ├── AI/                     DeepSeekClient, DeepSeekClassifier, OllamaClient,
│   │                           CommandParser, CommandHandler, AnalyticsSummarizer,
│   │                           BrandVoiceRAG, LlmClient (interface)
│   ├── Ingestion/              MetaWebhook, GoogleAdsWebhook, InstagramIngestor,
│   │                           WhatsAppWebhook
│   ├── Leads/                  LeadScorer, SmartRouter
│   ├── CRM/                    KrayinClient, KrayinWebhookReceiver
│   ├── Social/                 MixpostManager
│   ├── Workflow/               PostApprovalWorkflow, ConditionalApproval, CommentPipeline
│   ├── Agents/                 SocialListeningAgent, EvergreenRecycler
│   └── Support/                App (container), Config, Env, Database, Logger, Http,
│                               Cache, Bus, SentimentFilter, LlmUsage, AuditLog,
│                               Json, Request, Messages/
├── prompts/                    STATIC system prompts (byte-identical every call)
│   ├── classify.txt            classification JSON contract
│   ├── command.txt             command parser JSON contract
│   ├── analytics.txt           analytics summariser JSON contract
│   └── brand_voice/*.md        retrieved brand voice (injected into USER messages only)
├── migrations/                 001..008 SQL files (MySQL 8)
├── tests/                      smoke.php + schema.sqlite.sql
├── tools/lint.php              php -l over every file
├── docker/                     Dockerfile + docker-compose.yml
├── bootstrap.php               autoloading, env, container
├── migrate.php                 migration runner
├── worker.php                  queue processor
├── webhook.php                 unified webhook entry point
├── audit.php                   scheduled platform audit
├── digest.php                  daily email digest
└── .env.example
```

---

## 3. Database schema

| Table | Purpose |
|---|---|
| `leads` | every captured lead: platform, comment, `intent`, `intent_score`, `sentiment`, `lead_score`, `priority` (P1/P2/P3), `is_qualified`, `status`, `summary`, `suggested_reply`, `department`, `area`, `routing_queue`, `follow_up_date`, `appointment_slot`, `krayin_lead_id` |
| `comment_queue` | raw ingestion buffer (`pending → processing → processed/skipped/failed`), unique on `(platform, comment_id)` so webhook redelivery is idempotent |
| `social_posts` | outbound posts + approval state (`draft/pending_approval/approved/rejected/published/failed`), `mixpost_post_uuid`, `target_accounts` JSON |
| `google_ads_leads` | raw lead-form payload + flattened name/phone/email/company/city, `processed` flag, link to `leads` |
| `approval_audit` | `submitted / approved / rejected / delegated / sla_breached` per post per level |
| `account_workspaces` | Mixpost workspace ⇄ Krayin pipeline mapping, `platform_accounts` JSON |
| `audit_log` | durable interaction trail (Krayin/Mixpost calls, audits, agent runs) |
| `llm_usage` | **extension**: one row per LLM call — model, `prompt_tokens`, `completion_tokens`, `cached_tokens`, `cost_estimate`, `fallback` |
| `schema_migrations` | applied migration bookkeeping (created by `migrate.php`) |

Documented extensions to the original column list: `leads.sentiment_score`,
`leads.routing_queue`, `leads.google_ads_lead_id`, `comment_queue.skip_reason/attempts/last_error/lead_id/received_at/processed_at`,
`social_posts.platforms/scheduled_for/attempts/last_error/mixpost_response`, and the
`llm_usage` table. Nothing was removed or renamed.

---

## 4. Credentials you must supply

`php audit.php` prints what is still missing; the dashboard shows the same list.
Everything else keeps working while a credential is absent — the affected integration
logs `credentials pending` instead of failing the pipeline.

| Variable | Where to get it | Unlocks |
|---|---|---|
| `DEEPSEEK_API_KEY` | platform.deepseek.com → API keys | LLM classification (without it: Ollama, then local rules) |
| `DB_*` | your MySQL server | everything persisted |
| `KRAYIN_API_TOKEN` | Krayin → Settings → API tokens (Sanctum personal access token) | lead push, approval tasks, status sync |
| `KRAYIN_API_URL` | e.g. `http://localhost/krayin/public` | Krayin REST base (`{url}/api/v1`) |
| `KRAYIN_DEFAULT_APPROVER_ID` | Krayin user id | assignee of the "Review & Approve Social Post" task |
| `MIXPOST_API_TOKEN` | Mixpost → workspace → Settings → API tokens | publishing |
| `MIXPOST_WORKSPACE_UUID` | Mixpost workspace URL/settings | publishing target |
| `MIXPOST_URL` | e.g. `http://localhost/mixpost` | Mixpost base |
| `META_VERIFY_TOKEN` | you invent it, paste the same value in the Meta app dashboard | Facebook/Instagram webhook verification |
| `META_PAGE_ACCESS_TOKEN`, `META_APP_ID`, `META_APP_SECRET` | Meta developer dashboard | posting/replies, `X-Hub-Signature-256` validation |
| `INSTAGRAM_ACCESS_TOKEN`, `INSTAGRAM_BUSINESS_ACCOUNT_ID` | Meta/Instagram Graph | comment fetch/reply, social listening |
| `GOOGLE_ADS_WEBHOOK_KEY` | you invent it, paste as the "integration key" in Google Ads lead form | lead-form webhook authentication |
| `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_ID`, `WHATSAPP_VERIFY_TOKEN` | Meta WhatsApp Cloud API | inbound WhatsApp messages |
| `TWITTER_*` | X developer portal | Twitter/X publishing via Mixpost |
| `DIGEST_TO` | your inbox | daily digest email |
| `LISTENING_KEYWORDS` | your brand/product terms | social listening mentions |
| `DASHBOARD_USER` / `DASHBOARD_PASS` | you choose them | Basic auth on the dashboard |

`PLATFORM_ACCOUNT_MAP` maps a logical platform to the Mixpost account id used when a
command/post does not specify accounts:
`{"instagram":1,"linkedin":2,"twitter":3,"facebook":4}`.

---

## 5. External services

### 5.1 Krayin CRM (central hub)

```bash
composer create-project krayin/laravel-crm
cd laravel-crm && cp .env.example .env      # set DB_*, APP_URL
php artisan key:generate
php artisan krayin:install                   # creates the admin user
php artisan serve --port=8000                # or point a vhost at ./public
```

1. Log in, create a **Pipeline** and note its id (`KRAYIN_PIPELINE_ID`).
2. Generate an API token (Settings → API tokens / Sanctum personal access token) →
   `KRAYIN_API_TOKEN`.
3. Verify the REST surface your version exposes, because Krayin's API evolves:

```bash
curl -s -H "Authorization: Bearer $KRAYIN_API_TOKEN" -H 'Accept: application/json' \
     "$KRAYIN_API_URL/api/v1/leads?limit=1"
```

`KrayinClient` posts `title`, `description`, `lead_value`, `user_id`, `entity_type`,
`lead_pipeline_id` and a `person.name`; activities are posted as
`title/type/comment/lead_id/user_id/is_done/schedule_from/schedule_to/participants`.
If your Krayin version names a field differently, adjust
`src/CRM/KrayinClient.php::leadPayload()` / `createActivity()` — nothing else depends
on the field names.

4. Register the webhook (Settings → Webhooks): `https://your-host/webhook/krayin`
   with events `lead.created`, `lead.updated`, `quote.won`, and set
   `KRAYIN_WEBHOOK_SECRET` — Krayin must send `X-Krayin-Signature` (HMAC-SHA256 of the
   raw body). Without the secret the signature check is skipped with a warning.

### 5.2 Mixpost (multi-account publisher)

```bash
git clone https://github.com/inovector/mixpost
cd mixpost && composer install && cp .env.example .env
php artisan key:generate && php artisan migrate
php artisan mixpost:install                  # creates the first workspace + user
```

1. Connect the social accounts in the Mixpost UI, note their **account ids** →
   `PLATFORM_ACCOUNT_MAP`.
2. Create an API token and copy the **workspace UUID** → `MIXPOST_API_TOKEN`,
   `MIXPOST_WORKSPACE_UUID`.
3. API base URL used by this app: `{MIXPOST_URL}{MIXPOST_API_PREFIX}/{workspaceUuid}`
   (default prefix `/mixpost/api`). Verify:

```bash
curl -s -H "Authorization: Bearer $MIXPOST_API_TOKEN" -H 'Accept: application/json' \
     "$MIXPOST_URL/mixpost/api/$MIXPOST_WORKSPACE_UUID/accounts"
```

4. If the prefix differs in your build, set `MIXPOST_API_PREFIX` or adjust the paths in
   `src/Social/MixpostManager.php` (`/posts`, `/posts/approve/{uuid}`, `/posts/{uuid}`,
   `/accounts`). Mixpost statuses handled: `draft, scheduled, published, failed,
   needs_approval, publishing` — a `needs_approval` response is approved automatically
   after our own chain completes.

### 5.3 Ollama (local LLM fallback)

```bash
curl -fsSL https://ollama.com/install.sh | sh
ollama pull llama3.2:3b
ollama serve                      # http://localhost:11434
```

The classifier uses Ollama when `DEEPSEEK_API_KEY` is empty, when the daily token budget
is exhausted, or when a DeepSeek call fails. When Ollama is also unreachable, a local
rule engine (`DeepSeekClassifier::heuristicResult()`) keeps the queue moving and marks
those results `degraded`.

### 5.4 DeepSeek

1. Create a key at platform.deepseek.com → `DEEPSEEK_API_KEY`.
2. Model ids default to `deepseek-v4-flash` (fast/cheap, used for **all** classification,
   sentiment, routing and command parsing) and `deepseek-v4-pro` (only if thinking mode
   is explicitly enabled). Override with `DEEPSEEK_MODEL_FAST` / `DEEPSEEK_MODEL_REASONING`
   if your account exposes different ids; the SDK's legacy aliases (`deepseek-chat`,
   `deepseek-reasoner`) are subject to retirement, so they are not hard-coded.
3. Transport: `DEEPSEEK_TRANSPORT=http` (default) uses the built-in Guzzle client and
   exposes real `usage` counters, including `prompt_cache_hit_tokens`, which the cost
   guard needs. `DEEPSEEK_TRANSPORT=sdk` routes the same calls through
   `deepseek-php/deepseek-php-client` (v2.1) instead.

---

## 6. Webhook URLs to register

| Platform | URL | Verification | Subscribe to |
|---|---|---|---|
| Meta (Facebook Page) | `https://your-host/webhook/meta` | `hub.mode=subscribe`, `hub.verify_token=META_VERIFY_TOKEN`, echoes `hub.challenge` | `feed` (comments), `messages` optional |
| Instagram | `https://your-host/webhook/meta` | same handshake | `comments`, `messages` |
| WhatsApp Cloud API | `https://your-host/webhook/whatsapp` | `hub.verify_token=WHATSAPP_VERIFY_TOKEN` (falls back to `META_VERIFY_TOKEN`) | `messages` |
| Google Ads lead forms | `https://your-host/webhook/google-ads?google_key=GOOGLE_ADS_WEBHOOK_KEY` | key compared to `GOOGLE_ADS_WEBHOOK_KEY` | lead form submission |
| Krayin CRM | `https://your-host/webhook/krayin` | `X-Krayin-Signature` HMAC-SHA256 (`KRAYIN_WEBHOOK_SECRET`) | `lead.created`, `lead.updated`, `quote.won` |

Meta app permissions typically needed: `pages_show_list`, `pages_read_engagement`,
`pages_manage_metadata`, `pages_manage_engagement`, `instagram_basic`,
`instagram_manage_comments`, `instagram_manage_messages`, `business_management`.

Manual smoke test of any route (`:8080` with `composer serve`, `:8081` for the Docker stack):

```bash
# Meta verification handshake
curl -s "http://127.0.0.1:8080/webhook/meta?hub.mode=subscribe&hub.verify_token=$META_VERIFY_TOKEN&hub.challenge=42"

# Google Ads style lead form
curl -s -X POST "http://127.0.0.1:8080/webhook/google-ads?google_key=$GOOGLE_ADS_WEBHOOK_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"campaign_id":"1","campaign_name":"Search","user_column_data":[
        {"column_name":"FULL_NAME","string_value":"Aarti"},
        {"column_name":"PHONE_NUMBER","string_value":"+919000000000"},
        {"column_name":"EMAIL","string_value":"a@example.com"}]}'
```

Every route answers `200` immediately with a JSON summary and does no LLM work inline.

---

## 7. Running it

### 7.1 Worker

```bash
php worker.php --once            # one batch, then exit (cron friendly)
php worker.php --daemon          # loop forever, sleeping WORKER_SLEEP_SECONDS
php worker.php --status          # queue depth, pre-filter rate, token spend, readiness
php worker.php --dry-run         # classify without writing anything
php worker.php --requeue-failed  # put failed rows back (attempts < 3)
php worker.php --retry-publish   # retry failed Mixpost publications
```

Each run self-limits to `--max-runtime=55` seconds by default, so the `* * * * *` cron
entry below can never stack processes.

### 7.2 Cron

```cron
* * * * * cd /var/www/social-bot && php worker.php >> /var/log/bot-worker.log 2>&1
*/30 * * * * cd /var/www/social-bot && php audit.php >> /var/log/bot-audit.log 2>&1
0 8 * * * cd /var/www/social-bot && php digest.php >> /var/log/bot-digest.log 2>&1
```

Extra scheduled agent runs (also available as flags):

```cron
15 */6 * * * cd /var/www/social-bot && php audit.php --listen    >> /var/log/bot-listen.log 2>&1
30 9 * * 1  cd /var/www/social-bot && php audit.php --evergreen >> /var/log/bot-evergreen.log 2>&1
```

### 7.3 Token-budget discipline (where each rule lives)

| Rule | Implementation |
|---|---|
| 1. thinking mode OFF everywhere | `DeepSeekClient::withThinkingMode(false)` (selects `deepseek-v4-flash`); every classifier/parser call passes `thinking => false` |
| 2. JSON mode + `max_tokens=400` | `withResponseFormat('json_object')`, `withMaxTokens(400)`; the guard appends "Respond with valid JSON only." to the **user** message if a prompt ever loses the word "json" |
| 3. 10–20 comments per call, never 1-per-call | `DeepSeekClassifier::classifyBatch()` clamps the batch size to 10–20 and chunks |
| 4. static prompts, no dates/timestamps | prompts are read once in the constructor from `prompts/`; dynamic data (comments, brand voice, analytics snapshot) only ever goes into the user message |
| 5. VADER pre-filter before DeepSeek | `SentimentFilter` (length, low-value reactions, promo spam, emoji-only, link spam) — target 40–60% skipped, reported by `worker.php --status` and `audit.php` |
| 6. Ollama fallback | `OllamaClient`, used when the key is missing, the budget is spent, or DeepSeek errors |
| 7. log every call | `llm_usage` table + `storage/logs/deepseek-*.log`: model, prompt/completion/cached tokens, latency, cost estimate, fallback flag |

Cost controls: `DEEPSEEK_DAILY_TOKEN_BUDGET` (hard ceiling, verified by
`LlmUsage::overBudget()` before every paid call), `WORKER_OFFPEAK_ONLY=true` with
`DEEPSEEK_OFFPEAK_WINDOWS` (UTC, defaults `04:00-06:00,10:00-01:00`, all weekend) to
schedule the heavy batches into DeepSeek's off-peak discount window, and
`audit.php` reporting the cache-hit rate plus any call that got zero cached prompt
tokens.

---

## 8. Command interface

```php
$handler = \SocialBot\AI\CommandHandler::fromConfig();

$handler->handle('announce our new plan on instagram at 6pm tomorrow', 'ops@example.com');
$handler->handle('list pending', 'ops@example.com');
$handler->handle('approve', 'manager@example.com');
$handler->handle('how did we do this week', 'ops@example.com');
```

`CommandParser` (static `prompts/command.txt`, JSON mode, thinking off, brand-voice
guidance injected into the user message) returns
`action / content / platforms / schedule / requires_approval / confidence`, with a
keyword parser fallback when no LLM is reachable. Actions route through
`CommandHandler`:

| Action | Effect |
|---|---|
| `create_post`, `schedule_post` | `PostApprovalWorkflow::requestPost()` |
| `list_pending` | pending `social_posts` |
| `approve_post` | `PostApprovalWorkflow::approvePost()` for the oldest pending post |
| `check_analytics` | `AnalyticsSummarizer::summarize()` (SQL aggregates + LLM recommendation) |

---

## 9. Approval workflow

`PostApprovalWorkflow::requestPost($content, $accounts, $requestedBy, $options)`

1. Krayin lead titled **"Social Media Post Request"**.
2. Krayin task **"Review & Approve Social Post"** (`type=task`, assignee
   `KRAYIN_DEFAULT_APPROVER_ID`, due in `APPROVAL_SLA_HOURS`).
3. `social_posts` row with `status=pending_approval`.
4. `ConditionalApproval::determineLevels($content)`:
   always **Social Media Manager**; **+ Finance Manager** when the content matches
   `/\b(price|cost|\$|₹|discount|offer)\b/i` (plus a currency-amount check such as
   `$500` / `₹999`); **+ Legal** when it matches
   `/\b(competitor|vs|alternative)\b/i`; always ends with **Brand Manager**.
5. `approval_audit` row `action=submitted`.

`approvePost($postId, $approver, $remarks, $level = null)` records the sign-off for the
acting level (defaults to the next pending one), creates the next Krayin task, and once
the whole chain has approved calls `publishToMixpost()`: create the Mixpost post
(`schedule=true`), auto-approve it in Mixpost when it comes back `needs_approval`,
update `social_posts` (`published`, `mixpost_post_uuid`, `published_at`), then
`KrayinClient::updateLeadStatus($krayinLeadId, 'approved')`.
`rejectPost()` stops the chain and records the reason; `markSlaBreaches()` (run by
`audit.php`) writes `sla_breached` rows and Krayin escalation tasks exactly once per
level.

---

## 10. Agents

| Agent | How to run | What it does |
|---|---|---|
| `SocialListeningAgent` | `php audit.php --listen` | pulls the newest Instagram comments into `comment_queue` and reports comments matching `LISTENING_KEYWORDS` across every ingested platform |
| `EvergreenRecycler` | `php audit.php --evergreen` | ranks old published posts by attributed leads (exact `post_id` match, else a documented keyword-overlap heuristic in a 72h window) and re-submits the winners through the approval workflow; content fingerprints prevent re-queuing the same post twice in 30 days |

---

## 11. Modules

| File | Responsibility |
|---|---|
| `src/AI/DeepSeekClient.php` | OpenAI-compatible chat client with the spec's fluent surface (`withThinkingMode`, `withMaxTokens`, `withResponseFormat`) plus token/cache accounting; optional `DEEPSEEK_TRANSPORT=sdk` bridge to `deepseek-php/deepseek-php-client` |
| `src/AI/DeepSeekClassifier.php` | `classifyBatch()` — VADER survivors, batched, JSON mode, normalisation, provider fallbacks |
| `src/AI/OllamaClient.php` | local fallback provider |
| `src/AI/CommandParser.php` / `CommandHandler.php` | NL → structured action → module |
| `src/AI/AnalyticsSummarizer.php` | SQL snapshot + `prompts/analytics.txt` narrative |
| `src/AI/BrandVoiceRAG.php` | offline TF-IDF retrieval over `prompts/brand_voice/*.md`, injected into user messages |
| `src/Ingestion/*` | Meta, Google Ads, Instagram (SDK or Graph API), WhatsApp webhooks |
| `src/Leads/LeadScorer.php` | intent (≤40) + sentiment (≤20) + authority (≤20) + likes (≤10) + recency (≤10), priority ≥70 P1 / ≥40 P2, qualified = ≥40 and intent ∉ {spam, none} |
| `src/Leads/SmartRouter.php` | complaint/negative → `support_queue`; purchase/pricing/demo + P1 → `sales_priority`; + P2/P3 → `sales_queue`; spam → `archive`; else `general_followup` (+ department/area, SLA per queue) |
| `src/CRM/KrayinClient.php` | `createLead`, `createActivity`, `updateLeadStatus`, plus audit + rate limits |
| `src/CRM/KrayinWebhookReceiver.php` | `lead.created/updated`, `quote.won`; `qualified` auto-assigns a sales task |
| `src/Social/MixpostManager.php` | `createPost`, `approvePost`, `getPost`, `listPendingApprovals`, `listAccounts`, payload builder |
| `src/Workflow/*` | `ConditionalApproval`, `PostApprovalWorkflow`, `CommentPipeline` (worker steps 1–7) |
| `src/Support/*` | container, config, env, PDO+illuminate database layer, JSON-line logger, HTTP client with retries, Redis/file cache, Symfony Messenger bus, VADER pre-filter, token ledger, audit log, request wrapper |

---

## 12. Validation

```bash
composer lint                                  # php -l over every PHP file
composer smoke                                 # end-to-end suite, in-memory SQLite
php migrate.php --status                       # migration state
php audit.php --json                           # platform health as JSON
php -r "require 'vendor/autoload.php'; echo 'OK';"
```

Everything below was executed against this exact tree (PHP 8.3.35, MariaDB 10.11, the
project's own `docker/Dockerfile` image, no API credentials configured):

| Check | Command | Result |
|---|---|---|
| Syntax gate | `php tools/lint.php` | **50 files checked, 0 failures** |
| Dependency resolution | `composer install` | 54 packages installed from the committed lock file; platform pinned to PHP 8.3 in `composer.json` (the pin is what forces Symfony 7.4 instead of 8.x) |
| Schema | `php migrate.php` | **8/8 migrations applied** — InnoDB tables, `ENUM`/`JSON` columns, 3 foreign keys and all indexes verified via `information_schema` |
| Idempotency | `php migrate.php --status` | `8 applied`, re-running reports "Nothing to migrate" |
| Both DB paths | `php migrate.php --status` | `mysql (pdo)` without vendor, `mysql (illuminate/database)` with it |
| Test suite | `php tests/smoke.php` | **29/29 passed** |
| Ingestion over HTTP | `GET/POST /webhook/{meta,whatsapp,google-ads,krayin}` | Meta + WhatsApp verification echoed the challenge (200); a wrong token and a wrong `google_key` returned 403; Instagram, Facebook, WhatsApp and Google Ads payloads queued (200); Krayin `lead.updated` synced; an unknown route returned 404 |
| End-to-end pipeline | `php worker.php --once` | 6 comments (IG, FB, WhatsApp, Google Ads, 2 seeded) became 6 leads with intent/sentiment/score/priority/routing; the promo-spam row was `skipped`, the Google Ads raw row was linked back (`processed=1`, `lead_id` set), queue rows closed |
| Fallback chain | same run, no `DEEPSEEK_API_KEY`, no Ollama | local rule engine answered, every result flagged `degraded`, and `llm_usage` recorded `provider=heuristic, fallback=1, ok=0` with the reason |
| Ops tooling | `php audit.php` | 18 checks; only credential warnings; exit code 1 (warnings) as designed |
| Digest | `php digest.php --no-send` | HTML report written to `storage/digests/` |
| Dashboard | `GET /` | 200 with the rendered lead inbox |

`tests/smoke.php` boots the real application and exercises the real classes with only
two substitutions: the database becomes in-memory SQLite (`tests/schema.sqlite.sql`) and
the LLM becomes a fake client. It covers the pre-filter rules and skip rate, the
scoring table and thresholds, all five routing branches, the conditional approval chain
(including `$500`/`₹999`), batch sizing and prompt stability, provider fallbacks
(over-budget → fallback provider → local rules), the full pipeline
(queue → leads → Krayin with a mocked HTTP handler, including the Google Ads
raw-row link-back), the approval workflow through to a mocked Mixpost publish,
SLA-breach idempotency, webhook verification/parsing for Meta and WhatsApp, the cache
primitives, the Symfony Messenger bus, and the audit log.

---

## 13. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `cannot connect to the database` | MySQL down or `DB_*` wrong; `php migrate.php` reports the exact target |
| `SQLSTATE[42S02]` on first run | migrations not applied: `php migrate.php` |
| Duplicate leads | expected and handled: `leads.comment_id` is unique and inserts use `INSERT IGNORE` |
| Krayin `401` | `KRAYIN_API_TOKEN` missing/expired, or the API module is disabled in your Krayin build |
| Krayin `422` | field names differ in your Krayin version — adjust `leadPayload()` (see 5.1) |
| Mixpost `404` | wrong `MIXPOST_API_PREFIX` or workspace UUID (see 5.2) |
| DeepSeek `Prompt must contain the word 'json'` | a prompt was edited to remove "JSON"; the client also auto-appends the requirement to the user message |
| Cache hit rate 0% | a prompt file is being modified at runtime, or the user message is huge and variable; `audit.php` lists the offending calls |
| `credentials pending: ...` | informational — fill the variable and re-run `php audit.php` |
| Nothing classified | queue depth in `php worker.php --status`; check `storage/logs/worker-*.log` |
| Redis unavailable | harmless: the cache falls back to `storage/cache` (files) |
| `composer install` times out while unzipping | slow bind mounts (Windows/macOS Docker, network drives): `COMPOSER_PROCESS_TIMEOUT=1800 composer install`, or install into a volume |
| Platform check error "requires a PHP version >= 8.4" | the lock was resolved on a newer PHP; `composer.json` pins `config.platform.php=8.3.0` — re-run `composer update` to regenerate the lock against 8.3 |
| VADER warnings about unknown tokens | the port warns on phone numbers/emails; `SentimentFilter` strips those tokens and scopes the warnings away, so nothing reaches your error log |
| `Class "GuzzleHttp\HandlerStack" not found` | `composer install` has not been run; the HTTP clients are lazy, but every outbound integration needs Guzzle |

---

## 14. Security notes

* The dashboard supports HTTP Basic auth (`DASHBOARD_USER`/`DASHBOARD_PASS`) and escapes
  every rendered value; keep the web root at `public/` so `src/`, `.env` and `storage/`
  are never served.
* Webhook endpoints validate Meta's `X-Hub-Signature-256` (when `META_APP_SECRET` is
  set), the Google Ads `google_key`, and Krayin's HMAC signature; the WhatsApp route
  uses the verify-token handshake.
* All SQL goes through prepared statements; identifiers are quoted and table names are
  never built from user input.
* LLM output is never trusted structurally: intents/sentiments/priorities are
  whitelisted and scores clamped before they touch the database.
* Secrets live only in `.env` (git-ignored).

---

## 15. Manual next steps after install

1. `cp .env.example .env` and fill section 4's credentials.
2. Create the MySQL database and run `php migrate.php`.
3. Point `KRAYIN_API_URL`/`MIXPOST_URL` at your installs; verify with the `curl`
   commands in 5.1/5.2.
4. Register the webhooks in section 6 (Meta, WhatsApp, Google Ads, Krayin).
5. Install the cron entries in 7.2.
6. `php audit.php` should end `Overall: OK` (or only informational notes).
7. `composer smoke` must pass; then open the dashboard and confirm the stats bar
   populates after the first webhook arrives.

---

## 16. Contributing, security and license

| Document | Purpose |
|---|---|
| [CONTRIBUTING.md](CONTRIBUTING.md) | local setup, the cost/correctness invariants that must not regress, coding standards, PR process |
| [SECURITY.md](SECURITY.md) | private vulnerability reporting and the operator hardening checklist |
| [CHANGELOG.md](CHANGELOG.md) | release history (Keep a Changelog / SemVer) |
| [LICENSE](LICENSE) | MIT |
| [.github/workflows/ci.yml](.github/workflows/ci.yml) | CI: lint, 29-check smoke suite, migrations against MySQL 8, worker dry run |
| [docker/docker-compose.yml](docker/docker-compose.yml) | local stack: worker, web, MySQL 8, Redis 7, optional Ollama |

```bash
composer lint && composer smoke     # the two commands CI gates on
```

Contributions are welcome: open an issue with one of the templates, branch from `main`, and
keep commits in [Conventional Commits](https://www.conventionalcommits.org/) form. Please
never commit real API keys, access tokens or customer data — `.env` is git-ignored and
`.env.example` documents every variable.
