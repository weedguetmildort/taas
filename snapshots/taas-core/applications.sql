CREATE TABLE `{prefix}applications` (
  `application_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_ufid` varchar(8) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `applied_course_id` varchar(20) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `has_taken_course` tinyint(1) NOT NULL DEFAULT '0',
  `has_ta_for_course` tinyint(1) NOT NULL DEFAULT '0',
  `student_statement` varchar(500) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `student_preference_rank` int DEFAULT NULL,
  `professor_preference_rank` int DEFAULT NULL,
  `match_status` tinyint unsigned NOT NULL DEFAULT '1',
  `assigned_hours` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`application_id`),
  UNIQUE KEY `student_course` (`student_ufid`,`applied_course_id`),
  KEY `applied_course_id` (`applied_course_id`),
  KEY `match_status` (`match_status`),
  CONSTRAINT `fk_applications_course` FOREIGN KEY (`applied_course_id`) REFERENCES `{prefix}courses` (`course_id`),
  CONSTRAINT `fk_applications_student` FOREIGN KEY (`student_ufid`) REFERENCES `{prefix}students` (`student_ufid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
