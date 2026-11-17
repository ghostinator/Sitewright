-- 012: site audits (a crawl that analyses every page and lists concrete issues). No page bodies are kept, only extracted facts.
-- Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}audits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `base_url` VARCHAR(500) NOT NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT 'queued',
  `job_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `pages` INT UNSIGNED NOT NULL DEFAULT 0,
  `stopped_early` TINYINT(1) NOT NULL DEFAULT 0,
  `error` VARCHAR(255) NULL DEFAULT NULL,
  `summary` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` DATETIME NULL DEFAULT NULL,
  `finished_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_time` (`project_id`, `created_at`),
  KEY `state` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}audit_pages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `audit_id` BIGINT UNSIGNED NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `title_len` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `desc_len` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `h1_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `word_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `noindex` TINYINT(1) NOT NULL DEFAULT 0,
  `score` INT NULL DEFAULT NULL,
  `load_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `inbound` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `audit_score` (`audit_id`, `score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}audit_issues` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `audit_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(40) NOT NULL,
  `severity` VARCHAR(6) NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `detail` VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `audit_code` (`audit_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
