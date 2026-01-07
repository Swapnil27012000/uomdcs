<?php
// Common function to get department ID - use this in all files
function getDepartmentInfo($conn) {
    // Check if session is started
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['admin_username'])) {
        error_log("ERROR getDepartmentInfo: admin_username not set in session");
        return false;
    }
    
    $admin_username = trim($_SESSION['admin_username']); // Remove any whitespace
    error_log("DEBUG getDepartmentInfo: Looking for email: '" . $admin_username . "'");
    
    // Try exact match first
    $dept_query = "SELECT DEPT_ID, DEPT_COLL_NO, DEPT_NAME FROM department_master WHERE EMAIL = ?";
    $stmt = mysqli_prepare($conn, $dept_query);
    mysqli_stmt_bind_param($stmt, "s", $admin_username);
    mysqli_stmt_execute($stmt);
    $dept_result = mysqli_stmt_get_result($stmt);
    
    if (!$dept_result) {
        error_log("ERROR getDepartmentInfo: Query failed - " . mysqli_error($conn));
        return false;
    }
    
    $dept_info = mysqli_fetch_assoc($dept_result);
    
    if ($dept_info && $dept_info['DEPT_ID']) {
        error_log("SUCCESS getDepartmentInfo: Found exact match for: " . $admin_username);
        return $dept_info;
    }
    
    // If exact match fails, try case-insensitive search
    error_log("DEBUG getDepartmentInfo: Exact match failed, trying case-insensitive search");
    $dept_query = "SELECT DEPT_ID, DEPT_COLL_NO, DEPT_NAME FROM department_master WHERE LOWER(EMAIL) = LOWER(?)";
    $stmt = mysqli_prepare($conn, $dept_query);
    mysqli_stmt_bind_param($stmt, "s", $admin_username);
    mysqli_stmt_execute($stmt);
    $dept_result = mysqli_stmt_get_result($stmt);
    
    if (!$dept_result) {
        error_log("ERROR getDepartmentInfo: Case-insensitive query failed - " . mysqli_error($conn));
        return false;
    }
    
    $dept_info = mysqli_fetch_assoc($dept_result);
    
    if ($dept_info && $dept_info['DEPT_ID']) {
        error_log("SUCCESS getDepartmentInfo: Found case-insensitive match for: " . $admin_username);
        return $dept_info;
    }
    
    // If still no match, log all available emails for debugging
    error_log("ERROR getDepartmentInfo: No department found for email: " . $admin_username);
    $debug_query = "SELECT EMAIL FROM department_master LIMIT 10";
    $debug_result = mysqli_query($conn, $debug_query);
    if ($debug_result) {
        $emails = [];
        while ($row = mysqli_fetch_assoc($debug_result)) {
            $emails[] = $row['EMAIL'];
        }
        error_log("DEBUG getDepartmentInfo: Available emails in database: " . implode(', ', $emails));
    }
    
    return false;
}

// Common function to get department ID only
function getDepartmentId($conn) {
    $dept_info = getDepartmentInfo($conn);
    return $dept_info ? $dept_info['DEPT_ID'] : false;
}

/**
 * CRITICAL: Centralized Academic Year Function
 * Get academic year in YYYY-YYYY format
 * Academic year runs from July to June:
 * - July onwards (month >= 7): current_year to current_year+1 (e.g., July 2026 = "2026-2027")
 * - Below July (month < 7): (current_year-2) to (current_year-1) (e.g., Jan-June 2026 = "2024-2025")
 * 
 * This function returns the academic year that should be displayed.
 * If we're in Jan-June 2026, it returns "2024-2025" (the previous academic year).
 * If we're in July-Dec 2026, it returns "2026-2027" (the new academic year).
 * 
 * IMPORTANT: This matches the logic in dept_login/common_functions.php
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
?>
