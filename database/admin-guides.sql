CREATE TABLE IF NOT EXISTS site_guide_pages (
    slug VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    draft_payload JSON NULL,
    published_revision_id BIGINT UNSIGNED NULL,
    edit_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_guide_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id INT UNSIGNED NULL,
    payload JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY site_guide_revisions_slug (slug, id),
    CONSTRAINT site_guide_revisions_page FOREIGN KEY (slug) REFERENCES site_guide_pages (slug) ON DELETE CASCADE,
    CONSTRAINT site_guide_revisions_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
