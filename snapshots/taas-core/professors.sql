CREATE TABLE `{prefix}professors` (
  `professor_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `professor_ufid` varchar(8) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `professor_name` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `professor_email` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  PRIMARY KEY (`professor_id`),
  UNIQUE KEY `professor_email` (`professor_email`),
  UNIQUE KEY `professor_ufid` (`professor_ufid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
