-- 011: watched pages. History lives in seo_analysis (watch_id); this table holds the schedule and the last known state.
-- Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}watch_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `url` VARCHAR(500) NOT NULL,
  `url_hash` CHAR(64) CHARACTER SET ascii GENERATED ALWAYS AS (SHA2(`url`, 256)) STORED,
  `label` VARCHAR(100) NOT NULL DEFAULT '',
  `interval_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `next_run_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_run_at` DATETIME NULL DEFAULT NULL,
  `last_score` INT NULL DEFAULT NULL,
  `last_status` VARCHAR(10) NOT NULL DEFAULT 'new',
  `last_error` VARCHAR(255) NULL DEFAULT NULL,
  `fail_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_noindex` TINYINT(1) NOT NULL DEFAULT 0,
  `last_has_title` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_watch` (`project_id`, `url_hash`),
  KEY `due` (`enabled`, `next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
