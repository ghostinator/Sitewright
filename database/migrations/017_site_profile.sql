-- 017: what each site says about itself (found by reading its public pages) and what the owner chose to do with each item.
-- Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}site_profile` (
  `site_id` BIGINT UNSIGNED NOT NULL,
  `field` VARCHAR(30) CHARACTER SET ascii NOT NULL,
  `found` VARCHAR(600) NULL,
  `value` VARCHAR(600) NULL,
  `state` VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'pending',
  `found_at` DATETIME NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`site_id`, `field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `{{prefix}}sites` ADD COLUMN `profile_at` DATETIME NULL, ADD COLUMN `profile_error` VARCHAR(250) NULL;
