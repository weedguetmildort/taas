CREATE TABLE `{prefix}match_recommendations` (
  `recommendation_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint unsigned NOT NULL,
  `student_ufid` varchar(8) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `recommended_hours` int NOT NULL,
  `reason` varchar(40) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  PRIMARY KEY (`recommendation_id`),
  UNIQUE KEY `course_student` (`course_id`,`student_ufid`),
  KEY `student_ufid` (`student_ufid`),
  CONSTRAINT `fk_recommendations_course` FOREIGN KEY (`course_id`) REFERENCES `{prefix}courses` (`course_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recommendations_student` FOREIGN KEY (`student_ufid`) REFERENCES `{prefix}students` (`student_ufid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
