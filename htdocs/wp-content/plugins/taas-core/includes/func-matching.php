<?php
/**
 * Propose TA matches for courses using greedy, tiered passes
 *
 * Proposals only: Nothing is written to the database. An admin reviews and
 * finalizes them separately, which is when match_status changes and offers go out.
 *
 * Assumptions:
 * - Every application passed in has been approved by HR
 * - Every PhD student has applied to every course, so tier 6 is the catch-all for
 *   unassigned PhD students and no separate pass over all PhD students is needed
 * - Courses are processed in the order given, so earlier courses get first pick in each pass
 * - Matches can be partial: a student's hours can be split across several courses, and a
 *   course can be filled by several students. Each match takes the smaller of the student's
 *   hours left and the course's hours left, in strict priority order
 * - Admin has final say in the matches and can edit, change, and finalize matches as needed
 *
 * Notes: Arrays keyed by UFID are documented as array<string, ...>, but PHP
 * stores an all-digit key like '12345678' as an int. Read the ID from the value
 * (e.g. student_ufid) when it is needed as a string.
 *
 * SPECIAL NOTE: MASTERS/UNDERGRAD STUDENTS ARE NOT CONSIDERED IN THIS VERSION
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the total weekly TA hours for an enrollment and lab hours
 *
 * 10 hours of TA support per 35 students with no lab, per 30 with a 1-hour lab,
 * or per 25 with a 2-hour lab, rounded to the nearest multiple of 5.
 *
 * @param int $enrollment Current enrollment
 * @param int $lab_hours  Lab hours per week
 * @return int|null Null if enrollment is under 10 or lab_hours is not 0, 1, or 2.
 */
function taas_calc_weekly_hours( int $enrollment, int $lab_hours ): ?int {
	// Students covered by each 10 hours of TA support
	$students_per_10_hours = match ( $lab_hours ) {
		0       => 35,
		1       => 30,
		2       => 25,
		default => null,
	};

	if ( $enrollment < 10 || null === $students_per_10_hours ) {
		return null;
	}

	// Calculation of TA hours for the course
	$hours = ( $enrollment * 10 ) / $students_per_10_hours;

	// Round hours to nearest multiple of 5
	return (int) ( round( $hours / 5 ) * 5 );
}

/**
 * Return the total weekly TA hours a course needs
 *
 * @param TAAS_Course $course Course to staff
 * @return int hours
 * @throws InvalidArgumentException If enrollment is under 10 or lab_hours is not 0, 1, or 2.
 */
function taas_calc_course_ta_hours( TAAS_Course $course ): int {
	if ( $course->enrollment < 10 ) {
		throw new InvalidArgumentException( sprintf( 'Course %d has enrollment under 10.', (int) $course->course_id ) );
	}

	$hours = taas_calc_weekly_hours( $course->enrollment, $course->lab_hours );

	if ( null === $hours ) {
		throw new InvalidArgumentException( sprintf( 'Course %d has unsupported lab_hours: %d.', (int) $course->course_id, (int) $course->lab_hours ) );
	}

	return $hours;
}

/**
 * Match students to a course from a list of candidates, in order
 *
 * Matches can be partial. Each student gets the smaller of their hours left and the
 * course's hours left. A student with hours left over stays available to other
 * courses, and a course that is only partly filled keeps looking.
 *
 * @param TAAS_Course                         $course     Course to staff
 * @param string[]                            $ufids      Candidate student UFIDs, in priority order
 * @param string                              $reason     Reason recorded on each match
 * @param array<string, TAAS_Student_Profile> $profiles   Profiles keyed by student_ufid
 * @param array<string, int>                  $hours_left Student UFID => hours not yet assigned, updated in place.
 *                                                        A student missing from it has their full available_hours.
 * @param int                                 $cth        Course hours left to fill, updated in place
 * @return array<string, array{student_ufid: string, hours: int, reason: string}> Matches keyed by student UFID
 */
