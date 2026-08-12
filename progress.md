# Eratotime — Build Progress

## Current Phase: Phase 3 — CalDAV/Baïkal Read-Only Sync

### Status: Code built + tested locally; live sync needs the .env credentials step

Phases 0–2 are complete locally. Baïkal is **installed** on Hostinger (calendar URL verified live, Basic auth confirmed) and Phase 3's code is built and unit-tested. The remaining step is credential wiring: put the CalDAV password in `.env`, create `config.php`, run `bin/setup_caldav.php`, then confirm `cron/sync_calendars.php` against the deployment database.

### Model note (2026-08)
Requirements doc is **v2 (request-submission model)** — see `docs/eratotime-requirements.md` revision note and section 1.6. The app collects requests + soft-holds slots + notifies the organizer, who creates calendar events manually. **No calendar write path, no `.ics`, no tokenized reschedule/cancel** in v1. The Gmail address (`meertec.ltd@gmail.com`) must never appear in anything the app sends. `db/eratotime_migration.sql` is the authoritative schema.

### Dependencies
- [ ] MySQL database credentials from hPanel (DB name, username, password, host) — needed for live deployment; local dev uses the XAMPP MariaDB (see Local dev env below)
- [ ] Composer method on Hostinger (SSH install vs vendor-and-commit) — unknown; composer works fine locally
- [ ] **Baïkal install + `stephen@meertec.ltd` user/calendar + app-specific password** (calendar of record — needed by Phase 3; requirements §1.6/Appendix A)
- [ ] **Verify Baïkal server-side invite sending at Phase 3** (the earlier `invite_from` claim) — safe default is the Thunderbird client sends invites from `stephen@meertec.ltd` (requirements §2.4/3.4)
- [ ] Optional: Google Cloud Console OAuth app (**read-only** scope) — only if the Google secondary source is built
- [x] **Calendar-of-record resolved (2026-08-12): CalDAV/Baïkal under `stephen@meertec.ltd`** (requirements Appendix A). Google `meertec.ltd@gmail.com` = optional read-only secondary source + phone-alert attendee trick. Phone calendar = Google-alert trick (no DAVx⁵ app). Video = fixed Google Meet link auto-embedded in events via the `.ics` import (requirements §2.1).
- [x] **Build vs. adopt resolved:** custom build confirmed (2026-08). Easy!Appointments 1.6.0 reviewed from source; learning points in requirements Appendix B — not adopted.

### Local dev env
- XAMPP MariaDB 10.4.32 runs on **port 3307** (not the default 3306) on this machine. Root/empty password locally.
- Throwaway test database: `eratotime_test` — created automatically by the isolation test if absent; schema applied from `db/eratotime_migration.sql`. Verified: the migration runs cleanly and seeds tenant `meertec`, the two meeting types (30/60-min, 24h notice / 14d horizon), Mon–Fri 09:00–17:30, `stephen@meertec.ltd`, `request_hold_hours=24`, retention 30, and the inactive Google calendar source.
- Tenant-isolation test connection is configurable via `ERATO_TEST_DB_HOST/PORT/USER/PASS/NAME` (defaults: 127.0.0.1 / 3307 / root / '' / eratotime_test).
- Run tests: `composer install` then `vendor\bin\phpunit` (45 tests, 117 assertions — green).

---

## Phase Log

