<?php
/**
 * Common Progress Functions - Shared across Admin, AAQA, and Department dashboards
 * Based on AAQA_login/functions.php
 */

// Only load config.php if $conn is not already available
if (!isset($conn) || !$conn) {
    require_once(__DIR__ . '/config.php');
}

/**
 * Get academic year in YYYY-YYYY format
 * Academic year runs from July to June:
 * - July onwards (month >= 7): current_year to current_year+1 (e.g., July 2026 = "2026-2027")
 * - Below July (month < 7): (current_year-2) to (current_year-1) (e.g., Jan-June 2026 = "2024-2025")
 * 
 * CRITICAL: This function returns the academic year that should be displayed.
 * If we're in Jan-June 2026, it returns "2024-2025" (the previous academic year).
 * If we're in July-Dec 2026, it returns "2026-2027" (the new academic year).
 * 
 * @return string Academic year in format "YYYY-YYYY"
 */
if (!function_exists('getAcademicYear')) {
    function getAcademicYear() {
        $current_year = (int)date('Y');
        $current_month = (int)date('n');
        
        // If month >= 7 (July onwards), academic year is current_year to current_year+1
        // If month < 7 (January to June), academic year is (current_year-2) to (current_year-1)
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
    
    // Ensure database connection is available
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        error_log("checkFormCompletion: Database connection not available");
        return false;
    }
    
    if (empty($form_table)) {
        return false;
    }
    
    // Get DEPT_COLL_NO for department_profiles (it stores COLL_NO, not DEPT_ID)
    // CRITICAL: Handle both DEPT_COLL_NO and DEPT_ID to support all cases
    $dept_coll_no = null;
    if ($form_table === 'department_profiles') {
        $coll_query = "SELECT DEPT_COLL_NO FROM department_master WHERE DEPT_ID = ? LIMIT 1";
        $coll_stmt = mysqli_prepare($conn, $coll_query);
        if ($coll_stmt) {
            mysqli_stmt_bind_param($coll_stmt, "i", $dept_id);
            mysqli_stmt_execute($coll_stmt);
            $coll_result = mysqli_stmt_get_result($coll_stmt);
            if ($coll_row = mysqli_fetch_assoc($coll_result)) {
                $dept_coll_no = $coll_row['DEPT_COLL_NO'];
            }
            if ($coll_result) {
                mysqli_free_result($coll_result);
            }
            mysqli_stmt_close($coll_stmt);
        }
    }
    
    // Map form names to table names and check queries
    $form_checks = [
        'department_profiles' => "SELECT COUNT(*) as count FROM department_profiles WHERE (dept_id = ? OR dept_id = ?) AND A_YEAR = ?",
        // CRITICAL FIX: Check for brief_details_of_the_department with flexible A_YEAR matching
        // This form updates the same record each year, so we check for any record with matching DEPT_ID
        // and then verify A_YEAR matches (current or previous year for flexibility)
        'brief_details_of_the_department' => "SELECT COUNT(*) as count FROM brief_details_of_the_department WHERE DEPT_ID = ? AND (A_YEAR = ? OR A_YEAR = ?)",
        'programmes' => "SELECT COUNT(*) as count FROM programmes WHERE DEPT_ID = ?",
        'exec_dev' => "SELECT COUNT(*) as count FROM exec_dev WHERE DEPT_ID = ? AND A_YEAR = ?",
        'intake_actual_strength' => "SELECT COUNT(*) as count FROM intake_actual_strength WHERE DEPT_ID = ? AND A_YEAR = ?",
        'placement_details' => "SELECT COUNT(*) as count FROM placement_details WHERE DEPT_ID = ? AND A_YEAR = ?",
        'salary_table' => "SELECT COUNT(*) as count FROM salary_table WHERE DEPT_ID = ? AND A_YEAR = ?",
        'salary_details' => "SELECT COUNT(*) as count FROM salary_details WHERE DEPT_ID = ? AND A_YEAR = ?",
        'employers_details' => "SELECT COUNT(*) as count FROM employers_details WHERE DEPT_ID = ? AND A_YEAR = ?",
        'phd_details' => "SELECT COUNT(*) as count FROM phd_details WHERE DEPT_ID = ? AND A_YEAR = ?",
        'faculty_details' => "SELECT COUNT(*) as count FROM faculty_details WHERE DEPT_ID = ? AND A_YEAR = ?",
        'academic_peers' => "SELECT COUNT(*) as count FROM academic_peers WHERE DEPT_ID = ? AND A_YEAR = ?",
        'faculty_output' => "SELECT COUNT(*) as count FROM faculty_output WHERE DEPT_ID = ? AND A_YEAR = ?",
        'nepmarks' => "SELECT COUNT(*) as count FROM nepmarks WHERE DEPT_ID = ? AND SUBSTRING_INDEX(CAST(? AS CHAR), '-', 1) = SUBSTRING_INDEX(CAST(A_YEAR AS CHAR), '-', 1)",
        'department_data' => "SELECT COUNT(*) as count FROM department_data WHERE DEPT_ID = ?",
        'studentsupport' => "SELECT COUNT(*) as count FROM studentsupport WHERE dept = ? AND (A_YEAR = CAST(SUBSTRING_INDEX(CAST(? AS CHAR), '-', 1) AS UNSIGNED) OR A_YEAR = CAST(SUBSTRING_INDEX(CAST(? AS CHAR), '-', -1) AS UNSIGNED))",
        'conferences_workshops' => "SELECT COUNT(*) as count FROM conferences_workshops WHERE DEPT_ID = ? AND A_YEAR = ?",
        'collaborations' => "SELECT COUNT(*) as count FROM collaborations WHERE DEPT_ID = ? AND A_YEAR = ?"
    ];
    
    if (!isset($form_checks[$form_table])) {
        return false;
    }
    
    $query = $form_checks[$form_table];
    $stmt = mysqli_prepare($conn, $query);
    
    if (!$stmt) {
        error_log("Progress checkFormCompletion: Failed to prepare query for $form_table - " . mysqli_error($conn));
        return false;
    }
    
    // Bind parameters based on form type
    if ($form_table === 'department_profiles') {
        // CRITICAL: Check both DEPT_COLL_NO and DEPT_ID (as string) to handle all cases
        // Some records might be stored with DEPT_ID, others with DEPT_COLL_NO
        $dept_id_str = (string)$dept_id;
        $dept_coll_no_str = $dept_coll_no ? (string)$dept_coll_no : $dept_id_str;
        // Use both values - if DEPT_COLL_NO exists, check both; otherwise just check DEPT_ID
        mysqli_stmt_bind_param($stmt, "sss", $dept_coll_no_str, $dept_id_str, $academic_year);
    } elseif ($form_table === 'brief_details_of_the_department') {
        // CRITICAL FIX: Check current year and previous year for flexibility
        // This form updates the same record each year, so we need to check both current and previous academic year
        $prev_year = '';
        $current_year = (int)date('Y');
        $current_month = (int)date('n');
        if ($current_month >= 7) {
            // Current: 2025-2026, Previous: 2024-2025
            $prev_year = ($current_year - 1) . '-' . $current_year;
        } else {
            // Current: 2024-2025, Previous: 2023-2024
            $prev_year = ($current_year - 3) . '-' . ($current_year - 2);
        }
        mysqli_stmt_bind_param($stmt, "iss", $dept_id, $academic_year, $prev_year);
    } elseif ($form_table === 'studentsupport') {
        mysqli_stmt_bind_param($stmt, "iss", $dept_id, $academic_year, $academic_year);
    } elseif (strpos($query, 'A_YEAR') !== false) {
        mysqli_stmt_bind_param($stmt, "is", $dept_id, $academic_year);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $dept_id);
    }
    
    if (!mysqli_stmt_execute($stmt)) {
        error_log("Progress checkFormCompletion: Query execution failed for $form_table - " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return false;
    }
    
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        error_log("Progress checkFormCompletion: Failed to get result for $form_table");
        mysqli_stmt_close($stmt);
        return false;
    }
    
    $row = mysqli_fetch_assoc($result);
    $count = (int)($row['count'] ?? 0);
    
    mysqli_free_result($result);
    mysqli_stmt_close($stmt);
    
    // If data exists, form is complete
    if ($count > 0) {
        return true;
    }
    
    // ✅ NEW: Check if form is marked as skipped/N/A in form_completion_status table
    // This applies to forms that use the skip button without creating dummy records
    // Form names must match template records (dept_id = 0) exactly
    $status_only_forms = [
        'placement_details' => 'Placement',  // Match template: 'Placement'
        'salary_details' => 'Salary',          // Match template: 'Salary'
        'employers_details' => 'Employer',      // Match template: 'Employer'
        'academic_peers' => 'Academic Peers'   // Match template: 'Academic Peers' (with space)
    ];
    
    if (isset($status_only_forms[$form_table])) {
        $form_name = $status_only_forms[$form_table];
        // ✅ Check both new 'status' column (skip button) and old 'is_completed' column (legacy)
        // Simplified query - check status column first (most common case)
        $status_query = "SELECT id FROM form_completion_status 
                        WHERE dept_id = ? AND form_name = ? AND academic_year = ? 
                        AND (status = 'skipped' OR status = 'na' OR status = 'completed' OR is_completed = 1)
                        LIMIT 1";
        $status_stmt = mysqli_prepare($conn, $status_query);
        
        if (!$status_stmt) {
            error_log("checkFormCompletion: Failed to prepare status query for $form_table - " . mysqli_error($conn));
            return false;
        }
        
        mysqli_stmt_bind_param($status_stmt, "iss", $dept_id, $form_name, $academic_year);
        
        if (!mysqli_stmt_execute($status_stmt)) {
            error_log("checkFormCompletion: Failed to execute status query for $form_table - " . mysqli_stmt_error($status_stmt));
            mysqli_stmt_close($status_stmt);
            return false;
        }
        
        $status_result = mysqli_stmt_get_result($status_stmt);
        
        if ($status_result && mysqli_num_rows($status_result) > 0) {
            // Form is marked as skipped/N/A or completed - consider it complete
            mysqli_free_result($status_result);
            mysqli_stmt_close($status_stmt);
            return true;
        }
        
        if ($status_result) {
            mysqli_free_result($status_result);
        }
        mysqli_stmt_close($status_stmt);
    }
    
    return false;
}

