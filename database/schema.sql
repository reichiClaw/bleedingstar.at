-- BleedingStar catalog. MySQL 8, utf8mb4.
-- Dates stay partial: year / month / day are nullable on purpose.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS artists (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_id VARCHAR(32) NULL,
  slug VARCHAR(190) NOT NULL,
  name VARCHAR(190) NOT NULL,
  bio_html MEDIUMTEXT NULL,
  status ENUM('published','draft','hidden') NOT NULL DEFAULT 'published',
  website VARCHAR(500) NULL,
  facebook VARCHAR(500) NULL,
  soundcloud VARCHAR(500) NULL,
  twitter VARCHAR(500) NULL,
  image_path VARCHAR(500) NULL,
  image_source VARCHAR(40) NOT NULL DEFAULT 'legacy',
  editorial_locked TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_artists_slug (slug),
  UNIQUE KEY uq_artists_legacy (legacy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artist_roles (
  artist_id INT UNSIGNED NOT NULL,
  role_name VARCHAR(80) NOT NULL,
  PRIMARY KEY (artist_id, role_name),
  CONSTRAINT fk_roles_artist FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS releases (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_id VARCHAR(32) NULL,
  slug VARCHAR(190) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description_html MEDIUMTEXT NULL,
  release_year SMALLINT NULL,
  release_month TINYINT NULL,
  release_day TINYINT NULL,
  release_date_precision ENUM('day','month','year','unknown') NOT NULL DEFAULT 'unknown',
  release_type VARCHAR(40) NULL,
  status ENUM('published','hidden','draft','pending') NOT NULL DEFAULT 'published',
  featured TINYINT(1) NOT NULL DEFAULT 0,
  cover_path VARCHAR(500) NULL,
  cover_grid_path VARCHAR(500) NULL,
  cover_source VARCHAR(40) NULL,
  cover_remote_url VARCHAR(800) NULL,
  cover_attribution VARCHAR(500) NULL,
  cover_page_url VARCHAR(800) NULL,
  cover_fetched_at DATETIME NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'legacy',
  editorial_locked TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_releases_slug (slug),
  UNIQUE KEY uq_releases_legacy (legacy_id),
  KEY idx_releases_pub (status, release_year, release_month, release_day),
  KEY idx_releases_featured (featured, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS release_artists (
  release_id INT UNSIGNED NOT NULL,
  artist_id INT UNSIGNED NOT NULL,
  position SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (release_id, artist_id),
  KEY idx_ra_artist (artist_id),
  CONSTRAINT fk_ra_release FOREIGN KEY (release_id) REFERENCES releases (id) ON DELETE CASCADE,
  CONSTRAINT fk_ra_artist FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tracks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  release_id INT UNSIGNED NOT NULL,
  position SMALLINT NOT NULL,
  title VARCHAR(255) NOT NULL,
  duration VARCHAR(16) NULL,
  stream_url VARCHAR(800) NULL,
  isrc VARCHAR(20) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_track_pos (release_id, position),
  CONSTRAINT fk_tracks_release FOREIGN KEY (release_id) REFERENCES releases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS release_links (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  release_id INT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL,
  url VARCHAR(800) NOT NULL,
  provider VARCHAR(40) NULL,
  manual TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_links_release (release_id),
  CONSTRAINT fk_links_release FOREIGN KEY (release_id) REFERENCES releases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS release_formats (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  release_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  catalog_number VARCHAR(80) NULL,
  upc VARCHAR(32) NULL,
  details VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_formats_release (release_id),
  KEY idx_formats_upc (upc),
  CONSTRAINT fk_formats_release FOREIGN KEY (release_id) REFERENCES releases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_ids (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(40) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  external_id VARCHAR(64) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_external (provider, entity_type, external_id),
  KEY idx_external_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
  slug VARCHAR(80) NOT NULL,
  title VARCHAR(190) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rental_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(80) NOT NULL,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rcat_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rental_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id INT UNSIGNED NOT NULL,
  slug VARCHAR(120) NOT NULL,
  name VARCHAR(190) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  description_html MEDIUMTEXT NULL,
  specs_html MEDIUMTEXT NULL,
  image_path VARCHAR(500) NULL,
  status ENUM('published','hidden') NOT NULL DEFAULT 'published',
  price_cents INT NULL,
  price_public TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ritem_slug (slug),
  KEY idx_ritem_cat (category_id, status),
  CONSTRAINT fk_ritem_cat FOREIGN KEY (category_id) REFERENCES rental_categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rental_files (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  path VARCHAR(500) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_rfile_item (item_id),
  CONSTRAINT fk_rfile_item FOREIGN KEY (item_id) REFERENCES rental_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redirects (
  source_path VARCHAR(190) NOT NULL,
  target_path VARCHAR(190) NOT NULL,
  PRIMARY KEY (source_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inquiries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  topic VARCHAR(40) NOT NULL,
  name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NOT NULL,
  message MEDIUMTEXT NOT NULL,
  rental_payload TEXT NULL,
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  mail_status VARCHAR(40) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip VARCHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_login_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  job VARCHAR(40) NOT NULL,
  status VARCHAR(20) NOT NULL,
  dry_run TINYINT(1) NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  created_count INT NOT NULL DEFAULT 0,
  updated_count INT NOT NULL DEFAULT 0,
  review_count INT NOT NULL DEFAULT 0,
  error_count INT NOT NULL DEFAULT 0,
  message TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_sync_job (job, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_reviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(40) NOT NULL,
  external_id VARCHAR(64) NOT NULL,
  candidate_release_id INT UNSIGNED NULL,
  payload_json MEDIUMTEXT NOT NULL,
  reason VARCHAR(190) NOT NULL,
  status ENUM('open','merged','dismissed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_review_open (provider, external_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(40) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  external_id VARCHAR(64) NOT NULL,
  payload_json MEDIUMTEXT NOT NULL,
  fetched_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_record (provider, entity_type, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(80) NOT NULL,
  svalue TEXT NOT NULL,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
