<?php
/**
 * Test Progress Calculation - Debug Tool
 * This file helps debug why progress is showing 0
 */

require('session.php');
require('functions.php');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$academic_year = getAcademicYear();
echo "<h2>Progress Calculation Debug</h2>";
echo "<p>Academic Year: <strong>$academic_year</strong></p>";

// Get a sample department
$test_query = "SELECT DEPT_ID, DEPT_COLL_NO, DEPT_NAME FROM department_master LIMIT 5";
$test_result = mysqli_query($conn, $test_query);

if ($test_result && mysqli_num_rows($test_result) > 0) {
    echo "<h3>Testing Progress for Sample Departments:</h3>";
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>DEPT_ID</th><th>DEPT_COLL_NO</th><th>Department Name</th><th>Progress %</th><th>Completed Forms</th><th>Details</th></tr>";
    
    while ($dept = mysqli_fetch_assoc($test_result)) {
        $dept_id = $dept['DEPT_ID'];
        $dept_coll_no = $dept['DEPT_COLL_NO'];
        $dept_name = $dept['DEPT_NAME'];
        
        $progress = calculateDepartmentProgress($dept_id, $academic_year);
        
        echo "<tr>";
        echo "<td>$dept_id</td>";
        echo "<td>$dept_coll_no</td>";
        echo "<td>$dept_name</td>";
        echo "<td>" . $progress['progress_percentage'] . "%</td>";
        echo "<td>" . $progress['completed_forms'] . " / " . $progress['total_forms'] . "</td>";
        echo "<td>";
        echo "<details><summary>Form Status</summary><ul>";
        foreach ($progress['form_status'] as $form) {
            $status = $form['is_completed'] ? '✅' : '❌';
            echo "<li>$status {$form['form_name']} (Weight: {$form['weight']}%)</li>";
        }
        echo "</ul></details>";
        echo "</td>";
        echo "</tr>";
        
        // Test department_profiles specifically
        echo "<tr><td colspan='6' style='background:#f0f0f0; padding:10px;'>";
        echo "<strong>Testing department_profiles for DEPT_ID $dept_id (DEPT_COLL_NO: $dept_coll_no):</strong><br>";
        
        // Check what's in department_profiles
        $profile_check = "SELECT * FROM department_profiles WHERE dept_id = ? AND A_YEAR = ?";
        $profile_stmt = $conn->prepare($profile_check);
        if ($profile_stmt) {
            $profile_stmt->bind_param("ss", $dept_coll_no, $academic_year);
            $profile_stmt->execute();
            $profile_result = $profile_stmt->get_result();
            if ($profile_result->num_rows > 0) {
                $profile_row = $profile_result->fetch_assoc();
                echo "✅ Found profile: dept_id='{$profile_row['dept_id']}', A_YEAR='{$profile_row['A_YEAR']}'<br>";
            } else {
                echo "❌ No profile found with dept_id='$dept_coll_no' AND A_YEAR='$academic_year'<br>";
                // Try with DEPT_ID as string
                $profile_check2 = "SELECT * FROM department_profiles WHERE dept_id = ? AND A_YEAR = ?";
                $profile_stmt2 = $conn->prepare($profile_check2);
                $dept_id_str = (string)$dept_id;
                $profile_stmt2->bind_param("ss", $dept_id_str, $academic_year);
                $profile_stmt2->execute();
                $profile_result2 = $profile_stmt2->get_result();
                if ($profile_result2->num_rows > 0) {
                    echo "✅ Found profile with DEPT_ID as string: dept_id='$dept_id_str'<br>";
                } else {
                    echo "❌ Also not found with DEPT_ID='$dept_id_str'<br>";
                }
                mysqli_free_result($profile_result2);
                $profile_stmt2->close();
            }
            mysqli_free_result($profile_result);
            $profile_stmt->close();
        }
        
        // Check brief_details_of_the_department
        $brief_check = "SELECT COUNT(*) as cnt FROM brief_details_of_the_department WHERE DEPT_ID = ? AND A_YEAR = ?";
        $brief_stmt = $conn->prepare($brief_check);
        if ($brief_stmt) {
            $brief_stmt->bind_param("is", $dept_id, $academic_year);
            $brief_stmt->execute();
            $brief_result = $brief_stmt->get_result();
            $brief_row = $brief_result->fetch_assoc();
            echo "brief_details_of_the_department: " . ($brief_row['cnt'] > 0 ? "✅ Found {$brief_row['cnt']} records" : "❌ Not found") . "<br>";
            mysqli_free_result($brief_result);
            $brief_stmt->close();
        }
        
        echo "</td></tr>";
    }
    
    echo "</table>";
    mysqli_free_result($test_result);
} else {
    echo "<p>No departments found in database.</p>";
}

echo "<hr>";
echo "<h3>Sample Data Check:</h3>";
echo "<p>Checking what's actually in the database...</p>";

// Check department_profiles
$sample_profiles = "SELECT dept_id, A_YEAR, department_name FROM department_profiles LIMIT 10";
$profiles_result = mysqli_query($conn, $sample_profiles);
if ($profiles_result) {
    echo "<h4>Sample department_profiles records:</h4>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>dept_id</th><th>A_YEAR</th><th>department_name</th></tr>";
    while ($row = mysqli_fetch_assoc($profiles_result)) {
        echo "<tr><td>{$row['dept_id']}</td><td>{$row['A_YEAR']}</td><td>{$row['department_name']}</td></tr>";
    }
    echo "</table>";
    mysqli_free_result($profiles_result);
}

// Check brief_details_of_the_department
$sample_brief = "SELECT DEPT_ID, A_YEAR, DEPARTMENT_NAME FROM brief_details_of_the_department LIMIT 10";
$brief_result = mysqli_query($conn, $sample_brief);
if ($brief_result) {
    echo "<h4>Sample brief_details_of_the_department records:</h4>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>DEPT_ID</th><th>A_YEAR</th><th>DEPARTMENT_NAME</th></tr>";
    while ($row = mysqli_fetch_assoc($brief_result)) {
        echo "<tr><td>{$row['DEPT_ID']}</td><td>{$row['A_YEAR']}</td><td>{$row['DEPARTMENT_NAME']}</td></tr>";
    }
    echo "</table>";
    mysqli_free_result($brief_result);
}

?>

