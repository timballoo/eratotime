# Eratotime — Operations

How the deployed system fits together, how to redeploy/restore it, and how to
keep it safe. The build spec is `docs/eratotime-requirements.md`; this is the
day-to-day runbook.

## Layout (Hostinger)

- `domains/meertec.ltd/public_html/` — the **live doc root** for `www.meertec.ltd`.
  - `index.php`, `methodology.php`, … — the Meertec business site (its own repo).
  - `baikal/` — the CalDAV server (calendar of record, `stephen@meertec.ltd`).
  - `eratotime/` → **symlink** to the Eratotime doc root (keeps the old
    `www.meertec.ltd/eratotime` URL working; a doc-root wipe only removes the
    link, never the app).
- `domains/book.meertec.ltd/public_html/` — **Eratotime's own doc root**
  (`https://book.meertec.ltd`). This is OUTSIDE the shared doc root, so the
  business site's deploys / File Manager operations on `meertec.ltd` cannot
  touch it. CI deploys here; `.env`, `config.php`, `uploads/` live here and are
  protected from rsync `--delete`.
- `~/backups/` — the off-box backup staging area (pulled nightly by CI).
- `~/eratotime-keys/` — sodium encryption key (outside the web root).
- `~/eratotime-runtime/` — rate-limit file cache.

## Deploy

- **Every push to `main`** → GitHub Actions `deploy.yml`: PHP test suite
  (MySQL 8 service) → `composer install --no-dev` → `rsync --delete` to
  `domains/book.meertec.ltd/public_html/` → `php bin/migrate.php` →
  smoke-check `https://book.meertec.ltd/…`.
- `.rsyncignore` — `P` (protect) rules mean `.env`, `config.php` and `uploads/`
  are **never transferred or deleted** by CI. (rsync deletes excluded files by
  default — that's why they must be `P`, not `-`.)
- Manual redeploy: GitHub → Actions → "Deploy Eratotime via Rsync" →
  **Run workflow** (workflow_dispatch).
- `config.php` is server-only: if it's ever missing,
  `cp config-sample.php config.php` (CI never removes it).

## Secrets (server `.env` at `domains/book.meertec.ltd/public_html/.env`)

`ERATO_DB_*` (production DB), `ERATO_CSRF_KEY` + `ERATO_ALTCHA_HMAC_KEY`
(generate via `php bin/generate_keys.php`), `ERATO_SMTP_*`, `ERATO_CALDAV_*`.
`config.php` needs the admin passphrase hash: `php -r "echo password_hash('…', PASSWORD_DEFAULT);"` → paste into `'password_hash' => '…'`.

## Baïkal

- Lives at `domains/meertec.ltd/public_html/baikal` (still inside the shared
  doc root — its data is protected by the nightly backup).
- **Restore from backup (preferred if data exists):** the nightly backup
  artifact contains `baikal/config/` and `baikal/Specific/` (Baïkal's users,
  calendar, SQLite DB, settings). Restore = redeploy vanilla Baïkal, then copy
  `config/` + `Specific/` back, then `php bin/setup_caldav.php`.
- **Fresh vanilla install:** `bash bin/deploy_baikal.sh` on the box, then the
  web installer at `https://www.meertec.ltd/baikal/html/` (SQLite → admin),
  then add user `stephen@meertec.ltd` + a calendar, then
  `php bin/setup_caldav.php` and `php cron/sync_calendars.php`.
- Baïkal's server-side invite sending is not relied on — Thunderbird sends the
  invites from `stephen@meertec.ltd`.

## Backup / restore

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
*/5 * * * * /usr/bin/php /home/u835116879/domains/book.meertec.ltd/public_html/cron_dispatcher.php
```

The seeded jobs: `sync_calendars` (10 min), `retry_notifications` (5 min),
`cleanup` (daily). Each job tracks `last_run_at`, `last_status`,
`last_output`, `run_count` in `cron_jobs` — visible in the admin Cron tab,
which also toggles enable/disable and edits the schedule. HTTP trigger (if
wanted): `GET https://book.meertec.ltd/cron_dispatcher.php?key=YOUR_CRON_SECRET`
(needs `ERATO_CRON_SECRET` in `.env`).

## Structural note

Eratotime is now on its **own subdomain doc root** (`book.meertec.ltd`), so a
wipe of the shared `meertec.ltd` doc root cannot delete it. Baïkal remains in
the shared root; its data is covered by the nightly backup. If Baïkal ever
moves too, update `ERATO_CALDAV_URL` in the server `.env` and re-run
`bin/setup_caldav.php`.
