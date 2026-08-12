# Eratotime — Build Progress

## Current Phase: Phase 3 — Google Calendar Read-Only Sync

### Status: Blocked on decision + setup (see below)

Phase 0 (scaffold), Phase 1 (tenant isolation tests), and Phase 2 (availability engine) are complete **locally**. Phase 3 is gated on the calendar-of-record decision (requirements §1.6/Appendix A) and the Google Cloud Console OAuth app — both external/manual.

### Model note (2026-08)
Requirements doc is **v2 (request-submission model)** — see `docs/eratotime-requirements.md` revision note and section 1.6. The app collects requests + soft-holds slots + notifies the organizer, who creates calendar events manually. **No calendar write path, no `.ics`, no tokenized reschedule/cancel** in v1. The Gmail address (`meertec.ltd@gmail.com`) must never appear in anything the app sends. `db/eratotime_migration.sql` is the authoritative schema.

### Dependencies
- [ ] MySQL database credentials from hPanel (DB name, username, password, host) — needed for live deployment; local dev uses the XAMPP MariaDB (see Local dev env below)
- [ ] Composer method on Hostinger (SSH install vs vendor-and-commit) — unknown; composer works fine locally
- [ ] Google Cloud Console OAuth app (**read-only** scope only — needed by Phase 3, register early)
- [ ] **Open decision — calendar-of-record (requirements §1.6/Appendix A):** Google free Gmail (default) vs Google Workspace (`stephen@meertec.ltd`) vs self-hosted Baïkal CalDAV (`stephen@meertec.ltd`). **Must be resolved before Phase 3 starts** — it decides which account/server the read-only sync connects to.
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

### Phase 3 — Google Calendar Read-Only Sync
- **Status:** Not started
- **Prerequisites:** Phases 0–2 complete; **calendar-of-record decision resolved (requirements §1.6/Appendix A — the gate)**; Google Cloud Console project + OAuth credentials (**read-only** scope only)
- **Started:**
- **Completed:**
- **Notes:**
  - **Read-only only:** busy-block sync via `events.list` (`singleEvents=true`, pagination bound), idempotent upsert/delete keyed on Google's stable event ID. No write scope, no event creation, no `.ics` — verify in review.
  - **Open decision — calendar-of-record target for this phase:** Google (`meertec.ltd@gmail.com` — Appendix A Option 2, the default) vs Google Workspace under `stephen@meertec.ltd` (Option 1, paid) vs self-hosted Baïkal CalDAV under `stephen@meertec.ltd` (Option 3, zero cost, needs a CalDAV client for day-to-day viewing). **Resolve before starting this phase** — it determines whether this phase builds a Google OAuth connector or a CalDAV connector (pulling the phase-2 provider path forward). Note: 365 Family ruled out (custom domains dropped on Family tier Nov 2023).
  - If Google is chosen: sync source = the connected Google account, default `meertec.ltd@gmail.com`. The Gmail address is an admin-panel-only label — it appears nowhere the app sends to invitees.
  - **Do NOT** let the coding agent build any `.ics`/iTIP invite or derive `ORGANIZER` from the OAuth account object — the app builds no calendar invite at all in v1; the SMTP `From:` header always comes from `global_settings.mailbox_destination` (`stephen@meertec.ltd`).

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
