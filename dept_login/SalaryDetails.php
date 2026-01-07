<?php
ob_start();
// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
require_once('../config.php');
}

// Resolve academic year from session/header if available; fallback to computed
function resolveAcademicYear() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['A_YEAR'])) {
        return (string)$_SESSION['A_YEAR'];
    }
    if (!empty($_SESSION['userInfo']['A_YEAR'])) {
        return (string)$_SESSION['userInfo']['A_YEAR'];
    }
    // Use centralized getAcademicYear() function
    if (!function_exists('getAcademicYear')) {
        if (file_exists(__DIR__ . '/common_functions.php')) {
            require_once(__DIR__ . '/common_functions.php');
        }
    }
    if (function_exists('getAcademicYear')) {
        return getAcademicYear();
    }
    // Fallback calculation (month >= 7 for July onwards)
    $currentYear = (int)date('Y');
    $currentMonth = (int)date('n');
    if ($currentMonth >= 7) {
        return $currentYear . '-' . ($currentYear + 1);
    } else {
        return ($currentYear - 2) . '-' . ($currentYear - 1);
    }
}

// ============================================================================
// AJAX FORM SUBMISSION HANDLER - MUST BE BEFORE ANY HTML OUTPUT
// ============================================================================

// Handle AJAX form submission
// CRITICAL: Follow all checklist items to prevent crashes and ensure proper JSON responses
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    // CRITICAL #1: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // ✅ Load session data for userInfo BEFORE handling skip action
    if (!isset($userInfo) && file_exists(__DIR__ . '/session.php')) {
        require_once(__DIR__ . '/session.php');
    }
    
    // ✅ Allow skip_form_complete to be handled by skip_form_component.php (AFTER session setup)
    if ($_POST['action'] === 'skip_form_complete') {
        require_once(__DIR__ . '/skip_form_component.php');
        // skip_form_component.php will handle and exit
    }
    
    // Clear buffers again after session start
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // CRITICAL #4: Set proper JSON headers with cache control - MUST be before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        if ($_POST['action'] == 'add_salary') {
            // Get department ID using session management
            $admin_username = $_SESSION['admin_username'] ?? '';
            if (empty($admin_username)) {
                throw new Exception('Session expired. Please login again.');
            }
            
            $dept_query = "SELECT DEPT_ID FROM department_master WHERE EMAIL = ?";
            $stmt = mysqli_prepare($conn, $dept_query);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, "s", $admin_username);
            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new Exception('Query execution failed: ' . mysqli_stmt_error($stmt));
            }
            $result = mysqli_stmt_get_result($stmt);
            $dept_info = mysqli_fetch_assoc($result);
            mysqli_free_result($result);  // CRITICAL: Free result
            mysqli_stmt_close($stmt);  // CRITICAL: Close statement
            
            if (!$dept_info || !$dept_info['DEPT_ID']) {
                throw new Exception('Department ID not found. Please contact administrator.');
            }
            
            $dept = $dept_info['DEPT_ID'];
            
            // CRITICAL: Get academic year
            $academic_year = resolveAcademicYear();
            
            // CRITICAL: Check if this specific PRN already exists for this academic year (prevent duplicate PRN entries)
            // Allow multiple records per academic year, but prevent duplicate PRN entries
            $prn = trim($_POST['prn'] ?? '');
            if (!empty($prn)) {
                $check_existing_salary = "SELECT COUNT(*) as count FROM salary_details WHERE DEPT_ID = ? AND A_YEAR = ? AND PRN = ?";
                $check_existing_stmt = mysqli_prepare($conn, $check_existing_salary);
                if ($check_existing_stmt) {
                    mysqli_stmt_bind_param($check_existing_stmt, "iss", $dept, $academic_year, $prn);
                    mysqli_stmt_execute($check_existing_stmt);
                    $check_existing_result = mysqli_stmt_get_result($check_existing_stmt);
                    if ($check_existing_result && ($existing_row = mysqli_fetch_assoc($check_existing_result))) {
                        if ((int)$existing_row['count'] > 0) {
                            mysqli_free_result($check_existing_result);
                            mysqli_stmt_close($check_existing_stmt);
                            $A_YEAR_escaped = htmlspecialchars($academic_year, ENT_QUOTES, 'UTF-8');
                            $response = [
                                'success' => false,
                                'message' => "A record with PRN '$prn' already exists for academic year $A_YEAR_escaped. Please use Edit to modify existing records."
                            ];
                            while (ob_get_level() > 0) {
                                ob_end_clean();
                            }
                            echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            exit;
                        }
                        mysqli_free_result($check_existing_result);
                    } else {
                        if ($check_existing_result) {
                            mysqli_free_result($check_existing_result);
                        }
                    }
                    mysqli_stmt_close($check_existing_stmt);
                }
            }
            
            $program_name = trim($_POST['program_name'] ?? '');
            $prn = trim($_POST['prn'] ?? '');
            $student_name = trim($_POST['student_name'] ?? '');
            $company_name = trim($_POST['company_name'] ?? '');
            $designation = trim($_POST['designation'] ?? '');
            $salary = (float)($_POST['salary'] ?? 0);
            $job_order = trim($_POST['job_order'] ?? '');
            
            // Get program code
            $program_query = "SELECT programme_code FROM programmes WHERE programme_name = ? AND DEPT_ID = ? LIMIT 1";
            $stmt = mysqli_prepare($conn, $program_query);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, "si", $program_name, $dept);
            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new Exception('Query execution failed: ' . mysqli_stmt_error($stmt));
            }
            $result = mysqli_stmt_get_result($stmt);
            $program_data = mysqli_fetch_assoc($result);
            mysqli_free_result($result);  // CRITICAL: Free result
            mysqli_stmt_close($stmt);  // CRITICAL: Close statement
            
            if ($program_data) {
                $program_code = $program_data['programme_code'];
                
                // Insert new salary detail
            $insert_query = "INSERT INTO salary_details (DEPT_ID, PROGRAM_CODE, PROGRAM_NAME, PRN, STUDENT_NAME, COMPANY_NAME, DESIGNATION, SALARY, JOB_ORDER, A_YEAR) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $insert_query);
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . mysqli_error($conn));
                }
            mysqli_stmt_bind_param($stmt, "issssssdss", $dept, $program_code, $program_name, $prn, $student_name, $company_name, $designation, $salary, $job_order, $academic_year);
                
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);  // CRITICAL: Close statement
                    $response = [
                        'success' => true,
                        'message' => 'Salary details added successfully!'
                    ];
                } else {
                    $error = mysqli_stmt_error($stmt);
                    mysqli_stmt_close($stmt);
                    throw new Exception('Failed to add salary details: ' . $error);
                }
    } else {
                throw new Exception('Program not found!');
            }
            
        } elseif ($_POST['action'] == 'update_salary') {
            // Get department ID using session management
            $admin_username = $_SESSION['admin_username'] ?? '';
            if (empty($admin_username)) {
                throw new Exception('Session expired. Please login again.');
    }
            
            $dept_query = "SELECT DEPT_ID FROM department_master WHERE EMAIL = ?";
            $stmt = mysqli_prepare($conn, $dept_query);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, "s", $admin_username);
            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new Exception('Query execution failed: ' . mysqli_stmt_error($stmt));
            }
            $result = mysqli_stmt_get_result($stmt);
            $dept_info = mysqli_fetch_assoc($result);
            mysqli_free_result($result);  // CRITICAL: Free result
            mysqli_stmt_close($stmt);  // CRITICAL: Close statement
            
            if (!$dept_info || !$dept_info['DEPT_ID']) {
                throw new Exception('Department ID not found. Please contact administrator.');
            }
            
            $dept = $dept_info['DEPT_ID'];
            
            $id = $_POST['id'] ?? '';
            $program_name = trim($_POST['program_name'] ?? '');
            $prn = trim($_POST['prn'] ?? '');
            $student_name = trim($_POST['student_name'] ?? '');
            $company_name = trim($_POST['company_name'] ?? '');
            $designation = trim($_POST['designation'] ?? '');
            $salary = (float)($_POST['salary'] ?? 0);
            $job_order = trim($_POST['job_order'] ?? '');
            
            // Get program code
            $program_query = "SELECT programme_code FROM programmes WHERE programme_name = ? AND DEPT_ID = ?";
            $stmt = mysqli_prepare($conn, $program_query);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, "si", $program_name, $dept);
            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new Exception('Query execution failed: ' . mysqli_stmt_error($stmt));
            }
            $result = mysqli_stmt_get_result($stmt);
            $program_data = mysqli_fetch_assoc($result);
            mysqli_free_result($result);  // CRITICAL: Free result
            mysqli_stmt_close($stmt);  // CRITICAL: Close statement
            
            if ($program_data) {
                $program_code = $program_data['programme_code'];
                
                // Update salary detail
            $update_query = "UPDATE salary_details SET PROGRAM_CODE = ?, PROGRAM_NAME = ?, PRN = ?, STUDENT_NAME = ?, COMPANY_NAME = ?, DESIGNATION = ?, SALARY = ?, JOB_ORDER = ? WHERE ID = ? AND DEPT_ID = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . mysqli_error($conn));
                }
            mysqli_stmt_bind_param($stmt, "ssssssdsii", $program_code, $program_name, $prn, $student_name, $company_name, $designation, $salary, $job_order, $id, $dept);
                
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);  // CRITICAL: Close statement
                    $response = [
                        'success' => true,
                        'message' => 'Salary details updated successfully!'
                    ];
                } else {
                    $error = mysqli_stmt_error($stmt);
                    mysqli_stmt_close($stmt);
                    throw new Exception('Failed to update salary details: ' . $error);
                }
            } else {
                throw new Exception('Program not found!');
            }
            
        } elseif ($_POST['action'] == 'delete_salary') {
            // Get department ID using session management
            $admin_username = $_SESSION['admin_username'] ?? '';
            if (empty($admin_username)) {
                throw new Exception('Session expired. Please login again.');
            }
            
            $dept_query = "SELECT DEPT_ID FROM department_master WHERE EMAIL = ?";
            $stmt = mysqli_prepare($conn, $dept_query);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, "s", $admin_username);
            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new Exception('Query execution failed: ' . mysqli_stmt_error($stmt));
            }
            $result = mysqli_stmt_get_result($stmt);
            $dept_info = mysqli_fetch_assoc($result);
            mysqli_free_result($result);  // CRITICAL: Free result
            mysqli_stmt_close($stmt);  // CRITICAL: Close statement
            
            if (!$dept_info || !$dept_info['DEPT_ID']) {
                throw new Exception('Department ID not found. Please contact administrator.');
            }
            
            $dept = $dept_info['DEPT_ID'];
            
            $id = $_POST['id'] ?? '';
            $delete_query = "DELETE FROM salary_details WHERE ID = ? AND DEPT_ID = ?";
            $stmt = mysqli_prepare($conn, $delete_query);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, "ii", $id, $dept);
            
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);  // CRITICAL: Close statement
                $response = [
                    'success' => true,
                    'message' => 'Salary details deleted successfully!'
                ];
        } else {
                $error = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception('Failed to delete salary details: ' . $error);
            }
        } else {
            throw new Exception('Invalid action');
        }
        
    } catch (Exception $e) {
        // CRITICAL #2: Build error response in variable
        $response = [
            'success' => false,
            'message' => $e->getMessage()
        ];
    } catch (Error $e) {
        // CRITICAL #2: Build error response in variable for fatal errors
        $response = [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
    
    // CRITICAL #1: Clear ALL output buffers before final output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL #2: Output response once at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

include 'unified_header.php';

// Soft message container
echo '<div id="soft-message" class="soft-message" style="display: none;"></div>';

// Get department ID
$dept = $userInfo['DEPT_ID'] ?? 0;
if (empty($dept)) {
    // Fallback to session-based lookup if userInfo not available
    $admin_username = $_SESSION['admin_username'] ?? '';
    if (empty($admin_username)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: ../unified_login.php?error=session_expired');
        }
        echo '<!DOCTYPE html><html><head><title>Session Expired</title></head><body><h1>Session Expired</h1><p>Please <a href="../unified_login.php">login again</a>.</p></body></html>';
        exit;
    }
    
    $dept_query = "SELECT DEPT_ID FROM department_master WHERE EMAIL = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $dept_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $admin_username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $dept_info = mysqli_fetch_assoc($result);
        if ($result) {
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);
        
        if ($dept_info && $dept_info['DEPT_ID']) {
            $dept = $dept_info['DEPT_ID'];
        }
    }
}

