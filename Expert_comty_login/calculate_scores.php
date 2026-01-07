<?php
/**
 * Calculate Department Scores Script
 * Run this to populate department_scores table with auto-calculated marks
 * WARNING: This script should only be run by administrators
 */

// Uncomment the line below to enable this script
// die("Script is disabled. Uncomment the die() line in calculate_scores.php to enable.");

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 300); // 5 minutes

require_once(__DIR__ . '/../config.php');
require_once('expert_functions.php');

// Check if user is logged in (optional security)
// require('session.php');

echo "<!DOCTYPE html>
<html>
<head>
    <title>Calculate Department Scores</title>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'>
    <style>
        body { padding: 2rem; background: #f5f5f5; }
        .container { max-width: 1200px; background: white; padding: 2rem; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f8f9fa; padding: 1rem; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1><i class='fas fa-calculator'></i> Department Scores Calculation</h1>";
echo "<p class='text-muted'>Calculating auto-scores for all departments based on existing data...</p>";
echo "<hr>";

$start_time = microtime(true);
$academic_year = getAcademicYear();

echo "<div class='alert alert-info'><strong>Academic Year:</strong> $academic_year</div>";

// Get all departments with faculty_output data
$query = "SELECT DISTINCT DEPT_ID, A_YEAR FROM faculty_output WHERE A_YEAR = ?";
$stmt = $conn->prepare($query);
$departments = [];

if ($stmt) {
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
    $stmt->close();
}

$total_depts = count($departments);
echo "<p><strong>Departments to calculate:</strong> $total_depts</p>";

if ($total_depts == 0) {
    echo "<div class='alert alert-warning'>No departments found for academic year $academic_year</div>";
    echo "</div></body></html>";
    exit;
}

echo "<div class='progress mb-3'><div class='progress-bar' id='progressBar' style='width: 0%'>0%</div></div>";
echo "<div id='output'>";

$calculated = 0;
$errors = 0;

foreach ($departments as $index => $dept) {
    $dept_id = $dept['DEPT_ID'];
    $dept_year = $dept['A_YEAR'];
    
    try {
        // Calculate scores using the existing function
        $scores = getDepartmentScores($dept_id, $dept_year);
        
        // Get department name / code
        $name_query = "SELECT COALESCE(dn.collname, dm.DEPT_NAME) AS dept_name
                       FROM department_master dm
                       LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
                       WHERE dm.DEPT_ID = ?
                       LIMIT 1";
        $name_stmt = $conn->prepare($name_query);
        $dept_name = "Dept $dept_id";
        if ($name_stmt) {
            $name_stmt->bind_param("i", $dept_id);
            $name_stmt->execute();
            $name_result = $name_stmt->get_result();
            if ($name_row = $name_result->fetch_assoc()) {
                $dept_name = $name_row['dept_name'] ?? $dept_name;
            }
            $name_stmt->close();
        }
        
        echo "<div class='alert alert-success'>✓ <strong>$dept_name (ID: $dept_id):</strong> Total = " . number_format($scores['total'], 2) . " marks</div>";
        $calculated++;
        
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>✗ <strong>Dept $dept_id:</strong> Error - " . $e->getMessage() . "</div>";
        $errors++;
    }
    
    // Update progress
    $progress = round((($index + 1) / $total_depts) * 100);
    echo "<script>
        document.getElementById('progressBar').style.width = '$progress%';
        document.getElementById('progressBar').textContent = '$progress%';
    </script>";
    
    flush();
    ob_flush();
}

echo "</div>"; // End output div

$end_time = microtime(true);
$execution_time = round($end_time - $start_time, 2);

echo "<hr>";
echo "<h3>Summary</h3>";
echo "<ul>";
echo "<li class='success'><strong>Successfully calculated:</strong> $calculated departments</li>";
echo "<li class='error'><strong>Errors:</strong> $errors departments</li>";
echo "<li class='info'><strong>Execution time:</strong> $execution_time seconds</li>";
echo "</ul>";

echo "<div class='alert alert-info mt-4'>";
echo "<h5>What happens next?</h5>";
echo "<ul>";
echo "<li>Scores are now stored in the <code>department_scores</code> table</li>";
echo "<li>Expert reviewers can view and verify these scores in the review system</li>";
echo "<li>Experts can adjust scores based on their verification</li>";
echo "<li>Some items require expert evaluation and are not auto-calculated</li>";
echo "</ul>";
echo "</div>";

echo "<div class='mt-4'>";
echo "<a href='view_department_scores.php' class='btn btn-primary'><i class='fas fa-trophy'></i> View Department Scores</a> ";
echo "<a href='dashboard.php' class='btn btn-secondary'><i class='fas fa-arrow-left'></i> Back to Dashboard</a>";
echo "</div>";

echo "</div>"; // End container
echo "</body></html>";
?>

