-- 020: certificates Sitewright gets from Let's Encrypt (DNS validation), the DNS accounts it uses to prove ownership, and the servers that fetch them.
-- Statements are separated by ";" at end of line. Private keys and API tokens are stored encrypted with the app key.

CREATE TABLE IF NOT EXISTS `{{prefix}}dns_providers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `type` VARCHAR(20) CHARACTER SET ascii NOT NULL,
  `config_enc` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `checked_at` DATETIME NULL,
  `check_ok` TINYINT(1) NULL,
  `check_msg` VARCHAR(300) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}acme_accounts` (
  `directory_hash` CHAR(64) CHARACTER SET ascii NOT NULL,
  `directory` VARCHAR(300) NOT NULL,
  `email` VARCHAR(200) NOT NULL DEFAULT '',
  `key_enc` TEXT NOT NULL,
  `kid` VARCHAR(500) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`directory_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}certificates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `names` TEXT NOT NULL,
  `dns_provider_id` BIGINT UNSIGNED NOT NULL,
  `site_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `directory` VARCHAR(300) NOT NULL DEFAULT '',
  `state` VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'new',
  `key_enc` TEXT NULL,
  `chain_pem` MEDIUMTEXT NULL,
  `not_before` DATETIME NULL,
  `not_after` DATETIME NULL,
  `fingerprint` CHAR(64) CHARACTER SET ascii NULL,
  `last_error` VARCHAR(500) NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_attempt_at` DATETIME NULL,
  `next_attempt_at` DATETIME NULL,
  `issued_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `{{prefix}}cert_targets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `certificate_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `token_hash` CHAR(64) CHARACTER SET ascii NOT NULL,
  `allow_cidrs` VARCHAR(500) NOT NULL DEFAULT '',
  `last_pull_at` DATETIME NULL,
  `last_pull_ip` VARCHAR(45) NULL,
  `installed_fingerprint` CHAR(64) CHARACTER SET ascii NULL,
  `installed_at` DATETIME NULL,
  `install_status` VARCHAR(10) CHARACTER SET ascii NULL,
  `install_error` VARCHAR(250) NULL,
  `stale_alerted` CHAR(64) CHARACTER SET ascii NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `certificate` (`certificate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