if (empty($dept)) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Location: ../unified_login.php?error=dept_id_missing');
    }
    echo '<!DOCTYPE html><html><head><title>Department ID Not Found</title></head><body><h1>Department ID not found</h1><p>Please <a href="../unified_login.php">login again</a>.</p></body></html>';
    exit;
}

// CRITICAL: Get academic year - use centralized function
if (!function_exists('getAcademicYear')) {
    if (file_exists(__DIR__ . '/common_functions.php')) {
        require_once(__DIR__ . '/common_functions.php');
    }
}
if (function_exists('getAcademicYear')) {
    $A_YEAR = getAcademicYear();
} else {
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    if ($current_month >= 7) {
        $A_YEAR = $current_year . '-' . ($current_year + 1);
    } else {
        $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
    }
}

// CRITICAL: SalaryDetails allows multiple records per academic year (one per student/PRN)
// Do NOT lock the form - users can add multiple salary records for the same academic year
$existing_salary_data = null;
$form_locked = false; // Always false - allow multiple records per academic year

// POST-based secure delete handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
    if (file_exists(__DIR__ . '/csrf.php')) { require_once __DIR__ . '/csrf.php'; }
    if (!function_exists('validate_csrf') || !validate_csrf($_POST['csrf_token'] ?? '')) {
        echo "<script>alert('Security validation failed. Please refresh and try again.'); window.location.href='SalaryDetails.php';</script>";
        exit;
    }

    $id = (int)$_POST['id'];
    $delete_query = "DELETE FROM salary_details WHERE ID = ? AND DEPT_ID = ?";
    $stmt = mysqli_prepare($conn, $delete_query);
    mysqli_stmt_bind_param($stmt, "ii", $id, $dept);
    if (mysqli_stmt_execute($stmt)) {
        echo "<script>alert('Salary details deleted successfully!'); window.location.href = 'SalaryDetails.php';</script>";
    } else {
        echo "<script>alert('Error deleting salary details.'); window.location.href = 'SalaryDetails.php';</script>";
    }
    exit;
}

