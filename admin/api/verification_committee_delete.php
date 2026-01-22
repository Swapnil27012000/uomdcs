<?php
/**
 * API: Delete Verification Committee User
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

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Missing user_id']);
    exit;
}

// Start transaction
$conn->begin_transaction();

try {
    // Delete assignments first (foreign key constraint)
    $delete_assignments = $conn->prepare("DELETE FROM verification_committee_assignments WHERE verification_user_id = ?");
    $delete_assignments->bind_param("i", $user_id);
    $delete_assignments->execute();
    $delete_assignments->close();
    
    // Delete user
    $delete_user = $conn->prepare("DELETE FROM verification_committee_users WHERE id = ?");
    $delete_user->bind_param("i", $user_id);
    $delete_user->execute();
    $delete_user->close();
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
