<?php
// admin/download.php - Download Handler
require('session.php');

if (!class_exists('ZipArchive')) {
    die('Error: The ZipArchive class is not enabled. Please enable the zip extension in php.ini.');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

$selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';

// Database connection
// $database = [
//     'host' => 'database-ranking-mu.ctqcaks44o4u.ap-south-1.rds.amazonaws.com',
//     'user' => 'admin',
//     'pass' => 'sO77NWrPV0f0Yi8AuhG5',
//     'name' => 'u257276344_Nirf_Test' // Replace with your actual database name
// ];

// Additional admin-specific functionality
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Database Connection
$database = [
    'host' => $_ENV['DB_HOST'],
    'user' => $_ENV['DB_USER'],
    'pass' => $_ENV['DB_PASS'],
    'name' => $_ENV['DB_NAME']
];

$conn = new mysqli($database['host'], $database['user'], $database['pass'], $database['name']);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Define tables
$tables = [
    'intake_actual_strength', 'phd_details', 'placement_details', 'faculty_details', 'faculty_count', 
    'academic_peers', 'inter_faculty', 'sponsored_project_details', 'research_staff', 'patent_details', 
    'patent_info', 'exec_dev', 'consultancy_projects', 'employers_details', 'country_wise_student', 
    'salary_details', 'online_education_details'
];

$tempDir = 'temp_csv';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// Initialize ZIP
$zipFile = 'Database.zip';
$zip = new ZipArchive();

if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die("Error: Cannot create ZIP file.");
}

foreach ($tables as $table) {
    $safeTable = mysqli_real_escape_string($conn, $table);

    // Check if table exists
    $checkTableQuery = "SHOW TABLES LIKE '$safeTable'";
    $tableExists = mysqli_query($conn, $checkTableQuery);

    if (mysqli_num_rows($tableExists) > 0) {
        $csvFile = "$tempDir/$safeTable.csv";
        $file = fopen($csvFile, 'w');

        // Check if 'A_YEAR' column exists
        $checkYearColumn = mysqli_query($conn, "SHOW COLUMNS FROM $safeTable LIKE 'A_YEAR'");
        $hasYearColumn = (mysqli_num_rows($checkYearColumn) > 0);

        // Check if 'dept_id' column exists
        $checkDeptColumn = mysqli_query($conn, "SHOW COLUMNS FROM $safeTable LIKE 'dept_id'");
        $hasDeptColumn = (mysqli_num_rows($checkDeptColumn) > 0);

        // Build query
        $columnsQuery = "SHOW COLUMNS FROM $safeTable";
        $columnsResult = mysqli_query($conn, $columnsQuery);
        $columns = [];
        
        while ($col = mysqli_fetch_assoc($columnsResult)) {
            $columns[] = $col['Field'];
        }

        if ($hasDeptColumn) {
            $query = "SELECT t.*, d.dept_name FROM $safeTable t 
                      LEFT JOIN department_master d ON t.dept_id = d.dept_id";
            if ($hasYearColumn && $selectedYear !== 'all') {
                $query .= " WHERE t.A_YEAR = ?";
            }
        } else {
            $query = "SELECT * FROM $safeTable";
            if ($hasYearColumn && $selectedYear !== 'all') {
                $query .= " WHERE A_YEAR = ?";
            }
        }

        $stmt = $conn->prepare($query);
        if ($hasYearColumn && $selectedYear !== 'all') {
            $stmt->bind_param('s', $selectedYear);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && mysqli_num_rows($result) > 0) {
            $tableData = mysqli_fetch_all($result, MYSQLI_ASSOC);

            // Ensure DEPT_NAME is placed next to DEPT_ID
            if ($hasDeptColumn) {
                array_splice($columns, array_search('dept_id', $columns) + 1, 0, 'dept_name');
            }

            fputcsv($file, $columns); // Write column headers
            foreach ($tableData as $row) {
                $orderedRow = [];
                foreach ($columns as $col) {
                    $orderedRow[] = $row[$col] ?? ''; // Preserve order
                }
                fputcsv($file, $orderedRow);
            }
        } else {
            fputcsv($file, ['No data for selected year']);
        }

        fclose($file);
        $zip->addFile($csvFile, "$safeTable.csv");
    }
}

// Close ZIP properly
$zip->close();

// Send ZIP for download
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="Database.zip"');
header('Content-Length: ' . filesize($zipFile));
readfile($zipFile);

// Cleanup
array_map('unlink', glob("$tempDir/*"));
rmdir($tempDir);
unlink($zipFile);
exit;
?>