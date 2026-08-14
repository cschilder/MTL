-- =============================================================================
-- Travel companions: members linked to a trip so they can edit it together.
--
-- The owner of a trip (or an administrator) links other registered members to
-- it; a linked member may edit the trip and its stops as if it were their own.
-- Managing the links stays with the owner — a companion cannot invite others.
-- =============================================================================

CREATE TABLE `{{prefix}}trip_collaborators` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `trip_id`    INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NOT NULL,

    -- Who created the link, for the audit trail.
    `added_by`   INT UNSIGNED NULL DEFAULT NULL,

    `created_at` DATETIME NOT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_trip_user` (`trip_id`, `user_id`),
    KEY `ix_user` (`user_id`),

    CONSTRAINT `fk_collab_trip` FOREIGN KEY (`trip_id`)
        REFERENCES `{{prefix}}trips` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_collab_user` FOREIGN KEY (`user_id`)
        REFERENCES `{{prefix}}users` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
