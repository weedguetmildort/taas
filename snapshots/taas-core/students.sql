CREATE TABLE `{prefix}students` (
  `student_ufid` varchar(8) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `student_name` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `student_email` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  PRIMARY KEY (`student_ufid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
