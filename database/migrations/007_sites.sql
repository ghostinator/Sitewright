-- 007: connected WordPress sites (Sitewright calls the site; the site never calls Sitewright). Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}sites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `base_url` VARCHAR(255) NOT NULL,
  `secret_enc` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_sync_at` DATETIME NULL DEFAULT NULL,
  `last_status` VARCHAR(10) NOT NULL DEFAULT 'never',
  `last_error` VARCHAR(255) NULL DEFAULT NULL,
  `cursor_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `rules_hash` CHAR(64) NULL DEFAULT NULL,
  `sync_requested` TINYINT(1) NOT NULL DEFAULT 0,
  `plugin_version` VARCHAR(20) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_base` (`base_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