### Phase 0 — Environment & Scaffolding
- **Status:** Complete locally (not yet deployed to Hostinger)
- **Started:** 2026-08-12
- **Completed:** 2026-08-12
- **Notes:**
  - Project root: `eratotime\` (git repo, branch `main`). `.gitignore` excludes `config.php`, keys, `/vendor/`, uploads content.
  - `composer.json`: `google/apiclient ^2.16`, `phpmailer/phpmailer ^6.9`, `altcha-org/altcha ^2.1`, dev `phpunit/phpunit ^11` — all installed locally OK.
  - `config-sample.php` written (DB, encryption key path outside web root, ALTCHA HMAC key, admin, SMTP). Copy to `config.php` with real values at deploy.
  - `db/eratotime_migration.sql` = copy of the authoritative schema + seed; ran cleanly against MariaDB.
  - `tenant_lib.php` (tenant resolution + settings load) and `availability_lib.php` (engine) written and lint-clean.
  - Empty `providers/`, `cron/`, `api/`, `js/`, `uploads/` created for later phases.
  - **Handoff to Phase 3+:** confirm Composer method on Hostinger (SSH vs vendor-and-commit); real DB name/user/pass from hPanel when available; the local test DB is `eratotime_test` and is NOT the deployment database.

### Phase 1 — Tenant Isolation Test
- **Status:** Complete
- **Prerequisites:** Phase 0 complete
- **Started:** 2026-08-12
- **Completed:** 2026-08-12
- **Notes:**
  - `tests/TenantIsolationTest.php` seeds a throwaway fixture tenant (`isolation-fixture`) with overlapping data (same meeting-type slugs `30-min`/`60-min`, similar calendar label, same working hours, a blocked override + blockout + pending request on the same date as the real tenant's fixture) and proves no scoped query leaks across tenants — direct tables via data provider, indirect tables (`meeting_type_questions`, `calendar_blockouts`, `notification_outbox`) via parent joins, `tenant_lib` loads, path parsing, and availability (B's override/blockout must not affect A).
  - **Handoff:** the fixture tenant `isolation-fixture` is a permanent test fixture — keep it, never delete it as part of normal cleanup. Test self-creates `eratotime_test` + schema if absent. Keep this suite passing in every later phase (it's the regression check).

### Phase 2 — Availability Engine
- **Status:** Complete
- **Prerequisites:** Phases 0–1 complete
- **Started:** 2026-08-12
- **Completed:** 2026-08-12
- **Notes:**
  - Five layers per requirements §2.2: template → overrides → blockouts → soft-holds → buffers. Interval math is a **sorted-merge subtraction** (`availability_merge_intervals` / `availability_subtract`) — deliberately not EA's append-while-iterate splits. Grid walk `availability_fit_slots` starts at the first grid-aligned point satisfying the start-side buffer.
  - Wall-clock throughout; real timestamps only for min-notice (strictly `>` threshold) and max-horizon (day-granular, inclusive, tz-correct via organizer `now`).
  - Entry point: `availability_day(array $ctx)` — signature/fields documented in the file header. **Phase 3 wires real `calendar_blockouts` rows into `ctx['blockouts']` and pending soft-holds into `ctx['soft_holds']` without changing this interface.**
  - 29 unit tests (`tests/AvailabilityEngineTest.php`) covering the spec §8 matrix incl. EA regressions: fully-contained blockout, back-to-back merge, all-day block, workday-boundary clamp, zero-length ignored, buffers, soft-holds, min-notice/max-horizon edges, DST wall-clock invariance + DST-aware notice gate, caps.
  - **Handoff to Phase 3:** `availability_day()` contract is stable — build the caller in `api/slots.php` to supply organizer-tz blockouts/soft-holds and the organizer-tz `now`.

### Phase 3 — CalDAV/Baïkal Read-Only Sync (calendar of record)
- **Status:** Code built + unit-tested locally; **live sync blocked on the CalDAV credentials in .env**
- **Prerequisites:** Phases 0–2 complete; **calendar-of-record decision resolved (2026-08-12: CalDAV/Baïkal — requirements Appendix A)**; **Baïkal installed on Hostinger with `stephen@meertec.ltd` user + calendar + app-specific password**
- **Started:** 2026-08-12
- **Completed:** (code) 2026-08-12
- **Notes:**
  - Baïkal live at `https://www.meertec.ltd/baikal/html/dav.php/calendars/stephen@meertec.ltd/default/` — smoke-tested: responds **401 `WWW-Authenticate: Basic realm="sabre/dav"`** (endpoint live, Basic auth confirmed). Migration seed updated to this URL.
  - **Built:** `crypto_lib.php` (sodium secretbox at rest); `providers/caldav_provider.php` (REPORT calendar-query, sabre/vobject parsing — timed/all-day/cancelled/floating/recurring via `Recur\EventIterator`, ICS feed fallback, injectable HTTP transport); `calendar_sync_lib.php` (UID-keyed idempotent upsert/delete, fail-closed staleness, activity-log on failure); `cron/sync_calendars.php`; `bin/setup_caldav.php` (encrypts .env creds into calendar_sources).
  - **Secrets:** new `.env` (gitignored) approach — secrets live in `.env`, loaded by `env.php`/config.php, never in committed .php files. CalDAV password goes in `ERATO_CALDAV_PASSWORD`, then `php bin/setup_caldav.php` encrypts it into `calendar_sources.credentials_encrypted` and flips the source active.
  - **Tests:** 67 total / 208 assertions green — incl. crypto round-trip + tamper, provider XML/parsing/recurrence/mock-http, sync upsert/idempotency/deletion/failure/staleness against the local test DB. (Fixed a test fixture bug that emitted invalid `T1000Z` iCal times, and enabled `sodium` in local php.ini.)
  - **Remaining to finish Phase 3 live:** (1) fill `.env` with the CalDAV password; (2) `cp config-sample.php config.php`; (3) run `php bin/setup_caldav.php` against the deployment DB; (4) run `php cron/sync_calendars.php` and confirm blockouts match the Baïkal calendar.
  - **Verify Baïkal's server-side invite sending** (`invite_from` claim) — safe default: Thunderbird sends invites from `stephen@meertec.ltd`. (Baïkal DOES have an `invite_from` config field on the installer screen; leaving it empty = client-sent invites.)
  - **Do NOT** build any `.ics`/iTIP invite *sent to invitees*, or derive `ORGANIZER` from anything — the app builds no calendar invite; the SMTP `From:` always comes from `global_settings.mailbox_destination` (`stephen@meertec.ltd`). The only `.ics` the app produces is the organizer's own calendar-import file (Phase 5).

