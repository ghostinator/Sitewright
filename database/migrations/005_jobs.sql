-- 005: background job queue (crawl, bulk image analysis, mail, alerts). Statements are separated by ";" at end of line.

-- Small key/value store (worker heartbeat, health state).
CREATE TABLE IF NOT EXISTS `{{prefix}}kv` (
  `k` VARCHAR(64) NOT NULL,
  `v` VARCHAR(255) NOT NULL DEFAULT '',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(40) NOT NULL,
  `payload` MEDIUMTEXT NOT NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT 'queued',
  `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `run_after` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` DATETIME NULL DEFAULT NULL,
  `heartbeat_at` DATETIME NULL DEFAULT NULL,
  `finished_at` DATETIME NULL DEFAULT NULL,
  `result` MEDIUMTEXT NULL,
  `error` VARCHAR(500) NULL DEFAULT NULL,
  `error_status` SMALLINT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pick` (`status`, `run_after`, `id`),
  KEY `by_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
