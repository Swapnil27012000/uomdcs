<?php
/**
 * AAQA Functions - Department Management and Progress Tracking
 */

require_once(__DIR__ . '/../config.php');

// Load common functions to get centralized getAcademicYear()
if (file_exists(__DIR__ . '/../common_functions.php')) {
    require_once(__DIR__ . '/../common_functions.php');
}

/**
 * CRITICAL: Get academic year in YYYY-YYYY format
 * Uses centralized function from root common_functions.php
 * Academic year runs from July to June:
 * - July onwards (month >= 7): current_year to current_year+1
 * - Below July (month < 7): (current_year-2) to (current_year-1)
 */
if (!function_exists('getAcademicYear')) {
    function getAcademicYear() {
        // Use centralized function from common_functions.php
        $common_functions_path = __DIR__ . '/../common_functions.php';
        if (file_exists($common_functions_path)) {
            require_once($common_functions_path);
        }
        
        // CRITICAL: Match dept_login logic exactly
        // If month >= 7 (July onwards), academic year is current_year to current_year+1
        // If month < 7 (January to June), academic year is (current_year-2) to (current_year-1)
        $current_year = (int)date('Y');
        $current_month = (int)date('n');
        
        if ($current_month >= 7) {
            return $current_year . '-' . ($current_year + 1);
        } else {
            return ($current_year - 2) . '-' . ($current_year - 1);
        }
    }
}

/**
 * Check if a form is completed for a department
 * Returns true if data exists in the table, false otherwise
 */
