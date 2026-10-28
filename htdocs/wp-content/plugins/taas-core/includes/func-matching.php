<?php
/**
 * Propose TA matches for courses using a greedy, tiered pass
 *
 * Proposals only: Nothing is written to the database. An admin reviews and
 * finalizes them separately, which is when match_status changes and offers go out.
 *
 * Assumptions:
 * - Every application passed in has been approved by HR
 * - Only students who applied to a course are considered for it
 * - Courses are processed in the order given, so earlier courses get first pick
 * - Admin has final say in the matches and can edit, change, and finalize matches as needed
 *
 * Notes: Arrays keyed by UFID or course_id are documented as array<string, ...>, but PHP
 * stores an all-digit key like '12345678' as an int. Read the ID from the value
 * (e.g. student_ufid) when it is needed as a string.
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the total weekly TA hours a course needs
 *
 * @param TAAS_Course $course Course to staff
 * @return int hours
 */
function taas_calc_course_ta_hours( TAAS_Course $course ): int {
	// TODO: compute from enrollment and lab_hours using the staffing formula. edit course->weekly_hours in db/object after
	// TODO: add checks, such as enrollment == 0 (unless we can assume courses will always have enrollment > 0, etc.)

	return $course->weekly_hours ?? 0;
}

/**
 * Propose matches for one course
 *
 * Tiers, in priority order:
 * 1. Professor-ranked applicants who also ranked the course, by professor rank
 * 2. Applicants who ranked the course, by student rank
 * 3. Applicants with previous TA experience
 * 4. Applicants who have taken the course
 * 5. Any remaining applicant
 *
 * Matches are keyed by student UFID, in the order they were made.
 *
 * @param TAAS_Course                         $course      Course to staff
 * @param TAAS_Application[]                  $course_apps Applications for this course
 * @param array<string, TAAS_Student_Profile> $profiles    Profiles keyed by student_ufid
 * @param array<string, string>               $assigned    Student UFID => course_id, updated in place
 * @return array{matches: array<string, array{student_ufid: string, hours: int, reason: string}>, unfilled_hours: int}
 */
function taas_match_course( TAAS_Course $course, array $course_apps, array $profiles, array &$assigned ): array {
	$cth = taas_calc_course_ta_hours( $course );
	$matches = array();

	// Walk a tier's applicants in order, adding each eligible student as a match
	$run_tier = function ( array $apps, string $reason ) use ( &$cth, &$matches, &$assigned, $profiles, $course ): void {
		foreach ( $apps as $app ) {
			// Stop once there are no course hours left to assign
			if ( $cth <= 0 ) {
				break;
			}

			$student_ufid = $app->student_ufid;

			// Ensure the student has not already been assigned/is available
			if ( isset( $assigned[ $student_ufid ] ) ) {
				continue;
			}

			$hours = $profiles[ $student_ufid ]->available_hours ?? null;

			// Skip students with no stated availability, or more hours than the course has left
			if ( null === $hours || $hours <= 0 || $hours > $cth ) {
				continue;
			}

			// If student is "available," add student to matches
			$matches[ $student_ufid ] = array(
				'student_ufid' => $student_ufid,
				'hours'        => $hours,
				'reason'       => $reason,
			);

			$assigned[ $student_ufid ] = $course->course_id;
			$cth -= $hours;
		}
	};

	// Tier 1: professor's ranked picks who also want the course
	$prof_prefs = array_filter(
		$course_apps,
		fn( TAAS_Application $app ) => null !== $app->professor_preference_rank && null !== $app->student_preference_rank // Both professor and student stated their preference in the app
	);
	// Sort by ranking (the professor's first preference is considered first, then second preference, etc.)
	usort( $prof_prefs, fn( $a, $b ) => $a->professor_preference_rank <=> $b->professor_preference_rank );
	$run_tier( $prof_prefs, 'professor_preference' );

	// Tier 2: students who ranked the course
	$student_prefs = array_filter(
		$course_apps,
		fn( TAAS_Application $app ) => null !== $app->student_preference_rank // Instructor did not have a preference toward these students
	);
	// Sort by ranking (students who prefer this course first, then students who prefer this course second, etc.)
	usort( $student_prefs, fn( $a, $b ) => $a->student_preference_rank <=> $b->student_preference_rank );
	$run_tier( $student_prefs, 'student_preference' );

	// Tier 3: previous TAs (of any course, the schema does not track which one)
	$prev_tas = array_filter(
		$course_apps,
		fn( TAAS_Application $app ) => $profiles[ $app->student_ufid ]->has_ta_experience ?? false
	);
	$run_tier( $prev_tas, 'ta_experience' );

	// Tier 4: students who have taken the course
	$taken_course = array_filter(
		$course_apps,
		fn( TAAS_Application $app ) => $app->has_taken_course
	);
	$run_tier( $taken_course, 'taken_course' );

	// Tier 5: anyone else who applied
	$run_tier( $course_apps, 'applied' );

	return array(
		'matches'        => $matches,
		'unfilled_hours' => $cth,
	);
}

/**
 * Propose matches for every course
 *
 * @param TAAS_Course[]          $courses  Courses to staff, which is ordered by admin's choice
 * @param TAAS_Application[]     $apps     Applications across all courses
 * @param TAAS_Student_Profile[] $profiles Profiles of the applicants
 * @return array{matches: array<string, array>, unfilled_hours: array<string, int>} Both keyed by course_id
 */
function taas_run_matching( array $courses, array $apps, array $profiles ): array {
	$profiles_by_ufid = array();

    // Get student profiles accessed by student's UFID
	foreach ( $profiles as $profile ) {
		$profiles_by_ufid[ $profile->student_ufid ] = $profile;
	}

	$apps_by_course = array();

    // Get course applications accessed by course's ID
	foreach ( $apps as $app ) {
		$apps_by_course[ $app->applied_course_id ][] = $app;
	}

	$assigned           = array();
	$all_matches        = array();
	$all_unfilled_hours = array();

	foreach ( $courses as $course ) {
		$result = taas_match_course(
			$course,
			$apps_by_course[ $course->course_id ] ?? array(), // If a course has no apps, use an empty array
			$profiles_by_ufid,
			$assigned
		);

		$all_matches[ $course->course_id ]        = $result['matches'];
		$all_unfilled_hours[ $course->course_id ] = $result['unfilled_hours'];
	}

	return array(
		'matches'        => $all_matches,
		'unfilled_hours' => $all_unfilled_hours,
	);
}
