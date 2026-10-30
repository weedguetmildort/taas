<?php
/**
 * Propose TA matches for courses using greedy, tiered passes
 *
 * Proposals only: Nothing is written to the database. An admin reviews and
 * finalizes them separately, which is when match_status changes and offers go out.
 *
 * PhD students are prioritized over masters and undergrad students:
 * 1. PhD applicants are matched to the courses they applied to (tiers 1-5)
 * 2. Any PhD student still unassigned is matched to any course, applied or not
 * 3. Masters and undergrad applicants are matched to the courses they applied to (tiers 1-5)
 *
 * Assumptions:
 * - Every application passed in has been approved by HR
 * - Masters and undergrad students are only considered for courses they applied to
 * - Students with no college_status are treated as masters/undergrad
 * - Courses are processed in the order given, so earlier courses get first pick in each pass
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
 * 10 hours of TA support per 35 students with no lab, per 30 with a 1-hour lab,
 * or per 25 with a 2-hour lab, rounded to the nearest multiple of 5.
 *
 * @param TAAS_Course $course Course to staff
 * @return int hours
 * @throws InvalidArgumentException If enrollment is negative or lab_hours is not 0, 1, or 2.
 */
function taas_calc_course_ta_hours( TAAS_Course $course ): int {
	// TODO: save the result to course->weekly_hours in the db

	if ( $course->enrollment < 10 ) {
		throw new InvalidArgumentException( sprintf( 'Course %s has negative enrollment.', esc_html( $course->course_id ) ) );
	}

	// Students covered by each 10 hours of TA support
	$students_per_10_hours = match ( $course->lab_hours ) {
		0       => 35,
		1       => 30,
		2       => 25,
		default => throw new InvalidArgumentException( sprintf( 'Course %s has unsupported lab_hours: %d.', esc_html( $course->course_id ), (int) $course->lab_hours ) ),
	};

    // Calculation of TA hours for the course
	$hours = ( $course->enrollment * 10 ) / $students_per_10_hours;

    // Round hours to nearest multiple of 5
	return (int) ( round( $hours / 5 ) * 5 );
}

/**
 * Match students to a course from a list of candidates, in order
 *
 * @param TAAS_Course                         $course   Course to staff
 * @param string[]                            $ufids    Candidate student UFIDs, in priority order
 * @param string                              $reason   Reason recorded on each match
 * @param array<string, TAAS_Student_Profile> $profiles Profiles keyed by student_ufid
 * @param array<string, string>               $assigned Student UFID => course_id, updated in place
 * @param int                                 $cth      Course hours left to fill, updated in place
 * @return array<string, array{student_ufid: string, hours: int, reason: string}> Matches keyed by student UFID
 */
