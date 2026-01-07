<?php
// FacultyDetails - Faculty details form
// Start output buffering at the very beginning
ob_start();

// Set timezone before any date operations
if (!date_default_timezone_get() || date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

require('session.php');

// Academic year calculation - use centralized function
if (file_exists(__DIR__ . '/common_functions.php')) {
    require_once(__DIR__ . '/common_functions.php');
}
if (function_exists('getAcademicYear')) {
    $A_YEAR = getAcademicYear();
} else {
    // Fallback calculation - matches getAcademicYear() logic
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    if ($current_month >= 7) {
        $A_YEAR = $current_year . '-' . ($current_year + 1);
    } else {
        $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
    }
}

$dept = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;

// Get department details for display from department_master table
$dept_query = "SELECT DEPT_COLL_NO, DEPT_NAME FROM department_master WHERE DEPT_ID = ?";
$dept_stmt = mysqli_prepare($conn, $dept_query);
mysqli_stmt_bind_param($dept_stmt, 'i', $dept);
mysqli_stmt_execute($dept_stmt);
$dept_result = mysqli_stmt_get_result($dept_stmt);
$dept_info = mysqli_fetch_assoc($dept_result);
$dept_code = $dept_info['DEPT_COLL_NO'] ?? '';
$dept_name = $dept_info['DEPT_NAME'] ?? '';
if ($dept_result) {
    mysqli_free_result($dept_result);  // CRITICAL: Free result
}
mysqli_stmt_close($dept_stmt);

// ============================================================================
// HANDLE FORM SUBMISSION - MUST BE BEFORE unified_header.php
// ============================================================================

// Handle DELETE via POST (not GET) with CSRF protection
// CRITICAL: Follow all checklist items to prevent crashes and ensure proper responses
if (isset($_POST['delete_faculty']) && isset($_POST['faculty_id'])) {
    // CRITICAL #1: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
        if (function_exists('validate_csrf')) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (empty($csrf) || !validate_csrf($csrf)) {
                // Clear buffers before redirect
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                $_SESSION['error'] = 'Security validation failed. Please refresh and try again.';
                if (!headers_sent()) {
                    header('Location: FacultyDetails.php');
                }
                exit;
            }
        }
    }
    
    $id = (int)$_POST['faculty_id'];
    $delete_stmt = mysqli_prepare($conn, "DELETE FROM faculty_details WHERE ID = ? AND DEPT_ID = ?");
    if (!$delete_stmt) {
        // Clear buffers before redirect
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $_SESSION['error'] = 'Database error: Failed to prepare delete query';
        if (!headers_sent()) {
            header('Location: FacultyDetails.php');
        }
        exit;
    }
    
    mysqli_stmt_bind_param($delete_stmt, 'ii', $id, $dept);
    if (mysqli_stmt_execute($delete_stmt)) {
        mysqli_stmt_close($delete_stmt);  // CRITICAL: Close statement
        // Clear buffers before redirect
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: FacultyDetails.php?success=deleted');
        }
        exit;
    } else {
        $error = mysqli_stmt_error($delete_stmt);
        mysqli_stmt_close($delete_stmt);  // CRITICAL: Close statement
        // Clear buffers before redirect
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $_SESSION['error'] = 'Delete failed: ' . $error;
        if (!headers_sent()) {
            header('Location: FacultyDetails.php?error=delete_failed');
        }
        exit;
    }
}

// Helper function to identify error field from error message
function getErrorField($errorMessage) {
    $errorMessage = strtolower($errorMessage);
    if (strpos($errorMessage, 'mobile') !== false || strpos($errorMessage, 'phone') !== false) {
        return 'mobile_number';
    } elseif (strpos($errorMessage, 'email') !== false) {
        return 'email_id';
    } elseif (strpos($errorMessage, 'date of birth') !== false || strpos($errorMessage, 'dob') !== false) {
        return 'dob_input';
    } elseif (strpos($errorMessage, 'age') !== false) {
        return 'age_input';
    } elseif (strpos($errorMessage, 'experience') !== false) {
        if (strpos($errorMessage, 'teaching') !== false) {
            return 'teaching_exp';
        } elseif (strpos($errorMessage, 'industrial') !== false) {
            return 'industrial_exp';
        }
        return 'experience';
    } elseif (strpos($errorMessage, 'association') !== false) {
        if (strpos($errorMessage, 'type') !== false) {
            return 'association_type';
        } elseif (strpos($errorMessage, 'joining') !== false) {
            return 'date_of_joining';
        }
        return 'previous_year_assoc';
    } elseif (strpos($errorMessage, 'faculty name') !== false || strpos($errorMessage, 'name') !== false) {
        return 'faculty_name';
    }
    return null;
}

// Debug logging - Check if POST is received
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
}

