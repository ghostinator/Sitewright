-- 004: TOTP multi-factor authentication and the audit log. Statements are separated by ";" at end of line.

ALTER TABLE `{{prefix}}users`
  ADD COLUMN `totp_secret` VARCHAR(64) CHARACTER SET ascii NULL DEFAULT NULL,
  ADD COLUMN `totp_last_step` BIGINT UNSIGNED NOT NULL DEFAULT 0;

-- Single-use recovery codes; only a SHA-256 of the normalised code is stored.
CREATE TABLE IF NOT EXISTS `{{prefix}}recovery_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `code_hash` CHAR(64) CHARACTER SET ascii NOT NULL,
  `used_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_code` (`user_id`, `code_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Security-relevant events. No passwords, keys or prompt text are ever written here.
CREATE TABLE IF NOT EXISTS `{{prefix}}audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `username` VARCHAR(60) NOT NULL DEFAULT '',
  `action` VARCHAR(40) NOT NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `detail` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`),
  KEY `user_action` (`user_id`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
