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
composer lint                         # PHPCS — WordPress-Extra, WordPress-Docs, PHPCompatibilityWP
composer analyse                      # PHPStan
composer test                         # PHPUnit: unit + WordPress integration (needs a MySQL test DB, see phpunit.xml.dist)
php bin/build-assets.php              # minified CSS/JS for the theme and the plugin
bash bin/build-zip.sh                 # dist/diromax.zip (theme)
```

## Architecture

- `diromax-theme/` — the site's look and copy. Business data never lives here.
- `diromax-portal/` — the client portal plugin. Namespace `Diromax\Portal`, PSR-4 under `src/`.
  - `src/Domain` — pure PHP: money, VAT, ledger rules, the summary calculator. No WordPress calls; unit-tested to 100%.
  - `src/Security` — capabilities, the single access policy, rate limiting, document encryption, Turnstile.
  - `src/Data` — schema/migrations and one repository per table. Every query goes through `$wpdb->prepare()`.
  - `src/Service` — use cases (ledger, cases, documents, invites, login, notifications, mail).
  - `src/Admin`, `src/Portal` — controllers that build view models; `templates/` only prints them.
- Custom tables (InnoDB, foreign keys between them): cases, members, ledger, budget, updates, documents, audit, events, mail log, login tokens. ADR: cases are a table rather than a post type so ledger rows can reference them with a foreign key and the manager/status filters are indexed columns.
- Scheduled jobs (WP-Cron, run from system cron in production): the notification worker every 5 minutes, retention cleanup daily.

## Environments

- Production: https://diromax.co.il — deploy the theme ZIP and the plugin directory.
- Staging: required before production (section 10.4). Not set up yet.
- Local: any WordPress install with the theme and plugin symlinked in.

## Notes for AI agents

- Authorization lives in one place: `Diromax\Portal\Security\AccessPolicy`. Never check roles elsewhere.
- Money is integer agorot everywhere. Format only at the edge (`Domain\Money`).
- `DX_PORTAL_KEY` (base64, 32 bytes) must be defined in `wp-config.php` before documents can be uploaded. Losing it makes every stored document unreadable.
- Clients never reach wp-admin; everything they see is under `/portal/`.
