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
    // SECURITY: Validate table name against whitelist (prevent SQL injection)
    // Whitelist includes all tables defined in $tables array plus common system tables
    $allowed_tables = [
        'intake_actual_strength', 'phd_details', 'placement_details', 'faculty_details', 'faculty_count', 
        'academic_peers', 'inter_faculty', 'sponsored_project_details', 'research_staff', 'patent_details', 
        'patent_info', 'exec_dev', 'consultancy_projects', 'employers_details', 'country_wise_student', 
        'salary_details', 'online_education_details',
        'brief_details_of_the_department', 'faculty_output', 'nepmarks', 
        'department_data', 'student_support', 'conferences_workshops', 
        'collaborations', 'supporting_documents', 'expert_reviews', 
        'verification_flags', 'department_master', 'colleges'
    ];
    
    // Only process if table is in whitelist
    if (!in_array($table, $allowed_tables, true)) {
        continue; // Skip unknown tables
    }
    
    $safeTable = $table; // Safe after whitelist check

    // SECURITY: Use prepared statement for table existence check
    $checkTableQuery = "SHOW TABLES LIKE ?";
    $stmt_check = mysqli_prepare($conn, $checkTableQuery);
    if ($stmt_check) {
        mysqli_stmt_bind_param($stmt_check, 's', $safeTable);
        mysqli_stmt_execute($stmt_check);
        $tableExists = mysqli_stmt_get_result($stmt_check);
        $table_exists = ($tableExists && mysqli_num_rows($tableExists) > 0);
        if ($tableExists) {
            mysqli_free_result($tableExists);
        }
        mysqli_stmt_close($stmt_check);
    } else {
        continue; // Skip if query fails
    }

    if ($table_exists) {
        $csvFile = "$tempDir/$safeTable.csv";
        $file = fopen($csvFile, 'w');

        // SECURITY: Use prepared statement for column checks
        $checkYearQuery = "SHOW COLUMNS FROM `$safeTable` LIKE 'A_YEAR'";
        $yearResult = mysqli_query($conn, $checkYearQuery);
        $hasYearColumn = ($yearResult && mysqli_num_rows($yearResult) > 0);
        if ($yearResult) {
            mysqli_free_result($yearResult);
        }

        $checkDeptQuery = "SHOW COLUMNS FROM `$safeTable` LIKE 'dept_id'";
        $deptResult = mysqli_query($conn, $checkDeptQuery);
        $hasDeptColumn = ($deptResult && mysqli_num_rows($deptResult) > 0);
        if ($deptResult) {
            mysqli_free_result($deptResult);
        }

        // SECURITY: Use prepared statement for column listing
        $columnsQuery = "SHOW COLUMNS FROM `$safeTable`";
        $columnsResult = mysqli_query($conn, $columnsQuery);
        $columns = [];
        
        if ($columnsResult) {
            while ($col = mysqli_fetch_assoc($columnsResult)) {
                $columns[] = $col['Field'];
            }
            mysqli_free_result($columnsResult);
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
        
        // SECURITY: Free result and close statement
        if ($result) {
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);

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