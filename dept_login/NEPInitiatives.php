<?php
// Start session first - before any other code (EXACTLY like Departmental_Governance.php)
require('session.php');

// Suppress error reporting and start output buffering (EXACTLY like Departmental_Governance.php)
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start();

// Load config.php to ensure database connection is available
if (!isset($conn) || !$conn) {
    require_once(__DIR__ . '/../config.php');
}

// ============================================================================
// POST HANDLER - MUST BE ABSOLUTE FIRST, BEFORE ANYTHING ELSE
// ============================================================================
// Check for form submission FIRST - before any output
// CRITICAL: Check for form_submitted OR button names to catch all submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['form_submitted']) || isset($_POST['save_nep']) || isset($_POST['update_nep']))) {
    // Clean all output buffers BEFORE any processing (like Departmental_Governance.php)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Load config if needed - MUST be loaded for database connection
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    
    try {
        // CSRF validation (like Departmental_Governance.php)
        if (file_exists(__DIR__ . '/csrf.php')) {
            require_once(__DIR__ . '/csrf.php');
            if (function_exists('validate_csrf')) {
                $csrf = $_POST['csrf_token'] ?? '';
                if (empty($csrf)) {
                    $_SESSION['error'] = 'Security token missing. Please refresh and try again.';
                    while (ob_get_level() > 0) {
                        @ob_end_clean();
                    }
                    @ini_set('display_errors', 0);
                    @error_reporting(0);
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    @ob_clean();
                    @ob_end_clean();
                    if (!headers_sent()) {
                        header('Location: NEPInitiatives.php', true, 303);
                        header('Cache-Control: no-cache, must-revalidate, max-age=0');
                    }
                    exit;
                }
                if (!validate_csrf($csrf)) {
                    $_SESSION['error'] = 'Security token validation failed. Please refresh and try again.';
                    while (ob_get_level() > 0) {
                        @ob_end_clean();
                    }
                    @ini_set('display_errors', 0);
                    @error_reporting(0);
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    @ob_clean();
                    @ob_end_clean();
                    if (!headers_sent()) {
                        header('Location: NEPInitiatives.php', true, 303);
                        header('Cache-Control: no-cache, must-revalidate, max-age=0');
                    }
                    exit;
                }
            }
        }
        
        // Get department ID
        $admin_username = $_SESSION['admin_username'] ?? '';
        if (empty($admin_username)) {
            throw new Exception("Session expired. Please login again.");
        }
        
        $dept_query = "SELECT DEPT_ID FROM department_master WHERE EMAIL = ? LIMIT 1";
        $dept_stmt = mysqli_prepare($conn, $dept_query);
        if (!$dept_stmt) {
            throw new Exception("Database error while fetching department ID.");
        }
        mysqli_stmt_bind_param($dept_stmt, 's', $admin_username);
        mysqli_stmt_execute($dept_stmt);
        $dept_result = mysqli_stmt_get_result($dept_stmt);
        $dept_info = mysqli_fetch_assoc($dept_result);
        if ($dept_result) {
            mysqli_free_result($dept_result);
        }
        mysqli_stmt_close($dept_stmt);
        
        if (!$dept_info || !isset($dept_info['DEPT_ID'])) {
            throw new Exception("Department not found. Please login again.");
        }
    
        // Calculate academic year - use centralized function (CRITICAL: July onwards = new year)
        if (!function_exists('getAcademicYear')) {
            if (file_exists(__DIR__ . '/common_functions.php')) {
                require_once(__DIR__ . '/common_functions.php');
            }
        }
        if (function_exists('getAcademicYear')) {
            $A_YEAR = getAcademicYear();
        } else {
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            // CRITICAL: July onwards (month >= 7) = new year (2026-2027), Jan-June = old year (2024-2025)
            $A_YEAR = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 2) . '-' . ($current_year - 1);
        }
        $a_year = $A_YEAR; // Use full academic year string for database
        $dept_id = (int) $dept_info['DEPT_ID'];
        
        // CRITICAL: Check if data exists for this department and year (prevent duplicate submissions)
        $check_existing = "SELECT * FROM nepmarks WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
        $existing_stmt = mysqli_prepare($conn, $check_existing);
        $existing_data = null;
        if ($existing_stmt) {
            mysqli_stmt_bind_param($existing_stmt, 'is', $dept_id, $a_year);
            mysqli_stmt_execute($existing_stmt);
            $existing_result = mysqli_stmt_get_result($existing_stmt);
            if ($existing_result && mysqli_num_rows($existing_result) > 0) {
                $existing_data = mysqli_fetch_assoc($existing_result);
            }
            if ($existing_result) {
                mysqli_free_result($existing_result);
            }
            mysqli_stmt_close($existing_stmt);
        }
        
        // CRITICAL: If data exists and this is a new submission (not update), prevent duplicate
        if ($existing_data && !isset($_POST['update_nep']) && isset($_POST['save_nep'])) {
            $_SESSION['error'] = "Data already exists for academic year $A_YEAR. Form is locked. Use Update button to modify.";
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            if (!headers_sent()) {
                header('Location: NEPInitiatives.php', true, 303);
            }
            exit;
        }

        // Process checkbox data and store as JSON
        // CRITICAL: Get checkbox values - might come from actual checkboxes OR hidden inputs (when checkboxes were disabled)
        // Hidden inputs have the same name, so they'll be merged into the array automatically
        $nep_initiatives_raw = isset($_POST['nep_initiatives']) && is_array($_POST['nep_initiatives']) ? $_POST['nep_initiatives'] : [];
        $pedagogical_raw = isset($_POST['pedagogical']) && is_array($_POST['pedagogical']) ? $_POST['pedagogical'] : [];
        $assessments_raw = isset($_POST['assessments']) && is_array($_POST['assessments']) ? $_POST['assessments'] : [];
        
        // Remove duplicates and filter empty values
        $nep_initiatives = array_values(array_unique(array_filter($nep_initiatives_raw, function($v) { return !empty(trim($v)); })));
        $pedagogical = array_values(array_unique(array_filter($pedagogical_raw, function($v) { return !empty(trim($v)); })));
        $assessments = array_values(array_unique(array_filter($assessments_raw, function($v) { return !empty(trim($v)); })));
        
        
        // Process "Any other" text fields
        $nep_any_other_text = isset($_POST['nep_any_other_text']) ? trim($_POST['nep_any_other_text']) : '';
        $ped_any_other_text = isset($_POST['ped_any_other_text']) ? trim($_POST['ped_any_other_text']) : '';
        $assess_any_other_text = isset($_POST['assess_any_other_text']) ? trim($_POST['assess_any_other_text']) : '';
        
        
        // Add "Any other" text to the respective arrays if provided
        if (!empty($nep_any_other_text)) {
            $nep_initiatives[] = "Any other Innovative NEP Initiative/ Professional Activity: " . $nep_any_other_text;
        }
        if (!empty($ped_any_other_text)) {
            $pedagogical[] = "Any other Innovative Pedagogical Approach: " . $ped_any_other_text;
        }
        if (!empty($assess_any_other_text)) {
            $assessments[] = "Any other Assessment activity/ approach: " . $assess_any_other_text;
        }
        
        // Counts from checkboxes
        $nep_count = count($nep_initiatives);
        $ped_count = count($pedagogical);
        $assess_count = count($assessments);
        
        // Convert to JSON for database storage (don't escape here - prepared statements handle it)
        $nep_initiatives_json = json_encode($nep_initiatives, JSON_UNESCAPED_UNICODE);
        $pedagogical_json = json_encode($pedagogical, JSON_UNESCAPED_UNICODE);
        $assessments_json = json_encode($assessments, JSON_UNESCAPED_UNICODE);

        $moocs = isset($_POST['moocs']) && $_POST['moocs'] !== '' ? (int) $_POST['moocs'] : 0;
        
        // Process multiple MOOC entries
        $mooc_entries = [];
        if ($moocs > 0) {
            for ($i = 1; $i <= $moocs; $i++) {
                $platform = isset($_POST["mooc_platform_$i"]) ? trim($_POST["mooc_platform_$i"]) : '';
                $title = isset($_POST["mooc_title_$i"]) ? trim($_POST["mooc_title_$i"]) : '';
                $students = isset($_POST["mooc_students_$i"]) ? (int) $_POST["mooc_students_$i"] : 0;
                $credits = isset($_POST["mooc_credits_$i"]) ? (int) $_POST["mooc_credits_$i"] : 0;
                
                if (!empty($platform) || !empty($title) || $students > 0 || $credits > 0) {
                    $mooc_entries[] = [
                        'platform' => $platform,
                        'title' => $title,
                        'students' => $students,
                        'credits' => $credits
                    ];
                }
            }
        }
        
        // Convert MOOC entries to JSON for database storage
        $mooc_data_json = json_encode($mooc_entries, JSON_UNESCAPED_UNICODE);
        
        $econtent = isset($_POST['econtent']) ? (float) $_POST['econtent'] : 0;
        $result_days = isset($_POST['result_days']) ? (int) $_POST['result_days'] : 0;

        // Calculate scores
        $nep_score = min($nep_count, 30);
        $ped_score = min($ped_count, 20);
        $assess_score = min($assess_count, 20);
        $mooc_score = min($moocs * 2, 10);
        $econtent_score = min($econtent, 15);
        $result_score = ($result_days <= 30) ? 5 : (($result_days <= 45) ? 3 : 0);
        $total_marks = (int) ($nep_score + $ped_score + $assess_score + $mooc_score + $econtent_score + $result_score);

        // Check if JSON columns exist, if not add them
        $columns_to_add = [
            'nep_initiatives' => 'TEXT',
            'pedagogical' => 'TEXT', 
            'assessments' => 'TEXT'
        ];
        
        foreach ($columns_to_add as $column => $type) {
            $check_column = "SHOW COLUMNS FROM nepmarks LIKE '$column'";
            $result = mysqli_query($conn, $check_column);
            if ($result) {
                if (mysqli_num_rows($result) == 0) {
                    $add_column = "ALTER TABLE nepmarks ADD COLUMN $column $type";
                    mysqli_query($conn, $add_column);
                }
                mysqli_free_result($result);
            }
        }
        
        // For single entry system, always update if exists, insert if not (like Departmental_Governance.php)
        // Log what we're about to do
        if ($existing_data) {
            // UPDATE existing record - WHERE clause includes both DEPT_ID and A_YEAR
            $update_query = "UPDATE nepmarks SET A_YEAR = ?, DEPT_ID = ?, nep_count = ?, ped_count = ?, assess_count = ?, moocs = ?, mooc_data = ?, econtent = ?, nep_score = ?, ped_score = ?, assess_score = ?, mooc_score = ?, econtent_score = ?, result_days = ?, result_score = ?, total_marks = ?, nep_initiatives = ?, pedagogical = ?, assessments = ? WHERE DEPT_ID = ? AND A_YEAR = ?";
            
            
            $stmt = $conn->prepare($update_query);
            if ($stmt) {
                // Parameter types for UPDATE (21 params):
                // SET clause (19 params): A_YEAR(s) + DEPT_ID(i) + nep_count(i) + ped_count(i) + assess_count(i) + moocs(i) + mooc_data(s) + econtent(d) + nep_score(i) + ped_score(i) + assess_score(i) + mooc_score(i) + econtent_score(d) + result_days(i) + result_score(i) + total_marks(i) + nep_initiatives(s) + pedagogical(s) + assessments(s)
                // WHERE clause (2 params): DEPT_ID(i), A_YEAR(s)
                // Type string: 'siiiiisdiididiiisssis'
                $stmt->bind_param('siiiiisdiididiiisssis', 
                    $a_year, $dept_id, $nep_count, $ped_count, $assess_count, $moocs, 
                    $mooc_data_json, $econtent,
                    $nep_score, $ped_score, $assess_score, $mooc_score, $econtent_score,
                    $result_days, $result_score, $total_marks,
                    $nep_initiatives_json, $pedagogical_json, $assessments_json,
                    $dept_id, $a_year
                );
                if ($stmt->execute()) {
                    // CRITICAL: Clear and recalculate score cache after data update
                    require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
                    clearDepartmentScoreCache($dept_id, $a_year, true);
                    
                    $_SESSION['success'] = "Record updated successfully!";
                } else {
                    $error_msg = $stmt->error;
                    if ($stmt) {
                        $stmt->close();
                    }
                    throw new Exception("Error updating record: " . $error_msg);
                }
                if ($stmt) {
                    $stmt->close();
                }
            } else {
                $prepare_error = $conn->error;
                throw new Exception("Prepare failed for update: " . $prepare_error);
            }
        } else {
            // INSERT new record
            $insert_query = "INSERT INTO nepmarks (A_YEAR, DEPT_ID, nep_count, ped_count, assess_count, moocs, mooc_data, econtent, nep_score, ped_score, assess_score, mooc_score, econtent_score, result_days, result_score, total_marks, nep_initiatives, pedagogical, assessments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($insert_query);
            if ($stmt) {
                // Parameter types for INSERT (19 params):
                // 1: A_YEAR(s)
                // 2-6: DEPT_ID(i), nep_count(i), ped_count(i), assess_count(i), moocs(i) = 5i
                // 7: mooc_data(s) = 1s
                // 8: econtent(d) = 1d
                // 9-12: nep_score(i), ped_score(i), assess_score(i), mooc_score(i) = 4i
                // 13: econtent_score(d) = 1d
                // 14-16: result_days(i), result_score(i), total_marks(i) = 3i
                // 17-19: nep_initiatives(s), pedagogical(s), assessments(s) = 3s
                // Total: 1s + 5i + 1s + 1d + 4i + 1d + 3i + 3s = 19 params
                // Type string: 'siiiiisdiididiiisss'
                $stmt->bind_param('siiiiisdiididiiisss', 
                    $a_year, $dept_id, $nep_count, $ped_count, $assess_count, $moocs, 
                    $mooc_data_json, $econtent,
                    $nep_score, $ped_score, $assess_score, $mooc_score, $econtent_score,
                    $result_days, $result_score, $total_marks,
                    $nep_initiatives_json, $pedagogical_json, $assessments_json
                );
                
                if ($stmt->execute()) {
                    $insert_id = $conn->insert_id;
                    
                    // CRITICAL: Clear and recalculate score cache after data insert
                    require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
                    clearDepartmentScoreCache($dept_id, $a_year, true);
                    
                    @file_put_contents(__DIR__ . '/nep_debug.log', date('Y-m-d H:i:s') . " - INSERT SUCCESS - New ID: $insert_id\n", FILE_APPEND);
                    
                    // Verify data was actually saved
                    $verify_query = "SELECT * FROM nepmarks WHERE id = ? LIMIT 1";
                    $verify_stmt = $conn->prepare($verify_query);
                    if ($verify_stmt) {
                        $verify_stmt->bind_param('i', $insert_id);
                        $verify_stmt->execute();
                        $verify_result = $verify_stmt->get_result();
                        if ($verify_result && $verify_result->num_rows > 0) {
                            $verified_data = $verify_result->fetch_assoc();
                        } else {
                        }
                        if ($verify_result) {
                            $verify_result->free();
                        }
                        $verify_stmt->close();
                    }
                    
                    $_SESSION['success'] = "Record added successfully!";
                } else {
                    $error_msg = $stmt->error;
                    
                    // Store stmt reference before potential reassignment
                    $insert_stmt = $stmt;
                    
                    // Check if duplicate entry error - try UPDATE instead
                    if ($conn->errno == 1062 || strpos($error_msg, 'Duplicate entry') !== false) {
                        // Close INSERT statement first
                        $insert_stmt->close();
                        
                        // Update instead - WHERE clause includes both DEPT_ID and A_YEAR
                        $update_query = "UPDATE nepmarks SET A_YEAR = ?, DEPT_ID = ?, nep_count = ?, ped_count = ?, assess_count = ?, moocs = ?, mooc_data = ?, econtent = ?, nep_score = ?, ped_score = ?, assess_score = ?, mooc_score = ?, econtent_score = ?, result_days = ?, result_score = ?, total_marks = ?, nep_initiatives = ?, pedagogical = ?, assessments = ? WHERE DEPT_ID = ? AND A_YEAR = ?";
                        
                        $update_stmt = $conn->prepare($update_query);
                        if ($update_stmt) {
                            $update_stmt->bind_param('siiiiisdiididiiisssis', 
                                $a_year, $dept_id, $nep_count, $ped_count, $assess_count, $moocs, 
                                $mooc_data_json, $econtent,
                                $nep_score, $ped_score, $assess_score, $mooc_score, $econtent_score,
                                $result_days, $result_score, $total_marks,
                                $nep_initiatives_json, $pedagogical_json, $assessments_json,
                                $dept_id, $a_year
                            );
                            if ($update_stmt->execute()) {
                                $affected = $update_stmt->affected_rows;
                                
                                // CRITICAL: Clear and recalculate score cache after data update
                                require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
                                clearDepartmentScoreCache($dept_id, $a_year, true);
                                
                                $_SESSION['success'] = "Record updated successfully!";
                                $update_stmt->close();
                            } else {
                                $update_error = $update_stmt->error;
                                $update_stmt->close();
                                throw new Exception("Error updating record after duplicate: " . $update_error);
                            }
                        } else {
                            throw new Exception("Error adding record (duplicate): " . $conn->error);
                        }
                    } else {
                        // Close statement before throwing exception
                        $insert_stmt->close();
                        throw new Exception("Error adding record: " . $error_msg);
                    }
                }
                if (isset($stmt) && $stmt) {
                    $stmt->close();
                }
            } else {
                $prepare_error = $conn->error;
                throw new Exception("Prepare failed for insert: " . $prepare_error);
            }
        }
        
        // CRITICAL: All database statements are already closed in try/catch blocks above
        // Database connection will be closed by unified_footer.php when page loads after redirect
        // DO NOT close connection here - it may be used by other includes, and unified_footer handles it
        
        // Redirect after successful submission
        // CRITICAL: Always redirect to prevent resubmission on refresh (POST-Redirect-GET pattern)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Ensure no output before redirect
        @ini_set('display_errors', 0);
        @error_reporting(0);
        
        if (!headers_sent()) {
            header('Location: NEPInitiatives.php?updated=1', true, 303);
            header('Cache-Control: no-cache, must-revalidate');
        } else {
            // If headers already sent, use JavaScript redirect
            echo '<script>window.location.href = "NEPInitiatives.php?updated=1";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=NEPInitiatives.php?updated=1"></noscript>';
        }
        exit;
        
    } catch (Exception $e) {
        // Ensure any open statements are closed
        if (isset($stmt) && $stmt) {
            @$stmt->close();
        }
        if (isset($update_stmt) && $update_stmt) {
            @$update_stmt->close();
        }
        if (isset($insert_stmt) && $insert_stmt) {
            @$insert_stmt->close();
        }
        if (isset($dept_stmt) && $dept_stmt) {
            @mysqli_stmt_close($dept_stmt);
        }
        if (isset($existing_stmt) && $existing_stmt) {
            @mysqli_stmt_close($existing_stmt);
        }
        
        $_SESSION['error'] = "Error processing form: " . $e->getMessage();
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        
        // Ensure no output before redirect
        @ini_set('display_errors', 0);
        @error_reporting(0);
        
        // Ensure session is written before redirect
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        
        @ob_clean();
        @ob_end_clean();
        
        if (!headers_sent()) {
            header('Location: NEPInitiatives.php', true, 303);
            header('Cache-Control: no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        exit;
    } catch (Error $e) {
        // Catch PHP 7+ Errors (fatal errors, type errors, etc.)
        $_SESSION['error'] = "Fatal error: " . $e->getMessage();
        
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        
        @ini_set('display_errors', 0);
        @error_reporting(0);
        
        if (!headers_sent()) {
            header('Location: NEPInitiatives.php', true, 303);
            header('Cache-Control: no-cache, must-revalidate');
        } else {
            echo '<script>window.location.href = "NEPInitiatives.php";</script>';
        }
        exit;
    }
}
// END POST HANDLER

// ============================================================================
// PDF UPLOAD HANDLING - MUST BE BEFORE ANY HTML OUTPUT
// ============================================================================

// Handle PDF uploads - Route through common_upload_handler.php
if (isset($_POST['upload_document'])) {
    // Disable error display and clear all output buffers
    error_reporting(0);
    ini_set('display_errors', 0);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Load CSRF utilities
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
        if (function_exists('csrf_token')) {
            csrf_token(); // Generate token if it doesn't exist
        }
    }
    
        // Validate CSRF token
            if (function_exists('validate_csrf')) {
                $csrf_token = $_POST['csrf_token'] ?? '';
                if (empty($csrf_token) || !validate_csrf($csrf_token)) {
            header('Content-Type: application/json; charset=utf-8');
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Security token validation failed. Please refresh the page and try again.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
    
    // Route through common_upload_handler.php
    require_once(__DIR__ . '/common_upload_handler.php');
    
    // Set required variables for common handler - match Departmental_Governance.php exactly
    $dept_id = $userInfo['DEPT_ID'] ?? 0;
    $dept = $dept_id; // Keep for backward compatibility
    
    // Get dept_code from dept_info if available, otherwise use dept_id
    if (isset($dept_info) && isset($dept_info['DEPT_COLL_NO'])) {
        $dept_code = $dept_info['DEPT_COLL_NO'];
    } else {
        // Fallback: try to get from database
        $dept_code_query = "SELECT DEPT_COLL_NO FROM department_master WHERE DEPT_ID = ? LIMIT 1";
        $dept_code_stmt = mysqli_prepare($conn, $dept_code_query);
        if ($dept_code_stmt) {
            mysqli_stmt_bind_param($dept_code_stmt, "i", $dept_id);
            mysqli_stmt_execute($dept_code_stmt);
            $dept_code_result = mysqli_stmt_get_result($dept_code_stmt);
            if ($dept_code_row = mysqli_fetch_assoc($dept_code_result)) {
                $dept_code = $dept_code_row['DEPT_COLL_NO'] ?? $dept_id;
            } else {
                $dept_code = $dept_id;
            }
            if ($dept_code_result) {
                mysqli_free_result($dept_code_result);
            }
            mysqli_stmt_close($dept_code_stmt);
        } else {
            $dept_code = $dept_id;
        }
        }
        
    // Calculate academic year - MUST BE BEFORE using in upload_dir
    // Use A_YEAR format: uploads/{A_YEAR}/DEPARTMENT/{department_ID}/nep_initiatives/FILENAME.pdf
    // Calculate academic year - use centralized function (CRITICAL: July onwards = new year)
    if (!function_exists('getAcademicYear')) {
        if (file_exists(__DIR__ . '/common_functions.php')) {
            require_once(__DIR__ . '/common_functions.php');
        }
    }
    if (function_exists('getAcademicYear')) {
        $A_YEAR = getAcademicYear();
    } else {
        $current_year = (int)date('Y');
        $current_month = (int)date('n');
        // CRITICAL: July onwards (month >= 7) = new year (2025-2026), Jan-June = old year (2024-2025)
        if ($current_month >= 7) {
            $A_YEAR = $current_year . '-' . ($current_year + 1);
        } else {
            $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
        }
    }
    
    try {
        // Call the handler function
        $result = handleDocumentUpload('nep_initiatives', 'NEP Initiatives', [
            'upload_dir' => dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/nep_initiatives/",
            'max_size' => 10,
            'document_title' => 'NEP Initiatives Documentation',
            'srno' => (int)($_POST['srno'] ?? 1),
            'file_id' => $_POST['file_id'] ?? 'nep_doc'
        ]);
        
        // Ensure result is an array
        if (!is_array($result)) {
            $result = ['success' => false, 'message' => 'Invalid response from upload handler'];
        }
        
        // Clear ALL output buffers completely - CRITICAL for clean JSON
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Suppress any error output that might corrupt JSON
        @ini_set('display_errors', 0);
        @error_reporting(0);
        
        // Set headers BEFORE any output
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }
        
        // Output JSON and exit immediately
        $json_output = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json_output === false) {
            $json_output = @json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json_output === false) {
                $json_output = json_encode(['success' => false, 'message' => 'Error encoding response'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        echo $json_output;
        exit;
    } catch (Exception $e) {
        // Clear ALL output buffers completely - CRITICAL
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Suppress any error output
        @ini_set('display_errors', 0);
        @error_reporting(0);
        
        // Set headers BEFORE any output
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }
        
        $json_output = json_encode([
            'success' => false,
            'message' => 'Upload failed: ' . $e->getMessage()
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        echo $json_output;
    exit;
    } catch (Error $e) {
        // Clear ALL output buffers completely - CRITICAL
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Suppress any error output
        @ini_set('display_errors', 0);
        @error_reporting(0);
        
        // Set headers BEFORE any output
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }
        
        $json_output = json_encode([
            'success' => false,
            'message' => 'Upload failed: ' . $e->getMessage()
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        echo $json_output;
        exit;
    }
}

// Handle PDF deletion
if (isset($_GET['delete_doc'])) {
    // CRITICAL: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session and load config FIRST (silently)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    ob_start();
    if (!isset($conn) || !$conn) {
        if (file_exists(__DIR__ . '/../config.php')) {
            require_once(__DIR__ . '/../config.php');
        }
    }
    ob_end_clean();
    
    ob_start();
    if (file_exists(__DIR__ . '/session.php')) {
        require_once(__DIR__ . '/session.php');
    }
    ob_end_clean();
    
    // Clear buffers again
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // CRITICAL #4: Set headers BEFORE any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $srno = (int)($_GET['srno'] ?? 0);
        
        if ($srno <= 0) {
            $response = ['success' => false, 'message' => 'Invalid serial number'];
        } else {
            // Get department ID from admin username (email) using prepared statement
            $admin_username = $_SESSION['admin_username'] ?? '';
            if (empty($admin_username)) {
                $response = ['success' => false, 'message' => 'Session expired. Please login again.'];
            } else {
                $dept_query = "SELECT DEPT_ID FROM department_master WHERE EMAIL = ? LIMIT 1";
                $dept_stmt = mysqli_prepare($conn, $dept_query);
                if (!$dept_stmt) {
                    $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
                } else {
                    mysqli_stmt_bind_param($dept_stmt, 's', $admin_username);
                    if (mysqli_stmt_execute($dept_stmt)) {
                        $dept_result = mysqli_stmt_get_result($dept_stmt);
                        $dept_info = mysqli_fetch_assoc($dept_result);
                        if ($dept_result) {
                            mysqli_free_result($dept_result);  // CRITICAL: Free result
                        }
                        mysqli_stmt_close($dept_stmt);
                        
                        if (!$dept_info || !isset($dept_info['DEPT_ID'])) {
                            $response = ['success' => false, 'message' => 'Department not found'];
                        } else {
                            $dept_id = $dept_info['DEPT_ID'];
                            
                            // Calculate academic year
                            // Calculate academic year - use centralized function (CRITICAL: July onwards = new year)
                            if (!function_exists('getAcademicYear')) {
                                if (file_exists(__DIR__ . '/common_functions.php')) {
                                    require_once(__DIR__ . '/common_functions.php');
                                }
                            }
                            if (function_exists('getAcademicYear')) {
                                $academic_year = getAcademicYear();
                            } else {
                                // Fallback calculation - matches getAcademicYear() logic exactly
                                $current_year = (int)date('Y');
                                $current_month = (int)date('n');
                                // July onwards (month >= 7): current_year to current_year+1
                                // Below July (month < 7): (current_year-2) to (current_year-1)
                                if ($current_month >= 7) {
                                    $academic_year = $current_year . '-' . ($current_year + 1);
                                } else {
                                    $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
                                }
                            }
                            
                            // CRITICAL FIX: Query with fallback to check current year AND previous year
                            // This handles cases where documents were uploaded in previous academic year
                            $get_file_query = "SELECT file_path, file_name, upload_date, id, academic_year 
                                              FROM supporting_documents 
                                              WHERE dept_id = ? AND page_section = 'nep_initiatives' 
                                              AND serial_number = ? AND (academic_year = ? OR academic_year = ?) 
                                              AND status = 'active' 
                                              ORDER BY academic_year DESC, id DESC LIMIT 1";
                            $get_stmt = mysqli_prepare($conn, $get_file_query);
                            if (!$get_stmt) {
                                $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
                            } else {
                                // Calculate previous academic year for fallback
                                $prev_year = '';
                                $current_year = (int)date('Y');
                                $current_month = (int)date('n');
                                if ($current_month >= 7) {
                                    // Current: 2025-2026, Previous: 2024-2025
                                    $prev_year = ($current_year - 1) . '-' . $current_year;
                                } else {
                                    // Current: 2024-2025, Previous: 2023-2024
                                    $prev_year = ($current_year - 3) . '-' . ($current_year - 2);
                                }
                                mysqli_stmt_bind_param($get_stmt, 'iiss', $dept_id, $srno, $academic_year, $prev_year);
                                if (mysqli_stmt_execute($get_stmt)) {
                                    $result = mysqli_stmt_get_result($get_stmt);
                                    
                                    if ($result && mysqli_num_rows($result) > 0) {
                                        $row = mysqli_fetch_assoc($result);
                                        $file_path = $row['file_path'];
                                        $doc_year = $row['academic_year'] ?? $academic_year;
                                        mysqli_free_result($result);  // CRITICAL: Free result
                                        mysqli_stmt_close($get_stmt);
                                        
                                        // Delete file from filesystem safely (use document's actual academic year)
                                        $project_root = dirname(__DIR__);
                                        $phys_path = $file_path;
                                        
                                        // Handle different path formats
                                        if (strpos($phys_path, $project_root) === 0) {
                                            // Already absolute path
                                            $phys_path = $file_path;
                                        } elseif (strpos($phys_path, '../') === 0) {
                                            $phys_path = $project_root . '/' . str_replace('../', '', $phys_path);
                                        } elseif (strpos($phys_path, 'uploads/') === 0) {
                                            $phys_path = $project_root . '/' . $phys_path;
                                        } else {
                                            // Fallback: construct path using document's academic year
                                            $filename = basename($phys_path);
                                            $phys_path = $project_root . "/uploads/{$doc_year}/DEPARTMENT/{$dept_id}/nep_initiatives/{$filename}";
                                        }
                                        $phys_path = str_replace('\\', '/', $phys_path);
                                        
                                        if ($phys_path && file_exists($phys_path)) {
                                            @unlink($phys_path);
                                        }
                                        
                                        // Soft delete using prepared statement (use document's actual academic year)
                                        $delete_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE academic_year = ? AND dept_id = ? AND page_section = 'nep_initiatives' AND serial_number = ? AND status = 'active'";
                                        $delete_stmt = mysqli_prepare($conn, $delete_query);
                                        if (!$delete_stmt) {
                                            $response = ['success' => false, 'message' => 'Database error: Failed to prepare delete query'];
                                        } else {
                                            mysqli_stmt_bind_param($delete_stmt, 'sis', $doc_year, $dept_id, $srno);
                                            if (mysqli_stmt_execute($delete_stmt)) {
                                                $response = ['success' => true, 'message' => 'Document deleted successfully'];
                                            } else {
                                                $response = ['success' => false, 'message' => 'Database error: Failed to execute delete query'];
                                            }
                                            mysqli_stmt_close($delete_stmt);  // CRITICAL: Close statement
                                        }
                                    } else {
                                        if ($result) {
                                            mysqli_free_result($result);  // CRITICAL: Free result even if empty
                                        }
                                        mysqli_stmt_close($get_stmt);
                                        $response = ['success' => false, 'message' => 'Document not found'];
                                    }
                                } else {
                                    mysqli_stmt_close($get_stmt);
                                    $response = ['success' => false, 'message' => 'Database error: Failed to execute query'];
                                }
                            }
                        }
                    } else {
                        mysqli_stmt_close($dept_stmt);
                        $response = ['success' => false, 'message' => 'Database error: Failed to execute department query'];
                    }
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()];
    } catch (Error $e) {
        $response = ['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()];
    }
    
    // Clear buffers before output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Output response once at end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// HANDLE check_all_docs - BATCH endpoint to get ALL document statuses in ONE query
// This replaces 6 individual queries with a single efficient query
// ============================================================================
if (isset($_GET['check_all_docs'])) {
    // CRITICAL: Clear ALL output buffers FIRST - prevent any output before JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Start session and load dependencies FIRST
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config silently
    ob_start();
    if (!isset($conn) || !$conn) {
        if (file_exists(__DIR__ . '/../config.php')) {
            require_once(__DIR__ . '/../config.php');
        }
    }
    ob_end_clean();
    
    // Verify connection
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(503);
        }
        echo json_encode(['success' => false, 'message' => 'Database connection unavailable'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Load session silently
    ob_start();
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    ob_end_clean();
    
    // Clear output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Set headers
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    $response = ['success' => false, 'documents' => []];
    
    try {
        // Get department ID from admin username (email)
        $admin_username = $_SESSION['admin_username'] ?? '';
        if (empty($admin_username)) {
            $response = ['success' => false, 'message' => 'Session expired. Please login again.'];
        } else {
            $dept_query = "SELECT DEPT_ID FROM department_master WHERE EMAIL = ? LIMIT 1";
            $dept_stmt = mysqli_prepare($conn, $dept_query);
            if (!$dept_stmt) {
                $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
            } else {
                mysqli_stmt_bind_param($dept_stmt, 's', $admin_username);
                if (mysqli_stmt_execute($dept_stmt)) {
                    $dept_result = mysqli_stmt_get_result($dept_stmt);
                    $dept_info = mysqli_fetch_assoc($dept_result);
                    if ($dept_result) {
                        mysqli_free_result($dept_result);
                    }
                    mysqli_stmt_close($dept_stmt);
                    
                    if (!$dept_info || !isset($dept_info['DEPT_ID'])) {
                        $response = ['success' => false, 'message' => 'Department not found'];
                    } else {
                        $dept_id = $dept_info['DEPT_ID'];
                        
                        // Calculate academic year - use centralized function (CRITICAL: July onwards = new year)
                        if (!function_exists('getAcademicYear')) {
                            if (file_exists(__DIR__ . '/common_functions.php')) {
                                require_once(__DIR__ . '/common_functions.php');
                            }
                        }
                        if (function_exists('getAcademicYear')) {
                            $academic_year = getAcademicYear();
                            } else {
                                // Fallback calculation - matches getAcademicYear() logic exactly
                                $current_year = (int)date('Y');
                                $current_month = (int)date('n');
                                // July onwards (month >= 7): current_year to current_year+1
                                // Below July (month < 7): (current_year-2) to (current_year-1)
                                if ($current_month >= 7) {
                                    $academic_year = $current_year . '-' . ($current_year + 1);
                                } else {
                                    $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
                                }
                            }
                        
                        // CRITICAL FIX: Query with fallback to check current year AND previous year
                        // This handles cases where documents were uploaded in previous academic year
                        $batch_query = "SELECT serial_number, file_path, file_name, upload_date, id, academic_year
                                       FROM supporting_documents 
                                       WHERE dept_id = ? AND page_section = 'nep_initiatives' 
                                       AND (academic_year = ? OR academic_year = ?) AND status = 'active'
                                       ORDER BY academic_year DESC, id DESC";
                        $stmt = mysqli_prepare($conn, $batch_query);
                        
                        if (!$stmt) {
                            $response = ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
                        } else {
                            // Calculate previous academic year for fallback
                            $prev_year = '';
                            $current_year = (int)date('Y');
                            $current_month = (int)date('n');
                            if ($current_month >= 7) {
                                // Current: 2025-2026, Previous: 2024-2025
                                $prev_year = ($current_year - 1) . '-' . $current_year;
                            } else {
                                // Current: 2024-2025, Previous: 2023-2024
                                $prev_year = ($current_year - 3) . '-' . ($current_year - 2);
                            }
                            mysqli_stmt_bind_param($stmt, 'iss', $dept_id, $academic_year, $prev_year);
                            
                            if (mysqli_stmt_execute($stmt)) {
                                $result = mysqli_stmt_get_result($stmt);
                                $documents = [];
                                $project_root = dirname(__DIR__);
                                
                                // Track documents by serial number, prefer current year over previous year
                                $documents_found = [];
                                while ($row = mysqli_fetch_assoc($result)) {
                                    $srno = (int)$row['serial_number'];
                                    $doc_year = $row['academic_year'] ?? '';
                                    
                                    // Only keep the most recent document for each serial number (prefer current year)
                                    if (!isset($documents_found[$srno]) || $doc_year === $academic_year) {
                                        // Convert to web-accessible path (robust path conversion like PlacementDetails.php)
                                        $file_path = $row['file_path'];
                                        $project_root = dirname(__DIR__);
                                        
                                        // Handle absolute paths
                                        if (strpos($file_path, $project_root) === 0) {
                                            $file_path = str_replace([$project_root . '/', $project_root . '\\'], '', $file_path);
                                        }
                                        
                                        // Normalize path separators
                                        $file_path = str_replace('\\', '/', trim($file_path));
                                        $file_path = ltrim($file_path, './\\/');
                                        
                                        // Convert to web-accessible path
                                        if (strpos($file_path, 'uploads/') === 0) {
                                            $file_path = '../' . $file_path;
                                        } elseif (strpos($file_path, '../') !== 0 && strpos($file_path, 'http') !== 0) {
                                            // Reconstruct path if needed using document's academic year
                                            $filename = basename($file_path);
                                            $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept_id . '/nep_initiatives/' . $filename;
                                        }
                                        
                                        $documents_found[$srno] = [
                                            'success' => true,
                                            'file_path' => $file_path,
                                            'file_name' => $row['file_name'] ?? basename($file_path),
                                            'upload_date' => $row['upload_date'] ?? date('Y-m-d H:i:s'),
                                            'document_id' => $row['id']
                                        ];
                                    }
                                }
                                $documents = $documents_found;
                                
                                mysqli_free_result($result);
                                mysqli_stmt_close($stmt);
                                
                                $response = ['success' => true, 'documents' => $documents];
                            } else {
                                mysqli_stmt_close($stmt);
                                $response = ['success' => false, 'message' => 'Query execution failed: ' . mysqli_stmt_error($stmt)];
                            }
                        }
                    }
                } else {
                    mysqli_stmt_close($dept_stmt);
                    $response = ['success' => false, 'message' => 'Database error: Failed to execute department query'];
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle document status check
if (isset($_GET['check_doc'])) {
    // Start session first
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config if needed
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    
    // Clear any previous output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Set content type to JSON
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    
    // CRITICAL #2: Initialize response in variable - build response first, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $srno = (int)($_GET['srno'] ?? 0);
        
        if ($srno <= 0) {
            $response = ['success' => false, 'message' => 'Invalid serial number'];
        } else {
            // Get department ID from admin username (email)
            $admin_username = $_SESSION['admin_username'] ?? '';
            if (empty($admin_username)) {
                $response = ['success' => false, 'message' => 'Session expired. Please login again.'];
            } else {
                $dept_query = "SELECT DEPT_ID FROM department_master WHERE EMAIL = ? LIMIT 1";
                $dept_stmt = mysqli_prepare($conn, $dept_query);
                if (!$dept_stmt) {
                    $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
                } else {
                    mysqli_stmt_bind_param($dept_stmt, 's', $admin_username);
                    if (mysqli_stmt_execute($dept_stmt)) {
                        $dept_result = mysqli_stmt_get_result($dept_stmt);
                        $dept_info = mysqli_fetch_assoc($dept_result);
                        if ($dept_result) {
                            mysqli_free_result($dept_result);  // CRITICAL: Free result
                        }
                        mysqli_stmt_close($dept_stmt);
                        
                        if (!$dept_info || !isset($dept_info['DEPT_ID'])) {
                            $response = ['success' => false, 'message' => 'Department not found'];
                        } else {
                            $dept_id = $dept_info['DEPT_ID'];
                            
                            // Calculate academic year
                            // Calculate academic year - use centralized function (CRITICAL: July onwards = new year)
                            if (!function_exists('getAcademicYear')) {
                                if (file_exists(__DIR__ . '/common_functions.php')) {
                                    require_once(__DIR__ . '/common_functions.php');
                                }
                            }
                            if (function_exists('getAcademicYear')) {
                                $academic_year = getAcademicYear();
                            } else {
                                $current_year = (int)date('Y');
                                $current_month = (int)date('n');
                                // CRITICAL: July onwards (month >= 7) = new year (2025-2026), Jan-June = old year (2024-2025)
                                $academic_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year;
                            }
                            
                            $get_file_query = "SELECT file_path, file_name, upload_date, id FROM supporting_documents WHERE academic_year = ? AND dept_id = ? AND page_section = 'nep_initiatives' AND serial_number = ? AND status = 'active' LIMIT 1";
                            $stmt = mysqli_prepare($conn, $get_file_query);
                            if (!$stmt) {
                                $response = ['success' => false, 'message' => 'Database query error: Failed to prepare query'];
                            } else {
                                mysqli_stmt_bind_param($stmt, 'sii', $academic_year, $dept_id, $srno);
                                if (mysqli_stmt_execute($stmt)) {
                                    $result = mysqli_stmt_get_result($stmt);
                                    
                                    if ($result && mysqli_num_rows($result) > 0) {
                                        $row = mysqli_fetch_assoc($result);
                                        mysqli_free_result($result);  // CRITICAL: Free result
                                        mysqli_stmt_close($stmt);
                                        
                                        // Get absolute file path for existence check
                                        $absolute_path = $row['file_path'];
                                        $project_root = dirname(__DIR__);
                                        
                                        // Convert relative path to absolute if needed
                                        if (strpos($absolute_path, $project_root) !== 0 && strpos($absolute_path, '/') !== 0 && strpos($absolute_path, 'C:/') !== 0) {
                                            if (strpos($absolute_path, '../') === 0) {
                                                $absolute_path = $project_root . '/' . str_replace('../', '', $absolute_path);
                                            } elseif (strpos($absolute_path, 'uploads/') === 0) {
                                                $absolute_path = $project_root . '/' . $absolute_path;
                                            }
                                        }
                                        
                                        // Verify file actually exists on filesystem
                                        if (!file_exists($absolute_path)) {
                                            // File doesn't exist - mark as deleted in DB and return false
                                            $update_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE academic_year = ? AND dept_id = ? AND page_section = 'nep_initiatives' AND serial_number = ? AND status = 'active'";
                                            $update_stmt = mysqli_prepare($conn, $update_query);
                                            if ($update_stmt) {
                                                mysqli_stmt_bind_param($update_stmt, 'sii', $academic_year, $dept_id, $srno);
                                                mysqli_stmt_execute($update_stmt);
                                                mysqli_stmt_close($update_stmt);
                                            }
                                            $response = ['success' => false, 'message' => 'No document found'];
                                        } else {
                                            // File exists - return web-accessible path
                                            $file_path = $row['file_path'];
                                            if (strpos($file_path, 'uploads/') === 0) {
                                                $file_path = '../' . $file_path;
                                            } elseif (strpos($file_path, $project_root) === 0) {
                                                // Convert absolute path to relative web path
                                                $file_path = str_replace($project_root . '/', '../', $file_path);
                                            } elseif (strpos($file_path, '../uploads/') !== 0) {
                                                // Fallback: construct path
                                                $filename = basename($file_path);
                                                $file_path = '../uploads/' . $academic_year . '/DEPARTMENT/' . $dept_id . '/nep_initiatives/' . $filename;
                                            }
                                            
                                            $response = [
                                                'success' => true,
                                                'file_path' => $file_path,
                                                'file_name' => $row['file_name'] ?? basename($file_path),
                                                'upload_date' => $row['upload_date'] ?? date('Y-m-d H:i:s'),
                                                'document_id' => $row['id'] ?? 0
                                            ];
                                        }
                                    } else {
                                        if ($result) {
                                            mysqli_free_result($result);  // CRITICAL: Free result even if empty
                                        }
                                        mysqli_stmt_close($stmt);
                                        $response = ['success' => false, 'message' => 'No document found'];
                                    }
                                } else {
                                    mysqli_stmt_close($stmt);
                                    $response = ['success' => false, 'message' => 'Database error: Failed to execute query'];
                                }
                            }
                        }
                    } else {
                        mysqli_stmt_close($dept_stmt);
                        $response = ['success' => false, 'message' => 'Database error: Failed to execute department query'];
                    }
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Check failed: ' . $e->getMessage()];
    } catch (Error $e) {
        $response = ['success' => false, 'message' => 'Check failed: ' . $e->getMessage()];
    }
    
    // CRITICAL #1: Clear ALL output buffers before final output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL #2: Output response once at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// MAIN FORM SUBMISSION HANDLER - MUST BE BEFORE unified_header.php
// ============================================================================

// Calculate academic year
// Database A_YEAR is now VARCHAR(20) - use full format (e.g., "2024-2025")
$current_year = (int)date("Y");
$current_month = (int)date("n"); // 1-12
// Academic year format: if month >= 6 (June onwards), it's (current_year-1)-current_year
// Otherwise (Jan-May), it's also (current_year-1)-current_year
if ($current_month >= 7) {
    $A_YEAR = $current_year . '-' . ($current_year + 1);
} else {
    $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
}

// Get department ID from admin username (email) - same logic as DetailsOfDepartment.php
$admin_username = $_SESSION['admin_username'] ?? '';
$dept_query = "SELECT DEPT_ID, DEPT_COLL_NO, DEPT_NAME FROM department_master WHERE EMAIL = ? LIMIT 1";
$dept_stmt = mysqli_prepare($conn, $dept_query);
if ($dept_stmt) {
    mysqli_stmt_bind_param($dept_stmt, 's', $admin_username);
    mysqli_stmt_execute($dept_stmt);
    $dept_result = mysqli_stmt_get_result($dept_stmt);
    $dept_info = mysqli_fetch_assoc($dept_result);
    if ($dept_result) {
        mysqli_free_result($dept_result);
    }
    mysqli_stmt_close($dept_stmt);
} else {
    $dept_info = null;
}

if (!$dept_info || !isset($dept_info['DEPT_ID'])) {
    $_SESSION['error'] = "Department not found. Please login again.";
    // Use ob_end_clean before redirect
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Location: dashboard.php');
    }
    exit;
}

$dept = $dept_info['DEPT_ID'];
$dept_id = $dept; // Alias for consistency
$dept_code = $dept_info['DEPT_COLL_NO'];
$dept_name = $dept_info['DEPT_NAME'];

// Check if data already exists for this department and year
$existing_data = null;
// Calculate academic year - use centralized function (CRITICAL: July onwards = new year)
if (!function_exists('getAcademicYear')) {
    if (file_exists(__DIR__ . '/common_functions.php')) {
        require_once(__DIR__ . '/common_functions.php');
    }
}
if (function_exists('getAcademicYear')) {
    $A_YEAR = getAcademicYear();
} else {
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    // CRITICAL: July onwards (month >= 7) = new year (2025-2026), Jan-June = old year (2024-2025)
    $A_YEAR = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year;
}
$a_year = $A_YEAR; // Use full academic year string for database
$check_existing_query = "SELECT * FROM nepmarks WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $check_existing_query);
if ($stmt) {
mysqli_stmt_bind_param($stmt, 'is', $dept, $a_year);
mysqli_stmt_execute($stmt);
$check_existing = mysqli_stmt_get_result($stmt);
if ($check_existing && mysqli_num_rows($check_existing) > 0) {
    $existing_data = mysqli_fetch_assoc($check_existing);
    $form_locked = true; // CRITICAL: Set form_locked when data exists
    mysqli_free_result($check_existing); // CRITICAL: Free result
} else {
    if ($check_existing) {
        mysqli_free_result($check_existing); // CRITICAL: Free result even if empty
    }
}
mysqli_stmt_close($stmt);
}

// Initialize variables
$form_locked = $form_locked ?? false; // Use existing value if set, otherwise false
$success_message = isset($_SESSION['success']) ? $_SESSION['success'] : '';
$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);

// Check if data already exists
if ($existing_data) {
    $form_locked = true;
}

// Handle actions
$action = isset($_GET['action']) ? $_GET['action'] : '';

        // Handle clear data action
if ($action === 'clear_data') {
    // Calculate academic year for deletion (same logic as POST handler)
    // Database A_YEAR is now VARCHAR(20) - use full format (e.g., "2024-2025")
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    // Academic year format: if month >= 6 (June onwards), it's (current_year-1)-current_year
    // Otherwise (Jan-May), it's also (current_year-1)-current_year
    if ($current_month >= 7) {
    $A_YEAR = $current_year . '-' . ($current_year + 1);
} else {
    $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
}
    $a_year = $A_YEAR; // Use full academic year string for database
    // Delete from main table - match both DEPT_ID and A_YEAR
    $stmt = mysqli_prepare($conn, "DELETE FROM nepmarks WHERE DEPT_ID = ? AND A_YEAR = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $dept, $a_year);
        $main_deleted = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $main_deleted = false;
    }
    
    // Delete uploaded documents using the unified supporting_documents table
    $delete_docs_query = "SELECT file_path FROM supporting_documents WHERE dept_id = ? AND page_section = 'nep_initiatives' AND status = 'active'";
    $stmt_docs = mysqli_prepare($conn, $delete_docs_query);
    if ($stmt_docs) {
        mysqli_stmt_bind_param($stmt_docs, 'i', $dept);
        mysqli_stmt_execute($stmt_docs);
        $docs_result = mysqli_stmt_get_result($stmt_docs);
        
        $files_deleted = 0;
        if ($docs_result) {
            while ($doc = mysqli_fetch_assoc($docs_result)) {
                $file_path = $doc['file_path'];
                // Normalize path - handle both relative and absolute paths
                $physical_path = $file_path;
                if (strpos($file_path, '../') === 0) {
                    $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $file_path);
                } elseif (strpos($file_path, 'uploads/') === 0) {
                    $physical_path = dirname(__DIR__) . '/' . $file_path;
                } elseif (strpos($file_path, dirname(__DIR__)) !== 0 && !file_exists($file_path)) {
                    // Try with project root
                    $physical_path = dirname(__DIR__) . '/' . ltrim($file_path, '/');
                }
                
                if (file_exists($physical_path)) {
                    if (@unlink($physical_path)) {
                        $files_deleted++;
                    }
                } elseif (file_exists($file_path)) {
                    // Try original path
                    if (@unlink($file_path)) {
                        $files_deleted++;
                    }
                }
            }
            mysqli_free_result($docs_result);
        }
        mysqli_stmt_close($stmt_docs);
    }
    
    // Hard delete from unified supporting_documents table (completely remove records)
    $delete_docs_query = "DELETE FROM supporting_documents WHERE dept_id = ? AND page_section = 'nep_initiatives'";
    $stmt_docs2 = mysqli_prepare($conn, $delete_docs_query);
    if ($stmt_docs2) {
        mysqli_stmt_bind_param($stmt_docs2, 'i', $dept);
        $docs_deleted = mysqli_stmt_execute($stmt_docs2);
        mysqli_stmt_close($stmt_docs2);
    } else {
        $docs_deleted = false;
    }
    
    if ($main_deleted) {
        $success_message = "Data and uploaded documents cleared successfully! Deleted $files_deleted file(s).";
        $existing_data = null;
        $form_locked = false;
        // Redirect to clean URL after 2 seconds
        echo "<script>
            setTimeout(function() {
                window.location.href = 'NEPInitiatives.php';
            }, 2000);
        </script>";
    } else {
        $error_message = "Error clearing data: " . mysqli_error($conn);
    }
}

// NOTE: POST handler moved to top of file (right after session.php) to process before any output

// ============================================================================
// HTML OUTPUT STARTS HERE
// ============================================================================
if (file_exists(__DIR__ . '/unified_header.php')) {
    require(__DIR__ . '/unified_header.php');
} else {
    die('Error: unified_header.php not found');
}
?>

<div class="container my-5">
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" id="successAlert">
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
        <script>
            // Auto-dismiss success message and lock form after 2 seconds
            setTimeout(function() {
                const successAlert = document.getElementById('successAlert');
                if (successAlert) {
                    successAlert.style.transition = 'opacity 0.5s';
                    successAlert.style.opacity = '0';
                    setTimeout(function() {
                        if (successAlert.parentNode) {
                            successAlert.remove();
                        }
                    }, 500);
                }
                // Lock the form after successful submission
                disableForm();
            }, 2000);
        </script>
                    <?php endif; ?>
                    
                    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                    <?php endif; ?>

    <?php if ($form_locked): ?>
        <div class="alert alert-info">
            <strong>Form Status:</strong> Data has been submitted. Click "Update" to modify existing data.
        </div>
                    <?php endif; ?>

    <div class="container-fluid">
        <div class="glass-card">
            <form class="modern-form" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="nepForm" enctype="multipart/form-data">
                <?php if (file_exists(__DIR__ . '/csrf.php')) { require_once(__DIR__ . '/csrf.php'); if (function_exists('csrf_field')) { echo csrf_field(); } } ?>
                <!-- Hidden field to ensure POST handler detects submission -->
                <input type="hidden" name="form_submitted" value="1">
                <div class="page-header">
                    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <div>
                            <h1 class="page-title">
                                <i class="fas fa-lightbulb me-3"></i>NEP Initiatives
                            </h1>
                        </div>
                        <a href="export_page_pdf.php?page=NEPInitiatives" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                            <i class="fas fa-file-pdf"></i> Download as PDF
                        </a>
                    </div>
                </div>
                <div class="mb-3">
                            <h6><strong>Instructions:</strong></h6>
                            <ul class="mb-0">
                                <li>Enter at least some data in the form</li>
                                <li>If not applicable, enter <strong>0</strong> for numeric fields</li>
                                <li>Leave text fields empty if not applicable</li>
                            </ul>
                        </div>
                        
                        <?php 
                        // Display Skip Form Button
                        require_once(__DIR__ . '/skip_form_component.php');
                        $has_existing_data = ($existing_data !== null);
                        displaySkipFormButton('nep_initiatives', 'NEP Initiatives', $A_YEAR, $has_existing_data);
                        ?>
                        
                        <!-- NEP Initiatives -->
                        <fieldset class="mb-5">
                            <legend class="h5">1. NEP Initiatives and Professional Activities adopted by the department</legend>
                            <div class="row">
                                <?php
                                $nep_items = [
                                    "Multidisciplinary Curriculum/Major-Minor combinations for the UG Programme with CO-PO, PEOs (LOCF with flexible curricular structures)",
                                    "Research Projects and Publication Output at PG Programme/ Fourth Year UG -honours with Research",
                                    "Subject-specific Skills and Hands-on-Training Courses",
                                    "Open Electives from other faculty",
                                    "Value and Life Skills Education including Curriculum on Gender Equity, Indian Constitution, Environmental Education, Universal Human Values, Industry 4.0, etc.",
                                    "Generic and Subject-Specific IKS",
                                    "100 % Adoption of Assignment, Accumulation, Storage, and Transfer of Credits in ABC",
                                    "Community Engagement/ Field Projects/ Social Entrepreneurship and any specific Output thereof",
                                    "Joint Degree Programme",
                                    "Dual and Integrated Degree Programme",
                                    "Twinning Degree Programme",
                                    "Activities related to Bharatiya Bhasha Sanvardhan- encouragement for teaching in Indian and local language mediums, Textbooks, and content in Indian languages",
                                    "Entrepreneurship, Cocurricular, and Extension Activities as part of the Curriculum",
                                    "OJT/ Internship/ IPT ",
                                    "Apprenticeship Embedded Degree Programme ",
                                    "Multiple Entry-Multiple Exit (ME-ME)",
                                    "Professor/ Associate or Assistant Professor of Practice 18. Involvement of Artists and Field Professionals in Applied/ Visual/ Performing/ Fine Art Education",
                                    "Accommodation for Guru-Shishya Parampara and traditional learning (For Sanskrit University)",
                                    "NBA accreditation of Professional Programmes- 100% or partial, Accreditation for 6 or 3 years",
                                    "Compliance of Regulations/ Guidelines/ Standards/ Frameworks/Laws of Regulatory Bodies- PCI/ CoA/ BCI/ NCTE/ AICTE/ UGC/ Distance Education Bureau etc",
                                    "Any other Innovative NEP Initiative/ Professional Activity"
                                ];
                                
                                // Get existing NEP data to pre-select checkboxes
                                $existing_nep = [];
                                if ($existing_data && isset($existing_data['nep_initiatives'])) {
                                    $existing_nep = json_decode($existing_data['nep_initiatives'], true) ?: [];
                                }
                                $readonly_attr = $form_locked ? 'readonly disabled' : '';
                                // CRITICAL FIX: For checkboxes, readonly doesn't work - only use disabled
                                $checkbox_disabled_attr = $form_locked ? 'disabled' : '';
                                
                                foreach ($nep_items as $i => $label) {
                                    $isOther = (strpos($label, 'Any other Innovative NEP Initiative') !== false);
                                    $idAttr = $isOther ? 'nep_other' : ('nep' . $i);
                                    $is_checked = in_array($label, $existing_nep) ? 'checked="checked"' : '';
                                    $checked_attr = in_array($label, $existing_nep) ? 'checked' : '';
                                    echo '<div class="col-md-6 mb-2">';
                                    echo '<div class="form-check">';
                                    // CRITICAL FIX: Checkboxes only use disabled (not readonly) so they can be enabled before submit
                                    echo '<input class="form-check-input" type="checkbox" name="nep_initiatives[]" value="' . htmlspecialchars($label) . '" id="' . $idAttr . '" ' . $is_checked . ' ' . $checked_attr . ($checkbox_disabled_attr ? ' ' . $checkbox_disabled_attr : '') . '>';
                                    echo '<label class="form-check-label" for="' . $idAttr . '">' . htmlspecialchars($label) . '</label>';
                                    echo '</div></div>';
                                }
                                ?>
                            </div>
                            <div id="nep_other_text_container" class="mt-2" style="display:none;">
                                <input type="text" name="nep_any_other_text" class="form-control" placeholder="Please specify the other NEP initiative" <?php echo $readonly_attr; ?>>
                            </div>
                            
                            <!-- PDF Upload Section for NEP Initiatives -->
                            <div class="mt-4 p-3 border rounded bg-light">
                                <label class="form-label fw-bold mb-3"><b>Supporting Document for NEP Initiatives <span class="text-danger">*</span></b></label>
                                <div class="alert alert-info" role="alert">
                                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for NEP initiatives (Max. 30 marks).
                                </div>
                                <div class="input-group">
                                    <input type="file" id="nep_initiatives_pdf" name="nep_initiatives_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('nep_initiatives_pdf', 1)" <?php echo $readonly_attr; ?>>Upload</button>
                                </div>
                                <div id="nep_initiatives_pdf_status" class="mt-2"></div>
                            </div>
                        </fieldset>

                        <!-- Pedagogical Approaches -->
                        <fieldset class="mb-5">
                            <legend class="h5">2. Teaching-Learning Pedagogical Approaches</legend>
                            <div class="row">
                                <?php
                                $ped_items = [
                                    "Blended Learning",
                                    "Research-based Learning /Teaching",
                                    "Problem-based Learning /Teaching",
                                    "Project-based Learning /Teaching",
                                    "Situational Learning (Action Research Project)",
                                    "Experiential /Practical Teaching Strategies",
                                    "Skill-based teaching/ Learning",
                                    "Exceptional Teaching Strategies",
                                    "Designing Learning Experiences",
                                    "Use of Technology in Teaching and Learning (LMS, Interactive Smart Board, Flipped Classroom, etc)",
                                    "Use of AI Tools for Personalised Learning Models and Inclusive Practices that cater to diverse learning styles and backgrounds",
                                    "Use of Specific Tools for Special Education/ Physically Challenged Learners",
                                    "Remedial Coaching Pedagogy",
                                    "Scholarly Learner-Centric Activities Beyond Classroom",
                                    "Finishing School Pedagogy",
                                    "Field/ Industrial visits, Study Tours",
                                    "Case Studies for Management Program",
                                    "Moot Court in Law",
                                    "Multisensory learning",
                                    "Gamification of Learning",
                                    "Art Integrated Learning",
                                    "Language-neutral content and delivery to enable students to learn in their native language with the use of real-time translation services",
                                    "Any other Innovative Pedagogical Approach"
                                ];
                                
                                // Get existing PED data to pre-select checkboxes
                                $existing_ped = [];
                                if ($existing_data && isset($existing_data['pedagogical'])) {
                                    $existing_ped = json_decode($existing_data['pedagogical'], true) ?: [];
                                }
                                $readonly_attr = $form_locked ? 'readonly disabled' : '';
                                // CRITICAL FIX: For checkboxes, readonly doesn't work - only use disabled
                                $checkbox_disabled_attr = $form_locked ? 'disabled' : '';
                                
                                foreach ($ped_items as $i => $label) {
                                    $isOtherPed = (strpos($label, 'Any other Innovative Pedagogical Approach') !== false);
                                    $idAttrPed = $isOtherPed ? 'ped_other' : ('ped' . $i);
                                    $is_checked = in_array($label, $existing_ped) ? 'checked="checked"' : '';
                                    $checked_attr = in_array($label, $existing_ped) ? 'checked' : '';
                                    echo '<div class="col-md-6 mb-2">';
                                    echo '<div class="form-check">';
                                    // CRITICAL FIX: Checkboxes only use disabled (not readonly) so they can be enabled before submit
                                    echo '<input class="form-check-input" type="checkbox" name="pedagogical[]" value="' . htmlspecialchars($label) . '" id="' . $idAttrPed . '" ' . $is_checked . ' ' . $checked_attr . ($checkbox_disabled_attr ? ' ' . $checkbox_disabled_attr : '') . '>';
                                    echo '<label class="form-check-label" for="' . $idAttrPed . '">' . htmlspecialchars($label) . '</label>';
                                    echo '</div></div>';
                                }
                                ?>
                            </div>
                            <div id="ped_other_text_container" class="mt-2" style="display:none;">
                                <input type="text" name="ped_any_other_text" class="form-control" placeholder="Please specify the other pedagogical approach" <?php echo $readonly_attr; ?>>
                            </div>
                            
                            <!-- PDF Upload Section for Pedagogical Approaches -->
                            <div class="mt-4 p-3 border rounded bg-light">
                                <label class="form-label fw-bold mb-3"><b>Supporting Document for Pedagogical Approaches <span class="text-danger">*</span></b></label>
                                <div class="alert alert-info" role="alert">
                                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for pedagogical approaches (Max. 20 marks).
                                </div>
                                <div class="input-group">
                                    <input type="file" id="pedagogical_pdf" name="pedagogical_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('pedagogical_pdf', 2)" <?php echo $readonly_attr; ?>>Upload</button>
                                </div>
                                <div id="pedagogical_pdf_status" class="mt-2"></div>
                            </div>
                        </fieldset>

                        <!-- Assessments -->
                        <fieldset class="mb-5">
                            <legend class="h5">3. Student-Centric Assessments</legend>
                            <div class="row">
                                <?php
                                $assess_items = [
                                    "Assessment Rubrics",
                                    "Class Room Assessment Techniques",
                                    "Solving Exercises/ Tutorials",
                                    "Assessment of Problem-solving ability, Computational thinking",
                                    "Seminar/ Presentations, Viva-voce/ Oral Examination",
                                    "Group Tasks/Group Discussions/ Fishbowl Technique",
                                    "Weekly/ Interim Quiz Tests",
                                    "Open book examination",
                                    "Surprise Tests",
                                    "Portfolios and / E-Portfolios",
                                    "Classroom Response Systems",
                                    "Assessment of different skill levels including demonstration of Skills/ performance Demonstrations",
                                    "Assessment of Field Projects/ OJT/Internship",
                                    "Learning Outcome Attainment",
                                    "Competency Assessment",
                                    "Development of Question Bank",
                                    "Assessments powered by AIML for evaluation of skills and knowledge",
                                    "Digitization of Assessment Process",
                                    "Any other Assessment activity/ approach"
                                ];
                                
                                // Get existing ASSESS data to pre-select checkboxes
                                $existing_assess = [];
                                if ($existing_data && isset($existing_data['assessments'])) {
                                    $existing_assess = json_decode($existing_data['assessments'], true) ?: [];
                                }
                                $readonly_attr = $form_locked ? 'readonly disabled' : '';
                                // CRITICAL FIX: For checkboxes, readonly doesn't work - only use disabled
                                $checkbox_disabled_attr = $form_locked ? 'disabled' : '';
                                
                                foreach ($assess_items as $i => $label) {
                                    $isOtherAssess = (strpos($label, 'Any other Assessment activity') !== false);
                                    $idAttrAssess = $isOtherAssess ? 'assess_other' : ('assess' . $i);
                                    $is_checked = in_array($label, $existing_assess) ? 'checked="checked"' : '';
                                    $checked_attr = in_array($label, $existing_assess) ? 'checked' : '';
                                    echo '<div class="col-md-6 mb-2">';
                                    echo '<div class="form-check">';
                                    // CRITICAL FIX: Checkboxes only use disabled (not readonly) so they can be enabled before submit
                                    echo '<input class="form-check-input" type="checkbox" name="assessments[]" value="' . htmlspecialchars($label) . '" id="' . $idAttrAssess . '" ' . $is_checked . ' ' . $checked_attr . ($checkbox_disabled_attr ? ' ' . $checkbox_disabled_attr : '') . '>';
                                    echo '<label class="form-check-label" for="' . $idAttrAssess . '">' . htmlspecialchars($label) . '</label>';
                                    echo '</div></div>';
                                }
                                ?>
                            </div>
                            <div id="assess_other_text_container" class="mt-2" style="display:none;">
                                <input type="text" name="assess_any_other_text" class="form-control" placeholder="Please specify the other assessment activity or approach" <?php echo $readonly_attr; ?>>
                            </div>
                            
                            <!-- PDF Upload Section for Assessments -->
                            <div class="mt-4 p-3 border rounded bg-light">
                                <label class="form-label fw-bold mb-3"><b>Supporting Document for Student-Centric Assessments <span class="text-danger">*</span></b></label>
                                <div class="alert alert-info" role="alert">
                                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for student-centric assessments (Max. 20 marks).
                                </div>
                                <div class="input-group">
                                    <input type="file" id="assessments_pdf" name="assessments_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('assessments_pdf', 3)" <?php echo $readonly_attr; ?>>Upload</button>
                                </div>
                                <div id="assessments_pdf_status" class="mt-2"></div>
                            </div>
                        </fieldset>

                        <!-- MOOCs Information -->
                        <fieldset class="mb-5">
                            <legend class="h5" style="white-space: nowrap;">4. MOOCs Information</legend>
                            <div class="form-group">
                                <label class="form-label">Number of MOOC courses adopted</label>
                                <input type="number" class="form-control" name="moocs" id="moocs_count" min="0" value="<?php echo $existing_data ? (int)$existing_data['moocs'] : '0';?>" placeholder="0" onchange="generateMoocFields()" onkeyup="generateMoocFields()" onkeypress="preventNonNumericInput(event)" oninput="generateMoocFields()" onblur="generateMoocFields()" onfocus="generateMoocFields()" <?php echo $readonly_attr; ?>>
                                <small class="form-text text-muted">Enter the number and individual fields will appear below.</small>
                            </div>
                            
                            <div id="mooc_fields_container">
                                <!-- Dynamic MOOC fields will be generated here -->
                            </div>
                            
                            <!-- PDF Upload Section for MOOCs -->
                            <div class="mt-4 p-3 border rounded bg-light">
                                <label class="form-label fw-bold mb-3"><b>Supporting Document for MOOCs <span class="text-danger">*</span></b></label>
                                <div class="alert alert-info" role="alert">
                                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for MOOC courses (Max. 10 marks).
                                </div>
                                <div class="input-group">
                                    <input type="file" id="moocs_pdf" name="moocs_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('moocs_pdf', 4)" <?php echo $readonly_attr; ?>>Upload</button>
                                </div>
                                <div id="moocs_pdf_status" class="mt-2"></div>
                            </div>
                        </fieldset>

                        <!-- E-Content -->
                        <fieldset class="mb-5">
                            <legend class="h5">5. E-Content Development Credits</legend>
                            <div class="form-group">
                                <label class="form-label" for="econtent_input">E-Content Development Credits</label>
                                <input type="number" step="0.1" name="econtent" id="econtent_input" class="form-control" min="0" value="<?php echo $existing_data ? htmlspecialchars($existing_data['econtent']) : '0';?>" placeholder="0.0" onkeypress="preventNonNumericInput(event)" <?php echo $readonly_attr; ?>>
                            </div>
                            
                            <!-- PDF Upload Section for E-Content -->
                            <div class="mt-4 p-3 border rounded bg-light">
                                <label class="form-label fw-bold mb-3"><b>Supporting Document for E-Content Development <span class="text-danger">*</span></b></label>
                                <div class="alert alert-info" role="alert">
                                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for E-Content development (Max. 15 marks).
                                </div>
                                <div class="input-group">
                                    <input type="file" id="econtent_pdf" name="econtent_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('econtent_pdf', 5)" <?php echo $readonly_attr; ?>>Upload</button>
                                </div>
                                <div id="econtent_pdf_status" class="mt-2"></div>
                            </div>
                        </fieldset>

                        <!-- Result Declaration -->
                        <fieldset class="mb-5">
                            <legend class="h5">6. Days taken for Result Declaration</legend>
                            <div class="form-group">
                                <label class="form-label" for="result_days_input">Days taken for Result Declaration</label>
                                <input type="number" name="result_days" id="result_days_input" class="form-control" min="0" value="<?php echo $existing_data ? (int)$existing_data['result_days'] : '0';?>" placeholder="0" onkeypress="preventNonNumericInput(event)" <?php echo $readonly_attr; ?>>
                            </div>
                            
                            <!-- PDF Upload Section for Result Declaration -->
                            <div class="mt-4 p-3 border rounded bg-light">
                                <label class="form-label fw-bold mb-3"><b>Supporting Document for Result Declaration <span class="text-danger">*</span></b></label>
                                <div class="alert alert-info" role="alert">
                                    <strong>Note:</strong> Upload a PDF document containing supporting evidence for timely result declaration (Max. 5 marks).
                                </div>
                                <div class="input-group">
                                    <input type="file" id="result_declaration_pdf" name="result_declaration_pdf" accept=".pdf" class="form-control" onchange="validatePDF(this)" <?php echo $readonly_attr; ?>>
                                    <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('result_declaration_pdf', 6)" <?php echo $readonly_attr; ?>>Upload</button>
                                </div>
                                <div id="result_declaration_pdf_status" class="mt-2"></div>
                            </div>
                        </fieldset>

                        <div class="text-center">
                            <div class="d-flex flex-wrap justify-content-center gap-3">
                                <?php if ($form_locked): ?>
                                    <button type="button" class="btn btn-warning btn-lg" onclick="enableUpdate()">
                                        <i class="fas fa-edit me-2"></i>Update
                                    </button>
                                    <button type="submit" name="update_nep" value="1" class="btn btn-success btn-lg" id="updateBtn" style="display:none;">
                                        <i class="fas fa-save me-2"></i>Save Changes
                                    </button>
                                    <a href="?action=clear_data" class="btn btn-danger btn-lg" 
                                       onclick="return confirmClearData()">
                                        <i class="fas fa-trash me-2"></i>Clear Data
                                    </a>
                                <?php else: ?>
                                    <button type="submit" name="save_nep" value="1" class="btn btn-primary btn-lg">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Details
                                    </button>
                                <?php endif; ?>
                        </div>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    body {
        background: linear-gradient(135deg, #f6f8fa 0%, #e9ecef 100%);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .container {
        background: #fff;
        border-radius: 1.5rem;
        box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.1);
        padding: 3rem 2.5rem;
        margin-bottom: 2rem;
        border: 1px solid #e3e6ea;
    }

    fieldset {
        border: 2px solid #e3e6ea !important;
        border-radius: 1rem;
        padding: 2.5rem 2rem 2rem 2rem !important;
        margin-bottom: 3rem !important;
        background: linear-gradient(135deg, #fafdff 0%, #f8f9fa 100%);
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.05);
        position: relative;
        transition: all 0.3s ease;
    }
    
    fieldset:hover {
        box-shadow: 0 0.75rem 1.5rem rgba(0, 0, 0, 0.08);
        border-color: #d1d5db;
    }
    
    /* Checkbox styling */
    .form-check-input {
        width: 1.2em;
        height: 1.2em;
        margin-top: 0.1em;
        vertical-align: top;
        background-color: #fff;
        background-repeat: no-repeat;
        background-position: center;
        background-size: contain;
        border: 1px solid #ced4da;
        appearance: none;
        color-adjust: exact;
        cursor: pointer;
    }
    
    .form-check-input:checked {
        background-color: #0d6efd !important;
        border-color: #0d6efd !important;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important;
    }
    
    .form-check-input:checked[type="checkbox"] {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='none' stroke='%23fff' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='m6 10 3 3 6-6'/%3e%3c/svg%3e") !important;
        background-size: 100% 100% !important;
        background-position: center !important;
        background-repeat: no-repeat !important;
    }
    
    .form-check-input:focus {
        border-color: #86b7fe !important;
        outline: 0 !important;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important;
    }
    
    .form-check-input:hover {
        border-color: #0d6efd !important;
        cursor: pointer !important;
    }
    
    .form-check-label {
        cursor: pointer !important;
        margin-left: 0.5em !important;
        line-height: 1.2 !important;
        color: #212529 !important;
        font-weight: 500 !important;
        user-select: none !important;
    }
    
    .form-check {
        display: flex !important;
        align-items: flex-start !important;
        margin-bottom: 0.75rem !important;
        padding: 0.5rem 0.75rem !important;
        border-radius: 0.5rem;
        transition: all 0.2s ease;
        background: rgba(255, 255, 255, 0.5);
    }
    
    .form-check:hover {
        background: rgba(102, 126, 234, 0.05);
        transform: translateX(2px);
    }
    
    .form-check-input {
        width: 1.25rem !important;
        height: 1.25rem !important;
        margin-top: 0.125rem !important;
        margin-right: 0.5rem !important;
        cursor: pointer !important;
        flex-shrink: 0 !important;
    }

    legend {
        font-size: 1.4rem;
        font-weight: 700;
        color: white !important;
        width: auto;
        padding: 0.75rem 1.5rem;
        border-bottom: none;
        white-space: normal;
        overflow: visible;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 0.75rem;
        box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.1);
        margin-bottom: 1.5rem;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        line-height: 1.4;
        max-width: 100%;
        word-wrap: break-word;
        word-break: normal;
        hyphens: auto;
        min-width: 100%;
    }

    /* Ensure all h5 elements in legends have proper styling */
    legend.h5 {
        color: white !important;
        font-weight: 700;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }
    
    .form-check-label {
        cursor: pointer !important;
        margin-left: 0.5em !important;
        line-height: 1.3 !important;
        color: #2d3748 !important;
        font-weight: 500 !important;
        user-select: none !important;
        white-space: normal;
        word-wrap: break-word;
        hyphens: auto;
        font-size: 0.95rem;
    }

    label.form-label {
        font-weight: 500;
        color: #2b3a55;
    }

    input[type="number"],
    input[type="text"],
    input[type="date"],
    input[type="month"],
    input[type="url"],
    textarea,
    select {
        border-radius: 0.75rem !important;
        border: 2px solid #e2e8f0 !important;
        background: #fff !important;
        font-size: 1rem;
        padding: 0.75rem 1rem !important;
        transition: all 0.3s ease;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    input:focus,
    textarea:focus,
    select:focus {
        border-color: #667eea !important;
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25) !important;
        background: #fff !important;
        transform: translateY(-1px);
    }
    
    input:hover,
    textarea:hover,
    select:hover {
        border-color: #cbd5e0 !important;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    input[readonly],
    textarea[readonly],
    select[readonly] {
        background-color: #f8f9fa !important;
        color: #6c757d !important;
        cursor: not-allowed !important;
    }

    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
        .btn-lg {
            padding: 14px 28px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border: none;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-lg:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.2);
        }
        
        .btn-lg:active {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
    
    .btn-primary {
        background: linear-gradient(135deg, #007bff, #0056b3);
        border: none;
    }
    
    .btn-success {
        background: linear-gradient(135deg, #28a745, #1e7e34);
        border: none;
    }
    
    .btn-warning {
        background: linear-gradient(135deg, #ffc107, #e0a800);
        border: none;
        color: #212529;
    }
    
    .btn-info {
        background: linear-gradient(135deg, #17a2b8, #138496);
        border: none;
    }
    
    .btn-danger {
        background: linear-gradient(135deg, #dc3545, #c82333);
        border: none;
    }

    .btn {
        min-width: 160px;
        font-weight: 600;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        border-radius: 8px;
        transition: all 0.3s ease;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }

    .btn:active {
        transform: translateY(0);
    }

    .btn i {
        font-size: 1.1em;
    }

    .gap-3 {
        gap: 1rem !important;
    }

    .form-group {
        margin-bottom: 1.25rem;
    }

    @media (max-width: 767px) {
        .container {
            padding: 1rem 0.5rem;
        }

        fieldset {
            padding: 1rem 0.5rem 0.5rem 0.5rem !important;
        }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Define form lock state for JavaScript - CRITICAL FIX
var isFormLocked = <?php echo $form_locked ? 'true' : 'false'; ?>;

// SINGLE DOMContentLoaded listener - prevents duplicate handlers
let nepInitLoaded = false;
document.addEventListener('DOMContentLoaded', function() {
    if (nepInitLoaded) {
        return;
    }
    nepInitLoaded = true;
    
    // Ensure form submission works
    const form = document.getElementById('nepForm');
    if (form) {
        // Check if submit handler already added
        if (form.dataset.submitHandlerAdded === 'true') {
            return;
        } else {
            form.dataset.submitHandlerAdded = 'true';
            
            // SIMPLIFIED submit handler - just enable fields and allow submission
            let isSubmitting = false;
            form.addEventListener('submit', function(e) {
                // Prevent multiple submissions
                if (isSubmitting) {
                    e.preventDefault();
                    return false;
                }
                
                isSubmitting = true;
                
                // CRITICAL: Enable all fields FIRST so they're included in POST
                const allInputs = form.querySelectorAll('input, textarea, select');
                allInputs.forEach(input => {
                    if (input.type !== 'hidden' && input.type !== 'file' && input.type !== 'submit' && input.type !== 'button') {
                        input.disabled = false;
                        input.removeAttribute('disabled');
                        input.readOnly = false;
                        input.removeAttribute('readonly');
                    }
                });
            }, false);
        }
    }
    
    // Handle "Any other" checkboxes - consolidate duplicate handlers
    var otherCheckbox = document.getElementById('nep_other');
    var otherTextContainer = document.getElementById('nep_other_text_container');
    if (otherCheckbox && otherTextContainer && !otherCheckbox.dataset.handlerAdded) {
        otherCheckbox.dataset.handlerAdded = 'true';
        otherCheckbox.addEventListener('change', function() {
            otherTextContainer.style.display = this.checked ? 'block' : 'none';
        });
        // Check initial state
        if (otherCheckbox.checked) {
            otherTextContainer.style.display = 'block';
        }
    }

    var pedOtherCheckbox = document.getElementById('ped_other');
    var pedOtherTextContainer = document.getElementById('ped_other_text_container');
    if (pedOtherCheckbox && pedOtherTextContainer && !pedOtherCheckbox.dataset.handlerAdded) {
        pedOtherCheckbox.dataset.handlerAdded = 'true';
        pedOtherCheckbox.addEventListener('change', function() {
            pedOtherTextContainer.style.display = this.checked ? 'block' : 'none';
        });
        // Check initial state
        if (pedOtherCheckbox.checked) {
            pedOtherTextContainer.style.display = 'block';
        }
    }

    var assessOtherCheckbox = document.getElementById('assess_other');
    var assessOtherTextContainer = document.getElementById('assess_other_text_container');
    if (assessOtherCheckbox && assessOtherTextContainer && !assessOtherCheckbox.dataset.handlerAdded) {
        assessOtherCheckbox.dataset.handlerAdded = 'true';
        assessOtherCheckbox.addEventListener('change', function() {
            assessOtherTextContainer.style.display = this.checked ? 'block' : 'none';
        });
        // Check initial state
        if (assessOtherCheckbox.checked) {
            assessOtherTextContainer.style.display = 'block';
        }
    }
    
    // Add click handlers to all checkboxes - prevent duplicates
    const allCheckboxes = document.querySelectorAll('input[type="checkbox"]');
    allCheckboxes.forEach(checkbox => {
        if (!checkbox.dataset.mousedownHandlerAdded) {
            checkbox.dataset.mousedownHandlerAdded = 'true';
            checkbox.addEventListener('mousedown', function(e) {
                if (this.disabled) {
                    this.disabled = false;
                    this.removeAttribute('disabled');
                }
            }, true);
        }
    });
    
    // Load existing data ONCE (consolidate all setTimeout calls)
    let dataLoaded = false;
    const loadDataOnce = () => {
        if (dataLoaded) return;
        dataLoaded = true;
        
        setTimeout(function() {
            if (typeof loadExistingData === 'function') {
                loadExistingData();
            }
            if (typeof refreshAllCheckboxes === 'function') {
                refreshAllCheckboxes();
            }
        }, 300);
    };
    loadDataOnce();
    
    // Check if form was just submitted successfully - clear updated param to prevent loops
    // CRITICAL: Only run once per page load to prevent infinite loops
    if (!window.updatedParamHandled) {
        window.updatedParamHandled = true;
        // CRITICAL: Guard against infinite loops - only handle updated=1 once
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('updated') === '1' && !window.nepUpdatedHandled) {
            window.nepUpdatedHandled = true; // Prevent multiple executions
            
            // Remove updated=1 from URL IMMEDIATELY to prevent reload loops
            urlParams.delete('updated');
            const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
            window.history.replaceState({}, '', newUrl);
            
            
            // Success message should already be displayed from PHP session
            // Load form data ONCE without triggering additional requests
            setTimeout(function() {
                if (typeof loadExistingData === 'function' && !window.dataAlreadyLoaded) {
                    window.dataAlreadyLoaded = true;
                    try {
                        loadExistingData();
                    } catch (e) {
                    }
                }
                if (typeof refreshAllCheckboxes === 'function' && !window.checkboxesRefreshed) {
                    window.checkboxesRefreshed = true;
                    try {
                        refreshAllCheckboxes();
                    } catch (e) {
                    }
                }
            }, 300);
        }
    }
    
    // Lock form if it should be locked (when existing data is present)
    <?php if ($form_locked): ?>
    setTimeout(function() {
        if (typeof disableForm === 'function') {
            disableForm();
        }
    }, 1500);
    <?php endif; ?>
});

// CRITICAL: Capture field values before regenerating (like StudentSupport.php)
function captureFieldValues(container) {
    const values = {};
    if (!container) {
        return values;
    }
    container.querySelectorAll('input, textarea, select').forEach(element => {
        if (element.name) {
            values[element.name] = element.value;
        }
    });
    return values;
}

// CRITICAL: Restore field values after regenerating (like StudentSupport.php)
function restoreFieldValues(container, values) {
    if (!container || !values) {
        return;
    }
    container.querySelectorAll('input, textarea, select').forEach(element => {
        if (element.name && values.hasOwnProperty(element.name)) {
            element.value = values[element.name] || '';
        }
    });
}

// Generate dynamic MOOC fields - PRESERVE EXISTING VALUES (like StudentSupport.php)
function generateMoocFields() {
    const moocsCountInput = document.getElementById('moocs_count');
    if (!moocsCountInput) {
        return;
    }
    
    let moocsCount = parseInt(moocsCountInput.value || '0');
    if (isNaN(moocsCount) || moocsCount < 0) {
        moocsCount = 0;
        if (moocsCountInput) {
            moocsCountInput.value = '0';
        }
    }
    const container = document.getElementById('mooc_fields_container');
    if (!container) {
        return;
    }
    
    // CRITICAL: Capture existing values BEFORE clearing container (like StudentSupport.php)
    const previousValues = captureFieldValues(container);
    
    const isReadonly = moocsCountInput.hasAttribute('readonly');
    const readonlyAttr = isReadonly ? 'readonly disabled' : '';
    
    // Clear existing fields only if container exists
    if (container) {
        container.innerHTML = '';
    }
    
    if (moocsCount > 0) {
        for (let i = 1; i <= moocsCount; i++) {
            const moocCard = document.createElement('div');
            moocCard.className = 'card mb-3 p-3';
            moocCard.innerHTML = `
                <h5 class="mb-3">MOOC ${i}</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="fw-bold" for="mooc_platform_${i}">Platform</label>
                        <input type="text" class="form-control" name="mooc_platform_${i}" id="mooc_platform_${i}" placeholder="e.g., Coursera, edX" ${readonlyAttr}>
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold" for="mooc_title_${i}">MOOC Title</label>
                        <input type="text" class="form-control" name="mooc_title_${i}" id="mooc_title_${i}" placeholder="Course title" ${readonlyAttr}>
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold" for="mooc_students_${i}">MOOC Students</label>
                        <input type="number" class="form-control" name="mooc_students_${i}" id="mooc_students_${i}" min="0" placeholder="0" onkeypress="preventNonNumericInput(event)" ${readonlyAttr}>
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold" for="mooc_credits_${i}">MOOC Credits Transferred</label>
                        <input type="number" class="form-control" name="mooc_credits_${i}" id="mooc_credits_${i}" min="0" placeholder="0" onkeypress="preventNonNumericInput(event)" ${readonlyAttr}>
                    </div>
                </div>
            `;
            container.appendChild(moocCard);
        }
    }
    
    // CRITICAL: Restore previous values AFTER generating fields (like StudentSupport.php)
    restoreFieldValues(container, previousValues);
    
    // Enable all fields after generating them (for update mode)
    setTimeout(() => {
        enableAllFields();
    }, 50);
}

// Handle Enter key press to prevent form submission
function handleKeyDown(event) {
    // If Enter key is pressed and the target is a number input field
    if (event.key === 'Enter' && event.target.type === 'number') {
        // Prevent form submission
        event.preventDefault();
        // Trigger the field generation function based on the input ID
        const inputId = event.target.id;
        if (inputId === 'moocs_count') {
            generateMoocFields();
        }
        return false;
    }
    return true;
}

// Form validation function - ULTRA SIMPLIFIED - Always allow submission
function validateForm() {
    
    // CRITICAL: Always enable all fields FIRST - this is the most important step
    try {
        const form = document.getElementById('nepForm');
        if (form) {
            const allInputs = form.querySelectorAll('input, textarea, select');
            let enabledCount = 0;
            allInputs.forEach(input => {
                if (input.type !== 'hidden' && input.type !== 'file') {
                    if (input.disabled || input.hasAttribute('disabled')) {
                        input.disabled = false;
                        input.removeAttribute('disabled');
                        enabledCount++;
                    }
                    if (input.readOnly || input.hasAttribute('readonly')) {
                        input.readOnly = false;
                        input.removeAttribute('readonly');
                        enabledCount++;
                    }
                }
            });
        }
    } catch (e) {
        // Error enabling fields - continue anyway
    }
    
    // SIMPLIFIED VALIDATION - Just check for negative numbers, allow everything else
    try {
        const form = document.getElementById('nepForm');
        if (!form) {
            return true;
        }
        
        // Check for negative values only
        const numericFields = form.querySelectorAll('input[type="number"]');
        for (let i = 0; i < numericFields.length; i++) {
            const field = numericFields[i];
            if (field.disabled || field.type === 'hidden') {
                continue;
            }
            if (field.value && field.value !== '' && field.value !== null) {
                const numValue = parseFloat(field.value);
                if (!isNaN(numValue) && numValue < 0) {
                    alert('Please enter valid positive numbers only. Negative values are not allowed.');
                    field.focus();
                    return false;
                }
            }
        }
        
        return true;
        
    } catch (error) {
        // On ANY error, allow submission - server will validate
        return true;
    }
}

// Removed handleFormSubmit - using validateForm() directly like Departmental_Governance.php

// Prevent non-numeric input in number fields
function preventNonNumericInput(event) {
    // Allow: backspace, delete, tab, escape, enter, period, minus
    if ([8, 9, 27, 13, 46, 110, 189].indexOf(event.keyCode) !== -1 ||
        // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
        (event.keyCode === 65 && event.ctrlKey === true) ||
        (event.keyCode === 67 && event.ctrlKey === true) ||
        (event.keyCode === 86 && event.ctrlKey === true) ||
        (event.keyCode === 88 && event.ctrlKey === true) ||
        // Allow: home, end, left, right, down, up
        (event.keyCode >= 35 && event.keyCode <= 40)) {
        return;
    }
    // Block non-numeric characters
    if ((event.shiftKey || (event.keyCode < 48 || event.keyCode > 57)) && (event.keyCode < 96 || event.keyCode > 105)) {
        event.preventDefault();
    }
}

// Cancel edit confirmation
function confirmCancel() {
    return confirm('Are you sure you want to cancel editing? Any unsaved changes will be lost.');
}


function showSuccessMessage(message) {
    // Remove any existing success messages
    const existingAlerts = document.querySelectorAll('.alert-success.position-fixed');
    existingAlerts.forEach(alert => alert.remove());
    
    // Create simple success message
    const successDiv = document.createElement('div');
    successDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
    successDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 250px;';
    successDiv.innerHTML = `
        <i class="fas fa-check-circle me-2"></i>${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Add to page
    document.body.appendChild(successDiv);
    
    // Auto remove after 2 seconds
    setTimeout(() => {
        if (successDiv.parentNode) {
            successDiv.remove();
        }
    }, 2000);
}

function confirmClearData() {
    if (confirm('Are you sure you want to clear all data? This action cannot be undone!')) {
        // Proceed with clear data
        window.location.href = '?action=clear_data';
        return true;
    }
    return false;
}

function enableUpdate() {
    // Enable all form inputs - matches Departmental_Governance.php
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        // Skip Academic Year and Department fields - keep them non-editable
        if (input.name === 'academic_year' || input.name === 'dept_info') {
            return;
        }
        input.removeAttribute('readonly');
        input.disabled = false;
        input.style.pointerEvents = 'auto';
        input.style.cursor = 'text';
        input.style.backgroundColor = '#fff';
    });
    
    // Enable all file inputs specifically
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(input => {
        input.disabled = false;
        input.style.pointerEvents = 'auto';
        input.style.cursor = 'pointer';
    });
    
    // Enable all upload buttons
    const uploadButtons = document.querySelectorAll('button[onclick*="uploadDocument"]');
    uploadButtons.forEach(button => {
        button.disabled = false;
        button.style.pointerEvents = 'auto';
        button.style.cursor = 'pointer';
    });
    
    // CRITICAL: Regenerate MOOC fields to make them editable, then reload data
    // This ensures data persists when clicking Update (like StudentSupport.php)
    if (typeof generateMoocFields === 'function') {
        generateMoocFields();
        
        // Reload MOOC data from existing data after regenerating fields
        <?php if ($existing_data && isset($existing_data['mooc_data'])): ?>
        setTimeout(() => {
            try {
                const moocDataJson = <?php echo json_encode($existing_data['mooc_data'], JSON_UNESCAPED_UNICODE); ?>;
                if (moocDataJson) {
                    const moocData = JSON.parse(moocDataJson);
                    if (Array.isArray(moocData) && moocData.length > 0) {
                        moocData.forEach((mooc, index) => {
                            const i = index + 1;
                            const platformInput = document.querySelector(`input[name="mooc_platform_${i}"]`);
                            const titleInput = document.querySelector(`input[name="mooc_title_${i}"]`);
                            const studentsInput = document.querySelector(`input[name="mooc_students_${i}"]`);
                            const creditsInput = document.querySelector(`input[name="mooc_credits_${i}"]`);
                            
                            if (platformInput) platformInput.value = mooc.platform || '';
                            if (titleInput) titleInput.value = mooc.title || '';
                            if (studentsInput) studentsInput.value = mooc.students || '0';
                            if (creditsInput) creditsInput.value = mooc.credits || '0';
                        });
                    }
                }
            } catch (e) {
                console.warn('Failed to reload MOOC data:', e);
            }
        }, 150);
        <?php endif; ?>
    }
    
    // Show Save Changes button, hide Update button
    const updateBtn = document.getElementById('updateBtn');
    const updateTriggerBtn = document.querySelector('button[onclick="enableUpdate()"]');
    if (updateBtn) updateBtn.style.display = 'inline-block';
    if (updateTriggerBtn) updateTriggerBtn.style.display = 'none';
    
    // Refresh document status to show delete buttons with a small delay
    setTimeout(() => {
        if (typeof refreshAllDocumentStatuses === 'function') {
            refreshAllDocumentStatuses();
        }
    }, 100);
    
    // Show nice success message instead of alert
    const formContainer = document.querySelector('.modern-form');
    if (formContainer) {
        const existingAlert = formContainer.querySelector('.alert-info[data-update-message]');
        if (existingAlert) {
            existingAlert.remove();
        }
        const successAlert = document.createElement('div');
        successAlert.className = 'alert alert-info alert-dismissible fade show';
        successAlert.setAttribute('data-update-message', 'true');
        successAlert.innerHTML = `
            <i class="fas fa-info-circle me-2"></i>
            <strong>Form unlocked:</strong> You can now edit the form. Make your changes and click "Save Changes" to update.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        formContainer.insertBefore(successAlert, formContainer.firstChild);
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            if (successAlert.parentNode) {
                successAlert.remove();
            }
        }, 5000);
    }
}

// Enhanced function to enable all fields including dynamically generated ones
function enableAllFields() {
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        // Skip Academic Year and Department fields - keep them non-editable
        if (input.name === 'academic_year' || input.name === 'dept_info') {
            return;
        }
        input.removeAttribute('readonly');
        input.disabled = false;
        input.style.pointerEvents = 'auto';
        input.style.cursor = 'text';
        input.style.backgroundColor = '#fff';
    });
}

// Function to disable all form fields (make them read-only)
function disableForm() {
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        input.setAttribute('readonly', 'readonly');
        input.disabled = true;
        input.style.pointerEvents = 'none';
        input.style.cursor = 'not-allowed';
        input.style.backgroundColor = '#f8f9fa';
    });
    
    // Disable file inputs specifically
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(input => {
        input.disabled = true;
        input.style.pointerEvents = 'none';
        input.style.cursor = 'not-allowed';
    });
    
    // Disable upload buttons
    const uploadButtons = document.querySelectorAll('button[onclick*="uploadDocument"]');
    uploadButtons.forEach(button => {
        button.disabled = true;
        button.style.pointerEvents = 'none';
        button.style.cursor = 'not-allowed';
    });
    
    // Hide Save Changes button, show Update button
    const updateBtn = document.getElementById('updateBtn');
    const updateTriggerBtn = document.querySelector('button[onclick="enableUpdate()"]');
    if (updateBtn) updateBtn.style.display = 'none';
    if (updateTriggerBtn) updateTriggerBtn.style.display = 'inline-block';
}

// PDF validation function
function validatePDF(input) {
    const file = input.files[0];
    if (file) {
        const fileSize = file.size / 1024 / 1024; // Convert to MB
        const fileType = file.type;
        
        if (fileType !== 'application/pdf') {
            alert('Please select a valid PDF file.');
            input.value = '';
            return false;
        }
        
        if (fileSize > 10) {
            alert('File size must be less than 10MB.');
            input.value = '';
            return false;
        }
    }
    return true;
}

// Upload document function - with guard to prevent multiple simultaneous requests
const uploadInProgress = new Set();
function uploadDocument(fileId, srno) {
    // Prevent multiple simultaneous uploads
    const uploadKey = `${fileId}_${srno}`;
    if (uploadInProgress.has(uploadKey)) {
        return;
    }
    
    const fileInput = document.getElementById(fileId);
    const statusDiv = document.getElementById(fileId + '_status');
    
    if (!fileInput.files || !fileInput.files[0]) {
        alert('Please select a file to upload.');
        return;
    }
    
    if (!validatePDF(fileInput)) {
        return;
    }
    
    // Get CSRF token
    let csrfToken = '';
    const metaToken = document.querySelector('meta[name="csrf-token"]');
    if (metaToken && metaToken.content) {
        csrfToken = metaToken.content.trim();
    } else {
        const formToken = document.querySelector('input[name="csrf_token"]');
        if (formToken && formToken.value) {
            csrfToken = formToken.value.trim();
        }
    }
    
    if (!csrfToken) {
        alert('Security token not found. Please refresh the page and try again.');
        return;
    }
    
    uploadInProgress.add(uploadKey);
    const formData = new FormData();
    formData.append('upload_document', '1');
    formData.append('file_id', fileId);
    formData.append('srno', srno);
    formData.append('document', fileInput.files[0]);
    formData.append('csrf_token', csrfToken);
    
    // Show loading state
    statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Uploading...';
    statusDiv.className = 'mt-2 text-info';
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // CRITICAL #3: Handle empty responses in JS - return object, don't throw
        return response.text().then(text => {
            const trimmed = text.trim();
            
            // If empty response, return error object instead of throwing
            if (!trimmed || trimmed === '') {
                return { success: false, message: 'Upload failed: Empty server response' };
            }
            
            // Try to parse as JSON
            try {
                return JSON.parse(trimmed);
            } catch (e) {
                // CRITICAL #5: Return error response instead of throwing
                return { success: false, message: 'Upload failed: Invalid server response. Please refresh and try again.' };
            }
        });
    })
    .then(data => {
        // CRITICAL #3: Handle null/undefined data gracefully
        if (!data || typeof data !== 'object') {
            statusDiv.innerHTML = '<div class="alert alert-danger mb-0"><i class="fas fa-exclamation-circle me-2"></i>Upload failed: Invalid server response</div>';
            statusDiv.className = 'mt-2';
            uploadInProgress.delete(uploadKey);
            return;
        }
        
        if (data.success) {
            // Check form lock state dynamically (not from PHP variable at page load)
            var updateButton = document.querySelector('button[onclick="enableUpdate()"]');
            var isFormLocked = updateButton && updateButton.style.display !== 'none';
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            var deleteButton = isFormLocked ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>`;
            
            // Ensure file path is web-accessible
            // Handle paths: uploads/YEAR/DEPARTMENT/department_ID/nep_initiatives/FILENAME.pdf
            let viewPath = data.file_path || '';
            // Convert absolute paths to relative web paths
            if (viewPath && (viewPath.startsWith('/home/') || viewPath.startsWith('C:/') || viewPath.startsWith('C:\\'))) {
                // Extract relative path from absolute path
                const match = viewPath.match(/(uploads\/[\d]+\/DEPARTMENT\/\d+\/.+\.pdf)$/i);
                if (match) {
                    viewPath = '../' + match[1];
                }
            } else if (viewPath && !viewPath.startsWith('../') && !viewPath.startsWith('http')) {
                // Relative path - add ../ if it starts with uploads/
                if (viewPath.startsWith('uploads/')) {
                    viewPath = '../' + viewPath;
                }
            }
            
            // CRITICAL: Escape viewPath for HTML attribute to prevent XSS and syntax errors
            const escapedViewPath = viewPath ? String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
            statusDiv.innerHTML = `
                <div class="d-flex align-items-center flex-wrap">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <span class="text-success me-2">Document uploaded</span>
                    ${deleteButton}
                    <a href="${escapedViewPath}" target="_blank" class="btn btn-sm btn-outline-primary ms-1" rel="noopener noreferrer">
                        <i class="fas fa-eye"></i> View
                    </a>
                </div>
            `;
            statusDiv.className = 'mt-2 text-success';
            
            // Clear the file input
            fileInput.value = '';
        } else {
            // Show error message only if explicitly failed
            const errorMsg = (data && data.message) ? data.message : 'Upload failed. Please try again.';
            statusDiv.innerHTML = `<div class="alert alert-danger mb-0"><i class="fas fa-exclamation-circle me-2"></i>${errorMsg}</div>`;
            statusDiv.className = 'mt-2';
        }
    })
    .catch(error => {
        // CRITICAL #5: Handle errors gracefully - return object, don't throw
        statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Upload failed: ${error.message || 'Unknown error'}</span>`;
        statusDiv.className = 'mt-2 text-danger';
    })
    .finally(() => {
        // Always remove from in-progress set
        uploadInProgress.delete(uploadKey);
    });
}

// Delete document function - with guard to prevent multiple simultaneous requests
const deleteInProgress = new Set();
function deleteDocument(fileId, srno) {
    // Prevent multiple simultaneous deletes
    const deleteKey = `${fileId}_${srno}`;
    if (deleteInProgress.has(deleteKey)) {
        return;
    }
    
    // Check form lock state dynamically (not from PHP variable at page load)
    var updateButton = document.querySelector('button[onclick="enableUpdate()"]');
    var isFormLocked = updateButton && updateButton.style.display !== 'none';
    
    if (isFormLocked) {
        alert('Cannot delete document. Form is locked after submission.\n\nTo delete documents, please use the Update button to unlock the form first.');
        return;
    }
    
    if (!confirm('Are you sure you want to delete this document?')) {
        return;
    }
    
    deleteInProgress.add(deleteKey);
    const statusDiv = document.getElementById(fileId + '_status');
    statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Deleting...';
    statusDiv.className = 'mt-2 text-info';
    
    fetch(`?delete_doc=1&srno=${srno}`, {
        method: 'GET'
    })
    .catch(error => {
        // CRITICAL: Handle fetch errors (network, CORS, etc.) - return error object
        return { ok: false, text: () => Promise.resolve(JSON.stringify({ success: false, message: 'Network error: ' + (error.message || 'Failed to connect to server') })) };
    })
    .then(response => {
        // CRITICAL: Ensure response is valid before processing
        if (!response || typeof response.text !== 'function') {
            return { success: false, message: 'Delete failed: Invalid server response' };
        }
        
        // CRITICAL #3: Handle empty responses in JS - return object, don't throw
        return response.text().then(text => {
            const trimmed = text.trim();
            
            // If empty response, return error object instead of throwing
            if (!trimmed || trimmed === '') {
                return { success: false, message: 'Delete failed: Empty server response' };
            }
            
            // Try to parse as JSON
            try {
                return JSON.parse(trimmed);
            } catch (e) {
                // CRITICAL #5: Return error response instead of throwing
                return { success: false, message: 'Delete failed: Invalid server response' };
            }
        }).catch(textError => {
            // CRITICAL: Handle text() parsing errors
            return { success: false, message: 'Delete failed: Error reading server response' };
        });
    })
    .then(data => {
        // CRITICAL #3: Handle null/undefined data gracefully
        if (!data || typeof data !== 'object') {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
            return;
        }
        
        if (data.success) {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
        } else {
            statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">${data.message || 'Delete failed'}</span>`;
            statusDiv.className = 'mt-2 text-danger';
        }
    })
    .catch(error => {
        // CRITICAL #5: Handle errors gracefully - return object, don't throw
        statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Delete failed: ${error.message || 'Unknown error'}</span>`;
        statusDiv.className = 'mt-2 text-danger';
        
        // Return resolved promise to prevent unhandled promise rejection
        return Promise.resolve({ success: false, message: 'Delete failed: ' + (error.message || 'Unknown error') });
    })
    .finally(() => {
        // Always remove from in-progress set
        deleteInProgress.delete(deleteKey);
    });
}

// Check document status function - with guard to prevent multiple simultaneous requests
const checkInProgress = new Set();
function checkDocumentStatus(fileId, srno) {
    // Prevent multiple simultaneous checks
    const checkKey = `${fileId}_${srno}`;
    if (checkInProgress.has(checkKey)) {
        return;
    }
    
    const statusDiv = document.getElementById(fileId + '_status');
    if (!statusDiv) {
        return;
    }
    
    checkInProgress.add(checkKey);
    fetch(`?check_doc=1&srno=${srno}`, {
        method: 'GET'
    })
    .then(response => {
        // CRITICAL #3: Handle empty responses in JS - return object, don't throw
        return response.text().then(text => {
            const trimmed = text.trim();
            
            // If empty response, return error object instead of throwing
            if (!trimmed || trimmed === '') {
                return { success: false, message: 'No document found' };
            }
            
            // Try to parse as JSON
            try {
                return JSON.parse(trimmed);
            } catch (e) {
                // CRITICAL #5: Return error response instead of throwing
                return { success: false, message: 'Invalid server response' };
            }
        });
    })
    .then(data => {
        // CRITICAL #3: Handle null/undefined data gracefully
        if (!data || typeof data !== 'object') {
            return;
        }
        
        if (data.success && data.file_path) {
            // Check form lock state dynamically (not from PHP variable at page load)
            var updateButton = document.querySelector('button[onclick="enableUpdate()"]');
            var isFormLocked = updateButton && updateButton.style.display !== 'none';
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId4 = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            var deleteButton = isFormLocked ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId4}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>`;
            
            // Ensure file path is web-accessible
            // Handle paths: uploads/YEAR/DEPARTMENT/department_ID/nep_initiatives/FILENAME.pdf
            let viewPath = data.file_path;
            // Convert absolute paths to relative web paths
            if (viewPath && (viewPath.startsWith('/home/') || viewPath.startsWith('C:/') || viewPath.startsWith('C:\\'))) {
                // Extract relative path from absolute path
                const match = viewPath.match(/(uploads\/[\d]+\/DEPARTMENT\/\d+\/.+\.pdf)$/i);
                if (match) {
                    viewPath = '../' + match[1];
                }
            } else if (viewPath && !viewPath.startsWith('../') && !viewPath.startsWith('http')) {
                // Relative path - add ../ if it starts with uploads/
                if (viewPath.startsWith('uploads/')) {
                    viewPath = '../' + viewPath;
                }
            }
            
            // CRITICAL: Escape viewPath for HTML attribute to prevent XSS and syntax errors
            const escapedViewPath4 = viewPath ? String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
            statusDiv.innerHTML = `
                <div class="d-flex align-items-center flex-wrap">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <span class="text-success me-2">Document uploaded</span>
                    ${deleteButton}
                    <a href="${escapedViewPath4}" target="_blank" class="btn btn-sm btn-outline-primary ms-1" rel="noopener noreferrer">
                        <i class="fas fa-eye"></i> View
                    </a>
                </div>
            `;
            statusDiv.className = 'mt-2 text-success';
        } else {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
        }
    })
    .catch(error => {
        statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
        statusDiv.className = 'mt-2 text-muted';
    })
    .finally(() => {
        // Always remove from in-progress set
        checkInProgress.delete(checkKey);
    });
}

// CRITICAL FIX: Load ALL documents in ONE batch request instead of 6 individual requests
// This reduces database load from 6 queries to just 1 query per page load
function loadExistingDocuments() {
    const documentMap = {
        'nep_initiatives_pdf': 1,
        'pedagogical_pdf': 2,
        'assessments_pdf': 3,
        'moocs_pdf': 4,
        'econtent_pdf': 5,
        'result_declaration_pdf': 6
    };
    
    // ============================================================================
    // GLOBAL REQUEST LIMITING - Prevent connection exhaustion with 20+ users
    // ============================================================================
    let globalActiveRequests = 0;
    const MAX_CONCURRENT_REQUESTS = 3; // Maximum 3 simultaneous requests
    const requestQueue = [];
    
    function processQueuedRequests() {
        if (requestQueue.length === 0 || globalActiveRequests >= MAX_CONCURRENT_REQUESTS) {
            return;
        }
        
        const nextRequest = requestQueue.shift();
        globalActiveRequests++;
        
        nextRequest.fn()
            .then(nextRequest.resolve)
            .catch(nextRequest.reject)
            .finally(() => {
                globalActiveRequests--;
                setTimeout(processQueuedRequests, 100);
            });
    }
    
    function executeWithRateLimit(requestFn) {
        return new Promise((resolve, reject) => {
            if (globalActiveRequests >= MAX_CONCURRENT_REQUESTS) {
                // Queue the request
                requestQueue.push({
                    fn: requestFn,
                    resolve: resolve,
                    reject: reject
                });
                setTimeout(processQueuedRequests, 100);
            } else {
                globalActiveRequests++;
                requestFn()
                    .then(resolve)
                    .catch(reject)
                    .finally(() => {
                        globalActiveRequests--;
                        setTimeout(processQueuedRequests, 100);
                    });
            }
        });
    }
    
    // CRITICAL: Use batch endpoint with rate limiting - ONE request instead of 6 individual requests
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
    
    executeWithRateLimit(() => {
        return fetch('?check_all_docs=1', {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        },
        cache: 'no-cache',
        signal: controller.signal
    })
    .then(response => {
        clearTimeout(timeoutId);
        
        if (!response || !response.ok) {
                return { success: false, message: 'HTTP error: ' + response.status, documents: {} };
        }
        
        return response.text().then(text => {
            const trimmed = text.trim();
            if (!trimmed) {
                    return { success: false, message: 'Empty server response', documents: {} };
            }
                try {
            return JSON.parse(trimmed);
                } catch (e) {
                    return { success: false, message: 'Invalid server response', documents: {} };
                }
            });
        })
        .catch(error => {
            clearTimeout(timeoutId);
            if (error.name === 'AbortError') {
                return { success: false, message: 'Request timeout', documents: {} };
            }
            return { success: false, message: 'Network error: ' + (error.message || 'Unknown error'), documents: {} };
        });
    })
    .then(data => {
        if (data.success && data.documents) {
            // Update all document statuses from batch response
            Object.keys(documentMap).forEach(fileId => {
                const srno = documentMap[fileId];
                const statusDiv = document.getElementById(fileId + '_status');
                
                if (statusDiv) {
                    const docData = data.documents[srno];
                    if (docData && docData.success) {
                        // Check form lock state dynamically (not from PHP variable at page load)
                        var updateButton = document.querySelector('button[onclick="enableUpdate()"]');
                        var isFormLocked = updateButton && updateButton.style.display !== 'none';
                        // Update status div with document info
                        const filePath = docData.file_path || '';
                        const fileName = docData.file_name || 'Document';
                        const uploadDate = docData.upload_date || '';
                        // CRITICAL: Escape fileId to prevent JavaScript syntax errors
                        const escapedFileId3 = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
                        const deleteButton = isFormLocked ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId3}', ${srno})">
                            <i class="fas fa-trash"></i> Delete
                        </button>`;
                        
                        statusDiv.innerHTML = `<i class="fas fa-check-circle text-success me-2"></i>
                            <a href="${filePath}" target="_blank" class="text-success">${fileName}</a>
                            ${deleteButton}
                            <br><small class="text-muted">Uploaded: ${uploadDate}</small>`;
                        statusDiv.className = 'mt-2 text-success';
                    } else {
                        statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                        statusDiv.className = 'mt-2 text-muted';
                    }
                }
            });
        } else {
            // Fallback: If batch fails, show error but don't crash
            console.warn('[NEPInitiatives] Batch document check failed:', data.message || 'Unknown error');
            Object.keys(documentMap).forEach(fileId => {
                const statusDiv = document.getElementById(fileId + '_status');
                if (statusDiv) {
                    statusDiv.innerHTML = '<span class="text-muted">Check failed</span>';
                    statusDiv.className = 'mt-2 text-muted';
                }
            });
        }
    })
    .catch(error => {
        clearTimeout(timeoutId);
        
        // Handle errors gracefully
        console.warn('[NEPInitiatives] Error loading documents:', error);
        Object.keys(documentMap).forEach(fileId => {
            const statusDiv = document.getElementById(fileId + '_status');
            if (statusDiv) {
                if (error.name === 'AbortError') {
                    statusDiv.innerHTML = '<span class="text-warning">Request timed out</span>';
                    statusDiv.className = 'mt-2 text-warning';
                } else {
                    statusDiv.innerHTML = '<span class="text-muted">Check failed</span>';
                    statusDiv.className = 'mt-2 text-muted';
                }
            }
        });
    });
}

// Force refresh all document statuses (used when Update is clicked)
function refreshAllDocumentStatuses() {
    // Clear existing status divs first
    const statusDivs = document.querySelectorAll('[id$="_status"]');
    statusDivs.forEach(div => {
        div.innerHTML = '<span class="text-muted">Checking...</span>';
        div.className = 'mt-2 text-muted';
    });
    
    // Then reload all documents
    loadExistingDocuments();
}

// CRITICAL: Track if page has already initialized to prevent duplicate initialization
let pageInitialized = false;

// Initialize form on page load - prevent duplicate initialization
window.onload = function() {
    // CRITICAL: Prevent duplicate initialization
    if (pageInitialized) {
        console.log('[NEPInitiatives] Page already initialized, skipping');
        return;
    }
    pageInitialized = true;
    
    // Load existing documents with a small delay
    setTimeout(function() {
        loadExistingDocuments();
    }, 500);
    
    // Initialize MOOC fields - CRITICAL: Generate fields on page load
    const moocsCountInput = document.getElementById('moocs_count');
    if (moocsCountInput) {
        // Force set to 0 if empty, null, or undefined
        if (moocsCountInput.value === '' || moocsCountInput.value === null || moocsCountInput.value === undefined) {
            moocsCountInput.value = '0';
        }
        // Generate fields - data will be populated by loadExistingData() if it exists
        if (typeof generateMoocFields === 'function') {
            generateMoocFields();
        }
    }
    
    // Data loading is now handled in the consolidated DOMContentLoaded listener above
};

// Load existing data into form fields
function loadExistingData() {
    <?php if ($existing_data): ?>
    const existingData = <?php echo json_encode($existing_data); ?>;
    
    
    // Load NEP initiatives
    if (existingData.nep_initiatives) {
        try {
            const nepInitiatives = JSON.parse(existingData.nep_initiatives);
            if (Array.isArray(nepInitiatives)) {
                nepInitiatives.forEach(initiative => {
                    // Check if this is an "Any other" text entry
                    if (initiative.includes('Any other Innovative NEP Initiative/ Professional Activity:')) {
                        // Extract the text part
                        const textPart = initiative.replace('Any other Innovative NEP Initiative/ Professional Activity: ', '');
                        // Check the "Any other" checkbox
                        const anyOtherCheckbox = document.getElementById('nep_other');
                        if (anyOtherCheckbox) {
                            anyOtherCheckbox.checked = true;
                            anyOtherCheckbox.setAttribute('checked', 'checked');
                            // Show the text container
                            const textContainer = document.getElementById('nep_other_text_container');
                            if (textContainer) {
                                textContainer.style.display = 'block';
                                // Fill the text input
                                const textInput = document.querySelector('input[name="nep_any_other_text"]');
                                if (textInput) {
                                    textInput.value = textPart;
                                }
                            }
                        }
                    } else {
                        // Regular checkbox
                        const checkbox = document.querySelector(`input[name="nep_initiatives[]"][value="${initiative}"]`);
                        if (checkbox) {
                            checkbox.checked = true;
                            checkbox.setAttribute('checked', 'checked');
                            checkbox.dispatchEvent(new Event('change'));
                        } else {
                        }
                    }
                });
            }
        } catch (e) {
        }
    }
    
    // Load pedagogical approaches
    if (existingData.pedagogical) {
        try {
            const pedagogical = JSON.parse(existingData.pedagogical);
            if (Array.isArray(pedagogical)) {
                pedagogical.forEach(approach => {
                    // Check if this is an "Any other" text entry
                    if (approach.includes('Any other Innovative Pedagogical Approach:')) {
                        // Extract the text part
                        const textPart = approach.replace('Any other Innovative Pedagogical Approach: ', '');
                        // Check the "Any other" checkbox
                        const anyOtherCheckbox = document.getElementById('ped_other');
                        if (anyOtherCheckbox) {
                            anyOtherCheckbox.checked = true;
                            anyOtherCheckbox.setAttribute('checked', 'checked');
                            // Show the text container
                            const textContainer = document.getElementById('ped_other_text_container');
                            if (textContainer) {
                                textContainer.style.display = 'block';
                                // Fill the text input
                                const textInput = document.querySelector('input[name="ped_any_other_text"]');
                                if (textInput) {
                                    textInput.value = textPart;
                                }
                            }
                        }
                    } else {
                        // Regular checkbox
                        const checkbox = document.querySelector(`input[name="pedagogical[]"][value="${approach}"]`);
                        if (checkbox) {
                            checkbox.checked = true;
                            checkbox.setAttribute('checked', 'checked');
                            checkbox.dispatchEvent(new Event('change'));
                        } else {
                        }
                    }
                });
            }
        } catch (e) {
        }
    }
    
    // Load assessments
    if (existingData.assessments) {
        try {
            const assessments = JSON.parse(existingData.assessments);
            if (Array.isArray(assessments)) {
                assessments.forEach(assessment => {
                    // Check if this is an "Any other" text entry
                    if (assessment.includes('Any other Assessment activity/ approach:')) {
                        // Extract the text part
                        const textPart = assessment.replace('Any other Assessment activity/ approach: ', '');
                        // Check the "Any other" checkbox
                        const anyOtherCheckbox = document.getElementById('assess_other');
                        if (anyOtherCheckbox) {
                            anyOtherCheckbox.checked = true;
                            anyOtherCheckbox.setAttribute('checked', 'checked');
                            // Show the text container
                            const textContainer = document.getElementById('assess_other_text_container');
                            if (textContainer) {
                                textContainer.style.display = 'block';
                                // Fill the text input
                                const textInput = document.querySelector('input[name="assess_any_other_text"]');
                                if (textInput) {
                                    textInput.value = textPart;
                                }
                            }
                        }
                    } else {
                        // Regular checkbox
                        const checkbox = document.querySelector(`input[name="assessments[]"][value="${assessment}"]`);
                        if (checkbox) {
                            checkbox.checked = true;
                            checkbox.setAttribute('checked', 'checked');
                            checkbox.dispatchEvent(new Event('change'));
                        } else {
                        }
                    }
                });
            }
        } catch (e) {
        }
    }
    
    // Load MOOC count and data - CRITICAL: Generate fields first, then populate
    if (existingData.moocs !== undefined) {
        const moocsCountInput = document.getElementById('moocs_count');
        if (moocsCountInput) {
            const moocsValue = existingData.moocs || 0;
            moocsCountInput.value = moocsValue;
            
            // Generate fields first
            if (typeof generateMoocFields === 'function') {
                generateMoocFields();
            }
            
            // Load MOOC data from mooc_data column (JSON array) AFTER fields are generated
            if (existingData.mooc_data) {
                try {
                    const moocData = JSON.parse(existingData.mooc_data);
                    if (Array.isArray(moocData) && moocData.length > 0) {
                        // Use longer timeout to ensure fields are generated
                        setTimeout(() => {
                            moocData.forEach((mooc, index) => {
                                const i = index + 1;
                                const platformInput = document.querySelector(`input[name="mooc_platform_${i}"]`);
                                const titleInput = document.querySelector(`input[name="mooc_title_${i}"]`);
                                const studentsInput = document.querySelector(`input[name="mooc_students_${i}"]`);
                                const creditsInput = document.querySelector(`input[name="mooc_credits_${i}"]`);
                                
                                if (platformInput) platformInput.value = mooc.platform || '';
                                if (titleInput) titleInput.value = mooc.title || '';
                                if (studentsInput) studentsInput.value = mooc.students || '0';
                                if (creditsInput) creditsInput.value = mooc.credits || '0';
                            });
                        }, 300);
                    }
                } catch (e) {
                    // If mooc_data is not valid JSON, try to load from old format for backward compatibility
                    console.warn('Failed to parse mooc_data:', e);
                }
            }
        }
    }
    <?php endif; ?>
}

// Force refresh all checkboxes
function refreshAllCheckboxes() {
    const allCheckboxes = document.querySelectorAll('input[type="checkbox"]');
    allCheckboxes.forEach(checkbox => {
        // Force visual refresh
        const isChecked = checkbox.checked;
        checkbox.checked = false;
        checkbox.offsetHeight; // Trigger reflow
        checkbox.checked = isChecked;
        
        // Add visual feedback
        checkbox.style.transform = 'scale(1.05)';
        setTimeout(() => {
            checkbox.style.transform = 'scale(1)';
        }, 100);
    });
}

// REMOVED: Duplicate DOMContentLoaded listener - consolidated into main listener above

// Simplified checkbox handlers - just ensure they work when enabled/disabled
function handleCheckboxClick(event) {
    const checkbox = event.target;
    if (checkbox.type === 'checkbox') {
        // Ensure checkbox state is properly set
        if (checkbox.disabled) {
            // If disabled, try to enable it (for update mode)
            checkbox.disabled = false;
            checkbox.removeAttribute('disabled');
        }
    }
}



// Clear form after successful submission or update - REMOVED to prevent clearing after successful submission
// Form should remain populated with saved data after submission

// Populate MOOC fields when data exists
<?php if ($existing_data): ?>
// Add to existing DOMContentLoaded listener - use flag to prevent duplicate execution
if (typeof window.nepMoocDataLoaded === 'undefined') {
    window.nepMoocDataLoaded = false;
}
(function() {
    const initMoocData = function() {
        if (window.nepMoocDataLoaded) return;
        window.nepMoocDataLoaded = true;
        
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initMoocData);
            return;
        }
    // Load MOOC data from mooc_data column (JSON array)
    const moocDataJson = '<?php echo isset($existing_data['mooc_data']) ? addslashes($existing_data['mooc_data']) : '[]'; ?>';
    let moocData = [];
    
    try {
        moocData = JSON.parse(moocDataJson);
        if (!Array.isArray(moocData)) {
            moocData = [];
        }
    } catch (e) {
        // If not valid JSON, use empty array
        moocData = [];
    }
    
    // Set MOOC count from database value
    const moocsCountFromDB = <?php echo (int)($existing_data['moocs'] ?? 0); ?>;
    const moocsCountInputDB = document.getElementById('moocs_count');
    if (moocsCountInputDB) {
        if (moocsCountFromDB > 0) {
            moocsCountInputDB.value = moocsCountFromDB;
        } else {
            moocsCountInputDB.value = '0';
        }
        // Generate fields after setting the value
        setTimeout(() => {
            if (typeof generateMoocFields === 'function') {
                generateMoocFields();
            }
        }, 100);
    } else {
        // Try again after a delay
        setTimeout(() => {
            const moocsCountInputRetry = document.getElementById('moocs_count');
            if (moocsCountInputRetry && typeof generateMoocFields === 'function') {
                moocsCountInputRetry.value = moocsCountFromDB > 0 ? moocsCountFromDB : '0';
                generateMoocFields();
            }
        }, 200);
    }
        
    // Wait a bit for fields to be created, then populate them
    // CRITICAL: Use longer timeout to ensure fields are generated first
    setTimeout(() => {
        // Only populate MOOC fields if there are entries
        if (moocData.length > 0) {
            // Populate all MOOC fields
            moocData.forEach((mooc, index) => {
                const i = index + 1;
                const platformInput = document.querySelector(`input[name="mooc_platform_${i}"]`);
                const titleInput = document.querySelector(`input[name="mooc_title_${i}"]`);
                const studentsInput = document.querySelector(`input[name="mooc_students_${i}"]`);
                const creditsInput = document.querySelector(`input[name="mooc_credits_${i}"]`);
                
                if (platformInput) platformInput.value = mooc.platform || '';
                if (titleInput) titleInput.value = mooc.title || '';
                if (studentsInput) studentsInput.value = mooc.students || '0';
                if (creditsInput) creditsInput.value = mooc.credits || '0';
            });
        }
    }, 300);
    };
    
    // Initialize MOOC data loading
    initMoocData();
})();
<?php endif; ?>
</script>

<?php 
if (file_exists(__DIR__ . '/unified_footer.php')) {
    require(__DIR__ . '/unified_footer.php');
}
?>