// Handle GET delete (deprecated) - do not execute destructive action without POST+CSRF
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    echo "<script>alert('Delete action must be performed via secure form.'); window.location.href='SalaryDetails.php';</script>";
}

// Fetch all salary details for the department
$salary_query = "SELECT * FROM salary_details WHERE DEPT_ID = ? ORDER BY ID DESC";
$stmt = mysqli_prepare($conn, $salary_query);
mysqli_stmt_bind_param($stmt, "i", $dept);
mysqli_stmt_execute($stmt);
$salary_result = mysqli_stmt_get_result($stmt);
$salary_details = mysqli_fetch_all($salary_result, MYSQLI_ASSOC);
if ($salary_result) {
    mysqli_free_result($salary_result);  // CRITICAL: Free result
}
mysqli_stmt_close($stmt);  // CRITICAL: Close statement

// Fetch programs for dropdown
            $programs_query = "SELECT programme_name FROM programmes WHERE DEPT_ID = ? ORDER BY programme_name";
$stmt = mysqli_prepare($conn, $programs_query);
mysqli_stmt_bind_param($stmt, "i", $dept);
mysqli_stmt_execute($stmt);
$programs_result = mysqli_stmt_get_result($stmt);
$programs = mysqli_fetch_all($programs_result, MYSQLI_ASSOC);
if ($programs_result) {
    mysqli_free_result($programs_result);  // CRITICAL: Free result
}
mysqli_stmt_close($stmt);  // CRITICAL: Close statement

