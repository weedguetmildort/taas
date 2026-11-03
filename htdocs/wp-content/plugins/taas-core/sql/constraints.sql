-- ============================================================
-- TA Assignment System - foreign keys and check constraints
-- Separate from tables.sql because dbDelta cannot parse either clause
-- ============================================================

-- A profile cannot outlive its student
ALTER TABLE {prefix}student_profiles
	ADD CONSTRAINT fk_profiles_student
	FOREIGN KEY (student_ufid) REFERENCES {prefix}students(student_ufid)
	ON DELETE CASCADE;

-- Nor can a degree record
ALTER TABLE {prefix}academic_background
	ADD CONSTRAINT fk_background_student
	FOREIGN KEY (student_ufid) REFERENCES {prefix}students(student_ufid)
	ON DELETE CASCADE;

-- Submitted details belong to an existing student, like their shared profile
ALTER TABLE {prefix}student_submissions
	ADD CONSTRAINT fk_submissions_student
	FOREIGN KEY (student_ufid) REFERENCES {prefix}students(student_ufid)
	ON DELETE CASCADE;

-- Submitted terms use the same five semester values as TAAS_Semester
ALTER TABLE {prefix}student_submissions
	ADD CONSTRAINT chk_submissions_semester
	CHECK (semester IN (1, 2, 3, 4, 5));

-- A course must have a real professor. No cascade: deleting a professor
-- with live courses should fail loudly rather than delete the courses.
ALTER TABLE {prefix}courses
	ADD CONSTRAINT fk_courses_professor
	FOREIGN KEY (professor_ufid) REFERENCES {prefix}professors(professor_ufid);

-- Applications follow their student
ALTER TABLE {prefix}applications
	ADD CONSTRAINT fk_applications_student
	FOREIGN KEY (student_ufid) REFERENCES {prefix}students(student_ufid)
	ON DELETE CASCADE;

-- But not their course: deleting a course with applications should fail
ALTER TABLE {prefix}applications
	ADD CONSTRAINT fk_applications_course
	FOREIGN KEY (applied_course_id) REFERENCES {prefix}courses(course_id);

-- GPA on a 4.00 scale, or not yet provided
ALTER TABLE {prefix}academic_background
	ADD CONSTRAINT chk_background_gpa
	CHECK (gpa IS NULL OR (gpa >= 0 AND gpa <= 4.00));

-- Same rule for the current GPA on the profile
ALTER TABLE {prefix}student_profiles
	ADD CONSTRAINT chk_profiles_gpa
	CHECK (gpa IS NULL OR (gpa >= 0 AND gpa <= 4.00));

-- Lab hours capped at 2 per the staffing formula
ALTER TABLE {prefix}courses
	ADD CONSTRAINT chk_courses_lab_hours
	CHECK (lab_hours >= 0 AND lab_hours <= 2);
