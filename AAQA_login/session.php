<?php
// AAQA/session.php - AAQA session verification
require('../session_verification.php');

// Verify user has AAQA access
$userData = verifyUserSession('aaqa');

if (!$userData) {
    exit; // verifyUserSession handles redirect
}

// Extract user information
$userInfo = $userData['user_info'];
$permission = $userData['permission'];
$table = $userData['table'];
$email = $userData['email'];

// Additional AAQA specific checks
if (!hasPermission('aaqa')) {
    redirectToLogin("Access denied. AAQA permission required.");
}

// Set AAQA specific session variables
$_SESSION['current_role'] = 'aaqa';
$_SESSION['user_table'] = $table;
$_SESSION['user_permission'] = $permission;

// Log access for security
error_log("AAQA access: " . $email . " from " . $table . " table");
?>
