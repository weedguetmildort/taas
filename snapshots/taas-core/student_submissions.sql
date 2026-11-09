CREATE TABLE `{prefix}student_submissions` (
  `submission_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_ufid` varchar(8) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `semester` tinyint unsigned NOT NULL,
  `year` year NOT NULL,
  `submitted_details` json NOT NULL,
  PRIMARY KEY (`submission_id`),
  UNIQUE KEY `student_term` (`student_ufid`,`semester`,`year`),
  CONSTRAINT `fk_submissions_student` FOREIGN KEY (`student_ufid`) REFERENCES `{prefix}students` (`student_ufid`) ON DELETE CASCADE,
  CONSTRAINT `chk_submissions_semester` CHECK ((`semester` in (1,2,3,4,5)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
