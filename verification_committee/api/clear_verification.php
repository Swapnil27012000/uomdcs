<?php
/**
 * API Endpoint: Clear Verification Status
 */

header('Content-Type: application/json');

require_once(__DIR__ . '/../session.php');
require_once(__DIR__ . '/../functions.php');
require_once(__DIR__ . '/../../csrf_shared.php');

// CRITICAL: Check database connection (same as dept_login)
if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Database connection error. Please try again.']);
    exit;
}

$email = $_SESSION['admin_username'] ?? $_SESSION['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

// Validate CSRF token
if (!validate_csrf($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Validate required fields
$required = ['dept_id', 'academic_year', 'section_number', 'item_number'];
foreach ($required as $field) {
    if (!isset($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

$dept_id = (int)$input['dept_id'];
$academic_year = trim($input['academic_year']);
$section_number = (int)$input['section_number'];
$item_number = $input['item_number']; // Keep as string

// Check if item is assigned to this committee member
$assigned_items = getAssignedItems($email);
if (!isset($assigned_items[$section_number]) || !in_array((string)$item_number, $assigned_items[$section_number])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Item not assigned to you']);
    exit;
}

// Check if verification is locked
$my_status = getMyVerificationStatus($email, $dept_id, $section_number, $item_number, $academic_year);
if ($my_status && $my_status['is_locked']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Cannot clear locked verification']);
    exit;
}

// Clear verification status
$success = clearVerificationStatus($email, $dept_id, $section_number, $item_number, $academic_year);

if ($success) {
    echo json_encode(['success' => true, 'message' => 'Verification cleared successfully']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to clear verification']);
}