/**
 * Calculate completion progress for a department
 */
function calculateDepartmentProgress($dept_id, $academic_year = null) {
    global $conn;
    
    // Ensure database connection is available
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        error_log("calculateDepartmentProgress: Database connection not available");
        return [
            'progress_percentage' => 0,
            'completed_forms' => 0,
            'total_forms' => 17,
            'form_status' => []
        ];
    }
    
    if (!$academic_year) {
        if (function_exists('getAcademicYear')) {
            $academic_year = getAcademicYear();
        } else {
            // Fallback calculation
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            if ($current_month >= 7) {
                $academic_year = $current_year . '-' . ($current_year + 1);
            } else {
                $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
            }
        }
    }
    
    // ✅ Get form definitions with weights from table (dept_id = 0 = template)
    // This uses the old logic: form definitions stored in table
    $query = "SELECT form_name, form_table, weight 
              FROM form_completion_status 
              WHERE dept_id = 0 AND academic_year = ? 
              ORDER BY weight DESC";
    $stmt = mysqli_prepare($conn, $query);
    $forms = [];
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $academic_year);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $forms[] = $row;
            }
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);
    }
    
    // ✅ Fallback: If no forms found in table, use default hardcoded list
    // This ensures it works even if table doesn't have form definitions yet
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
 * Get all departments with progress (for admin/AAQA dashboards)
 */
