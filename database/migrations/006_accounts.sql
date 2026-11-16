-- 006: roles, disabled accounts, password-reset tokens. Statements are separated by ";" at end of line.
-- Every account that exists before this migration was a full administrator, so it stays one; new rows default to the least privilege.

ALTER TABLE `{{prefix}}users`
  ADD COLUMN `role` VARCHAR(10) NOT NULL DEFAULT 'admin',
  ADD COLUMN `disabled` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `{{prefix}}users` ALTER COLUMN `role` SET DEFAULT 'viewer';

CREATE TABLE IF NOT EXISTS `{{prefix}}password_resets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) CHARACTER SET ascii NOT NULL,
  `purpose` VARCHAR(10) NOT NULL DEFAULT 'reset',
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token_hash`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
