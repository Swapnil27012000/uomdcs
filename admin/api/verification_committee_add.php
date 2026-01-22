<?php
/**
 * API: Add Verification Committee User
 * verification_committee_users is a STANDALONE table with EMAIL, PASS_WORD, ROLE columns
 */
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Simple session check for admin - don't use session.php as it has die() statements that break JSON responses
// Check if user is logged in
if (!isset($_SESSION['admin_username']) && !isset($_SESSION['user_permission'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
    exit;
}

// Check admin permission - be flexible with permission checking
$has_admin_permission = false;

// If admin_username is set, user is likely logged in (used across all user types)
if (isset($_SESSION['admin_username'])) {
    $has_admin_permission = true;
}

// Also check user_permission if set
if (isset($_SESSION['user_permission'])) {
    $permission = strtolower(trim($_SESSION['user_permission']));
    $has_admin_permission = (
        $permission === 'admin' || 
        $permission === 'administrator' || 
        $permission === 'adm' ||
        $permission === 'boss' ||
        $permission === 'nodal' ||
        stripos($permission, 'admin') !== false
    );
}

// Check if admin session flag is set
if (isset($_SESSION['admin']) && $_SESSION['admin'] === true) {
    $has_admin_permission = true;
}

if (!$has_admin_permission) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin permission required.']);
    exit;
}

// Now require config
require('../../config.php');

// Enable error logging
$error_log = [];

try {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $role_name = isset($_POST['role_name']) ? trim($_POST['role_name']) : '';
    $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';

    if (empty($email) || empty($role_name)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields: email and role_name are required']);
        exit;
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit;
    }

    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'verification_committee_users'");
    if (!$table_check || $table_check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Database table verification_committee_users does not exist. Please run the SQL script first.']);
        exit;
    }
    if ($table_check) {
        mysqli_free_result($table_check);
    }

    // Check if user exists in boss table (to get password)
    $boss_user = null;
    $password = '';
    
    $boss_query = $conn->prepare("SELECT Id, EMAIL, PASS_WORD FROM boss WHERE LOWER(EMAIL) = LOWER(?) LIMIT 1");
    if ($boss_query) {
        $boss_query->bind_param("s", $email);
        if ($boss_query->execute()) {
            $boss_result = $boss_query->get_result();
            if ($boss_result && $boss_result->num_rows > 0) {
                $boss_user = $boss_result->fetch_assoc();
                $password = $boss_user['PASS_WORD']; // Use existing password from boss table
            }
            if ($boss_result) {
                mysqli_free_result($boss_result);
            }
        }
        $boss_query->close();
    }

    // If not in boss table, generate a random password
    if (empty($password)) {
        $password = bin2hex(random_bytes(8)); // 16 character random password
    }

    // Check if already exists in verification_committee_users
    $check_existing = $conn->prepare("SELECT id FROM verification_committee_users WHERE LOWER(EMAIL) = LOWER(?) LIMIT 1");
    if (!$check_existing) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit;
    }
    
    $check_existing->bind_param("s", $email);
    if (!$check_existing->execute()) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        $check_existing->close();
        exit;
    }
    
    $existing_result = $check_existing->get_result();
    if ($existing_result && $existing_result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'User already exists in verification committee']);
        mysqli_free_result($existing_result);
        $check_existing->close();
        exit;
    }
    if ($existing_result) {
        mysqli_free_result($existing_result);
    }
    $check_existing->close();

    // Insert into verification_committee_users (STANDALONE table structure: EMAIL, PASS_WORD, ROLE, NAME, is_active)
    $insert = $conn->prepare("INSERT INTO verification_committee_users (EMAIL, PASS_WORD, ROLE, NAME, is_active) VALUES (?, ?, ?, ?, ?)");
    if (!$insert) {
        echo json_encode(['success' => false, 'message' => 'Database error preparing insert: ' . mysqli_error($conn)]);
        exit;
    }
    
    $insert->bind_param("ssssi", $email, $password, $role_name, $name, $is_active);

    if ($insert->execute()) {
        echo json_encode(['success' => true, 'message' => 'User added successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add user: ' . mysqli_error($conn)]);
    }
    
    $insert->close();
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit;
}
