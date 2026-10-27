-- ============================================================
-- TA Application / Matching System — Database Schema (converted draft)
-- Based on: Frontend Data Requirements - Blackboard Builders
-- ============================================================

-- Professors: identification + portal display info
CREATE TABLE {prefix}professors (
	professor_ufid varchar(8) NOT NULL,
	professor_name varchar(255) NOT NULL,
	professor_email varchar(255) NOT NULL,
	PRIMARY KEY  (professor_ufid)
);

-- Students: identification + applicant list display info
CREATE TABLE {prefix}students (
	student_ufid varchar(8) NOT NULL,
	student_name varchar(255) NOT NULL,
	student_email varchar(255) NOT NULL,
	PRIMARY KEY  (student_ufid)
);

-- Student profiles: one-to-one extension of students for fields not needed
-- in every applicant-list query (availability, contact info, etc.).
CREATE TABLE {prefix}student_profiles (
	student_ufid varchar(8) NOT NULL,
	phone varchar(15) DEFAULT NULL,
	admitted_semester varchar(20) DEFAULT NULL,
	available_hours int DEFAULT NULL,                   -- NULL = not yet provided, distinct from 0
	major varchar(255) DEFAULT NULL,                    -- most recent/applicable major; string for multiple majors
	gpa decimal(3,2) DEFAULT NULL,                      -- most current GPA
	enrollment_status tinyint unsigned DEFAULT NULL,    -- current program, 0 = Not Enrolled, 1 = AA, 2 = BS, 3 = MS, 4 = PhD (bind to PHP enum)
	resume_url varchar(255) DEFAULT NULL,               -- url to something like google docs
	toefl_ibt_score int DEFAULT NULL,                   -- international students only
	toefl_ibt_date date DEFAULT NULL,
	has_ta_experience tinyint(1) NOT NULL DEFAULT 0,
	eap5836_status tinyint unsigned DEFAULT NULL,       -- (PhD) 1 = taken at uf, 2 = taken not at uf, 3 = taking, 4 = not taken
	eap5837_status tinyint unsigned DEFAULT NULL,       -- (PhD) same php enum values as above
	country_code varchar(2) DEFAULT NULL,               -- ISO 3166-1 alpha-2
	additional_info varchar(1000) DEFAULT NULL,         -- students' notes for anyone reading their profile (professors/HR)
	PRIMARY KEY  (student_ufid)
);

-- Academic background: one row per degree/program a student has completed
-- or is completing. One-to-many, since a student may have multiple degrees
-- (e.g. an AA plus a BS, or degrees from multiple institutions).
CREATE TABLE {prefix}academic_background (
	background_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	student_ufid varchar(8) NOT NULL,
	university varchar(255) NOT NULL,
	degree_type tinyint unsigned NOT NULL,              -- 1 = AA, 2 = BS, 3 = MS, 4 = PhD (bind to PHP enum)
	major varchar(255) DEFAULT NULL,                    -- can be multiple, like "Computer Science, Business"
	minor varchar(255) DEFAULT NULL,
	grad_date date NOT NULL,                            -- store first-of-month; format as MM/YYYY in frontend
	gpa decimal(3,2) DEFAULT NULL,
	PRIMARY KEY  (background_id),
	KEY student_ufid (student_ufid)
);

-- Courses: identification, owned by a professor
CREATE TABLE {prefix}courses (
	course_id varchar(20) NOT NULL,                     -- course section number
	course_code varchar(20) NOT NULL,
	course_name varchar(255) NOT NULL,
	course_semester tinyint unsigned NOT NULL,          -- 1 = Fall, 2 = Spring, 3 = SummerA, 4 = SummerB, 5 = SummerC
	course_year year NOT NULL,
	is_online tinyint(1) NOT NULL DEFAULT 0,
	course_description varchar(1000) DEFAULT NULL,
	course_notes varchar(1000) DEFAULT NULL,            -- professor's notes/requests regarding matching
	sections int DEFAULT NULL,
	weekly_hours int DEFAULT NULL,                      -- total TA hours required, calculated by formula
	lab_hours int NOT NULL DEFAULT 0,
	enrollment int NOT NULL DEFAULT 0,
	max_enrollment int NOT NULL DEFAULT 0,
	professor_ufid varchar(8) NOT NULL,
	PRIMARY KEY  (course_id),
	KEY course_code (course_code),
	KEY professor_ufid (professor_ufid)
);

-- Applications: the join between a student and a course they applied to TA for.
-- This is where per-applicant, per-course data lives (rankings, eligibility flags,
-- and eventual match outcome).
CREATE TABLE {prefix}applications (
	application_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	student_ufid varchar(8) NOT NULL,
	applied_course_id varchar(20) NOT NULL,             -- links applicant to course
	has_taken_course tinyint(1) NOT NULL DEFAULT 0,
	student_statement varchar(500) DEFAULT NULL,
	student_preference_rank int DEFAULT NULL,           -- how the student ranks this course among their applications
	professor_preference_rank int DEFAULT NULL,         -- how the professor ranks this applicant among theirs
	match_status tinyint unsigned NOT NULL DEFAULT 1,   -- 1 = pending, 2 = matched, 3 = rejected, 4 = withdrawn (bind to PHP enum)
	PRIMARY KEY  (application_id),
	UNIQUE KEY student_course (student_ufid,applied_course_id),
	KEY applied_course_id (applied_course_id),
	KEY match_status (match_status)
);