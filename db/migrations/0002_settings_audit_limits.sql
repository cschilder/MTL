-- =============================================================================
-- Site settings, the audit trail and the rate-limit store.
-- =============================================================================

-- Key/value configuration editable from the management environment. Anything
-- that belongs to the installation rather than to a deployment lives here;
-- credentials stay in config/config.php.
CREATE TABLE `{{prefix}}settings` (
    `key`        VARCHAR(120) NOT NULL,
    `value`      LONGTEXT NULL DEFAULT NULL,

    -- Tells the reader how to cast `value` back from its text form.
    `type`       ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',

    `group`      VARCHAR(60) NOT NULL DEFAULT 'general',

    -- Autoloaded settings are fetched in one query on every request; the rest
    -- are read on demand.
    `autoload`   TINYINT(1) NOT NULL DEFAULT 1,

    `updated_by` INT UNSIGNED NULL DEFAULT NULL,
    `updated_at` DATETIME NOT NULL,

    PRIMARY KEY (`key`),
    KEY `ix_settings_autoload` (`autoload`),
    CONSTRAINT `fk_settings_user`
        FOREIGN KEY (`updated_by`) REFERENCES `{{prefix}}users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Append-only record of who changed what. Rows survive the deletion of the
-- user and of the subject, which is the point of an audit trail.
CREATE TABLE `{{prefix}}audit_log` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED NULL DEFAULT NULL,
    `user_label`   VARCHAR(120) NOT NULL DEFAULT '',

    -- Dotted verb, e.g. 'trip.published', 'media.deleted', 'user.role_changed'.
    `action`       VARCHAR(80) NOT NULL,

    `subject_type` VARCHAR(40) NOT NULL DEFAULT '',
    `subject_id`   BIGINT UNSIGNED NULL DEFAULT NULL,
    `subject_label` VARCHAR(191) NOT NULL DEFAULT '',

    -- Before/after values or other context, JSON encoded.
    `meta`         LONGTEXT NULL DEFAULT NULL,

    `ip`           VARCHAR(45) NOT NULL DEFAULT '',
    `user_agent`   VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`   DATETIME NOT NULL,

    PRIMARY KEY (`id`),
    KEY `ix_audit_created` (`created_at`),
    KEY `ix_audit_user` (`user_id`, `created_at`),
    KEY `ix_audit_subject` (`subject_type`, `subject_id`),
    KEY `ix_audit_action` (`action`, `created_at`),
    CONSTRAINT `fk_audit_user`
        FOREIGN KEY (`user_id`) REFERENCES `{{prefix}}users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Fixed-window counters for sign-in attempts, uploads and API calls.
-- Kept in the database rather than in APCu because shared hosting spreads
-- requests over several PHP processes that share no memory.
CREATE TABLE `{{prefix}}rate_limits` (
    `bucket`     VARCHAR(160) NOT NULL,
    `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,

    PRIMARY KEY (`bucket`),
    KEY `ix_rate_limits_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
