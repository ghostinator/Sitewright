-- 016: alert history (every decision, including why an alert was held back), the batch/digest queue, and mute windows.
-- Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}alert_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `event` VARCHAR(60) NOT NULL,
  `level` VARCHAR(8) NOT NULL DEFAULT 'warning',
  `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `message` VARCHAR(500) NOT NULL DEFAULT '',
  `outcome` VARCHAR(12) NOT NULL,
  `channels` VARCHAR(60) NOT NULL DEFAULT '',
  `reason` VARCHAR(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `created` (`created_at`),
  KEY `outcome_time` (`outcome`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}alert_batch` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `mode` VARCHAR(8) NOT NULL,
  `event` VARCHAR(60) NOT NULL,
  `level` VARCHAR(8) NOT NULL DEFAULT 'warning',
  `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `message` VARCHAR(1500) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `mode_time` (`mode`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}alert_mutes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `until` DATETIME NOT NULL,
  `reason` VARCHAR(200) NOT NULL DEFAULT '',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `until` (`until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
