-- =============================================================================
-- Users and authentication.
--
-- Every table uses InnoDB (foreign keys, row locking), utf8mb4 (emoji and
-- non-Latin place names) and DATETIME columns holding UTC. Local time is a
-- presentation concern and is applied per user at render time.
--
-- Indexed VARCHARs are capped at 191 characters: on an InnoDB build without
-- large index prefixes, utf8mb4 allows 767/4 = 191 bytes in a single-column
-- index, and MTL has no reason to exceed that.
-- =============================================================================

CREATE TABLE `{{prefix}}users` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Stable public identifier. Used in URLs and API payloads so a numeric id
    -- never has to be exposed.
    `uuid`              CHAR(36) NOT NULL,

    `name`              VARCHAR(120) NOT NULL,
    `email`             VARCHAR(191) NOT NULL,

    -- Argon2id by default, bcrypt when the PHP build lacks sodium. The column
    -- is wide enough for either, plus room for a future algorithm.
    `password_hash`     VARCHAR(255) NOT NULL DEFAULT '',

    -- admin  : everything, including user management and maintenance
    -- editor : all content, no user management
    -- author : own trips, steps, albums and media
    -- viewer : read-only access to private content
    `role`              ENUM('admin','editor','author','viewer') NOT NULL DEFAULT 'author',

    -- invited : account created, first password not set yet
    -- active  : can sign in
    -- disabled: kept for attribution, cannot sign in
    `status`            ENUM('invited','active','disabled') NOT NULL DEFAULT 'invited',

    `avatar_media_id`   INT UNSIGNED NULL DEFAULT NULL,
    `bio`               VARCHAR(500) NOT NULL DEFAULT '',
    `locale`            VARCHAR(8) NOT NULL DEFAULT 'nl',
    `timezone`          VARCHAR(64) NOT NULL DEFAULT 'Europe/Amsterdam',

    -- TOTP second factor. The secret is stored encrypted with the application
    -- key; `totp_confirmed_at` is only set once a code has been verified, so a
    -- half-finished enrolment never locks anyone out.
    `totp_secret`       VARBINARY(255) NULL DEFAULT NULL,
    `totp_confirmed_at` DATETIME NULL DEFAULT NULL,
    `recovery_codes`    LONGTEXT NULL DEFAULT NULL,

    `email_verified_at` DATETIME NULL DEFAULT NULL,
    `last_login_at`     DATETIME NULL DEFAULT NULL,
    `last_login_ip`     VARCHAR(45) NOT NULL DEFAULT '',
    `last_seen_at`      DATETIME NULL DEFAULT NULL,

    -- Progressive lockout after repeated failed sign-ins.
    `failed_attempts`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`      DATETIME NULL DEFAULT NULL,

    -- Per-user interface preferences (theme, globe defaults, editor mode).
    -- JSON encoded; validated in PHP rather than by the column type so an old
    -- MariaDB without the JSON alias behaves identically.
    `preferences`       LONGTEXT NULL DEFAULT NULL,

    `created_at`        DATETIME NOT NULL,
    `updated_at`        DATETIME NOT NULL,

    -- Soft delete: content keeps its author even after the account is removed.
    `deleted_at`        DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_uuid` (`uuid`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `ix_users_status_role` (`status`, `role`),
    KEY `ix_users_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Single-use and long-lived tokens: password resets, e-mail verification,
-- invitations and "remember me" cookies.
--
-- Only the hash is stored. A database dump therefore cannot be replayed as a
-- valid cookie or reset link.
-- -----------------------------------------------------------------------------
CREATE TABLE `{{prefix}}user_tokens` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED NOT NULL,
    `type`         ENUM('remember','password_reset','email_verify','invite','api') NOT NULL,

    -- Public half of the token, used to look the row up without a table scan.
    `selector`     CHAR(32) NOT NULL,

    -- SHA-256 of the secret half.
    `token_hash`   CHAR(64) NOT NULL,

    `label`        VARCHAR(120) NOT NULL DEFAULT '',
    `ip`           VARCHAR(45) NOT NULL DEFAULT '',
    `user_agent`   VARCHAR(255) NOT NULL DEFAULT '',
    `expires_at`   DATETIME NOT NULL,
    `used_at`      DATETIME NULL DEFAULT NULL,
    `created_at`   DATETIME NOT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_tokens_selector` (`selector`),
    KEY `ix_user_tokens_user_type` (`user_id`, `type`),
    KEY `ix_user_tokens_expiry` (`expires_at`),
    CONSTRAINT `fk_user_tokens_user`
        FOREIGN KEY (`user_id`) REFERENCES `{{prefix}}users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
