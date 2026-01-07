<?php
// AcademicPeers - Academic Peers/Outbound Faculty form
// Start output buffering at the very beginning
ob_start();

// Set timezone before any date operations
if (!date_default_timezone_get() || date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

require('session.php');

// Load common functions for getAcademicYear()
if (file_exists(__DIR__ . '/common_functions.php')) {
    require_once(__DIR__ . '/common_functions.php');
}

// Academic year - use centralized function
if (!function_exists('getAcademicYear')) {
    // Fallback if function not loaded
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    if ($current_month >= 7) {
        $A_YEAR = $current_year . '-' . ($current_year + 1);
    } else {
        $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
    }
} else {
    $A_YEAR = getAcademicYear();
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

// ✅ Handle skip_form_complete action FIRST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'skip_form_complete') {
    // Clear output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Ensure userInfo is available
    if (!isset($userInfo) && isset($_SESSION['userInfo'])) {
        $userInfo = $_SESSION['userInfo'];
    }
    
    // Handle skip action
    require_once(__DIR__ . '/skip_form_component.php');
    // skip_form_component.php will handle and exit
}

// Handle DELETE via POST (not GET) with CSRF protection
if (isset($_POST['delete_peer']) && isset($_POST['peer_id'])) {
    // CRITICAL #1: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    // CRITICAL: Ensure database connection exists
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $_SESSION['error'] = 'Database connection unavailable. Please refresh the page and try again.';
        if (!headers_sent()) {
            header('Location: AcademicPeers.php');
        }
        exit;
    }
    
    // CRITICAL: Ensure dept_id is set
    $dept = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
    if (!$dept) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $_SESSION['error'] = 'Department ID not found. Please login again.';
        if (!headers_sent()) {
            header('Location: AcademicPeers.php');
        }
        exit;
    }
    
    // CRITICAL: Validate CSRF token
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
        if (function_exists('validate_csrf')) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (empty($csrf) || !validate_csrf($csrf)) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                $_SESSION['error'] = 'Security validation failed. Please refresh and try again.';
                if (!headers_sent()) {
                    header('Location: AcademicPeers.php');
                }
                exit;
            }
        }
    }
    
    // CRITICAL: Validate and sanitize peer_id
    $id = (int)($_POST['peer_id'] ?? 0);
    if ($id <= 0) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $_SESSION['error'] = 'Invalid record ID. Please try again.';
        if (!headers_sent()) {
            header('Location: AcademicPeers.php');
        }
        exit;
    }
    
    // CRITICAL: Verify ownership before delete (check if record exists and belongs to dept)
    $verify_query = "SELECT ID FROM academic_peers WHERE ID = ? AND DEPT_ID = ? LIMIT 1";
    $verify_stmt = mysqli_prepare($conn, $verify_query);
    if (!$verify_stmt) {
        $db_error = mysqli_error($conn);
        error_log("AcademicPeers delete verify prepare error: " . $db_error);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $_SESSION['error'] = 'Database error occurred. Please try again.';
        if (!headers_sent()) {
            header('Location: AcademicPeers.php');
        }
        exit;
    }
    
    mysqli_stmt_bind_param($verify_stmt, 'ii', $id, $dept);
    if (!mysqli_stmt_execute($verify_stmt)) {
        $error = mysqli_stmt_error($verify_stmt);
        error_log("AcademicPeers delete verify execution error: " . $error);
        mysqli_stmt_close($verify_stmt);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $_SESSION['error'] = 'Database error occurred. Please try again.';
        if (!headers_sent()) {
            header('Location: AcademicPeers.php');
        }
        exit;
    }
    
    $verify_result = mysqli_stmt_get_result($verify_stmt);
    if (!$verify_result || mysqli_num_rows($verify_result) === 0) {
        if ($verify_result) {
            mysqli_free_result($verify_result);
        }
        mysqli_stmt_close($verify_stmt);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $_SESSION['error'] = 'Record not found or access denied.';
        if (!headers_sent()) {
            header('Location: AcademicPeers.php');
        }
        exit;
    }
    
    mysqli_free_result($verify_result);
    mysqli_stmt_close($verify_stmt);
    
    // CRITICAL: Now perform the delete
    $delete_stmt = mysqli_prepare($conn, "DELETE FROM academic_peers WHERE ID = ? AND DEPT_ID = ?");
    if (!$delete_stmt) {
        $db_error = mysqli_error($conn);
        error_log("AcademicPeers delete prepare error: " . $db_error);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $_SESSION['error'] = 'Database error occurred. Please try again.';
        if (!headers_sent()) {
            header('Location: AcademicPeers.php');
        }
        exit;
    }
    
    mysqli_stmt_bind_param($delete_stmt, 'ii', $id, $dept);
    if (mysqli_stmt_execute($delete_stmt)) {
        $affected_rows = mysqli_stmt_affected_rows($delete_stmt);
        mysqli_stmt_close($delete_stmt);
        
        if ($affected_rows > 0) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $_SESSION['success'] = 'Academic peer record deleted successfully!';
            if (!headers_sent()) {
                header('Location: AcademicPeers.php?success=deleted');
            }
            exit;
        } else {
            // No rows affected (shouldn't happen after verification, but handle gracefully)
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $_SESSION['error'] = 'Record not found or already deleted.';
            if (!headers_sent()) {
                header('Location: AcademicPeers.php');
            }
            exit;
        }
    } else {
        // CRITICAL: Log error but don't expose internal details
        $error = mysqli_stmt_error($delete_stmt);
        error_log("AcademicPeers delete execution error: " . $error);
        mysqli_stmt_close($delete_stmt);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // CRITICAL: Use generic error message, don't expose database error
        $_SESSION['error'] = 'Failed to delete record. Please try again.';
        if (!headers_sent()) {
            header('Location: AcademicPeers.php');
        }
        exit;
    }
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
            header('Location: AcademicPeers.php');
        }
        exit;
    }
    
    // Ensure dept is set
    $dept = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
    if (!$dept) {
        $_SESSION['error'] = 'Department ID not found. Please login again.';
        if (!headers_sent()) {
            header('Location: AcademicPeers.php');
        }
        exit;
    }
    
    // Ensure A_YEAR is set
    if (empty($A_YEAR)) {
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
    }
    
    // CSRF validation
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
        if (function_exists('validate_csrf')) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (empty($csrf) || !validate_csrf($csrf)) {
                $_SESSION['error'] = 'Security validation failed. Please refresh and try again.';
                if (!headers_sent()) {
                    header('Location: AcademicPeers.php');
                }
                exit;
            }
        }
    }
    
    try {
        // CRITICAL: With prepared statements, mysqli_real_escape_string is NOT needed
        // Just trim and validate - prepared statements handle SQL injection protection
        // Sanitize and validate inputs
        $source = trim($_POST['source'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $job_title = trim($_POST['job_title'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $institution = trim($_POST['institution'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        // Check if we're updating an existing record
        $update_id = isset($_POST['peer_id']) ? (int)$_POST['peer_id'] : 0;
        
        // Validate email
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address. Please enter a valid email format.');
        }
        
        // Validate required fields
        if (empty($first_name)) {
            throw new Exception('First name is required.');
        }
        if (empty($last_name)) {
            throw new Exception('Last name is required.');
        }
        if (empty($institution)) {
            throw new Exception('Institution name is required.');
        }
        if (empty($country)) {
            throw new Exception('Country or Territory is required.');
        }
        
        if ($update_id > 0) {
            // UPDATE existing record
            $query = "UPDATE academic_peers SET 
                A_YEAR = ?,
                SOURCE = ?,
                TITLE = ?,
                FIRST_NAME = ?,
                LAST_NAME = ?,
                JOB_TITLE = ?,
                DEPARTMENT = ?,
                INSTITUTION = ?,
                COUNTRY = ?,
                EMAIL = ?,
                SUBJECT = ?,
                PHONE = ?
                WHERE ID = ? AND DEPT_ID = ?";
            
            $stmt = mysqli_prepare($conn, $query);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssssssssssssii', 
                    $A_YEAR, $source, $title, $first_name, $last_name, $job_title, 
                    $department, $institution, $country, $email, $subject, $phone,
                    $update_id, $dept);
                
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    $_SESSION['success'] = 'Academic peer details updated successfully!';
                    
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Location: AcademicPeers.php');
                        exit;
                    }
                    echo '<script>window.location.href = "AcademicPeers.php";</script>';
                    exit;
                } else {
                    // CRITICAL: Log error for debugging
                    $error = mysqli_stmt_error($stmt);
                    error_log("AcademicPeers update error: " . $error);
                    mysqli_stmt_close($stmt);
                    throw new Exception('Failed to update academic peer details. Please try again.');
                }
            } else {
                // CRITICAL: Log error but don't expose internal details
                $db_error = mysqli_error($conn);
                error_log("AcademicPeers update prepare error: " . $db_error);
                throw new Exception('Database error occurred. Please try again.');
            }
        } else {
            // INSERT new record
            $query = "INSERT INTO academic_peers (A_YEAR, DEPT_ID, SOURCE, TITLE, FIRST_NAME, LAST_NAME, JOB_TITLE, DEPARTMENT, INSTITUTION, COUNTRY, EMAIL, SUBJECT, PHONE) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = mysqli_prepare($conn, $query);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'sisssssssssss', 
                    $A_YEAR, $dept, $source, $title, $first_name, $last_name, $job_title, 
                    $department, $institution, $country, $email, $subject, $phone);
                
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    $_SESSION['success'] = 'Academic peer details saved successfully!';
                    
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Location: AcademicPeers.php');
                        exit;
                    }
                    echo '<script>window.location.href = "AcademicPeers.php";</script>';
                    exit;
                } else {
                    // CRITICAL: Log error for debugging
                    $error = mysqli_stmt_error($stmt);
                    error_log("AcademicPeers insert error: " . $error);
                    mysqli_stmt_close($stmt);
                    throw new Exception('Failed to save academic peer details. Please try again.');
                }
            } else {
                // CRITICAL: Log error but don't expose internal details
                $db_error = mysqli_error($conn);
                error_log("AcademicPeers insert prepare error: " . $db_error);
                throw new Exception('Database error occurred. Please try again.');
            }
        }
        
    } catch (Throwable $e) {
        // CRITICAL: Log error for debugging but don't expose internal details to users
        error_log("AcademicPeers form submission error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        // CRITICAL: Don't expose internal error messages - use generic message
        // Only show user-friendly validation errors, not database/internal errors
        $error_message = $e->getMessage();
        // If it's a validation error (user input), show it; otherwise use generic message
        if (strpos($error_message, 'required') !== false || 
            strpos($error_message, 'Invalid') !== false ||
            strpos($error_message, 'must be') !== false) {
            // User-friendly validation error - safe to show
            $_SESSION['error'] = $error_message;
        } else {
            // Internal/database error - use generic message
            $_SESSION['error'] = 'An error occurred while saving. Please try again.';
        }
        
        $update_id = isset($_POST['peer_id']) ? (int)$_POST['peer_id'] : 0;
        $redirect_url = ($update_id > 0) ? 'AcademicPeers.php?edit_id=' . $update_id : 'AcademicPeers.php';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: ' . $redirect_url);
            exit;
        }
        echo '<script>window.location.href = "' . htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8') . '";</script>';
        exit;
    }
}

