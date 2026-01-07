<?php
// Expert_comty_login/session.php - Expert Committee session verification
require_once(__DIR__ . '/../session_verification.php');

// Verify user has Expert Committee access
$userData = verifyUserSession('expert');

if (!$userData) {
    exit; // verifyUserSession handles redirect
}

// Extract user information
$userInfo = $userData['user_info'];
$permission = $userData['permission'];
$table = $userData['table'];
$email = $userData['email'];

// Additional Expert Committee specific checks
if (!hasPermission('expert')) {
    redirectToLogin("Access denied. Expert Committee permission required.");
}

// Set Expert Committee specific session variables
$_SESSION['current_role'] = 'expert';
$_SESSION['user_table'] = $table;
$_SESSION['user_permission'] = $permission;

// Log access for security
error_log("Expert Committee access: " . $email . " from " . $table . " table");
?>