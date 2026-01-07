<?php
// user/session.php - User session verification
require('../session_verification.php');

// Verify user has User access (generic user access)
$userData = verifyUserSession();

if (!$userData) {
    exit; // verifyUserSession handles redirect
}

// Extract user information
$userInfo = $userData['user_info'];
$permission = $userData['permission'];
$table = $userData['table'];
$email = $userData['email'];

// Set User specific session variables
$_SESSION['current_role'] = 'user';
$_SESSION['user_table'] = $table;
$_SESSION['user_permission'] = $permission;

// Log access for security
error_log("User access: " . $email . " from " . $table . " table");
?>
