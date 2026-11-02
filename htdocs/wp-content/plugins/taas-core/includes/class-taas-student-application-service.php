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

	private readonly TAAS_Student_Repository $students;
	private readonly TAAS_Student_Submission_Repository $submissions;
	private readonly TAAS_Application_Repository $applications;
	private readonly TAAS_Course_Repository $courses;
	private readonly TAAS_Student_Profile_Repository $profiles;
	private readonly TAAS_Academic_Background_Repository $backgrounds;

	/**
	 * Save a validated initial submission, or recognize an identical retry.
	 *
	 * input['details'] becomes the term's submitted_details snapshot unchanged.
	 * @see TAAS_Student_Submission::__construct() Stored details field schema.
	 *
	 * @param string $ufid  Verified authenticated owner.
	 * @param array{
	 *     term: array{semester: int, year: int},
	 *     details: array{
	 *         student_name: string, student_email: string,
	 *         profile: array{
	 *             phone: string, country_code: string, admitted_semester: string,
	 *             available_hours: int, enrollment_status: int,
	 *             eap5836_status: int, eap5837_status: int,
	 *             resume_url: ?string, additional_info: string,
	 *             english_test: array{type: string, score: int}|null
	 *         },
	 *         academic_background: list<array{
	 *             university: string, major: string, minor: string,
	 *             degree_type: int, grad_date: string, gpa: float|null
	 *         }>
	 *     },
	 *     course_preferences: list<array{course_id: string, rank: int, has_completed_course: bool}>
	 * } $input Normalized application input.
	 * @return array{submission_id: int, already_saved: bool}
	 * @throws DomainException If the term changed, closed, or was already applied for.
	 */
	public static function submit( string $ufid, array $input ): array {
		return ( new self() )->submit_application( $ufid, $input );
	}

	/**
	 * Replace one existing term's details and active course preferences atomically.
	 *
	 * @param string $ufid Verified owner.
	 * @param array{
	 *     term: array{semester: int, year: int},
	 *     details: array{
	 *         student_name: string, student_email: string,
	 *         profile: array{
	 *             phone: string, country_code: string, admitted_semester: string,
	 *             available_hours: int, enrollment_status: int,
	 *             eap5836_status: int, eap5837_status: int,
	 *             resume_url: ?string, additional_info: string,
	 *             english_test: array{type: string, score: int}|null
	 *         },
	 *         academic_background: list<array{
	 *             university: string, major: string, minor: string,
	 *             degree_type: int, grad_date: string, gpa: float|null
	 *         }>
	 *     },
	 *     course_preferences: list<array{course_id: string, rank: int, has_completed_course: bool}>
	 * } $input Validated application; zero choices withdraws all.
	 * @param string $revision Token from the saved term read.
	 * @return array{submission_id: int, revision: string}
	 * @throws DomainException If the application changed, closed or is not editable.
	 */
	public static function update( string $ufid, array $input, string $revision ): array {
		return ( new self() )->update_application( $ufid, $input, $revision );
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
	 * Optimistic concurrency token for the complete saved term, not revision history.
	 *
	 * @param TAAS_Student_Submission $snapshot Saved details.
	 * @param array                   $choices Locked or freshly read course rows.
	 * @return string
	 */
	public static function revision( TAAS_Student_Submission $snapshot, array $choices ): string {
		return hash( 'sha256', json_encode( array( $snapshot->submission_id, $snapshot->submitted_details, $choices ), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	/** Each save operation owns one set of repositories. */
	private function __construct() {
		$this->students = new TAAS_Student_Repository();
		$this->submissions = new TAAS_Student_Submission_Repository();
		$this->applications = new TAAS_Application_Repository();
		$this->courses = new TAAS_Course_Repository();
		$this->profiles = new TAAS_Student_Profile_Repository();
		$this->backgrounds = new TAAS_Academic_Background_Repository();
	}

	/** Validate and save a new application as one transaction. */
	private function submit_application( string $ufid, array $input ): array {
		return TAAS_Transaction::run(
			function () use ( $ufid, $input ) {
				$details      = $input['details'];
				$semester     = TAAS_Semester::from( $input['term']['semester'] );
				$year         = $input['term']['year'];
				// This write locks the student until commit/rollback, including concurrent retries.
				$this->students->save_identity( new TAAS_Student( $ufid, $details['student_name'], $details['student_email'] ) );
				$period = TAAS_Application_Period::current();
				if ( is_wp_error( $period ) || $period['semester'] !== $semester->value || $period['year'] !== $year ) {
					throw new DomainException( 'The application term has changed or is unavailable. Reload the application information before submitting.', 409 );
				}
				if ( ! $period['is_open'] ) {
					throw new DomainException( 'Applications are closed. Your form has not been saved.', 403 );
				}
				$existing_submission = $this->submissions->find_by_student_and_term( $ufid, $semester, $year );
				$existing_choices  = $this->applications->find_by_student_and_term( $ufid, $semester, $year );
				if ( null !== $existing_submission || $existing_choices ) {
					if ( null !== $existing_submission && self::is_identical_retry( $existing_submission, $existing_choices, $input ) ) {
						return array( 'submission_id' => $existing_submission->submission_id, 'already_saved' => true );
					}
					// TODO: Agree the already-applied recovery submission policy with the team.
					// Preserve the existing application rather than guessing replacement semantics.
					throw new DomainException( 'An application already exists for this term. View your application status to make changes.', 409 );
				}
				$this->validate_preferences( $input['course_preferences'], $semester, $year );

				$this->save_details( $ufid, $details );
				foreach ( $input['course_preferences'] as $preference ) {
					$this->applications->insert( new TAAS_Application( null, $ufid, $preference['course_id'], has_taken_course: true, student_preference_rank: $preference['rank'] ) );
				}
				$submission_id = $this->submissions->save_for_term( $ufid, $semester, $year, $details );
				return array( 'submission_id' => $submission_id, 'already_saved' => false );
			}
		);
	}

	/** Check the saved version before applying all edits in one transaction. */
	private function update_application( string $ufid, array $input, string $revision ): array {
		return TAAS_Transaction::run(
			function () use ( $ufid, $input, $revision ) {
				$details      = $input['details'];
				$semester     = TAAS_Semester::from( $input['term']['semester'] );
				$year         = $input['term']['year'];

				// Serialize with initial submission and other edits by this owner.
				$this->students->save_identity( new TAAS_Student( $ufid, $details['student_name'], $details['student_email'] ) );
				$existing_submission = $this->submissions->find_by_student_and_term( $ufid, $semester, $year );
				if ( null === $existing_submission ) {
					throw new DomainException( 'No application was found for this term.', 404 );
				}
				$existing_choices = $this->applications->find_by_student_and_term( $ufid, $semester, $year, true );
				if ( ! self::can_edit( $semester, $year, $existing_choices ) ) {
					throw new DomainException( 'This application cannot be edited online at this time. Please check the application period or contact the administrator.', 403 );
				}
				if ( ! hash_equals( self::revision( $existing_submission, $existing_choices ), $revision ) ) {
					throw new DomainException( 'Your application has changed since it was loaded. Review the latest saved application before submitting again.', 409 );
				}
				$previous_choices_by_course = array_column( $existing_choices, null, 'applied_course_id' );
				$selected_ranks = $this->validate_preferences( $input['course_preferences'], $semester, $year, $previous_choices_by_course );

				$this->save_choices( $ufid, $previous_choices_by_course, $selected_ranks );
				$this->save_details( $ufid, $details );
				$submission_id = $this->submissions->save_for_term( $ufid, $semester, $year, $details );

				$saved_snapshot = $this->submissions->find_by_student_and_term( $ufid, $semester, $year );
				$saved_choices = $this->applications->find_by_student_and_term( $ufid, $semester, $year );
				return array( 'submission_id' => $submission_id, 'revision' => self::revision( $saved_snapshot, $saved_choices ) );
			}
		);
	}

	/** Recognize a retry only when pending preferences and submitted details agree. */
	private static function is_identical_retry( TAAS_Student_Submission $saved_submission, array $saved_choices, array $input ): bool {
		if ( ! self::same_details( $saved_submission->submitted_details, $input['details'] ) ) {
			return false;
		}
		foreach ( $saved_choices as $choice ) {
			if ( (int) $choice['match_status'] !== TAAS_Match_Status::Pending->value ) {
				return false;
			}
		}
		$preferences = array_map(
			static fn( array $row ) => array(
				'course_id'            => $row['applied_course_id'],
				'rank'                 => (int) $row['student_preference_rank'],
				'has_completed_course' => (bool) $row['has_taken_course'],
			),
			$saved_choices
		);
		return $preferences === $input['course_preferences'];
	}

	/** Compare JSON details without coercing text or depending on object key order. */
	private static function same_details( mixed $saved, mixed $submitted ): bool {
		if ( is_array( $saved ) && is_array( $submitted ) ) {
			if ( count( $saved ) !== count( $submitted ) || array_is_list( $saved ) !== array_is_list( $submitted ) ) {
				return false;
			}
			foreach ( $saved as $key => $value ) {
				if ( ! array_key_exists( $key, $submitted ) || ! self::same_details( $value, $submitted[ $key ] ) ) {
					return false;
				}
			}
			return true;
		}
		// JSON/database round trips may represent the same number as int or float.
		if ( ( is_int( $saved ) || is_float( $saved ) ) && ( is_int( $submitted ) || is_float( $submitted ) ) ) {
			return $saved == $submitted;
		}
		return $saved === $submitted;
	}

	/**
	 * Validate course availability/reactivation and return course IDs mapped to ranks.
	 * @param list<array{course_id: string, rank: int, has_completed_course: bool}> $preferences
	 * @param array<string|int, array<string, mixed>> $previous_choices Course ID to database row.
	 * @return array<string|int, int> Course ID to submitted rank.
	 */
	private function validate_preferences( array $preferences, TAAS_Semester $semester, int $year, array $previous_choices = array() ): array {

		$selected_ranks = array();
		foreach ( $preferences as $preference ) {
			$course_id = $preference['course_id'];
			$course = $this->courses->find( $course_id );
			if ( null === $course || $course->course_semester !== $semester || $course->course_year !== $year ) {
				throw new DomainException( 'A selected course is not available in this application term.', 400 );
			}
			$previous = $previous_choices[ $course_id ] ?? null;
			// TODO: Decide whether professor ranks are cleared or retained on reactivation.
			// Do not guess: reactivation with a prior professor rank needs review.
			if ( null !== $previous && (int) $previous['match_status'] === TAAS_Match_Status::Withdrawn->value && null !== $previous['professor_preference_rank'] ) {
				throw new DomainException( 'This withdrawn course requires administrator review before it can be added again.', 409 );
			}
			$selected_ranks[ $course_id ] = $preference['rank'];
		}
		return $selected_ranks;
	}

	/**
	 * Apply validated selections inside the caller's student-locked transaction.
	 * @param array<string|int, array<string, mixed>> $previous_choices Course ID to database row.
	 * @param array<string|int, int> $selected_ranks Course ID to submitted rank.
	 */
	private function save_choices( string $ufid, array $previous_choices, array $selected_ranks ): void {
		foreach ( $previous_choices as $course_id => $choice ) {
			if ( array_key_exists( $course_id, $selected_ranks ) ) {
				$this->applications->save_student_choice( $ufid, (int) $choice['application_id'], $selected_ranks[ $course_id ], TAAS_Match_Status::Pending );
			} elseif ( (int) $choice['match_status'] === TAAS_Match_Status::Pending->value ) {
				$this->applications->save_student_choice( $ufid, (int) $choice['application_id'], null, TAAS_Match_Status::Withdrawn );
			}
		}
		foreach ( $selected_ranks as $course_id => $rank ) {
			if ( ! isset( $previous_choices[ $course_id ] ) ) {
				$this->applications->insert( new TAAS_Application( null, $ufid, (string) $course_id, has_taken_course: true, student_preference_rank: $rank ) );
			}
		}
	}

	/** Save validated shared profile/background details inside the caller's transaction. */
	private function save_details( string $ufid, array $details ): void {
		$previous_profile = $this->profiles->find( $ufid );
		$profile_data = $details['profile'];
		$current_degree = $details['academic_background'][0];
		$english_test = $profile_data['english_test'];
		$toefl_score = 'TOEFL' === ( $english_test['type'] ?? null ) ? $english_test['score'] : null;
		// An unrelated form edit must not erase metadata for the same saved test.
		$toefl_date = null !== $toefl_score && $previous_profile?->toefl_ibt_score === $toefl_score
			? $previous_profile?->toefl_ibt_date : null;
		$profile = new TAAS_Student_Profile(
			student_ufid: $ufid,
			phone: $profile_data['phone'],
			admitted_semester: $profile_data['admitted_semester'],
			available_hours: $profile_data['available_hours'],
			// The first entry represents the current or most recent degree.
			major: $current_degree['major'],
			gpa: $current_degree['gpa'],
			enrollment_status: TAAS_Enrollment_Status::from( $profile_data['enrollment_status'] ),
			resume_url: $profile_data['resume_url'],
			toefl_ibt_score: $toefl_score,
			toefl_ibt_date: $toefl_date,
			has_ta_experience: $previous_profile?->has_ta_experience ?? false,
			eap5836_status: TAAS_Course_Taken_Status::from( $profile_data['eap5836_status'] ),
			eap5837_status: TAAS_Course_Taken_Status::from( $profile_data['eap5837_status'] ),
			country_code: $profile_data['country_code'],
			additional_info: $profile_data['additional_info'],
		);
		if ( null === $previous_profile ) {
			$this->profiles->insert( $profile );
		} else {
			$this->profiles->update( $ufid, $profile );
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
		$this->backgrounds->replace_for_student( $ufid, $degrees );
	}
}
