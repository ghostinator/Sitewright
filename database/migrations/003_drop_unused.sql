-- 003: remove tables nothing reads or writes (ARCH-002). `users` is kept (authentication).
-- analytics_pages had a read query but no writer and no UI; projects/schema/settings were never used.
DROP TABLE IF EXISTS `{{prefix}}analytics_pages`;
DROP TABLE IF EXISTS `{{prefix}}projects`;
DROP TABLE IF EXISTS `{{prefix}}schema`;
DROP TABLE IF EXISTS `{{prefix}}settings`;
