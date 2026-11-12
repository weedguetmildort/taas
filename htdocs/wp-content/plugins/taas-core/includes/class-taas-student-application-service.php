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
	 * Student identity repository.
	 *
	 * @var TAAS_Student_Repository
	 */
	private readonly TAAS_Student_Repository $students;
	/**
	 * Term snapshot repository.
	 *
	 * @var TAAS_Student_Submission_Repository
	 */
	private readonly TAAS_Student_Submission_Repository $submissions;
	/**
	 * Course application repository.
	 *
	 * @var TAAS_Application_Repository
	 */
	private readonly TAAS_Application_Repository $applications;
	/**
	 * Course offering repository.
	 *
	 * @var TAAS_Course_Repository
	 */
	private readonly TAAS_Course_Repository $courses;
	/**
	 * Shared student profile repository.
	 *
	 * @var TAAS_Student_Profile_Repository
	 */
	private readonly TAAS_Student_Profile_Repository $profiles;
	/**
	 * Current degree repository.
	 *
	 * @var TAAS_Academic_Background_Repository
	 */
	private readonly TAAS_Academic_Background_Repository $backgrounds;

	/**
	 * Save a validated initial submission, or recognize an identical retry.
	 *
	 * The input['details'] value becomes the term's submitted_details snapshot unchanged.
	 *
	 * @see TAAS_Student_Submission::__construct() Stored details field schema.
	 *
	 * @param string $ufid  Verified authenticated owner.
	 * @param array  $input Normalized application input; see the detailed shape below.
	 * @phpstan-param array{
	 *     term: array{semester: int, year: int},
	 *     details: array{
	 *         student_name: string, student_email: string,
	 *         profile: array{
	 *             phone: string, country_code: string, admitted_semester: string,
	 *             available_hours: int, enrollment_status: int,
	 *             eap5836_status: int, eap5837_status: int,
	 *             resume_url: ?string, additional_info: string, advisor_name: string,
	 *             fws_eligibility: string, fws_award_letter_url?: string|null,
	 *             english_test_score: int
	 *         },
	 *         academic_background: list<array{
	 *             university: string, major: string, minor: string,
	 *             degree_type: int, grad_date: string, gpa: float|null
	 *         }>
	 *     },
	 *     course_preferences: list<array{course_id: string, rank: int, course_completion: int, has_ta_for_course: bool}>
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
	 * @param array  $input Normalized application input; see the detailed shape below.
	 * @param string $revision Token from the saved term read.
	 * @param bool   $replacement Whether to repopulate unranked courses for a PhD replacement.
	 * @phpstan-param array{
	 *     term: array{semester: int, year: int},
	 *     details: array{
	 *         student_name: string, student_email: string,
	 *         profile: array{
	 *             phone: string, country_code: string, admitted_semester: string,
	 *             available_hours: int, enrollment_status: int,
	 *             eap5836_status: int, eap5837_status: int,
	 *             resume_url: ?string, additional_info: string, advisor_name: string,
	 *             fws_eligibility: string, fws_award_letter_url?: string|null,
	 *             english_test_score: int
	 *         },
	 *         academic_background: list<array{
	 *             university: string, major: string, minor: string,
	 *             degree_type: int, grad_date: string, gpa: float|null
	 *         }>
	 *     },
	 *     course_preferences: list<array{course_id: string, rank: int, course_completion: int, has_ta_for_course: bool}>
	 * } $input Validated application.
	 * @return array{submission_id: int, revision: string}
	 * @throws DomainException If the application changed, closed or is not editable.
	 */
	public static function update( string $ufid, array $input, string $revision, bool $replacement = false ): array {
		return ( new self() )->update_application( $ufid, $input, $revision, $replacement );
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
			if ( TAAS_Match_Status::Pending->value !== (int) $choice['match_status'] ) {
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Revisions require strict JSON errors and stable numeric types.
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

	/**
	 * Validate and save a new application as one transaction.
	 *
	 * @param string $ufid Verified authenticated owner.
	 * @param array  $input Normalized application input.
	 */
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
				$preferences = array_column( $input['course_preferences'], null, 'course_id' );
				if ( self::is_phd( $details ) ) {
					$preferences = $this->include_unranked_courses( $preferences, $semester, $year );
				}
				if ( null !== $existing_submission || $existing_choices ) {
					if ( null !== $existing_submission && self::is_identical_retry( $existing_submission, $existing_choices, $details, $preferences ) ) {
						return array(
							'submission_id' => $existing_submission->submission_id,
							'already_saved' => true,
						);
					}
					throw new DomainException( 'An application already exists for this term. View your application status to make changes.', 409 );
				}
				$this->validate_preferences( $input['course_preferences'], $semester, $year );

				$this->save_details( $ufid, $details );
				$this->save_choices( $ufid, array(), $preferences );
				$submission_id = $this->submissions->save_for_term( $ufid, $semester, $year, $details );
				return array(
					'submission_id' => $submission_id,
					'already_saved' => false,
				);
			}
		);
	}

	/**
	 * Check the saved version before applying all edits in one transaction.
	 *
	 * @param string $ufid Verified authenticated owner.
	 * @param array  $input Normalized application input.
	 * @param string $revision Expected saved revision token.
	 * @param bool   $replacement Whether to repopulate unranked courses for a PhD replacement.
	 */
	private function update_application( string $ufid, array $input, string $revision, bool $replacement ): array {
		return TAAS_Transaction::run(
			function () use ( $ufid, $input, $revision, $replacement ) {
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
				$this->validate_preferences( $input['course_preferences'], $semester, $year );
				$selected_preferences = array_column( $input['course_preferences'], null, 'course_id' );
				$was_phd = self::is_phd( $existing_submission->submitted_details );
				$is_phd = self::is_phd( $details );
				if ( $is_phd && ( $replacement || ! $was_phd ) ) {
					$selected_preferences = $this->include_unranked_courses( $selected_preferences, $semester, $year );
				}
				$this->save_choices( $ufid, $previous_choices_by_course, $selected_preferences, $is_phd );
				$this->save_details( $ufid, $details );
				$submission_id = $this->submissions->save_for_term( $ufid, $semester, $year, $details );

				$saved_snapshot = $this->submissions->find_by_student_and_term( $ufid, $semester, $year );
				$saved_choices = $this->applications->find_by_student_and_term( $ufid, $semester, $year );
				return array(
					'submission_id' => $submission_id,
					'revision'      => self::revision( $saved_snapshot, $saved_choices ),
				);
			}
		);
	}

	/**
	 * Recognize a retry only when pending preferences and submitted details agree.
	 *
	 * @param TAAS_Student_Submission $saved_submission Saved term snapshot.
	 * @param array                   $saved_choices Saved course application rows.
	 * @param array                   $details Normalized submitted details.
	 * @param array                   $expected_choices Expected choices keyed by course ID.
	 */
	private static function is_identical_retry( TAAS_Student_Submission $saved_submission, array $saved_choices, array $details, array $expected_choices ): bool {
		$saved_details = $saved_submission->submitted_details;
		$saved_details['profile']['advisor_name'] ??= 'N/A';
		if ( ! self::same_details( $saved_details, $details ) ) {
			return false;
		}
		foreach ( $saved_choices as $choice ) {
			if ( TAAS_Match_Status::Pending->value !== (int) $choice['match_status'] ) {
				return false;
			}
		}
		$preferences = array_map(
			static fn( array $row ) => array(
				'course_id'         => $row['applied_course_id'],
				'rank'              => TAAS_Cast::int_or_null( $row['student_preference_rank'] ),
				'course_completion' => (int) $row['has_taken_course'],
				'has_ta_for_course' => (bool) $row['has_ta_for_course'],
			),
			$saved_choices
		);
		$preferences = array_column( $preferences, null, 'course_id' );
		ksort( $preferences );
		ksort( $expected_choices );
		return $preferences === $expected_choices;
	}

	/**
	 * Check the program recorded in the term's submitted details.
	 *
	 * @param array $details Submitted details.
	 */
	private static function is_phd( array $details ): bool {
		return TAAS_Enrollment_Status::Phd->value === ( $details['profile']['enrollment_status'] ?? null );
	}

	/**
	 * Add the term's remaining courses with no rank or course-history claims.
	 *
	 * @param array         $preferences Student choices keyed by course ID.
	 * @param TAAS_Semester $semester Application semester.
	 * @param int           $year Application year.
	 */
	private function include_unranked_courses( array $preferences, TAAS_Semester $semester, int $year ): array {
		foreach ( $this->courses->application_choices( $semester, $year ) as $course ) {
			$course_id = $course['course_id'];
			$preferences[ $course_id ] ??= array(
				'course_id'         => $course_id,
				'rank'              => null,
				'course_completion' => 0,
				'has_ta_for_course' => false,
			);
		}
		return $preferences;
	}

	/**
	 * Compare JSON details without coercing text or depending on object key order.
	 *
	 * @param mixed $saved Previously saved value.
	 * @param mixed $submitted Submitted value.
	 */
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
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Both operands were explicitly verified as numeric; JSON may change int/float representation.
			return $saved == $submitted;
		}
		return $saved === $submitted;
	}

	/**
	 * Validate that selected courses belong to the submitted term.
	 *
	 * @param list<array{course_id: string, rank: int, course_completion: int, has_ta_for_course: bool}> $preferences Submitted course selections.
	 * @param TAAS_Semester                                                                              $semester Application semester.
	 * @param int                                                                                        $year Application year.
	 * @throws DomainException If a course is unavailable.
	 */
	private function validate_preferences( array $preferences, TAAS_Semester $semester, int $year ): void {
		foreach ( $preferences as $preference ) {
			$course_id = $preference['course_id'];
			// Shared lock until commit, so an admin cannot move the course to another term after this check.
			$course = $this->courses->find_locked( (int) $course_id, false );
			if ( null === $course || $course->course_semester !== $semester || $course->course_year !== $year ) {
				throw new DomainException( 'A selected course is not available in this application term.', 400 );
			}
		}
	}

	/**
	 * Apply validated selections inside the caller's student-locked transaction.
	 *
	 * @param string                                  $ufid Verified authenticated owner.
	 * @param array<string|int, array<string, mixed>> $previous_choices Course ID to database row.
	 * @param array<string|int, array>                $selected_preferences Validated selections keyed by course ID.
	 * @param bool                                    $keep_unranked Whether omitted courses stay on a PhD application.
	 */
	private function save_choices( string $ufid, array $previous_choices, array $selected_preferences, bool $keep_unranked = false ): void {
		foreach ( $previous_choices as $course_id => $choice ) {
			if ( array_key_exists( $course_id, $selected_preferences ) ) {
				$preference = $selected_preferences[ $course_id ];
				$this->applications->save_student_choice(
					$ufid,
					(int) $choice['application_id'],
					$preference['rank'],
					TAAS_Match_Status::Pending,
					has_ta_for_course: $preference['has_ta_for_course'],
					has_taken_course: $preference['course_completion']
				);
			} elseif ( ! $keep_unranked ) {
				$this->applications->delete( (int) $choice['application_id'] );
			} elseif ( null !== $choice['student_preference_rank'] ) {
				$this->applications->save_student_choice( $ufid, (int) $choice['application_id'], null, TAAS_Match_Status::Pending );
			}
		}
		foreach ( $selected_preferences as $course_id => $preference ) {
			if ( ! isset( $previous_choices[ $course_id ] ) ) {
				$this->applications->insert( new TAAS_Application( null, $ufid, (int) $course_id, has_taken_course: $preference['course_completion'], has_ta_for_course: $preference['has_ta_for_course'], student_preference_rank: $preference['rank'] ) );
			}
		}
	}

	/**
	 * Save validated shared profile/background details inside the caller's transaction.
	 *
	 * @param string $ufid Verified authenticated owner.
	 * @param array  $details Normalized submitted details.
	 */
	private function save_details( string $ufid, array $details ): void {
		$previous_profile = $this->profiles->find( $ufid );
		$profile_data = $details['profile'];
		$current_degree = $details['academic_background'][0];
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
			fws_eligibility: $profile_data['fws_eligibility'],
			fws_award_letter_url: $profile_data['fws_award_letter_url'],
			english_test_score: TAAS_English_Test_Score::from( $profile_data['english_test_score'] ),
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
