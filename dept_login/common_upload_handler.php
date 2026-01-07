<?php
/**
 * Common Upload Handler for Department Login
 * Handles all PDF document uploads, deletions, and checks
 * Usage: Include this file and call handleDocumentUpload() with parameters
 */

// Do NOT require session.php here - it should already be included by the calling page
// Only include if not already included (check if $conn exists)
if (!isset($conn) || !$conn) {
    // Only require if absolutely necessary (shouldn't happen in normal flow)
    if (file_exists(__DIR__ . '/session.php')) {
        require_once(__DIR__ . '/session.php');
    }
}

// Do NOT include any view/layout here; this is a library used by pages.
// Load common functions for getAcademicYear()
if (file_exists(__DIR__ . '/common_functions.php')) {
    require_once(__DIR__ . '/common_functions.php');
}

// CRITICAL FIX: Compute academic year locally if not already defined by caller
// Use centralized getAcademicYear() function (month >= 7 for July onwards)
if (!isset($A_YEAR)) {
    if (function_exists('getAcademicYear')) {
        $A_YEAR = getAcademicYear();
    } else {
        // Fallback calculation - matches getAcademicYear() logic
        $current_year = (int)date("Y");
        $current_month = (int)date("n");
        // July onwards (month >= 7): current_year to current_year+1
        // Below July (month < 7): (current_year-2) to (current_year-1)
        if ($current_month >= 7) {
            $A_YEAR = $current_year . '-' . ($current_year + 1);
        } else {
            $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
        }
    }
}
// Load CSRF utilities if available (optional)
if (file_exists(__DIR__ . '/csrf.php')) {
	require_once __DIR__ . '/csrf.php';
}

