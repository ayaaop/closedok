CREATE TABLE `restore_events` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` varchar(128) NOT NULL,
  `item` bigint(20) UNSIGNED NOT NULL,
  `scope` bigint(20) NOT NULL DEFAULT 0,
  `actor` bigint(20) UNSIGNED NOT NULL,
  `deleted_at` bigint(20) UNSIGNED NOT NULL,
  `expires_at` bigint(20) UNSIGNED NOT NULL,
  `state` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `retained` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `target` (`kind`, `item`, `scope`, `id`),
  KEY `expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

ALTER TABLE `attachments` ADD KEY `target` (`target_type`, `target_id`);
ALTER TABLE `audio_relations` ADD KEY `audio` (`audio`);
