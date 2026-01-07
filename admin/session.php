<?php
// admin/session.php - Admin session verification
require('../session_verification.php');

// Verify user has Admin access
$userData = verifyUserSession('admin');

if (!$userData) {
    exit; // verifyUserSession handles redirect
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
