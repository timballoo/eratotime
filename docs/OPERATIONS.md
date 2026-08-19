# Eratotime — Operations

How the deployed system fits together, how to redeploy/restore it, and how to
keep it safe. The build spec is `docs/eratotime-requirements.md`; this is the
day-to-day runbook.

## Layout (Hostinger)

- `domains/meertec.ltd/public_html/` — the **live doc root** for `www.meertec.ltd`.
  - `index.php`, `methodology.php`, … — the Meertec business site (its own repo).
- `domains/meertec.ltd/public_html/book/` — **Eratotime's doc root**; the
  Hostinger-managed subdomain `book.meertec.ltd` maps here. CI deploys here and
  rsync `P`-protects `.env`, `config.php`, `uploads/` and `baikal/` (see
  `.rsyncignore`). Because this lives inside the shared `meertec.ltd` doc root,
  a business-site deploy could delete it — the nightly backup covers it.
  - `baikal/` — the CalDAV server (calendar of record, `stephen@meertec.ltd`).
- `~/backups/` — the off-box backup staging area (pulled nightly by CI).
- `~/eratotime-keys/` — sodium encryption key (outside the web root).
- `~/eratotime-runtime/` — rate-limit file cache.
- `domains/book.meertec.ltd/public_html/` — **legacy** Eratotime/Baïkal doc root
  (pre-2026-08-18 layout). Superseded by `meertec.ltd/public_html/book/`; the
  remaining `config.php`/`.env` there are inert copies — do not edit them.

## Deploy

- **Every push to `main`** → GitHub Actions `deploy.yml`: PHP test suite
  (MySQL 8 service) → `composer install --no-dev` → `rsync --delete` to
  `domains/meertec.ltd/public_html/book/` → `php bin/migrate.php` →
  smoke-check `https://book.meertec.ltd/…`.
