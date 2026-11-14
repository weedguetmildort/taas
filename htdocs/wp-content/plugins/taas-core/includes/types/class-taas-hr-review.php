<?php
/**
 * Saved HR decision for a submitted application.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/** The latest HR decision and the version used for concurrency checks. */
final class TAAS_HR_Review {

	/**
	 * Create a saved decision.
	 *
	 * @param int         $submission_id Linked application.
	 * @param string      $decision      Approve or reject.
	 * @param int         $version       Saved decision version.
	 * @param int|null    $reviewed_by   WordPress user ID when available.
	 * @param string|null $reviewed_at   UTC timestamp when available.
	 * @param string|null $review_comment Private HR comment.
	 */
	public function __construct(
		public readonly int $submission_id,
		public readonly string $decision,
		public readonly int $version,
		public readonly ?int $reviewed_by,
		public readonly ?string $reviewed_at,
		public readonly ?string $review_comment = null,
	) {}

	/**
	 * Hydrate a saved review.
	 *
	 * @param array|object $row Database row.
	 * @return self
	 */
	public static function from_row( array|object $row ): self {
		$data = TAAS_Cast::row( $row );
		return new self(
			(int) $data['submission_id'],
			(string) $data['decision'],
			(int) $data['version'],
			TAAS_Cast::int_or_null( $data['reviewed_by'] ),
			TAAS_Cast::string_or_null( $data['reviewed_at'] ),
			TAAS_Cast::string_or_null( $data['review_comment'] ?? null )
		);
	}

	/**
	 * Serialize a review for storage.
	 *
	 * @return array{submission_id: int, decision: string, version: int, reviewed_by: ?int, reviewed_at: ?string, review_comment: ?string}
	 */
	public function to_row(): array {
		return array(
			'submission_id'  => $this->submission_id,
			'decision'       => $this->decision,
			'version'        => $this->version,
			'reviewed_by'    => $this->reviewed_by,
			'reviewed_at'    => $this->reviewed_at,
			'review_comment' => $this->review_comment,
		);
	}
}