### Phase 4 — Public Booking Page
- **Status:** Not started
- **Prerequisites:** Phases 0–3 complete
- **Started:**
- **Completed:**
- **Notes:**
  - Availability rendering only in this phase (no submission yet). Includes ALTCHA widget + honeypot on the form and the iframe `postMessage` resize handshake (requirements §2.7).

### Phase 5 — Request Submission Flow
- **Status:** Not started
- **Prerequisites:** Phase 4 complete
- **Started:**
- **Completed:**
- **Notes:**
  - **Definition-of-Done additions (v2):** no `.ics`/iTIP attachment and no calendar event creation — verify instead that (a) the confirmation email's `From:` header reads `stephen@meertec.ltd`, (b) `meertec.ltd@gmail.com` appears nowhere in any email, page, or link the app produces, and (c) the Google Calendar quick-add link in the organizer notification email is correct (right times, invitee, location; no Gmail address in it).
  - Submission runs in a transaction: server-side availability re-check → `request_log` write (soft-hold) → `notification_outbox` queue (invitee email, organizer email, optional WhatsApp). Rate-limited + ALTCHA-verified (requirements §4.2).

### Phase 6 — Admin Panel
- **Status:** Not started
- **Prerequisites:** Phases 0–5 complete
- **Started:**
- **Completed:**
- **Notes:**
  - Four-state weekly grid: template / overrides / read-only synced busy / read-only pending soft-holds (requirements §2.6).
  - Request log with **mark fulfilled / mark cancelled** actions — the organizer's close-the-loop workflow (§2.4).

### Phase 7 — Cron Hardening & Go-Live
- **Status:** Not started
- **Prerequisites:** Phases 0–6 complete
- **Started:**
- **Completed:**
- **Notes:**
  - Three crons: `sync_calendars.php`, `retry_notifications.php`, `cleanup.php` (request-log retention + soft-hold expiry sweep).
