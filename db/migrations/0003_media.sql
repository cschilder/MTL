-- =============================================================================
-- The media library: one row per uploaded file.
--
-- Originals are never modified. Derived sizes are generated on upload and
-- recorded in `variants`, so regenerating them later is a matter of deleting
-- the files and re-running the media:rebuild task.
-- =============================================================================

CREATE TABLE `{{prefix}}media` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36) NOT NULL,
    `user_id`         INT UNSIGNED NULL DEFAULT NULL,

    -- Path below storage/media, e.g. '2026/07/29/a3f1c0de9b.jpg'. Sharding by
    -- date keeps any one directory small enough for FTP clients to list.
    `path`            VARCHAR(191) NOT NULL,

    `original_name`   VARCHAR(255) NOT NULL DEFAULT '',
    `extension`       VARCHAR(12) NOT NULL DEFAULT '',
    `mime_type`       VARCHAR(100) NOT NULL DEFAULT '',
    `kind`            ENUM('image','video','audio','document') NOT NULL DEFAULT 'image',

    `size_bytes`      BIGINT UNSIGNED NOT NULL DEFAULT 0,

    -- SHA-256 of the original bytes. Uploading the same file twice reuses the
    -- existing row instead of storing it again.
    `checksum`        CHAR(64) NOT NULL DEFAULT '',

    `width`           INT UNSIGNED NULL DEFAULT NULL,
    `height`          INT UNSIGNED NULL DEFAULT NULL,
    `duration_ms`     INT UNSIGNED NULL DEFAULT NULL,

    -- EXIF orientation 1-8. Variants are written upright, but the original
    -- keeps its tag, so the value is needed to display it correctly.
    `orientation`     TINYINT UNSIGNED NOT NULL DEFAULT 1,

    -- Geotag lifted from EXIF, or set by hand in the media editor. DECIMAL
    -- rather than FLOAT so a coordinate round-trips exactly.
    `latitude`        DECIMAL(10,7) NULL DEFAULT NULL,
    `longitude`       DECIMAL(10,7) NULL DEFAULT NULL,
    `altitude_m`      DECIMAL(8,2) NULL DEFAULT NULL,

    -- When the photo was taken (EXIF DateTimeOriginal), in UTC. Distinct from
    -- created_at, which is when it was uploaded.
    `captured_at`     DATETIME NULL DEFAULT NULL,

    `camera_make`     VARCHAR(80) NOT NULL DEFAULT '',
    `camera_model`    VARCHAR(80) NOT NULL DEFAULT '',
    `lens`            VARCHAR(120) NOT NULL DEFAULT '',
    `exposure`        VARCHAR(60) NOT NULL DEFAULT '',
    `iso`             INT UNSIGNED NULL DEFAULT NULL,
    `focal_length`    VARCHAR(24) NOT NULL DEFAULT '',

    `title`           VARCHAR(191) NOT NULL DEFAULT '',
    `caption_md`      MEDIUMTEXT NULL DEFAULT NULL,
    `caption_html`    MEDIUMTEXT NULL DEFAULT NULL,
    `alt_text`        VARCHAR(500) NOT NULL DEFAULT '',
    `credit`          VARCHAR(191) NOT NULL DEFAULT '',

    -- Average colour as #rrggbb, used as the background while the image loads.
    `dominant_color`  CHAR(7) NOT NULL DEFAULT '',

    -- Tiny base64 JPEG/WebP shown blurred before the real image arrives.
    `placeholder`     VARCHAR(1200) NOT NULL DEFAULT '',

    -- Generated sizes: {"thumb":{"path":"...","w":320,"h":213,"bytes":18234}, ...}
    `variants`        LONGTEXT NULL DEFAULT NULL,

    -- Poster frame for a video, itself a row in this table.
    `poster_media_id` INT UNSIGNED NULL DEFAULT NULL,

    `status`          ENUM('processing','ready','failed') NOT NULL DEFAULT 'ready',
    `processing_error` VARCHAR(500) NOT NULL DEFAULT '',

    -- Original plus every variant, so the storage report does not have to stat
    -- the filesystem.
    `storage_bytes`   BIGINT UNSIGNED NOT NULL DEFAULT 0,

    -- public   : anyone with the URL
    -- inherit  : follows the visibility of whatever it is attached to
    -- private  : signed-in users with access only
    `visibility`      ENUM('public','inherit','private') NOT NULL DEFAULT 'inherit',

    `usage_count`     INT UNSIGNED NOT NULL DEFAULT 0,

    `created_at`      DATETIME NOT NULL,
    `updated_at`      DATETIME NOT NULL,
    `deleted_at`      DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_media_uuid` (`uuid`),
    UNIQUE KEY `uq_media_path` (`path`),
    KEY `ix_media_checksum` (`checksum`),
    KEY `ix_media_user` (`user_id`, `created_at`),
    KEY `ix_media_kind` (`kind`, `deleted_at`),
    KEY `ix_media_captured` (`captured_at`),
    KEY `ix_media_geo` (`latitude`, `longitude`),
    KEY `ix_media_deleted` (`deleted_at`),
    CONSTRAINT `fk_media_user`
        FOREIGN KEY (`user_id`) REFERENCES `{{prefix}}users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_media_poster`
        FOREIGN KEY (`poster_media_id`) REFERENCES `{{prefix}}media` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Users get their avatar column now that media exists.
ALTER TABLE `{{prefix}}users`
    ADD CONSTRAINT `fk_users_avatar`
        FOREIGN KEY (`avatar_media_id`) REFERENCES `{{prefix}}media` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;
