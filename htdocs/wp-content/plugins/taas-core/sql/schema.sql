-- ============================================================
-- TA Application / Matching System — Database Schema (converted draft)
-- Based on: Frontend Data Requirements - Blackboard Builders
-- ============================================================

-- Professors: identification + portal display info.
-- Course ingestion creates professors from name and email alone, since the admin
-- does not have UFIDs. The UFID is filled in on the professor's first Shibboleth
-- sign-in, matched by email (TAAS_Professor_Repository::find_or_claim()).
CREATE TABLE {prefix}professors (
	professor_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	professor_ufid varchar(8) DEFAULT NULL,             -- NULL until the professor first signs in
	professor_name varchar(255) NOT NULL,
	professor_email varchar(255) NOT NULL,              -- stored lowercased
	PRIMARY KEY  (professor_id),
	UNIQUE KEY professor_ufid (professor_ufid),         -- many NULLs are allowed
	UNIQUE KEY professor_email (professor_email)
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
	phone varchar(16) DEFAULT NULL,
	admitted_semester varchar(20) DEFAULT NULL,
	available_hours int DEFAULT NULL,                   -- NULL = not yet provided, distinct from 0
	major varchar(255) DEFAULT NULL,                    -- most recent/applicable major; string for multiple majors
	gpa decimal(3,2) DEFAULT NULL,                      -- most current GPA
	enrollment_status tinyint unsigned DEFAULT NULL,    -- current program, 0 = Not Enrolled, 1 = Undergraduate, 2 = Masters, 3 = PhD (bind to PHP enum)
	resume_url varchar(255) DEFAULT NULL,               -- url to something like google docs
	fws_eligibility varchar(14) NOT NULL DEFAULT 'no',  -- yes, no, not_applicable; graduates/default profiles use no
	fws_award_letter_url varchar(255) DEFAULT NULL,     -- separate from the resume; required for eligible undergraduates
	english_test_score tinyint unsigned DEFAULT NULL, -- selected range 1–4; null until answered
	has_ta_experience tinyint(1) NOT NULL DEFAULT 0,
	eap5836_status tinyint unsigned DEFAULT NULL,       -- 1 = taken, 2 = enrolled, 3 = not yet taken, 4 = N/A
	eap5837_status tinyint unsigned DEFAULT NULL,       -- same EAP status codes as above
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

-- TODO: This table's schema is not finalized; review with the team before release.
-- Preserve one submitted-details snapshot per student and academic term.
-- submitted_details holds profile/identity fields and academic-background entries;
-- course choices remain in applications. Same-term edits replace this snapshot.
-- JSON keys: student_name, student_email, profile, academic_background (ordered degrees).
-- Owner and term remain separate columns. This column has no nested-field SQL validation.
-- Full field schema: TAAS_Student_Submission constructor PHPDoc and
-- docs/student-application-api.md (Submitted-details snapshot contract).
CREATE TABLE {prefix}student_submissions (
	submission_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	student_ufid varchar(8) NOT NULL,
	semester tinyint unsigned NOT NULL,                 -- TAAS_Semester enum, same values as courses.course_semester
	year year NOT NULL,
	submitted_details json NOT NULL,
	PRIMARY KEY  (submission_id),
	UNIQUE KEY student_term (student_ufid,semester,year)
);

-- Latest HR decision for a submitted application. No row means unreviewed.
CREATE TABLE {prefix}hr_reviews (
	submission_id bigint(20) unsigned NOT NULL,
	decision varchar(7) NOT NULL,
	version int unsigned NOT NULL,
	reviewed_by bigint(20) unsigned DEFAULT NULL,
	reviewed_at datetime DEFAULT NULL,
	review_comment text,
	PRIMARY KEY  (submission_id)
);

-- Courses: identification, owned by a professor
CREATE TABLE {prefix}courses (
	course_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, -- one offering of a course in one term
	course_code varchar(20) NOT NULL,
	course_name varchar(255) NOT NULL,
	course_semester tinyint unsigned NOT NULL,          -- 1 = Fall, 2 = Spring, 3 = SummerA, 4 = SummerB, 5 = SummerC
	course_year year NOT NULL,
	is_online tinyint(1) NOT NULL DEFAULT 0,
	course_description varchar(1000) DEFAULT NULL,
	course_notes varchar(1000) DEFAULT NULL,            -- professor's notes/requests regarding matching
	weekly_hours int DEFAULT NULL,                      -- total TA hours required, calculated by formula
	lab_hours int NOT NULL DEFAULT 0,
	enrollment int NOT NULL DEFAULT 0,
	max_enrollment int NOT NULL DEFAULT 0,
	professor_id bigint(20) unsigned NOT NULL,
	eligible_student_types tinyint unsigned NOT NULL DEFAULT 7, -- bit mask: undergraduate 1, master's 2, PhD 4
	PRIMARY KEY  (course_id),
	UNIQUE KEY course_offering_by_delivery (course_semester,course_year,course_code,course_name,is_online),
	KEY course_code (course_code),
	KEY professor_id (professor_id)
);

-- Applications: the join between a student and a course they applied to TA for.
-- This is where per-applicant, per-course data lives (rankings, eligibility flags,
-- and eventual match outcome).
CREATE TABLE {prefix}applications (
	application_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	student_ufid varchar(8) NOT NULL,
	applied_course_id bigint(20) unsigned NOT NULL,     -- links applicant to course
	has_taken_course tinyint(1) NOT NULL DEFAULT 0,
	has_ta_for_course tinyint(1) NOT NULL DEFAULT 0,    -- student has previously TA'd this course (vs. has_ta_experience: any course)
	student_statement varchar(500) DEFAULT NULL,
	student_preference_rank int DEFAULT NULL,           -- how the student ranks this course among their applications
	professor_preference_rank int DEFAULT NULL,         -- how the professor ranks this applicant among theirs
	match_status tinyint unsigned NOT NULL DEFAULT 1,   -- 1 = pending, 2 = matched, 3 = rejected, 4 = withdrawn (bind to PHP enum)
	assigned_hours int NOT NULL DEFAULT 0,              -- TA hours assigned once match_status is matched
	PRIMARY KEY  (application_id),
	UNIQUE KEY student_course (student_ufid,applied_course_id),
	KEY applied_course_id (applied_course_id),
	KEY match_status (match_status)
);

-- Match recommendations: the matching algorithm's latest proposals, one row per
-- student per course. Each run replaces every row, so only the latest run is kept.
-- Rows are inserted in priority order, so recommendation_id orders a course's matches.
CREATE TABLE {prefix}match_recommendations (
	recommendation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	course_id bigint(20) unsigned NOT NULL,
	student_ufid varchar(8) NOT NULL,
	recommended_hours int NOT NULL,                     -- weekly hours the student would give this course
	reason varchar(40) NOT NULL,                        -- tier that made the match, e.g. professor_preference_1, applied
	PRIMARY KEY  (recommendation_id),
	UNIQUE KEY course_student (course_id,student_ufid),
	KEY student_ufid (student_ufid)
);

-- Application cycle: one term's run through the stages. Only one cycle is ever
-- current, so this table holds at most one row, with cycle_id = 1. All dates are UTC.
-- The active stage is not stored: it is worked out from these dates on each request.
CREATE TABLE {prefix}application_cycle (
	cycle_id tinyint unsigned NOT NULL,
	cycle_semester tinyint unsigned NOT NULL,           -- same values as courses.course_semester
	cycle_year year NOT NULL,
	student_application_start_at datetime DEFAULT NULL, -- the stage starts once start_at comes, NULL = never starts
	student_application_end_at datetime DEFAULT NULL,   -- the stage ends once end_at comes, NULL = no set end
	hr_review_start_at datetime DEFAULT NULL,
	hr_review_end_at datetime DEFAULT NULL,
	professor_ranking_start_at datetime DEFAULT NULL,
	professor_ranking_end_at datetime DEFAULT NULL,
	admin_matching_start_at datetime DEFAULT NULL,
	admin_matching_end_at datetime DEFAULT NULL,
	PRIMARY KEY  (cycle_id)
);
