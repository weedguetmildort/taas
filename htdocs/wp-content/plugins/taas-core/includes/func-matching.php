<?php
/**
 * Propose TA matches for courses using greedy, tiered passes
 *
 * Proposals only: Each run replaces the match_recommendations table with its
 * proposals, and nothing else is written. An admin reviews and finalizes them
 * separately, which is when match_status changes and offers go out.
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
 * Try to match one student to a course
 *
 * Matches can be partial. The student gets the smaller of their hours left and the
 * course's hours left. A student with hours left over stays available to other
 * courses, and a course that is only partly filled keeps looking.
 *
 * @param string                              $student_ufid Candidate student UFID
 * @param string                              $reason       Reason recorded on the match
 * @param array<string, TAAS_Student_Profile> $profiles     Profiles keyed by student_ufid
 * @param array<string, int>                  $hours_left   Student UFID => hours not yet assigned, updated in place.
 *                                                          A student missing from it has their full available_hours.
 * @param int                                 $cth          Course hours left to fill, updated in place
 * @return array{student_ufid: string, hours: int, reason: string}|null The match, or null if the course is
 *                                                                      full or the student has no hours left
 */
function taas_match_candidate( string $student_ufid, string $reason, array $profiles, array &$hours_left, int &$cth ): ?array {
	// No course hours left to assign
	if ( $cth <= 0 ) {
		return null;
	}

	// Hours the student can still give, from their profile if they have not been matched yet
	$available = $hours_left[ $student_ufid ] ?? $profiles[ $student_ufid ]->available_hours ?? null;

	// Skip students with no stated availability or no hours left
	if ( null === $available || $available <= 0 ) {
		return null;
	}

	// Give the course as many of the student's hours as it still needs
	$hours = min( $available, $cth );

	$hours_left[ $student_ufid ] = $available - $hours;
	$cth                        -= $hours;

	return array(
		'student_ufid' => $student_ufid,
		'hours'        => $hours,
		'reason'       => $reason,
	);
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
	$run_tier = function ( array $apps, string $reason, ?string $rank_field = null ) use ( &$cth, &$matches, &$hours_left, $profiles ): void {
		foreach ( $apps as $app ) {
			// Stop once the course is full
			if ( $cth <= 0 ) {
				return;
			}

			// Already matched to this course in an earlier tier
			if ( isset( $matches[ $app->student_ufid ] ) ) {
				continue;
			}

			if ( null === $rank_field ) {
				$app_reason = $reason; // tiers 3–6: no rank given, use the reason as is
			} else {
				$app_reason = $reason . '_' . $app->$rank_field; // tiers 1–2: add the rank to the end
			}

			$match = taas_match_candidate( $app->student_ufid, $app_reason, $profiles, $hours_left, $cth );
			if ( null !== $match ) {
				$matches[ $app->student_ufid ] = $match;
			}
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
 * Saves the matches to match_recommendations, replacing whatever an earlier run left there.
 * Courses whose TA hours cannot be calculated (enrollment under 10, or lab_hours not 0, 1,
 * or 2) are skipped: they get no matches and are listed in skipped_courses instead.
 *
 * @param TAAS_Course[]          $courses  Courses to staff, which is ordered by admin's choice
 * @param TAAS_Application[]     $apps     Applications across all courses
 * @param TAAS_Student_Profile[] $profiles Profiles of the applicants
 * @return array{matches: array<int, array>, unfilled_hours: array<int, int>, students_with_hours_left: array<string, int>, skipped_courses: int[]}
 *         matches and unfilled_hours are keyed by course_id and leave out skipped courses; students_with_hours_left
 *         maps the UFID of each PhD student with hours not given to any course to those hours, in profile order;
 *         skipped_courses lists the course_id of skipped courses, in the order given
 * @throws RuntimeException If saving the recommendations fails.
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
	$skipped_courses    = array();

	// Hours left to fill per course, carried across every pass
	foreach ( $courses as $course ) {
		$hours = taas_calc_weekly_hours( $course->enrollment, $course->lab_hours );

		// Skip courses whose TA hours cannot be calculated
		if ( null === $hours ) {
			$skipped_courses[] = (int) $course->course_id;
			continue;
		}

		$all_matches[ $course->course_id ]        = array();
		$all_unfilled_hours[ $course->course_id ] = $hours;
	}

	// PhD students, to the courses they applied to
	foreach ( $courses as $course ) {
		// Skipped course
		if ( ! isset( $all_unfilled_hours[ $course->course_id ] ) ) {
			continue;
		}

		$all_matches[ $course->course_id ] += taas_match_course(
			$course,
			$phd_apps_by_course[ $course->course_id ] ?? array(), // If a course has no apps, use an empty array
			$profiles_by_ufid,
			$hours_left,
			$all_unfilled_hours[ $course->course_id ]
		);
	}

	// Save the matches, replacing the previous run's
	$recommendations = array();

	foreach ( $all_matches as $course_id => $matches ) {
		foreach ( $matches as $match ) {
			$recommendations[] = new TAAS_Match_Recommendation(
				recommendation_id: null,
				course_id: (int) $course_id,
				student_ufid: (string) $match['student_ufid'],
				recommended_hours: $match['hours'],
				reason: $match['reason'],
			);
		}
	}

	( new TAAS_Match_Recommendation_Repository() )->replace_all( $recommendations );

	// PhD students with hours not given to any course, including students never matched
	$students_with_hours_left = array();

	foreach ( $profiles as $profile ) {
		// Only PhD students are matched in this version
		if ( TAAS_Enrollment_Status::Phd !== $profile->enrollment_status ) {
			continue;
		}

		$left = $hours_left[ $profile->student_ufid ] ?? $profile->available_hours;

		if ( null !== $left && $left > 0 ) {
			$students_with_hours_left[ $profile->student_ufid ] = $left;
		}
	}

	return array(
		'matches'                  => $all_matches,
		'unfilled_hours'           => $all_unfilled_hours,
		'students_with_hours_left' => $students_with_hours_left,
		'skipped_courses'          => $skipped_courses,
	);
}
