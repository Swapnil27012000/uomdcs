<?php
// verify_otp.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'config.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method.");
    }

    if (empty($_POST['otp'])) {
        throw new Exception("OTP is required.");
    }

    $otp = trim($_POST['otp']);

    if (!isset($_SESSION['pending_email']) || !isset($_SESSION['pending_table'])) {
        throw new Exception("Session expired. Please login again.");
    }

    $email = $_SESSION['pending_email'];
    $table = $_SESSION['pending_table']; // 'department_master' or 'boss'

    // Get OTP row from the correct table
    if ($table === 'department_master') {
        $stmt = $conn->prepare("SELECT otp, otp_expiry, DEPT_ID, PERMISSION FROM department_master WHERE EMAIL = ?");
    } else {
        $stmt = $conn->prepare("SELECT otp, otp_expiry, PERMISSION FROM boss WHERE EMAIL = ?");
    }

    if (!$stmt) {
        throw new Exception("Database error (prepare).");
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        throw new Exception("User not found.");
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    // Check expiry
    if (empty($row['otp_expiry']) || strtotime($row['otp_expiry']) < time()) {
        throw new Exception("OTP has expired. Please resend OTP.");
    }

    // Match OTP (string compare)
    if (!isset($row['otp']) || (string)$row['otp'] !== (string)$otp) {
        throw new Exception("Incorrect OTP. Try again.");
    }

    // OTP valid -> set session according to permission/role
    $permission = $row['PERMISSION'] ?? ($_SESSION['pending_permission'] ?? null);
    $deptId = $row['DEPT_ID'] ?? ($_SESSION['pending_dept_id'] ?? null);

    // Unified permission mapping for all user types
    // Maps permission values to appropriate session flags and redirect URLs
    $redirectUrl = "user/dashboard.php";
    
    // Normalize permission to lowercase for consistent matching
    $permission_str = (string)$permission;
    $permission_lower = strtolower(trim($permission_str));
    
    // Debug logging for ALL users to see what's happening
    error_log("OTP Verify Debug - Email: " . $email . ", Table: " . $table . ", Permission: [" . $permission_str . "], Lower: [" . $permission_lower . "]");
    
    // Determine user type based on table and permission
    if ($table === 'boss') {
        // Boss table contains all supported user types
        // Check AAQA first (before switch) to handle any variation - use case-insensitive check
        $permission_check = $permission_str . '|' . $permission_lower;
        if (stripos($permission_check, 'aaqa') !== false || $permission_lower === 'aaqa_login' || $permission_lower === 'aaqa') {
            error_log("AAQA Match Found - Setting AAQA session for: " . $email);
            $_SESSION['AAQA'] = true;
            $_SESSION['admin_username'] = $email;
            $redirectUrl = "AAQA_login/dashboard.php";
        } else {
            switch ($permission_lower) {
                // Admin/Nodal Officer permissions
                case 'boss':
                case 'nodal':
                case 'admin':
                    $_SESSION['admin'] = true;
                    $redirectUrl = "admin/Dashboard.php";
                    break;
                
                // MUIBEAS permissions
                case 'muibeas':
                    $_SESSION['MUIBEAS'] = true;
                    $redirectUrl = "MUIBEAS/dashboard.php";
                    break;
                    
                // Expert Committee permissions
                case 'expert_comty_login':
                case 'expert':
                case 'expert_committee':
                    $_SESSION['Expert_comty_login'] = true;
                    $_SESSION['admin_username'] = $email;  // Set this early
                    $redirectUrl = "Expert_comty_login/dashboard.php";
                    break;
                
                // Chairman permissions
                case 'chairman_login':
                case 'chairman':
                    $_SESSION['Chairman_login'] = true;
                    $redirectUrl = "Chairman_login/dashboard.php";
                    break;
                    
                // Default fallback for boss table
                default:
                    // Log unknown permission for debugging
                    error_log("Unknown boss permission: " . $permission . " (lower: " . $permission_lower . ") for user: " . $email);
                    
                    // Set generic user session
                    $_SESSION['userus'] = $email;
                    $redirectUrl = "user/dashboard.php";
                    break;
            }
        }
    } else {
        // department_master table - all users have 'user' permission
        $_SESSION['dept_login'] = true;
        $_SESSION['dept_id'] = $deptId;
        $redirectUrl = "dept_login/dashboard.php";
    }

    // Set common session values
    $_SESSION['admin_username'] = $email;   //admin_username
    if ($deptId) $_SESSION['dept_id'] = $deptId;
    if ($permission) $_SESSION['permission'] = $permission;

    // Clear OTP columns in DB for security
    if ($table === 'department_master') {
        $stmt = $conn->prepare("UPDATE department_master SET otp = NULL, otp_expiry = NULL WHERE EMAIL = ?");
    } else {
        $stmt = $conn->prepare("UPDATE boss SET otp = NULL, otp_expiry = NULL WHERE EMAIL = ?");
    }
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->close();
    }

    // Cleanup temp OTP session data
    unset($_SESSION['login_otp'], $_SESSION['otp_expiry'], $_SESSION['pending_email'], $_SESSION['pending_dept_id'], $_SESSION['pending_permission'], $_SESSION['pending_table']);

    // Debug info for troubleshooting
    $debug_info = [
        'permission' => $permission_str,
        'permission_lower' => $permission_lower,
        'table' => $table,
        'email' => $email,
        'redirect' => $redirectUrl,
        'session_aaqa_set' => isset($_SESSION['AAQA'])
    ];
    
    echo json_encode([
        'status' => 'success',
        'message' => 'OTP verified successfully.',
        'redirect' => $redirectUrl,
        'debug' => $debug_info  // Remove this in production
    ]);
    exit;

} catch (Exception $e) {
    $msg = $e->getMessage();
    // Optionally log $msg
    echo json_encode([
        'status' => 'error',
        'message' => $msg
    ]);
    exit;
}