if (isset($_POST['submit'])){
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Suppress any output during POST processing
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    // Clean all output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Ensure database connection is available
    if (!isset($conn) || !$conn) {
        $_SESSION['error'] = 'Database connection failed. Please try again.';
        if (!headers_sent()) {
            header('Location: FacultyDetails.php');
        }
        exit;
    }
    
    // Ensure dept is set
    $dept = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
    if (!$dept) {
        $_SESSION['error'] = 'Department ID not found. Please login again.';
        if (!headers_sent()) {
            header('Location: FacultyDetails.php');
        }
        exit;
    }
    
    // Ensure A_YEAR is set
    if (empty($A_YEAR)) {
        if (function_exists('getAcademicYear')) {
            $A_YEAR = getAcademicYear();
        } else {
            // Fallback calculation - matches getAcademicYear() logic
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            if ($current_month >= 7) {
                $A_YEAR = $current_year . '-' . ($current_year + 1);
            } else {
                $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
            }
        }
    }
    
    // CSRF validation
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
        if (function_exists('validate_csrf')) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (empty($csrf) || !validate_csrf($csrf)) {
                $_SESSION['error'] = 'Security validation failed. Please refresh and try again.';
                if (!headers_sent()) {
                    header('Location: FacultyDetails.php');
                }
                exit;
            }
        }
    }
    
    try {
        // Sanitize and validate inputs
        $Faculty_Name = mysqli_real_escape_string($conn, trim($_POST['Faculty_Name'] ?? ''));
        $gender = mysqli_real_escape_string($conn, trim($_POST['gender'] ?? ''));
        $designation = mysqli_real_escape_string($conn, trim($_POST['designation'] ?? ''));
        $DOB_raw = trim($_POST['DOB'] ?? '');
        // Validate DOB format (YYYY-MM-DD)
        $DOB = '';
        if (!empty($DOB_raw)) {
            // Check if it's in correct format
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $DOB_raw)) {
                // Validate date is valid
                $date_parts = explode('-', $DOB_raw);
                if (checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
                    $DOB = mysqli_real_escape_string($conn, $DOB_raw);
                } else {
                    throw new Exception('Invalid date of birth. Please enter a valid date.');
                }
            } else {
                throw new Exception('Invalid date format. Date must be in YYYY-MM-DD format.');
            }
        } else {
            throw new Exception('Date of birth is required.');
        }
        $Age = (int)($_POST['Age'] ?? 0);
    $qualification = mysqli_real_escape_string($conn, trim($_POST['qualification'] ?? ''));
    $experience = (int)($_POST['experience'] ?? 0);
    $pan_number = mysqli_real_escape_string($conn, trim($_POST['pan_number'] ?? ''));
    $Associated = mysqli_real_escape_string($conn, trim($_POST['Associated'] ?? ''));
    $Teaching = (int)($_POST['Teaching'] ?? 0);
    $Industrial = (int)($_POST['Industrial'] ?? 0);
    $Date_of_joining = mysqli_real_escape_string($conn, trim($_POST['Date_of_joining'] ?? ''));
    $latest_joining = mysqli_real_escape_string($conn, trim($_POST['latest_joining'] ?? ''));
    $Association_Type = mysqli_real_escape_string($conn, trim($_POST['Association_Type'] ?? ''));
    $EmailID_of_Faculty = mysqli_real_escape_string($conn, trim($_POST['EmailID_of_Faculty'] ?? ''));
    $Mobile_Number_of_Faculty = preg_replace('/[^0-9]/', '', trim($_POST['Mobile_Number_of_Faculty'] ?? '')); // Remove non-digits
    
        // Check if we're updating an existing record (before validation to use in redirects)
        $update_id = isset($_POST['faculty_id']) ? (int)$_POST['faculty_id'] : 0;
        
        // Validate mobile number (exactly 10 digits)
        if (strlen($Mobile_Number_of_Faculty) !== 10 || !preg_match('/^[0-9]{10}$/', $Mobile_Number_of_Faculty)) {
            throw new Exception('Invalid mobile number. Please enter exactly 10 digits (numbers only).');
        }
        
        // Validate email
        if (!filter_var($EmailID_of_Faculty, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address. Please enter a valid email format.');
        }
    
        // If Previous Year Association is "No", set association fields to empty/default values
        if ($Associated === 'No') {
            $Association_Type = '';
            $Date_of_joining = '';
            $latest_joining = '';
        } else if ($Associated === 'Yes') {
            // Validate association fields are provided if "Yes"
            if (empty($Association_Type) || empty($Date_of_joining) || empty($latest_joining)) {
                throw new Exception('If associated in previous year (Yes), please fill Association Type, Date of Joining, and Latest Joining Date.');
            }
        }

        // Numeric validations
        if ($Age < 18 || $Age > 100) {
            throw new Exception('Invalid age. Age must be between 18 and 100 years.');
        }
        if ($experience < 0 || $experience > 9999) {
            throw new Exception('Invalid experience. Please enter a valid number (0-9999 months).');
        }
        if ($Teaching < 0 || $Teaching > 9999) {
            throw new Exception('Invalid teaching experience. Please enter a valid number (0-9999 months).');
        }
        if ($Industrial < 0 || $Industrial > 9999) {
            throw new Exception('Invalid industrial experience. Please enter a valid number (0-9999 months).');
        }
        
        if ($update_id > 0) {
        // UPDATE existing record
        // Handle empty dates for "No" association - use a default date that indicates N/A
        $Date_of_joining_db = ($Associated === 'No' || empty($Date_of_joining)) ? '1900-01-01' : $Date_of_joining;
        $latest_joining_db = ($Associated === 'No' || empty($latest_joining)) ? '1900-01-01' : $latest_joining;
        
        $query = "UPDATE faculty_details SET 
            A_YEAR = ?,
            FACULTY_NAME = ?,
            GENDER = ?,
            DESIGNATION = ?,
            DOB = ?,
            AGE = ?,
            QUALIF = ?,
            EXPERIENCE = ?,
            PAN_NUM = ?,
            FACULTY_ASSO_IN_PREV_YEAR = ?,
            FACULTY_EXP_TEACHING = ?,
            FACULTY_EXP_INDUSTRIAL = ?,
            JOINING_INSTITUTE_DATE = ?,
            LATEST_DATE = ?,
            ASSOC_TYPE = ?,
            EMAIL_ID = ?,
            MOBILE_NUM = ?
            WHERE ID = ? AND DEPT_ID = ?";
        
        $stmt = mysqli_prepare($conn, $query);
        if ($stmt) {
            // Types: A_YEAR(s), FACULTY_NAME(s), GENDER(s), DESIGNATION(s), DOB(s), AGE(i), QUALIF(s), EXPERIENCE(i), PAN_NUM(s), FACULTY_ASSO_IN_PREV_YEAR(s), FACULTY_EXP_TEACHING(i), FACULTY_EXP_INDUSTRIAL(i), JOINING_INSTITUTE_DATE(s), LATEST_DATE(s), ASSOC_TYPE(s), EMAIL_ID(s), MOBILE_NUM(s), ID(i), DEPT_ID(i)
            mysqli_stmt_bind_param($stmt, 'sssssisissiisssssii', 
                $A_YEAR, $Faculty_Name, $gender, $designation, $DOB, $Age, $qualification, 
                $experience, $pan_number, $Associated, $Teaching, $Industrial, $Date_of_joining_db, 
                $latest_joining_db, $Association_Type, $EmailID_of_Faculty, $Mobile_Number_of_Faculty, 
                $update_id, $dept);
            
            if (mysqli_stmt_execute($stmt)) {
                $affected_rows = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
                
                // Set session message BEFORE clearing buffers
                $_SESSION['success'] = 'Faculty details updated successfully!';
                
                // Clean buffers and redirect
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                if (!headers_sent()) {
                    header('Location: FacultyDetails.php');
                    exit;
                }
                // Fallback redirect to prevent blank page if headers already sent
        echo '<script>window.location.href = "FacultyDetails.php";</script>';
                exit;
            } else {
                $error = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception('Failed to update faculty details: ' . $error);
            }
        } else {
            throw new Exception('Database error: ' . mysqli_error($conn));
        }
    } else {
        // INSERT new record
        // Handle empty dates for "No" association - use a default date that indicates N/A
        $Date_of_joining_db = ($Associated === 'No' || empty($Date_of_joining)) ? '1900-01-01' : $Date_of_joining;
        $latest_joining_db = ($Associated === 'No' || empty($latest_joining)) ? '1900-01-01' : $latest_joining;
        
        $query = "INSERT INTO `faculty_details`(`A_YEAR`, `DEPT_ID`, `FACULTY_NAME`, `GENDER`, `DESIGNATION`, `DOB`, `AGE`, `QUALIF`, `EXPERIENCE`, `PAN_NUM`, `FACULTY_ASSO_IN_PREV_YEAR`, `FACULTY_EXP_TEACHING`, `FACULTY_EXP_INDUSTRIAL`, `JOINING_INSTITUTE_DATE`, `LATEST_DATE`, `ASSOC_TYPE`, `EMAIL_ID`, `MOBILE_NUM`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = mysqli_prepare($conn, $query);
        if ($stmt) {
            // Types: A_YEAR(s), DEPT_ID(i), FACULTY_NAME(s), GENDER(s), DESIGNATION(s), DOB(s), AGE(i), QUALIF(s), EXPERIENCE(i), PAN_NUM(s), FACULTY_ASSO_IN_PREV_YEAR(s), FACULTY_EXP_TEACHING(i), FACULTY_EXP_INDUSTRIAL(i), JOINING_INSTITUTE_DATE(s), LATEST_DATE(s), ASSOC_TYPE(s), EMAIL_ID(s), MOBILE_NUM(s)
            mysqli_stmt_bind_param($stmt, 'sissssisissiisssss', 
                $A_YEAR, $dept, $Faculty_Name, $gender, $designation, $DOB, $Age, $qualification, 
                $experience, $pan_number, $Associated, $Teaching, $Industrial, $Date_of_joining_db, 
                $latest_joining_db, $Association_Type, $EmailID_of_Faculty, $Mobile_Number_of_Faculty);
            
            if (mysqli_stmt_execute($stmt)) {
                $insert_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
                
                // Set session message BEFORE clearing buffers
                $_SESSION['success'] = 'Faculty details saved successfully!';
                
                // Clean buffers and redirect
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                if (!headers_sent()) {
                    header('Location: FacultyDetails.php');
                    exit;
                }
                // Fallback redirect to prevent blank page if headers already sent
        echo '<script>window.location.href = "FacultyDetails.php";</script>';
                exit;
            } else {
                $error = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception('Failed to save faculty details: ' . $error);
            }
        } else {
            throw new Exception('Database error: ' . mysqli_error($conn));
        }
    }
    
    } catch (Throwable $e) {
        // Store error message and form data in session for display
        $_SESSION['error'] = $e->getMessage();
        $_SESSION['error_field'] = getErrorField($e->getMessage());
        
        // Store all form data to repopulate form after error
        $_SESSION['form_data'] = [
            'Faculty_Name' => $_POST['Faculty_Name'] ?? '',
            'gender' => $_POST['gender'] ?? '',
            'designation' => $_POST['designation'] ?? '',
            'DOB' => $_POST['DOB'] ?? '',
            'Age' => $_POST['Age'] ?? '',
            'qualification' => $_POST['qualification'] ?? '',
            'experience' => $_POST['experience'] ?? '',
            'pan_number' => $_POST['pan_number'] ?? '',
            'Associated' => $_POST['Associated'] ?? '',
            'Teaching' => $_POST['Teaching'] ?? '',
            'Industrial' => $_POST['Industrial'] ?? '',
            'Date_of_joining' => $_POST['Date_of_joining'] ?? '',
            'latest_joining' => $_POST['latest_joining'] ?? '',
            'Association_Type' => $_POST['Association_Type'] ?? '',
            'EmailID_of_Faculty' => $_POST['EmailID_of_Faculty'] ?? '',
            'Mobile_Number_of_Faculty' => $_POST['Mobile_Number_of_Faculty'] ?? '',
            'faculty_id' => $_POST['faculty_id'] ?? 0
        ];
        
        $update_id = isset($_POST['faculty_id']) ? (int)$_POST['faculty_id'] : 0;
        $redirect_url = ($update_id > 0) ? 'FacultyDetails.php?edit_id=' . $update_id . '&error=1' : 'FacultyDetails.php?error=1';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: ' . $redirect_url);
            exit;
        }
        // Fallback redirect to prevent blank page if headers already sent
        echo '<script>window.location.href = "' . $redirect_url . '";</script>';
        exit;
    }
}

