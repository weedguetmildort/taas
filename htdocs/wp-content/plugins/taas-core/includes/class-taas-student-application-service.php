<?php
/**
 * Atomic persistence for initial submissions and existing student applications.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Initial creation and explicit existing-term edits have separate entry points.
 */
final class TAAS_Student_Application_Service {

	/**
	 * Save a validated initial submission, or recognize an identical retry.
	 *
	 * @param string $ufid  Verified authenticated owner.
	 * @param array  $input Normalized application input.
	 * @return array Submission ID and whether this was an identical retry.
	 * @throws DomainException If the term changed, closed, or was already applied for.
	 */
	public static function submit( string $ufid, array $input ): array {
		return TAAS_Transaction::run(
			static function () use ( $ufid, $input ) {
				$students     = new TAAS_Student_Repository();
				$submissions  = new TAAS_Student_Submission_Repository();
				$applications = new TAAS_Application_Repository();
				$details      = $input['details'];
				$semester     = TAAS_Semester::from( $input['term']['semester'] );
				$year         = $input['term']['year'];
				// This write locks the student until commit/rollback, including concurrent retries.
				$students->save_identity( new TAAS_Student( $ufid, $details['student_name'], $details['student_email'] ) );
				$period = TAAS_Application_Period::current();
				if ( is_wp_error( $period ) || $period['semester'] !== $semester->value || $period['year'] !== $year ) {
					throw new DomainException( 'The application term has changed or is unavailable. Reload the application information before submitting.', 409 );
				}
				if ( ! $period['is_open'] ) {
					throw new DomainException( 'Applications are closed. Your form has not been saved.', 403 );
				}
				$existing = $submissions->find_by_student_and_term( $ufid, $semester, $year );
				$choices  = $applications->find_by_student_and_term( $ufid, $semester, $year );
				if ( null !== $existing || $choices ) {
					$same_choices = array_map(
						static fn( array $row ) => array(
							'course_id'            => $row['applied_course_id'],
							'rank'                 => (int) $row['student_preference_rank'],
							'has_completed_course' => (bool) $row['has_taken_course'],
						),
						$choices
					);
					$all_pending = count( array_filter( $choices, static fn( array $row ) => (int) $row['match_status'] === TAAS_Match_Status::Pending->value ) ) === count( $choices );
					if ( null !== $existing && $existing->submitted_details == $details && $all_pending && $same_choices === $input['course_preferences'] ) {
						return array( 'submission_id' => $existing->submission_id, 'already_saved' => true );
					}
					// TODO: Agree the already-applied recovery submission policy with the team.
					// Preserve the existing application rather than guessing replacement semantics.
					throw new DomainException( 'An application already exists for this term. View your application status to make changes.', 409 );
				}
				$courses = new TAAS_Course_Repository();
				foreach ( $input['course_preferences'] as $preference ) {
					$course = $courses->find( $preference['course_id'] );
					if ( null === $course || $course->course_semester !== $semester || $course->course_year !== $year ) {
						throw new DomainException( 'A selected course is not available in this application term.', 400 );
					}
				}
				self::save_details( $ufid, $details );
				foreach ( $input['course_preferences'] as $preference ) {
					$applications->insert( new TAAS_Application( null, $ufid, $preference['course_id'], has_taken_course: true, student_preference_rank: $preference['rank'] ) );
				}
				$id = $submissions->save_for_term( $ufid, $semester, $year, $details );
				return array( 'submission_id' => $id, 'already_saved' => false );
			}
		);
	}

