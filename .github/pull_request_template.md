## What does this change?

<!-- One or two sentences. Link the issue: "Closes #123". -->

## Type of change

- [ ] Bug fix
- [ ] New feature
- [ ] Refactor / internal cleanup
- [ ] Documentation
- [ ] CI / tooling

## How was it verified?

<!-- Exact commands and observed results. -->

- [ ] `composer lint` passes
- [ ] `composer smoke` passes (29 checks)
- [ ] `php migrate.php` applies cleanly against MySQL 8 / MariaDB
- [ ] Manually exercised the affected route/command (describe below)

## Cost and safety checklist

- [ ] No LLM call gained thinking mode, lost JSON mode, or dropped below a 10-comment batch
- [ ] `prompts/*.txt` stays byte-identical at runtime (no dates or dynamic values added)
- [ ] The VADER pre-filter still runs before any LLM call
- [ ] Every new LLM call is recorded through `LlmUsage`
- [ ] Model output is still validated/clamped before it reaches the database
- [ ] Missing credentials/integrations degrade with a logged warning instead of throwing
- [ ] New or changed SQL uses bound parameters, and all output is escaped
- [ ] No secrets, tokens or real customer data in code, tests, logs or screenshots

## Schema / configuration impact

<!-- New tables or columns? New env vars? Both README and CHANGELOG updated? -->

- [ ] `migrations/*.sql` and `tests/schema.sqlite.sql` updated together
- [ ] `.env.example` and the README credential table updated
- [ ] `CHANGELOG.md` updated