// Check if we're in edit mode (before unified_header to avoid output issues)
$edit_id = null;
$edit_data = null;
$is_edit_mode = false;
$form_data = null; // For repopulating form after error

// Check if we have form data from a previous error submission
if (isset($_SESSION['form_data']) && isset($_GET['error'])) {
    $form_data = $_SESSION['form_data'];
    $edit_id = isset($form_data['faculty_id']) ? (int)$form_data['faculty_id'] : 0;
    if ($edit_id > 0) {
        $is_edit_mode = true;
    }
    // Convert form_data to edit_data format for compatibility
    $edit_data = [
        'ID' => $edit_id,
        'FACULTY_NAME' => $form_data['Faculty_Name'] ?? '',
        'GENDER' => $form_data['gender'] ?? '',
        'DESIGNATION' => $form_data['designation'] ?? '',
        'DOB' => $form_data['DOB'] ?? '',
        'AGE' => $form_data['Age'] ?? '',
        'QUALIF' => $form_data['qualification'] ?? '',
        'EXPERIENCE' => $form_data['experience'] ?? '',
        'PAN_NUM' => $form_data['pan_number'] ?? '',
        'FACULTY_ASSO_IN_PREV_YEAR' => $form_data['Associated'] ?? '',
        'FACULTY_EXP_TEACHING' => $form_data['Teaching'] ?? '',
        'FACULTY_EXP_INDUSTRIAL' => $form_data['Industrial'] ?? '',
        'JOINING_INSTITUTE_DATE' => $form_data['Date_of_joining'] ?? '',
        'LATEST_DATE' => $form_data['latest_joining'] ?? '',
        'ASSOC_TYPE' => $form_data['Association_Type'] ?? '',
        'EMAIL_ID' => $form_data['EmailID_of_Faculty'] ?? '',
        'MOBILE_NUM' => $form_data['Mobile_Number_of_Faculty'] ?? ''
    ];
    // Clear form_data from session after using it
    unset($_SESSION['form_data']);
}