function taas_match_candidates( TAAS_Course $course, array $ufids, string $reason, array $profiles, array &$assigned, int &$cth ): array {
	$matches = array();

	foreach ( $ufids as $student_ufid ) {
		// Stop once there are no course hours left to assign
		if ( $cth < 10 ) {
			break;
		}

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

	return $matches;
}

/**
 * Propose matches for one course from its applicants
 *
 * Tiers, in priority order:
 * 1. Professor-ranked applicants who also ranked the course, by professor rank
 * 2. Applicants who ranked the course, by student rank
 * 3. Applicants who have taken the course
 * 4. Applicants with previous TA experience
 * 5. Any remaining applicant
 *
 * Matches are keyed by student UFID, in the order they were made.
 *
 * @param TAAS_Course                         $course      Course to staff
 * @param TAAS_Application[]                  $course_apps Applications for this course
 * @param array<string, TAAS_Student_Profile> $profiles    Profiles keyed by student_ufid
 * @param array<string, string>               $assigned    Student UFID => course_id, updated in place
 * @param int                                 $cth         Course hours left to fill, updated in place
 * @return array<string, array{student_ufid: string, hours: int, reason: string}> Matches keyed by student UFID
 */
function taas_match_course( TAAS_Course $course, array $course_apps, array $profiles, array &$assigned, int &$cth ): array {
	$matches = array();

	// Go through each tier's applicants in order, adding each eligible student as a match
	// If rank_field is given, that rank is added to the end of the reason (e.g. professor_preference_1)
	$run_tier = function ( array $apps, string $reason, ?string $rank_field = null ) use ( &$cth, &$matches, &$assigned, $profiles, $course ): void {
		// Skip building the tier once the course is full
		if ( $cth < 10 ) {
			return;
		}

		foreach ( $apps as $app ) {
			if ( null === $rank_field ) {
				$app_reason = $reason; // tiers 3–5: no rank given, use the reason as is
			} else {
				$app_reason = $reason . '_' . $app->$rank_field; // tiers 1–2: add the rank to the end
			}

			$matches   += taas_match_candidates( $course, array( $app->student_ufid ), $app_reason, $profiles, $assigned, $cth );
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
		fn( $a, $b ) => [ $a->professor_preference_rank, $a->student_ufid ] <=> [ $b->professor_preference_rank, $b->student_ufid ]
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
		fn( $a, $b ) => [ $a->student_preference_rank, $a->student_ufid ] <=> [ $b->student_preference_rank, $b->student_ufid ]
	);
	$run_tier( $student_prefs, 'student_preference', 'student_preference_rank' );

	// Tier 3: students who have taken the course
	$taken_course = array_filter(
		$course_apps,
		fn( TAAS_Application $app ) => $app->has_taken_course
	);
	$run_tier( $taken_course, 'taken_course' );

	// Tier 4: previous TAs (of any course, the schema does not track which one)
	$prev_tas = array_filter(
		$course_apps,
		fn( TAAS_Application $app ) => $profiles[ $app->student_ufid ]->has_ta_experience ?? false
	);
	$run_tier( $prev_tas, 'ta_experience' );

	// Tier 5: anyone else who applied
	$run_tier( $course_apps, 'applied' );

	return $matches;
}

/**
 * Propose matches for every course
 *
 * @param TAAS_Course[]          $courses  Courses to staff, which is ordered by admin's choice
 * @param TAAS_Application[]     $apps     Applications across all courses
 * @param TAAS_Student_Profile[] $profiles Profiles of the applicants and of every PhD student
 * @return array{matches: array<string, array>, unfilled_hours: array<string, int>} Both keyed by course_id
 * @throws InvalidArgumentException If any course's TA hours cannot be calculated.
 */
function taas_run_matching( array $courses, array $apps, array $profiles ): array {
	$profiles_by_ufid = array();

	// Get student profiles accessed by student's UFID
	foreach ( $profiles as $profile ) {
		$profiles_by_ufid[ $profile->student_ufid ] = $profile;
	}

	// Collect PhD students, with previous TAs first, for matching outside their applications
	$phd_profiles = array_filter(
		$profiles,
		fn( TAAS_Student_Profile $profile ) => TAAS_College_Status::Phd === $profile->college_status
	);
	usort( $phd_profiles, fn( $a, $b ) => $b->has_ta_experience <=> $a->has_ta_experience );
	$phd_ufids = array_map( fn( TAAS_Student_Profile $profile ) => $profile->student_ufid, $phd_profiles );

    $phd_apps_by_course   = array();
	// $other_apps_by_course = array();

	// Get course applications accessed by course's ID, split by whether the applicant is a PhD student
	foreach ( $apps as $app ) {
		$is_phd = TAAS_College_Status::Phd === ( $profiles_by_ufid[ $app->student_ufid ]->college_status ?? null );

		if ( $is_phd ) {
			$phd_apps_by_course[ $app->applied_course_id ][] = $app;
		} 
        /*
        else {
			$other_apps_by_course[ $app->applied_course_id ][] = $app;
		}
        */
	}

	$assigned           = array();
	$all_matches        = array();
	$all_unfilled_hours = array();

	// Hours left to fill per course, carried across every pass
	foreach ( $courses as $course ) {
		$all_matches[ $course->course_id ]        = array();
		$all_unfilled_hours[ $course->course_id ] = taas_calc_course_ta_hours( $course );
	}

	// Pass 1: PhD applicants, to the courses they applied to
	foreach ( $courses as $course ) {
		$all_matches[ $course->course_id ] += taas_match_course(
			$course,
			$phd_apps_by_course[ $course->course_id ] ?? array(), // If a course has no apps, use an empty array
			$profiles_by_ufid,
			$assigned,
			$all_unfilled_hours[ $course->course_id ]
		);
	}

	// Pass 2: any PhD student still unassigned, to any course
	foreach ( $courses as $course ) {
		$all_matches[ $course->course_id ] += taas_match_candidates(
			$course,
			$phd_ufids,
			'phd_pool',
			$profiles_by_ufid,
			$assigned,
			$all_unfilled_hours[ $course->course_id ]
		);
	}

    /*
    // Pass 3: masters and undergrad applicants, to the courses they applied to
    foreach ( $courses as $course ) {
        $all_matches[ $course->course_id ] += taas_match_course(
            $course,
            $other_apps_by_course[ $course->course_id ] ?? array(),
            $profiles_by_ufid,
            $assigned,
            $all_unfilled_hours[ $course->course_id ]
        );
    }
    */

	return array(
		'matches'        => $all_matches,
		'unfilled_hours' => $all_unfilled_hours,
	);
}