function checkFormCompletion($dept_id, $academic_year, $form_table) {
    global $conn;
    
    if (empty($form_table)) {
        return false;
    }
    
    // Get DEPT_COLL_NO for department_profiles (it stores COLL_NO, not DEPT_ID)
    $dept_coll_no = null;
    if ($form_table === 'department_profiles') {
        $coll_query = "SELECT DEPT_COLL_NO FROM department_master WHERE DEPT_ID = ? LIMIT 1";
        $coll_stmt = $conn->prepare($coll_query);
        if ($coll_stmt) {
            $coll_stmt->bind_param("i", $dept_id);
            $coll_stmt->execute();
            $coll_result = $coll_stmt->get_result();
            if ($coll_row = $coll_result->fetch_assoc()) {
                $dept_coll_no = $coll_row['DEPT_COLL_NO'];
            }
            mysqli_free_result($coll_result);
            $coll_stmt->close();
        }
    }
    
    // Map form names to table names and check queries
    // Note: department_profiles.dept_id stores DEPT_COLL_NO (VARCHAR), not DEPT_ID
    $form_checks = [
        'department_profiles' => "SELECT COUNT(*) as count FROM department_profiles WHERE dept_id = ? AND A_YEAR = ?",
        'brief_details_of_the_department' => "SELECT COUNT(*) as count FROM brief_details_of_the_department WHERE DEPT_ID = ? AND A_YEAR = ?",
        'programmes' => "SELECT COUNT(*) as count FROM programmes WHERE DEPT_ID = ?", // programmes doesn't use A_YEAR filter
        'exec_dev' => "SELECT COUNT(*) as count FROM exec_dev WHERE DEPT_ID = ? AND A_YEAR = ?",
        'intake_actual_strength' => "SELECT COUNT(*) as count FROM intake_actual_strength WHERE DEPT_ID = ? AND A_YEAR = ?",
        'placement_details' => "SELECT COUNT(*) as count FROM placement_details WHERE DEPT_ID = ? AND A_YEAR = ?",
        'salary_details' => "SELECT COUNT(*) as count FROM salary_details WHERE DEPT_ID = ? AND A_YEAR = ?",
        'employers_details' => "SELECT COUNT(*) as count FROM employers_details WHERE DEPT_ID = ? AND A_YEAR = ?",
        'phd_details' => "SELECT COUNT(*) as count FROM phd_details WHERE DEPT_ID = ? AND A_YEAR = ?",
        'faculty_details' => "SELECT COUNT(*) as count FROM faculty_details WHERE DEPT_ID = ? AND A_YEAR = ?",
        'academic_peers' => "SELECT COUNT(*) as count FROM academic_peers WHERE DEPT_ID = ? AND A_YEAR = ?",
        'faculty_output' => "SELECT COUNT(*) as count FROM faculty_output WHERE DEPT_ID = ? AND A_YEAR = ?",
        'nepmarks' => "SELECT COUNT(*) as count FROM nepmarks WHERE DEPT_ID = ? AND (A_YEAR = ? OR A_YEAR = CAST(SUBSTRING_INDEX(CAST(? AS CHAR), '-', -1) AS UNSIGNED))",
        'department_data' => "SELECT COUNT(*) as count FROM department_data WHERE DEPT_ID = ?", // department_data doesn't use A_YEAR
        'studentsupport' => "SELECT COUNT(*) as count FROM studentsupport WHERE dept = ? AND (A_YEAR = CAST(SUBSTRING_INDEX(CAST(? AS CHAR), '-', 1) AS UNSIGNED) OR A_YEAR = CAST(SUBSTRING_INDEX(CAST(? AS CHAR), '-', -1) AS UNSIGNED))",
        'conferences_workshops' => "SELECT COUNT(*) as count FROM conferences_workshops WHERE DEPT_ID = ? AND A_YEAR = ?",
        'collaborations' => "SELECT COUNT(*) as count FROM collaborations WHERE DEPT_ID = ? AND A_YEAR = ?"
    ];
    
    if (!isset($form_checks[$form_table])) {
        return false;
    }
    
    $query = $form_checks[$form_table];
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        error_log("Failed to prepare query for form check: $form_table - " . $conn->error);
        return false;
    }
    
    // Bind parameters based on query type
    if ($form_table === 'department_profiles') {
        // department_profiles.dept_id stores DEPT_COLL_NO (VARCHAR), not DEPT_ID
        if (!$dept_coll_no) {
            error_log("AAQA checkFormCompletion: Could not find DEPT_COLL_NO for DEPT_ID $dept_id");
            $stmt->close();
            return false;
        }
        $stmt->bind_param("ss", $dept_coll_no, $academic_year);
    } elseif (strpos($query, 'SUBSTRING_INDEX') !== false) {
        // For tables with integer A_YEAR fallback
        if ($form_table === 'nepmarks') {
            $a_year_int = (int)substr($academic_year, -4);
            $stmt->bind_param("iss", $dept_id, $academic_year, $academic_year);
        } elseif ($form_table === 'studentsupport') {
            // studentsupport.A_YEAR is INT, extract year from "2024-2025" format
            $a_year_start = (int)substr($academic_year, 0, 4);
            $a_year_end = (int)substr($academic_year, -4);
            $stmt->bind_param("iss", $dept_id, $academic_year, $academic_year);
        } else {
            $stmt->bind_param("is", $dept_id, $academic_year);
        }
    } elseif (strpos($query, 'A_YEAR') !== false) {
        $stmt->bind_param("is", $dept_id, $academic_year);
    } else {
        // No A_YEAR in query (e.g., programmes, department_data)
        $stmt->bind_param("i", $dept_id);
    }
    
    if (!$stmt->execute()) {
        error_log("AAQA checkFormCompletion: Query execution failed for $form_table - " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $result = $stmt->get_result();
    if (!$result) {
        error_log("AAQA checkFormCompletion: Failed to get result for $form_table - " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $row = $result->fetch_assoc();
    $count = (int)($row['count'] ?? 0);
    
    // Enhanced debug logging with more details
    $debug_info = "dept_id=$dept_id, form=$form_table, academic_year=$academic_year, count=$count";
    if ($form_table === 'department_profiles' && isset($dept_coll_no)) {
        $debug_info .= ", dept_coll_no=$dept_coll_no";
    }
    
    if ($count > 0) {
        error_log("AAQA checkFormCompletion: ✅ Form $form_table COMPLETED - $debug_info");
    } else {
        error_log("AAQA checkFormCompletion: ❌ Form $form_table NOT completed - $debug_info");
    }
    
    mysqli_free_result($result);
    $stmt->close();
    
    return $count > 0;
}

/**
 * Calculate completion progress for a department
 * Returns array with completion percentage and details
 */
function calculateDepartmentProgress($dept_id, $academic_year = null) {
    global $conn;
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    // Get form definitions with weights
    $query = "SELECT form_name, form_table, weight 
              FROM form_completion_status 
              WHERE dept_id = 0 AND academic_year = ? 
              ORDER BY weight DESC";
    $stmt = $conn->prepare($query);
    $forms = [];
    
    if ($stmt) {
        $stmt->bind_param("s", $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $forms[] = $row;
        }
        mysqli_free_result($result);
        $stmt->close();
    }
    
    // If no forms found, use default list
    if (empty($forms)) {
        $forms = [
            ['form_name' => 'Profile', 'form_table' => 'department_profiles', 'weight' => 4.17],
            ['form_name' => 'Department Details', 'form_table' => 'brief_details_of_the_department', 'weight' => 6.94],
            ['form_name' => 'Programmes Offered', 'form_table' => 'programmes', 'weight' => 5.56],
            ['form_name' => 'Executive Development Program', 'form_table' => 'exec_dev', 'weight' => 2.78],
            ['form_name' => 'Student Enrolment', 'form_table' => 'intake_actual_strength', 'weight' => 5.56],
            ['form_name' => 'Placement', 'form_table' => 'placement_details', 'weight' => 5.56],
            ['form_name' => 'Salary', 'form_table' => 'salary_details', 'weight' => 2.78],
            ['form_name' => 'Employer', 'form_table' => 'employers_details', 'weight' => 2.78],
            ['form_name' => 'PhD', 'form_table' => 'phd_details', 'weight' => 4.17],
            ['form_name' => 'Faculty Details', 'form_table' => 'faculty_details', 'weight' => 6.94],
            ['form_name' => 'Academic Peers', 'form_table' => 'academic_peers', 'weight' => 4.17],
            ['form_name' => 'Faculty Output', 'form_table' => 'faculty_output', 'weight' => 11.11],
            ['form_name' => 'NEP Initiatives', 'form_table' => 'nepmarks', 'weight' => 8.33],
            ['form_name' => 'Departmental Governance', 'form_table' => 'department_data', 'weight' => 9.72],
            ['form_name' => 'Student Support', 'form_table' => 'studentsupport', 'weight' => 8.33],
            ['form_name' => 'Conferences and Workshops', 'form_table' => 'conferences_workshops', 'weight' => 5.56],
            ['form_name' => 'Collaborations', 'form_table' => 'collaborations', 'weight' => 5.56]
        ];
    }
    
    $total_weight = 0;
    $completed_weight = 0;
    $form_status = [];
    
    foreach ($forms as $form) {
        $is_completed = checkFormCompletion($dept_id, $academic_year, $form['form_table']);
        $weight = (float)$form['weight'];
        $total_weight += $weight;
        
        if ($is_completed) {
            $completed_weight += $weight;
        }
        
        $form_status[] = [
            'form_name' => $form['form_name'],
            'form_table' => $form['form_table'],
            'weight' => $weight,
            'is_completed' => $is_completed
        ];
    }
    
    $progress_percentage = $total_weight > 0 ? ($completed_weight / $total_weight) * 100 : 0;
    
    // Debug logging - show which forms are completed
    $completed_forms_list = array_filter($form_status, function($f) { return $f['is_completed']; });
    $completed_names = array_column($completed_forms_list, 'form_name');
    $not_completed_list = array_filter($form_status, function($f) { return !$f['is_completed']; });
    $not_completed_names = array_column($not_completed_list, 'form_name');
    
    error_log("AAQA calculateDepartmentProgress: dept_id=$dept_id, academic_year=$academic_year");
    error_log("  Progress: $progress_percentage% | Completed: $completed_weight/$total_weight");
    error_log("  ✅ Completed forms (" . count($completed_names) . "): " . (empty($completed_names) ? 'NONE' : implode(', ', $completed_names)));
    error_log("  ❌ Not completed (" . count($not_completed_names) . "): " . (empty($not_completed_names) ? 'NONE' : implode(', ', $not_completed_names)));
    
    return [
        'progress_percentage' => round($progress_percentage, 2),
        'completed_weight' => $completed_weight,
        'total_weight' => $total_weight,
        'completed_forms' => count(array_filter($form_status, function($f) { return $f['is_completed']; })),
        'total_forms' => count($form_status),
        'form_status' => $form_status
    ];
}

/**
 * Get all departments with progress
 */
function getAllDepartmentsWithProgress($academic_year = null) {
    global $conn;
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    // Check if connection exists
    if (!isset($conn) || !$conn) {
        error_log("AAQA getAllDepartmentsWithProgress: Database connection not available");
        return [];
    }
    
    $query = "SELECT DISTINCT 
                dm.DEPT_ID,
                dm.DEPT_COLL_NO AS DEPT_CODE,
                COALESCE(dn.collname, dm.DEPT_NAME) AS DEPT_NAME,
                dm.EMAIL,
                dm.HOD_NAME,
                COALESCE(dp.category, 'Uncategorized') AS CATEGORY
              FROM department_master dm
              LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
              LEFT JOIN department_profiles dp ON (
                (dp.dept_id = CAST(dm.DEPT_ID AS CHAR) OR dp.dept_id = dm.DEPT_COLL_NO)
                AND dp.A_YEAR = ?
              )
              ORDER BY COALESCE(dn.collname, dm.DEPT_NAME) ASC";
    
    $stmt = $conn->prepare($query);
    $departments = [];
    
    if (!$stmt) {
        error_log("AAQA getAllDepartmentsWithProgress: Failed to prepare query - " . $conn->error);
        return [];
    }
    
    try {
        $stmt->bind_param("s", $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result) {
            error_log("AAQA getAllDepartmentsWithProgress: Query execution failed - " . $stmt->error);
            $stmt->close();
            return [];
        }
        
        while ($row = $result->fetch_assoc()) {
            try {
                $progress = calculateDepartmentProgress($row['DEPT_ID'], $academic_year);
                $row['progress'] = $progress;
                $departments[] = $row;
            } catch (Exception $e) {
                error_log("AAQA getAllDepartmentsWithProgress: Error calculating progress for dept " . $row['DEPT_ID'] . " - " . $e->getMessage());
                // Add department with default progress
                $row['progress'] = [
                    'progress_percentage' => 0,
                    'completed_forms' => 0,
                    'total_forms' => 17
                ];
                $departments[] = $row;
            }
        }
        
        mysqli_free_result($result);
        $stmt->close();
    } catch (Exception $e) {
        error_log("AAQA getAllDepartmentsWithProgress: Exception - " . $e->getMessage());
        if ($stmt) {
            $stmt->close();
        }
    }
    
    return $departments;
}

/**
 * Update form completion status (manual override)
 */
function updateFormCompletionStatus($dept_id, $academic_year, $form_name, $is_completed, $completed_by = null) {
    global $conn;
    
    // Get weight from template
    $weight_query = "SELECT weight FROM form_completion_status WHERE dept_id = 0 AND academic_year = ? AND form_name = ? LIMIT 1";
    $weight_stmt = $conn->prepare($weight_query);
    $weight = 0;
    
    if ($weight_stmt) {
        $weight_stmt->bind_param("ss", $academic_year, $form_name);
        $weight_stmt->execute();
        $weight_result = $weight_stmt->get_result();
        if ($weight_row = $weight_result->fetch_assoc()) {
            $weight = (float)$weight_row['weight'];
        }
        mysqli_free_result($weight_result);
        $weight_stmt->close();
    }
    
    // Get form_table from template
    $table_query = "SELECT form_table FROM form_completion_status WHERE dept_id = 0 AND academic_year = ? AND form_name = ? LIMIT 1";
    $table_stmt = $conn->prepare($table_query);
    $form_table = null;
    
    if ($table_stmt) {
        $table_stmt->bind_param("ss", $academic_year, $form_name);
        $table_stmt->execute();
        $table_result = $table_stmt->get_result();
        if ($table_row = $table_result->fetch_assoc()) {
            $form_table = $table_row['form_table'];
        }
        mysqli_free_result($table_result);
        $table_stmt->close();
    }
    
    // Insert or update completion status
    $query = "INSERT INTO form_completion_status 
              (dept_id, academic_year, form_name, form_table, is_completed, completed_at, completed_by, weight)
              VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)
              ON DUPLICATE KEY UPDATE
              is_completed = VALUES(is_completed),
              completed_at = IF(VALUES(is_completed) = 1, NOW(), completed_at),
              completed_by = VALUES(completed_by),
              updated_at = NOW()";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("issisisd", $dept_id, $academic_year, $form_name, $form_table, $is_completed, $completed_by, $weight);
        $stmt->execute();
        $stmt->close();
        return true;
    }
    
    return false;
}