if (isset($_GET['edit_id']) && !$is_edit_mode) {
    $edit_id = (int)$_GET['edit_id'];
    $edit_query = "SELECT * FROM faculty_details WHERE ID = ? AND DEPT_ID = ?";
    $edit_stmt = mysqli_prepare($conn, $edit_query);
    mysqli_stmt_bind_param($edit_stmt, 'ii', $edit_id, $dept);
    mysqli_stmt_execute($edit_stmt);
    $edit_result = mysqli_stmt_get_result($edit_stmt);
    $edit_data = mysqli_fetch_assoc($edit_result);
    if ($edit_result) {
        mysqli_free_result($edit_result);  // CRITICAL: Free result
    }
    mysqli_stmt_close($edit_stmt);
    
    if ($edit_data) {
        $is_edit_mode = true;
    } else {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: FacultyDetails.php?error=record_not_found');
        }
        exit;
    }
}

// IMPORTANT: Include unified_header AFTER POST handling and edit mode check to prevent output issues
require('unified_header.php');

// Display session messages
if (isset($_SESSION['success'])) {
    $success_message = $_SESSION['success'];
    unset($_SESSION['success']);
    }
if (isset($_SESSION['error'])) {
    $error_message = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>
<div class="container-fluid" style="max-width: 100%; overflow-x: hidden;">
    <div class="main-content-area">
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-users me-3"></i>Faculty Details
                    </h1>
                    <p class="page-subtitle"><?php echo $is_edit_mode ? 'Edit faculty information and qualifications' : 'Manage faculty information and qualifications'; ?></p>
                </div>
                <a href="export_page_pdf.php?page=FacultyDetails" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
            </div>
        </div>
        
        
        <?php if ($is_edit_mode): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i><strong>Edit Mode:</strong> You are editing an existing faculty record. Make your changes and click "Update Faculty Details" to save.
            </div>
        <?php endif; ?>

        <?php if (isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" id="successAlert">
                <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <script>
                setTimeout(function() {
                    const alert = document.getElementById('successAlert');
                    if (alert) {
                        alert.style.transition = 'opacity 0.5s';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 2000);
            </script>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" id="successAlert">
                <?php 
                    $success_messages = [
                        'saved' => 'Faculty details saved successfully!',
                        'updated' => 'Faculty details updated successfully!',
                        'deleted' => 'Faculty record deleted successfully!'
                    ];
                    echo $success_messages[$_GET['success']] ?? 'Operation completed successfully!';
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <script>
                setTimeout(function() {
                    const alert = document.getElementById('successAlert');
                    if (alert) {
                        alert.style.transition = 'opacity 0.5s';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 2000);
            </script>
        <?php endif; ?>

        <?php 
        $error_field = isset($_SESSION['error_field']) ? $_SESSION['error_field'] : null;
        if (isset($error_message)): 
        ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert" id="errorAlert" style="border-left: 4px solid #dc3545; background-color: #f8d7da;">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle me-2" style="font-size: 1.2rem;"></i>
                    <div class="flex-grow-1">
                        <strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?>
                        <?php if ($error_field): ?>
                            <div class="mt-2 small">Please check the highlighted field below.</div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>
            <script>
                // Scroll to error message and highlight field
                document.addEventListener('DOMContentLoaded', function() {
                    const errorAlert = document.getElementById('errorAlert');
                    if (errorAlert) {
                        errorAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        <?php if ($error_field): ?>
                        const errorField = document.getElementById('<?php echo htmlspecialchars($error_field, ENT_QUOTES); ?>');
                        if (errorField) {
                            setTimeout(function() {
                                errorField.focus();
                                errorField.style.borderColor = '#dc3545';
                                errorField.style.boxShadow = '0 0 0 0.2rem rgba(220, 53, 69, 0.25)';
                                errorField.classList.add('is-invalid');
                                
                                // Remove highlight after 5 seconds
                                setTimeout(function() {
                                    errorField.style.borderColor = '';
                                    errorField.style.boxShadow = '';
                                    errorField.classList.remove('is-invalid');
                                }, 5000);
                            }, 500);
                        }
                        <?php endif; ?>
                    }
                });
            </script>
        <?php 
        unset($_SESSION['error_field']);
        elseif (isset($_GET['error']) && $_GET['error'] !== '1'): 
        ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                    $error_messages = [
                        'save_failed' => 'Failed to save faculty details. Please try again.',
                        'delete_failed' => 'Failed to delete faculty record. Please try again.',
                        'prepare_failed' => 'Database error. Please contact administrator.',
                        'invalid_age' => 'Invalid age. Age must be between 18 and 100 years.',
                        'invalid_experience' => 'Invalid experience. Please enter a valid number (0-9999 months).',
                        'invalid_teaching' => 'Invalid teaching experience. Please enter a valid number (0-9999 months).',
                        'invalid_industrial' => 'Invalid industrial experience. Please enter a valid number (0-9999 months).',
                        'csrf_failed' => 'Security validation failed. Please refresh and try again.',
                        'update_failed' => 'Failed to update faculty details. Please try again.',
                        'record_not_found' => 'Faculty record not found.',
                        'invalid_request' => 'Invalid request.',
                        'invalid_mobile' => 'Invalid mobile number. Please enter exactly 10 digits (numbers only).',
                        'invalid_email' => 'Invalid email address. Please enter a valid email format.',
                        'missing_association_fields' => 'If associated in previous year (Yes), please fill Association Type, Date of Joining, and Latest Joining Date.'
                    ];
                    echo $error_messages[$_GET['error']] ?? 'An error occurred. Please try again.';
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form class="modern-form" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" enctype="multipart/form-data" autocomplete="off" id="facultyForm" novalidate>
                    <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                    <?php if ($is_edit_mode && $edit_data): ?>
                        <input type="hidden" name="faculty_id" value="<?php echo $edit_data['ID']; ?>">
                    <?php endif; ?>
                    
                    <!-- Personal Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-user me-2"></i>Personal Information
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="faculty_name"><b>Faculty Name *</b></label>
                                    <input type="text" name="Faculty_Name" id="faculty_name" class="form-control" placeholder="Enter Full Name" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['FACULTY_NAME'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="gender"><b>Gender *</b></label>
                                    <select name="gender" id="gender" class="form-control" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo ($is_edit_mode && ($edit_data['GENDER'] ?? '') == 'Male') ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($is_edit_mode && ($edit_data['GENDER'] ?? '') == 'Female') ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo ($is_edit_mode && ($edit_data['GENDER'] ?? '') == 'Other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="designation_select"><b>Designation *</b></label>
                                    <select name="designation" class="form-control" required id="designation_select">
                                        <option value="">Select Designation</option>
                                        <option value="PROFESSOR" <?php echo ($is_edit_mode && trim($edit_data['DESIGNATION'] ?? '') == 'PROFESSOR') ? 'selected' : ''; ?>>Professor</option>
                                        <option value="ASSOCIATE PROFESSOR" <?php echo ($is_edit_mode && trim($edit_data['DESIGNATION'] ?? '') == 'ASSOCIATE PROFESSOR') ? 'selected' : ''; ?>>Associate Professor</option>
                                        <option value="ASSISTANT PROFESSOR" <?php echo ($is_edit_mode && trim($edit_data['DESIGNATION'] ?? '') == 'ASSISTANT PROFESSOR') ? 'selected' : ''; ?>>Assistant Professor</option>
                                        <option value="ADJUNCT PROFESSOR" <?php echo ($is_edit_mode && trim($edit_data['DESIGNATION'] ?? '') == 'ADJUNCT PROFESSOR') ? 'selected' : ''; ?>>Adjunct Professor</option>
                                        <option value="CHAIR PROFESSOR" <?php echo ($is_edit_mode && trim($edit_data['DESIGNATION'] ?? '') == 'CHAIR PROFESSOR') ? 'selected' : ''; ?>>Chair Professor</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="pan_number"><b>PAN Number *</b></label>
                                    <input type="text" name="pan_number" id="pan_number" class="form-control" placeholder="Enter PAN Number" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['PAN_NUM'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Date of Birth and Age Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-calendar-alt me-2"></i>Date of Birth & Age
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label" for="day_input"><b>Date of Birth (DD/MM/YYYY) *</b></label>
                                    <div class="row">
                                        <div class="col-4">
                                            <label for="day_input" class="sr-only">Day</label>
                                            <input type="number" id="day_input" class="form-control" placeholder="DD" min="1" max="31" oninput="validateAndCalculateAge()">
                                        </div>
                                        <div class="col-4">
                                            <label for="month_input" class="sr-only">Month</label>
                                            <input type="number" id="month_input" class="form-control" placeholder="MM" min="1" max="12" oninput="validateAndCalculateAge()">
                                        </div>
                                        <div class="col-4">
                                            <label for="year_input" class="sr-only">Year</label>
                                            <input type="number" id="year_input" class="form-control" placeholder="YYYY" min="1900" max="2025" oninput="validateAndCalculateAge()">
                                        </div>
                                    </div>
                                    <input type="hidden" name="DOB" id="dob_input" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['DOB'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                    <div class="form-text">Enter day, month, and year separately. Age will be calculated automatically.</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label" for="age_input"><b>Age (Auto-calculated)</b></label>
                                    <input type="number" name="Age" id="age_input" class="form-control" placeholder="Auto-calculated" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['AGE'] ?? '', ENT_QUOTES) : ''; ?>" readonly>
                                    <div class="form-text">Age is automatically calculated</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Qualifications and Experience Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-graduation-cap me-2"></i>Qualifications & Experience
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="qualification"><b>Highest Qualification *</b></label>
                                    <input type="text" name="qualification" id="qualification" class="form-control" placeholder="e.g., Ph.D., M.Tech, M.Sc" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['QUALIF'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="experience"><b>Total Experience (Months) *</b></label>
                                    <input type="number" name="experience" id="experience" class="form-control" placeholder="Enter experience in months" min="0" max="9999" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['EXPERIENCE'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="teaching_exp"><b>Teaching Experience (Months) *</b></label>
                                    <input type="number" name="Teaching" id="teaching_exp" class="form-control" placeholder="Enter teaching experience" min="0" max="9999" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['FACULTY_EXP_TEACHING'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="industrial_exp"><b>Industrial Experience (Months) *</b></label>
                                    <input type="number" name="Industrial" id="industrial_exp" class="form-control" placeholder="Enter industrial experience" min="0" max="9999" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['FACULTY_EXP_INDUSTRIAL'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Association Details Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-building me-2"></i>Association Details
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="previous_year_assoc"><b>Previous Year Association *</b></label>
                                    <select name="Associated" class="form-control" required id="previous_year_assoc">
                                        <option value="">Select Option</option>
                                        <option value="Yes" <?php echo ($is_edit_mode && ($edit_data['FACULTY_ASSO_IN_PREV_YEAR'] ?? '') == 'Yes') ? 'selected' : ''; ?>>Yes - Associated in 2022-2023</option>
                                        <option value="No" <?php echo ($is_edit_mode && ($edit_data['FACULTY_ASSO_IN_PREV_YEAR'] ?? '') == 'No') ? 'selected' : ''; ?>>No - Not Associated in 2022-2023</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="association_type"><b>Association Type *</b></label>
                                    <select name="Association_Type" class="form-control" id="association_type" <?php echo ($is_edit_mode && ($edit_data['FACULTY_ASSO_IN_PREV_YEAR'] ?? '') == 'No') ? '' : 'required'; ?>>
                                        <option value="">Select Association Type</option>
                                        <option value="REGULAR" <?php echo ($is_edit_mode && ($edit_data['ASSOC_TYPE'] ?? '') == 'REGULAR') ? 'selected' : ''; ?>>Regular</option>
                                        <option value="CONTRACTUAL" <?php echo ($is_edit_mode && ($edit_data['ASSOC_TYPE'] ?? '') == 'CONTRACTUAL') ? 'selected' : ''; ?>>Contractual</option>
                                        <option value="VISITING" <?php echo ($is_edit_mode && ($edit_data['ASSOC_TYPE'] ?? '') == 'VISITING') ? 'selected' : ''; ?>>Visiting</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="date_of_joining"><b>Date of Joining Institute *</b></label>
                                    <input type="date" name="Date_of_joining" id="date_of_joining" class="form-control" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['JOINING_INSTITUTE_DATE'] ?? '', ENT_QUOTES) : ''; ?>" <?php echo ($is_edit_mode && ($edit_data['FACULTY_ASSO_IN_PREV_YEAR'] ?? '') == 'No') ? '' : 'required'; ?>>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="latest_joining"><b>Latest Joining Date *</b></label>
                                    <input type="date" name="latest_joining" id="latest_joining" class="form-control" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['LATEST_DATE'] ?? '', ENT_QUOTES) : ''; ?>" <?php echo ($is_edit_mode && ($edit_data['FACULTY_ASSO_IN_PREV_YEAR'] ?? '') == 'No') ? '' : 'required'; ?>>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-envelope me-2"></i>Contact Information
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="email_id"><b>Official Email ID *</b></label>
                                    <input type="email" name="EmailID_of_Faculty" id="email_id" class="form-control" placeholder="faculty@university.edu" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['EMAIL_ID'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><b>Mobile Number *</b></label>
                                    <input type="tel" name="Mobile_Number_of_Faculty" id="mobile_number" class="form-control" placeholder="10-digit mobile number" pattern="[0-9]{10}" maxlength="10" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['MOBILE_NUM'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                    <div class="form-text">Enter exactly 10 digits</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="text-center mt-4">
                        <?php if ($is_edit_mode): ?>
                            <button type="submit" name="submit" class="btn btn-warning btn-lg" id="submitBtn">
                                <i class="fas fa-save me-2"></i>Update Faculty Details
                            </button>
                            <a href="FacultyDetails.php" class="btn btn-secondary btn-lg">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        <?php else: ?>
                            <button type="submit" name="submit" class="btn btn-primary btn-lg" id="submitBtn">
                            <i class="fas fa-save me-2"></i>Submit Faculty Details
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

    <!-- Show Entered Data -->
    <div class="card">
        <div class="card-body">
            <h3 class="fs-4 mb-3 text-center" id="msg"><b>You Have Entered the Following Data</b></h3>
            <div class="table-responsive" style="max-width: 100%; overflow-x: auto;">
                <table class="table table-hover modern-table" style="min-width: 1800px;">
                    <thead class="table-header">
                        <tr>
                            <th scope="col">Academic Year</th>
                            <th scope="col">Faculty Name</th>
                            <th scope="col">Gender</th>
                            <th scope="col">Designation</th>
                            <th scope="col">Date of Birth</th>
                            <th scope="col">Age</th>
                            <th scope="col">Qualification</th>
                            <th scope="col">Experience</th>
                            <th scope="col">PAN Number</th>
                            <th scope="col">Previous Year</th>
                            <th scope="col">Teaching Exp</th>
                            <th scope="col">Industrial Exp</th>
                            <th scope="col">Joining Date</th>
                            <th scope="col">Latest Date</th>
                            <th scope="col">Association Type</th>
                            <th scope="col">Email ID</th>
                            <th scope="col">Mobile Number</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                $record_query = "SELECT * FROM faculty_details WHERE DEPT_ID = ?";
                $record_stmt = mysqli_prepare($conn, $record_query);
                mysqli_stmt_bind_param($record_stmt, 'i', $dept);
                mysqli_stmt_execute($record_stmt);
                $Record = mysqli_stmt_get_result($record_stmt);
                while ($row = mysqli_fetch_array($Record)) {
                    ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['A_YEAR'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['FACULTY_NAME'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['GENDER'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['DESIGNATION'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['DOB'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['AGE'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['QUALIF'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['EXPERIENCE'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['PAN_NUM'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['FACULTY_ASSO_IN_PREV_YEAR'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['FACULTY_EXP_TEACHING'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['FACULTY_EXP_INDUSTRIAL'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['JOINING_INSTITUTE_DATE'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['LATEST_DATE'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['ASSOC_TYPE'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['EMAIL_ID'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['MOBILE_NUM'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <div class="btn-group" role="group">
                            <a class="btn btn-sm btn-outline-warning" href="FacultyDetails.php?edit_id=<?php echo (int)$row['ID']; ?>">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <form method="POST" style="display:inline;" onsubmit="return confirmDelete(<?php echo (int)$row['ID']; ?>, '<?php echo htmlspecialchars($row['FACULTY_NAME'] ?? '', ENT_QUOTES, 'UTF-8'); ?>');">
                                <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                                <input type="hidden" name="delete_faculty" value="1">
                                <input type="hidden" name="faculty_id" value="<?php echo (int)$row['ID']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php
                    }
                if ($Record) {
                    mysqli_free_result($Record);  // CRITICAL: Free result
                }
                mysqli_stmt_close($record_stmt);
                    ?>                            
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
<style>
    body {
        background: linear-gradient(135deg, #f6f8fa 0%, #e9ecef 100%);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        overflow-x: hidden;
    }

    .container-fluid {
        max-width: 100%;
        margin: 0 auto;
        padding: 0 1rem;
        overflow-x: hidden;
    }

    .div {
        background: #fff;
        border-radius: 1.5rem;
        box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.1);
        padding: 3rem 2.5rem;
        margin-bottom: 2rem;
        border: 1px solid #e3e6ea;
    }

    .form-control {
        border-radius: 0.75rem !important;
        border: 2px solid #e2e8f0 !important;
        background: #fff !important;
        font-size: 1rem;
        padding: 0.75rem 1rem !important;
        transition: all 0.3s ease;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .form-control:focus {
        border-color: #667eea !important;
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25) !important;
        background: #fff !important;
        transform: translateY(-1px);
    }

    .btn {
        min-width: 160px;
        font-weight: 600;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        border-radius: 8px;
        transition: all 0.3s ease;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }

    .alert {
        border-radius: 1rem;
        border: none;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
    }

    .form-text {
        color: #6c757d;
        font-size: 0.875rem;
        margin-top: 0.25rem;
    }

    @media (max-width: 767px) {
        .div {
            padding: 1rem 0.5rem;
        }
    }

    .form-section {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 1rem;
        padding: 2rem;
        margin-bottom: 2rem;
        border: 1px solid rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
    }

    .section-title {
        color: #667eea;
        font-size: 1.5rem;
        font-weight: 600;
        margin-bottom: 1.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #667eea;
        display: flex;
        align-items: center;
    }

    .section-title i {
        color: #764ba2;
        margin-right: 0.5rem;
    }

    /* Error highlighting styles */
    .is-invalid {
        border-color: #dc3545 !important;
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
    }

    .invalid-feedback {
        display: block;
        width: 100%;
        margin-top: 0.25rem;
        font-size: 0.875rem;
        color: #dc3545;
    }

    #errorAlert {
        animation: slideInDown 0.5s ease-out;
    }

    @keyframes slideInDown {
        from {
            transform: translateY(-100%);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .field-error {
        border-left: 3px solid #dc3545;
        padding-left: 0.5rem;
        margin-top: 0.25rem;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function validateAndCalculateAge() {
        const dayInput = document.getElementById('day_input');
        const monthInput = document.getElementById('month_input');
        const yearInput = document.getElementById('year_input');
        const dobInput = document.getElementById('dob_input');
        const ageInput = document.getElementById('age_input');
        
        const day = parseInt(dayInput.value);
        const month = parseInt(monthInput.value);
        const year = parseInt(yearInput.value);
        
        // Clear previous values if any field is empty
        if (!dayInput.value || !monthInput.value || !yearInput.value) {
            dobInput.value = '';
            ageInput.value = '';
            return;
        }
        
        // Validate day
        if (day < 1 || day > 31) {
            showNotification('Please enter a valid day (1-31)', 'warning');
            dayInput.focus();
            return;
        }
        
        // Validate month
        if (month < 1 || month > 12) {
            showNotification('Please enter a valid month (1-12)', 'warning');
            monthInput.focus();
            return;
        }
        
        // Validate year
        if (year < 1900 || year > new Date().getFullYear()) {
            showNotification('Please enter a valid year (1900-' + new Date().getFullYear() + ')', 'warning');
            yearInput.focus();
            return;
        }
        
        // Validate date exists (e.g., Feb 30 doesn't exist)
        const testDate = new Date(year, month - 1, day);
        if (testDate.getDate() !== day || testDate.getMonth() !== month - 1 || testDate.getFullYear() !== year) {
            showNotification('Please enter a valid date', 'warning');
            dayInput.focus();
            return;
        }
        
        // Check if date is in the future
        const today = new Date();
        if (testDate > today) {
            showNotification('Date of birth cannot be in the future', 'warning');
            yearInput.focus();
            return;
        }
        
        // Calculate age
        let age = today.getFullYear() - year;
        const monthDiff = today.getMonth() - (month - 1);
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < day)) {
            age--;
        }
        
        // Validate age range
        if (age < 18) {
            showNotification('Age must be at least 18 years', 'warning');
            yearInput.focus();
            return;
        }
        
        if (age > 100) {
            showNotification('Please enter a valid date of birth (age cannot exceed 100)', 'warning');
            yearInput.focus();
            return;
        }
        
        // Set the formatted date for database
        const formattedDate = year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        dobInput.value = formattedDate;
        ageInput.value = age;
        
        // Add success animation
        ageInput.classList.add('success-animation');
        setTimeout(() => {
            ageInput.classList.remove('success-animation');
        }, 600);
    }

    function showNotification(message, type = 'info') {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.notification');
        existingNotifications.forEach(notification => notification.remove());

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            color: white;
            font-weight: 600;
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            max-width: 300px;
        `;

        // Set background color based on type
        const colors = {
            success: 'linear-gradient(135deg, #28a745, #20c997)',
            error: 'linear-gradient(135deg, #dc3545, #e74c3c)',
            warning: 'linear-gradient(135deg, #ffc107, #f39c12)',
            info: 'linear-gradient(135deg, #17a2b8, #3498db)'
        };
        notification.style.background = colors[type] || colors.info;

        notification.innerHTML = `
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: white; font-size: 1.2rem; cursor: pointer; margin-left: auto;">×</button>
            </div>
        `;

        // Add to page
        document.body.appendChild(notification);

        // Animate in
        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 100);

        // Auto remove after 3 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }
        }, 3000);
    }

    // REWRITTEN: Completely rewritten form submission handler - SIMPLE AND DIRECT
    (function wireFacultyFormSubmission(){
        function attachHandlers() {
            const form = document.getElementById('facultyForm');
            const submitBtn = document.getElementById('submitBtn');
            
            if (!form) {
                return;
            }
            
            if (!submitBtn) {
                return;
            }
            
            
            // Handle submit button click - ensure button type is submit
            submitBtn.addEventListener('click', function(e){
                
                // Ensure button type is submit (should already be set in HTML)
                if (submitBtn.type !== 'submit') {
                    submitBtn.type = 'submit';
                }
            });
            
            // Handle form submit event - validate here (this is the correct place)
            form.addEventListener('submit', function(e){
                
                // CRITICAL: Re-enable ALL disabled fields and remove readonly BEFORE validation/submission
                // Disabled/readonly fields don't submit their values to the server!
                const allInputs = form.querySelectorAll('input, select, textarea');
                let removedCount = 0;
                allInputs.forEach(input => {
                    if (input.hasAttribute('readonly') || input.readOnly) {
                        input.removeAttribute('readonly');
                        input.readOnly = false;
                        removedCount++;
                    }
                    if (input.hasAttribute('disabled') || input.disabled) {
                        input.disabled = false;
                        input.removeAttribute('disabled');
                        removedCount++;
                    }
                });
                
                // Ensure DOB hidden field is set from date inputs BEFORE validation
        const dayInput = document.getElementById('day_input');
        const monthInput = document.getElementById('month_input');
        const yearInput = document.getElementById('year_input');
        const dobInput = document.getElementById('dob_input');
        const ageInput = document.getElementById('age_input');
        
                // If date inputs are filled, update DOB and calculate age
                if (dayInput && dayInput.value && monthInput && monthInput.value && yearInput && yearInput.value) {
                    const day = parseInt(dayInput.value, 10);
                    const month = parseInt(monthInput.value, 10);
                    const year = parseInt(yearInput.value, 10);
                    if (day && month && year && dobInput) {
                        const formattedDate = year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
                        dobInput.value = formattedDate;
                        
                        // Calculate age if not set
                        if (ageInput && (!ageInput.value || ageInput.value === '0')) {
                            if (typeof validateAndCalculateAge === 'function') {
                                validateAndCalculateAge();
                            }
                        }
                    }
        }
        
                // Basic validation - check required fields
                const facultyName = document.getElementById('faculty_name');
                if (!facultyName || !facultyName.value || !facultyName.value.trim()) {
                    e.preventDefault();
                    showNotification('Please enter the faculty name', 'warning');
                    if (facultyName) facultyName.focus();
            return false;
        }
                
                const email = document.getElementById('email_id');
                if (!email || !email.value) {
                    e.preventDefault();
                    showNotification('Please enter an email address', 'warning');
                    if (email) email.focus();
            return false;
        }
        
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email.value)) {
                    e.preventDefault();
            showNotification('Please enter a valid email address', 'warning');
                    if (email) email.focus();
            return false;
        }
        
                const mobile = document.getElementById('mobile_number');
                if (!mobile || !mobile.value || mobile.value.length !== 10 || !/^[0-9]{10}$/.test(mobile.value)) {
                    e.preventDefault();
            showNotification('Please enter a valid 10-digit mobile number', 'warning');
                    if (mobile) mobile.focus();
            return false;
        }
                
                // Check DOB - must have either date inputs filled OR DOB hidden field set
                const hasDateInputs = dayInput && dayInput.value && monthInput && monthInput.value && yearInput && yearInput.value;
                const hasDobValue = dobInput && dobInput.value && dobInput.value.trim() !== '' && dobInput.value !== '0000-00-00';
                
                if (!hasDateInputs && !hasDobValue) {
                    e.preventDefault();
                    showNotification('Please enter complete date of birth (DD/MM/YYYY)', 'warning');
                    if (dayInput) dayInput.focus();
                    return false;
    }
                
                // Handle "No" association - clear those fields
                const prevYearAssoc = document.getElementById('previous_year_assoc');
                if (prevYearAssoc && prevYearAssoc.value === 'No') {
                    const assocType = document.getElementById('association_type');
                    const dateOfJoining = document.getElementById('date_of_joining');
                    const latestJoining = document.getElementById('latest_joining');
                    
                    if (assocType) assocType.value = '';
                    if (dateOfJoining) dateOfJoining.value = '';
                    if (latestJoining) latestJoining.value = '';
                }
                
                
                // Update button text to show submission in progress
                const originalHtml = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
                
                // Log all form data that will be submitted
                const formData = new FormData(form);
                
                // IMPORTANT: Do NOT call e.preventDefault() - allow natural form submission
                // The form will POST to the server now
                
                // Disable button AFTER allowing form to submit (prevent double submission)
                setTimeout(function() {
                    submitBtn.disabled = true;
                }, 100);
            });
            
        }
        
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', attachHandlers);
        } else {
            attachHandlers();
        }
    })();
    
    // Initialize form on page load
    document.addEventListener('DOMContentLoaded', function() {
        
        // Initialize form validation
        // Add input event listeners for real-time validation
        const dobInput = document.getElementById('dob_input');
        if (dobInput) {
            dobInput.addEventListener('change', function() {
                validateAndCalculateAge();
            });
        }
        
        // If in edit mode, populate date fields from DOB
        <?php if ($is_edit_mode && !empty($edit_data['DOB'])): ?>
        const dobValue = '<?php echo $edit_data['DOB']; ?>';
        if (dobValue) {
            const dobParts = dobValue.split('-');
            if (dobParts.length === 3) {
                const yearInput = document.getElementById('year_input');
                const monthInput = document.getElementById('month_input');
                const dayInput = document.getElementById('day_input');
                if (yearInput && monthInput && dayInput) {
                    yearInput.value = dobParts[0];
                    monthInput.value = parseInt(dobParts[1]); // Remove leading zeros
                    dayInput.value = parseInt(dobParts[2]); // Remove leading zeros
                    // Trigger age calculation
                    setTimeout(() => {
                        validateAndCalculateAge();
                    }, 100);
                }
            }
        }
        <?php endif; ?>
        
        // Handle Previous Year Association change
        const prevYearAssoc = document.getElementById('previous_year_assoc');
        const assocType = document.getElementById('association_type');
        const dateOfJoining = document.getElementById('date_of_joining');
        const latestJoining = document.getElementById('latest_joining');
        
        function toggleAssociationFields() {
            if (!prevYearAssoc || !assocType || !dateOfJoining || !latestJoining) {
                return;
            }
            
            if (prevYearAssoc.value === 'No') {
                // Disable fields and remove required attribute
                assocType.disabled = true;
                assocType.removeAttribute('required');
                assocType.value = '';
                dateOfJoining.disabled = true;
                dateOfJoining.removeAttribute('required');
                dateOfJoining.value = '';
                latestJoining.disabled = true;
                latestJoining.removeAttribute('required');
                latestJoining.value = '';
                
                // Add visual styling
                assocType.style.backgroundColor = '#f8f9fa';
                assocType.style.cursor = 'not-allowed';
                dateOfJoining.style.backgroundColor = '#f8f9fa';
                dateOfJoining.style.cursor = 'not-allowed';
                latestJoining.style.backgroundColor = '#f8f9fa';
                latestJoining.style.cursor = 'not-allowed';
            } else {
                // Enable fields and add required attribute
                assocType.disabled = false;
                assocType.setAttribute('required', 'required');
                dateOfJoining.disabled = false;
                dateOfJoining.setAttribute('required', 'required');
                latestJoining.disabled = false;
                latestJoining.setAttribute('required', 'required');
                
                // Remove visual styling
                assocType.style.backgroundColor = '';
                assocType.style.cursor = '';
                dateOfJoining.style.backgroundColor = '';
                dateOfJoining.style.cursor = '';
                latestJoining.style.backgroundColor = '';
                latestJoining.style.cursor = '';
            }
        }
        
        // Set initial state based on current value
        if (prevYearAssoc && assocType && dateOfJoining && latestJoining) {
            toggleAssociationFields();
            
            // Add event listener
            prevYearAssoc.addEventListener('change', toggleAssociationFields);
        } else {
        }
        
        // Mobile number input restriction - only numbers, max 10 digits
        const mobileInput = document.getElementById('mobile_number');
        if (mobileInput) {
            mobileInput.addEventListener('input', function(e) {
                // Remove any non-digit characters
                this.value = this.value.replace(/[^0-9]/g, '');
                // Limit to 10 digits
                if (this.value.length > 10) {
                    this.value = this.value.slice(0, 10);
                }
            });
        }
    });

    function confirmDelete(id, name) {
        if (confirm('Are you sure you want to delete faculty member "' + name + '"? This action cannot be undone.')) {
            showNotification('Deleting faculty record...', 'info');
            return true;
        }
        return false;
    }


    // Smooth message function for success notifications
    function showSmoothMessage(message, type = 'success') {
        // Remove existing messages
        const existing = document.querySelectorAll('.smooth-message');
        existing.forEach(msg => msg.remove());

        const messageDiv = document.createElement('div');
        messageDiv.className = `smooth-message smooth-message-${type}`;
        messageDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            color: white;
            font-weight: 600;
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            max-width: 400px;
        `;

        const colors = {
            success: 'linear-gradient(135deg, #28a745, #20c997)',
            error: 'linear-gradient(135deg, #dc3545, #e74c3c)',
            warning: 'linear-gradient(135deg, #ffc107, #f39c12)',
            info: 'linear-gradient(135deg, #17a2b8, #3498db)'
        };
        messageDiv.style.background = colors[type] || colors.success;

        messageDiv.innerHTML = `
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: white; font-size: 1.2rem; cursor: pointer; margin-left: auto;">×</button>
            </div>
        `;

        document.body.appendChild(messageDiv);

        setTimeout(() => {
            messageDiv.style.transform = 'translateX(0)';
        }, 100);

        setTimeout(() => {
            if (messageDiv.parentNode) {
                messageDiv.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (messageDiv.parentNode) {
                        messageDiv.remove();
                    }
                }, 300);
            }
        }, 2000);
    }
</script>

<?php
require "unified_footer.php";
?>





