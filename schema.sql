-- ContentDB mirror schema. Import once into an empty MariaDB database.
-- Run: mysql -u USER -p DBNAME < schema.sql   (or via phpMyAdmin "Import")

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS packages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cdb_author VARCHAR(64) NOT NULL,
    cdb_name VARCHAR(64) NOT NULL,
    type VARCHAR(16) NOT NULL,                 -- mod | game | txp
    title VARCHAR(255) NOT NULL,
    short_description VARCHAR(512) NOT NULL DEFAULT '',
    long_description_md MEDIUMTEXT,
    long_description_html MEDIUMTEXT,
    license VARCHAR(64) DEFAULT NULL,
    media_license VARCHAR(64) DEFAULT NULL,
    forum_url VARCHAR(512) DEFAULT NULL,
    repo VARCHAR(512) DEFAULT NULL,
    issue_tracker VARCHAR(512) DEFAULT NULL,
    website VARCHAR(512) DEFAULT NULL,
    donate_url VARCHAR(512) DEFAULT NULL,
    thumbnail_source_url VARCHAR(512) DEFAULT NULL,
    thumbnail_local_path VARCHAR(512) DEFAULT NULL,
    score DECIMAL(10,4) DEFAULT NULL,
    downloads INT UNSIGNED DEFAULT 0,
    state VARCHAR(32) DEFAULT NULL,
    dev_state VARCHAR(32) DEFAULT NULL,
    cdb_created_at DATETIME DEFAULT NULL,
    cdb_approved_at DATETIME DEFAULT NULL,
    ai_disclosure VARCHAR(32) DEFAULT NULL,
    content_warnings JSON DEFAULT NULL,
    maintainers JSON DEFAULT NULL,
    game_support JSON DEFAULT NULL,            -- upstream's own resolved list, not recomputed by us
    supports_all_games TINYINT(1) NOT NULL DEFAULT 0,
    translation_url VARCHAR(512) DEFAULT NULL,
    video_url VARCHAR(512) DEFAULT NULL,
    video_thumbnail_url VARCHAR(512) DEFAULT NULL,
    latest_release_id INT UNSIGNED DEFAULT NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_removed_upstream TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_author_name (cdb_author, cdb_name),
    KEY idx_type (type),
    KEY idx_latest_release (latest_release_id),
    FULLTEXT KEY ft_title_desc (title, short_description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration for existing installs: CREATE TABLE IF NOT EXISTS above is a
-- no-op once the table already exists, so these columns need to be added
-- explicitly. ADD COLUMN IF NOT EXISTS is idempotent (MariaDB 10.0.2+) -
-- safe to re-run this whole file against a live database at any time.
ALTER TABLE packages
    ADD COLUMN IF NOT EXISTS ai_disclosure VARCHAR(32) DEFAULT NULL AFTER cdb_approved_at,
    ADD COLUMN IF NOT EXISTS content_warnings JSON DEFAULT NULL AFTER ai_disclosure,
    ADD COLUMN IF NOT EXISTS maintainers JSON DEFAULT NULL AFTER content_warnings,
    ADD COLUMN IF NOT EXISTS game_support JSON DEFAULT NULL AFTER maintainers,
    ADD COLUMN IF NOT EXISTS supports_all_games TINYINT(1) NOT NULL DEFAULT 0 AFTER game_support,
    ADD COLUMN IF NOT EXISTS translation_url VARCHAR(512) DEFAULT NULL AFTER supports_all_games,
    ADD COLUMN IF NOT EXISTS video_url VARCHAR(512) DEFAULT NULL AFTER translation_url,
    ADD COLUMN IF NOT EXISTS video_thumbnail_url VARCHAR(512) DEFAULT NULL AFTER video_url;

CREATE TABLE IF NOT EXISTS tags (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(64) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_tags (
    package_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (package_id, tag_id),
    KEY idx_tag (tag_id),
    CONSTRAINT fk_pt_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
    CONSTRAINT fk_pt_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS screenshots (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    package_id INT UNSIGNED NOT NULL,
    source_url VARCHAR(512) NOT NULL,
    local_path VARCHAR(512) DEFAULT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_package (package_id),
    CONSTRAINT fk_screenshot_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS releases (
    id INT UNSIGNED NOT NULL,                  -- ContentDB's own release id, used as PK
    package_id INT UNSIGNED NOT NULL,
    name VARCHAR(128) DEFAULT NULL,
    title VARCHAR(255) DEFAULT NULL,
    release_notes MEDIUMTEXT,
    release_date DATETIME DEFAULT NULL,
    min_engine_version VARCHAR(32) DEFAULT NULL,
    max_engine_version VARCHAR(32) DEFAULT NULL,
    size_bytes BIGINT UNSIGNED DEFAULT NULL,
    source_url VARCHAR(512) DEFAULT NULL,       -- relative /uploads/... path from API
    local_path VARCHAR(512) DEFAULT NULL,
    downloaded_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_package (package_id),
    CONSTRAINT fk_release_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS minetest_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(32) NOT NULL,
    is_dev TINYINT(1) NOT NULL DEFAULT 0,
    protocol_version INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_provides (
    package_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (package_id, name),
    KEY idx_name (name),
    CONSTRAINT fk_provides_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_depends (
    package_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,                -- raw string: plain modname OR "author/name"
    is_optional TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (package_id, name, is_optional),
    KEY idx_name (name),
    CONSTRAINT fk_depends_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    mode VARCHAR(16) NOT NULL,                  -- full | incremental
    packages_seen INT UNSIGNED NOT NULL DEFAULT 0,
    packages_added INT UNSIGNED NOT NULL DEFAULT 0,
    packages_updated INT UNSIGNED NOT NULL DEFAULT 0,
    releases_added INT UNSIGNED NOT NULL DEFAULT 0,
    packages_removed_flagged INT UNSIGNED NOT NULL DEFAULT 0,
    errors_count INT UNSIGNED NOT NULL DEFAULT 0,
    duration_seconds INT UNSIGNED DEFAULT NULL,
    notes MEDIUMTEXT,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
