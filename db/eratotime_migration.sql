-- =====================================================================
-- Eratotime — initial schema migration
-- Target database: u835116879_meertec_erato  (dedicated DB, per section 4.1
--   of the requirements doc — no cross-database references from this schema)
-- Run as: u835116879_admin (or whichever DB user has been granted rights
--   on this specific database — do not run as a global/shared account)
--
-- Run via phpMyAdmin's SQL tab, or:
--   mysql -h <host> -u u835116879_admin -p u835116879_meertec_erato < eratotime_migration.sql
--
-- No credentials are embedded in this file. Nothing in here needs editing
-- to run except optionally the seed values in the final section, which are
-- already the real v1 values from section 1.5 of the requirements doc.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. tenants — exactly one seeded row in v1 (section 1.4)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug          VARCHAR(64)  NOT NULL,
    display_name  VARCHAR(191) NOT NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. tenant_admins — one row in v1; schema tenant-ready, not yet a real
--    multi-user auth system (section 1.4)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenant_admins (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    username      VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_admins_tenant_username (tenant_id, username),
    CONSTRAINT fk_tenant_admins_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. global_settings — one row per tenant (section 2.5/2.6/5)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS global_settings (
    id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                   BIGINT UNSIGNED NOT NULL,
    organizer_bio               TEXT NULL,
    organizer_photo_path        VARCHAR(255) NULL,
    global_daily_cap            SMALLINT UNSIGNED NULL,
    global_weekly_cap           SMALLINT UNSIGNED NULL,
    mailbox_destination         VARCHAR(191) NOT NULL,
    whatsapp_enabled            TINYINT(1) NOT NULL DEFAULT 0,
    whatsapp_destination_number VARCHAR(32) NULL,
    organizer_timezone          VARCHAR(64) NOT NULL DEFAULT 'Europe/London',
    request_hold_hours          SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    request_log_retention_days  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    UNIQUE KEY uq_global_settings_tenant (tenant_id),
    CONSTRAINT fk_global_settings_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. meeting_types (section 2.1/5)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meeting_types (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    slug              VARCHAR(64)  NOT NULL,
    name              VARCHAR(191) NOT NULL,
    description       TEXT NULL,
    duration_min      SMALLINT UNSIGNED NOT NULL,
    location_details  VARCHAR(255) NULL,
    buffer_before_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    buffer_after_min  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    min_notice_hours  SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    max_horizon_days  SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    daily_cap         SMALLINT UNSIGNED NULL,
    active            TINYINT(1) NOT NULL DEFAULT 1,
    sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_meeting_types_tenant_slug (tenant_id, slug),
    CONSTRAINT fk_meeting_types_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. meeting_type_questions — scoped indirectly via meeting_type_id (section 5)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meeting_type_questions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meeting_type_id BIGINT UNSIGNED NOT NULL,
    label           VARCHAR(191) NOT NULL,
    type            ENUM('text','textarea','select') NOT NULL DEFAULT 'text',
    required        TINYINT(1) NOT NULL DEFAULT 0,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_mtq_meeting_type
        FOREIGN KEY (meeting_type_id) REFERENCES meeting_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. working_hours — recurring template week, layer 1 (section 2.2)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS working_hours (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id  BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,  -- 0=Sunday .. 6=Saturday
    start_time TIME NOT NULL,
    end_time   TIME NOT NULL,
    KEY idx_working_hours_tenant (tenant_id),
    CONSTRAINT fk_working_hours_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. availability_overrides — date-specific exceptions, layer 2 (section 2.2)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS availability_overrides (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id  BIGINT UNSIGNED NOT NULL,
    date       DATE NOT NULL,
    is_blocked TINYINT(1) NOT NULL DEFAULT 1,
    start_time TIME NULL,
    end_time   TIME NULL,
    note       VARCHAR(255) NULL,
    KEY idx_availability_overrides_tenant_date (tenant_id, date),
    CONSTRAINT fk_availability_overrides_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. calendar_sources (section 3/5)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS calendar_sources (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    provider            ENUM('google','microsoft','caldav','ics') NOT NULL,
    label               VARCHAR(191) NOT NULL,
    credentials_encrypted TEXT NULL,       -- NULL until OAuth consent completes
    calendar_identifier VARCHAR(255) NULL, -- e.g. the Google calendar/account ID
    ics_url             VARCHAR(500) NULL,
    active              TINYINT(1) NOT NULL DEFAULT 0,
    last_synced_at      DATETIME NULL,
    last_sync_status    ENUM('ok','error','never_run') NOT NULL DEFAULT 'never_run',
    last_sync_error     TEXT NULL,
    KEY idx_calendar_sources_tenant (tenant_id),
    CONSTRAINT fk_calendar_sources_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. calendar_blockouts — scoped indirectly via calendar_source_id (section 3.5/5)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS calendar_blockouts (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    calendar_source_id BIGINT UNSIGNED NOT NULL,
    external_uid      VARCHAR(255) NOT NULL,
    start_utc         DATETIME NOT NULL,
    end_utc           DATETIME NOT NULL,
    synced_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_calendar_blockouts_source_range (calendar_source_id, start_utc, end_utc),
    UNIQUE KEY uq_calendar_blockouts_source_uid (calendar_source_id, external_uid),
    CONSTRAINT fk_calendar_blockouts_source
        FOREIGN KEY (calendar_source_id) REFERENCES calendar_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. request_log — the authoritative request record (spec 2.3/2.4/5): a
--     soft-hold + audit row, NOT an appointment archive. The app never
--     creates calendar events; the organizer creates them manually and
--     marks the request fulfilled/cancelled via the admin panel. Pending
--     rows block their interval in the availability engine until the
--     organizer acts or soft_hold_expires_at passes (then status -> expired
--     and the slot reopens). Rows are purged past request_log_retention_days.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS request_log (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    meeting_type_id     BIGINT UNSIGNED NULL,
    invitee_name        VARCHAR(191) NOT NULL,
    invitee_email       VARCHAR(191) NOT NULL,
    guest_emails        JSON NULL,
    requested_start_utc DATETIME NOT NULL,
    requested_end_utc   DATETIME NOT NULL,
    custom_answers      JSON NULL,
    status              ENUM('pending','fulfilled','cancelled','expired') NOT NULL DEFAULT 'pending',
    sent_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    soft_hold_expires_at DATETIME NOT NULL,
    KEY idx_request_log_tenant_window (tenant_id, requested_start_utc, requested_end_utc),
    KEY idx_request_log_status (tenant_id, status),
    KEY idx_request_log_soft_hold (soft_hold_expires_at),
    KEY idx_request_log_sent_at (sent_at),
    -- Duplicate-submission guard (spec 4.2): an identical resubmission of the
    -- same slot by the same invitee cannot insert a second row. The submission
    -- transaction additionally takes a per-tenant serialization point and
    -- re-checks overlapping requests/blockouts before inserting.
    UNIQUE KEY uq_request_log_tenant_slot_invitee (tenant_id, requested_start_utc, invitee_email),
    CONSTRAINT fk_request_log_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_request_log_meeting_type
        FOREIGN KEY (meeting_type_id) REFERENCES meeting_types(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. notification_outbox — decouples durable request_log write from
--     retryable notification send (section 4.5/5)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_outbox (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    request_log_id BIGINT UNSIGNED NOT NULL,
    channel        ENUM('email','whatsapp') NOT NULL,
    recipient      VARCHAR(191) NOT NULL,  -- email address or WhatsApp number
    status         ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    next_retry_at  DATETIME NULL,
    last_error     TEXT NULL,
    KEY idx_notification_outbox_status (status, next_retry_at),
    KEY idx_notification_outbox_tenant (tenant_id),
    CONSTRAINT fk_notification_outbox_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_outbox_request_log
        FOREIGN KEY (request_log_id) REFERENCES request_log(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. activity_log — lightweight debugging trail (section 5)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id  BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    detail     JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_activity_log_tenant_created (tenant_id, created_at),
    CONSTRAINT fk_activity_log_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Seed data — the single real v1 tenant (section 1.5 of the requirements
-- doc). Safe to run once; guarded with INSERT IGNORE / ON DUPLICATE so a
-- re-run doesn't create a second tenant row by accident.
-- =====================================================================

INSERT INTO tenants (slug, display_name, active)
VALUES ('meertec', 'Meertec Ltd', 1)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

-- Capture the tenant id for the rest of the seed inserts.
SET @tenant_id = (SELECT id FROM tenants WHERE slug = 'meertec' LIMIT 1);

INSERT INTO global_settings (
    tenant_id, mailbox_destination, whatsapp_enabled, organizer_timezone, request_hold_hours, request_log_retention_days
)
VALUES (
    @tenant_id, 'stephen@meertec.ltd', 0, 'Europe/London', 24, 30
)
ON DUPLICATE KEY UPDATE
    mailbox_destination = VALUES(mailbox_destination),
    organizer_timezone  = VALUES(organizer_timezone);

-- Calendar sources seeded but INACTIVE until their setup steps are done:
--   1. PRIMARY — Baïkal CalDAV (calendar of record, Appendix A decision 2026-08):
--      install Baïkal, create the stephen@meertec.ltd user/calendar, then fill
--      credentials_encrypted + ics_url/calendar_identifier and flip active.
--   2. SECONDARY (optional) — Google (meertec.ltd@gmail.com): requires the OAuth
--      consent flow (section 3.2) if the organizer wants Gmail meetings to block
--      slots. credentials_encrypted stays NULL until then.
-- Flip `active` to 1 only as part of Phase 3, not before.
INSERT INTO calendar_sources (
    tenant_id, provider, label, calendar_identifier, active, last_sync_status
)
SELECT @tenant_id, 'caldav', 'Meertec Baikal (calendar of record)', 'https://www.meertec.ltd/baikal/html/dav.php/calendars/stephen@meertec.ltd/default/', 0, 'never_run'
WHERE NOT EXISTS (
    SELECT 1 FROM calendar_sources
    WHERE tenant_id = @tenant_id AND provider = 'caldav'
);

INSERT INTO calendar_sources (
    tenant_id, provider, label, calendar_identifier, active, last_sync_status
)
SELECT @tenant_id, 'google', 'Meertec Gmail (secondary)', 'meertec.ltd@gmail.com', 0, 'never_run'
WHERE NOT EXISTS (
    SELECT 1 FROM calendar_sources
    WHERE tenant_id = @tenant_id AND calendar_identifier = 'meertec.ltd@gmail.com'
);

-- Default working hours: Mon-Fri 09:00-17:30 (section 2.2's example) —
-- adjust via the admin grid editor once Phase 6 exists; this is just a
-- sane starting template so the availability engine has something to
-- compute against from Phase 0 onward.
INSERT INTO working_hours (tenant_id, day_of_week, start_time, end_time)
SELECT @tenant_id, d, '09:00:00', '17:30:00'
FROM (SELECT 1 AS d UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) AS weekdays
WHERE NOT EXISTS (
    SELECT 1 FROM working_hours WHERE tenant_id = @tenant_id
);

-- Two default meeting types (30 and 60 min), per section 2.1, with the
-- 1-14 day booking window discussed earlier.
INSERT INTO meeting_types (
    tenant_id, slug, name, duration_min, min_notice_hours, max_horizon_days, active, sort_order
)
SELECT @tenant_id, '30-min', '30 Minute Meeting', 30, 24, 14, 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM meeting_types WHERE tenant_id = @tenant_id AND slug = '30-min'
);

INSERT INTO meeting_types (
    tenant_id, slug, name, duration_min, min_notice_hours, max_horizon_days, active, sort_order
)
SELECT @tenant_id, '60-min', '60 Minute Meeting', 60, 24, 14, 1, 2
WHERE NOT EXISTS (
    SELECT 1 FROM meeting_types WHERE tenant_id = @tenant_id AND slug = '60-min'
);
