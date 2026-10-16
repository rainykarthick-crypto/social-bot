# Contributing

Thanks for taking the time to contribute. This project is a PHP 8.3 service that talks to
LLM APIs, CRMs and social platforms, so a few invariants matter more than style here.

## Getting set up

```bash
git clone https://github.com/rainykarthick-crypto/social-bot.git
cd social-bot
composer install
cp .env.example .env
```

You do **not** need MySQL, Redis, Krayin, Mixpost or any API key to work on the codebase:

```bash
composer lint     # php -l over every file
composer smoke    # 29-check end-to-end suite on in-memory SQLite with a fake LLM
```

For a full local stack (MySQL 8, Redis 7, dashboard, worker):

```bash
docker compose -f docker/docker-compose.yml up -d
docker compose -f docker/docker-compose.yml run --rm app php migrate.php
# dashboard on http://127.0.0.1:8081
```

## Invariants — please do not regress these

These are cost and correctness guarantees, enforced by review:

1. **Thinking mode stays off** for classification, sentiment, routing and command parsing
   (`withThinkingMode(false)` / `thinking => false`); only explicit reasoning tasks may use
   `deepseek-v4-pro`.
2. **JSON mode stays on** with `max_tokens=400` for every structured call.
3. **Batching stays between 10 and 20 comments per request.** Never one request per comment.
4. **`prompts/*.txt` must stay byte-identical at runtime.** No dates, timestamps, tenant
   names or other dynamic values — dynamic data belongs in the *user* message. This is what
   makes DeepSeek's prompt cache work; `audit.php` reports cache misses.
5. **The VADER pre-filter runs before any LLM call.** If a change lowers the skip rate below
   the 40–60 % band, explain why in the pull request.
6. **Every LLM call is recorded** in `llm_usage` (tokens, cached tokens, cost estimate,
   fallback flag).
7. **Never trust model output structurally.** Whitelist intents/sentiments/priorities and
   clamp scores before anything reaches the database.
8. **Degrade, never crash.** A missing credential, an unreachable CRM or a missing Redis must
   log and continue; see `src/Support/` for the fallback patterns already in place.

## Coding standards

- PHP 8.3, `declare(strict_types=1);` in every file, PSR-12 formatting, PSR-4 autoloading
  (`SocialBot\` → `src/`).
- No closing `?>` in PHP-only files.
- All SQL through `SocialBot\Support\Database` with bound parameters; never interpolate user
  input. Escape all HTML output with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Prefer returning a structured `['ok' => bool, 'error' => ?string, ...]` over throwing for
  anything that crosses a network boundary.
- Keep classes focused: one reason to change, dependencies injected, no static singletons
  outside `App`, `Config` and `Logger`.

## Tests

- `composer smoke` must pass. Add assertions to `tests/smoke.php` for behavioural changes
  (it runs the real classes with SQLite and a fake LLM client).
- If you add a table or column, update **both** `migrations/*.sql` and
  `tests/schema.sqlite.sql`, and note it in `CHANGELOG.md`.
- If you add a webhook route, add a parsing/verification test for it.

## Pull requests

1. Branch from `main`: `git checkout -b feat/short-description`.
2. Keep commits focused and use [Conventional Commits](https://www.conventionalcommits.org/):
   `feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`, `perf:`, `ci:`.
3. Update `README.md` and `CHANGELOG.md` when behaviour, configuration or setup changes.
4. Run `composer lint && composer smoke` before pushing; CI runs the same plus MySQL 8
   migrations.
5. Fill in the pull request template, including how you verified the change and any
   follow-up work.

## Reporting bugs and security issues

- Bugs and feature requests: use the issue templates.
- Security vulnerabilities: **do not** open a public issue — follow [SECURITY.md](SECURITY.md).

## License

By contributing you agree that your contributions are licensed under the MIT License
(see [LICENSE](LICENSE)).
