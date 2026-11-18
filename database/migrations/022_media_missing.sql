-- 022: images in a connected WordPress site's media library that have no alt text (read through the plugin's separate "image list" approval),
-- with a suggested alt text, so they can be reviewed and applied in bulk. Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}media_missing` (
  `site_id` BIGINT UNSIGNED NOT NULL,
  `attachment_id` BIGINT UNSIGNED NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `title` VARCHAR(200) NULL,
  `parent_title` VARCHAR(200) NULL,
  `parent_url` VARCHAR(500) NULL,
  `suggestion` VARCHAR(300) NULL,
  `confidence` VARCHAR(5) CHARACTER SET ascii NOT NULL DEFAULT 'none',
  `status` VARCHAR(8) CHARACTER SET ascii NOT NULL DEFAULT 'open',
  `scanned_at` DATETIME NOT NULL,
  PRIMARY KEY (`site_id`, `attachment_id`),
  KEY `worklist` (`site_id`, `status`, `confidence`, `attachment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
