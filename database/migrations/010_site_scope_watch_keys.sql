-- 010: per-site scoping of Search Console imports and a stable history key for watched pages.
-- Statements are separated by ";" at end of line.

ALTER TABLE `{{prefix}}gsc_connections` ADD COLUMN `site_id` BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE `{{prefix}}seo_analysis` ADD COLUMN `watch_id` BIGINT UNSIGNED NULL DEFAULT NULL, ADD KEY `watch_idx` (`watch_id`, `analyzed_at`), ADD KEY `project_time` (`project_id`, `analyzed_at`);
