<?php
// Common form header component with Academic Year and Department fields
// This file should be included in all forms that need these fields

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include config for database connection
// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    include 'config.php';
}
require 'common_functions.php';

// Get department ID using common function
$dept_info = getDepartmentInfo($conn);
if (!$dept_info) {
    $dept = '';
    $dept_code = '';
    $dept_name = '';
} else {
    $dept = $dept_info['DEPT_ID'];
    $dept_code = $dept_info['DEPT_COLL_NO'];
    $dept_name = $dept_info['DEPT_NAME'];
}

// Set academic year - use centralized function
if (!function_exists('getAcademicYear')) {
    // Function should be loaded from common_functions.php (already required above)
    if (file_exists(__DIR__ . '/common_functions.php')) {
        require_once(__DIR__ . '/common_functions.php');
    }
}

if (function_exists('getAcademicYear')) {
    $A_YEAR = getAcademicYear();
    // Parse academic year for display
    $year_parts = explode('-', $A_YEAR);
    $academic_start_year = (int)$year_parts[0];
    $academic_end_year = (int)$year_parts[1];
} else {
    // Fallback calculation (month >= 7 for July onwards)
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    if ($current_month >= 7) {
        $academic_start_year = $current_year;
        $academic_end_year = $current_year + 1;
    } else {
        $academic_start_year = $current_year - 1;
        $academic_end_year = $current_year;
    }
    $A_YEAR = $academic_start_year . '-' . $academic_end_year;
}

$academic_year_display = '1 JULY ' . $academic_start_year . ' - 30 JUNE ' . $academic_end_year;
?>

<!-- Academic Year and Department Information Header -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label fw-bold mb-2 fs-5">Academic Year</label>
            <input type="text" name="academic_year" value="<?php echo $academic_year_display; ?>" class="form-control" readonly style="background-color: #f8f9fa; cursor: not-allowed;">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label fw-bold mb-2 fs-5">Department Code & Name</label>
            <input type="text" name="dept_info" value="<?php echo $dept_code . ' - ' . $dept_name; ?>" class="form-control" readonly style="background-color: #f8f9fa; cursor: not-allowed;">
        </div>
    </div>
</div>
