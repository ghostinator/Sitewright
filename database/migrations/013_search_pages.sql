-- 013: Search Console query+page rows for the last 28 days (replaced on every sync of a connection). Feeds the opportunity finder.
-- Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}search_pages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `connection_id` BIGINT UNSIGNED NOT NULL,
  `page` VARCHAR(1000) NOT NULL,
  `page_hash` CHAR(64) CHARACTER SET ascii GENERATED ALWAYS AS (SHA2(`page`, 256)) STORED,
  `query` VARCHAR(500) NOT NULL,
  `query_hash` CHAR(64) CHARACTER SET ascii GENERATED ALWAYS AS (SHA2(`query`, 256)) STORED,
  `impressions` INT UNSIGNED NOT NULL DEFAULT 0,
  `clicks` INT UNSIGNED NOT NULL DEFAULT 0,
  `ctr` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `position` DECIMAL(6,2) NOT NULL DEFAULT 0,
  `window_start` DATE NOT NULL,
  `window_end` DATE NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_row` (`connection_id`, `page_hash`, `query_hash`),
  KEY `project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
