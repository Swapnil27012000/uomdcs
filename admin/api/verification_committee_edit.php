<?php
/**
 * API: Edit Verification Committee User
 */
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Simple session check for admin
if (!isset($_SESSION['admin_username']) && !isset($_SESSION['user_permission'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
    exit;
}

$has_admin_permission = false;
if (isset($_SESSION['admin_username'])) {
    $has_admin_permission = true;
}
if (isset($_SESSION['user_permission'])) {
    $permission = strtolower(trim($_SESSION['user_permission']));
    $has_admin_permission = (
        $permission === 'admin' || 
        $permission === 'administrator' || 
        $permission === 'adm' ||
        $permission === 'boss' ||
        stripos($permission, 'admin') !== false
    );
}
if (isset($_SESSION['admin']) && $_SESSION['admin'] === true) {
    $has_admin_permission = true;
}

if (!$has_admin_permission) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin permission required.']);
    exit;
}

require('../../config.php');

$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$role_name = isset($_POST['role_name']) ? trim($_POST['role_name']) : '';
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

if (!$user_id || empty($role_name)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Update using correct column names: ROLE (not role_name), NAME (optional)
$update = $conn->prepare("UPDATE verification_committee_users SET ROLE = ?, NAME = ?, is_active = ? WHERE id = ?");
$update->bind_param("ssii", $role_name, $name, $is_active, $user_id);

if ($update->execute()) {
    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update user: ' . $conn->error]);
}
$update->close();
