---
name: Bug report
about: Something is not working as documented
title: '[Bug] '
labels: bug
assignees: ''
---

## What happened

<!-- A clear description of the bug, and what you expected instead. -->

## Steps to reproduce

1.
2.
3.

## Environment

| | |
|---|---|
| PHP version (`php -v`) | |
| MySQL / MariaDB version | |
| Redis version (or "files fallback") | |
| How it runs (Docker compose / plain PHP / other) | |
| Krayin version (if relevant) | |
| Mixpost version (if relevant) | |

## Component

<!-- Tick everything that applies. -->

- [ ] `worker.php` / classification pipeline
- [ ] Webhook ingestion (Meta / Instagram / WhatsApp / Google Ads / Krayin)
- [ ] Scoring or routing (`LeadScorer`, `SmartRouter`)
- [ ] Krayin client or webhook receiver
- [ ] Mixpost client or publishing
- [ ] Approval workflow
- [ ] Dashboard (`public/`)
- [ ] CLI (`migrate.php`, `audit.php`, `digest.php`)
- [ ] Docker / CI

## Evidence

```text
# Relevant log lines (storage/logs/*.log, docker compose logs app, worker.php --status, audit.php)
# REDACT API keys, tokens, webhook secrets and customer data before pasting.
```

## Checklist

- [ ] I ran `php audit.php` and included the failing checks
- [ ] I ran `composer smoke` (does it still pass?)
- [ ] I searched existing issues for a duplicate
- [ ] No secrets or personal data are included above
