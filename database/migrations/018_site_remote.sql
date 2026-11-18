-- 018: what a WordPress site's owner has approved us to read through the plugin (1.1+), and what we stored from it.
-- Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}site_remote` (
  `site_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(20) CHARACTER SET ascii NOT NULL,
  `state` VARCHAR(12) CHARACTER SET ascii NOT NULL,
  `data` TEXT NULL,
  `items` INT UNSIGNED NOT NULL DEFAULT 0,
  `fetched_at` DATETIME NULL,
  `error` VARCHAR(250) NULL,
  PRIMARY KEY (`site_id`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}site_pages` (
  `site_id` BIGINT UNSIGNED NOT NULL,
  `path_hash` CHAR(40) CHARACTER SET ascii NOT NULL,
  `path` VARCHAR(500) NOT NULL,
  `type` VARCHAR(30) CHARACTER SET ascii NOT NULL DEFAULT 'page',
  `title` VARCHAR(300) NULL,
  `modified` DATETIME NULL,
  PRIMARY KEY (`site_id`, `path_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}site_seo` (
  `site_id` BIGINT UNSIGNED NOT NULL,
  `path_hash` CHAR(40) CHARACTER SET ascii NOT NULL,
  `path` VARCHAR(500) NOT NULL,
  `seo_title` VARCHAR(300) NULL,
  `seo_description` VARCHAR(500) NULL,
  `noindex` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`site_id`, `path_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