function getAllDepartmentsWithProgress($academic_year = null) {
    global $conn;
    
    if (!$academic_year) {
        $academic_year = getAcademicYear();
    }
    
    if (!isset($conn) || !$conn) {
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
    
    $stmt = mysqli_prepare($conn, $query);
    $departments = [];
    
    if (!$stmt) {
        return [];
    }
    
    try {
        mysqli_stmt_bind_param($stmt, "s", $academic_year);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (!$result) {
            mysqli_stmt_close($stmt);
            return [];
        }
        
        while ($row = mysqli_fetch_assoc($result)) {
            try {
                $progress = calculateDepartmentProgress($row['DEPT_ID'], $academic_year);
                $row['progress'] = $progress;
                $departments[] = $row;
            } catch (Exception $e) {
                $row['progress'] = [
                    'progress_percentage' => 0,
                    'completed_forms' => 0,
                    'total_forms' => 17
                ];
                $departments[] = $row;
            }
        }
        
        mysqli_free_result($result);
        mysqli_stmt_close($stmt);
    } catch (Exception $e) {
        if ($stmt) {
            mysqli_stmt_close($stmt);
        }
    }
    
    return $departments;
}

/**
 * Get progress for a specific form/page (for department dashboard)
 */
function getFormProgress($dept_id, $academic_year, $form_table) {
    $progress = calculateDepartmentProgress($dept_id, $academic_year);
    
    // Find the specific form in the status
    foreach ($progress['form_status'] as $form) {
        if ($form['form_table'] === $form_table) {
            return [
                'is_completed' => $form['is_completed'],
                'form_name' => $form['form_name'],
                'weight' => $form['weight']
            ];
        }
    }
    
    return null;
}

