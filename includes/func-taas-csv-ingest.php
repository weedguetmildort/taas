<?php

use PhpOffice\PhpSpreadsheet\IOFactory;

// Load Composer dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap WordPress
require_once '/var/www/html/wp-load.php';

// Ensure the plugin has loaded TAAS_Schema
if (!class_exists('TAAS_Schema')) {
    throw new RuntimeException(
        'TAAS_Schema is not loaded. Check that the TAAS plugin is active.'
    );
}

// Use WordPress's existing database connection
global $wpdb;

// Get table names using the project's schema class
$professors = TAAS_Schema::table('professors');
$courses = TAAS_Schema::table('courses');

// Read CSV or XLSX rows
function readCourseRows(string $filePath): Generator
{
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    if ($extension === 'csv') {
        $file = fopen($filePath, 'r');

        if ($file === false) {
            throw new RuntimeException("Cannot open CSV file.");
        }

        try {
            // Skip header
            fgetcsv($file);

            while (($row = fgetcsv($file)) !== false) {
                if ($row === [null]) {
                    continue;
                }

                yield $row;
            }
        } finally {
            fclose($file);
        }

    } elseif ($extension === 'xlsx') {
        $spreadsheet = IOFactory::load($filePath);

        try {
            // Read the first worksheet
            $worksheet = $spreadsheet->getSheet(0);
            $lastRow = $worksheet->getHighestDataRow();

            for ($rowNumber = 2; $rowNumber <= $lastRow; $rowNumber++) {
                $row = $worksheet->rangeToArray(
                    "A{$rowNumber}:H{$rowNumber}",
                    null,
                    true,
                    false
                )[0];

                // Skip empty rows
                if (count(array_filter(
                    $row,
                    fn($value) =>
                        $value !== null &&
                        trim((string)$value) !== ''
                )) === 0) {
                    continue;
                }

                yield $row;
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

    } else {
        throw new InvalidArgumentException(
            "Unsupported file type. Please use .csv or .xlsx."
        );
    }
}

try {
    echo "Connected to database!\n";
    echo "Professors table: {$professors}\n";
    echo "Courses table: {$courses}\n";

    // Get CSV or XLSX file path
    $filePath = trim(readline(
        "Enter the path to your CSV or XLSX file: "
    ));

    if (!is_file($filePath) || !is_readable($filePath)) {
        throw new RuntimeException(
            "File does not exist or cannot be read."
        );
    }

    // Start a transaction
    $result = $wpdb->query("START TRANSACTION");

    if ($result === false) {
        throw new RuntimeException(
            "Could not start transaction: " . $wpdb->last_error
        );
    }

    // Find the next available IDs
    $nextProfessorId = (int) $wpdb->get_var(
        "SELECT COALESCE(MAX(professor_ufid), 0) + 1
        FROM {$professors}"
    );

    $nextCourseId = (int) $wpdb->get_var(
        "SELECT COALESCE(MAX(course_id), 0) + 1
        FROM {$courses}"
    );

    if ($wpdb->last_error !== '') {
        throw new RuntimeException(
            "Could not retrieve next IDs: " . $wpdb->last_error
        );
    }

    $courseCount = 0;
    $professorCount = 0;

    // Read each row from CSV or XLSX
    foreach (readCourseRows($filePath) as $row) {
        if (count($row) !== 8) {
            throw new RuntimeException(
                "Invalid row: expected 8 columns, got " . count($row)
            );
        }

        [
            $courseCode,
            $labHours,
            $courseName,
            $enrollment,
            $maxEnrollment,
            $sections,
            $professorName,
            $professorEmail
        ] = array_map(
            fn($value) => trim((string)($value ?? '')),
            $row
        );

        // Check if professor exists
        $professorId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT professor_ufid
                FROM {$professors}
                WHERE professor_name = %s
                    AND professor_email = %s",
                $professorName,
                $professorEmail
            )
        );

        if ($wpdb->last_error !== '') {
            throw new RuntimeException(
                "Error finding professor: " . $wpdb->last_error
            );
        }

        // If professor doesn't exist, create one
        if ($professorId === null) {
            $professorId = $nextProfessorId;

            $result = $wpdb->insert(
                $professors,
                [
                    'professor_ufid' => $professorId,
                    'professor_name' => $professorName,
                    'professor_email' => $professorEmail
                ],
                ['%s', '%s', '%s']
            );

            if ($result === false) {
                throw new RuntimeException(
                    "Error inserting professor: " . $wpdb->last_error
                );
            }

            $nextProfessorId++;
            $professorCount++;
        }

        // Insert course with the correct professor ID
        $result = $wpdb->insert(
            $courses,
            [
                'course_id' => $nextCourseId,
                'course_code' => $courseCode,
                'course_name' => $courseName,
                'sections' => $sections,
                'lab_hours' => $labHours,
                'enrollment' => $enrollment,
                'max_enrollment' => $maxEnrollment,
                'professor_ufid' => $professorId
            ],
            [
                '%s', // course_id
                '%s', // course_code
                '%s', // course_name
                '%d', // sections
                '%d', // lab_hours
                '%d', // enrollment
                '%d', // max_enrollment
                '%s'  // professor_ufid
            ]
        );

        if ($result === false) {
            throw new RuntimeException(
                "Error inserting course: " . $wpdb->last_error
            );
        }

        $nextCourseId++;
        $courseCount++;
    }

    // Save all inserts
    if ($wpdb->query("COMMIT") === false) {
        throw new RuntimeException(
            "Error committing transaction: " . $wpdb->last_error
        );
    }

    echo "Import completed successfully!\n";
    echo "New professors added: {$professorCount}\n";
    echo "Courses added: {$courseCount}\n";

} catch (Throwable $e) {
    // Roll back any uncommitted inserts
    $wpdb->query("ROLLBACK");

    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
