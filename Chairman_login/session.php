<?php
// Chairman_login/session.php - Chairman session verification
require_once(__DIR__ . '/../session_verification.php');

// Verify user has Chairman access
$userData = verifyUserSession('chairman');

if (!$userData) {
    exit; // verifyUserSession handles redirect
}

// Extract user information
$userInfo = $userData['user_info'];
$permission = $userData['permission'];
$table = $userData['table'];
$email = $userData['email'];

// Additional Chairman specific checks
if (!hasPermission('chairman')) {
    redirectToLogin("Access denied. Chairman permission required.");
}

// Set Chairman specific session variables
$_SESSION['current_role'] = 'chairman';
$_SESSION['user_table'] = $table;
$_SESSION['user_permission'] = $permission;

// Log access for security
error_log("Chairman access: " . $email . " from " . $table . " table");
?>
