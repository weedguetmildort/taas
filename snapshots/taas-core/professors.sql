CREATE TABLE `{prefix}professors` (
  `professor_ufid` varchar(8) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `professor_name` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `professor_email` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  PRIMARY KEY (`professor_ufid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
