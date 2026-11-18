-- 021: every change Sitewright makes to the alt text or title of an image on a connected WordPress site, with what it was before, so it can be undone.
-- Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}media_changes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` BIGINT UNSIGNED NOT NULL,
  `image_url` VARCHAR(1000) NOT NULL,
  `attachment_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `old_alt` VARCHAR(600) NULL,
  `new_alt` VARCHAR(600) NULL,
  `old_title` VARCHAR(300) NULL,
  `new_title` VARCHAR(300) NULL,
  `applied_by` BIGINT UNSIGNED NULL,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `undone_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `site_time` (`site_id`, `applied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
