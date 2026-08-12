# Eratotime

Personal, self-hosted, Calendly-style **request** tool. Invitees pick a slot; the app holds it (soft-hold), emails the organizer, and the organizer creates the calendar event manually. No calendar-write integration, no `.ics`, no tokenized reschedule/cancel in v1.

The authoritative build spec is `docs/eratotime-requirements.md` (in the workspace parent of this repo) — including the request-submission model, the `stephen@meertec.ltd` vs `meertec.ltd@gmail.com` identity decision, and the Easy!Appointments review in Appendix B. The schema in `db/eratotime_migration.sql` is authoritative over any summary in this file.

## Status

Phases 0–2 complete locally (scaffold, tenant-isolation tests, availability engine). Phase 3 (Google Calendar read-only sync) is blocked on the calendar-of-record decision and the Google Cloud Console OAuth app.

## Stack

- PHP 8.1+ backend, vanilla JS (ES modules) frontend, no framework
- MySQL/MariaDB, Composer deps: `google/apiclient`, `phpmailer/phpmailer`, `altcha-org/altcha`
- PHPUnit 11 for tests

## Setup (local dev)

```sh
composer install
cp config-sample.php config.php   # fill in real values; config.php is gitignored
vendor\bin\phpunit                 # 45 tests / 117 assertions
```

The tenant-isolation tests self-create a throwaway database (`eratotime_test`) and apply `db/eratotime_migration.sql` if the schema is absent. Connection defaults to the local XAMPP MariaDB (`127.0.0.1:3307`, root, empty password); override via `ERATO_TEST_DB_HOST/PORT/USER/PASS/NAME`.

## Layout

- `availability_lib.php` — layered availability engine (pure, DST-safe, entry point `availability_day($ctx)`)
- `tenant_lib.php` — tenant resolution from the URL path + settings load
- `config-sample.php` — config template (copy to `config.php`)
- `db/eratotime_migration.sql` — authoritative schema + seed
- `tests/` — availability engine + tenant-isolation suites
- `providers/`, `cron/`, `api/`, `js/` — empty, filled by later phases
