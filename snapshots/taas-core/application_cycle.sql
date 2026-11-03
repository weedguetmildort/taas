CREATE TABLE `{prefix}application_cycle` (
  `cycle_id` tinyint unsigned NOT NULL,
  `cycle_semester` tinyint unsigned NOT NULL,
  `cycle_year` year NOT NULL,
  `student_application_start_at` datetime DEFAULT NULL,
  `student_application_end_at` datetime DEFAULT NULL,
  `hr_review_start_at` datetime DEFAULT NULL,
  `hr_review_end_at` datetime DEFAULT NULL,
  `professor_ranking_start_at` datetime DEFAULT NULL,
  `professor_ranking_end_at` datetime DEFAULT NULL,
  `admin_matching_start_at` datetime DEFAULT NULL,
  `admin_matching_end_at` datetime DEFAULT NULL,
  PRIMARY KEY (`cycle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
