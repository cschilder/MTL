-- =============================================================================
-- Trips (a journey) and steps (a stop within it, with its own report).
--
-- A step is the unit the globe plots and the unit a reader lands on from a
-- marker, so it carries both the coordinates and the written report.
-- =============================================================================

CREATE TABLE `{{prefix}}trips` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36) NOT NULL,
    `user_id`          INT UNSIGNED NULL DEFAULT NULL,

    `title`            VARCHAR(191) NOT NULL,
    `slug`             VARCHAR(191) NOT NULL,
    `summary`          VARCHAR(500) NOT NULL DEFAULT '',

    -- The long-form introduction. Markdown is the stored truth; the rendered
    -- HTML is cached alongside it so a page view never runs the parser.
    `body_md`          MEDIUMTEXT NULL DEFAULT NULL,
    `body_html`        MEDIUMTEXT NULL DEFAULT NULL,

    `cover_media_id`   INT UNSIGNED NULL DEFAULT NULL,

    `start_date`       DATE NULL DEFAULT NULL,
    `end_date`         DATE NULL DEFAULT NULL,

    `status`           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',

    -- public   : listed and readable by anyone
    -- unlisted : readable with the share link, never listed
    -- private  : signed-in users with access only
    `visibility`       ENUM('public','unlisted','private') NOT NULL DEFAULT 'private',

    -- Secret component of the unlisted share URL.
    `share_token`      CHAR(22) NULL DEFAULT NULL,

    -- ISO 3166-1 alpha-2 codes touched by this trip, JSON encoded. Denormalised
    -- from the steps so the globe legend and the trip list avoid a join.
    `country_codes`    VARCHAR(500) NOT NULL DEFAULT '',

    -- Great-circle distance along the ordered steps, in kilometres.
    `distance_km`      DECIMAL(10,2) NOT NULL DEFAULT 0,

    -- Maintained by triggers in application code on write, so listings are a
    -- single query.
    `step_count`       INT UNSIGNED NOT NULL DEFAULT 0,
    `media_count`      INT UNSIGNED NOT NULL DEFAULT 0,
    `view_count`       INT UNSIGNED NOT NULL DEFAULT 0,

    -- Accent colour for the route on the globe, #rrggbb.
    `color`            CHAR(7) NOT NULL DEFAULT '#2ec27e',

    -- Sort key for the trip overview; lower comes first, then start_date.
    `position`         INT NOT NULL DEFAULT 0,

    `created_at`       DATETIME NOT NULL,
    `updated_at`       DATETIME NOT NULL,
    `published_at`     DATETIME NULL DEFAULT NULL,
    `deleted_at`       DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_trips_uuid` (`uuid`),
    UNIQUE KEY `uq_trips_slug` (`slug`),
    UNIQUE KEY `uq_trips_share_token` (`share_token`),
    KEY `ix_trips_user` (`user_id`, `created_at`),
    KEY `ix_trips_listing` (`status`, `visibility`, `deleted_at`),
    KEY `ix_trips_dates` (`start_date`, `end_date`),
    CONSTRAINT `fk_trips_user`
        FOREIGN KEY (`user_id`) REFERENCES `{{prefix}}users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_trips_cover`
        FOREIGN KEY (`cover_media_id`) REFERENCES `{{prefix}}media` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `{{prefix}}steps` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36) NOT NULL,
    `trip_id`          INT UNSIGNED NOT NULL,
    `user_id`          INT UNSIGNED NULL DEFAULT NULL,

    `title`            VARCHAR(191) NOT NULL,

    -- Unique within the trip, not globally, so two journeys can both have a
    -- step called "aankomst".
    `slug`             VARCHAR(191) NOT NULL,

    -- The travel report itself.
    `body_md`          MEDIUMTEXT NULL DEFAULT NULL,
    `body_html`        MEDIUMTEXT NULL DEFAULT NULL,

    -- Plain-text opening lines, for listings, search and meta descriptions.
    `excerpt`          VARCHAR(500) NOT NULL DEFAULT '',

    `latitude`         DECIMAL(10,7) NULL DEFAULT NULL,
    `longitude`        DECIMAL(10,7) NULL DEFAULT NULL,
    `altitude_m`       DECIMAL(8,2) NULL DEFAULT NULL,

    `location_name`    VARCHAR(191) NOT NULL DEFAULT '',
    `country_code`     CHAR(2) NOT NULL DEFAULT '',
    `timezone`         VARCHAR(64) NOT NULL DEFAULT '',

    -- When the visitor was there, in UTC. This is the axis the globe's time
    -- scrubber moves along.
    `occurred_at`      DATETIME NULL DEFAULT NULL,
    `occurred_end_at`  DATETIME NULL DEFAULT NULL,

    -- Manual ordering inside the trip. Steps without coordinates or dates
    -- still need a defined sequence.
    `position`         INT NOT NULL DEFAULT 0,

    `status`           ENUM('draft','published') NOT NULL DEFAULT 'draft',
    `visibility`       ENUM('inherit','public','private') NOT NULL DEFAULT 'inherit',

    `cover_media_id`   INT UNSIGNED NULL DEFAULT NULL,

    -- Optional extra dimensions plotted on the globe: weather at the time,
    -- and a 1-5 rating that can drive marker height or colour.
    `weather`          VARCHAR(500) NOT NULL DEFAULT '',
    `temperature_c`    DECIMAL(4,1) NULL DEFAULT NULL,
    `rating`           TINYINT UNSIGNED NULL DEFAULT NULL,

    -- Distance from the previous step along the route, in kilometres.
    `distance_km`      DECIMAL(10,2) NOT NULL DEFAULT 0,

    `media_count`      INT UNSIGNED NOT NULL DEFAULT 0,
    `view_count`       INT UNSIGNED NOT NULL DEFAULT 0,

    `created_at`       DATETIME NOT NULL,
    `updated_at`       DATETIME NOT NULL,
    `published_at`     DATETIME NULL DEFAULT NULL,
    `deleted_at`       DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_steps_uuid` (`uuid`),
    UNIQUE KEY `uq_steps_trip_slug` (`trip_id`, `slug`),
    KEY `ix_steps_trip_order` (`trip_id`, `position`),
    KEY `ix_steps_time` (`occurred_at`),
    KEY `ix_steps_geo` (`latitude`, `longitude`),
    KEY `ix_steps_country` (`country_code`),
    KEY `ix_steps_status` (`status`, `deleted_at`),
    CONSTRAINT `fk_steps_trip`
        FOREIGN KEY (`trip_id`) REFERENCES `{{prefix}}trips` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_steps_user`
        FOREIGN KEY (`user_id`) REFERENCES `{{prefix}}users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_steps_cover`
        FOREIGN KEY (`cover_media_id`) REFERENCES `{{prefix}}media` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Media attached to a step, in the order they appear in the gallery.
CREATE TABLE `{{prefix}}step_media` (
    `step_id`   INT UNSIGNED NOT NULL,
    `media_id`  INT UNSIGNED NOT NULL,
    `position`  INT NOT NULL DEFAULT 0,

    -- Overrides media.caption for this appearance only, so the same photo can
    -- read differently in two reports.
    `caption`   VARCHAR(500) NOT NULL DEFAULT '',

    `created_at` DATETIME NOT NULL,

    PRIMARY KEY (`step_id`, `media_id`),
    KEY `ix_step_media_order` (`step_id`, `position`),
    KEY `ix_step_media_media` (`media_id`),
    CONSTRAINT `fk_step_media_step`
        FOREIGN KEY (`step_id`) REFERENCES `{{prefix}}steps` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_step_media_media`
        FOREIGN KEY (`media_id`) REFERENCES `{{prefix}}media` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
