CREATE TABLE `{prefix}hr_reviews` (
  `submission_id` bigint unsigned NOT NULL,
  `decision` varchar(7) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `version` int unsigned NOT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`submission_id`),
  CONSTRAINT `fk_hr_reviews_submission` FOREIGN KEY (`submission_id`) REFERENCES `{prefix}student_submissions` (`submission_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_hr_reviews_decision` CHECK (((`decision` in (_utf8mb4'approve',_utf8mb4'reject')) and (`version` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
