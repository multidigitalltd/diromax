# Project notes

<!-- Created once from the dev-standard repository. This file belongs to the project: edit it freely and keep it short. No secrets here. -->

## What this project is

The Diromax website (brokerage and property-improvement management) and its client portal: a private area where each client follows their property file — agreement, budget, expenses, payments, payment requests, documents and progress updates — and receives a branded email on every update.

## Risk level

- **Client portal (`diromax-portal` plugin): level 3.** It holds financial records of private clients and their signed agreements (which usually carry ID numbers). Section 5.1.3 of the standard. Decided by the owner on 2026-11-18.
- **Marketing site (`diromax-theme`): level 1.** Public content and a lead form.

Level 3 obligations that live outside the code: an external penetration test before launch and every 18 months, MFA for every admin and project-manager account, and active monitoring. See `EXCEPTIONS.md` for the approved deviations.

## Stack

- WordPress 6.8+ (tested on 7.1), PHP 8.3+ for the plugin. The theme still declares PHP 8.0 for its own code.
- MySQL 8 / MariaDB 10.11, InnoDB, utf8mb4.
- No runtime dependencies. Composer is used for development tools only (`composer.lock` is committed).
- Mail through `wp_mail()`, like the rest of the site.
- Document encryption with libsodium (bundled with PHP).

## Commands

```bash
composer install                      # dev tools
composer lint                         # PHPCS — WordPress-Extra, WordPress-Docs, PHPCompatibilityWP (plugin: phpcs.xml.dist, theme: phpcs-theme.xml.dist)
composer analyse                      # PHPStan level 8 (plugin: phpstan.neon.dist, theme: phpstan-theme.neon.dist)
composer test                         # PHPUnit: unit + WordPress integration (needs a MySQL test DB, see phpunit.xml.dist)
bash diromax-portal/tests/e2e/setup.sh /tmp/dx-e2e   # throwaway WordPress for the browser tests (client, manager, phase2 scripts)
php bin/build-assets.php              # minified CSS/JS for the theme and the plugin
bash bin/build-zip.sh                 # dist/diromax.zip (theme)
bash bin/build-plugin-zip.sh          # dist/diromax-portal.zip (plugin, without tests)
```

## Architecture

- `diromax-theme/` — the site's look and copy. Business data never lives here, except the leads from the contact form (custom post type `dx_lead`).
  - Multi Digital web defaults (standard 15): leads have their own capability (administrators and project managers), status, internal notes, consent record, campaign source, retention with automatic deletion (default 24 months), an audited CSV export and a retried notification (`inc/leads-crm.php`); Turnstile on the lead form when `DX_TURNSTILE_*` are defined (`inc/turnstile.php`); first-visit privacy notice, legal footer links and the Multi Digital credit (`inc/privacy.php`).
  - The theme is loaded in the PHPUnit integration suite (`ThemeWebDefaultsTest`, `ThemeHelpersTest`) and covered by `tests/e2e/theme.e2e.js`. It has its own PHPCS ruleset (`phpcs-theme.xml.dist`: PHP 8.0+, text domain `diromax`, prefix `dx`) and PHPStan config (`phpstan-theme.neon.dist`, level 8), both run by `composer lint` / `composer analyse` and in CI.
- `diromax-portal/` — the client portal plugin. Namespace `Diromax\Portal`, PSR-4 under `src/`.
  - `src/Domain` — pure PHP: money, VAT, ledger rules, the summary calculator. No WordPress calls; unit-tested to 100%.
  - `src/Security` — capabilities, the single access policy, rate limiting, document encryption, Turnstile.
  - `src/Data` — schema/migrations and one repository per table. Every query goes through `$wpdb->prepare()`.
  - `src/Service` — use cases (ledger, cases, documents, invites, login, notifications, mail).
  - `src/Admin`, `src/Portal` — controllers that build view models; `templates/` only prints them.
  - Admin screens share one shell (`templates/admin/parts/header.php`: brand bar, plugin navigation, page head) and the portal's design tokens; `admin.css` is scoped to `body.dxa-screen`, so the rest of wp-admin is untouched. The dashboard totals come from each active case's cached summary (one query), the activity feed from one audit query scoped to the manager. `Service\DemoCase` builds a sample case through the regular services (no clients, so no email).
- Custom tables (InnoDB, foreign keys between them): cases, members, ledger, budget, updates, documents, audit, events, mail log, login tokens, decisions, acknowledgements (schema version 2). ADR: cases are a table rather than a post type so ledger rows can reference them with a foreign key and the manager/status filters are indexed columns.
- Scheduled jobs (WP-Cron, run from system cron in production): the notification worker every 5 minutes, retention cleanup daily. The once-a-day emails (payment reminders, daily digests, monthly summary) run inside the five-minute worker after the configured local hour (`Service\DailyJobs`); there is no third schedule.
- Phase 2 (spec section 15): manager dashboard, reminders, per-member email mode, decisions for client approval, agreement read confirmation, CSV export, case from a theme lead, password sign-in for clients only.

## Environments

- Production: https://diromax.co.il — deploy the theme ZIP and the plugin directory.
- Staging: required before production (section 10.4). Not set up yet.
- Local: any WordPress install with the theme and plugin symlinked in.

## Notes for AI agents

- Authorization lives in one place: `Diromax\Portal\Security\AccessPolicy`. Never check roles elsewhere.
- Money is integer agorot everywhere. Format only at the edge (`Domain\Money`).
- `DX_PORTAL_KEY` (base64, 32 bytes) must be defined in `wp-config.php` before documents can be uploaded. Losing it makes every stored document unreadable.
- Clients never reach wp-admin; everything they see is under `/portal/`.
- Staff never sign in through the portal's password form (it would bypass two-factor); `PasswordService` refuses anyone who is not client-only.
- Every portal form posts back with a nonce bound to the object and answers a foreign case with 404, exactly like a missing one.
