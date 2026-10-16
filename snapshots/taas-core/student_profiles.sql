CREATE TABLE `{prefix}student_profiles` (
  `student_ufid` varchar(8) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `phone` varchar(15) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `admitted_semester` varchar(20) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `available_hours` int DEFAULT NULL,
  `college_status` tinyint unsigned DEFAULT NULL,
  `resume_url` varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `toefl_score` int DEFAULT NULL,
  `has_ta_experience` tinyint(1) NOT NULL DEFAULT '0',
  `eap5836_status` tinyint unsigned DEFAULT NULL,
  `eap5837_status` tinyint unsigned DEFAULT NULL,
  `country_code` varchar(2) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `additional_info` varchar(1000) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  PRIMARY KEY (`student_ufid`),
  CONSTRAINT `fk_profiles_student` FOREIGN KEY (`student_ufid`) REFERENCES `{prefix}students` (`student_ufid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
