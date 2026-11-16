-- 002: integrity, auth support, honesty columns. Statements are separated by ";" at end of line.

CREATE TABLE IF NOT EXISTS `{{prefix}}rate_limits` (
  `bucket` CHAR(64) NOT NULL,
  `window_start` INT UNSIGNED NOT NULL,
  `hits` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`bucket`),
  KEY `window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- project_id: 0 means "no project" (NULL cannot take part in unique keys). Also flag generated sample rows.
UPDATE `{{prefix}}seo_analysis` SET `project_id` = 0 WHERE `project_id` IS NULL;
ALTER TABLE `{{prefix}}seo_analysis` MODIFY `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN `is_sample` TINYINT(1) NOT NULL DEFAULT 0;

UPDATE `{{prefix}}analytics_keywords` SET `project_id` = 0 WHERE `project_id` IS NULL;
ALTER TABLE `{{prefix}}analytics_keywords` MODIFY `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN `is_sample` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `keyword_hash` CHAR(64) CHARACTER SET ascii GENERATED ALWAYS AS (SHA2(`keyword`, 256)) STORED;
DELETE a FROM `{{prefix}}analytics_keywords` a JOIN `{{prefix}}analytics_keywords` b
  ON a.project_id = b.project_id AND a.keyword_hash = b.keyword_hash AND a.`date` = b.`date` AND a.id > b.id;
ALTER TABLE `{{prefix}}analytics_keywords` ADD UNIQUE KEY `uniq_keyword_day` (`project_id`, `keyword_hash`, `date`);

UPDATE `{{prefix}}404_monitor` SET `project_id` = 0 WHERE `project_id` IS NULL;
ALTER TABLE `{{prefix}}404_monitor` MODIFY `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN `is_sample` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `uri_hash` CHAR(64) CHARACTER SET ascii GENERATED ALWAYS AS (SHA2(`uri`, 256)) STORED;
DELETE a FROM `{{prefix}}404_monitor` a JOIN `{{prefix}}404_monitor` b
  ON a.project_id = b.project_id AND a.uri_hash = b.uri_hash AND a.id > b.id;
ALTER TABLE `{{prefix}}404_monitor` ADD UNIQUE KEY `uniq_uri` (`project_id`, `uri_hash`);

UPDATE `{{prefix}}redirections` SET `project_id` = 0 WHERE `project_id` IS NULL;
ALTER TABLE `{{prefix}}redirections` MODIFY `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN `is_sample` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `source_hash` CHAR(64) CHARACTER SET ascii GENERATED ALWAYS AS (SHA2(`source_url`, 256)) STORED;
DELETE a FROM `{{prefix}}redirections` a JOIN `{{prefix}}redirections` b
  ON a.project_id = b.project_id AND a.source_hash = b.source_hash AND a.id > b.id;
ALTER TABLE `{{prefix}}redirections` ADD UNIQUE KEY `uniq_source` (`project_id`, `source_hash`);

UPDATE `{{prefix}}local_locations` SET `project_id` = 0 WHERE `project_id` IS NULL;
ALTER TABLE `{{prefix}}local_locations` MODIFY `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN `is_sample` TINYINT(1) NOT NULL DEFAULT 0;

-- Only plain URL sitemaps are generated (news/video/image extensions were never emitted); fold legacy rows into 'general'.
UPDATE `{{prefix}}sitemaps` SET `project_id` = 0 WHERE `project_id` IS NULL;
UPDATE `{{prefix}}sitemaps` SET `type` = 'general' WHERE `type` <> 'general';
ALTER TABLE `{{prefix}}sitemaps` MODIFY `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY `type` ENUM('general') NOT NULL DEFAULT 'general',
  ADD COLUMN `url_hash` CHAR(64) CHARACTER SET ascii GENERATED ALWAYS AS (SHA2(`url`, 256)) STORED;
DELETE a FROM `{{prefix}}sitemaps` a JOIN `{{prefix}}sitemaps` b
  ON a.project_id = b.project_id AND a.`type` = b.`type` AND a.url_hash = b.url_hash AND a.id > b.id;
ALTER TABLE `{{prefix}}sitemaps` ADD UNIQUE KEY `uniq_sitemap_url` (`project_id`, `type`, `url_hash`);

-- Content AI: record which path actually produced the text ('unknown' for rows created before this column existed).
UPDATE `{{prefix}}content_ai` SET `project_id` = 0 WHERE `project_id` IS NULL;
ALTER TABLE `{{prefix}}content_ai` MODIFY `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'unknown';

-- Image SEO: unknown optimisation state is NULL, not 0/1; one row per (project, image URL).
UPDATE `{{prefix}}image_seo` SET `project_id` = 0 WHERE `project_id` IS NULL;
ALTER TABLE `{{prefix}}image_seo` MODIFY `project_id` BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY `optimized` TINYINT(1) NULL DEFAULT NULL,
  ADD COLUMN `url_hash` CHAR(64) CHARACTER SET ascii GENERATED ALWAYS AS (SHA2(`image_url`, 256)) STORED;
DELETE a FROM `{{prefix}}image_seo` a JOIN `{{prefix}}image_seo` b
  ON a.project_id = b.project_id AND a.url_hash = b.url_hash AND a.id > b.id;
ALTER TABLE `{{prefix}}image_seo` ADD UNIQUE KEY `uniq_image_url` (`project_id`, `url_hash`);
