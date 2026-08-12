# Eratotime

Personal, self-hosted, Calendly-style **request** tool. Invitees pick a slot; the app holds it (soft-hold), emails the organizer, and the organizer creates the calendar event manually. No calendar-write integration, no `.ics` invites, no tokenized reschedule/cancel in v1.

The authoritative build spec is `docs/eratotime-requirements.md` — including the request-submission model, the `stephen@meertec.ltd` identity decision, the calendar-of-record (CalDAV/Baïkal) resolution in Appendix A, and the Easy!Appointments review in Appendix B. Build progress lives in `progress.md`. The schema in `db/eratotime_migration.sql` is authoritative over any summary in this file.

## Status

Phases 0–3 built and unit-tested locally (scaffold, tenant-isolation tests, availability engine, **CalDAV/Baïkal read-only sync** — live sync verified against the Baïkal calendar). 67 tests / 209 assertions green. Phase 4 (public booking page) is next.

## Stack

- PHP 8.2+, vanilla JS (ES modules) frontend, no framework
- MySQL/MariaDB; Composer deps: `sabre/dav`, `sabre/vobject`, `phpmailer/phpmailer`, `altcha-org/altcha` (`google/apiclient` only if the optional Google secondary source is built)
- PHPUnit 11 for tests; sodium extension required (encryption at rest)

## Secrets & config

All secrets live in a **gitignored `.env`** (never in committed `.php` files) — loaded by `env.php`/`config.php`:

```sh
composer install
cp .env.example .env     # fill in real values (DB, SMTP, CalDAV, keys)
cp config-sample.php config.php
```

`config.php` and `.env` are gitignored. The sodium key file (`ERATO_ENC_KEY_PATH`) is created on first use.

## Setup (local dev)

The tenant-isolation and sync tests self-create a throwaway database (`eratotime_test`) and apply `db/eratotime_migration.sql` if the schema is absent. Connection defaults to the local XAMPP MariaDB (`127.0.0.1:3307`, root, empty password); override via `ERATO_TEST_DB_HOST/PORT/USER/PASS/NAME`.

```sh
vendor\bin\phpunit        # 67 tests / 209 assertions
```

## Deployment (Hostinger)

1. **Files** — deploy the repo to `www.meertec.ltd/eratotime` (preferably **outside** `public_html`; if it must sit inside a web-served directory, the shipped `.htaccess` denies `.env`, `config.php`, and `*.key`).
2. **`.env`** — create on the live server from `.env.example` with real values: `ERATO_DB_*` (dedicated MySQL DB, spec 4.1), `ERATO_ENC_KEY_PATH`, `ERATO_ALTCHA_HMAC_KEY`, `ERATO_SMTP_*` (Hostinger mailbox), `ERATO_CALDAV_*` (Baïkal).
3. **`config.php`** — copy from `config-sample.php`.
4. **Schema** — apply `db/eratotime_migration.sql` to the deployment DB (seeds the `meertec` tenant, Baïkal + Google source rows, meeting types, working hours).
5. **CalDAV source** — `php bin/setup_caldav.php` encrypts the Baïkal credentials into `calendar_sources` and flips the source active.
6. **Crons** — schedule on Hostinger (cron job runner), e.g. every 10 min: `php .../cron/sync_calendars.php`; plus `cron/retry_notifications.php` and `cron/cleanup.php` once these exist.

## Layout

- `availability_lib.php` — layered availability engine (pure, DST-safe, entry point `availability_day($ctx)`)
- `tenant_lib.php` — tenant resolution from the URL path + settings load
- `calendar_sync_lib.php` — per-source sync orchestration (UID upsert/delete, fail-closed)
- `crypto_lib.php` — sodium secretbox encryption at rest
- `providers/caldav_provider.php` — CalDAV/ICS read-only busy-block sync
- `env.php` — minimal `.env` loader
- `bin/setup_caldav.php` — one-time: encrypt `.env` CalDAV creds into the source row
- `cron/sync_calendars.php` — calendar sync cron entry point
- `config-sample.php` — config template (copy to `config.php`)
- `db/eratotime_migration.sql` — authoritative schema + seed
- `tests/` — availability engine, tenant isolation, crypto, CalDAV provider, sync orchestration
- `api/`, `js/` — filled by later phases
