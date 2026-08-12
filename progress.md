# Eratotime — Build Progress

## Current Phase: Phase 7 — Cron Hardening & Go-Live Checklist

### Status: Code complete (all 3 crons written + tested); deployment on Hostinger pending

Phases 0–6 complete. The full request tool works locally end-to-end, **101 tests / 393 assertions green**. The only remaining work is on the live box: deploy + `.env`/`config.php` secrets, migration, `bin/setup_caldav.php`, schedule the 3 crons, and run the §8 go-live matrix against `www.meertec.ltd`.

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
- **Status:** Built + tested locally (availability rendering; submission wiring is Phase 5)
- **Prerequisites:** Phases 0–3 complete — real calendar sync working
- **Started:** 2026-08-12
- **Completed:** (rendering) 2026-08-12
- **Notes:**
  - Route `/t/{slug}/book/{slug}` → `book.php` (`.htaccess` rewrite, path-parse fallback). Brand-matched to meertec.ltd: Ink/Paper/Brass/Verdigris tokens, Fraunces + IBM Plex, round-logo masthead (`css/eratotime.css`).
  - `api/slots.php` — `month=` returns dates with open slots; `date=` returns slots + `utc_slots` (client renders in invitee tz). Availability from the local cache (blockouts/soft-holds), fail-closed on stale sync sources (verified: 30-min grid 09:00–17:00, 60-min 09:00–16:30, DST-correct UTC).
  - `js/booking.js` (ES module) — organizer bio/photo, tz detect + override dropdown, month picker (past/min-notice/closed dates disabled), slot buttons in invitee tz, form (name/email/custom questions/guests/honeypot). Submits to `api/requests.php` (Phase 5) with graceful fallback notice.
  - `js/embed-resize.js` — postMessage iframe height handshake (spec 2.7). Page is standalone (no parent-asset dependency).
  - `availability_context_lib.php` — DB→engine ctx assembly (working hours/overrides/blockouts/soft-holds/counts/caps/stale), UTC→organizer-tz per-date block conversion; shared with Phase 5/6.
  - Tests: 72 total / 219 assertions green (added AvailabilityContextTest for UTC→org-tz conversion edge cases).
  - **Phase 5 handoff:** `api/requests.php` (POST) is the only missing piece — booking.js already posts the expected shape (tenant, type, slot_utc, name, email, timezone, questions[], guests[], website honeypot).

### Phase 5 — Request Submission Flow
- **Status:** Built + tested locally (round-trip verified over HTTP; live SMTP still to configure)
- **Prerequisites:** Phase 4 complete
- **Started:** 2026-08-12
- **Completed:** (code) 2026-08-12
- **Notes:**
  - `request_lib.php` — `request_submit()`: transaction with per-tenant `FOR UPDATE` serialization, availability re-check, `request_log` insert (soft-hold = `request_hold_hours`), `notification_outbox` queue (invitee_confirmation + organizer_request + optional whatsapp_organizer). Unique key blocks identical duplicates.
  - `api/requests.php` — transport guards first (spec 4.2): per-IP file-cache rate limit (10/10min), honeypot, stateless HMAC CSRF, ALTCHA (when enabled). `api/altcha.php` serves the challenge; booking page issues CSRF and loads the ALTCHA widget when `ERATO_ALTCHA_HMAC_KEY` is set.
  - `notify_lib.php` — PHPMailer/SMTP (dev mode = no-op when no host); subject prefixes per 2.5 (`[Eratotime Request] …`, `[Eratotime] Confirmation — …`); organizer email carries an **`.ics` import file** (SUMMARY `Eratotime: {type} — {invitee}`, DTSTART/DTEND UTC, **LOCATION = fixed Meet link**, answers in DESCRIPTION) built with Sabre/VObject; WhatsApp via CallMeBot when key set; outbox send/backoff/retry.
  - `cron/retry_notifications.php` — retries pending outbox with backoff; marks failed past the window.
  - Migration: `request_log.invitee_timezone`, `notification_outbox.template` (default 'email').
  - **Round-trip verified over HTTP** (`api/requests.php`): request → pending row + soft-hold active + both outbox rows 'sent' (dev mode). 91 tests / 270 assertions green.
  - **Handoff to Phase 6:** admin panel (meeting type CRUD, four-state weekly grid, request log with mark fulfilled/cancelled, calendar connection status, failed-notification warning, organizer profile). The fulfil/cancel actions just flip `request_log.status` and end the soft-hold's blocking role — the engine already ignores non-pending rows.

### Phase 6 — Admin Panel
- **Status:** Built + tested locally (HTTP smoke-tested: login, dashboard, grid save/load, all CRUD)
- **Prerequisites:** Phases 4–5 complete
- **Started:** 2026-08-12
- **Completed:** (code) 2026-08-12
- **Notes:**
  - `admin.php` + `css/admin.css` + `js/admin.js` — single-passphrase login (config['admin'], rate-limited 5/5min, session + CSRF), nav: Dashboard / Availability / Meeting types / Requests / Calendars / Settings.
  - `admin_lib.php` — auth, weekly grid load/save (template mode rewrites `working_hours` per day; override mode rewrites `availability_overrides` per date, incl. full-day 'blocked'), request fulfil/cancel, meeting-type CRUD + questions, settings/profile/photo, sources list + sync-now, dashboard warnings (failed notifications, stale sync fail-closed).
  - **Engine upgrade:** `availability_day_open_ranges()` — multi-range days (blocked internal cells) supported by the engine; grid editor can express them. Backward-compatible (29→31 availability tests).
  - `api/admin/*`: `_guard.php` (auth+csrf+JSON), `availability_grid.php`, `requests.php`, `meeting_types.php`, `settings.php` (incl. photo upload), `sources.php`, `dashboard.php`, `login.php`.
  - HTTP smoke-tested: login 200, dashboard/grid/MT/requests/settings/sources all 200, grid template save + reload round-trip correct. 98 tests / 370 assertions green.
  - **Handoff to Phase 7:** schedule `cron/sync_calendars.php`, `cron/retry_notifications.php`, and `cron/cleanup.php` (cleanup.php is still to be written) on Hostinger; run the §8 manual test matrix; HTTPS + .htaccess verified on the live domain; deploy .env/config.php with real secrets.

### Phase 7 — Cron Hardening & Go-Live Checklist
- **Status:** Code complete (all three crons written + tested); **deployment pending** (live Hostinger setup)
- **Prerequisites:** Phases 0–6 complete
- **Started:** 2026-08-12
- **Completed:** (code) 2026-08-12
- **Notes:**
  - All three crons exist: `sync_calendars.php` (Phase 3), `retry_notifications.php` (Phase 5), `cleanup.php` (this phase: expires stale soft-holds, purges terminal requests past `request_log_retention_days`, never touches active holds; cascades outbox). 101 tests / 393 assertions green.
  - **Remaining (external/manual, needs the live Hostinger box):** deploy the repo + `.env` + `config.php` with real secrets; run the migration; `bin/setup_caldav.php`; schedule the 3 crons (every 10 min sync; 5 min retry; daily cleanup); HTTPS + `.htaccess` verified; full §8 manual test matrix against `www.meertec.ltd`; the booking page iframe-embedded on the business site (spec 2.7).
  - Config for the live box: `ERATO_CSRF_KEY`, `ERATO_ALTCHA_HMAC_KEY`, `ERATO_SMTP_*`, `ERATO_CALDAV_*` all set; admin passphrase hash in `config.php`.
