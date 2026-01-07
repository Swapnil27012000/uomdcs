<?php
/**
 * Lock Expert Review - AJAX Endpoint
 * Locks/finalizes the expert review (cannot be edited after)
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once('session.php');
require_once('expert_functions.php');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    exit;
}

$dept_id = (int)($input['dept_id'] ?? 0);
$academic_year = $input['academic_year'] ?? getAcademicYear();
$expert_email = $email; // From session

if (!$dept_id || !$expert_email) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

// Check if review exists
$check_query = "SELECT id, is_locked FROM expert_reviews WHERE expert_email = ? AND dept_id = ? AND academic_year = ? LIMIT 1";
$check_stmt = $conn->prepare($check_query);

if (!$check_stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$check_stmt->bind_param("sis", $expert_email, $dept_id, $academic_year);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($row = $check_result->fetch_assoc()) {
    $review_id = $row['id'];
    $already_locked = (bool)$row['is_locked'];
    
    if ($already_locked) {
        echo json_encode(['success' => false, 'message' => 'Review is already locked']);
        $check_stmt->close();
        exit;
    }
    
    // Lock the review
    $lock_query = "UPDATE expert_reviews SET
        is_locked = 1,
        review_status = 'locked',
        review_completed_at = NOW(),
        review_locked_at = NOW(),
        updated_at = NOW()
        WHERE id = ?";
    
    $lock_stmt = $conn->prepare($lock_query);
    if ($lock_stmt) {
        $lock_stmt->bind_param("i", $review_id);
        
        if ($lock_stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Review locked successfully',
                'review_id' => $review_id,
                'locked_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to lock review']);
        }
        $lock_stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Review not found. Please save your review first.']);
}

$check_stmt->close();
?>

