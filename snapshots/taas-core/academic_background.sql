CREATE TABLE `{prefix}academic_background` (
  `background_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_ufid` varchar(8) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `university` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `degree_type` tinyint unsigned NOT NULL,
  `major` varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `minor` varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `grad_date` date NOT NULL,
  `gpa` decimal(3,2) DEFAULT NULL,
  PRIMARY KEY (`background_id`),
  KEY `student_ufid` (`student_ufid`),
  CONSTRAINT `fk_background_student` FOREIGN KEY (`student_ufid`) REFERENCES `{prefix}students` (`student_ufid`) ON DELETE CASCADE,
  CONSTRAINT `chk_background_gpa` CHECK (((`gpa` is null) or ((`gpa` >= 0) and (`gpa` <= 4.00))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