	/**
	 * Optimistic concurrency token for the complete saved term, not revision history.
	 *
	 * @param TAAS_Student_Submission $snapshot Saved details.
	 * @param array                   $choices Locked or freshly read course rows.
	 * @return string
	 */
	public static function revision( TAAS_Student_Submission $snapshot, array $choices ): string {
		return hash( 'sha256', json_encode( array( $snapshot->submission_id, $snapshot->submitted_details, $choices ), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	/**
	 * Whether online edits are supported for this term under the agreed rules.
	 *
	 * @param TAAS_Semester $semester Saved semester.
	 * @param int           $year Saved year.
	 * @param array         $choices Current rows.
	 * @return bool
	 */
	public static function can_edit( TAAS_Semester $semester, int $year, array $choices ): bool {
		$period = TAAS_Application_Period::current();
		if ( is_wp_error( $period ) || ! $period['is_open'] || $period['semester'] !== $semester->value || $period['year'] !== $year ) {
			return false;
		}
		// Matching/rejection follow closure; also guard finalized results defensively.
		foreach ( $choices as $choice ) {
			if ( ! in_array( (int) $choice['match_status'], array( TAAS_Match_Status::Pending->value, TAAS_Match_Status::Withdrawn->value ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Replace one existing term's details and active course preferences atomically.
	 *
	 * @param string $ufid Verified owner.
	 * @param array  $input Validated details/term/preferences; zero choices withdraws all.
	 * @param string $revision Token from the saved term read.
	 * @return array Saved ID and fresh concurrency token.
	 * @throws DomainException If the application changed, closed or is not editable.
	 */
	public static function update( string $ufid, array $input, string $revision ): array {
		return TAAS_Transaction::run(
			static function () use ( $ufid, $input, $revision ) {
				$details      = $input['details'];
				$semester     = TAAS_Semester::from( $input['term']['semester'] );
				$year         = $input['term']['year'];
				$students     = new TAAS_Student_Repository();
				$submissions  = new TAAS_Student_Submission_Repository();
				$applications = new TAAS_Application_Repository();
				// Serialize with initial submission and other edits by this owner.
				$students->save_identity( new TAAS_Student( $ufid, $details['student_name'], $details['student_email'] ) );
				$snapshot = $submissions->find_by_student_and_term( $ufid, $semester, $year );
				if ( null === $snapshot ) {
					throw new DomainException( 'No application was found for this term.', 404 );
				}
				$choices = $applications->find_by_student_and_term( $ufid, $semester, $year, true );
				if ( ! self::can_edit( $semester, $year, $choices ) ) {
					throw new DomainException( 'This application cannot be edited online at this time. Please check the application period or contact the administrator.', 403 );
				}
				if ( ! hash_equals( self::revision( $snapshot, $choices ), $revision ) ) {
					throw new DomainException( 'Your application has changed since it was loaded. Review the latest saved application before submitting again.', 409 );
				}
				$by_course = array();
				foreach ( $choices as $choice ) {
					$by_course[ $choice['applied_course_id'] ] = $choice;
				}
				$courses  = new TAAS_Course_Repository();
				$selected = array();
				foreach ( $input['course_preferences'] as $preference ) {
					$id     = $preference['course_id'];
					$course = $courses->find( $id );
					if ( null === $course || $course->course_semester !== $semester || $course->course_year !== $year ) {
						throw new DomainException( 'A selected course is not available in this application term.', 400 );
					}
					$previous = $by_course[ $id ] ?? null;
					// TODO: Decide whether professor ranks are cleared or retained on reactivation.
					// Do not guess: reactivation with a prior professor rank needs review.
					if ( null !== $previous && (int) $previous['match_status'] === TAAS_Match_Status::Withdrawn->value && null !== $previous['professor_preference_rank'] ) {
						throw new DomainException( 'This withdrawn course requires administrator review before it can be added again.', 409 );
					}
					$selected[ $id ] = $preference['rank'];
				}
				foreach ( $choices as $choice ) {
					$id = $choice['applied_course_id'];
					if ( array_key_exists( $id, $selected ) ) {
						$applications->save_student_choice( $ufid, (int) $choice['application_id'], $selected[ $id ], TAAS_Match_Status::Pending );
					} elseif ( (int) $choice['match_status'] === TAAS_Match_Status::Pending->value ) {
						$applications->save_student_choice( $ufid, (int) $choice['application_id'], null, TAAS_Match_Status::Withdrawn );
					}
				}
				foreach ( $selected as $id => $rank ) {
					if ( ! isset( $by_course[ $id ] ) ) {
						$applications->insert( new TAAS_Application( null, $ufid, (string) $id, has_taken_course: true, student_preference_rank: $rank ) );
					}
				}
				self::save_details( $ufid, $details );
				$id = $submissions->save_for_term( $ufid, $semester, $year, $details );
				$fresh = $submissions->find_by_student_and_term( $ufid, $semester, $year );
				return array( 'submission_id' => $id, 'revision' => self::revision( $fresh, $applications->find_by_student_and_term( $ufid, $semester, $year ) ) );
			}
		);
	}

	/** Save validated shared profile/background details inside the caller's transaction. */
	private static function save_details( string $ufid, array $details ): void {
		$profiles = new TAAS_Student_Profile_Repository();
		$previous = $profiles->find( $ufid );
		$p        = $details['profile'];
		$english  = $p['english_test'];
		$toefl_score = 'TOEFL' === ( $english['type'] ?? null ) ? $english['score'] : null;
		// An unrelated form edit must not erase metadata for the same saved test.
		$toefl_date = null !== $toefl_score && $previous?->toefl_ibt_score === $toefl_score
			? $previous?->toefl_ibt_date : null;
		$profile  = new TAAS_Student_Profile(
			student_ufid: $ufid,
			phone: $p['phone'],
			admitted_semester: $p['admitted_semester'],
			available_hours: $p['available_hours'],
			// No approved rule selects one degree's GPA/major as the current profile's.
			major: $previous?->major,
			gpa: $previous?->gpa,
			enrollment_status: TAAS_Enrollment_Status::from( $p['enrollment_status'] ),
			resume_url: $p['resume_url'],
			toefl_ibt_score: $toefl_score,
			toefl_ibt_date: $toefl_date,
			has_ta_experience: $previous?->has_ta_experience ?? false,
			eap5836_status: TAAS_Course_Taken_Status::from( $p['eap5836_status'] ),
			eap5837_status: TAAS_Course_Taken_Status::from( $p['eap5837_status'] ),
			country_code: $p['country_code'],
			additional_info: $p['additional_info'],
		);
		if ( null === $previous ) {
			$profiles->insert( $profile );
		} else {
			$profiles->update( $ufid, $profile );
		}
		$degrees = array_map(
			static fn( array $degree ) => new TAAS_Academic_Background(
				background_id: null,
				student_ufid: $ufid,
				university: $degree['university'],
				degree_type: TAAS_Degree_Type::from( $degree['degree_type'] ),
				grad_date: new DateTimeImmutable( $degree['grad_date'] ),
				major: $degree['major'],
				minor: '' === $degree['minor'] ? null : $degree['minor'],
				gpa: $degree['gpa'],
			),
			$details['academic_background']
		);
		( new TAAS_Academic_Background_Repository() )->replace_for_student( $ufid, $degrees );
	}

}
