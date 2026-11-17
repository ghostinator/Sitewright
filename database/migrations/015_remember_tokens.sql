-- 015: "keep me signed in" tokens. Only a SHA-256 of the secret half is stored; the cookie holds selector.secret.
-- Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}remember_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `selector` CHAR(24) CHARACTER SET ascii NOT NULL,
  `validator_hash` CHAR(64) CHARACTER SET ascii NOT NULL,
  `pwf` CHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '',
  `user_agent` VARCHAR(200) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_selector` (`selector`),
  KEY `user` (`user_id`),
  KEY `expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
