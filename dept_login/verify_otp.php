<?php
// verify_otp.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    include 'config.php';
}

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

    // Example mapping (adjust to your actual PERMISSION values)
    //  - 'boss' => nodal/admin
    //  - 'department' => dept login
    //  - 'AAQA', 'MUIBEAS', 'Expert_comty_login', 'Chairman_login' => role names
    // Map permission -> session flags and redirect URL
    $redirectUrl = "user/dashboard.php";
    switch (strtolower((string)$permission)) {
        case 'boss':
        case 'nodal':
            $_SESSION['admin'] = true;
            $redirectUrl = "admin/Dashboard.php";
            break;
        case 'department':
        case 'dept':
            $_SESSION['dept_login'] = true;
            $_SESSION['dept_id'] = $deptId;
            $redirectUrl = "dept_login/dashboard.php";
            break;
        case 'aaqa':
            $_SESSION['AAQA'] = true;
            $redirectUrl = "AAQA_login/dashboard.php";
            break;
        case 'muibeas':
            $_SESSION['MUIBEAS'] = true;
            $redirectUrl = "MUIBEAS/dashboard.php";
            break;
        case 'expert_comty_login':
        case 'expert':
            $_SESSION['Expert_comty_login'] = true;
            $redirectUrl = "Expert_comty_login/dashboard.php";
            break;
        case 'chairman_login':
        case 'chairman':
            $_SESSION['Chairman_login'] = true;
            $redirectUrl = "Chairman_login/dashboard.php";
            break;
        default:
            // Default generic user dashboard
            $_SESSION['admin_username'] = $email;
            $redirectUrl = "user/dashboard.php";
            break;
    }

    // Set common session values
    $_SESSION['admin_username'] = $email;
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

    echo json_encode([
        'status' => 'success',
        'message' => 'OTP verified successfully.',
        'redirect' => $redirectUrl
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
