<?php

use PhpOffice\PhpSpreadsheet\IOFactory;

// Load Composer dependencies
require __DIR__ . '/../vendor/autoload.php';

// Load .env variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$dbhost = $_ENV['DB_HOST'];
$dbport = $_ENV['DB_PORT'];
$dbname = $_ENV['DB_NAME'];
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASSWORD'];

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

            // Row 1 contains column headers
            $lastRow = $worksheet->getHighestDataRow();

            for ($rowNumber = 2; $rowNumber <= $lastRow; $rowNumber++) {
                // Read columns A through H
                $row = $worksheet
                    ->rangeToArray(
                        "A{$rowNumber}:H{$rowNumber}",
                        null,
                        true,
                        false
                    )[0];

                // Skip empty rows
                if (count(array_filter(
                    $row,
                    fn($value) => $value !== null && trim((string)$value) !== ''
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
    // Connect to the database
    $pdo = new PDO(
        "mysql:host=$dbhost;port=$dbport;dbname=$dbname",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

    echo "Connected to database!\n";

    // Get CSV or XLSX file path
    $filePath = trim(readline(
        "Enter the path to your CSV or XLSX file: "
    ));

    if (!is_file($filePath) || !is_readable($filePath)) {
        throw new RuntimeException(
            "File does not exist or cannot be read."
        );
    }

    // Find an existing professor
    $findProfessorStmt = $pdo->prepare(
        "SELECT professor_ufid
        FROM professors
        WHERE professor_name = :professor_name
            AND professor_email = :professor_email"
    );

    // Insert a new professor
    $insertProfessorStmt = $pdo->prepare(
        "INSERT INTO professors
        (professor_ufid, professor_name, professor_email)
        VALUES
        (:professor_ufid, :professor_name, :professor_email)"
    );

    // Insert a new course
    $insertCourseStmt = $pdo->prepare(
        "INSERT INTO courses
        (id, course_code, course_name, sections,
            lab_hours, enrollment, max_enrollment, professor_ufid)
        VALUES
        (:id, :course_code, :course_name, :sections,
            :lab_hours, :enrollment, :max_enrollment, :professor_ufid)"
    );

    // Start a transaction
    $pdo->beginTransaction();

    // Find the next available IDs
    $nextProfessorId = (int) $pdo->query(
        "SELECT COALESCE(MAX(professor_ufid), 0) + 1 FROM professors"
    )->fetchColumn();

    $nextCourseId = (int) $pdo->query(
        "SELECT COALESCE(MAX(id), 0) + 1 FROM courses"
    )->fetchColumn();

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
        $findProfessorStmt->execute([
            ':professor_name' => $professorName,
            ':professor_email' => $professorEmail
        ]);

        $professorId = $findProfessorStmt->fetchColumn();

        // If professor doesn't exist, create one
        if ($professorId === false) {
            $professorId = $nextProfessorId;

            $insertProfessorStmt->execute([
                ':professor_ufid' => $professorId,
                ':professor_name' => $professorName,
                ':professor_email' => $professorEmail
            ]);

            $nextProfessorId++;
            $professorCount++;
        }

        // Insert course with the correct professor ID
        $insertCourseStmt->execute([
            ':id' => $nextCourseId,
            ':course_code' => $courseCode,
            ':course_name' => $courseName,
            ':sections' => $sections,
            ':lab_hours' => $labHours,
            ':enrollment' => $enrollment,
            ':max_enrollment' => $maxEnrollment,
            ':professor_ufid' => $professorId
        ]);

        $nextCourseId++;
        $courseCount++;
    }

    // Save all inserts
    $pdo->commit();

    echo "Import completed successfully!\n";
    echo "New professors added: $professorCount\n";
    echo "Courses added: $courseCount\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
