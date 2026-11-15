-- 008: Google Search Console connection (OAuth, read-only). The refresh token is stored ENCRYPTED (see core/Crypto.php).
-- Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}gsc_connections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `refresh_token_enc` TEXT NOT NULL,
  `property_url` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_sync_at` DATETIME NULL DEFAULT NULL,
  `last_status` VARCHAR(10) NOT NULL DEFAULT 'new',
  `last_error` VARCHAR(255) NULL DEFAULT NULL,
  `rows_synced` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