// Check if we're in edit mode
$edit_id = null;
$edit_data = null;
$is_edit_mode = false;

if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $edit_query = "SELECT * FROM academic_peers WHERE ID = ? AND DEPT_ID = ?";
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
            header('Location: AcademicPeers.php?error=record_not_found');
        }
        exit;
    }
}

// IMPORTANT: Include unified_header AFTER POST handling
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
                        <i class="fas fa-user-graduate me-3"></i>Academic Peers
                    </h1>
                    <p class="page-subtitle"><?php echo $is_edit_mode ? 'Edit academic peer information' : 'Manage outbound faculty and academic collaborators'; ?></p>
                </div>
                <a href="export_page_pdf.php?page=AcademicPeers" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
            </div>
        </div>
        
        <?php 
        // Display Skip Form Button for departments with NO academic peer data
        require_once(__DIR__ . '/skip_form_component.php');
        $check_existing_query = "SELECT id FROM academic_peers WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
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
        displaySkipFormButton('academic_peers', 'Academic Peers', $A_YEAR, $has_existing_data);
        ?>
        
        <?php if ($is_edit_mode): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i><strong>Edit Mode:</strong> You are editing an existing academic peer record. Make your changes and click "Update Details" to save.
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
                        'saved' => 'Academic peer details saved successfully!',
                        'updated' => 'Academic peer details updated successfully!',
                        'deleted' => 'Academic peer record deleted successfully!'
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

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                    $error_messages = [
                        'save_failed' => 'Failed to save academic peer details. Please try again.',
                        'delete_failed' => 'Failed to delete academic peer record. Please try again.',
                        'record_not_found' => 'Academic peer record not found.',
                        'invalid_email' => 'Invalid email address. Please enter a valid email format.'
                    ];
                    echo $error_messages[$_GET['error']] ?? 'An error occurred. Please try again.';
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form class="modern-form" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" enctype="multipart/form-data" autocomplete="off" id="peerForm" novalidate>
                    <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                    <?php if ($is_edit_mode && $edit_data): ?>
                        <input type="hidden" name="peer_id" value="<?php echo $edit_data['ID']; ?>">
                    <?php endif; ?>
                    
                    <!-- Basic Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-user me-2"></i>Basic Information
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="source"><b>Source *</b></label>
                                    <input type="text" name="source" id="source" class="form-control" placeholder="e.g., University" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['SOURCE'] ?? '', ENT_QUOTES) : 'University'; ?>" required>
                                    <div class="form-text">Institution source or type</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="title"><b>Title *</b></label>
                                    <select name="title" id="title" class="form-control" required>
                                        <option value="">Select Title</option>
                                        <option value="Dr" <?php echo ($is_edit_mode && ($edit_data['TITLE'] ?? '') == 'Dr') ? 'selected' : ''; ?>>Dr</option>
                                        <option value="Prof" <?php echo ($is_edit_mode && ($edit_data['TITLE'] ?? '') == 'Prof') ? 'selected' : ''; ?>>Prof</option>
                                        <option value="Mr" <?php echo ($is_edit_mode && ($edit_data['TITLE'] ?? '') == 'Mr') ? 'selected' : ''; ?>>Mr</option>
                                        <option value="Ms" <?php echo ($is_edit_mode && ($edit_data['TITLE'] ?? '') == 'Ms') ? 'selected' : ''; ?>>Ms</option>
                                        <option value="Mrs" <?php echo ($is_edit_mode && ($edit_data['TITLE'] ?? '') == 'Mrs') ? 'selected' : ''; ?>>Mrs</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="first_name"><b>First Name *</b></label>
                                    <input type="text" name="first_name" id="first_name" class="form-control" placeholder="Enter First Name" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['FIRST_NAME'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="last_name"><b>Last Name *</b></label>
                                    <input type="text" name="last_name" id="last_name" class="form-control" placeholder="Enter Last Name" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['LAST_NAME'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Professional Details Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-briefcase me-2"></i>Professional Details
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="job_title"><b>Job Title/Designation *</b></label>
                                    <select name="job_title" id="job_title" class="form-control" required>
                                        <option value="">Select Job Title</option>
                                        <option value="Scientific" <?php echo ($is_edit_mode && ($edit_data['JOB_TITLE'] ?? '') == 'Scientific') ? 'selected' : ''; ?>>Scientific</option>
                                        <option value="PROFESSOR" <?php echo ($is_edit_mode && ($edit_data['JOB_TITLE'] ?? '') == 'PROFESSOR') ? 'selected' : ''; ?>>Professor</option>
                                        <option value="ASSOCIATE PROFESSOR" <?php echo ($is_edit_mode && ($edit_data['JOB_TITLE'] ?? '') == 'ASSOCIATE PROFESSOR') ? 'selected' : ''; ?>>Associate Professor</option>
                                        <option value="ASSISTANT PROFESSOR" <?php echo ($is_edit_mode && ($edit_data['JOB_TITLE'] ?? '') == 'ASSISTANT PROFESSOR') ? 'selected' : ''; ?>>Assistant Professor</option>
                                        <option value="ADJUNCT PROFESSOR" <?php echo ($is_edit_mode && ($edit_data['JOB_TITLE'] ?? '') == 'ADJUNCT PROFESSOR') ? 'selected' : ''; ?>>Adjunct Professor</option>
                                        <option value="CHAIR PROFESSOR" <?php echo ($is_edit_mode && ($edit_data['JOB_TITLE'] ?? '') == 'CHAIR PROFESSOR') ? 'selected' : ''; ?>>Chair Professor</option>
                                        <option value="Researcher" <?php echo ($is_edit_mode && ($edit_data['JOB_TITLE'] ?? '') == 'Researcher') ? 'selected' : ''; ?>>Researcher</option>
                                        <option value="Lecturer" <?php echo ($is_edit_mode && ($edit_data['JOB_TITLE'] ?? '') == 'Lecturer') ? 'selected' : ''; ?>>Lecturer</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="department"><b>Department *</b></label>
                                    <input type="text" name="department" id="department" class="form-control" placeholder="e.g., Chemistry, Management" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['DEPARTMENT'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label" for="subject"><b>Subject/Specialization *</b></label>
                                    <input type="text" name="subject" id="subject" class="form-control" placeholder="e.g., Chemistry, Management, Computer Science" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['SUBJECT'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Institution Details Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-building me-2"></i>Institution Details
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label" for="institution"><b>Institution Name *</b></label>
                                    <input type="text" name="institution" id="institution" class="form-control" placeholder="Enter Institution/University Name" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['INSTITUTION'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                    <div class="form-text">Full name of the collaborating institution</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label" for="country"><b>Country or Territory *</b></label>
                                    <input type="text" name="country" id="country" class="form-control" placeholder="e.g., India, United States, United Kingdom" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['COUNTRY'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                    <div class="form-text">Enter the country or territory name</div>
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
                                    <label class="form-label" for="email"><b>Email Address *</b></label>
                                    <input type="email" name="email" id="email" class="form-control" placeholder="peer@institution.edu" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['EMAIL'] ?? '', ENT_QUOTES) : ''; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="phone"><b>Phone Number (Optional)</b></label>
                                    <input type="tel" name="phone" id="phone" class="form-control" placeholder="Contact number" value="<?php echo $is_edit_mode ? htmlspecialchars($edit_data['PHONE'] ?? '', ENT_QUOTES) : ''; ?>">
                                    <div class="form-text">Optional contact number</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="text-center mt-4">
                        <?php if ($is_edit_mode): ?>
                            <button type="submit" name="submit" class="btn btn-warning btn-lg" id="submitBtn">
                                <i class="fas fa-save me-2"></i>Update Details
                            </button>
                            <a href="AcademicPeers.php" class="btn btn-secondary btn-lg">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        <?php else: ?>
                            <button type="submit" name="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                <i class="fas fa-save me-2"></i>Submit Details
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Show Entered Data -->
        <div class="card">
            <div class="card-body">
                <h3 class="fs-4 mb-3 text-center" id="msg"><b>Academic Peers Records</b></h3>
                <div class="table-responsive" style="max-width: 100%; overflow-x: auto;">
                    <table class="table table-hover modern-table" style="min-width: 1600px;">
                        <thead class="table-header">
                            <tr>
                                <th scope="col">Academic Year</th>
                                <th scope="col">Source</th>
                                <th scope="col">Title</th>
                                <th scope="col">First Name</th>
                                <th scope="col">Last Name</th>
                                <th scope="col">Job Title</th>
                                <th scope="col">Department</th>
                                <th scope="col">Institution</th>
                                <th scope="col">Country</th>
                                <th scope="col">Email</th>
                                <th scope="col">Subject</th>
                                <th scope="col">Phone</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $record_query = "SELECT * FROM academic_peers WHERE DEPT_ID = ? ORDER BY ID DESC";
                            $record_stmt = mysqli_prepare($conn, $record_query);
                            mysqli_stmt_bind_param($record_stmt, 'i', $dept);
                            mysqli_stmt_execute($record_stmt);
                            $Record = mysqli_stmt_get_result($record_stmt);
                            
                            if (mysqli_num_rows($Record) > 0) {
                                while ($row = mysqli_fetch_array($Record)) {
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['A_YEAR'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['SOURCE'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['TITLE'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['FIRST_NAME'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['LAST_NAME'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['JOB_TITLE'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['DEPARTMENT'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['INSTITUTION'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['COUNTRY'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['EMAIL'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['SUBJECT'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['PHONE'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a class="btn btn-sm btn-outline-warning" href="AcademicPeers.php?edit_id=<?php echo (int)$row['ID']; ?>">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <form method="POST" style="display:inline;" class="delete-peer-form" data-peer-id="<?php echo (int)$row['ID']; ?>" data-peer-name="<?php echo htmlspecialchars(($row['FIRST_NAME'] ?? '') . ' ' . ($row['LAST_NAME'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return handleDeleteSubmit(this, event);">
                                                <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                                                <input type="hidden" name="delete_peer" value="1">
                                                <input type="hidden" name="peer_id" value="<?php echo (int)$row['ID']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger delete-peer-btn">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php
                                }
                            } else {
                                echo '<tr><td colspan="13" class="text-center">No records found. Add your first academic peer above.</td></tr>';
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
        min-width: 120px;
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

    .modern-table {
        background: white;
        border-radius: 0.75rem;
        overflow: hidden;
    }

    .table-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    .table-header th {
        border: none;
        padding: 1rem;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
    }

    .modern-table tbody tr {
        transition: all 0.3s ease;
    }

    .modern-table tbody tr:hover {
        background-color: #f8f9fa;
        transform: scale(1.01);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    /* Real-time validation styles */
    .form-control.is-valid {
        border-color: #28a745 !important;
        padding-right: calc(1.5em + 0.75rem);
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%2328a745' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right calc(0.375em + 0.1875rem) center;
        background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
    }

    .form-control.is-invalid {
        border-color: #dc3545 !important;
        padding-right: calc(1.5em + 0.75rem);
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='none' stroke='%23dc3545' viewBox='0 0 12 12'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right calc(0.375em + 0.1875rem) center;
        background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
    }

    .validation-message {
        font-size: 0.875rem;
        margin-top: 0.25rem;
        display: block;
    }

    .validation-message.valid {
        color: #28a745;
    }

    .validation-message.invalid {
        color: #dc3545;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function showNotification(message, type = 'info') {
        const existingNotifications = document.querySelectorAll('.notification');
        existingNotifications.forEach(notification => notification.remove());

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

        document.body.appendChild(notification);

        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 100);

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

    // Form submission handler
    (function wirePeerFormSubmission(){
        function attachHandlers() {
            const form = document.getElementById('peerForm');
            const submitBtn = document.getElementById('submitBtn');
            
            if (!form || !submitBtn) {
                return;
            }
            
            form.addEventListener('submit', function(e){
                // Validate required fields
                const firstName = document.getElementById('first_name');
                const lastName = document.getElementById('last_name');
                const institution = document.getElementById('institution');
                const country = document.getElementById('country');
                const email = document.getElementById('email');
                
                if (!firstName || !firstName.value.trim()) {
                    e.preventDefault();
                    showNotification('Please enter the first name', 'warning');
                    if (firstName) firstName.focus();
                    return false;
                }
                
                if (!lastName || !lastName.value.trim()) {
                    e.preventDefault();
                    showNotification('Please enter the last name', 'warning');
                    if (lastName) lastName.focus();
                    return false;
                }
                
                if (!institution || !institution.value.trim()) {
                    e.preventDefault();
                    showNotification('Please enter the institution name', 'warning');
                    if (institution) institution.focus();
                    return false;
                }
                
                if (!country || !country.value.trim()) {
                    e.preventDefault();
                    showNotification('Please enter the country or territory', 'warning');
                    if (country) country.focus();
                    return false;
                }
                
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
                
                // Update button text
                const originalHtml = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
                
                // Disable button to prevent double submission
                setTimeout(function() {
                    submitBtn.disabled = true;
                }, 100);
            });
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', attachHandlers);
        } else {
            attachHandlers();
        }
    })();

    // Real-time Email Validation
    (function setupEmailValidation() {
        function attachEmailValidation() {
            const emailInput = document.getElementById('email');
            if (!emailInput) return;

            // Create validation message element
            const validationMsg = document.createElement('small');
            validationMsg.className = 'validation-message';
            validationMsg.id = 'email_validation_msg';
            
            // Insert after email input
            if (emailInput.parentNode && !document.getElementById('email_validation_msg')) {
                emailInput.parentNode.insertBefore(validationMsg, emailInput.nextSibling);
            }

            // Email validation regex
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            // Validate on input (real-time)
            emailInput.addEventListener('input', function() {
                const value = this.value.trim();
                
                // Remove previous validation classes
                this.classList.remove('is-valid', 'is-invalid');
                
                if (value === '') {
                    // Empty - no validation
                    validationMsg.textContent = '';
                    validationMsg.className = 'validation-message';
                } else if (emailRegex.test(value)) {
                    // Valid email
                    this.classList.add('is-valid');
                    validationMsg.textContent = '✓ Valid email format';
                    validationMsg.className = 'validation-message valid';
                } else {
                    // Invalid email
                    this.classList.add('is-invalid');
                    validationMsg.textContent = '✗ Please enter a valid email (e.g., name@domain.com)';
                    validationMsg.className = 'validation-message invalid';
                }
            });

            // Also validate on blur (when user leaves field)
            emailInput.addEventListener('blur', function() {
                const value = this.value.trim();
                if (value !== '' && !emailRegex.test(value)) {
                    this.classList.add('is-invalid');
                    this.classList.remove('is-valid');
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', attachEmailValidation);
        } else {
            attachEmailValidation();
        }
    })();

    // Real-time Phone Number Validation
    (function setupPhoneValidation() {
        function attachPhoneValidation() {
            const phoneInput = document.getElementById('phone');
            if (!phoneInput) return;

            // Create validation message element
            const validationMsg = document.createElement('small');
            validationMsg.className = 'validation-message';
            validationMsg.id = 'phone_validation_msg';
            
            // Insert after phone input
            if (phoneInput.parentNode && !document.getElementById('phone_validation_msg')) {
                phoneInput.parentNode.insertBefore(validationMsg, phoneInput.nextSibling);
            }

            // Phone validation: only digits, max 10
            phoneInput.addEventListener('input', function() {
                let value = this.value;
                
                // Remove any non-digit characters
                value = value.replace(/[^0-9]/g, '');
                
                // Limit to 10 digits
                if (value.length > 10) {
                    value = value.slice(0, 10);
                }
                
                // Update input value
                this.value = value;
                
                // Remove previous validation classes
                this.classList.remove('is-valid', 'is-invalid');
                
                if (value === '') {
                    // Empty - optional field, no validation needed
                    validationMsg.textContent = '';
                    validationMsg.className = 'validation-message';
                } else if (value.length === 10) {
                    // Valid - exactly 10 digits
                    this.classList.add('is-valid');
                    validationMsg.textContent = '✓ Valid 10-digit phone number';
                    validationMsg.className = 'validation-message valid';
                } else if (value.length > 0 && value.length < 10) {
                    // Invalid - less than 10 digits
                    this.classList.add('is-invalid');
                    validationMsg.textContent = `✗ Please enter 10 digits (currently ${value.length})`;
                    validationMsg.className = 'validation-message invalid';
                }
            });

            // Validate on blur
            phoneInput.addEventListener('blur', function() {
                const value = this.value.trim();
                if (value !== '' && value.length !== 10) {
                    this.classList.add('is-invalid');
                    this.classList.remove('is-valid');
                }
            });

            // Prevent non-numeric input on keypress
            phoneInput.addEventListener('keypress', function(e) {
                // Allow only numbers (0-9)
                const charCode = e.which ? e.which : e.keyCode;
                if (charCode > 31 && (charCode < 48 || charCode > 57)) {
                    e.preventDefault();
                    return false;
                }
                
                // Prevent more than 10 digits
                if (this.value.length >= 10) {
                    e.preventDefault();
                    return false;
                }
            });

            // Prevent paste of non-numeric content
            phoneInput.addEventListener('paste', function(e) {
                e.preventDefault();
                const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                const numericOnly = pastedText.replace(/[^0-9]/g, '').slice(0, 10);
                this.value = numericOnly;
                
                // Trigger input event to validate
                this.dispatchEvent(new Event('input', { bubbles: true }));
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', attachPhoneValidation);
        } else {
            attachPhoneValidation();
        }
    })();

    // CRITICAL: Prevent duplicate delete submissions
    let deleteInProgress = false;
    const activeDeleteRequests = new Set();
    
    function handleDeleteSubmit(form, event) {
        // CRITICAL: Prevent default submission
        event.preventDefault();
        
        // Get peer info from form data attributes
        const peerId = form.getAttribute('data-peer-id');
        const peerName = form.getAttribute('data-peer-name');
        
        // CRITICAL: Check if delete is already in progress for this record
        if (activeDeleteRequests.has(peerId)) {
            showNotification('Delete request already in progress. Please wait...', 'warning');
            return false;
        }
        
        // CRITICAL: Check global delete flag
        if (deleteInProgress) {
            showNotification('Another delete operation is in progress. Please wait...', 'warning');
            return false;
        }
        
        // Confirm deletion
        if (!confirm('Are you sure you want to delete "' + peerName + '"? This action cannot be undone.')) {
            return false;
        }
        
        // CRITICAL: Mark as in progress
        deleteInProgress = true;
        activeDeleteRequests.add(peerId);
        
        // Disable the delete button to prevent double-click
        const deleteBtn = form.querySelector('.delete-peer-btn');
        const originalHtml = deleteBtn.innerHTML;
        deleteBtn.disabled = true;
        deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        
        // Show notification
        showNotification('Deleting record...', 'info');
        
        // CRITICAL: Create FormData and submit via fetch to handle errors properly
        const formData = new FormData(form);
        
        // Get CSRF token
        const csrfToken = form.querySelector('input[name="csrf_token"]')?.value || '';
        if (!csrfToken) {
            showNotification('Security token not found. Please refresh the page.', 'error');
            deleteInProgress = false;
            activeDeleteRequests.delete(peerId);
            deleteBtn.disabled = false;
            deleteBtn.innerHTML = originalHtml;
            return false;
        }
        
        // CRITICAL: Add timeout to prevent hanging requests
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
        
        // Submit delete request
        fetch(form.action || window.location.href, {
            method: 'POST',
            body: formData,
            signal: controller.signal,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            clearTimeout(timeoutId);
            
            // Check if response is a redirect (status 302/301)
            if (response.redirected || response.status === 302 || response.status === 301) {
                // Redirect happened - reload page to show success/error message
                window.location.href = response.url || window.location.href;
                return;
            }
            
            // Try to parse as JSON (in case of error response)
            return response.text().then(text => {
                const trimmed = text.trim();
                if (!trimmed) {
                    // Empty response - assume success and reload
                    window.location.reload();
                    return;
                }
                
                // Try to parse as JSON
                try {
                    const json = JSON.parse(trimmed);
                    if (json.success === false) {
                        showNotification(json.message || 'Delete failed. Please try again.', 'error');
                        deleteInProgress = false;
                        activeDeleteRequests.delete(peerId);
                        deleteBtn.disabled = false;
                        deleteBtn.innerHTML = originalHtml;
                    } else {
                        // Success - reload page
                        window.location.reload();
                    }
                } catch (e) {
                    // Not JSON - assume HTML redirect response, reload page
                    window.location.reload();
                }
            });
        })
        .catch(error => {
            clearTimeout(timeoutId);
            
            if (error.name === 'AbortError') {
                showNotification('Delete request timed out. Please refresh the page and try again.', 'error');
            } else {
                console.error("Delete request error:", error);
                showNotification('Network error occurred. Please refresh the page and try again.', 'error');
            }
            
            deleteInProgress = false;
            activeDeleteRequests.delete(peerId);
            deleteBtn.disabled = false;
            deleteBtn.innerHTML = originalHtml;
        });
        
        return false; // Prevent default form submission
    }
    
    // Legacy function for backward compatibility (if called directly)
    function confirmDelete(name) {
        if (confirm('Are you sure you want to delete "' + name + '"? This action cannot be undone.')) {
            showNotification('Deleting record...', 'info');
            return true;
        }
        return false;
    }
</script>

<?php
require "unified_footer.php";
?>
