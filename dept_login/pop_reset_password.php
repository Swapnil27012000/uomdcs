<?php
session_start();
// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    include "config.php";
}

if (!isset($_SESSION['admin_username'])) {
    header("Location: index.php");
    exit();
}

$new_pass = trim($_POST['new_pass']);
$email    = $_SESSION['admin_username'];

// Simple validation
if (empty($new_pass)) {
    die("Password cannot be empty");
}

// Update password + status
$query = "UPDATE department_master SET PASS_WORD=?, status=1 WHERE EMAIL=?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $new_pass, $email);
$stmt->execute();

$_SESSION['status'] = 1; // update session flag

// ✅ Always redirect back to dashboard
header("Location: dashboard.php?msg=success");
exit();
?>
