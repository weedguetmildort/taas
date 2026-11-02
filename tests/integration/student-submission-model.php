<?php
/** Snapshot hydration/serialization checks; no database records are written. */
declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require '/var/www/html/wp-load.php';

function taas_snapshot_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

/** Invalid stored data must use the exception handled by the REST layer. */
function taas_snapshot_rejects( array|object $row ): UnexpectedValueException {
	try {
		TAAS_Student_Submission::from_row( $row );
	} catch ( UnexpectedValueException $error ) {
		return $error;
	}
	throw new RuntimeException( 'Malformed snapshot row was accepted.' );
}

$row = array(
	'submission_id' => '12', 'student_ufid' => '12345678',
	'semester' => '1', 'year' => '2026', 'submitted_details' => '{}',
);
foreach ( array( '{', '', '{"name":}', "{\"name\":\"\xFF\"}" ) as $json ) {
	$error = taas_snapshot_rejects( array_replace( $row, array( 'submitted_details' => $json ) ) );
	taas_snapshot_check( $error->getPrevious() instanceof JsonException, 'Malformed JSON must preserve its original exception.' );
}
echo "PASS: Invalid JSON uses UnexpectedValueException and retains the cause\n";

foreach ( array( '[]', '[{"name":"Student"}]', 'null', 'true', '42', '"Student"' ) as $json ) {
	taas_snapshot_rejects( array_replace( $row, array( 'submitted_details' => $json ) ) );
}
echo "PASS: Snapshot JSON must be an object\n";

foreach ( array( '0', '6', '-1', '255', null ) as $semester ) {
	$invalid = array_replace( $row, array( 'semester' => $semester ) );
	taas_snapshot_rejects( $invalid );
	taas_snapshot_rejects( (object) $invalid );
}
$missing_semester = $row;
unset( $missing_semester['semester'] );
taas_snapshot_rejects( $missing_semester );
echo "PASS: Unknown, null and missing semesters use UnexpectedValueException\n";

foreach ( TAAS_Semester::cases() as $semester ) {
	$valid = array_replace( $row, array( 'semester' => (string) $semester->value ) );
	foreach ( array( $valid, (object) $valid ) as $input ) {
		$snapshot = TAAS_Student_Submission::from_row( $input );
		taas_snapshot_check( $semester === $snapshot->semester && 12 === $snapshot->submission_id
			&& '12345678' === $snapshot->student_ufid && 2026 === $snapshot->year
			&& array() === $snapshot->submitted_details, 'Valid stored values must retain their normalized types.' );
	}
}
echo "PASS: All valid semesters hydrate from array and object rows\n";

$details = array(
	'student_name' => "Alex O'Brien — Université",
	'profile' => array( 'resume_url' => null, 'available_hours' => 10 ),
	'academic_background' => array(
		array( 'major' => 'Computer Science', 'gpa' => 3.0 ),
		array( 'major' => 'Mathematics', 'gpa' => null ),
	),
);
$snapshot = new TAAS_Student_Submission( null, '12345678', TAAS_Semester::Fall, 2026, $details );
$encoded = $snapshot->to_row();
taas_snapshot_check( ! array_key_exists( 'submission_id', $encoded ), 'Unsaved snapshots must omit their generated ID.' );
taas_snapshot_check( $details === TAAS_Student_Submission::from_row( $encoded )->submitted_details, 'Snapshot serialization must preserve text, number types, nulls and degree order.' );
echo "PASS: Snapshot values round-trip without changing degree order or numeric types\n";
