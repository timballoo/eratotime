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
