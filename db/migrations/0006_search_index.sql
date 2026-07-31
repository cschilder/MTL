-- =============================================================================
-- Full-text search across trips, steps, albums and media captions.
--
-- One denormalised table rather than FULLTEXT indexes on each content table:
-- it keeps the ranking rules in one place, lets a single query search
-- everything, and avoids adding a FULLTEXT index to tables that are written on
-- every page view.
--
-- MySQL 5.6+ and MariaDB 10.0+ both support FULLTEXT on InnoDB. The
-- application falls back to LIKE matching when the index is unavailable, so a
-- host with an older engine still has a working search box.
-- =============================================================================

CREATE TABLE `{{prefix}}search_index` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    `subject_type`  ENUM('trip','step','album','media') NOT NULL,
    `subject_id`    INT UNSIGNED NOT NULL,

    -- Denormalised so a result can be rendered without touching the source
    -- table, and so a private row can be filtered out before any join.
    `title`         VARCHAR(191) NOT NULL DEFAULT '',
    `body`          MEDIUMTEXT NOT NULL,
    `url`           VARCHAR(255) NOT NULL DEFAULT '',
    `visibility`    ENUM('public','unlisted','private') NOT NULL DEFAULT 'private',
    `owner_id`      INT UNSIGNED NULL DEFAULT NULL,
    `occurred_at`   DATETIME NULL DEFAULT NULL,

    `updated_at`    DATETIME NOT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_search_subject` (`subject_type`, `subject_id`),
    KEY `ix_search_visibility` (`visibility`, `subject_type`),
    KEY `ix_search_owner` (`owner_id`),
    FULLTEXT KEY `ft_search_content` (`title`, `body`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
