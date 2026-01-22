<?php
/**
 * API: Get Assignments for Verification Committee User
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

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Missing user_id']);
    exit;
}

// Get user email and role (using correct column names: EMAIL, ROLE)
$user_query = $conn->prepare("SELECT EMAIL, ROLE FROM verification_committee_users WHERE id = ? LIMIT 1");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
if (!$user_result || $user_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}
$user_data = $user_result->fetch_assoc();
$user_query->close();

// Get assignments
$assignments_query = $conn->prepare("SELECT section_number, item_number FROM verification_committee_assignments WHERE verification_user_id = ?");
$assignments_query->bind_param("i", $user_id);
$assignments_query->execute();
$assignments_result = $assignments_query->get_result();
$assignments = [];
while ($row = $assignments_result->fetch_assoc()) {
    $assignments[] = $row;
}
$assignments_query->close();

echo json_encode([
    'success' => true,
    'assignments' => $assignments,
    'user' => $user_data
]);
