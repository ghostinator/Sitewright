-- 014: Core Web Vitals measured through Google PageSpeed Insights (opt-in), and a per-watch switch for weekly measurements.
-- Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}pagespeed_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `watch_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `url` VARCHAR(500) NOT NULL,
  `strategy` VARCHAR(8) NOT NULL DEFAULT 'mobile',
  `status` VARCHAR(12) NOT NULL DEFAULT 'ok',
  `error` VARCHAR(255) NULL DEFAULT NULL,
  `perf_score` TINYINT UNSIGNED NULL DEFAULT NULL,
  `lcp_ms` INT UNSIGNED NULL DEFAULT NULL,
  `cls` DECIMAL(6,3) NULL DEFAULT NULL,
  `tbt_ms` INT UNSIGNED NULL DEFAULT NULL,
  `fcp_ms` INT UNSIGNED NULL DEFAULT NULL,
  `ttfb_ms` INT UNSIGNED NULL DEFAULT NULL,
  `field_category` VARCHAR(12) NULL DEFAULT NULL,
  `field_lcp_ms` INT UNSIGNED NULL DEFAULT NULL,
  `field_cls` DECIMAL(6,3) NULL DEFAULT NULL,
  `field_inp_ms` INT UNSIGNED NULL DEFAULT NULL,
  `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `url_time` (`url`(191), `fetched_at`),
  KEY `watch_time` (`watch_id`, `fetched_at`),
  KEY `project_time` (`project_id`, `fetched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `{{prefix}}watch_items` ADD COLUMN `pagespeed` TINYINT(1) NOT NULL DEFAULT 0;
