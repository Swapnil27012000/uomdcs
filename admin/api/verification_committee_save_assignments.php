<?php
/**
 * API: Save Assignments for Verification Committee User
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
$assignments_json = isset($_POST['assignments']) ? $_POST['assignments'] : '[]';

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Missing user_id']);
    exit;
}

// Check if table exists
$table_check = $conn->query("SHOW TABLES LIKE 'verification_committee_assignments'");
if (!$table_check || $table_check->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Database table verification_committee_assignments does not exist. Please run the SQL script first.']);
    exit;
}
if ($table_check) {
    mysqli_free_result($table_check);
}

// Get user email and role (using correct column names: EMAIL, ROLE)
$user_query = $conn->prepare("SELECT EMAIL, ROLE FROM verification_committee_users WHERE id = ? LIMIT 1");
if (!$user_query) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

$user_query->bind_param("i", $user_id);
if (!$user_query->execute()) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    $user_query->close();
    exit;
}

$user_result = $user_query->get_result();
if (!$user_result || $user_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    $user_query->close();
    exit;
}
$user_data = $user_result->fetch_assoc();
$user_query->close();
if ($user_result) {
    mysqli_free_result($user_result);
}

$email = $user_data['EMAIL'];
$role_name = $user_data['ROLE'];

// Decode assignments
$assignments = json_decode($assignments_json, true);
if (!is_array($assignments)) {
    echo json_encode(['success' => false, 'message' => 'Invalid assignments format']);
    exit;
}

// Start transaction
$conn->begin_transaction();

try {
    // Delete existing assignments
    $delete = $conn->prepare("DELETE FROM verification_committee_assignments WHERE verification_user_id = ?");
    if (!$delete) {
        throw new Exception('Database error preparing delete: ' . mysqli_error($conn));
    }
    $delete->bind_param("i", $user_id);
    if (!$delete->execute()) {
        throw new Exception('Database error executing delete: ' . mysqli_error($conn));
    }
    $delete->close();
    
    // Insert new assignments
    if (count($assignments) > 0) {
        $insert = $conn->prepare("INSERT INTO verification_committee_assignments (verification_user_id, committee_email, role_name, section_number, item_number) VALUES (?, ?, ?, ?, ?)");
        if (!$insert) {
            throw new Exception('Database error preparing insert: ' . mysqli_error($conn));
        }
        
        foreach ($assignments as $assignment) {
            $section = (int)($assignment['section_number'] ?? 0);
            $item = (int)($assignment['item_number'] ?? 0);
            
            if ($section < 0 || $item < 0) {
                continue; // Skip invalid assignments
            }
            
            $insert->bind_param("issii", $user_id, $email, $role_name, $section, $item);
            if (!$insert->execute()) {
                throw new Exception('Database error executing insert: ' . mysqli_error($conn));
            }
        }
        $insert->close();
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Assignments saved successfully']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