- `.rsyncignore` — `P` (protect) rules mean `.env`, `config.php`, `uploads/`
  and `baikal/` are **never transferred or deleted** by CI. (rsync deletes
  excluded files by default — that's why they must be `P`, not `-`.)
- Manual redeploy: GitHub → Actions → "Deploy Eratotime via Rsync" →
  **Run workflow** (workflow_dispatch).
- `config.php` is server-only: if it's ever missing,
  `cp config-sample.php config.php` (CI never removes it).

## Secrets (server `.env` at `domains/meertec.ltd/public_html/book/.env`)

`ERATO_DB_*` (production DB `u835116879_meertec_erato`), `ERATO_CSRF_KEY` +
`ERATO_ALTCHA_HMAC_KEY` (generate via `php bin/generate_keys.php`),
`ERATO_SMTP_*`, `ERATO_CALDAV_*`.
`config.php` needs the admin passphrase hash: `php -r "echo password_hash('…', PASSWORD_DEFAULT);"` → paste into `'password_hash' => '…'`.
`ERATO_GOOGLE_SERVICE_ACCOUNT_PATH` and `ERATO_GOOGLE_MEET_CALENDAR_ID` (see **Google Meet** below).

## Admin password reset

If the admin password is forgotten, use the **secret reset URL** stored in the
`tenants` table. The reset secret is a 64-character hex token — one-time use,
regenerated after each successful reset.

### Generate a reset link

SSH into the server and run:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Copy the output (a 64-char hex string) and store it:

```bash
php -r "
\$pdo = new PDO('mysql:host=127.0.0.1;dbname=u835116879_meertec_erato', 'u835116879_admin', 'YOUR_DB_PASS');
\$pdo->exec(\"UPDATE tenants SET reset_secret = '"$(php -r "echo bin2hex(random_bytes(32));")"' WHERE slug = 'meertec'\");
echo 'Done';
"
```

Or more simply, use any MySQL client:

```sql
UPDATE tenants SET reset_secret = '<64-char-hex-token>' WHERE slug = 'meertec';
```

Then share this URL with the person who needs to reset:

```
https://book.meertec.ltd/admin.php?reset=<64-char-hex-token>
```

### What happens

1. The link shows a password-change form (new password + confirm).
2. On submit, the new password is hashed and written to `config.php`.
3. The reset token is regenerated — the old link immediately stops working.
4. The person is redirected to the login page with the new password.

### Notes

- The reset link works only once — each use generates a new token.
- The link does not expire (there's no timestamp check), but it's invalidated
  after use. If you want to revoke it without using it, just set
  `reset_secret = NULL` in the `tenants` row.
- The reset form does not require the old password — that's the whole point.

## Baïkal

- Lives at `domains/meertec.ltd/public_html/book/baikal` (moved from the old
  `book.meertec.ltd/public_html/baikal` on 2026-08-18 when the subdomain doc
  root moved). Eratotime CI protects it (`P baikal/` in `.rsyncignore`), so
  deploys never delete it. The server `.env` `ERATO_CALDAV_URL` points here:
  `https://book.meertec.ltd/baikal/html/dav.php/calendars/stephen@meertec.ltd/default/`.
  - `config/baikal.yaml` holds an **absolute `sqlite_file` path** — if Baïkal
    is ever moved, update that path to match.
- **Restore from backup (preferred if data exists):** the nightly backup
  artifact contains `baikal/config/` and `baikal/Specific/` (Baïkal's users,
  calendar, SQLite DB, settings). Restore = redeploy vanilla Baïkal, then copy
  `config/` + `Specific/` back, then `php bin/setup_caldav.php`.
- **Fresh vanilla install:** `bash bin/deploy_baikal.sh` on the box, then the
  web installer at `https://book.meertec.ltd/baikal/html/` (SQLite → admin),
  then add user `stephen@meertec.ltd` + a calendar, then
  `php bin/setup_caldav.php` and `php cron/sync_calendars.php`.
- Baïkal's server-side invite sending is not relied on — Thunderbird sends the
  invites from `stephen@meertec.ltd`. Any CalDAV client (Thunderbird) must
  point at the `book.meertec.ltd/baikal` URL above.

## Google Meet (dynamic link generation, optional)

When enabled, a unique Google Meet link is generated per booking via the
Calendar API. The Service Account authenticates with its own credentials —
no impersonation or domain-wide delegation needed — and the Gmail address
(`meertec.ltd@gmail.com`) never appears in any email or output sent to
invitees.

### Prerequisites
- A Google account with Google Calendar enabled (`meertec.ltd@gmail.com`).
- The **"Eratotime Meet Rooms"** calendar created in that account (or any
  existing calendar you want to reuse).

### Fresh install (new service account)

1. **Google Cloud Console → IAM & Admin → Service Accounts**
   - Create service account (e.g. `eratotime-meet@…iam.gserviceaccount.com`).
   - Create a JSON key → download it.
   - Place it at `/home/u835116879/eratotime-keys/service-account.json` (outside
     the web root — never put it in `book/`). The CI rsync ignores
     `eratotime-keys/` and `.rsyncignore` does not need updating.

2. **Share the calendar** with the service account email
   (`eratotime-meet@…iam.gserviceaccount.com`) → grant **Make changes to
   events** permission.

3. **Get the calendar ID** — Google Calendar Settings → Integrate calendar
   → copy the Calendar ID (looks like `abc123@group.calendar.google.com`).

4. **Add secrets to server `.env`:**
   ```
   ERATO_GOOGLE_SERVICE_ACCOUNT_PATH=/home/u835116879/eratotime-keys/service-account.json
   ERATO_GOOGLE_MEET_CALENDAR_ID=abc123@group.calendar.google.com
   ```

5. **Admin panel → Settings** → enable "Dynamic Meet links".

### Rotate service account key

1. Google Cloud Console → Service Accounts → Keys → Create new key (JSON).
2. Replace `/home/u835116879/eratotime-keys/service-account.json`.
3. No code changes needed — the path in `.env` is unchanged.

### How it works

- On each booking, `request_lib.php` calls `meet_create_link()` which
  creates a temporary calendar event with `conferenceData.createRequest`
  (type `hangoutsMeet`), then extracts the link from `getHangoutLink()`
  or the `entryPoints[0].uri` fallback.
- The link is stored on `request_log.meet_link` and used by the notification
  system in preference to the static per-type `video_link`.
- If `delete_meet_events` is enabled, the temp event is deleted after
  extracting the link (the Meet link persists regardless).
- On failure, the system falls back to the static `video_link` or
  `global_settings.meet_link` — dynamic link generation is never required
  for a booking to succeed.

- **Nightly 02:00 UTC** `backup.yml` + manual dispatch: `bin/backup.php` dumps
  the Eratotime DB as gzipped SQL to `~/backups/eratotime/`; the workflow pulls
  that plus Baïkal's `config/` + `Specific/` and stores a 14-day artifact.
- **Restore Eratotime DB:** download the artifact, `gunzip` the `.sql`, load
  with `mysql -h <host> -u <user> -p <db> < dump.sql`. CI redeploy restores the
  app code itself.
- Box prunes to 14 generations to match the artifact window.

## Crons (hPanel → Advanced → Cron Jobs)

**ONE** system cron calls the dispatcher every 5 minutes; the `cron_jobs` table
decides what runs when (configured in the admin panel → **Cron** tab):

```
*/5 * * * * /usr/bin/php /home/u835116879/domains/meertec.ltd/public_html/book/cron_dispatcher.php
```

> **Check this entry** — after the 2026-08-18 doc-root move it must point at
> `meertec.ltd/public_html/book/cron_dispatcher.php` (the old
> `book.meertec.ltd/public_html` path no longer exists).

The seeded jobs: `sync_calendars` (10 min), `retry_notifications` (5 min),
`cleanup` (daily). Each job tracks `last_run_at`, `last_status`,
`last_output`, `run_count` in `cron_jobs` — visible in the admin Cron tab,
which also toggles enable/disable and edits the schedule. HTTP trigger (if
wanted): `GET https://book.meertec.ltd/cron_dispatcher.php?key=YOUR_CRON_SECRET`
(needs `ERATO_CRON_SECRET` in `.env`).

## Structural note

Eratotime + Baïkal live under `domains/meertec.ltd/public_html/book/` (the
subdomain `book.meertec.ltd` doc root), so `www.meertec.ltd` shares the root
but the subdomain keeps its own routing. The `book.meertec.ltd` files can be
hit by a wipe of the shared `meertec.ltd` doc root — the nightly off-box
backup is the safety net. If Baïkal ever moves again, update the absolute
`sqlite_file` in its `config/baikal.yaml` and re-run `bin/setup_caldav.php`.