if (!function_exists('handleDocumentUpload')) {
    
    /**
     * Handle document upload
     * @param string $page_section - The page section identifier (e.g., 'executive_development', 'details_dept')
     * @param string $section_name - Display name of the section
     * @param array $options - Additional options:
     *                          - upload_dir: Custom upload directory
     *                          - max_size: Max file size in MB (default 10)
     *                          - document_title: Custom document title
     *                          - srno: Serial number
     * @return array - ['success' => bool, 'message' => string, ...]
     */
    function handleDocumentUpload($page_section, $section_name, $options = []) {
        // CRITICAL: Suppress errors and start output buffering to prevent any output
        error_reporting(0);
        ini_set('display_errors', 0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        
        global $conn, $userInfo, $dept_code;
        
        // CRITICAL: Get academic year - check multiple sources and ensure it's set correctly
        // First try GLOBALS (set by calling pages)
        $A_YEAR = $GLOBALS['A_YEAR'] ?? null;
        
        // Fallback: try to get from global scope
        if (empty($A_YEAR)) {
            global $A_YEAR;
        }
        
        // If still empty, calculate it
        if (empty($A_YEAR)) {
            // Calculate academic year using centralized function
            if (function_exists('getAcademicYear')) {
                $A_YEAR = getAcademicYear();
            } else {
                // Fallback calculation - matches getAcademicYear() logic
                $current_year = (int)date("Y");
                $current_month = (int)date("n");
                // July onwards (month >= 7): current_year to current_year+1
                // Below July (month < 7): (current_year-2) to (current_year-1)
                if ($current_month >= 7) {
                    $A_YEAR = $current_year . '-' . ($current_year + 1);
                } else {
                    $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
                }
            }
        }
        
        // Validate academic year is set (should never be empty at this point, but double-check)
        if (empty($A_YEAR)) {
            throw new Exception('Academic year not found. Please contact administrator.');
        }
        
        // Get dept_id from GLOBALS if not set as global variable (set by calling pages)
        $dept_id = $GLOBALS['dept_id'] ?? null;
        // Fallback: try to get from userInfo if available
        if (!$dept_id && isset($userInfo['DEPT_ID'])) {
            $dept_id = $userInfo['DEPT_ID'];
        }
        // Fallback: try to get from session
        if (!$dept_id && isset($_SESSION['dept_id'])) {
            $dept_id = $_SESSION['dept_id'];
        }
        
        // Default options
        $defaults = [
            'upload_dir' => null,
            'max_size' => 10, // MB
            'document_title' => null,
            'srno' => 1,
            'file_id' => ''
        ];
        
        $options = array_merge($defaults, $options);
        
        try {
            // Validate database connection
            if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
                throw new Exception('Database connection not available. Please try again.');
            }
            
            // Validate department ID (DEPT_ID from database)
            if (!$dept_id) {
                throw new Exception('Department ID (DEPT_ID) not found. Please contact administrator.');
            }
            
            // CRITICAL: Validate academic year is set before using it in queries
            if (empty($A_YEAR)) {
                // Try to calculate it one more time
                if (function_exists('getAcademicYear')) {
                    $A_YEAR = getAcademicYear();
                } else {
                    $current_year = (int)date("Y");
                    $current_month = (int)date("n");
                    if ($current_month >= 7) {
                        $A_YEAR = $current_year . '-' . ($current_year + 1);
                    } else {
                        $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
                    }
                }
            }
            
            if (empty($A_YEAR)) {
                throw new Exception('Academic year is required but not set. Please contact administrator.');
            }
            
            // Get form data
            $srno = (int)($_POST['srno'] ?? $options['srno']);
            $file_id = $_POST['file_id'] ?? $options['file_id'];
            
            if (empty($file_id) || $srno <= 0) {
                throw new Exception('File ID and serial number are required.');
            }
            
            // Validate file upload
            if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No file uploaded or upload error occurred.');
            }
            
            $file = $_FILES['document'];
            
            // Validate CSRF token when present
            if (function_exists('validate_csrf')) {
                $csrf = $_POST['csrf_token'] ?? '';
                if (!validate_csrf($csrf)) {
                    throw new Exception('Security validation failed. Please refresh and try again.');
                }
            }

            // Validate file type (extension + MIME)
            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($file_extension !== 'pdf') {
                throw new Exception('Only PDF files are allowed.');
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if ($mime !== 'application/pdf') {
                throw new Exception('Invalid file type. Only PDF documents are allowed.');
            }
            
            // Validate file size
            $max_size = $options['max_size'] * 1024 * 1024;
            if ($file['size'] > $max_size) {
                throw new Exception('File size must be less than ' . $options['max_size'] . 'MB.');
            }
            
            // Create upload directory (outside dept_login folder - use ../uploads/)
            // Structure: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/{page_section}/
            // Use absolute path with dirname(__DIR__) for consistency
            // IMPORTANT: Use dept_id (DEPT_ID) for directory name, not dept_code
            // A_YEAR format: e.g., "2024-2025" (not just "2025")
            if ($options['upload_dir']) {
                $upload_dir = $options['upload_dir'];
            } else {
                // Use dept_id (database DEPT_ID) for directory name
                if (!$dept_id) {
                    throw new Exception('Department ID (DEPT_ID) not found. Please contact administrator.');
                }
                $upload_dir = dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/{$page_section}/";
            }
            
            if (!file_exists($upload_dir)) {
                if (!mkdir($upload_dir, 0777, true)) {
                    throw new Exception('Failed to create upload directory.');
                }
                @chmod($upload_dir, 0777);
            }
            
            // Generate unique filename in format: FILENAME_UNIC_CODE.pdf
            $original_name = pathinfo($file['name'], PATHINFO_FILENAME);
            $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $original_name);
            // Generate unique code (combination of timestamp, microseconds, dept_id, and random number for uniqueness)
            // CRITICAL: Include microseconds and dept_id to prevent collisions with 20+ concurrent users
            // CRITICAL: Type cast dept_id to integer for security (UNIFIED_SECURITY_GUIDE.md requirement)
            // CRITICAL: Use local variable to avoid modifying global $dept_id (affects other pages)
            $dept_id_for_filename = (int)$dept_id;
            $unique_code = time() . '_' . (int)(microtime(true) * 1000000) % 1000000 . '_' . $dept_id_for_filename . '_' . random_int(1000, 9999);
            $file_name = "{$safe_name}_{$unique_code}.pdf";
            $file_path = $upload_dir . $file_name;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $file_path)) {
                throw new Exception('Failed to upload file.');
            }
            @chmod($file_path, 0644);
            
            // Convert physical path to web-accessible path for database storage
            // Handle both absolute paths (with dirname(__DIR__)) and relative paths
            $project_root = dirname(__DIR__);
            if (strpos($file_path, $project_root) === 0) {
                // Absolute path - convert to relative web path (without ../ prefix for DB storage)
                $web_path = str_replace($project_root . '/', '', $file_path);
            } else {
                // Already relative or has ../ prefix - remove ../ for DB storage
            $web_path = str_replace('../', '', $file_path);
            }
            // Normalize directory separators
            $web_path = str_replace('\\', '/', $web_path);
            
            // For return value, add ../ prefix for web access from dept_login directory
            $web_path_for_return = (strpos($web_path, 'uploads/') === 0) ? '../' . $web_path : $web_path;
            
            // Get document title
            $document_title = $options['document_title'] ?? ($section_name . ' Documentation');
            
            // Get uploaded by
            $uploaded_by = $_SESSION['admin_username'] ?? $userInfo['EMAIL'] ?? 'Unknown';
            
            // Get program_id from POST if available (for intake_actual_strength and other program-specific sections)
            $program_id = $_POST['program_id'] ?? $options['program_id'] ?? '';
            
            // For intake_actual_strength: Check if section_name already contains program code (unique section_name approach)
            // If section_name contains '_PROG_', it means we're using unique section_name per program
            // In that case, use section_name for the check instead of program_id
            // For programmes_offered: They still use program_id to separate documents
            $uses_unique_section_name = ($page_section === 'intake_actual_strength' && strpos($section_name, '_PROG_') !== false);
            
            // CRITICAL FIX: For intake_actual_strength ONLY, HARD DELETE any old records BEFORE inserting
            // This prevents unique constraint violations by completely removing conflicting records first.
            // Since unique constraints apply even to deleted records, we must HARD DELETE to prevent conflicts.
            // CRITICAL: Must include section_name in DELETE query to only delete records for THIS specific program
            // This ensures each program's documents are independent and not deleted when uploading for another program
            // SAFETY: This cleanup ONLY runs for 'intake_actual_strength' page_section and will NOT affect other pages.
            if ($page_section === 'intake_actual_strength' && $uses_unique_section_name) {
                // CRITICAL FIX: Include section_name in query to only delete records for THIS program
                // This ensures Program A's documents are NOT deleted when uploading for Program B
                // First, get file paths of records to delete (only for this specific program via section_name)
                $get_files_query = "SELECT id, file_path FROM supporting_documents 
                    WHERE academic_year = ? AND dept_id = ? AND page_section = ? AND serial_number = ? AND section_name = ?";
                $get_files_stmt = mysqli_prepare($conn, $get_files_query);
                if ($get_files_stmt) {
                    mysqli_stmt_bind_param($get_files_stmt, "sisis", $A_YEAR, $dept_id, $page_section, $srno, $section_name);
                    if (mysqli_stmt_execute($get_files_stmt)) {
                        $files_result = mysqli_stmt_get_result($get_files_stmt);
                        $project_root = dirname(__DIR__);
                        
                        // Delete physical files
                        while ($file_row = mysqli_fetch_assoc($files_result)) {
                            $old_file_path = $file_row['file_path'];
                            if (!empty($old_file_path)) {
                                // Convert to physical path
                                $physical_path = $old_file_path;
                                if (strpos($physical_path, '../') === 0) {
                                    $physical_path = $project_root . '/' . str_replace('../', '', $physical_path);
                                } elseif (strpos($physical_path, 'uploads/') === 0) {
                                    $physical_path = $project_root . '/' . $physical_path;
                                }
                                $physical_path = str_replace('\\', '/', $physical_path);
                                
                                if (file_exists($physical_path)) {
                                    @unlink($physical_path);
                                }
                            }
                        }
                        mysqli_free_result($files_result);
                    }
                    mysqli_stmt_close($get_files_stmt);
                }
                
                // CRITICAL FIX: Include section_name in DELETE query to only delete records for THIS program
                // This ensures Program A's documents are NOT deleted when uploading for Program B
                $cleanup_query = "DELETE FROM supporting_documents 
                    WHERE academic_year = ? AND dept_id = ? AND page_section = ? AND serial_number = ? AND section_name = ?";
                $cleanup_stmt = mysqli_prepare($conn, $cleanup_query);
                if ($cleanup_stmt) {
                    mysqli_stmt_bind_param($cleanup_stmt, "sisis", $A_YEAR, $dept_id, $page_section, $srno, $section_name);
                    if (mysqli_stmt_execute($cleanup_stmt)) {
                        $affected = mysqli_stmt_affected_rows($cleanup_stmt);
                        if ($affected > 0) {
                            error_log("[common_upload_handler] Hard deleted $affected old record(s) for intake_actual_strength (program: $section_name) - will INSERT new record");
                        }
                    }
                    mysqli_stmt_close($cleanup_stmt);
                }
            }
            
            // For programmes_offered: They use program_id to separate documents
            // For intake_actual_strength with unique section_name: Use section_name instead
            if ($page_section === 'programmes_offered' && !empty($program_id)) {
                // For programmes_offered, check if document exists with program_id
                // Check for BOTH active AND deleted records to handle re-uploads after deletion
                // CRITICAL: Use ORDER BY id DESC LIMIT 1 to get the most recent record
                $check_query = "SELECT id, file_path, status FROM supporting_documents 
                    WHERE academic_year = ? AND dept_id = ? AND page_section = ? AND serial_number = ? AND program_id = ?
                    ORDER BY id DESC LIMIT 1";
                $stmt_check = mysqli_prepare($conn, $check_query);
                if ($stmt_check) {
                    mysqli_stmt_bind_param($stmt_check, "sisss", $A_YEAR, $dept_id, $page_section, $srno, $program_id);
                    if (!mysqli_stmt_execute($stmt_check)) {
                        mysqli_stmt_close($stmt_check);
                        $check_result = false;
                    } else {
                        $check_result = mysqli_stmt_get_result($stmt_check);
                        // Don't close statement yet - we'll close after using result
                    }
                } else {
                    $check_result = false;
                }
            } else {
                // For other sections, check if document already exists (including deleted ones)
                // CRITICAL FIX: For intake_actual_strength with unique section_name, MUST filter by section_name
                // This ensures we only check/update documents for THIS specific program, not other programs
                // Without section_name filter, uploading for Program B would find and update Program A's document
                if ($page_section === 'intake_actual_strength' && $uses_unique_section_name) {
                    // CRITICAL FIX: Include section_name in check query to only find documents for THIS program
                    // This prevents updating documents from other programs when uploading for a new program
                    $check_query = "SELECT id, file_path, status, section_name, program_id FROM supporting_documents 
                        WHERE academic_year = ? AND dept_id = ? AND page_section = ? AND serial_number = ? AND section_name = ?
                        ORDER BY id DESC LIMIT 1";
                    $stmt_check = mysqli_prepare($conn, $check_query);
                    if ($stmt_check) {
                        mysqli_stmt_bind_param($stmt_check, "sisis", $A_YEAR, $dept_id, $page_section, $srno, $section_name);
                        if (!mysqli_stmt_execute($stmt_check)) {
                            mysqli_stmt_close($stmt_check);
                            $check_result = false;
                        } else {
                            $check_result = mysqli_stmt_get_result($stmt_check);
                            // Don't close statement yet - we'll close after using result
                        }
                    } else {
                        $check_result = false;
                    }
                } else {
                    // For other sections, check with section_name to match the unique constraint
                    // The unique constraint is (academic_year, dept_id, page_section, serial_number, section_name)
                    // CRITICAL: Check for both active AND deleted records to handle re-uploads after deletion
                    // CRITICAL: Use ORDER BY id DESC LIMIT 1 to get the most recent record (active if exists, deleted otherwise)
                    $check_query = "SELECT id, file_path, status FROM supporting_documents 
                        WHERE academic_year = ? AND dept_id = ? AND page_section = ? AND serial_number = ? AND section_name = ?
                        ORDER BY id DESC LIMIT 1";
                    $stmt_check = mysqli_prepare($conn, $check_query);
                    if ($stmt_check) {
                        mysqli_stmt_bind_param($stmt_check, "sisis", $A_YEAR, $dept_id, $page_section, $srno, $section_name);
                        if (!mysqli_stmt_execute($stmt_check)) {
                            mysqli_stmt_close($stmt_check);
                            $check_result = false;
                        } else {
                            $check_result = mysqli_stmt_get_result($stmt_check);
                            // Don't close statement yet - we'll close after using result
                        }
                    } else {
                        $check_result = false;
                    }
                }
            }
            
            $message = 'Document uploaded successfully.';
            
            // Track if check statement is closed
            $check_stmt_closed = false;
            
            if ($check_result && mysqli_num_rows($check_result) > 0) {
                // Document exists (either active or deleted) - update/reactivate it
                $existing_doc = mysqli_fetch_assoc($check_result);
                mysqli_free_result($check_result); // CRITICAL: Free result set
                if (isset($stmt_check) && $stmt_check && !$check_stmt_closed) {
                    mysqli_stmt_close($stmt_check); // CRITICAL: Close statement
                    $check_stmt_closed = true;
                }
                $old_file_path = $existing_doc['file_path'];
                $was_deleted = ($existing_doc['status'] === 'deleted');
                
                // Delete old file if it exists and is different from new file
                if (!empty($old_file_path)) {
                    $project_root = dirname(__DIR__);
                    if (strpos($old_file_path, '../') === 0) {
                        $old_physical_path = $project_root . '/' . str_replace('../', '', $old_file_path);
                    } elseif (strpos($old_file_path, 'uploads/') === 0) {
                        $old_physical_path = $project_root . '/' . $old_file_path;
                    } else {
                        $old_physical_path = $old_file_path;
                    }
                    if ($old_physical_path !== $file_path && file_exists($old_physical_path)) {
                        @unlink($old_physical_path);
                    }
                }
                
                // For program-specific sections, ensure program_id and section_name are updated
                // CRITICAL: For intake_actual_strength with unique section_name, also update section_name field
                if ($page_section === 'intake_actual_strength' && $uses_unique_section_name && !empty($program_id)) {
                    // Update with section_name for intake_actual_strength using unique format
                    $update_query = "UPDATE supporting_documents SET 
                        document_title = ?, file_path = ?, file_name = ?, file_size = ?, 
                        uploaded_by = ?, updated_date = NOW(), status = 'active', program_id = ?, section_name = ?
                        WHERE id = ?";
                    
                    $stmt = mysqli_prepare($conn, $update_query);
                    if (!$stmt) {
                        unlink($file_path);
                        throw new Exception('Failed to prepare update statement: ' . mysqli_error($conn));
                    }
                    mysqli_stmt_bind_param($stmt, "sssissis", 
                        $document_title, $web_path, $file['name'], $file['size'], $uploaded_by, $program_id, $section_name, $existing_doc['id']);
                } elseif (($page_section === 'intake_actual_strength' || $page_section === 'programmes_offered') && !empty($program_id)) {
                    // For programmes_offered or intake_actual_strength without unique section_name, only update program_id
                    $update_query = "UPDATE supporting_documents SET 
                        document_title = ?, file_path = ?, file_name = ?, file_size = ?, 
                        uploaded_by = ?, updated_date = NOW(), status = 'active', program_id = ?
                        WHERE id = ?";
                    
                    $stmt = mysqli_prepare($conn, $update_query);
                    if (!$stmt) {
                        unlink($file_path);
                        throw new Exception('Failed to prepare update statement: ' . mysqli_error($conn));
                    }
                    mysqli_stmt_bind_param($stmt, "sssissi", 
                        $document_title, $web_path, $file['name'], $file['size'], $uploaded_by, $program_id, $existing_doc['id']);
                } else {
                    $update_query = "UPDATE supporting_documents SET 
                        document_title = ?, file_path = ?, file_name = ?, file_size = ?, 
                        uploaded_by = ?, updated_date = NOW(), status = 'active' 
                        WHERE id = ?";
                    
                    $stmt = mysqli_prepare($conn, $update_query);
                    if (!$stmt) {
                        unlink($file_path);
                        throw new Exception('Failed to prepare update statement: ' . mysqli_error($conn));
                    }
                    mysqli_stmt_bind_param($stmt, "sssisi", 
                        $document_title, $web_path, $file['name'], $file['size'], $uploaded_by, $existing_doc['id']);
                }
                
                if (!mysqli_stmt_execute($stmt)) {
                    unlink($file_path);
                    mysqli_stmt_close($stmt);
                    throw new Exception('Failed to update document record: ' . mysqli_error($conn));
                }
                
                // Get the document ID from existing_doc
                $document_id = $existing_doc['id'] ?? null;
                
                mysqli_stmt_close($stmt);
                
                $message = $was_deleted ? 'Document uploaded successfully (reactivated).' : 'Document updated successfully.';
            } else {
                // No existing document found - free result set and close statement if not already closed
                if ($check_result) {
                    mysqli_free_result($check_result); // CRITICAL: Free result set
                }
                if (isset($stmt_check) && $stmt_check && !$check_stmt_closed) {
                    mysqli_stmt_close($stmt_check); // CRITICAL: Close statement
                }
                // Insert new record - DO NOT use ON DUPLICATE KEY UPDATE to prevent overwriting other programs
                // Use program_id from POST if available, otherwise use empty string
                $insert_program_id = !empty($program_id) ? $program_id : '';
                
                // CRITICAL: For program-specific sections, check again with program_id to ensure we don't overwrite
                if (($page_section === 'intake_actual_strength' || $page_section === 'programmes_offered') && !empty($insert_program_id)) {
                    // Double-check no document exists for this specific program_id to prevent accidental overwrites
                    $double_check_query = "SELECT id FROM supporting_documents 
                        WHERE academic_year = ? AND dept_id = ? AND page_section = ? 
                        AND serial_number = ? AND program_id = ? AND status = 'active'";
                    $double_check_stmt = mysqli_prepare($conn, $double_check_query);
                    if ($double_check_stmt) {
                        mysqli_stmt_bind_param($double_check_stmt, "sisss", $A_YEAR, $dept_id, $page_section, $srno, $insert_program_id);
                        if (!mysqli_stmt_execute($double_check_stmt)) {
                            mysqli_stmt_close($double_check_stmt);
                            $double_check_result = false;
                        } else {
                            $double_check_result = mysqli_stmt_get_result($double_check_stmt);
                        }
                        if ($double_check_result && mysqli_num_rows($double_check_result) > 0) {
                            // Document exists - update it instead
                            $existing_row = mysqli_fetch_assoc($double_check_result);
                            
                            // CRITICAL: For intake_actual_strength with unique section_name, also update section_name field
                            if ($page_section === 'intake_actual_strength' && $uses_unique_section_name && !empty($insert_program_id)) {
                                $update_query = "UPDATE supporting_documents SET 
                                    document_title = ?, file_path = ?, file_name = ?, file_size = ?, 
                                    uploaded_by = ?, updated_date = NOW(), status = 'active', program_id = ?, section_name = ?
                                    WHERE id = ?";
                                $update_stmt = mysqli_prepare($conn, $update_query);
                                if (!$update_stmt) {
                                    unlink($file_path);
                                    mysqli_stmt_close($double_check_stmt);
                                    throw new Exception('Failed to prepare update statement: ' . mysqli_error($conn));
                                }
                                mysqli_stmt_bind_param($update_stmt, "sssissis", 
                                    $document_title, $web_path, $file['name'], $file['size'], $uploaded_by, $insert_program_id, $section_name, $existing_row['id']);
                            } else {
                                $update_query = "UPDATE supporting_documents SET 
                                    document_title = ?, file_path = ?, file_name = ?, file_size = ?, 
                                    uploaded_by = ?, updated_date = NOW(), status = 'active', program_id = ?
                                    WHERE id = ?";
                                $update_stmt = mysqli_prepare($conn, $update_query);
                                if (!$update_stmt) {
                                    unlink($file_path);
                                    mysqli_stmt_close($double_check_stmt);
                                    throw new Exception('Failed to prepare update statement: ' . mysqli_error($conn));
                                }
                                mysqli_stmt_bind_param($update_stmt, "sssissi", 
                                    $document_title, $web_path, $file['name'], $file['size'], $uploaded_by, $insert_program_id, $existing_row['id']);
                            }
                            if (!mysqli_stmt_execute($update_stmt)) {
                                unlink($file_path);
                                mysqli_stmt_close($update_stmt);
                                mysqli_stmt_close($double_check_stmt);
                                throw new Exception('Failed to update document record: ' . mysqli_error($conn));
                            }
                            
                            // Get document ID from existing_row
                            $document_id = $existing_row['id'] ?? null;
                            
                            mysqli_stmt_close($update_stmt);
                            mysqli_stmt_close($double_check_stmt);
                            $message = 'Document updated successfully.';
                        } else {
                            // No document exists - free result set and close statement, then insert new one
                            if ($double_check_result) {
                                mysqli_free_result($double_check_result); // CRITICAL: Free result set
                            }
                            mysqli_stmt_close($double_check_stmt);
                            
                            // CRITICAL FIX: For intake_actual_strength, use INSERT ... ON DUPLICATE KEY UPDATE
                            // This ensures we always UPDATE existing records (even deleted ones) instead of getting duplicate errors
                            // This matches the behavior of ExecutiveDevelopment.php
                            if ($page_section === 'intake_actual_strength' && $uses_unique_section_name) {
                                $insert_query = "INSERT INTO supporting_documents 
                                    (academic_year, dept_id, page_section, section_name, serial_number, program_id, document_title, file_path, file_name, file_size, uploaded_by, status) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                                    ON DUPLICATE KEY UPDATE
                                    document_title = VALUES(document_title),
                                    file_path = VALUES(file_path),
                                    file_name = VALUES(file_name),
                                    file_size = VALUES(file_size),
                                    uploaded_by = VALUES(uploaded_by),
                                    updated_date = NOW(),
                                    status = 'active',
                                    program_id = VALUES(program_id),
                                    section_name = VALUES(section_name)";
                                
                                $insert_stmt = mysqli_prepare($conn, $insert_query);
                                if (!$insert_stmt) {
                                    unlink($file_path);
                                    throw new Exception('Failed to prepare insert query: ' . mysqli_error($conn));
                                }
                                // Type string: s=i-s-s-i-s-s-s-s-i-s (11 parameters)
                                mysqli_stmt_bind_param($insert_stmt, "sississsisi", 
                                    $A_YEAR, $dept_id, $page_section, $section_name, $srno, $insert_program_id,
                                    $document_title, $web_path, $file['name'], $file['size'], $uploaded_by);
                                
                                if (!mysqli_stmt_execute($insert_stmt)) {
                                    unlink($file_path);
                                    mysqli_stmt_close($insert_stmt);
                                    throw new Exception('Failed to save document record: ' . mysqli_error($conn));
                                }
                                
                                // Get the document ID (last insert ID)
                                $document_id = mysqli_insert_id($conn);
                                
                                mysqli_stmt_close($insert_stmt);
                            } else {
                                // For other sections, regular INSERT
                                $insert_query = "INSERT INTO supporting_documents 
                                    (academic_year, dept_id, page_section, section_name, serial_number, program_id, document_title, file_path, file_name, file_size, uploaded_by, status) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                                
                                $insert_stmt = mysqli_prepare($conn, $insert_query);
                                if (!$insert_stmt) {
                                    unlink($file_path);
                                    throw new Exception('Failed to prepare insert query: ' . mysqli_error($conn));
                                }
                                // Type string: s=i-s-s-i-s-s-s-s-i-s (11 parameters)
                                mysqli_stmt_bind_param($insert_stmt, "sississsisi", 
                                    $A_YEAR, $dept_id, $page_section, $section_name, $srno, $insert_program_id,
                                    $document_title, $web_path, $file['name'], $file['size'], $uploaded_by);
                                
                                if (!mysqli_stmt_execute($insert_stmt)) {
                                    unlink($file_path);
                                    mysqli_stmt_close($insert_stmt);
                                    throw new Exception('Failed to save document record: ' . mysqli_error($conn));
                                }
                                
                                // Get the document ID (last insert ID)
                                $document_id = mysqli_insert_id($conn);
                                
                                mysqli_stmt_close($insert_stmt);
                            }
                        }
                    } else {
                        // Fallback: For intake_actual_strength, use ON DUPLICATE KEY UPDATE; for others, regular INSERT
                        if ($page_section === 'intake_actual_strength' && $uses_unique_section_name) {
                            $insert_query = "INSERT INTO supporting_documents 
                                (academic_year, dept_id, page_section, section_name, serial_number, program_id, document_title, file_path, file_name, file_size, uploaded_by, status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                                ON DUPLICATE KEY UPDATE
                                document_title = VALUES(document_title),
                                file_path = VALUES(file_path),
                                file_name = VALUES(file_name),
                                file_size = VALUES(file_size),
                                uploaded_by = VALUES(uploaded_by),
                                updated_date = NOW(),
                                status = 'active',
                                program_id = VALUES(program_id),
                                section_name = VALUES(section_name)";
                        } else {
                            $insert_query = "INSERT INTO supporting_documents 
                                (academic_year, dept_id, page_section, section_name, serial_number, program_id, document_title, file_path, file_name, file_size, uploaded_by, status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                        }
                        
                        $insert_stmt = mysqli_prepare($conn, $insert_query);
                        if (!$insert_stmt) {
                            unlink($file_path);
                            throw new Exception('Failed to prepare insert query: ' . mysqli_error($conn));
                        }
                        // Type string: s=i-s-s-i-s-s-s-s-i-s (11 parameters)
                        mysqli_stmt_bind_param($insert_stmt, "sississsisi", 
                            $A_YEAR, $dept_id, $page_section, $section_name, $srno, $insert_program_id,
                            $document_title, $web_path, $file['name'], $file['size'], $uploaded_by);
                        
                        if (!mysqli_stmt_execute($insert_stmt)) {
                            unlink($file_path);
                            mysqli_stmt_close($insert_stmt);
                            throw new Exception('Failed to save document record: ' . mysqli_error($conn));
                        }
                        
                        // Get the document ID (last insert ID)
                        $document_id = mysqli_insert_id($conn);
                        
                        mysqli_stmt_close($insert_stmt);
                    }
                } else {
                    // For non-program-specific sections
                    // CRITICAL FIX: For intake_actual_strength, use ON DUPLICATE KEY UPDATE to prevent duplicate errors
                    if ($page_section === 'intake_actual_strength' && $uses_unique_section_name) {
                        $insert_query = "INSERT INTO supporting_documents 
                            (academic_year, dept_id, page_section, section_name, serial_number, program_id, document_title, file_path, file_name, file_size, uploaded_by, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                            ON DUPLICATE KEY UPDATE
                            document_title = VALUES(document_title),
                            file_path = VALUES(file_path),
                            file_name = VALUES(file_name),
                            file_size = VALUES(file_size),
                            uploaded_by = VALUES(uploaded_by),
                            updated_date = NOW(),
                            status = 'active',
                            program_id = VALUES(program_id),
                            section_name = VALUES(section_name)";
                    } else {
                        $insert_query = "INSERT INTO supporting_documents 
                            (academic_year, dept_id, page_section, section_name, serial_number, program_id, document_title, file_path, file_name, file_size, uploaded_by, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                    }
                    
                    $stmt = mysqli_prepare($conn, $insert_query);
                    if (!$stmt) {
                        unlink($file_path);
                        throw new Exception('Failed to prepare insert query: ' . mysqli_error($conn));
                    }
                    // Type string: s=i-s-s-i-s-s-s-s-i-s (11 parameters: A_YEAR, dept_id, page_section, section_name, srno, program_id, document_title, web_path, file_name, file_size, uploaded_by)
                    mysqli_stmt_bind_param($stmt, "sississsisi",
                        $A_YEAR, $dept_id, $page_section, $section_name, $srno, $insert_program_id,
                        $document_title, $web_path, $file['name'], $file['size'], $uploaded_by);
                    
                    if (!mysqli_stmt_execute($stmt)) {
                        unlink($file_path);
                        mysqli_stmt_close($stmt);
                        throw new Exception('Failed to save document record: ' . mysqli_error($conn));
                    }
                    
                    // Get the document ID (last insert ID)
                    $document_id = mysqli_insert_id($conn);
                    
                    mysqli_stmt_close($stmt);
                }
            }
            
            // CRITICAL: Initialize document_id variable if not already set (shouldn't happen, but safety check)
            if (!isset($document_id)) {
                $document_id = null;
            }
            
            // Clear any output before returning
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            return [
                'success' => true,
                'message' => $message,
                'file_path' => $web_path_for_return, // Return web-accessible path with ../ prefix
                'file_name' => $file['name'],
                'file_size' => $file['size'],
                'document_id' => $document_id ?? null, // Include document ID in response
                'id' => $document_id ?? null // Also include as 'id' for compatibility
            ];
            
        } catch (Exception $e) {
            // Clear any output before returning error
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            error_log("Upload error in handleDocumentUpload: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        } catch (Error $e) {
            // Clear any output before returning error
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            error_log("Upload fatal error in handleDocumentUpload: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
            return [
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ];
        } catch (Throwable $e) {
            // Clear any output before returning error
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            error_log("Upload throwable error in handleDocumentUpload: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
            return [
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Handle document deletion
     * @param string $page_section - The page section identifier
     * @return array - ['success' => bool, 'message' => string]
     */
    function handleDocumentDeletion($page_section) {
        global $conn, $userInfo, $A_YEAR, $dept_id;
        
        try {
            $srno = (int)($_GET['srno'] ?? 0);
            
            if ($srno <= 0) {
                throw new Exception('Serial number is required for deletion.');
            }
            
            // Get file path from database
            $get_file_query = "SELECT file_path, id FROM supporting_documents 
                WHERE academic_year = ? AND dept_id = ? AND page_section = ? AND serial_number = ?";
            
            $stmt = mysqli_prepare($conn, $get_file_query);
            mysqli_stmt_bind_param($stmt, "sisi", $A_YEAR, $dept_id, $page_section, $srno);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($result && mysqli_num_rows($result) > 0) {
                $row = mysqli_fetch_assoc($result);
                $file_path = $row['file_path'];
                $doc_id = $row['id'];
                
                // Delete file from filesystem (convert to physical path)
                $project_root = dirname(__DIR__);
                if (strpos($file_path, '../') === 0) {
                    $phys = $project_root . '/' . str_replace('../', '', $file_path);
                } elseif (strpos($file_path, 'uploads/') === 0) {
                    $phys = $project_root . '/' . $file_path;
                } else {
                    $phys = $file_path;
                }
                if (file_exists($phys)) {
                    @unlink($phys);
                }
                
                // Soft delete from database
                $delete_query = "UPDATE supporting_documents SET status = 'deleted' WHERE id = ?";
                $stmt_del = mysqli_prepare($conn, $delete_query);
                mysqli_stmt_bind_param($stmt_del, "i", $doc_id);
                
                if (!mysqli_stmt_execute($stmt_del)) {
                    throw new Exception('Database error: ' . mysqli_error($conn));
                }
                
                return ['success' => true, 'message' => 'Document deleted successfully'];
            } else {
                throw new Exception('Document not found');
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Check document status
     * @param string $page_section - The page section identifier
     * @return array - ['success' => bool, 'file_path' => string, 'file_name' => string]
     */
    function handleDocumentCheck($page_section) {
        global $conn, $userInfo, $A_YEAR, $dept_id;
        
        try {
            $srno = (int)($_GET['srno'] ?? 0);
            
            if ($srno <= 0) {
                throw new Exception('Serial number is required.');
            }
            
            $get_file_query = "SELECT file_path, file_name, upload_date, document_id FROM supporting_documents 
                WHERE academic_year = ? AND dept_id = ? AND page_section = ? AND serial_number = ? AND status = 'active' LIMIT 1";
            
            $stmt = mysqli_prepare($conn, $get_file_query);
            if (!$stmt) {
                throw new Exception('Database error: ' . mysqli_error($conn));
            }
            
            mysqli_stmt_bind_param($stmt, "sisi", $A_YEAR, $dept_id, $page_section, $srno);
            
            if (!mysqli_stmt_execute($stmt)) {
                $error = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception('Database error: ' . $error);
            }
            
            $result = mysqli_stmt_get_result($stmt);
            
            if ($result && mysqli_num_rows($result) > 0) {
                $row = mysqli_fetch_assoc($result);
                mysqli_free_result($result);
                mysqli_stmt_close($stmt);
                
                $file_path = $row['file_path'];
                
                // Ensure file path has ../ prefix for web access from dept_login directory
                if (strpos($file_path, '../') !== 0) {
                    if (strpos($file_path, 'uploads/') === 0) {
                        $file_path = '../' . $file_path;
                    }
                }
                
                return [
                    'success' => true,
                    'file_path' => $file_path,
                    'file_name' => $row['file_name'],
                    'upload_date' => $row['upload_date'],
                    'document_id' => $row['document_id'] ?? null
                ];
            } else {
                if ($result) {
                    mysqli_free_result($result);
                }
                mysqli_stmt_close($stmt);
                return ['success' => false, 'message' => 'No document found'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
?>
