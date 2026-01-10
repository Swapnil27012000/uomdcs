<?php
// verification_committee/session.php - Verification Committee session verification
require('../session_verification.php');

// Verify user has Verification Committee access
$userData = verifyUserSession('verification_committee');

if (!$userData) {
    exit; // verifyUserSession handles redirect
}

// Extract user information
$userInfo = $userData['user_info'];
$permission = $userData['permission'];
$table = $userData['table'];
$email = $userData['email'];

// Additional Verification Committee specific checks
if (!hasPermission('verification_committee')) {
    redirectToLogin("Access denied. Verification Committee permission required.");
}

// Set Verification Committee specific session variables
$_SESSION['current_role'] = 'verification_committee';
$_SESSION['user_table'] = $table;
$_SESSION['user_permission'] = $permission;
// CRITICAL FIX: Set admin_username for dashboard compatibility
$_SESSION['admin_username'] = $email;
// Ensure verification_committee session is set
$_SESSION['verification_committee'] = true;

// Log access for security
// Error logging disabled
?>