function taas_match_candidates( TAAS_Course $course, array $ufids, string $reason, array $profiles, array &$hours_left, int &$cth ): array {
	$matches = array();

	foreach ( $ufids as $student_ufid ) {
		// Stop once there are no course hours left to assign
		if ( $cth <= 0 ) {
			break;
		}

		// Hours the student can still give, from their profile if they have not been matched yet
		$available = $hours_left[ $student_ufid ] ?? $profiles[ $student_ufid ]->available_hours ?? null;

		// Skip students with no stated availability or no hours left
		if ( null === $available || $available <= 0 ) {
			continue;
		}

		// Give the course as many of the student's hours as it still needs
		$hours = min( $available, $cth );

		$matches[ $student_ufid ] = array(
			'student_ufid' => $student_ufid,
			'hours'        => $hours,
			'reason'       => $reason,
		);

		$hours_left[ $student_ufid ] = $available - $hours;
		$cth                        -= $hours;
	}

	return $matches;
}

/**
 * Propose matches for one course from its applicants
 *
 * Tiers, in priority order:
 * 1. Professor-ranked applicants who also ranked the course, by professor rank
 * 2. Applicants who ranked the course, by student rank
 * 3. Applicants who have previously TA'd the course
 * 4. Applicants who have taken the course
 * 5. Applicants with previous TA experience
 * 6. Any remaining applicant
 *
 * Matches are keyed by student UFID, in the order they were made. Within this
 * course, a student is matched at most once, under the first tier that reaches
 * them while they still have hours. The same student may also be matched to
 * other courses with their leftover hours.
 *
 * @param TAAS_Course                         $course      Course to staff
 * @param TAAS_Application[]                  $course_apps Applications for this course
 * @param array<string, TAAS_Student_Profile> $profiles    Profiles keyed by student_ufid
 * @param array<string, int>                  $hours_left  Student UFID => hours not yet assigned, updated in place
 * @param int                                 $cth         Course hours left to fill, updated in place
 * @return array<string, array{student_ufid: string, hours: int, reason: string}> Matches keyed by student UFID
 */
function taas_match_course( TAAS_Course $course, array $course_apps, array $profiles, array &$hours_left, int &$cth ): array {
	$matches = array();

	// Go through each tier's applicants in order, adding each eligible student as a match
	// If rank_field is given, that rank is added to the end of the reason (e.g. professor_preference_1)
	$run_tier = function ( array $apps, string $reason, ?string $rank_field = null ) use ( &$cth, &$matches, &$hours_left, $profiles, $course ): void {
		// Skip building the tier once the course is full
		if ( $cth <= 0 ) {
			return;
		}

		foreach ( $apps as $app ) {
			// Already matched to this course in an earlier tier
			if ( isset( $matches[ $app->student_ufid ] ) ) {
				continue;
			}

			if ( null === $rank_field ) {
				$app_reason = $reason; // tiers 3–6: no rank given, use the reason as is
			} else {
				$app_reason = $reason . '_' . $app->$rank_field; // tiers 1–2: add the rank to the end
			}

			$matches += taas_match_candidates( $course, array( $app->student_ufid ), $app_reason, $profiles, $hours_left, $cth );
		}
	};

	// Tier 1: professor's ranked picks who also want the course
	$prof_prefs = array_filter(
		$course_apps,
		fn( TAAS_Application $app ) => null !== $app->professor_preference_rank && null !== $app->student_preference_rank // Both professor and student stated their preference in the app
	);
	// Sort by ranking (the professor's first preference is considered first, then second preference, etc.), breaking ties by UFID
	usort(
		$prof_prefs,
		fn( $a, $b ) => array( $a->professor_preference_rank, $a->student_ufid ) <=> array( $b->professor_preference_rank, $b->student_ufid )
	);
	$run_tier( $prof_prefs, 'professor_preference', 'professor_preference_rank' );

	// Tier 2: students who ranked the course
	$student_prefs = array_filter(
		$course_apps,
		fn( TAAS_Application $app ) => null !== $app->student_preference_rank // Instructor did not have a preference toward these students
	);
	// Sort by ranking (students who prefer this course first, then students who prefer this course second, etc.), breaking ties by UFID
	usort(
		$student_prefs,
		fn( $a, $b ) => array( $a->student_preference_rank, $a->student_ufid ) <=> array( $b->student_preference_rank, $b->student_ufid )
	);
	$run_tier( $student_prefs, 'student_preference', 'student_preference_rank' );

	// Tier 3: students who have previously TA'd this course
	$ta_for_course = array_filter(
		$course_apps,
		fn( TAAS_Application $app ) => $app->has_ta_for_course
	);
	$run_tier( $ta_for_course, 'ta_for_course' );

	// Tier 4: students who have taken the course
	$taken_course = array_filter(
		$course_apps,
		fn( TAAS_Application $app ) => $app->has_taken_course
	);
	$run_tier( $taken_course, 'taken_course' );

	// Tier 5: previous TAs of any course (from the student's profile)
	$prev_tas = array_filter(
		$course_apps,
		fn( TAAS_Application $app ) => $profiles[ $app->student_ufid ]->has_ta_experience ?? false
	);
	$run_tier( $prev_tas, 'ta_experience' );

	// Tier 6: anyone else who applied
	$run_tier( $course_apps, 'applied' );

	return $matches;
}

