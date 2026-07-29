-- =============================================================================
-- Albums, tags and the chunked-upload staging table.
-- =============================================================================

-- An album is an ordered set of media. It can stand alone, or belong to a trip
-- or to a single step; the three cases share one table because they differ
-- only in what they hang from.
CREATE TABLE `{{prefix}}albums` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36) NOT NULL,
    `user_id`        INT UNSIGNED NULL DEFAULT NULL,

    `trip_id`        INT UNSIGNED NULL DEFAULT NULL,
    `step_id`        INT UNSIGNED NULL DEFAULT NULL,

    `title`          VARCHAR(191) NOT NULL,
    `slug`           VARCHAR(191) NOT NULL,
    `description_md`   MEDIUMTEXT NULL DEFAULT NULL,
    `description_html` MEDIUMTEXT NULL DEFAULT NULL,

    `cover_media_id` INT UNSIGNED NULL DEFAULT NULL,

    `status`         ENUM('draft','published') NOT NULL DEFAULT 'draft',
    `visibility`     ENUM('inherit','public','unlisted','private') NOT NULL DEFAULT 'inherit',
    `share_token`    CHAR(22) NULL DEFAULT NULL,

    -- grid   : equal tiles
    -- masonry: preserves aspect ratios
    -- story  : one image per row, full width, for a photo essay
    `layout`         ENUM('grid','masonry','story') NOT NULL DEFAULT 'grid',

    `position`       INT NOT NULL DEFAULT 0,
    `media_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `view_count`     INT UNSIGNED NOT NULL DEFAULT 0,

    `created_at`     DATETIME NOT NULL,
    `updated_at`     DATETIME NOT NULL,
    `deleted_at`     DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_albums_uuid` (`uuid`),
    UNIQUE KEY `uq_albums_slug` (`slug`),
    UNIQUE KEY `uq_albums_share_token` (`share_token`),
    KEY `ix_albums_trip` (`trip_id`, `position`),
    KEY `ix_albums_step` (`step_id`),
    KEY `ix_albums_user` (`user_id`),
    KEY `ix_albums_listing` (`status`, `visibility`, `deleted_at`),
    CONSTRAINT `fk_albums_user`
        FOREIGN KEY (`user_id`) REFERENCES `{{prefix}}users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_albums_trip`
        FOREIGN KEY (`trip_id`) REFERENCES `{{prefix}}trips` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_albums_step`
        FOREIGN KEY (`step_id`) REFERENCES `{{prefix}}steps` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_albums_cover`
        FOREIGN KEY (`cover_media_id`) REFERENCES `{{prefix}}media` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `{{prefix}}album_media` (
    `album_id`   INT UNSIGNED NOT NULL,
    `media_id`   INT UNSIGNED NOT NULL,
    `position`   INT NOT NULL DEFAULT 0,
    `caption`    VARCHAR(500) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL,

    PRIMARY KEY (`album_id`, `media_id`),
    KEY `ix_album_media_order` (`album_id`, `position`),
    KEY `ix_album_media_media` (`media_id`),
    CONSTRAINT `fk_album_media_album`
        FOREIGN KEY (`album_id`) REFERENCES `{{prefix}}albums` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_album_media_media`
        FOREIGN KEY (`media_id`) REFERENCES `{{prefix}}media` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Tags, applied to any content type through one polymorphic pivot.
-- -----------------------------------------------------------------------------
CREATE TABLE `{{prefix}}tags` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(80) NOT NULL,
    `slug`        VARCHAR(96) NOT NULL,
    `color`       CHAR(7) NOT NULL DEFAULT '',
    `usage_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tags_slug` (`slug`),
    KEY `ix_tags_usage` (`usage_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `{{prefix}}taggables` (
    `tag_id`        INT UNSIGNED NOT NULL,
    `taggable_type` ENUM('trip','step','album','media') NOT NULL,
    `taggable_id`   INT UNSIGNED NOT NULL,
    `created_at`    DATETIME NOT NULL,

    PRIMARY KEY (`tag_id`, `taggable_type`, `taggable_id`),
    KEY `ix_taggables_subject` (`taggable_type`, `taggable_id`),
    CONSTRAINT `fk_taggables_tag`
        FOREIGN KEY (`tag_id`) REFERENCES `{{prefix}}tags` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Staging for chunked uploads.
--
-- The browser splits a file into fixed-size parts so no single request runs
-- into post_max_size or the FastCGI timeout. One row tracks a file in flight;
-- it is deleted once the parts have been assembled, and expired rows are swept
-- by the maintenance task.
-- -----------------------------------------------------------------------------
CREATE TABLE `{{prefix}}upload_sessions` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36) NOT NULL,
    `user_id`        INT UNSIGNED NOT NULL,

    `original_name`  VARCHAR(255) NOT NULL,
    `mime_type`      VARCHAR(100) NOT NULL DEFAULT '',
    `size_bytes`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `chunk_bytes`    INT UNSIGNED NOT NULL DEFAULT 0,
    `chunks_total`   INT UNSIGNED NOT NULL DEFAULT 1,

    -- Bitmap of received chunk indexes, one character per chunk ('0'/'1').
    -- A resumed upload asks for this and only re-sends what is missing.
    `chunks_received` MEDIUMTEXT NOT NULL,

    `bytes_received` BIGINT UNSIGNED NOT NULL DEFAULT 0,

    -- Path below storage/tmp holding the partial file.
    `temp_path`      VARCHAR(191) NOT NULL,

    -- Where the finished media should be attached, if anywhere.
    `target_type`    ENUM('none','step','album','trip','avatar') NOT NULL DEFAULT 'none',
    `target_id`      INT UNSIGNED NULL DEFAULT NULL,

    `status`         ENUM('pending','assembling','complete','failed') NOT NULL DEFAULT 'pending',
    `error`          VARCHAR(500) NOT NULL DEFAULT '',
    `media_id`       INT UNSIGNED NULL DEFAULT NULL,

    `created_at`     DATETIME NOT NULL,
    `updated_at`     DATETIME NOT NULL,
    `expires_at`     DATETIME NOT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_upload_sessions_uuid` (`uuid`),
    KEY `ix_upload_sessions_user` (`user_id`, `status`),
    KEY `ix_upload_sessions_expiry` (`expires_at`),
    CONSTRAINT `fk_upload_sessions_user`
        FOREIGN KEY (`user_id`) REFERENCES `{{prefix}}users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_upload_sessions_media`
        FOREIGN KEY (`media_id`) REFERENCES `{{prefix}}media` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
