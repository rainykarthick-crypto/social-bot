# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅        |

## Reporting a vulnerability

Please **do not** disclose security issues in public issues, discussions or pull requests.

Use GitHub's private reporting instead:
**Security → Advisories → Report a vulnerability**
(`https://github.com/rainykarthick-crypto/social-bot/security/advisories/new`).

If you cannot use that channel, email the maintainer listed in `composer.json` with:

- affected version/commit,
- a description of the impact,
- reproduction steps or a proof of concept,
- any suggested fix.

You can expect an acknowledgement within 3 working days and a status update within 10.
Please allow a coordinated disclosure window (typically 90 days) before publishing details.

## What this project handles

This service processes untrusted input and holds credentials, so the following areas are
security-relevant — reports touching them are especially welcome:

- **Webhook authentication.** Meta payloads are validated with `X-Hub-Signature-256`
  (`META_APP_SECRET`); Google Ads lead forms with the `google_key` shared secret; Krayin with
  an HMAC-SHA256 signature (`KRAYIN_WEBHOOK_SECRET`); WhatsApp through the verify-token
  handshake. Any bypass, timing leak or unsigned path is a vulnerability.
- **Prompt injection.** Comment text, lead-form values and DM bodies all reach the model.
  Output is re-validated against whitelists before it is written to the database, and the
  local rule engine never invents a purchase intent — but bypasses of that validation matter.
- **SQL injection.** All queries are parameterised through `SocialBot\Support\Database`;
  any user-influenced identifier or raw fragment is a bug.
- **Secret handling.** Credentials live only in `.env` (git-ignored). Never commit real keys,
  access tokens or webhook secrets, and redact them from logs, issues and screenshots.
- **Dashboard exposure.** `public/` is the only web root; `DASHBOARD_USER`/`DASHBOARD_PASS`
  enable HTTP Basic auth. Reports of data exposure through the dashboard, CSRF, or XSS in
  rendered lead/comment content are treated as high severity.
- **Denial of wallet.** The daily token budget (`DEEPSEEK_DAILY_TOKEN_BUDGET`) is a security
  control; ways to bypass it and burn paid tokens are valid reports.

## Hardening checklist for operators

- Serve only `public/` from the web root; never expose `.env`, `storage/` or `src/`.
- Terminate TLS in front of every webhook route and set all platform secrets.
- Set `DASHBOARD_USER`/`DASHBOARD_PASS`, and keep the dashboard off the public internet if
  possible.
- Rotate `KRAYIN_API_TOKEN`, `MIXPOST_API_TOKEN`, Meta/Instagram tokens and
  `GOOGLE_ADS_WEBHOOK_KEY` on a schedule.
- Watch `php audit.php` output: it flags pending credentials, failed queue rows and
  unpushed leads.
