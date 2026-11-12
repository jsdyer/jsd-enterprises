CREATE TABLE IF NOT EXISTS community_hidden_topics (
    post_id INT UNSIGNED NOT NULL,
    reason VARCHAR(250) NOT NULL,
    hidden_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id),
    CONSTRAINT community_hidden_topic FOREIGN KEY (post_id) REFERENCES community_topics (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_hidden_replies (
    post_id INT UNSIGNED NOT NULL,
    reason VARCHAR(250) NOT NULL,
    hidden_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id),
    CONSTRAINT community_hidden_reply FOREIGN KEY (post_id) REFERENCES community_replies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
