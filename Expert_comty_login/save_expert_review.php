<?php
/**
 * Save Expert Review - AJAX Endpoint
 * Saves expert scores and review progress
 */

// CRITICAL: Clear ALL output buffers FIRST
while (ob_get_level() > 0) {
    ob_end_clean();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/expert_review_errors.log');

// Load dependencies silently
ob_start();
require_once(__DIR__ . '/../config.php'); // Load config first for database connection
ob_end_clean();

ob_start();
require_once('session.php');
ob_end_clean();

ob_start();
require_once('expert_functions.php');
ob_end_clean();

// Clear again after includes
while (ob_get_level() > 0) {
    ob_end_clean();
}

// CRITICAL: Set headers BEFORE any output
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-cache, must-revalidate');
}

// CRITICAL: Build response in variable, output once at end
$response = ['success' => false, 'message' => 'Unknown error'];

try {
    // Verify database connection
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection not available');
    }
    
    // Get JSON input
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true);
    
    if (!$input) {
        throw new Exception('Invalid input data: ' . substr($raw_input, 0, 200));
    }
    
    $dept_id = (int)($input['dept_id'] ?? 0);
    $academic_year = $input['academic_year'] ?? getAcademicYear();
    
    // Verify email is set from session
    if (!isset($email) || empty($email)) {
        throw new Exception('Expert email not found in session. Please login again.');
    }
    $expert_email = $email;
    
    if (!$dept_id || !$expert_email) {
        throw new Exception('Missing required parameters: dept_id=' . $dept_id . ', email=' . ($expert_email ? 'set' : 'missing'));
    }

    // Extract scores
    $expert_score_section_1 = (float)($input['expert_score_section_1'] ?? 0);
    $expert_score_section_2 = (float)($input['expert_score_section_2'] ?? 0);
    $expert_score_section_3 = (float)($input['expert_score_section_3'] ?? 0);
    $expert_score_section_4 = (float)($input['expert_score_section_4'] ?? 0);
    $expert_score_section_5 = (float)($input['expert_score_section_5'] ?? 0);
    $expert_total_score = $expert_score_section_1 + $expert_score_section_2 + $expert_score_section_3 + $expert_score_section_4 + $expert_score_section_5;
    
    // Extract individual item scores (JSON)
    $expert_item_scores = null;
    if (isset($input['expert_item_scores']) && is_array($input['expert_item_scores'])) {
        $expert_item_scores = json_encode($input['expert_item_scores'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    
    // Extract narrative scores and remarks (JSON)
    $expert_narrative_scores = null;
    if (isset($input['expert_narrative_scores']) && is_array($input['expert_narrative_scores'])) {
        $expert_narrative_scores = json_encode($input['expert_narrative_scores'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log("[Save Expert Review] Narrative scores received: " . count($input['expert_narrative_scores']) . " items");
        error_log("[Save Expert Review] Narrative scores JSON: " . substr($expert_narrative_scores, 0, 500));
    } else {
        error_log("[Save Expert Review] No narrative scores in input or not an array");
    }
    
    $review_notes = $input['review_notes'] ?? '';
    
    // Get department auto scores for reference
    $dept_scores = getDepartmentScores($dept_id, $academic_year);
    if (!is_array($dept_scores)) {
        throw new Exception('Failed to get department scores');
    }
    
    // Get expert category
    $expert_category = getExpertCategory($expert_email);
    if (empty($expert_category)) {
        throw new Exception('Expert category not found for email: ' . $expert_email);
    }

    // Check if review exists and if it's locked
    $check_query = "SELECT id, is_locked FROM expert_reviews WHERE expert_email = ? AND dept_id = ? AND academic_year = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_query);
    if (!$check_stmt) {
        throw new Exception('Failed to prepare check query: ' . $conn->error);
    }
    
    $exists = false;
    $review_id = null;
    $is_locked = false;
    
    $check_stmt->bind_param("sis", $expert_email, $dept_id, $academic_year);
    if (!$check_stmt->execute()) {
        $check_stmt->close();
        throw new Exception('Failed to execute check query: ' . $check_stmt->error);
    }
    
    $check_result = $check_stmt->get_result();
    if ($row = $check_result->fetch_assoc()) {
        $exists = true;
        $review_id = $row['id'];
        $is_locked = (bool)$row['is_locked'];
    }
    $check_stmt->close();

    // Handle DELETE request (for Clear Data functionality)
    if (isset($input['action']) && $input['action'] === 'delete') {
        if ($exists && $review_id) {
            $delete_query = "DELETE FROM expert_reviews WHERE id = ? AND expert_email = ? AND dept_id = ? AND academic_year = ?";
            $delete_stmt = $conn->prepare($delete_query);
            if (!$delete_stmt) {
                throw new Exception('Failed to prepare delete query: ' . $conn->error);
            }
            
            $delete_stmt->bind_param("isis", $review_id, $expert_email, $dept_id, $academic_year);
            if ($delete_stmt->execute()) {
                $response = [
                    'success' => true,
                    'message' => 'Expert review data deleted successfully',
                    'deleted' => true
                ];
            } else {
                throw new Exception('Failed to delete review: ' . $delete_stmt->error);
            }
            $delete_stmt->close();
        } else {
            // No record exists, so deletion is already "done"
            $response = [
                'success' => true,
                'message' => 'No review data to delete',
                'deleted' => false
            ];
        }
        
        // Output response and exit
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Allow updates even if locked (for chairman-requested changes)
    // If lock is requested, we'll handle it separately
    $should_lock = isset($input['lock']) && $input['lock'] === true;
    $should_unlock = isset($input['unlock']) && $input['unlock'] === true;
    
    // Handle lock/unlock operations separately (only update status, don't require scores)
    if ($should_lock || $should_unlock) {
        if (!$exists) {
            throw new Exception('Review does not exist. Cannot ' . ($should_lock ? 'lock' : 'unlock') . ' a non-existent review.');
        }
        
        $new_status = $should_lock ? 'completed' : 'in_progress';
        $new_lock = $should_lock ? 1 : 0;
        
        $lock_query = "UPDATE expert_reviews SET 
            is_locked = ?,
            review_status = ?,
            updated_at = NOW()
            WHERE id = ?";
        
        $lock_stmt = $conn->prepare($lock_query);
        if (!$lock_stmt) {
            throw new Exception('Failed to prepare lock query: ' . $conn->error);
        }
        
        $lock_stmt->bind_param("isi", $new_lock, $new_status, $review_id);
        if ($lock_stmt->execute()) {
            $response = [
                'success' => true,
                'message' => 'Review ' . ($should_lock ? 'locked' : 'unlocked') . ' successfully',
                'is_locked' => (bool)$new_lock
            ];
        } else {
            throw new Exception('Failed to ' . ($should_lock ? 'lock' : 'unlock') . ' review: ' . $lock_stmt->error);
        }
        $lock_stmt->close();
        
        // Output and exit for lock/unlock
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($exists) {
        // UPDATE existing review (allow updates even if locked)
        // Check if JSON columns exist, if not use basic update
        $check_json_col = $conn->query("SHOW COLUMNS FROM expert_reviews LIKE 'expert_item_scores'");
        $has_json_cols = $check_json_col && $check_json_col->num_rows > 0;
        mysqli_free_result($check_json_col);
        
        if ($has_json_cols) {
            $update_query = "UPDATE expert_reviews SET
                dept_score_section_1 = ?,
                dept_score_section_2 = ?,
                dept_score_section_3 = ?,
                dept_score_section_4 = ?,
                dept_score_section_5 = ?,
                dept_total_score = ?,
                expert_score_section_1 = ?,
                expert_score_section_2 = ?,
                expert_score_section_3 = ?,
                expert_score_section_4 = ?,
                expert_score_section_5 = ?,
                expert_total_score = ?,
                expert_item_scores = ?,
                expert_narrative_scores = ?,
                review_notes = ?,
                review_status = ?,
                is_locked = ?,
                updated_at = NOW()
                WHERE id = ?";
        } else {
            $update_query = "UPDATE expert_reviews SET
                dept_score_section_1 = ?,
                dept_score_section_2 = ?,
                dept_score_section_3 = ?,
                dept_score_section_4 = ?,
                dept_score_section_5 = ?,
                dept_total_score = ?,
                expert_score_section_1 = ?,
                expert_score_section_2 = ?,
                expert_score_section_3 = ?,
                expert_score_section_4 = ?,
                expert_score_section_5 = ?,
                expert_total_score = ?,
                review_notes = ?,
                review_status = ?,
                is_locked = ?,
                updated_at = NOW()
                WHERE id = ?";
        }
        
        $update_stmt = $conn->prepare($update_query);
        if (!$update_stmt) {
            throw new Exception('Failed to prepare update query: ' . $conn->error);
        }
        
        // Parameters: 6 dept scores (d) + 6 expert scores (d) + 1 notes (s) + 1 status (s) + 1 lock (i) + 1 id (i) = 16 params
        // Type string: 12 d's + 2 s's + 2 i's = "ddddddddddddsii" (must be exactly 16 chars)
        // Preserve existing lock status when doing regular save (lock/unlock handled separately above)
        $new_status = $is_locked ? 'completed' : 'in_progress';
        $new_lock = $is_locked ? 1 : 0;
        
        // Ensure all values are set (convert null to 0 for numeric, empty string for text)
        $dept_1 = (float)($dept_scores['section_1'] ?? 0);
        $dept_2 = (float)($dept_scores['section_2'] ?? 0);
        $dept_3 = (float)($dept_scores['section_3'] ?? 0);
        $dept_4 = (float)($dept_scores['section_4'] ?? 0);
        $dept_5 = (float)($dept_scores['section_5'] ?? 0);
        $dept_total = (float)($dept_scores['total'] ?? 0);
        $expert_1 = (float)($expert_score_section_1 ?? 0);
        $expert_2 = (float)($expert_score_section_2 ?? 0);
        $expert_3 = (float)($expert_score_section_3 ?? 0);
        $expert_4 = (float)($expert_score_section_4 ?? 0);
        $expert_5 = (float)($expert_score_section_5 ?? 0);
        $expert_total = (float)($expert_total_score ?? 0);
        $notes = (string)($review_notes ?? '');
        $status = (string)($new_status ?? 'in_progress');
        $lock = (int)($new_lock ?? 0);
        $id = (int)($review_id ?? 0);
        
        // Build parameters based on whether JSON columns exist
        if ($has_json_cols) {
            // Type string: 12 d's (6 dept + 6 expert scores) + 2 s's (item_scores JSON, narrative_scores JSON) + 1 s (notes) + 1 s (status) + 2 i's (lock, id) = 18 chars
            $type_string = "ddddddddddddssssii";  // 12 d's, 4 s's (2 JSON + notes + status), 2 i's
            $item_scores_json = $expert_item_scores ?? null;
            $narrative_scores_json = $expert_narrative_scores ?? null;
            $params = [$dept_1, $dept_2, $dept_3, $dept_4, $dept_5, $dept_total,
                       $expert_1, $expert_2, $expert_3, $expert_4, $expert_5, $expert_total,
                       $item_scores_json, $narrative_scores_json, $notes, $status, $lock, $id];
        } else {
            // Type string: 12 d's (6 dept + 6 expert scores) + 2 s's (notes, status) + 2 i's (lock, id) = 16 chars
            $type_string = "ddddddddddddssii";  // 12 d's, 2 s's, 2 i's
            $params = [$dept_1, $dept_2, $dept_3, $dept_4, $dept_5, $dept_total,
                       $expert_1, $expert_2, $expert_3, $expert_4, $expert_5, $expert_total,
                       $notes, $status, $lock, $id];
        }
        
        // Debug: Verify counts match
        if (strlen($type_string) !== count($params)) {
            error_log("bind_param mismatch: type_string length=" . strlen($type_string) . ", param count=" . count($params));
            error_log("Type string: " . $type_string);
            error_log("Params: " . print_r($params, true));
            throw new Exception('bind_param type string length (' . strlen($type_string) . ') does not match parameter count (' . count($params) . ')');
        }
        
        $update_stmt->bind_param($type_string, ...$params);
        
        if ($update_stmt->execute()) {
            $response = [
                'success' => true,
                'message' => 'Review saved successfully',
                'review_id' => $review_id,
                'scores' => [
                    'section_1' => $expert_score_section_1,
                    'section_2' => $expert_score_section_2,
                    'section_3' => $expert_score_section_3,
                    'section_4' => $expert_score_section_4,
                    'section_5' => $expert_score_section_5,
                    'total' => $expert_total_score
                ]
            ];
        } else {
            throw new Exception('Failed to update review: ' . $update_stmt->error);
        }
        $update_stmt->close();
    } else {
        // INSERT new review
        // Check if JSON columns exist
        $check_json_col = $conn->query("SHOW COLUMNS FROM expert_reviews LIKE 'expert_item_scores'");
        $has_json_cols = $check_json_col && $check_json_col->num_rows > 0;
        mysqli_free_result($check_json_col);
        
        if ($has_json_cols) {
            $insert_query = "INSERT INTO expert_reviews (
                expert_email, dept_id, academic_year, category,
                dept_score_section_1, dept_score_section_2, dept_score_section_3, 
                dept_score_section_4, dept_score_section_5, dept_total_score,
                expert_score_section_1, expert_score_section_2, expert_score_section_3,
                expert_score_section_4, expert_score_section_5, expert_total_score,
                expert_item_scores, expert_narrative_scores,
                review_notes, review_status, review_started_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_progress', NOW(), NOW())";
        } else {
            $insert_query = "INSERT INTO expert_reviews (
                expert_email, dept_id, academic_year, category,
                dept_score_section_1, dept_score_section_2, dept_score_section_3, 
                dept_score_section_4, dept_score_section_5, dept_total_score,
                expert_score_section_1, expert_score_section_2, expert_score_section_3,
                expert_score_section_4, expert_score_section_5, expert_total_score,
                review_notes, review_status, review_started_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_progress', NOW(), NOW())";
        }
        
        $insert_stmt = $conn->prepare($insert_query);
        if (!$insert_stmt) {
            throw new Exception('Failed to prepare insert query: ' . $conn->error);
        }
        
        // Ensure all values are set
        $dept_1 = (float)($dept_scores['section_1'] ?? 0);
        $dept_2 = (float)($dept_scores['section_2'] ?? 0);
        $dept_3 = (float)($dept_scores['section_3'] ?? 0);
        $dept_4 = (float)($dept_scores['section_4'] ?? 0);
        $dept_5 = (float)($dept_scores['section_5'] ?? 0);
        $dept_total = (float)($dept_scores['total'] ?? 0);
        $expert_1 = (float)($expert_score_section_1 ?? 0);
        $expert_2 = (float)($expert_score_section_2 ?? 0);
        $expert_3 = (float)($expert_score_section_3 ?? 0);
        $expert_4 = (float)($expert_score_section_4 ?? 0);
        $expert_5 = (float)($expert_score_section_5 ?? 0);
        $expert_total = (float)($expert_total_score ?? 0);
        $notes = (string)($review_notes ?? '');
        $category = (string)($expert_category ?? '');
        
        if ($has_json_cols) {
            // Type string: siss (4) + dddddd (6 dept) + dddddd (6 expert) + ss (2 JSON) + s (notes) = 19 chars
            $type_string_insert = "sissddddddddddddsss";
            $item_scores_json = $expert_item_scores ?? null;
            $narrative_scores_json = $expert_narrative_scores ?? null;
            $params_insert = [$expert_email, $dept_id, $academic_year, $category,
                             $dept_1, $dept_2, $dept_3, $dept_4, $dept_5, $dept_total,
                             $expert_1, $expert_2, $expert_3, $expert_4, $expert_5, $expert_total,
                             $item_scores_json, $narrative_scores_json, $notes];
        } else {
            // Type string: siss (4) + dddddd (6 dept) + dddddd (6 expert) + s (notes) = 17 chars
            $type_string_insert = "sissdddddddddddds";
            $params_insert = [$expert_email, $dept_id, $academic_year, $category,
                             $dept_1, $dept_2, $dept_3, $dept_4, $dept_5, $dept_total,
                             $expert_1, $expert_2, $expert_3, $expert_4, $expert_5, $expert_total,
                             $notes];
        }
        
        // Debug: Verify counts match
        if (strlen($type_string_insert) !== count($params_insert)) {
            error_log("INSERT bind_param mismatch: type_string length=" . strlen($type_string_insert) . ", param count=" . count($params_insert));
            throw new Exception('INSERT bind_param type string length (' . strlen($type_string_insert) . ') does not match parameter count (' . count($params_insert) . ')');
        }
        
        $insert_stmt->bind_param($type_string_insert, ...$params_insert);
        
        if ($insert_stmt->execute()) {
            $review_id = $insert_stmt->insert_id;
            $response = [
                'success' => true,
                'message' => 'Review created successfully',
                'review_id' => $review_id,
                'scores' => [
                    'section_1' => $expert_score_section_1,
                    'section_2' => $expert_score_section_2,
                    'section_3' => $expert_score_section_3,
                    'section_4' => $expert_score_section_4,
                    'section_5' => $expert_score_section_5,
                    'total' => $expert_total_score
                ]
            ];
        } else {
            throw new Exception('Failed to create review: ' . $insert_stmt->error);
        }
        $insert_stmt->close();
    }
    
} catch (Exception $e) {
    // Log the error
    error_log("Expert Review Save Error: " . $e->getMessage() . " | File: " . __FILE__ . " | Line: " . $e->getLine());
    $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
} catch (Error $e) {
    // Log fatal errors
    error_log("Expert Review Save Fatal Error: " . $e->getMessage() . " | File: " . __FILE__ . " | Line: " . $e->getLine());
    $response = ['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()];
}

// CRITICAL: Clear ALL output buffers before final output
while (ob_get_level() > 0) {
    ob_end_clean();
}

// CRITICAL: Output response once at the end
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
?>

