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
bash diromax-portal/tests/e2e/setup.sh /tmp/dx-e2e   # throwaway WordPress for the browser tests
# then, from diromax-portal/tests/e2e, in this order (each builds on the last):
#   node client.e2e.js /tmp/dx-e2e; manager; phase2; theme; manage; phase3; and last perf.e2e.js
php bin/build-assets.php              # minified CSS/JS for the theme and the plugin
bash bin/build-zip.sh                 # dist/diromax.zip (theme)
bash bin/build-plugin-zip.sh          # dist/diromax-portal.zip (plugin, without tests)
php bin/build-icons.php               # app icons for the management app, from the theme logo (needs GD)
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
  - The management app at `/portal/manage/` (`Portal\ManageController`) renders the same admin templates and view models (`Admin\Pages::*_data()`) in a page of its own (`templates/manage/shell.php`), without the theme or wp-admin. `Admin::use_front()` points every plugin link at the app; form handlers detect the app from the form's referer (`Router::is_manage_url()`) and send the user back there. Same capability checks, same admin-post handlers and nonces. Rewrite rules refresh once per `Router::RULES_VERSION`, since replacing the plugin by upload does not run activation.
  - Releases: bump `Version` in `diromax-portal/diromax-portal.php` and `DX_PORTAL_VERSION` together (theme: `style.css` and `DX_VERSION`); `ReleaseTest` fails if they differ. Asset URLs carry the version and a content hash (`Support\AssetFile`, theme `dx_asset_version()`), so a changed file is never served from a stale cache. Changes are listed in `CHANGELOG.md`.
  - The key guard: `DocumentStore` remembers the fingerprint (an HMAC made with the key) of the key the first document was stored with; `DocumentService::key_status()` reports a changed key while documents exist, blocks uploads, and staff screens show an error.
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
- Staff never sign in through the portal's password form (staff sign in on wp-login.php); `PasswordService` refuses anyone who is not client-only.
- Every password sign-in needs an emailed 6-digit code (`Service\LoginCodeService`; wp-login.php through `Security\LoginGate`, the portal through `Portal\Controller`). Sign-in links need none. `DX_PORTAL_EMAIL_CODE_OFF` is the emergency switch. This is exception 5 in `EXCEPTIONS.md`.
- All site mail goes through Postmark when a server token is set (`Service\Postmark` on `pre_wp_mail`). Postmark and Turnstile settings are saved on the plugin's settings screen by `Support\Integrations` (secrets sealed with `DX_PORTAL_KEY`, context `integration|{field}`; exception 6); a `wp-config.php` constant wins and locks the field. Read them only through `Support\MailConfig` and `Security\Turnstile` (the theme through `dx_turnstile_site_key()` / `dx_turnstile_secret()`), never `constant()`.
- Budgets are per case (schema 7, spec 19.2): `dx_budget` holds one row per section and sub-section ("category" is the line key, unique in the case; "parent" the section key; `wpdb` maps a column named `parent` to `%d`, so inserts pass formats). `Domain\BudgetTree` holds the rules (edit, move, roll-up) and `Domain\BudgetTemplate` parses the default from the settings textarea. A ledger entry's `category` is a line key; the validator allows only the case's own keys. New cases are seeded from `Settings::budget_template()`; `BudgetRepository::adopt_sections()` upgraded schema 6 data.
- Meetings (schema 8, spec 20.1): `dx_meetings` (UTC times, `sequence` for calendar updates, reminder flags) and `dx_meeting_people`. `Service\MeetingService` sends each invited client who gets email a `meeting.ics` (`Domain\Ics`, RFC 5545, METHOD REQUEST/CANCEL) through `Notifier::send_to_member()` with an attachment; reminders run in `DailyJobs`. Form rules are pure in `Domain\MeetingInput`.
- Payments forecast (spec 20.2): `Domain\Forecast` (pure: buckets, overdue, variance) over `DashboardRepository::open_requests()` and the cases' cached summaries; `Service\ForecastService` gates it to administrators (`dx_manage_portal`) for the screen and the CSV.
- Payment plan (schema 9, spec 21): `dx_plan` holds each case's payments (`planned` → `draft` → `sent`, or `cancelled`; "paid" is read from the linked request) and `cases.plan_base` the base for shares (0 = the budget total). `Domain\PaymentPlan` is pure (validation, shares in basis points, totals). `Service\PlanService` makes drafts when a stage is marked done (hooked in `CaseService::save()`), on a date (`DailyJobs`), at once when a payment is added after its time came, or by hand; `PlanRepository::to_draft()` is a conditional UPDATE so a payment is drafted once. Approving a draft goes through `LedgerService::add()` like any request. New cases copy `Settings::plan_template` (stage or manual payments only).
- Timeline (schema 10, spec 22): `dx_schedule` holds a "stage" row per stage (planned start, the end first shown to the client, the note on a moved end) and "milestone" rows; a stage's planned end stays the stage's `due` in `cases.stages`, so late flags and the timeline share one date. `cases.schedule_visible` publishes it. `Domain\Timeline` is pure (bar geometry in percent, plan from `Settings::schedule_weeks`). `Service\ScheduleService` keeps first dates and notes and enqueues `schedule_changed` (card built at send time in `MailItems`) only when the manager asks. The drawing (`templates/portal/parts/gantt.php`, shared by both sides) is `aria-hidden`; the facts are also text.
- Site visits (schema 11, spec 23): `dx_visits` and `dx_findings` (findings cascade with their visit and case; their `photo_id`/`after_photo_id` point into `dx_photos`, SET NULL). `Domain\SiteVisit` holds the rules (visit/finding validation, overdue, counts). `Service\VisitService` stores finding photos through `PhotoService::attach()` (the journal, encrypted) and keeps each photo shown only while its finding and visit are; views read findings and photos in one query each (`PhotoRepository::find_many()`). Overdue findings feed the dashboard and `MorningSummary`; `visit_published` is a `MailItems` card. Note: a tab's data must not reuse a key of `Pages::case_data()` (`counts`, `tabs`…) because they are merged.
- Quote comparisons (schema 12, spec 24): `dx_comparisons` (draft → open → chosen, or withdrawn; `entry_id` links the ledger commitment, SET NULL) and `dx_offers` (cascade; `document_id` into `dx_documents`, SET NULL). `Domain\QuoteComparison` holds the rules. `Service\QuoteService` uploads quotes as hidden "quote" documents and shows them on send; the client's choice is a conditional UPDATE (`QuoteRepository::choose()`), allowed to owners and partners (`DecisionService::may_answer()`), posted through the portal's `case_action` step `choose`; `commit()` locks the row and adds a committed expense through `LedgerService::add()`. Views read comparisons, quotes and documents in one query each.
- Case deletion (spec 19.1): administrators only, any status; `CaseService::delete()` removes files, rows (foreign keys cascade), the lead link and client accounts that are in no other case (never staff), and returns how many accounts went.
- Webhooks: `Portal\Webhooks` (`/wp-json/diromax-portal/v1/postmark/events|inbound`), HTTP Basic with the webhook key, compared as SHA-256 against `Integrations::webhook_hash()` (the key is generated on the settings screen and shown once, or `DX_POSTMARK_WEBHOOK_KEY`); they never sign in a WordPress user. Client replies are sorted by `Service\InboundService` and kept in `dx_messages`.
- `wp_tempnam()` and other `wp-admin/includes` functions are missing on the portal and in REST requests: use `Support\TempFile`.
- Performance budget (standard 9.1–9.2): `tests/e2e/perf.e2e.js` runs Lighthouse on the key pages (home, portal sign-in, client case and documents, management dashboard and case), phone and desktop, and fails CI over budget: score 90 / 95, LCP 2.5 s, CLS 0.1, TBT 200 ms (lab stand-in for INP), TTFB 800 ms, 200 KB of JavaScript. It reuses the sessions phase3 saves (sign-ins are rate limited: 5 per address per 15 minutes). The E2E router gzips CSS/JS/SVG like production; HTML is measured uncompressed, which is stricter.
- Never insert content above existing content after load (layout shift): wide tables are wrapped in `.dxa-scroll` in the templates, not by script.
- Messages (spec 18.1): one thread per case in `dx_messages` (`direction` in/out, `channel` email/portal), through `Service\MessageService`. Clients write with `post()`, staff answer with `reply()`. Email replies (17.3) join the same thread.
- Photos (spec 18.2): `dx_photos` plus two encrypted files each (the image, at most 2560 px, and a 640 px thumbnail), through `Service\PhotoService`. They are served by `/portal/photo/{id}/[thumb/]`, checked with `PhotoService::may_view()`. A case deletion must delete their files too (`CaseService::delete`).
- Never put a `<form>` inside a `<p>`: the parser closes the paragraph first, so selectors and styles miss the buttons.
- Every portal form posts back with a nonce bound to the object and answers a foreign case with 404, exactly like a missing one.
