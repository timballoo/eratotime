# Eratotime — Operations

How the deployed system fits together, how to redeploy/restore it, and how to
keep it safe. The build spec is `docs/eratotime-requirements.md`; this is the
day-to-day runbook.

## Layout (Hostinger)

- `domains/meertec.ltd/public_html/` — the **live doc root** for `www.meertec.ltd`.
  - `index.php`, `methodology.php`, … — the Meertec business site (its own repo).
  - `eratotime/` — this app (deployed by CI, never manually).
  - `baikal/` — the CalDAV server (calendar of record, `stephen@meertec.ltd`).
- `~/backups/` — the off-box backup staging area (pulled nightly by CI).
- `~/eratotime-keys/` — sodium encryption key (outside the web root).
- `~/eratotime-runtime/` — rate-limit file cache.

> **Note:** everything lives inside the shared doc root, so a File Manager /
> rsync `--delete` operation on `public_html/` can wipe siblings. The durable
> fix is a dedicated **subdomain** (e.g. `book.meertec.ltd`) with its own doc
> root for `eratotime/` + `baikal/` — see "Structural hardening" below.

## Deploy

- **Every push to `main`** → GitHub Actions `deploy.yml`: PHP test suite
  (MySQL 8 service) → `composer install --no-dev` → `rsync --delete` to
  `domains/meertec.ltd/public_html/eratotime/` → `php bin/migrate.php`.
- `.rsyncignore` excludes `.env`, `config.php`, `preview.php`, `tests/`,
  `uploads/` — so server secrets/config/photo uploads are **never** deployed
  or deleted by CI.
- Manual redeploy: GitHub → Actions → "Deploy Eratotime via Rsync" →
  **Run workflow** (workflow_dispatch).
- After a deploy that touches nothing server-side, `config.php` still has to
  exist on the box (it is not deployed): `cp config-sample.php config.php`
  (only needed once; CI never removes it).

## Secrets (server `.env` at `domains/meertec.ltd/public_html/eratotime/.env`)

`ERATO_DB_*` (production DB), `ERATO_CSRF_KEY` + `ERATO_ALTCHA_HMAC_KEY`
(generate via `php bin/generate_keys.php`), `ERATO_SMTP_*`, `ERATO_CALDAV_*`.
`config.php` needs the admin passphrase hash: `php -r "echo password_hash('…', PASSWORD_DEFAULT);"` → paste into `'password_hash' => '…'`.

## Baïkal

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
  with `mysql -h <host> -u <user> -p <db> < dump.sql` (or via the web mysql
  import). CI redeploy restores the app code itself.
- Box prunes to 14 generations to match the artifact window.

## Crons (hPanel → Advanced → Cron Jobs)

- `sync_calendars.php` every 10 min:
  `php /home/u835116879/domains/meertec.ltd/public_html/eratotime/cron/sync_calendars.php`
- `retry_notifications.php` every 5 min.
- `cleanup.php` daily.

## Structural hardening (recommended)

Move `eratotime/` and `baikal/` into their **own subdomain doc root**
(`book.meertec.ltd`) so the business site's deploy can never touch them:
1. hPanel → Websites → Add Subdomain → `book.meertec.ltd` (doc root auto-created).
2. Retarget the CI deploy path in `deploy.yml` to
   `…/domains/book.meertec.ltd/public_html/`.
3. Move Baïkal there too and update `ERATO_CALDAV_URL`.
4. Point the site's "Book a slot" link (`$site['book']`) at the new URL.
Then the shared-`public_html` risk disappears entirely.