/**
 * Propose matches for every course
 *
 * @param TAAS_Course[]          $courses  Courses to staff, which is ordered by admin's choice
 * @param TAAS_Application[]     $apps     Applications across all courses
 * @param TAAS_Student_Profile[] $profiles Profiles of the applicants
 * @return array{matches: array<int, array>, unfilled_hours: array<int, int>, students_with_hours_left: array<string, int>}
 *         matches and unfilled_hours are keyed by course_id; students_with_hours_left maps the UFID of each
 *         PhD student with hours not given to any course to those hours, in profile order
 * @throws InvalidArgumentException If any course's TA hours cannot be calculated.
 */
function taas_run_matching( array $courses, array $apps, array $profiles ): array {
	$profiles_by_ufid = array();

	// Get student profiles accessed by student's UFID
	foreach ( $profiles as $profile ) {
		$profiles_by_ufid[ $profile->student_ufid ] = $profile;
	}

	$phd_apps_by_course = array();

	// Get course applications accessed by course's ID, split by whether the applicant is a PhD student
	foreach ( $apps as $app ) {
		$is_phd = TAAS_Enrollment_Status::Phd === ( $profiles_by_ufid[ $app->student_ufid ]->enrollment_status ?? null );

		if ( $is_phd ) {
			$phd_apps_by_course[ $app->applied_course_id ][] = $app;
		}
	}

	$hours_left         = array(); // Student UFID => hours not yet assigned, shared across courses
	$all_matches        = array();
	$all_unfilled_hours = array();

	// Hours left to fill per course, carried across every pass
	foreach ( $courses as $course ) {
		$all_matches[ $course->course_id ]        = array();
		$all_unfilled_hours[ $course->course_id ] = taas_calc_course_ta_hours( $course );
	}

	// PhD students, to the courses they applied to
	foreach ( $courses as $course ) {
		$all_matches[ $course->course_id ] += taas_match_course(
			$course,
			$phd_apps_by_course[ $course->course_id ] ?? array(), // If a course has no apps, use an empty array
			$profiles_by_ufid,
			$hours_left,
			$all_unfilled_hours[ $course->course_id ]
		);
	}

	// PhD students with hours not given to any course, including students never matched
	$students_with_hours_left = array();

	foreach ( $profiles as $profile ) {
		$left = $hours_left[ $profile->student_ufid ] ?? $profile->available_hours;

		if ( null !== $left && $left > 0 ) {
			$students_with_hours_left[ $profile->student_ufid ] = $left;
		}
	}

	return array(
		'matches'                  => $all_matches,
		'unfilled_hours'           => $all_unfilled_hours,
		'students_with_hours_left' => $students_with_hours_left,
	);
}