// Get record to edit if specified
$edit_record = null;
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $edit_id = $_GET['id'];
    $edit_query = "SELECT * FROM salary_details WHERE ID = ? AND DEPT_ID = ?";
    $stmt = mysqli_prepare($conn, $edit_query);
    mysqli_stmt_bind_param($stmt, "ii", $edit_id, $dept);
    mysqli_stmt_execute($stmt);
    $edit_result = mysqli_stmt_get_result($stmt);
    $edit_record = mysqli_fetch_assoc($edit_result);
    if ($edit_result) {
        mysqli_free_result($edit_result);  // CRITICAL: Free result
    }
    mysqli_stmt_close($stmt);  // CRITICAL: Close statement
}
?>

<style>
    /* Soft Message Styles */
    .soft-message {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        z-index: 9999;
        max-width: 400px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        animation: slideInRight 0.3s ease-out;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .soft-message i {
        font-size: 1.2rem;
    }
    
    .soft-message.success {
        background: linear-gradient(135deg, #10b981, #059669);
    }
    
    .soft-message.error {
        background: linear-gradient(135deg, #ef4444, #dc2626);
    }
    
    .soft-message.info {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
    }
    
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    .form-container {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 30px;
        margin-bottom: 30px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .table-container {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .btn-action {
        margin: 2px;
    }
    .form-group {
        margin-bottom: 15px;
    }
    .required {
        color: red;
    }
</style>

            <div class="main-content-area">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-header">
                    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <div>
                            <h1><i class="fas fa-money-bill-wave me-2"></i>Salary Details Management</h1>
                            <p class="text-muted">Manage student salary details and placement information</p>
                        </div>
                        <a href="export_page_pdf.php?page=SalaryDetails" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                            <i class="fas fa-file-pdf"></i> Download as PDF
                        </a>
                    </div>
                </div>


                <?php 
                // Display Skip Form Button for departments with NO salary data
                require_once(__DIR__ . '/skip_form_component.php');
                $check_existing_query = "SELECT ID FROM salary_details WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
                $check_existing_stmt = mysqli_prepare($conn, $check_existing_query);
                $has_existing_data = false;
                if ($check_existing_stmt) {
                    mysqli_stmt_bind_param($check_existing_stmt, "is", $dept, $A_YEAR);
                    mysqli_stmt_execute($check_existing_stmt);
                    $check_existing_result = mysqli_stmt_get_result($check_existing_stmt);
                    if ($check_existing_result && mysqli_num_rows($check_existing_result) > 0) {
                        $has_existing_data = true;
                    }
                    if ($check_existing_result) {
                        mysqli_free_result($check_existing_result);
                    }
                    mysqli_stmt_close($check_existing_stmt);
                }
                displaySkipFormButton('salary', 'Salary Details', $A_YEAR, $has_existing_data);
                ?>

                <!-- Add/Edit Form -->
                <div class="form-container">
                    <h3>
                        <i class="fas fa-<?php echo $edit_record ? 'edit' : 'plus'; ?> me-2"></i>
                        <?php echo $edit_record ? 'Edit Salary Details' : 'Add New Salary Details'; ?>
                    </h3>
                    
                    <form method="POST" action="" id="salaryForm">
                        <input type="hidden" name="action" value="<?php echo $edit_record ? 'update_salary' : 'add_salary'; ?>">
                        <?php if ($edit_record): ?>
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_record['ID']); ?>">
                        <?php endif; ?>
                        
                                <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="program_name">Program Name <span class="required">*</span></label>
                                    <select class="form-control" name="program_name" id="program_name" required>
                                        <option value="">Select Program</option>
                                        <?php foreach ($programs as $program): ?>
                                            <option value="<?php echo htmlspecialchars($program['programme_name']); ?>" 
                                                    <?php echo ($edit_record && $edit_record['PROGRAM_NAME'] == $program['programme_name']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($program['programme_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="prn">PRN <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="prn" id="prn" 
                                           value="<?php echo $edit_record ? htmlspecialchars($edit_record['PRN']) : ''; ?>" required>
                                </div>
                                        </div>
                                    </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="student_name">Student Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="student_name" id="student_name" 
                                           value="<?php echo $edit_record ? htmlspecialchars($edit_record['STUDENT_NAME']) : ''; ?>" required>
                                        </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="company_name">Company Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="company_name" id="company_name" 
                                           value="<?php echo $edit_record ? htmlspecialchars($edit_record['COMPANY_NAME']) : ''; ?>" required>
                                    </div>
                                </div>
                            </div>

                                <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="designation">Designation <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="designation" id="designation" 
                                           value="<?php echo $edit_record ? htmlspecialchars($edit_record['DESIGNATION']) : ''; ?>" required>
                                        </div>
                                    </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="salary">Salary (in Lakhs) <span class="required">*</span></label>
                                    <input type="number" class="form-control" name="salary" id="salary" min="0" step="0.01" placeholder="e.g., 3.50 for ₹3.5 Lakhs"
                                           value="<?php echo $edit_record ? htmlspecialchars($edit_record['SALARY']) : ''; ?>" required>
                                    <small class="text-muted">Enter salary in Lakhs (e.g., 3.50 for ₹3.5 Lakhs)</small>
                                </div>
                                        </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="job_order">Job Order</label>
                                    <input type="text" class="form-control" name="job_order" id="job_order" 
                                           value="<?php echo $edit_record ? htmlspecialchars($edit_record['JOB_ORDER']) : ''; ?>">
                                    </div>
                                </div>
                            </div>

                        <div class="form-group text-center">
                            <button type="submit" class="btn btn-success btn-lg me-3" id="submitBtn">
                                <i class="fas fa-save me-2"></i><?php echo $edit_record ? 'Update Salary Details' : 'Add Salary Details'; ?>
                                </button>
                            <?php if ($edit_record): ?>
                                <a href="SalaryDetails.php" class="btn btn-secondary btn-lg">
                                    <i class="fas fa-times me-2"></i>Cancel Edit
                                </a>
                            <?php endif; ?>
                            </div>
                        </form>
                </div>

                <!-- Salary Details Table -->
                <div class="table-container">
                    <h3><i class="fas fa-table me-2"></i>Salary Details Records</h3>
                    
                    <?php if (empty($salary_details)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No salary details found. Add some records to get started.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Program Name</th>
                                        <th>PRN</th>
                                        <th>Student Name</th>
                                        <th>Company Name</th>
                                        <th>Designation</th>
                                        <th>Salary</th>
                                        <th>Job Order</th>
                                        <th>Academic Year</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($salary_details as $salary): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($salary['ID']); ?></td>
                                            <td><?php echo htmlspecialchars($salary['PROGRAM_NAME']); ?></td>
                                            <td><?php echo htmlspecialchars($salary['PRN']); ?></td>
                                            <td><?php echo htmlspecialchars($salary['STUDENT_NAME']); ?></td>
                                            <td><?php echo htmlspecialchars($salary['COMPANY_NAME']); ?></td>
                                            <td><?php echo htmlspecialchars($salary['DESIGNATION']); ?></td>
                                            <td><?php echo htmlspecialchars(number_format((float)$salary['SALARY'], 2)); ?> Lakhs</td>
                                            <td><?php echo htmlspecialchars($salary['JOB_ORDER']); ?></td>
                                            <td><?php echo htmlspecialchars($salary['A_YEAR']); ?></td>
                                            <td>
                                                <a href="SalaryDetails.php?action=edit&id=<?php echo $salary['ID']; ?>" 
                                                   class="btn btn-warning btn-sm btn-action" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button type="button" class="btn btn-danger btn-sm btn-action" title="Delete"
                                                        onclick="deleteSalary(<?php echo $salary['ID']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'unified_footer.php'; ?>

        <script>
// ============================================================================
// AJAX FORM SUBMISSION AND SOFT MESSAGE FUNCTIONS
// ============================================================================

// Show smooth message function
function showSmoothMessage(message, type = 'info') {
    const messageDiv = document.getElementById('soft-message');
    if (!messageDiv) return;
    
    // Set message content and type
    messageDiv.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        <span>${message}</span>
    `;
    messageDiv.className = `soft-message ${type}`;
    messageDiv.style.display = 'flex';
    
    // Auto-hide after 3 seconds
    setTimeout(() => {
        messageDiv.style.display = 'none';
    }, 3000);
}

// Handle form submission with AJAX
document.getElementById('salaryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    
    // Show loading state
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
    submitBtn.disabled = true;
    
    // Prepare form data
    const formData = new FormData(this);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(async (response) => {
        const cloned = response.clone();
        try {
            return await response.json();
        } catch (e) {
            const text = await cloned.text();
            const snippet = (text || '').replace(/<[^>]*>/g, ' ').slice(0, 400);
            throw new Error(snippet || 'Non-JSON response received');
        }
    })
    .then(data => {
        if (data.success) {
            showSmoothMessage(data.message, 'success');
            // Clear form if it's a new record (add mode)
            if (!document.querySelector('input[name="id"]')) {
                this.reset();
                // Clear all input fields for next entry
                const inputs = this.querySelectorAll('input[type="text"], input[type="number"], select');
                inputs.forEach(input => {
                    if (input.name !== 'action') {
                        input.value = '';
                    }
                });
            }
            // Reload page after 2 seconds to show updated data
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            showSmoothMessage(data.message, 'error');
        }
                })
    .catch(error => {
        // Show server response or error message for debugging
        const msg = (error && error.message) ? error.message : 'An error occurred while processing the request.';
        showSmoothMessage(msg, 'error');
    })
    .finally(() => {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});

// Delete salary function
function deleteSalary(id) {
    if (confirm('Are you sure you want to delete this salary detail?')) {
        const formData = new FormData();
        formData.append('action', 'delete_salary');
        formData.append('id', id);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSmoothMessage(data.message, 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
                    } else {
                showSmoothMessage(data.message, 'error');
                    }
                })
        .catch(error => {
            // Error occurred
            showSmoothMessage('An error occurred while deleting the record.', 'error');
        });
    }
}
        </script>
