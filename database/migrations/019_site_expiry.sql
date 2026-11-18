-- 019: when each site's certificate and domain registration run out, and which warning was last sent.
-- Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}site_expiry` (
  `site_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(10) CHARACTER SET ascii NOT NULL,
  `state` VARCHAR(10) CHARACTER SET ascii NOT NULL,
  `expires_at` DATETIME NULL,
  `detail` VARCHAR(300) NULL,
  `error` VARCHAR(250) NULL,
  `checked_at` DATETIME NOT NULL,
  `alerted` SMALLINT NULL,
  PRIMARY KEY (`site_id`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `{{prefix}}sites` ADD COLUMN `expiry_at` DATETIME NULL;
