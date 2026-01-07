<?php
// MUIBEAS/session.php - MUIBEAS session verification
require('../session_verification.php');

// Verify user has MUIBEAS access
$userData = verifyUserSession('muibeas');

if (!$userData) {
    exit; // verifyUserSession handles redirect
}

// Extract user information
$userInfo = $userData['user_info'];
$permission = $userData['permission'];
$table = $userData['table'];
$email = $userData['email'];

// Additional MUIBEAS specific checks
if (!hasPermission('muibeas')) {
    redirectToLogin("Access denied. MUIBEAS permission required.");
}

// Set MUIBEAS specific session variables
$_SESSION['current_role'] = 'muibeas';
$_SESSION['user_table'] = $table;
$_SESSION['user_permission'] = $permission;

// Log access for security
error_log("MUIBEAS access: " . $email . " from " . $table . " table");
?>
