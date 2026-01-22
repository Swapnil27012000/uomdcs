<?php
// admin/session.php - Admin session verification
// Add error handling to catch any fatal errors
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require('../session_verification.php');
} catch (ParseError $e) {
    error_log("FATAL PARSE ERROR in session_verification.php: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    die("System error: Configuration file error. Please contact administrator.");
} catch (Error $e) {
    error_log("FATAL ERROR loading session_verification.php: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    die("System error: Failed to load session verification. Please contact administrator.");
} catch (Exception $e) {
    error_log("ERROR loading session_verification.php: " . $e->getMessage());
    die("System error: Failed to load session verification. Please contact administrator.");
}

// Verify user has Admin access
try {
    $userData = verifyUserSession('admin');
    
    if (!$userData) {
        exit; // verifyUserSession handles redirect
    }
} catch (Error $e) {
    error_log("FATAL ERROR in verifyUserSession: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    // Try to redirect to login
    if (function_exists('redirectToLogin')) {
        redirectToLogin("System error occurred. Please try again.");
    } else {
        header('Location: ../unified_login.php');
        exit;
    }
} catch (Exception $e) {
    error_log("ERROR in verifyUserSession: " . $e->getMessage());
    // Try to redirect to login
    if (function_exists('redirectToLogin')) {
        redirectToLogin("System error occurred. Please try again.");
    } else {
        header('Location: ../unified_login.php');
        exit;
    }
}

// Extract user information
$userInfo = $userData['user_info'];
$permission = $userData['permission'];
$table = $userData['table'];
$email = $userData['email'];

// Additional Admin specific checks
if (!hasPermission('admin')) {
    redirectToLogin("Access denied. Admin permission required.");
}

// Set Admin specific session variables
$_SESSION['current_role'] = 'admin';
$_SESSION['user_table'] = $table;
$_SESSION['user_permission'] = $permission;

// Log access for security
error_log("Admin access: " . $email . " from " . $table . " table");
?>
