<?php
/**
 * Faculty Output Management System
 * Handles form submission, data locking, PDF generation, and data management
 */

// FacultyOutput.php - Faculty Output Form
// ============================================================================
// CRITICAL: Handle AJAX requests FIRST - before ANY output, includes, or whitespace
// ============================================================================

// ============================================================================
// HANDLE delete_doc FIRST - before any includes to prevent HTML output
// ============================================================================
if (isset($_GET['delete_doc']) || ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_doc']))) {
    // CRITICAL #1: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Start session and load config FIRST (silently)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    ob_start();
    // CRITICAL: Use simple pattern like StudentSupport.php - just check $conn
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    ob_end_clean();
    
    ob_start();
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    ob_end_clean();
    
    // Clear buffers again
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL #4: Set headers BEFORE any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        // Handle both GET and POST - JavaScript uses GET with ?delete_doc=1&srno=
        $srno = (int)($_GET['srno'] ?? $_POST['srno'] ?? 0);
        
        $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
        if (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found.'];
        } elseif ($srno <= 0) {
            $response = ['success' => false, 'message' => 'Invalid serial number.'];
        } else {
        
            // Use centralized getAcademicYear() function if available
            if (!function_exists('getAcademicYear')) {
                if (file_exists(__DIR__ . '/common_functions.php')) {
                    require_once(__DIR__ . '/common_functions.php');
                }
            }
            if (function_exists('getAcademicYear')) {
                $academic_year = getAcademicYear();
            } else {
                // Fallback calculation - matches getAcademicYear() logic
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
            
            // Get file path using prepared statement - match StudentSupport.php
            $get_file_query = "SELECT file_path FROM supporting_documents WHERE academic_year = ? AND dept_id = ? AND page_section = 'faculty_output' AND serial_number = ? AND status = 'active' LIMIT 1";
            $stmt = mysqli_prepare($conn, $get_file_query);
            if (!$stmt) {
                $response = ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
            } else {
                mysqli_stmt_bind_param($stmt, 'sis', $academic_year, $dept_id, $srno);
                if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if ($result && mysqli_num_rows($result) > 0) {
                        $row = mysqli_fetch_assoc($result);
                        mysqli_free_result($result); // CRITICAL: Free result set
                        $file_path = $row['file_path'];
                        
                        // CRITICAL: Delete file from filesystem FIRST - handle all path formats properly
                        $physical_path = $file_path;
                        
                        // Handle relative paths starting with ../
                        if (strpos($file_path, '../') === 0) {
                            $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $file_path);
                        }
                        // Handle paths starting with uploads/
                        elseif (strpos($file_path, 'uploads/') === 0) {
                            $physical_path = dirname(__DIR__) . '/' . $file_path;
                        }
                        // Handle absolute paths - check if they're within project directory
                        elseif (strpos($file_path, dirname(__DIR__)) === 0) {
                            $physical_path = $file_path;
                        }
                        // If path doesn't contain project root, assume it's relative to project root
                        else {
                            $physical_path = dirname(__DIR__) . '/' . ltrim($file_path, '/');
                        }
                        
                        // Normalize path separators for Windows/Linux compatibility
                        $physical_path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $physical_path);
                        $physical_path = realpath($physical_path) ?: $physical_path;
                        
                        // CRITICAL: Delete physical file if it exists
                        $file_deleted = false;
                        if (file_exists($physical_path) && is_file($physical_path)) {
                            $file_deleted = @unlink($physical_path);
                        }
                        
                        // CRITICAL: Soft delete from database - ALWAYS attempt even if file deletion failed
                        $delete_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE academic_year = ? AND dept_id = ? AND page_section = 'faculty_output' AND serial_number = ? AND status = 'active'";
                        $delete_stmt = mysqli_prepare($conn, $delete_query);
                        if (!$delete_stmt) {
                            $response = ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
                        } else {
                            mysqli_stmt_bind_param($delete_stmt, 'sis', $academic_year, $dept_id, $srno);
                            $db_deleted = mysqli_stmt_execute($delete_stmt);
                            $affected_rows = mysqli_stmt_affected_rows($delete_stmt);
                            mysqli_stmt_close($delete_stmt); // CRITICAL: Close statement
                            
                            // CRITICAL: Build response in variable
                            if ($db_deleted && $affected_rows > 0) {
                                $response = ['success' => true, 'message' => 'Document deleted successfully' . ($file_deleted ? '' : ' (file not found on disk)')];
                            } else {
                                $response = ['success' => false, 'message' => 'Database delete failed or record not found'];
                            }
                        }
                    } else {
                        // Free result if it exists
                        if ($result) {
                            mysqli_free_result($result); // CRITICAL: Free result even if empty
                        }
                        $response = ['success' => false, 'message' => 'Document not found'];
                    }
                    mysqli_stmt_close($stmt); // CRITICAL: Close statement
                } else {
                    mysqli_stmt_close($stmt);
                    $response = ['success' => false, 'message' => 'Database error: Failed to execute query'];
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    // CRITICAL #1: Clear ALL output buffers before final output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL #4: Set headers again before output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Output response once at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// HANDLE check_doc - before any includes to prevent HTML output
// Match StudentSupport.php pattern exactly for stability
// ============================================================================
if (isset($_GET['check_doc'])) {
    // CRITICAL: Clear ALL output buffers FIRST - prevent any output before JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Suppress error display but log errors
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Start session and load dependencies FIRST (before any potential output)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config silently (capture any output)
    ob_start();
    // CRITICAL: Use simple pattern like StudentSupport.php - just check $conn
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    ob_end_clean();
    
    // CRITICAL: Verify connection exists and is alive after loading config
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode(['success' => false, 'message' => 'Database connection not available'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Load session silently (capture any output)
    ob_start();
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    ob_end_clean();
    
    // CRITICAL: Clear ALL output buffers again after loading dependencies
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL #4: Set proper JSON headers with cache control - MUST be before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Initialize response in variable - build response first, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $srno = (int)($_GET['srno'] ?? 0);
        $file_id = $_GET['file_id'] ?? '';
        
        if ($srno <= 0) {
            $response = ['success' => false, 'message' => 'Invalid serial number'];
        } else {
            $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
            if (!$dept_id) {
                $response = ['success' => false, 'message' => 'Department ID not found'];
            } else {
                // Use centralized getAcademicYear() function if available
                if (!function_exists('getAcademicYear')) {
                    if (file_exists(__DIR__ . '/common_functions.php')) {
                        require_once(__DIR__ . '/common_functions.php');
                    }
                }
                if (function_exists('getAcademicYear')) {
                    $academic_year = getAcademicYear();
                } else {
                    // Fallback calculation - matches getAcademicYear() logic
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
                
                // Use prepared statement - use 'id' column (not 'document_id') to match table structure
                $check_query = "SELECT file_path, file_name, upload_date, id FROM supporting_documents WHERE academic_year = ? AND dept_id = ? AND page_section = 'faculty_output' AND serial_number = ? AND status = 'active' LIMIT 1";
                $stmt = mysqli_prepare($conn, $check_query);
                
                if (!$stmt) {
                    $error = mysqli_error($conn);
                    $response = ['success' => false, 'message' => 'Database error: ' . $error];
                } else {
                    mysqli_stmt_bind_param($stmt, 'sis', $academic_year, $dept_id, $srno);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $result = mysqli_stmt_get_result($stmt);
                        
                        if ($result && mysqli_num_rows($result) > 0) {
                            $row = mysqli_fetch_assoc($result);
                            mysqli_free_result($result); // CRITICAL: Free result set
                            mysqli_stmt_close($stmt); // CRITICAL: Close statement after success
                            
                            // Convert to web-accessible path
                            $file_path = $row['file_path'];
                            if (strpos($file_path, dirname(__DIR__)) === 0) {
                                $file_path = str_replace(dirname(__DIR__) . '/', '', $file_path);
                            }
                            if (strpos($file_path, 'uploads/') === 0) {
                                $file_path = '../' . $file_path;
                            }
                            
                            $response = [
                                'success' => true, 
                                'file_path' => $file_path, 
                                'file_name' => $row['file_name'], 
                                'upload_date' => $row['upload_date'],
                                'document_id' => $row['id'] ?? null  // Use 'id' column from table
                            ];
                        } else {
                            if ($result) {
                                mysqli_free_result($result); // CRITICAL: Free result set even if empty
                            }
                            mysqli_stmt_close($stmt); // CRITICAL: Close statement even if no results
                            $response = ['success' => false, 'message' => 'No document found'];
                        }
                    } else {
                        $error = mysqli_stmt_error($stmt);
                        mysqli_stmt_close($stmt); // CRITICAL: Close statement on execution error
                        $response = ['success' => false, 'message' => 'Query execution failed: ' . $error];
                    }
                }
            }
        }
    } catch (Exception $e) {
        // CRITICAL: Build error response in variable
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    } catch (Error $e) {
        // CRITICAL: Build error response in variable for fatal errors
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
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
// HANDLE check_all_docs - BATCH ENDPOINT for all documents (CRITICAL for 20+ users)
// Returns all documents in ONE query instead of 31 separate queries
// ============================================================================
if (isset($_GET['check_all_docs'])) {
    // CRITICAL: Clear ALL output buffers FIRST - prevent any output before JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Suppress error display but log errors
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Start session and load dependencies FIRST (before any potential output)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config silently (capture any output)
    ob_start();
    // CRITICAL: Use simple pattern - just check $conn
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    ob_end_clean();
    
    // CRITICAL: Verify connection exists and is alive after loading config
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(503); // Service Unavailable
        }
        echo json_encode(['success' => false, 'message' => 'Database connection not available'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Load session silently (capture any output)
    ob_start();
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    ob_end_clean();
    
    // CRITICAL: Clear ALL output buffers again after loading dependencies
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL: Set proper JSON headers with cache control - MUST be before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL: Initialize response in variable - build response first, output once at end
    $response = ['success' => false, 'message' => 'Unknown error', 'documents' => []];
    
    try {
        $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
        if (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found', 'documents' => []];
        } else {
            // CRITICAL FIX: Use centralized getAcademicYear() function consistently
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
            
            // Calculate previous academic year for fallback
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            if ($current_month >= 7) {
                // Current: 2025-2026, Previous: 2024-2025
                $prev_year = ($current_year - 1) . '-' . $current_year;
            } else {
                // Current: 2024-2025, Previous: 2023-2024
                $prev_year = ($current_year - 3) . '-' . ($current_year - 2);
            }
            
            // CRITICAL: Query with fallback to check current year AND previous year
            // This handles cases where documents were uploaded in previous academic year
            $batch_query = "SELECT serial_number, file_path, file_name, upload_date, id, academic_year
                           FROM supporting_documents 
                           WHERE dept_id = ? AND page_section = 'faculty_output' 
                           AND (academic_year = ? OR academic_year = ?) AND status = 'active'
                           ORDER BY academic_year DESC, id DESC";
            $stmt = mysqli_prepare($conn, $batch_query);
            
            if (!$stmt) {
                $error = mysqli_error($conn);
                $response = ['success' => false, 'message' => 'Database error: ' . $error, 'documents' => []];
            } else {
                mysqli_stmt_bind_param($stmt, 'iss', $dept_id, $academic_year, $prev_year);
                
                if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    
                    $documents = [];
                    $documents_found = []; // Track documents by serial number, prefer current year
                    if ($result) {
                        while ($row = mysqli_fetch_assoc($result)) {
                            $srno = (int)$row['serial_number'];
                            $doc_year = $row['academic_year'] ?? $academic_year;
                            
                            // Only keep the most recent document for each serial number (prefer current year)
                            if (!isset($documents_found[$srno]) || $doc_year === $academic_year) {
                                // Convert to web-accessible path (robust path conversion)
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
                                    $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept_id . '/faculty_output/' . $filename;
                            }
                            
                            // Store by serial_number for easy lookup
                                $documents_found[$srno] = [
                                'success' => true,
                                'file_path' => $file_path,
                                'file_name' => $row['file_name'],
                                'upload_date' => $row['upload_date'],
                                'document_id' => $row['id'] ?? null
                            ];
                        }
                        }
                        $documents = $documents_found;
                        mysqli_free_result($result); // CRITICAL: Free result set
                    }
                    mysqli_stmt_close($stmt); // CRITICAL: Close statement
                    
                    $response = [
                        'success' => true,
                        'message' => 'Documents loaded successfully',
                        'documents' => $documents
                    ];
                } else {
                    $error = mysqli_stmt_error($stmt);
                    mysqli_stmt_close($stmt); // CRITICAL: Close statement on execution error
                    $response = ['success' => false, 'message' => 'Query execution failed: ' . $error, 'documents' => []];
                }
            }
        }
    } catch (Exception $e) {
        // CRITICAL: Build error response in variable
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'documents' => []];
    } catch (Error $e) {
        // CRITICAL: Build error response in variable for fatal errors
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'documents' => []];
    }
    
    // CRITICAL: Clear ALL output buffers before final output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL: Output response once at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Now continue with normal page load
require('session.php');

// Enable error logging but don't display to user
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ============================================================================
// CORRECT ACADEMIC YEAR LOGIC (July Rollover)
// ============================================================================
// If month >= 7 (July onwards): academic year is current_year to current_year+1
// If month < 7 (January to June): academic year is (current_year-2) to (current_year-1)
// 
// Example: January 2026 = 2024-2025, July 2026 = 2026-2027
// ============================================================================
$current_year = (int)date('Y');
$current_month = (int)date('n');

if ($current_month >= 7) {
    // July onwards: e.g., July 2026 = 2026-2027
    $A_YEAR = $current_year . '-' . ($current_year + 1);
} else {
    // Before July: e.g., January 2026 = 2024-2025
    $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
}

// Financial year logic - convert academic year to financial year
function getFinancialYear($academic_year) {
    if (empty($academic_year)) {
        // Use centralized getAcademicYear() function if available
        if (!function_exists('getAcademicYear')) {
            if (file_exists(__DIR__ . '/common_functions.php')) {
                require_once(__DIR__ . '/common_functions.php');
            }
        }
        if (function_exists('getAcademicYear')) {
            $academic_year = getAcademicYear();
        } else {
            // Fallback calculation - matches getAcademicYear() logic
            $current_year = (int)date("Y");
            $current_month = (int)date("n");
            // July onwards (month >= 7): current_year to current_year+1
            // Below July (month < 7): (current_year-2) to (current_year-1)
            if ($current_month >= 7) {
                $academic_year = $current_year . '-' . ($current_year + 1);
            } else {
                $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
            }
        }
    }
    $parts = explode('-', $academic_year);
    if (count($parts) == 2) {
        $start_year = intval($parts[0]);
        $end_year = intval($parts[1]);
        
        // Convert academic year to financial year
        // Academic year 2022-2023 = Financial year 2022-2023
        // Academic year 2023-2024 = Financial year 2023-2024  
        // Academic year 2024-2025 = Financial year 2024-2025
        return $start_year . '-' . $end_year;
    }
    return $academic_year;
}

$FINANCIAL_YEAR = getFinancialYear($A_YEAR ?? '');

function getCurrentDepartmentId(): int {
    if (!empty($GLOBALS['userInfo']['DEPT_ID'])) {
        return (int)$GLOBALS['userInfo']['DEPT_ID'];
    }
    if (isset($_SESSION['dept_id'])) {
        return (int)$_SESSION['dept_id'];
    }
    return 0;
}

function encodeFacultyJson($data): string {
    if (empty($data)) {
        return json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $encoded;
}

function bindParamsDynamic($stmt, array &$values): void {
    $types = '';
    $refs = [];
    foreach ($values as $key => $value) {
        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
            $values[$key] = (string)$value;
        }
        $refs[$key] = &$values[$key];
    }
    array_unshift($refs, $types);
    $bindArguments = array_merge([$stmt], $refs);
    call_user_func_array('mysqli_stmt_bind_param', $bindArguments);
}

// ============================================================================
// CRITICAL: Handle AJAX requests FIRST - BEFORE ANY INCLUDES OR OUTPUT
// ============================================================================

// Handle PDF uploads - MUST BE FIRST (use common_upload_handler.php)
if (isset($_POST['upload_document'])) {
    // Start session and load config FIRST (minimal requirements for AJAX)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config for database connection - suppress any output
    ob_start();
    // CRITICAL: Use simple pattern like StudentSupport.php - just check $conn
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    ob_end_clean();
    
    // Load session for user info - suppress any output
    ob_start();
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    ob_end_clean();
    
    // Clear all output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Suppress all errors and warnings
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Set proper headers FIRST - before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    try {
        // CSRF validation when utilities available
        if (file_exists(__DIR__ . '/csrf.php')) {
            ob_start();
            require_once __DIR__ . '/csrf.php';
            ob_end_clean();
            
            if (function_exists('csrf_token')) {
                csrf_token(); // Generate if missing
            }
            if (function_exists('validate_csrf')) {
                $csrf = $_POST['csrf_token'] ?? null;
                if (empty($csrf)) {
                    // CRITICAL: Clear buffers and set headers before exit
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Content-Type: application/json; charset=UTF-8');
                        header('Cache-Control: no-cache, must-revalidate, max-age=0');
                    }
                    $error_response = ['success' => false, 'message' => 'Security token missing. Please refresh the page.'];
                    echo json_encode($error_response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    exit;
                }
                if (!validate_csrf($csrf)) {
                    // CRITICAL: Clear buffers and set headers before exit
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Content-Type: application/json; charset=UTF-8');
                        header('Cache-Control: no-cache, must-revalidate, max-age=0');
                    }
                    $error_response = ['success' => false, 'message' => 'Security validation failed. Please refresh and try again.'];
                    echo json_encode($error_response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
    }
    
        $file_id = $_POST['file_id'] ?? '';
        $srno = (int)($_POST['srno'] ?? 0);
        
        // Get department ID
        $dept_id = getCurrentDepartmentId();
        if (!$dept_id) {
            // CRITICAL: Clear buffers and set headers before exit
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-cache, must-revalidate, max-age=0');
            }
            $error_response = ['success' => false, 'message' => 'Department ID not found. Please login again.'];
            echo json_encode($error_response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Calculate academic year - use centralized function if available
        if (!function_exists('getAcademicYear')) {
            if (file_exists(__DIR__ . '/common_functions.php')) {
                require_once(__DIR__ . '/common_functions.php');
            }
        }
        if (function_exists('getAcademicYear')) {
            $A_YEAR = getAcademicYear();
        } else {
            // Fallback calculation - matches getAcademicYear() logic
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            if ($current_month >= 7) {
                $A_YEAR = $current_year . '-' . ($current_year + 1);
            } else {
                $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
            }
        }
        
        // Route through common_upload_handler.php
        require_once(__DIR__ . '/common_upload_handler.php');
        
        // Set global variables for common_upload_handler.php
        global $conn, $userInfo;
        
        // Verify dept_id is set
        if (!$dept_id || $dept_id <= 0) {
            $dept_id = getCurrentDepartmentId();
            if (!$dept_id || $dept_id <= 0) {
                throw new Exception('Department ID (DEPT_ID) not found. Please login again.');
            }
        }
        
        // Verify A_YEAR is set
        if (empty($A_YEAR)) {
            // Use centralized getAcademicYear() function if available
            if (!function_exists('getAcademicYear')) {
                if (file_exists(__DIR__ . '/common_functions.php')) {
                    require_once(__DIR__ . '/common_functions.php');
                }
            }
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
        
        $GLOBALS['dept_id'] = $dept_id;
        $GLOBALS['A_YEAR'] = $A_YEAR;
        
        // Get specific document title based on file_id
        $document_titles_map = [
            'awards_state_govt' => 'State Level Awards Documentation',
            'awards_national_govt' => 'National Level Awards Documentation',
            'awards_international_govt' => 'International Level Awards Documentation',
            'awards_international_fellowship' => 'International Fellowship Documentation',
            'projects_non_govt' => 'Non-Government Sponsored Projects Documentation',
            'projects_govt' => 'Government Sponsored Projects Documentation',
            'projects_consultancy' => 'Consultancy Projects Documentation',
            'training_corporate' => 'Corporate Training Programs Documentation',
            'recognitions_infra' => 'Department Recognitions & Infrastructure Documentation',
            'dpiit_certificates' => 'DPIIT Recognition Certificates',
            'investment_agreements' => 'Investment Agreements & Funding Proof',
            'grant_letters' => 'Government Grant Letters',
            'trl_documentation' => 'Technology Readiness Level Documentation',
            'turnover_certificates' => 'Turnover Certificates & Financial Statements',
            'alumni_verification' => 'Alumni Founder Verification Documents',
            'patents_ipr' => 'IPR (Patents/Copyright/Trademarks/Designs) Documentation',
            'patents_filed' => 'Patents Filed Documentation',
            'patents_published' => 'Patents Published Documentation',
            'patents_granted' => 'Patents Granted Documentation',
            'copyrights_docs' => 'Copyrights Documentation',
            'designs_docs' => 'Designs Documentation',
            'publications_scopus' => 'Scopus/Web of Sciences Journals Documentation',
            'publications_conference' => 'Conference Publications Documentation',
            'publications_ugc' => 'UGC Listed Non-Scopus Journals Documentation',
            'publications_issn' => 'ISSN Journals & Special Issues Documentation',
            'publications_art' => 'Art Exhibitions & Theatre Performances Documentation',
            'bibliometrics_impact' => 'Impact Factor & Bibliometrics Documentation',
            'bibliometrics_hindex' => 'h-index Documentation',
            'books_moocs' => 'Books, Chapters & MOOCs Documentation',
            'desc_initiative_doc' => 'Research Initiative Description Documentation',
            'desc_collaboration_doc' => 'Collaboration Description Documentation'
        ];
        
        $specific_document_title = $document_titles_map[$file_id] ?? 'Faculty Output Documentation';
        
        try {
            // Construct upload directory path: uploads/YEAR/DEPARTMENT/department_ID/Faculty_output/
            // Ensure path uses forward slashes and correct structure
            $upload_directory = "uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/Faculty_output/";
            $upload_dir = dirname(__DIR__) . '/' . $upload_directory;
            
            // Log the upload path for debugging
            error_log("[FacultyOutput] Upload path: {$upload_dir}");
            error_log("[FacultyOutput] Dept ID: {$dept_id}, Year: {$A_YEAR}");
            
            // Use common upload handler with specific document title
            // Path structure: uploads/YEAR/DEPARTMENT/department_ID/Faculty_output/FILENAME_UNIQUE_CODE.pdf
            $result = handleDocumentUpload('faculty_output', 'Faculty Output', [
                'upload_dir' => $upload_dir,
                'max_size' => 10,
                'document_title' => $specific_document_title,
                'srno' => $srno,
                'file_id' => $file_id
            ]);
                
            // CRITICAL: Ensure result is an array - build response in variable
            $response = $result;
            if (!is_array($response)) {
                $response = ['success' => false, 'message' => 'Invalid response from upload handler'];
            }
            
            // Ensure file path has ../ prefix for web access
            if ($response['success'] && isset($response['file_path'])) {
                $web_path = $response['file_path'];
                if (strpos($web_path, '../') !== 0 && strpos($web_path, 'uploads/') === 0) {
                    $web_path = '../' . $web_path;
                }
                // Normalize path separators
                $web_path = str_replace('\\', '/', $web_path);
                $response['file_path'] = $web_path;
            }
            
            // CRITICAL: Clear ALL output buffers completely - prevent any output before JSON
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            // CRITICAL: Set proper JSON headers with cache control BEFORE any output
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: 0');
                header('X-Content-Type-Options: nosniff');
            }
            
            // CRITICAL: Build JSON response in variable, output once at end
            $json_output = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json_output === false) {
                // Fallback if encoding fails
                $json_output = json_encode(['success' => false, 'message' => 'Error encoding response'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            
            // Output JSON once at end
            echo $json_output;
        } catch (Exception $e) {
            // CRITICAL: Clear ALL output buffers completely
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
            // CRITICAL: Set proper JSON headers with cache control BEFORE any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: 0');
                header('X-Content-Type-Options: nosniff');
            }
            
            // CRITICAL: Build response in variable, output once at end
            $error_response = ['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()];
            $json_output = json_encode($error_response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json_output === false) {
                $json_output = json_encode(['success' => false, 'message' => 'Error encoding response'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            echo $json_output;
                exit;
        }
    } catch (Exception $e) {
        // CRITICAL: Clear ALL output buffers completely
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
        // CRITICAL: Set proper JSON headers with cache control BEFORE any output
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-Content-Type-Options: nosniff');
        }
        
        // CRITICAL: Build response in variable, output once at end
        $error_response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        $json_output = json_encode($error_response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json_output === false) {
            $json_output = json_encode(['success' => false, 'message' => 'Error encoding response'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        echo $json_output;
        exit;
    } catch (Error $e) {
        // CRITICAL: Clear ALL output buffers completely
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
        // CRITICAL: Set proper JSON headers with cache control BEFORE any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-Content-Type-Options: nosniff');
        }
        
        // CRITICAL: Build response in variable, output once at end
        $error_response = ['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()];
        $json_output = json_encode($error_response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json_output === false) {
            $json_output = json_encode(['success' => false, 'message' => 'Error encoding response'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        echo $json_output;
            exit;
        }
            exit;
        }

// Delete handler moved to top of file (above check_doc handler at line 15)

// Handle document status check - MUST BE FIRST
// ============================================================================
// NOTE: check_doc handler moved to top of file (before session.php include)
// This duplicate handler has been removed to prevent empty JSON responses
// ============================================================================

// Require common_functions early to ensure getDepartmentInfo is available
if (!function_exists('getDepartmentInfo')) {
    require_once(__DIR__ . '/common_functions.php');
}

error_reporting(0);
ini_set('display_errors', 0);

// Get department ID from session (already verified in session.php)
// Get dept_id - but don't exit here if not found, wait until after unified_header.php
// This section runs BEFORE unified_header.php, so $userInfo may not be available yet
$dept_id = getCurrentDepartmentId();
// Note: We'll validate $dept_id again after unified_header.php is included

// Get dept_info - ensure it's available (only if we have dept_id)
// CRITICAL: Verify connection exists before database operations
if ($dept_id > 0 && isset($conn) && $conn) {
    if (!isset($dept_info) || empty($dept_info) || !is_array($dept_info)) {
        if (function_exists('getDepartmentInfo')) {
            // getDepartmentInfo from common_functions.php only takes $conn
            $dept_info = getDepartmentInfo($conn);
            if (!$dept_info || !is_array($dept_info)) {
                $dept_info = ['DEPT_COLL_NO' => $dept_id, 'DEPT_NAME' => ''];
            }
        } else {
            // Fallback: get from database directly
            $dept_info_query = "SELECT DEPT_COLL_NO, DEPT_NAME FROM department_master WHERE DEPT_ID = ? LIMIT 1";
            $dept_stmt = mysqli_prepare($conn, $dept_info_query);
            if ($dept_stmt) {
                mysqli_stmt_bind_param($dept_stmt, "i", $dept_id);
                mysqli_stmt_execute($dept_stmt);
                $dept_result = mysqli_stmt_get_result($dept_stmt);
                if ($dept_result && mysqli_num_rows($dept_result) > 0) {
                    $dept_info = mysqli_fetch_assoc($dept_result);
                    mysqli_free_result($dept_result); // CRITICAL: Free result set
                } else {
                    if ($dept_result) {
                        mysqli_free_result($dept_result); // CRITICAL: Free result set even if empty
                    }
                    $dept_info = ['DEPT_COLL_NO' => $dept_id, 'DEPT_NAME' => ''];
                }
                mysqli_stmt_close($dept_stmt); // CRITICAL: Close statement
            } else {
                $dept_info = ['DEPT_COLL_NO' => $dept_id, 'DEPT_NAME' => ''];
            }
        }
    }
    
    // Ensure dept_info is always set
    if (!isset($dept_info) || !is_array($dept_info)) {
        $dept_info = ['DEPT_COLL_NO' => $dept_id, 'DEPT_NAME' => ''];
    }
    
    $dept_code = $dept_info['DEPT_COLL_NO'] ?? $dept_id;
    $dept_name = $dept_info['DEPT_NAME'] ?? '';
} else {
    // Set defaults if dept_id not available yet (will be set after unified_header.php)
    $dept_info = ['DEPT_COLL_NO' => '', 'DEPT_NAME' => ''];
    $dept_code = '';
    $dept_name = '';
}

// Get messages from session and clear them (like StudentSupport.php)
$success_message = isset($_SESSION['success']) ? $_SESSION['success'] : '';
$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);

// Initialize variables
$form_locked = false;
$existing_data = null;

// Success message handling removed - now handled directly in clear data action

// ============================================================================
// DATA RETRIEVAL
// ============================================================================

// Check if faculty_output table exists, if not create it
// CRITICAL: Use simple pattern like StudentSupport.php - just check $conn
if (!isset($conn) || !$conn) {
    require_once(__DIR__ . '/../config.php');
}

// CRITICAL: Verify connection exists and is alive after loading config
if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
    error_log("FacultyOutput: Database connection not available or dead at table check");
    die("Database connection error. Please contact administrator.");
}

$table_check = "SHOW TABLES LIKE 'faculty_output'";
$table_result = mysqli_query($conn, $table_check);
$table_exists = false;
if ($table_result) {
    $table_exists = mysqli_num_rows($table_result) > 0;
    mysqli_free_result($table_result); // CRITICAL: Free result set
}
if (!$table_exists) {
    // Create a basic faculty_output table with JSON fields
    $create_table = "CREATE TABLE IF NOT EXISTS `faculty_output` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `DEPT_ID` int(11) NOT NULL,
        `A_YEAR` varchar(20) NOT NULL,
        `recognitions` text DEFAULT NULL,
        `other_recognitions` text DEFAULT NULL,
        `infra_funding` decimal(15,2) DEFAULT 0.00,
        `innovation_courses` int(11) DEFAULT 0,
        `innovation_training` int(11) DEFAULT 0,
        `research_staff_male` int(11) DEFAULT 0,
        `research_staff_female` int(11) DEFAULT 0,
        `research_staff_other` int(11) DEFAULT 0,
        `sponsored_projects_total` int(11) DEFAULT 0,
        `sponsored_projects_agencies` int(11) DEFAULT 0,
        `sponsored_amount_agencies` decimal(15,2) DEFAULT 0.00,
        `sponsored_projects_industries` int(11) DEFAULT 0,
        `sponsored_amount_industries` decimal(15,2) DEFAULT 0.00,
        `patents_published_2022` int(11) DEFAULT 0,
        `patents_published_2023` int(11) DEFAULT 0,
        `patents_published_2024` int(11) DEFAULT 0,
        `patents_granted_2022` int(11) DEFAULT 0,
        `patents_granted_2023` int(11) DEFAULT 0,
        `patents_granted_2024` int(11) DEFAULT 0,
        `awards` longtext DEFAULT NULL COMMENT 'JSON data for awards',
        `projects` longtext DEFAULT NULL COMMENT 'JSON data for projects',
        `trainings` longtext DEFAULT NULL COMMENT 'JSON data for trainings',
        `publications` longtext DEFAULT NULL COMMENT 'JSON data for publications',
        `bibliometrics` longtext DEFAULT NULL COMMENT 'JSON data for bibliometrics',
        `books` longtext DEFAULT NULL COMMENT 'JSON data for books',
        `desc_initiative` text DEFAULT NULL,
        `desc_impact` text DEFAULT NULL,
        `desc_collaboration` text DEFAULT NULL,
        `desc_plan` text DEFAULT NULL,
        `desc_recognition` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_dept_year` (`DEPT_ID`, `A_YEAR`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (!mysqli_query($conn, $create_table)) {
        if ($table_result) {
            mysqli_free_result($table_result); // CRITICAL: Free result set before die
        }
        die("
        <div class='container my-5'>
            <div class='alert alert-danger'>
                <h4>Database Error</h4>
                <p>Failed to create faculty_output table: " . mysqli_error($conn) . "</p>
            </div>
        </div>
        ");
    }
    if ($table_result) {
        mysqli_free_result($table_result); // CRITICAL: Free result set
    }
} else {
    // Table exists, check if JSON columns exist and add them if missing
    // CRITICAL OPTIMIZATION: Use single query to get all columns instead of 40+ individual queries
    // This prevents database crashes with 20+ concurrent users
    $all_columns_query = "SHOW COLUMNS FROM faculty_output";
    $all_columns_result = mysqli_query($conn, $all_columns_query);
    $existing_columns = [];
    if ($all_columns_result) {
        while ($col_row = mysqli_fetch_assoc($all_columns_result)) {
            $existing_columns[] = $col_row['Field'];
        }
        mysqli_free_result($all_columns_result); // CRITICAL: Free result set
    }
    
    $columns_to_add = [
        'awards' => "ALTER TABLE faculty_output ADD COLUMN awards LONGTEXT DEFAULT NULL COMMENT 'JSON data for awards'",
        'projects' => "ALTER TABLE faculty_output ADD COLUMN projects LONGTEXT DEFAULT NULL COMMENT 'JSON data for projects'",
        'trainings' => "ALTER TABLE faculty_output ADD COLUMN trainings LONGTEXT DEFAULT NULL COMMENT 'JSON data for trainings'",
        'publications' => "ALTER TABLE faculty_output ADD COLUMN publications LONGTEXT DEFAULT NULL COMMENT 'JSON data for publications'",
        'bibliometrics' => "ALTER TABLE faculty_output ADD COLUMN bibliometrics LONGTEXT DEFAULT NULL COMMENT 'JSON data for bibliometrics'",
        'books' => "ALTER TABLE faculty_output ADD COLUMN books LONGTEXT DEFAULT NULL COMMENT 'JSON data for books'",
        'research_staff_details' => "ALTER TABLE faculty_output ADD COLUMN research_staff_details LONGTEXT DEFAULT NULL COMMENT 'JSON data for research staff'",
        'copyrights_published' => "ALTER TABLE faculty_output ADD COLUMN copyrights_published INT(11) DEFAULT 0",
        'copyrights_granted' => "ALTER TABLE faculty_output ADD COLUMN copyrights_granted INT(11) DEFAULT 0",
        'designs_published' => "ALTER TABLE faculty_output ADD COLUMN designs_published INT(11) DEFAULT 0",
        'designs_granted' => "ALTER TABLE faculty_output ADD COLUMN designs_granted INT(11) DEFAULT 0",
        'tot_count' => "ALTER TABLE faculty_output ADD COLUMN tot_count INT(11) DEFAULT 0",
        'awards_count' => "ALTER TABLE faculty_output ADD COLUMN awards_count INT(11) DEFAULT 0",
        'projects_count' => "ALTER TABLE faculty_output ADD COLUMN projects_count INT(11) DEFAULT 0",
        'training_count' => "ALTER TABLE faculty_output ADD COLUMN training_count INT(11) DEFAULT 0",
        'publications_count' => "ALTER TABLE faculty_output ADD COLUMN publications_count INT(11) DEFAULT 0",
        'bibliometrics_count' => "ALTER TABLE faculty_output ADD COLUMN bibliometrics_count INT(11) DEFAULT 0",
        'books_count' => "ALTER TABLE faculty_output ADD COLUMN books_count INT(11) DEFAULT 0",
        'total_dpiit_startups' => "ALTER TABLE faculty_output ADD COLUMN total_dpiit_startups INT(11) DEFAULT 0",
        'dpiit_startup_details' => "ALTER TABLE faculty_output ADD COLUMN dpiit_startup_details LONGTEXT DEFAULT NULL",
        'total_vc_investments' => "ALTER TABLE faculty_output ADD COLUMN total_vc_investments INT(11) DEFAULT 0",
        'vc_investment_details' => "ALTER TABLE faculty_output ADD COLUMN vc_investment_details LONGTEXT DEFAULT NULL",
        'total_seed_funding' => "ALTER TABLE faculty_output ADD COLUMN total_seed_funding INT(11) DEFAULT 0",
        'seed_funding_details' => "ALTER TABLE faculty_output ADD COLUMN seed_funding_details LONGTEXT DEFAULT NULL",
        'total_fdi_investments' => "ALTER TABLE faculty_output ADD COLUMN total_fdi_investments INT(11) DEFAULT 0",
        'fdi_investment_details' => "ALTER TABLE faculty_output ADD COLUMN fdi_investment_details LONGTEXT DEFAULT NULL",
        'total_innovation_grants' => "ALTER TABLE faculty_output ADD COLUMN total_innovation_grants INT(11) DEFAULT 0",
        'innovation_grants_details' => "ALTER TABLE faculty_output ADD COLUMN innovation_grants_details LONGTEXT DEFAULT NULL",
        'total_trl_innovations' => "ALTER TABLE faculty_output ADD COLUMN total_trl_innovations INT(11) DEFAULT 0",
        'trl_innovations_details' => "ALTER TABLE faculty_output ADD COLUMN trl_innovations_details LONGTEXT DEFAULT NULL",
        'total_turnover_achievements' => "ALTER TABLE faculty_output ADD COLUMN total_turnover_achievements INT(11) DEFAULT 0",
        'turnover_achievements_details' => "ALTER TABLE faculty_output ADD COLUMN turnover_achievements_details LONGTEXT DEFAULT NULL",
        'total_forbes_alumni' => "ALTER TABLE faculty_output ADD COLUMN total_forbes_alumni INT(11) DEFAULT 0",
        'forbes_alumni_details' => "ALTER TABLE faculty_output ADD COLUMN forbes_alumni_details LONGTEXT DEFAULT NULL",
        'patent_details' => "ALTER TABLE faculty_output ADD COLUMN patent_details LONGTEXT DEFAULT NULL",
        'patents_count' => "ALTER TABLE faculty_output ADD COLUMN patents_count INT(11) DEFAULT 0"
    ];
    
    // CRITICAL OPTIMIZATION: Check all columns in memory instead of individual queries
    foreach ($columns_to_add as $column => $sql) {
        // Check if column exists in the array we fetched
        if (!in_array($column, $existing_columns, true)) {
            // Column doesn't exist, add it
            if (!mysqli_query($conn, $sql)) {
                error_log("FacultyOutput: Failed to add column $column: " . mysqli_error($conn));
            } else {
                // Add to existing_columns array to prevent duplicate attempts in same request
                $existing_columns[] = $column;
            }
        }
    }
}

// Check if data already exists for this department and academic year (like StudentSupport.php)
// NOTE: This runs BEFORE unified_header.php, so $dept_id might be 0
// We'll re-check after unified_header.php is included
$editRow = null; // Initialize editRow like StudentSupport.php
$existing_data = null; // Initialize
$form_locked = false; // Initialize

// Only try to load existing data if we have a valid dept_id
$check_dept_id = getCurrentDepartmentId();
if ($check_dept_id > 0 && isset($conn)) {
    $existing_data_query = "SELECT * FROM faculty_output WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $existing_stmt = mysqli_prepare($conn, $existing_data_query);
    if ($existing_stmt) {
        mysqli_stmt_bind_param($existing_stmt, "is", $check_dept_id, $A_YEAR);
        mysqli_stmt_execute($existing_stmt);
        $existing_result = mysqli_stmt_get_result($existing_stmt);
        if ($existing_result && mysqli_num_rows($existing_result) > 0) {
            $existing_row = mysqli_fetch_assoc($existing_result);
            mysqli_free_result($existing_result); // CRITICAL: Free result set
            $form_locked = true; // Lock form if data exists
            // Populate form with existing data (like StudentSupport.php)
            $existing_data = $existing_row;
            $editRow = $existing_row; // Set editRow for form population
            error_log("[FacultyOutput] Form locked - Found existing data for dept=$check_dept_id, A_YEAR=$A_YEAR");
        } else {
            if ($existing_result) {
                mysqli_free_result($existing_result); // CRITICAL: Free result set even if empty
            }
            error_log("[FacultyOutput] Form unlocked - No existing data for dept=$check_dept_id, A_YEAR=$A_YEAR");
        }
        mysqli_stmt_close($existing_stmt); // CRITICAL: Close statement
    }
}

// ============================================================================
// CLEAR DATA HANDLING
// ============================================================================

// Handle clear data action
if (isset($_GET['action']) && $_GET['action'] === 'clear_data') {
    try {
        $dept = getCurrentDepartmentId();
        if (!$dept) {
            throw new Exception('Department ID not found. Please contact administrator.');
        }
        $academic_year = $A_YEAR;
        
        // Clear data from faculty_output table for current academic year
        if (isset($existing_data['id'])) {
            $clear_query = "DELETE FROM faculty_output WHERE DEPT_ID = ? AND A_YEAR = ?";
            $stmt = mysqli_prepare($conn, $clear_query);
            mysqli_stmt_bind_param($stmt, "is", $dept, $academic_year);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        
        // Clear data from unified faculty_output table only
        // All data is now in the main table, no need to clear separate tables
        
        // Delete uploaded documents using the unified supporting_documents table for current academic year
        // Get ALL documents (not just active ones) to delete files
        $delete_docs_query = "SELECT file_path FROM supporting_documents WHERE dept_id = ? AND page_section = 'faculty_output' AND academic_year = ?";
        $stmt = mysqli_prepare($conn, $delete_docs_query);
        mysqli_stmt_bind_param($stmt, "is", $dept, $academic_year);
        mysqli_stmt_execute($stmt);
        $docs_result = mysqli_stmt_get_result($stmt);
        
        if ($docs_result) {
            while ($doc = mysqli_fetch_assoc($docs_result)) {
                $file_path = $doc['file_path'];
                $absolute_path = (strpos($file_path, 'uploads/') === 0 || strpos($file_path, '../uploads/') === 0)
                    ? dirname(__DIR__) . '/' . ltrim(str_replace('../', '', $file_path), '/')
                    : $file_path;
                if (file_exists($absolute_path)) {
                    @unlink($absolute_path);
                }
            }
            mysqli_free_result($docs_result); // CRITICAL: Free result set after loop
        }
        mysqli_stmt_close($stmt); // CRITICAL: Close statement
        
        // Hard delete from unified supporting_documents table for current academic year only
        $delete_docs_query = "DELETE FROM supporting_documents WHERE dept_id = ? AND page_section = 'faculty_output' AND academic_year = ?";
        $stmt = mysqli_prepare($conn, $delete_docs_query);
        mysqli_stmt_bind_param($stmt, "is", $dept, $academic_year);
        $docs_deleted = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        
        // Set success message in session
        $_SESSION['success'] = 'Data and uploaded documents cleared successfully for academic year ' . $A_YEAR . '!';
        
        // Clear output buffers before redirect
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Redirect to clean URL without query params
        if (!headers_sent()) {
            header('Location: FacultyOutput.php');
        exit();
        }
    } catch (Exception $e) {
        $clear_error = "Error clearing data: " . $e->getMessage();
    }
}
// ============================================================================
// HANDLE FORM SUBMISSION - MUST BE BEFORE unified_header.php
// ============================================================================
// CRITICAL: Check for both button names and hidden inputs (form.submit() doesn't include button)
if (isset($_POST['save_faculty']) || isset($_POST['update_faculty'])) {
    // Suppress error output during POST processing
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    // DEBUG: Log POST data for sections 8, 9, 10
    error_log("[FacultyOutput] DEBUG - POST data for publications_count: " . ($_POST['publications_count'] ?? 'NOT SET'));
    error_log("[FacultyOutput] DEBUG - POST data for bibliometrics_count: " . ($_POST['bibliometrics_count'] ?? 'NOT SET'));
    error_log("[FacultyOutput] DEBUG - POST data for books_count: " . ($_POST['books_count'] ?? 'NOT SET'));
    
    // Check if any publication fields are in POST
    // CRITICAL: Scan all POST keys to support 25+ publications (removed 50 limit)
    $pub_fields_found = 0;
    $pub_fields_with_data = 0;
    $max_pub_index = 0;
    foreach ($_POST as $key => $value) {
        if (preg_match('/^pub_(title|type|venue|indexed|authors|date|url)_(\d+)$/', $key, $matches)) {
            $i = (int)$matches[2];
            $max_pub_index = max($max_pub_index, $i);
            $pub_fields_found++;
            $title = trim($_POST["pub_title_$i"] ?? '');
            $type = trim($_POST["pub_type_$i"] ?? '');
            if (!empty($title) || !empty($type)) {
                $pub_fields_with_data++;
                error_log("[FacultyOutput] DEBUG - Publication $i has data: title='$title', type='$type'");
            }
        }
    }
    error_log("[FacultyOutput] DEBUG - Found $pub_fields_found publication field sets in POST (max index: $max_pub_index), $pub_fields_with_data with data");
    
    // Check if any bibliometric fields are in POST
    $bib_fields_found = 0;
    $bib_fields_with_data = 0;
    for ($i = 1; $i <= 50; $i++) {
        if (isset($_POST["bib_teacher_name_$i"]) || isset($_POST["bib_impact_factor_$i"])) {
            $bib_fields_found++;
            $name = trim($_POST["bib_teacher_name_$i"] ?? '');
            if (!empty($name)) {
                $bib_fields_with_data++;
                error_log("[FacultyOutput] DEBUG - Bibliometric $i has data: name='$name'");
            }
        }
    }
    error_log("[FacultyOutput] DEBUG - Found $bib_fields_found bibliometric field sets in POST, $bib_fields_with_data with data");
    
    // Check if any book fields are in POST
    $book_fields_found = 0;
    $book_fields_with_data = 0;
    for ($i = 1; $i <= 50; $i++) {
        if (isset($_POST["book_title_$i"]) || isset($_POST["book_type_$i"])) {
            $book_fields_found++;
            $title = trim($_POST["book_title_$i"] ?? '');
            $type = trim($_POST["book_type_$i"] ?? '');
            if (!empty($title) || !empty($type)) {
                $book_fields_with_data++;
                error_log("[FacultyOutput] DEBUG - Book $i has data: title='$title', type='$type'");
            }
        }
    }
    error_log("[FacultyOutput] DEBUG - Found $book_fields_found book field sets in POST, $book_fields_with_data with data");
    
    // CSRF validation
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
        if (function_exists('validate_csrf')) {
            $csrf = $_POST['csrf_token'] ?? null;
            if (empty($csrf)) {
                $_SESSION['error'] = 'Security token missing. Please refresh and try again.';
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                if (!headers_sent()) {
                    header('Location: FacultyOutput.php');
                }
                exit;
            }
            if (!validate_csrf($csrf)) {
                $_SESSION['error'] = 'Security token validation failed. Please refresh and try again.';
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                if (!headers_sent()) {
                    header('Location: FacultyOutput.php');
                }
                exit;
            }
        }
    }
    
    // Verify database connection - use simple pattern like StudentSupport.php
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    
    // CRITICAL: Verify connection exists and is alive after loading config
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        $_SESSION['error'] = 'Database connection not available. Please try again.';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: FacultyOutput.php');
        }
        exit;
    }
    
    // Ensure dept_id and A_YEAR are set
    $dept_id = getCurrentDepartmentId();
    if (!$dept_id || empty($A_YEAR)) {
        error_log("FacultyOutput Error: dept_id=$dept_id, A_YEAR=$A_YEAR");
        $_SESSION['error'] = 'Invalid department or academic year. Please refresh the page.';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: FacultyOutput.php');
        }
        exit;
    }
    
    try {
        // Academic year and dept_id already validated above
        // Test database connection
        if (!$conn) {
            throw new Exception('Database connection failed');
        }
        
        // Simple test query
        $test_query = "SELECT 1 as test";
        $stmt = mysqli_prepare($conn, $test_query);
        mysqli_stmt_execute($stmt);
        $test_result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        if (!$test_result) {
            throw new Exception('Database test query failed: ' . mysqli_error($conn));
        }
        
        $dept_id = getCurrentDepartmentId();
        if (!$dept_id) {
            echo json_encode(['success' => false, 'message' => 'Department ID not found. Please login again.']);
            exit;
        }
        $academic_year = $A_YEAR;
        
        // Simple sanitization for scalar fields
        $other_recognitions = trim($_POST['other_recognitions'] ?? '');
        $infra_funding = (float) ($_POST['infra_funding'] ?? 0);
        $innovation_courses = (int) ($_POST['innovation_courses'] ?? 0);
        $innovation_training = (int) ($_POST['innovation_training'] ?? 0);
        // Patent summary counts - using existing patent_info table structure
        $patents_published_2022 = (int) ($_POST['patents_published_2022'] ?? 0);
        $patents_published_2023 = (int) ($_POST['patents_published_2023'] ?? 0);
        $patents_published_2024 = (int) ($_POST['patents_published_2024'] ?? 0);
        $patents_granted_2022 = (int) ($_POST['patents_granted_2022'] ?? 0);
        $patents_granted_2023 = (int) ($_POST['patents_granted_2023'] ?? 0);
        $patents_granted_2024 = (int) ($_POST['patents_granted_2024'] ?? 0);
        $total_published = $patents_published_2022 + $patents_published_2023 + $patents_published_2024;
        $total_granted = $patents_granted_2022 + $patents_granted_2023 + $patents_granted_2024;
        $patents_count = $total_published + $total_granted;
        
        // Additional IPR fields
        $copyrights_published = (int) ($_POST['copyrights_published'] ?? 0);
        $copyrights_granted = (int) ($_POST['copyrights_granted'] ?? 0);
        $designs_published = (int) ($_POST['designs_published'] ?? 0);
        $designs_granted = (int) ($_POST['designs_granted'] ?? 0);
        $tot_count = (int) ($_POST['tot_count'] ?? 0);
        // CRITICAL: Set values for descriptive fields - check if they exist in POST
        // Don't use default "-" if field exists but is empty (might be intentional)
        $desc_initiative = isset($_POST['desc_initiative']) ? trim($_POST['desc_initiative']) : '-';
        $desc_impact = isset($_POST['desc_impact']) ? trim($_POST['desc_impact']) : '-';
        $desc_collaboration = isset($_POST['desc_collaboration']) ? trim($_POST['desc_collaboration']) : '-';
        $desc_plan = isset($_POST['desc_plan']) ? trim($_POST['desc_plan']) : '-';
        $desc_recognition = isset($_POST['desc_recognition']) ? trim($_POST['desc_recognition']) : '-';
        
        // DEBUG: Log descriptive fields
        error_log("[FacultyOutput] DEBUG - desc_initiative in POST: " . (isset($_POST['desc_initiative']) ? 'YES (length: ' . strlen($desc_initiative) . ')' : 'NO'));
        error_log("[FacultyOutput] DEBUG - desc_impact in POST: " . (isset($_POST['desc_impact']) ? 'YES (length: ' . strlen($desc_impact) . ')' : 'NO'));
        error_log("[FacultyOutput] DEBUG - desc_collaboration in POST: " . (isset($_POST['desc_collaboration']) ? 'YES (length: ' . strlen($desc_collaboration) . ')' : 'NO'));
        error_log("[FacultyOutput] DEBUG - desc_plan in POST: " . (isset($_POST['desc_plan']) ? 'YES (length: ' . strlen($desc_plan) . ')' : 'NO'));
        error_log("[FacultyOutput] DEBUG - desc_recognition in POST: " . (isset($_POST['desc_recognition']) ? 'YES (length: ' . strlen($desc_recognition) . ')' : 'NO'));
        
        // Process JSON data for dynamic fields
        $awards_data = [];
        $projects_data = [];
        $trainings_data = [];
        $publications_data = [];
        $bibliometrics_data = [];
        $books_data = [];
        
        // Dynamic field processing happens in lines 707+ with correct field names
        
        // New sections - Research Staff (will be saved to research_staff table)
        $research_staff_male = (int) ($_POST['research_staff_male'] ?? 0);
        $research_staff_female = (int) ($_POST['research_staff_female'] ?? 0);
        $research_staff_other = (int) ($_POST['research_staff_other'] ?? 0);
        
        // Process detailed research staff data (like StudentSupport.php - read numbered fields)
        $research_staff_data = [];
        $research_staff_total_count = $research_staff_male + $research_staff_female + $research_staff_other;
        if ($research_staff_total_count > 0) {
            for ($i = 1; $i <= $research_staff_total_count; $i++) {
                $agency = isset($_POST["research_staff_agency_$i"]) ? trim($_POST["research_staff_agency_$i"]) : '';
                $amount = isset($_POST["research_staff_amount_$i"]) ? (float)($_POST["research_staff_amount_$i"]) : 0;
                $gender = isset($_POST["research_staff_gender_$i"]) ? trim($_POST["research_staff_gender_$i"]) : 'male';
                if (!empty($agency) || $amount > 0) {
                    $research_staff_data[] = [
                        'agency' => $agency,
                        'amount' => $amount,
                        'gender' => $gender
                    ];
                }
            }
        }
        
        // New sections - Sponsored Project Details (will be saved to sponsored_project_details table)
        $sponsored_projects_total = (int) ($_POST['sponsored_projects_total'] ?? 0);
        $sponsored_projects_agencies = (int) ($_POST['sponsored_projects_agencies'] ?? 0);
        $sponsored_amount_agencies = (float) ($_POST['sponsored_amount_agencies'] ?? 0);
        $sponsored_projects_industries = (int) ($_POST['sponsored_projects_industries'] ?? 0);
        $sponsored_amount_industries = (float) ($_POST['sponsored_amount_industries'] ?? 0);

        // Arrays (dynamic sections) saved as JSON
        $awards_count = (int) ($_POST['awards_count'] ?? 0);
        $projects_count = (int) ($_POST['projects_count'] ?? 0);
        $training_count = (int) ($_POST['training_count'] ?? 0);
        // CRITICAL: Read initial count values from POST (will be updated with actual collected data later)
        // These are just initial values - the actual counts will be calculated from collected data arrays
        $publications_count = (int) ($_POST['publications_count'] ?? 0);
        $bibliometrics_count = (int) ($_POST['bibliometrics_count'] ?? 0);
        $books_count = (int) ($_POST['books_count'] ?? 0);
        
        // DEBUG: Log initial POST values
        error_log("[FacultyOutput] DEBUG - Initial POST counts - publications: $publications_count, bibliometrics: $bibliometrics_count, books: $books_count");
        
        // Process detailed award data
        $award_data = [];
        $awards_count = (int)($_POST['awards_count'] ?? 0);
        
        // CRITICAL FIX: If count is 0, skip processing and clear data
        if ($awards_count > 0) {
            $max_awards = max($awards_count, 50);
            for ($i = 1; $i <= $max_awards; $i++) {
                // Check if any field exists for this index
                if (isset($_POST["award_name_$i"]) || isset($_POST["award_recognition_body_$i"]) || isset($_POST["award_level_$i"]) ||
                    isset($_POST["award_title_$i"]) || isset($_POST["award_agency_$i"]) || isset($_POST["award_date_$i"])) {
                    $name = isset($_POST["award_name_$i"]) ? trim($_POST["award_name_$i"]) : '';
                    $recognition_body = isset($_POST["award_recognition_body_$i"]) ? trim($_POST["award_recognition_body_$i"]) : '';
                    $level = isset($_POST["award_level_$i"]) ? trim($_POST["award_level_$i"]) : '';
                    $title = isset($_POST["award_title_$i"]) ? trim($_POST["award_title_$i"]) : '';
                    $agency = isset($_POST["award_agency_$i"]) ? trim($_POST["award_agency_$i"]) : '';
                    $date = isset($_POST["award_date_$i"]) ? trim($_POST["award_date_$i"]) : '';
                    $agency_address = isset($_POST["award_agency_address_$i"]) ? trim($_POST["award_agency_address_$i"]) : '';
                    $agency_email = isset($_POST["award_agency_email_$i"]) ? trim($_POST["award_agency_email_$i"]) : '';
                    $agency_contact = isset($_POST["award_agency_contact_$i"]) ? trim($_POST["award_agency_contact_$i"]) : '';
                    if (!empty($name) || !empty($recognition_body) || !empty($level) || !empty($title) || !empty($agency)) {
                        $award_data[] = [
                            'name' => $name,
                            'recognition_body' => $recognition_body,
                            'level' => $level,
                            'title' => $title,
                            'agency' => $agency,
                            'date' => $date,
                            'agency_address' => $agency_address,
                            'agency_email' => $agency_email,
                            'agency_contact' => $agency_contact
                        ];
                    }
                } else if ($i > $awards_count) {
                    break; // Stop if we've passed the count
                }
            }
        }
        // CRITICAL: When count is 0, ensure data is empty array
        $awards = encodeFacultyJson($award_data);
        // Always update count based on actual data collected
        $awards_count = count($award_data);
        
        // Process detailed project data
        $project_data = [];
        
        // CRITICAL FIX: If count is 0, skip processing and clear data
        if ($projects_count > 0) {
            $max_projects = max($projects_count, 50);
            for ($i = 1; $i <= $max_projects; $i++) {
                // Check if any field exists for this index
                if (isset($_POST["project_title_$i"]) || isset($_POST["project_type_$i"]) || isset($_POST["project_agency_$i"]) ||
                    isset($_POST["project_investigators_$i"]) || isset($_POST["project_start_date_$i"]) || isset($_POST["project_end_date_$i"]) || isset($_POST["project_amount_$i"])) {
                    $title = isset($_POST["project_title_$i"]) ? trim($_POST["project_title_$i"]) : '';
                    $type = isset($_POST["project_type_$i"]) ? trim($_POST["project_type_$i"]) : '';
                    $agency = isset($_POST["project_agency_$i"]) ? trim($_POST["project_agency_$i"]) : '';
                    $investigators = isset($_POST["project_investigators_$i"]) ? trim($_POST["project_investigators_$i"]) : '';
                    $start_date = isset($_POST["project_start_date_$i"]) ? trim($_POST["project_start_date_$i"]) : '';
                    $end_date = isset($_POST["project_end_date_$i"]) ? trim($_POST["project_end_date_$i"]) : '';
                    $amount = isset($_POST["project_amount_$i"]) ? trim($_POST["project_amount_$i"]) : '';
                    if (!empty($title) || !empty($type) || !empty($agency)) {
                        $project_data[] = [
                            'title' => $title,
                            'type' => $type,
                            'agency' => $agency,
                            'investigators' => $investigators,
                            'start_date' => $start_date,
                            'end_date' => $end_date,
                            'amount' => $amount
                        ];
                    }
                } else if ($i > $projects_count) {
                    break; // Stop if we've passed the count
                }
            }
        }
        // CRITICAL: When count is 0, ensure data is empty array
        $projects = encodeFacultyJson($project_data);
        // Always update count based on actual data collected
        $projects_count = count($project_data);
        
        // Process detailed training data
        $training_data = [];
        
        // CRITICAL FIX: If count is 0, skip processing and clear data
        if ($training_count > 0) {
            $max_trainings = max($training_count, 50);
            for ($i = 1; $i <= $max_trainings; $i++) {
                // Check if any field exists for this index
                if (isset($_POST["training_name_$i"]) || isset($_POST["training_corp_$i"]) || isset($_POST["training_revenue_$i"]) || isset($_POST["training_participants_$i"])) {
                    $name = isset($_POST["training_name_$i"]) ? trim($_POST["training_name_$i"]) : '';
                    $corp = isset($_POST["training_corp_$i"]) ? trim($_POST["training_corp_$i"]) : '';
                    $revenue = isset($_POST["training_revenue_$i"]) ? trim($_POST["training_revenue_$i"]) : '';
                    $participants = isset($_POST["training_participants_$i"]) ? trim($_POST["training_participants_$i"]) : '';
                    if (!empty($name) || !empty($corp) || !empty($revenue)) {
                        $training_data[] = [
                            'name' => $name,
                            'corp' => $corp,
                            'revenue' => $revenue,
                            'participants' => $participants
                        ];
                    }
                } else if ($i > $training_count) {
                    break; // Stop if we've passed the count
                }
            }
        }
        // CRITICAL: When count is 0, ensure data is empty array
        $trainings = encodeFacultyJson($training_data);
        // Always update count based on actual data collected
        $training_count = count($training_data);
        
        // ============================================================================
        // SECTION 8: RESEARCH PUBLICATIONS - READ FROM JSON FIELD
        // ============================================================================
        error_log("[FacultyOutput] CRITICAL - Starting publications processing");
        
        // CRITICAL: Read from JSON field first (preferred method)
        if (!empty($_POST['publications_json'])) {
            $publication_data = json_decode($_POST['publications_json'], true);
            if (!is_array($publication_data)) {
                $publication_data = [];
                error_log("[FacultyOutput] WARNING - publications_json is not valid JSON, using empty array");
            } else {
                error_log("[FacultyOutput] CRITICAL - Read publications from JSON field: " . count($publication_data) . " items");
            }
        } else {
            // Fallback: Try to collect from individual POST fields (for backward compatibility)
            error_log("[FacultyOutput] WARNING - publications_json not found, falling back to individual POST fields");
            $publication_data = [];
            $max_detected_index = 0;
            // CRITICAL: Scan up to 200 fields to support 25+ publications (removed 50 limit)
            // Scan all POST keys to find the highest index
            foreach ($_POST as $key => $value) {
                if (preg_match('/^pub_(title|type|venue|indexed|authors|date|url)_(\d+)$/', $key, $matches)) {
                    $index = (int)$matches[2];
                    $max_detected_index = max($max_detected_index, $index);
                }
            }
            $user_publications_count = (int)($_POST['publications_count'] ?? 0);
            
            // CRITICAL FIX: If count is 0, skip processing and clear data
            if ($user_publications_count > 0) {
                if ($max_detected_index > $user_publications_count) {
                    $user_publications_count = $max_detected_index;
                }
                // CRITICAL: Use detected index or user count, no hard limit (supports 25+ publications)
                $max_scan = max($user_publications_count, $max_detected_index, 1);
                // CRITICAL: Cap at reasonable limit (200) to prevent infinite loops, but allow 25+
                $max_scan = min($max_scan, 200);
                // CRITICAL: Process ALL entries up to user_publications_count to ensure empty fields are saved
                // This allows users to fill in data later (e.g., month/year) without losing the entry
                for ($i = 1; $i <= $max_scan; $i++) {
                    // CRITICAL: Always process if within user_publications_count OR if any field exists in POST
                    // This ensures entries with empty fields are still saved when count is set
                    $should_process = ($i <= $user_publications_count) || 
                                      isset($_POST["pub_title_$i"]) || 
                                      isset($_POST["pub_type_$i"]) || 
                                      isset($_POST["pub_venue_$i"]);
                    
                    if ($should_process) {
                        $title = isset($_POST["pub_title_$i"]) ? trim($_POST["pub_title_$i"]) : '';
                        $type = isset($_POST["pub_type_$i"]) ? trim($_POST["pub_type_$i"]) : '';
                        $venue = isset($_POST["pub_venue_$i"]) ? trim($_POST["pub_venue_$i"]) : '';
                        $indexed = isset($_POST["pub_indexed_$i"]) ? trim($_POST["pub_indexed_$i"]) : '';
                        $authors = isset($_POST["pub_authors_$i"]) ? trim($_POST["pub_authors_$i"]) : '';
                        $date = isset($_POST["pub_date_$i"]) ? trim($_POST["pub_date_$i"]) : '';
                        $url = isset($_POST["pub_url_$i"]) ? trim($_POST["pub_url_$i"]) : '';
                        
                        // CRITICAL: Save entry if within count OR if has any data (allows partial data entry)
                        // This ensures users can fill in month/year later without losing the entry
                        if ($i <= $user_publications_count || !empty($title) || !empty($type) || !empty($venue)) {
                            $publication_data[] = [
                                'title' => $title,
                                'type' => $type,
                                'venue' => $venue,
                                'indexed' => $indexed,
                                'authors' => $authors,
                                'date' => $date,  // Can be empty - user can fill later
                                'url' => $url
                            ];
                        }
                    }
                }
            }
        }
        
        // Encode and set count
        if (empty($publication_data)) {
            $publications = '[]';
            $publications_count = 0;
        } else {
            $publications = encodeFacultyJson($publication_data);
            $publications_count = count($publication_data);
        }
        
        error_log("[FacultyOutput] CRITICAL - Publications collected: count=$publications_count, JSON length=" . strlen($publications));
        if ($publications_count > 0) {
            error_log("[FacultyOutput] CRITICAL - First publication title: " . ($publication_data[0]['title'] ?? 'N/A'));
        }
        
        // ============================================================================
        // SECTION 9: BIBLIOMETRICS & H-INDEX - READ FROM JSON FIELD
        // ============================================================================
        error_log("[FacultyOutput] CRITICAL - Starting bibliometrics processing");
        
        // CRITICAL: Read from JSON field first (preferred method)
        if (!empty($_POST['bibliometrics_json'])) {
            $bibliometric_data = json_decode($_POST['bibliometrics_json'], true);
            if (!is_array($bibliometric_data)) {
                $bibliometric_data = [];
                error_log("[FacultyOutput] WARNING - bibliometrics_json is not valid JSON, using empty array");
            } else {
                error_log("[FacultyOutput] CRITICAL - Read bibliometrics from JSON field: " . count($bibliometric_data) . " items");
            }
        } else {
            // Fallback: Try to collect from individual POST fields (for backward compatibility)
            error_log("[FacultyOutput] WARNING - bibliometrics_json not found, falling back to individual POST fields");
            $bibliometric_data = [];
            $max_detected_bib_index = 0;
            for ($i = 1; $i <= 50; $i++) {
                if (isset($_POST["bib_teacher_name_$i"]) || isset($_POST["bib_impact_factor_$i"])) {
                    $max_detected_bib_index = max($max_detected_bib_index, $i);
                }
            }
            $user_bibliometrics_count = (int)($_POST['bibliometrics_count'] ?? 0);
            
            // CRITICAL FIX: If count is 0, skip processing and clear data
            if ($user_bibliometrics_count > 0) {
                if ($max_detected_bib_index > $user_bibliometrics_count) {
                    $user_bibliometrics_count = $max_detected_bib_index;
                }
                $max_scan = max($user_bibliometrics_count, 50);
                for ($i = 1; $i <= $max_scan; $i++) {
                    if (isset($_POST["bib_teacher_name_$i"]) || isset($_POST["bib_impact_factor_$i"])) {
                        $name = isset($_POST["bib_teacher_name_$i"]) ? trim($_POST["bib_teacher_name_$i"]) : '';
                        $impact_factor = isset($_POST["bib_impact_factor_$i"]) ? trim($_POST["bib_impact_factor_$i"]) : '';
                        $citations = isset($_POST["bib_citations_$i"]) ? trim($_POST["bib_citations_$i"]) : '';
                        $h_index = isset($_POST["bib_h_index_$i"]) ? trim($_POST["bib_h_index_$i"]) : '';
                        
                        if (!empty($name) || !empty($impact_factor)) {
                            $bibliometric_data[] = [
                                'name' => $name,
                                'impact_factor' => $impact_factor,
                                'citations' => $citations,
                                'h_index' => $h_index
                            ];
                        }
                    }
                }
            }
        }
        
        // Encode and set count
        if (empty($bibliometric_data)) {
            $bibliometrics = '[]';
            $bibliometrics_count = 0;
        } else {
            $bibliometrics = encodeFacultyJson($bibliometric_data);
            $bibliometrics_count = count($bibliometric_data);
        }
        
        error_log("[FacultyOutput] CRITICAL - Bibliometrics collected: count=$bibliometrics_count, JSON length=" . strlen($bibliometrics));
        if ($bibliometrics_count > 0) {
            error_log("[FacultyOutput] CRITICAL - First bibliometric name: " . ($bibliometric_data[0]['name'] ?? 'N/A'));
        }
        
        // ============================================================================
        // SECTION 10: BOOKS, CHAPTERS, AND MOOCs - READ FROM JSON FIELD
        // ============================================================================
        error_log("[FacultyOutput] CRITICAL - Starting books processing");
        
        // CRITICAL: Read from JSON field first (preferred method)
        if (!empty($_POST['books_json'])) {
            $book_data = json_decode($_POST['books_json'], true);
            if (!is_array($book_data)) {
                $book_data = [];
                error_log("[FacultyOutput] WARNING - books_json is not valid JSON, using empty array");
            } else {
                error_log("[FacultyOutput] CRITICAL - Read books from JSON field: " . count($book_data) . " items");
            }
        } else {
            // Fallback: Try to collect from individual POST fields (for backward compatibility)
            error_log("[FacultyOutput] WARNING - books_json not found, falling back to individual POST fields");
            $book_data = [];
            $max_detected_book_index = 0;
            for ($i = 1; $i <= 50; $i++) {
                if (isset($_POST["book_title_$i"]) || isset($_POST["book_type_$i"])) {
                    $max_detected_book_index = max($max_detected_book_index, $i);
                }
            }
            $user_books_count = (int)($_POST['books_count'] ?? 0);
            
            // CRITICAL FIX: If count is 0, skip processing and clear data
            if ($user_books_count > 0) {
                if ($max_detected_book_index > $user_books_count) {
                    $user_books_count = $max_detected_book_index;
                }
                $max_scan = max($user_books_count, 50);
                for ($i = 1; $i <= $max_scan; $i++) {
                    if (isset($_POST["book_title_$i"]) || isset($_POST["book_type_$i"])) {
                        $title = isset($_POST["book_title_$i"]) ? trim($_POST["book_title_$i"]) : '';
                        $type = isset($_POST["book_type_$i"]) ? trim($_POST["book_type_$i"]) : '';
                        $authors = isset($_POST["book_authors_$i"]) ? trim($_POST["book_authors_$i"]) : '';
                        $month = isset($_POST["book_month_$i"]) ? trim($_POST["book_month_$i"]) : '';
                        $year = isset($_POST["book_year_$i"]) ? trim($_POST["book_year_$i"]) : '';
                        $publisher = isset($_POST["book_publisher_$i"]) ? trim($_POST["book_publisher_$i"]) : '';
                        
                        if (!empty($title) || !empty($type)) {
                            $book_data[] = [
                                'title' => $title,
                                'type' => $type,
                                'authors' => $authors,
                                'month' => $month,
                                'year' => $year,
                                'publisher' => $publisher
                            ];
                        }
                    }
                }
            }
        }
        
        // Encode and set count
        if (empty($book_data)) {
            $books = '[]';
            $books_count = 0;
        } else {
            $books = encodeFacultyJson($book_data);
            $books_count = count($book_data);
        }
        
        error_log("[FacultyOutput] CRITICAL - Books collected: count=$books_count, JSON length=" . strlen($books));
        if ($books_count > 0) {
            error_log("[FacultyOutput] CRITICAL - First book title: " . ($book_data[0]['title'] ?? 'N/A'));
        }
        
        // Process detailed patent data (changed from array notation to numbered fields)
        // Use the calculated patents_count from line 921, not from POST
        // $patents_count is already calculated above as: $patents_count = $total_published + $total_granted;
        $patent_data = [];
        // Use calculated count, but also check POST as fallback
        $patents_count_from_post = (int)($_POST['patents_count'] ?? 0);
        // Use the calculated value from line 921 (total_published + total_granted), but ensure it matches POST if available
        if ($patents_count_from_post > 0 && $patents_count_from_post != $patents_count) {
            $patents_count = $patents_count_from_post; // Use POST value if it differs (might be manually set)
        }
        if ($patents_count > 0) {
            for ($i = 1; $i <= $patents_count; $i++) {
                $app_number = isset($_POST["patent_app_number_$i"]) ? trim($_POST["patent_app_number_$i"]) : '';
                $status = isset($_POST["patent_status_$i"]) ? trim($_POST["patent_status_$i"]) : '';
                $inventors = isset($_POST["patent_inventors_$i"]) ? trim($_POST["patent_inventors_$i"]) : '';
                $title = isset($_POST["patent_title_$i"]) ? trim($_POST["patent_title_$i"]) : '';
                $applicants = isset($_POST["patent_applicants_$i"]) ? trim($_POST["patent_applicants_$i"]) : '';
                $url = isset($_POST["patent_url_$i"]) ? trim($_POST["patent_url_$i"]) : '';
                // Save if any key field has data (not just app_number)
                if (!empty($app_number) || !empty($status) || !empty($inventors) || !empty($title) || !empty($applicants) || !empty($url)) {
                    $patent_data[] = [
                        'app_number' => $app_number,
                        'status' => $status,
                        'inventors' => $inventors,
                        'title' => $title,
                        'applicants' => $applicants,
                        'filed_date' => isset($_POST["patent_filed_date_$i"]) ? trim($_POST["patent_filed_date_$i"]) : '',
                        'published_date' => isset($_POST["patent_published_date_$i"]) ? trim($_POST["patent_published_date_$i"]) : '',
                        'patent_number' => isset($_POST["patent_number_$i"]) ? trim($_POST["patent_number_$i"]) : '',
                        'assignees' => isset($_POST["patent_assignees_$i"]) ? trim($_POST["patent_assignees_$i"]) : '',
                        'url' => $url
                    ];
                }
            }
        }
        $patent_details_json = encodeFacultyJson($patent_data);

        // ============================================================================
        // INNOVATION & STARTUP ECOSYSTEM DATA PROCESSING
        // ============================================================================
        
        // Summary counts for innovation data
        $total_dpiit_startups = (int) ($_POST['total_dpiit_startups'] ?? 0);
        $total_vc_investments = (int) ($_POST['total_vc_investments'] ?? 0);
        $total_seed_funding = (int) ($_POST['total_seed_funding'] ?? 0);
        $total_fdi_investments = (int) ($_POST['total_fdi_investments'] ?? 0);
        $total_innovation_grants = (int) ($_POST['total_innovation_grants'] ?? 0);
        $total_trl_innovations = (int) ($_POST['total_trl_innovations'] ?? 0);
        $total_turnover_achievements = (int) ($_POST['total_turnover_achievements'] ?? 0);
        $total_forbes_alumni = (int) ($_POST['total_forbes_alumni'] ?? 0);

        // Process DPIIT Startup Recognition data (read from numbered fields OR array notation - support both)
        // IMPORTANT: If count > 0, save entries even if empty (with defaults: 0 for numbers, "-" for text)
        $dpiit_data = [];
        $dpiit_count = (int)($_POST['total_dpiit_startups'] ?? 0);
        // Try numbered fields first (new format)
        for ($i = 1; $i <= $dpiit_count; $i++) {
            $startup_name = isset($_POST["dpiit_startup_name_$i"]) ? trim($_POST["dpiit_startup_name_$i"]) : '';
            // Save even if empty when count > 0 (use defaults)
            if ($dpiit_count > 0) {
                $dpiit_data[] = [
                    'startup_name' => !empty($startup_name) ? $startup_name : '-',
                    'dpiit_registration_no' => isset($_POST["dpiit_registration_no_$i"]) && trim($_POST["dpiit_registration_no_$i"]) !== '' ? trim($_POST["dpiit_registration_no_$i"]) : '-',
                    'year_of_recognition' => isset($_POST["dpiit_year_recognition_$i"]) && trim($_POST["dpiit_year_recognition_$i"]) !== '' ? trim($_POST["dpiit_year_recognition_$i"]) : '0'
                ];
            } elseif (!empty($startup_name)) {
                // Only save if startup_name is not empty when count is 0 (backward compatibility)
                $dpiit_data[] = [
                    'startup_name' => $startup_name,
                    'dpiit_registration_no' => isset($_POST["dpiit_registration_no_$i"]) ? trim($_POST["dpiit_registration_no_$i"]) : '',
                    'year_of_recognition' => isset($_POST["dpiit_year_recognition_$i"]) ? trim($_POST["dpiit_year_recognition_$i"]) : ''
                ];
            }
        }
        // Fallback: try array notation if numbered fields didn't work
        if (empty($dpiit_data) && isset($_POST['dpiit_startup_name']) && is_array($_POST['dpiit_startup_name'])) {
            foreach ($_POST['dpiit_startup_name'] as $index => $startup_name) {
                if (!empty($startup_name)) {
                    $dpiit_data[] = [
                        'startup_name' => $startup_name,
                        'dpiit_registration_no' => $_POST['dpiit_registration_no'][$index] ?? '',
                        'year_of_recognition' => $_POST['dpiit_year_recognition'][$index] ?? ''
                    ];
                }
            }
        }
        // Update count based on actual data
        if (!empty($dpiit_data)) {
            $total_dpiit_startups = count($dpiit_data);
        } else {
            $total_dpiit_startups = 0;
        }
        
        // Process VC Investment data (read from numbered fields OR array notation)
        // IMPORTANT: If count > 0, save entries even if empty (with defaults: 0 for numbers, "-" for text)
        $vc_data = [];
        $vc_count = (int)($_POST['total_vc_investments'] ?? 0);
        for ($i = 1; $i <= $vc_count; $i++) {
            $startup_name = isset($_POST["vc_startup_name_$i"]) ? trim($_POST["vc_startup_name_$i"]) : '';
            // Save even if empty when count > 0 (use defaults)
            if ($vc_count > 0) {
                $vc_data[] = [
                    'startup_name' => !empty($startup_name) ? $startup_name : '-',
                    'dpiit_no' => isset($_POST["vc_dpiit_no_$i"]) && trim($_POST["vc_dpiit_no_$i"]) !== '' ? trim($_POST["vc_dpiit_no_$i"]) : '-',
                    'received_amount_rs' => isset($_POST["vc_amount_$i"]) && trim($_POST["vc_amount_$i"]) !== '' ? (float)($_POST["vc_amount_$i"]) : 0,
                    'organization' => isset($_POST["vc_organization_$i"]) && trim($_POST["vc_organization_$i"]) !== '' ? trim($_POST["vc_organization_$i"]) : '-',
                    'year_of_receiving' => isset($_POST["vc_year_$i"]) && trim($_POST["vc_year_$i"]) !== '' ? trim($_POST["vc_year_$i"]) : '0',
                    'achievement_level' => isset($_POST["vc_achievement_$i"]) && trim($_POST["vc_achievement_$i"]) !== '' ? trim($_POST["vc_achievement_$i"]) : '-',
                    'type_of_investment' => isset($_POST["vc_type_$i"]) && trim($_POST["vc_type_$i"]) !== '' ? trim($_POST["vc_type_$i"]) : '-'
                ];
            } elseif (!empty($startup_name)) {
                // Only save if startup_name is not empty when count is 0 (backward compatibility)
                $vc_data[] = [
                    'startup_name' => $startup_name,
                    'dpiit_no' => isset($_POST["vc_dpiit_no_$i"]) ? trim($_POST["vc_dpiit_no_$i"]) : '',
                    'received_amount_rs' => isset($_POST["vc_amount_$i"]) ? (float)($_POST["vc_amount_$i"]) : 0,
                    'organization' => isset($_POST["vc_organization_$i"]) ? trim($_POST["vc_organization_$i"]) : '',
                    'year_of_receiving' => isset($_POST["vc_year_$i"]) ? trim($_POST["vc_year_$i"]) : '',
                    'achievement_level' => isset($_POST["vc_achievement_$i"]) ? trim($_POST["vc_achievement_$i"]) : '',
                    'type_of_investment' => isset($_POST["vc_type_$i"]) ? trim($_POST["vc_type_$i"]) : ''
                ];
            }
        }
        // Fallback: array notation
        if (empty($vc_data) && isset($_POST['vc_startup_name']) && is_array($_POST['vc_startup_name'])) {
            foreach ($_POST['vc_startup_name'] as $index => $startup_name) {
                if (!empty($startup_name)) {
                    $vc_data[] = [
                        'startup_name' => $startup_name,
                        'dpiit_no' => $_POST['vc_dpiit_no'][$index] ?? '',
                        'received_amount_rs' => $_POST['vc_amount'][$index] ?? 0,
                        'organization' => $_POST['vc_organization'][$index] ?? '',
                        'year_of_receiving' => $_POST['vc_year'][$index] ?? '',
                        'achievement_level' => $_POST['vc_achievement'][$index] ?? '',
                        'type_of_investment' => $_POST['vc_type'][$index] ?? ''
                    ];
                }
            }
        }
        if (!empty($vc_data)) {
            $total_vc_investments = count($vc_data);
        } else {
            $total_vc_investments = 0;
        }

        // Process Seed Funding data (read from numbered fields OR array notation)
        // IMPORTANT: If count > 0, save entries even if empty (with defaults: 0 for numbers, "-" for text)
        $seed_data = [];
        $seed_count = (int)($_POST['total_seed_funding'] ?? 0);
        for ($i = 1; $i <= $seed_count; $i++) {
            $startup_name = isset($_POST["seed_startup_name_$i"]) ? trim($_POST["seed_startup_name_$i"]) : '';
            // Save even if empty when count > 0 (use defaults)
            if ($seed_count > 0) {
                $seed_data[] = [
                    'startup_name' => !empty($startup_name) ? $startup_name : '-',
                    'dpiit_no' => isset($_POST["seed_dpiit_no_$i"]) && trim($_POST["seed_dpiit_no_$i"]) !== '' ? trim($_POST["seed_dpiit_no_$i"]) : '-',
                    'seed_funding_received' => isset($_POST["seed_amount_$i"]) && trim($_POST["seed_amount_$i"]) !== '' ? (float)($_POST["seed_amount_$i"]) : 0,
                    'government_organization' => isset($_POST["seed_organization_$i"]) && trim($_POST["seed_organization_$i"]) !== '' ? trim($_POST["seed_organization_$i"]) : '-',
                    'year_of_receiving' => isset($_POST["seed_year_$i"]) && trim($_POST["seed_year_$i"]) !== '' ? trim($_POST["seed_year_$i"]) : '0',
                    'achievement_level' => isset($_POST["seed_achievement_$i"]) && trim($_POST["seed_achievement_$i"]) !== '' ? trim($_POST["seed_achievement_$i"]) : '-',
                    'type_of_investment' => isset($_POST["seed_type_$i"]) && trim($_POST["seed_type_$i"]) !== '' ? trim($_POST["seed_type_$i"]) : '-'
                ];
            } elseif (!empty($startup_name)) {
                // Only save if startup_name is not empty when count is 0 (backward compatibility)
                $seed_data[] = [
                    'startup_name' => $startup_name,
                    'dpiit_no' => isset($_POST["seed_dpiit_no_$i"]) ? trim($_POST["seed_dpiit_no_$i"]) : '',
                    'seed_funding_received' => isset($_POST["seed_amount_$i"]) ? (float)($_POST["seed_amount_$i"]) : 0,
                    'government_organization' => isset($_POST["seed_organization_$i"]) ? trim($_POST["seed_organization_$i"]) : '',
                    'year_of_receiving' => isset($_POST["seed_year_$i"]) ? trim($_POST["seed_year_$i"]) : '',
                    'achievement_level' => isset($_POST["seed_achievement_$i"]) ? trim($_POST["seed_achievement_$i"]) : '',
                    'type_of_investment' => isset($_POST["seed_type_$i"]) ? trim($_POST["seed_type_$i"]) : ''
                ];
            }
        }
        // Fallback: array notation
        if (empty($seed_data) && isset($_POST['seed_startup_name']) && is_array($_POST['seed_startup_name'])) {
            foreach ($_POST['seed_startup_name'] as $index => $startup_name) {
                if (!empty($startup_name)) {
                    $seed_data[] = [
                        'startup_name' => $startup_name,
                        'dpiit_no' => $_POST['seed_dpiit_no'][$index] ?? '',
                        'seed_funding_received' => $_POST['seed_amount'][$index] ?? 0,
                        'government_organization' => $_POST['seed_organization'][$index] ?? '',
                        'year_of_receiving' => $_POST['seed_year'][$index] ?? '',
                        'achievement_level' => $_POST['seed_achievement'][$index] ?? '',
                        'type_of_investment' => $_POST['seed_type'][$index] ?? ''
                    ];
                }
            }
        }
        if (!empty($seed_data)) {
            $total_seed_funding = count($seed_data);
        } else {
            $total_seed_funding = 0;
        }

        // Process Innovation Grants data (read from numbered fields OR array notation)
        // IMPORTANT: If count > 0, save entries even if empty (with defaults: 0 for numbers, "-" for text)
        $grant_data = [];
        $grant_count = (int)($_POST['total_innovation_grants'] ?? 0);
        for ($i = 1; $i <= $grant_count; $i++) {
            $organization = isset($_POST["grant_organization_$i"]) ? trim($_POST["grant_organization_$i"]) : '';
            // Save even if empty when count > 0 (use defaults)
            if ($grant_count > 0) {
                $grant_data[] = [
                    'government_organization' => !empty($organization) ? $organization : '-',
                    'program_scheme_name' => isset($_POST["grant_program_$i"]) && trim($_POST["grant_program_$i"]) !== '' ? trim($_POST["grant_program_$i"]) : '-',
                    'grant_amount' => isset($_POST["grant_amount_$i"]) && trim($_POST["grant_amount_$i"]) !== '' ? (float)($_POST["grant_amount_$i"]) : 0,
                    'year_of_receiving' => isset($_POST["grant_year_$i"]) && trim($_POST["grant_year_$i"]) !== '' ? trim($_POST["grant_year_$i"]) : '0'
                ];
            } elseif (!empty($organization)) {
                // Only save if organization is not empty when count is 0 (backward compatibility)
                $grant_data[] = [
                    'government_organization' => $organization,
                    'program_scheme_name' => isset($_POST["grant_program_$i"]) ? trim($_POST["grant_program_$i"]) : '',
                    'grant_amount' => isset($_POST["grant_amount_$i"]) ? (float)($_POST["grant_amount_$i"]) : 0,
                    'year_of_receiving' => isset($_POST["grant_year_$i"]) ? trim($_POST["grant_year_$i"]) : ''
                ];
            }
        }
        // Fallback: array notation
        if (empty($grant_data) && isset($_POST['grant_organization']) && is_array($_POST['grant_organization'])) {
            foreach ($_POST['grant_organization'] as $index => $organization) {
                if (!empty($organization)) {
                    $grant_data[] = [
                        'government_organization' => $organization,
                        'program_scheme_name' => $_POST['grant_program'][$index] ?? '',
                        'grant_amount' => $_POST['grant_amount'][$index] ?? 0,
                        'year_of_receiving' => $_POST['grant_year'][$index] ?? ''
                    ];
                }
            }
        }
        if (!empty($grant_data)) {
            $total_innovation_grants = count($grant_data);
        } else {
            $total_innovation_grants = 0;
        }

        // Process FDI Investment data (read from numbered fields OR array notation)
        // IMPORTANT: If count > 0, save entries even if empty (with defaults: 0 for numbers, "-" for text)
        $fdi_data = [];
        $fdi_count = (int)($_POST['total_fdi_investments'] ?? 0);
        for ($i = 1; $i <= $fdi_count; $i++) {
            $startup_name = isset($_POST["fdi_startup_name_$i"]) ? trim($_POST["fdi_startup_name_$i"]) : '';
            // Save even if empty when count > 0 (use defaults)
            if ($fdi_count > 0) {
                $fdi_data[] = [
                    'startup_name' => !empty($startup_name) ? $startup_name : '-',
                    'dpiit_no' => isset($_POST["fdi_dpiit_no_$i"]) && trim($_POST["fdi_dpiit_no_$i"]) !== '' ? trim($_POST["fdi_dpiit_no_$i"]) : '-',
                    'fdi_investment_received' => isset($_POST["fdi_amount_$i"]) && trim($_POST["fdi_amount_$i"]) !== '' ? (float)($_POST["fdi_amount_$i"]) : 0,
                    'organization' => isset($_POST["fdi_organization_$i"]) && trim($_POST["fdi_organization_$i"]) !== '' ? trim($_POST["fdi_organization_$i"]) : '-',
                    'year_of_receiving' => isset($_POST["fdi_year_$i"]) && trim($_POST["fdi_year_$i"]) !== '' ? trim($_POST["fdi_year_$i"]) : '0',
                    'achievement_level' => isset($_POST["fdi_achievement_$i"]) && trim($_POST["fdi_achievement_$i"]) !== '' ? trim($_POST["fdi_achievement_$i"]) : '-',
                    'type_of_investment' => isset($_POST["fdi_type_$i"]) && trim($_POST["fdi_type_$i"]) !== '' ? trim($_POST["fdi_type_$i"]) : '-'
                ];
            } elseif (!empty($startup_name)) {
                // Only save if startup_name is not empty when count is 0 (backward compatibility)
                $fdi_data[] = [
                    'startup_name' => $startup_name,
                    'dpiit_no' => isset($_POST["fdi_dpiit_no_$i"]) ? trim($_POST["fdi_dpiit_no_$i"]) : '',
                    'fdi_investment_received' => isset($_POST["fdi_amount_$i"]) ? (float)($_POST["fdi_amount_$i"]) : 0,
                    'organization' => isset($_POST["fdi_organization_$i"]) ? trim($_POST["fdi_organization_$i"]) : '',
                    'year_of_receiving' => isset($_POST["fdi_year_$i"]) ? trim($_POST["fdi_year_$i"]) : '',
                    'achievement_level' => isset($_POST["fdi_achievement_$i"]) ? trim($_POST["fdi_achievement_$i"]) : '',
                    'type_of_investment' => isset($_POST["fdi_type_$i"]) ? trim($_POST["fdi_type_$i"]) : ''
                ];
            }
        }
        // Fallback: array notation
        if (empty($fdi_data) && isset($_POST['fdi_startup_name']) && is_array($_POST['fdi_startup_name'])) {
            foreach ($_POST['fdi_startup_name'] as $index => $startup_name) {
                if (!empty($startup_name)) {
                    $fdi_data[] = [
                        'startup_name' => $startup_name,
                        'dpiit_no' => $_POST['fdi_dpiit_no'][$index] ?? '',
                        'fdi_investment_received' => $_POST['fdi_amount'][$index] ?? 0,
                        'organization' => $_POST['fdi_organization'][$index] ?? '',
                        'year_of_receiving' => $_POST['fdi_year'][$index] ?? '',
                        'achievement_level' => $_POST['fdi_achievement'][$index] ?? '',
                        'type_of_investment' => $_POST['fdi_type'][$index] ?? ''
                    ];
                }
            }
        }
        if (!empty($fdi_data)) {
            $total_fdi_investments = count($fdi_data);
        } else {
            $total_fdi_investments = 0;
        }

        // Process TRL Innovations data (read from numbered fields OR array notation)
        // IMPORTANT: If count > 0, save entries even if empty (with defaults: 0 for numbers, "-" for text)
        $trl_data = [];
        $trl_count = (int)($_POST['total_trl_innovations'] ?? 0);
        for ($i = 1; $i <= $trl_count; $i++) {
            $startup_name = isset($_POST["trl_startup_name_$i"]) ? trim($_POST["trl_startup_name_$i"]) : '';
            // Save even if empty when count > 0 (use defaults)
            if ($trl_count > 0) {
                $trl_data[] = [
                    'startup_name' => !empty($startup_name) ? $startup_name : '-',
                    'dpiit_no' => isset($_POST["trl_dpiit_no_$i"]) && trim($_POST["trl_dpiit_no_$i"]) !== '' ? trim($_POST["trl_dpiit_no_$i"]) : '-',
                    'innovation_name' => isset($_POST["trl_innovation_name_$i"]) && trim($_POST["trl_innovation_name_$i"]) !== '' ? trim($_POST["trl_innovation_name_$i"]) : '-',
                    'stage_level' => isset($_POST["trl_stage_$i"]) && trim($_POST["trl_stage_$i"]) !== '' ? trim($_POST["trl_stage_$i"]) : '-',
                    'achievement_level' => isset($_POST["trl_achievement_$i"]) && trim($_POST["trl_achievement_$i"]) !== '' ? trim($_POST["trl_achievement_$i"]) : '-'
                ];
            } elseif (!empty($startup_name)) {
                // Only save if startup_name is not empty when count is 0 (backward compatibility)
                $trl_data[] = [
                    'startup_name' => $startup_name,
                    'dpiit_no' => isset($_POST["trl_dpiit_no_$i"]) ? trim($_POST["trl_dpiit_no_$i"]) : '',
                    'innovation_name' => isset($_POST["trl_innovation_name_$i"]) ? trim($_POST["trl_innovation_name_$i"]) : '',
                    'stage_level' => isset($_POST["trl_stage_$i"]) ? trim($_POST["trl_stage_$i"]) : '',
                    'achievement_level' => isset($_POST["trl_achievement_$i"]) ? trim($_POST["trl_achievement_$i"]) : ''
                ];
            }
        }
        // Fallback: array notation
        if (empty($trl_data) && isset($_POST['trl_startup_name']) && is_array($_POST['trl_startup_name'])) {
            foreach ($_POST['trl_startup_name'] as $index => $startup_name) {
                if (!empty($startup_name)) {
                    $trl_data[] = [
                        'startup_name' => $startup_name,
                        'dpiit_no' => $_POST['trl_dpiit_no'][$index] ?? '',
                        'innovation_name' => $_POST['trl_innovation_name'][$index] ?? '',
                        'stage_level' => $_POST['trl_stage'][$index] ?? '',
                        'achievement_level' => $_POST['trl_achievement'][$index] ?? ''
                    ];
                }
            }
        }
        if (!empty($trl_data)) {
            $total_trl_innovations = count($trl_data);
        } else {
            $total_trl_innovations = 0;
        }

        // Process Turnover Achievement data (read from numbered fields OR array notation)
        // IMPORTANT: If count > 0, save entries even if empty (with defaults: 0 for numbers, "-" for text)
        $turnover_data = [];
        $turnover_count = (int)($_POST['total_turnover_achievements'] ?? 0);
        for ($i = 1; $i <= $turnover_count; $i++) {
            $startup_name = isset($_POST["turnover_startup_name_$i"]) ? trim($_POST["turnover_startup_name_$i"]) : '';
            // Save even if empty when count > 0 (use defaults)
            if ($turnover_count > 0) {
                $turnover_data[] = [
                    'startup_name' => !empty($startup_name) ? $startup_name : '-',
                    'dpiit_no' => isset($_POST["turnover_dpiit_no_$i"]) && trim($_POST["turnover_dpiit_no_$i"]) !== '' ? trim($_POST["turnover_dpiit_no_$i"]) : '-',
                    'company_turnover' => isset($_POST["turnover_amount_$i"]) && trim($_POST["turnover_amount_$i"]) !== '' ? trim($_POST["turnover_amount_$i"]) : '-'
                ];
            } elseif (!empty($startup_name)) {
                // Only save if startup_name is not empty when count is 0 (backward compatibility)
                $turnover_data[] = [
                    'startup_name' => $startup_name,
                    'dpiit_no' => isset($_POST["turnover_dpiit_no_$i"]) ? trim($_POST["turnover_dpiit_no_$i"]) : '',
                    'company_turnover' => isset($_POST["turnover_amount_$i"]) ? trim($_POST["turnover_amount_$i"]) : ''
                ];
            }
        }
        // Fallback: array notation
        if (empty($turnover_data) && isset($_POST['turnover_startup_name']) && is_array($_POST['turnover_startup_name'])) {
            foreach ($_POST['turnover_startup_name'] as $index => $startup_name) {
                if (!empty($startup_name)) {
                    $turnover_data[] = [
                        'startup_name' => $startup_name,
                        'dpiit_no' => $_POST['turnover_dpiit_no'][$index] ?? '',
                        'company_turnover' => $_POST['turnover_amount'][$index] ?? ''
                    ];
                }
            }
        }
        if (!empty($turnover_data)) {
            $total_turnover_achievements = count($turnover_data);
        } else {
            $total_turnover_achievements = 0;
        }

        // Process Forbes Alumni data (read from numbered fields OR array notation)
        // IMPORTANT: If count > 0, save entries even if empty (with defaults: 0 for numbers, "-" for text)
        $forbes_data = [];
        $forbes_count = (int)($_POST['total_forbes_alumni'] ?? 0);
        for ($i = 1; $i <= $forbes_count; $i++) {
            $program = isset($_POST["forbes_program_$i"]) ? trim($_POST["forbes_program_$i"]) : '';
            // Save even if empty when count > 0 (use defaults)
            if ($forbes_count > 0) {
                $forbes_data[] = [
                    'program_name' => !empty($program) ? $program : '-',
                    'year_of_passing' => isset($_POST["forbes_year_passing_$i"]) && trim($_POST["forbes_year_passing_$i"]) !== '' ? trim($_POST["forbes_year_passing_$i"]) : '0',
                    'founder_company_name' => isset($_POST["forbes_company_$i"]) && trim($_POST["forbes_company_$i"]) !== '' ? trim($_POST["forbes_company_$i"]) : '-',
                    'year_founded' => isset($_POST["forbes_year_founded_$i"]) && trim($_POST["forbes_year_founded_$i"]) !== '' ? trim($_POST["forbes_year_founded_$i"]) : '0'
                ];
            } elseif (!empty($program)) {
                // Only save if program is not empty when count is 0 (backward compatibility)
                $forbes_data[] = [
                    'program_name' => $program,
                    'year_of_passing' => isset($_POST["forbes_year_passing_$i"]) ? trim($_POST["forbes_year_passing_$i"]) : '',
                    'founder_company_name' => isset($_POST["forbes_company_$i"]) ? trim($_POST["forbes_company_$i"]) : '',
                    'year_founded' => isset($_POST["forbes_year_founded_$i"]) ? trim($_POST["forbes_year_founded_$i"]) : ''
                ];
            }
        }
        // Fallback: array notation
        if (empty($forbes_data) && isset($_POST['forbes_program']) && is_array($_POST['forbes_program'])) {
            foreach ($_POST['forbes_program'] as $index => $program) {
                if (!empty($program)) {
                    $forbes_data[] = [
                        'program_name' => $program,
                        'year_of_passing' => $_POST['forbes_year_passing'][$index] ?? '',
                        'founder_company_name' => $_POST['forbes_company'][$index] ?? '',
                        'year_founded' => $_POST['forbes_year_founded'][$index] ?? ''
                    ];
                }
            }
        }
        if (!empty($forbes_data)) {
            $total_forbes_alumni = count($forbes_data);
        } else {
            $total_forbes_alumni = 0;
        }
        
        // Handle client data
        $book_months = encodeFacultyJson($_POST['book_months'] ?? []);
        $book_years = encodeFacultyJson($_POST['book_years'] ?? []);
        $recognitions = encodeFacultyJson($_POST['recognitions'] ?? []);


        // CRITICAL OPTIMIZATION: Check patents_amount column using optimized approach
        // Fetch all columns once (optimized - single query) instead of individual query
        // This prevents database crashes with concurrent users
        $has_patents_amount = false;
        // Fetch all columns once (optimized - single query)
        $column_check = "SHOW COLUMNS FROM faculty_output";
        $column_result = mysqli_query($conn, $column_check);
        if ($column_result) {
            $temp_columns = [];
            while ($col_row = mysqli_fetch_assoc($column_result)) {
                $temp_columns[] = $col_row['Field'];
            }
            mysqli_free_result($column_result); // CRITICAL: Free result set
            $has_patents_amount = in_array('patents_amount', $temp_columns, true);
        }
        
        // ============================================================================
        // ENCODE ALL JSON DATA HERE (before database queries)
        // ============================================================================
        
        // Encode JSON data for fields that need it
        $research_staff_json = encodeFacultyJson($research_staff_data);
        $patent_details_json = encodeFacultyJson($patent_data);
        $dpiit_json = encodeFacultyJson($dpiit_data);
        $vc_json = encodeFacultyJson($vc_data);
        $seed_json = encodeFacultyJson($seed_data);
        $fdi_json = encodeFacultyJson($fdi_data);
        $grants_json = encodeFacultyJson($grant_data);
        $trl_json = encodeFacultyJson($trl_data);
        $turnover_json = encodeFacultyJson($turnover_data);
        $forbes_json = encodeFacultyJson($forbes_data);
        
        // ============================================================================
        // END JSON ENCODING
        // ============================================================================
        
        // Check if updating existing data or inserting new - MUST check in POST handler
        // Check for existing record for this department and academic year
        $check_existing_query = "SELECT * FROM faculty_output WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
        $check_stmt = mysqli_prepare($conn, $check_existing_query);
        $existing_data = null;
        if ($check_stmt) {
            mysqli_stmt_bind_param($check_stmt, "is", $dept_id, $academic_year);
            mysqli_stmt_execute($check_stmt);
            $existing_result = mysqli_stmt_get_result($check_stmt);
            if ($existing_result && mysqli_num_rows($existing_result) > 0) {
                $existing_data = mysqli_fetch_assoc($existing_result);
                
                // CRITICAL: On UPDATE, preserve existing JSON data if new data is empty
                // BUT: If POST count is 0, clear the data (user explicitly set count to 0)
                $post_awards_count = (int)($_POST['awards_count'] ?? -1);
                if (!empty($existing_data['awards'])) {
                    $new_awards = json_decode($awards, true);
                    // CRITICAL: If POST count is 0, clear data (user wants to delete all)
                    if ($post_awards_count === 0) {
                        $awards = '[]';
                        $awards_count = 0;
                    } elseif (empty($new_awards) || $awards === '[]' || $awards === null || !is_array($new_awards)) {
                        // If new data is empty or null AND count not explicitly 0, keep existing data
                        $awards = $existing_data['awards'];
                        $awards_count = count(json_decode($existing_data['awards'], true) ?: []);
                    } else {
                        // New data provided, use it (form should send all data including existing)
                        $awards = json_encode($new_awards);
                        $awards_count = count($new_awards);
                    }
                } elseif ($post_awards_count === 0) {
                    // No existing data but count is 0, ensure it's cleared
                    $awards = '[]';
                    $awards_count = 0;
                }
                // Preserve existing projects data if new data is empty
                // BUT: If POST count is 0, clear the data (user explicitly set count to 0)
                $post_projects_count = (int)($_POST['projects_count'] ?? -1);
                if (!empty($existing_data['projects'])) {
                    $new_projects = json_decode($projects, true);
                    // CRITICAL: If POST count is 0, clear data (user wants to delete all)
                    if ($post_projects_count === 0) {
                        $projects = '[]';
                        $projects_count = 0;
                    } elseif (empty($new_projects) || $projects === '[]' || $projects === null || !is_array($new_projects)) {
                        // If new data is empty or null AND count not explicitly 0, keep existing data
                        $projects = $existing_data['projects'];
                        $projects_count = count(json_decode($existing_data['projects'], true) ?: []);
                    } else {
                        $projects = json_encode($new_projects);
                        $projects_count = count($new_projects);
                    }
                } elseif ($post_projects_count === 0) {
                    // No existing data but count is 0, ensure it's cleared
                    $projects = '[]';
                    $projects_count = 0;
                }
                // Preserve existing trainings data if new data is empty
                // BUT: If POST count is 0, clear the data (user explicitly set count to 0)
                $post_training_count = (int)($_POST['training_count'] ?? -1);
                if (!empty($existing_data['trainings'])) {
                    $new_trainings = json_decode($trainings, true);
                    // CRITICAL: If POST count is 0, clear data (user wants to delete all)
                    if ($post_training_count === 0) {
                        $trainings = '[]';
                        $training_count = 0;
                    } elseif (empty($new_trainings) || $trainings === '[]' || $trainings === null || !is_array($new_trainings)) {
                        // If new data is empty or null AND count not explicitly 0, keep existing data
                        $trainings = $existing_data['trainings'];
                        $training_count = count(json_decode($existing_data['trainings'], true) ?: []);
                    } else {
                        $trainings = json_encode($new_trainings);
                        $training_count = count($new_trainings);
                    }
                } elseif ($post_training_count === 0) {
                    // No existing data but count is 0, ensure it's cleared
                    $trainings = '[]';
                    $training_count = 0;
                }
                // ============================================================================
                // CRITICAL: Preserve existing data ONLY if NO data was collected AND no fields were in POST
                // BUT: If POST count is 0, clear the data (user explicitly set count to 0)
                // ============================================================================
                // For publications - ONLY preserve if collected array is empty AND no POST fields
                // CRITICAL: Scan all POST keys to support 25+ publications (removed 50 limit)
                $post_publications_count = (int)($_POST['publications_count'] ?? -1);
                if (!empty($existing_data['publications'])) {
                    $has_post_fields = false;
                    foreach ($_POST as $key => $value) {
                        if (preg_match('/^pub_(title|type|venue|indexed|authors|date|url)_\d+$/', $key)) {
                            $has_post_fields = true;
                            break;
                        }
                    }
                    // CRITICAL: If POST count is 0, clear data (user wants to delete all)
                    if ($post_publications_count === 0) {
                        error_log("[FacultyOutput] CRITICAL - Clearing publications (POST count is 0)");
                        $publications = '[]';
                        $publications_count = 0;
                    } elseif ($publications_count == 0 && !$has_post_fields) {
                        // Only preserve if NO data collected AND no POST fields AND count not explicitly 0
                        error_log("[FacultyOutput] CRITICAL - Preserving existing publications (no new data)");
                        $publications = $existing_data['publications'];
                        $publications_count = count(json_decode($existing_data['publications'], true) ?: []);
                    } else {
                        error_log("[FacultyOutput] CRITICAL - Using newly collected publications (count=$publications_count, has_post_fields=" . ($has_post_fields ? 'yes' : 'no') . ")");
                    }
                } elseif ($post_publications_count === 0) {
                    // No existing data but count is 0, ensure it's cleared
                    $publications = '[]';
                    $publications_count = 0;
                }
                
                // For bibliometrics - ONLY preserve if collected array is empty AND no POST fields
                // BUT: If POST count is 0, clear the data (user explicitly set count to 0)
                $post_bibliometrics_count = (int)($_POST['bibliometrics_count'] ?? -1);
                if (!empty($existing_data['bibliometrics'])) {
                    $has_post_fields = false;
                    for ($i = 1; $i <= 50; $i++) {
                        if (isset($_POST["bib_teacher_name_$i"]) || isset($_POST["bib_impact_factor_$i"]) || 
                            isset($_POST["bib_citations_$i"]) || isset($_POST["bib_h_index_$i"])) {
                            $has_post_fields = true;
                            break;
                        }
                    }
                    // CRITICAL: If POST count is 0, clear data (user wants to delete all)
                    if ($post_bibliometrics_count === 0) {
                        error_log("[FacultyOutput] CRITICAL - Clearing bibliometrics (POST count is 0)");
                        $bibliometrics = '[]';
                        $bibliometrics_count = 0;
                    } elseif ($bibliometrics_count == 0 && !$has_post_fields) {
                        // Only preserve if NO data collected AND no POST fields AND count not explicitly 0
                        error_log("[FacultyOutput] CRITICAL - Preserving existing bibliometrics (no new data)");
                        $bibliometrics = $existing_data['bibliometrics'];
                        $bibliometrics_count = count(json_decode($existing_data['bibliometrics'], true) ?: []);
                    } else {
                        error_log("[FacultyOutput] CRITICAL - Using newly collected bibliometrics (count=$bibliometrics_count, has_post_fields=" . ($has_post_fields ? 'yes' : 'no') . ")");
                    }
                } elseif ($post_bibliometrics_count === 0) {
                    // No existing data but count is 0, ensure it's cleared
                    $bibliometrics = '[]';
                    $bibliometrics_count = 0;
                }
                
                // For books - ONLY preserve if collected array is empty AND no POST fields
                // BUT: If POST count is 0, clear the data (user explicitly set count to 0)
                $post_books_count = (int)($_POST['books_count'] ?? -1);
                if (!empty($existing_data['books'])) {
                    $has_post_fields = false;
                    for ($i = 1; $i <= 50; $i++) {
                        if (isset($_POST["book_title_$i"]) || isset($_POST["book_type_$i"]) || isset($_POST["book_authors_$i"]) ||
                            isset($_POST["book_month_$i"]) || isset($_POST["book_year_$i"]) || isset($_POST["book_publisher_$i"])) {
                            $has_post_fields = true;
                            break;
                        }
                    }
                    // CRITICAL: If POST count is 0, clear data (user wants to delete all)
                    if ($post_books_count === 0) {
                        error_log("[FacultyOutput] CRITICAL - Clearing books (POST count is 0)");
                        $books = '[]';
                        $books_count = 0;
                    } elseif ($books_count == 0 && !$has_post_fields) {
                        // Only preserve if NO data collected AND no POST fields AND count not explicitly 0
                        error_log("[FacultyOutput] CRITICAL - Preserving existing books (no new data)");
                        $books = $existing_data['books'];
                        $books_count = count(json_decode($existing_data['books'], true) ?: []);
                    } else {
                        error_log("[FacultyOutput] CRITICAL - Using newly collected books (count=$books_count, has_post_fields=" . ($has_post_fields ? 'yes' : 'no') . ")");
                    }
                } elseif ($post_books_count === 0) {
                    // No existing data but count is 0, ensure it's cleared
                    $books = '[]';
                    $books_count = 0;
                }
                // Preserve existing research staff data if new data is empty
                // BUT: If POST counts are all 0, clear the data (user explicitly set counts to 0)
                $post_research_staff_male = (int)($_POST['research_staff_male'] ?? -1);
                $post_research_staff_female = (int)($_POST['research_staff_female'] ?? -1);
                $post_research_staff_other = (int)($_POST['research_staff_other'] ?? -1);
                $post_research_staff_total = $post_research_staff_male + $post_research_staff_female + $post_research_staff_other;
                
                if (!empty($existing_data['research_staff_details'])) {
                    $new_research_staff = json_decode($research_staff_json, true);
                    // CRITICAL: If all POST counts are 0, clear data (user wants to delete all)
                    if ($post_research_staff_total === 0 && $post_research_staff_male === 0 && $post_research_staff_female === 0 && $post_research_staff_other === 0) {
                        $research_staff_json = '[]';
                        $research_staff_male = 0;
                        $research_staff_female = 0;
                        $research_staff_other = 0;
                    } elseif (empty($new_research_staff) || $research_staff_json === '[]' || $research_staff_json === null || !is_array($new_research_staff)) {
                        // If new data is empty or null AND counts not explicitly 0, keep existing data
                        $research_staff_json = $existing_data['research_staff_details'];
                    } else {
                        // New data provided, use it
                        $research_staff_json = encodeFacultyJson($new_research_staff);
                    }
                } elseif ($post_research_staff_total === 0 && $post_research_staff_male === 0 && $post_research_staff_female === 0 && $post_research_staff_other === 0) {
                    // No existing data but counts are 0, ensure it's cleared
                    $research_staff_json = '[]';
                    $research_staff_male = 0;
                    $research_staff_female = 0;
                    $research_staff_other = 0;
                }
                
                // Preserve existing patent details data if new data is empty (replace logic like StudentSupport.php)
                if (!empty($existing_data['patent_details'])) {
                    // $patent_data is already populated from numbered fields in the main processing section above
                    if (empty($patent_data) || empty($patent_details_json) || $patent_details_json === '[]') {
                        $patent_details_json = $existing_data['patent_details'];
                    } else {
                        // New data provided, use it (already encoded above)
                        // $patent_details_json is already set correctly
                    }
                }
                // Preserve existing DPIIT startups data if new data is empty (replace logic like StudentSupport.php)
                if (!empty($existing_data['dpiit_startup_details'])) {
                    $new_dpiit = json_decode($dpiit_json, true);
                    if (empty($new_dpiit) || $dpiit_json === '[]' || $dpiit_json === null || !is_array($new_dpiit)) {
                        $dpiit_json = $existing_data['dpiit_startup_details'];
                        $total_dpiit_startups = count(json_decode($existing_data['dpiit_startup_details'], true) ?: []);
                    } else {
                        $dpiit_json = json_encode($new_dpiit);
                        $total_dpiit_startups = count($new_dpiit);
                    }
                }
                // Preserve existing VC investments data if new data is empty (replace logic like StudentSupport.php)
                if (!empty($existing_data['vc_investment_details'])) {
                    $new_vc = json_decode($vc_json, true);
                    if (empty($new_vc) || $vc_json === '[]' || $vc_json === null || !is_array($new_vc)) {
                        $vc_json = $existing_data['vc_investment_details'];
                        $total_vc_investments = count(json_decode($existing_data['vc_investment_details'], true) ?: []);
                    } else {
                        $vc_json = json_encode($new_vc);
                        $total_vc_investments = count($new_vc);
                    }
                }
                
                // Preserve existing seed funding data if new data is empty (replace logic like StudentSupport.php)
                if (!empty($existing_data['seed_funding_details'])) {
                    $new_seed = json_decode($seed_json, true);
                    if (empty($new_seed) || $seed_json === '[]' || $seed_json === null || !is_array($new_seed)) {
                        $seed_json = $existing_data['seed_funding_details'];
                        $total_seed_funding = count(json_decode($existing_data['seed_funding_details'], true) ?: []);
                    } else {
                        $seed_json = json_encode($new_seed);
                        $total_seed_funding = count($new_seed);
                    }
                }
                
                // Preserve existing FDI investments data if new data is empty (replace logic like StudentSupport.php)
                if (!empty($existing_data['fdi_investment_details'])) {
                    $new_fdi = json_decode($fdi_json, true);
                    if (empty($new_fdi) || $fdi_json === '[]' || $fdi_json === null || !is_array($new_fdi)) {
                        $fdi_json = $existing_data['fdi_investment_details'];
                        $total_fdi_investments = count(json_decode($existing_data['fdi_investment_details'], true) ?: []);
                    } else {
                        $fdi_json = json_encode($new_fdi);
                        $total_fdi_investments = count($new_fdi);
                    }
                }
                
                // Preserve existing innovation grants data if new data is empty (replace logic like StudentSupport.php)
                if (!empty($existing_data['innovation_grants_details'])) {
                    $new_grants = json_decode($grants_json, true);
                    if (empty($new_grants) || $grants_json === '[]' || $grants_json === null || !is_array($new_grants)) {
                        $grants_json = $existing_data['innovation_grants_details'];
                        $total_innovation_grants = count(json_decode($existing_data['innovation_grants_details'], true) ?: []);
                    } else {
                        $grants_json = json_encode($new_grants);
                        $total_innovation_grants = count($new_grants);
                    }
                }
                
                // Preserve existing TRL innovations data if new data is empty (replace logic like StudentSupport.php)
                if (!empty($existing_data['trl_innovations_details'])) {
                    $new_trl = json_decode($trl_json, true);
                    if (empty($new_trl) || $trl_json === '[]' || $trl_json === null || !is_array($new_trl)) {
                        $trl_json = $existing_data['trl_innovations_details'];
                        $total_trl_innovations = count(json_decode($existing_data['trl_innovations_details'], true) ?: []);
                    } else {
                        $trl_json = json_encode($new_trl);
                        $total_trl_innovations = count($new_trl);
                    }
                }
                
                // Preserve existing turnover achievements data if new data is empty (replace logic like StudentSupport.php)
                if (!empty($existing_data['turnover_achievements_details'])) {
                    $new_turnover = json_decode($turnover_json, true);
                    if (empty($new_turnover) || $turnover_json === '[]' || $turnover_json === null || !is_array($new_turnover)) {
                        $turnover_json = $existing_data['turnover_achievements_details'];
                        $total_turnover_achievements = count(json_decode($existing_data['turnover_achievements_details'], true) ?: []);
                    } else {
                        $turnover_json = json_encode($new_turnover);
                        $total_turnover_achievements = count($new_turnover);
                    }
                }
                
                // Preserve existing Forbes alumni data if new data is empty (replace logic like StudentSupport.php)
                if (!empty($existing_data['forbes_alumni_details'])) {
                    $new_forbes = json_decode($forbes_json, true);
                    if (empty($new_forbes) || $forbes_json === '[]' || $forbes_json === null || !is_array($new_forbes)) {
                        $forbes_json = $existing_data['forbes_alumni_details'];
                        $total_forbes_alumni = count(json_decode($existing_data['forbes_alumni_details'], true) ?: []);
                    } else {
                        $forbes_json = json_encode($new_forbes);
                        $total_forbes_alumni = count($new_forbes);
                    }
                }
            }
            mysqli_stmt_close($check_stmt);
        }
        
        // CRITICAL: Ensure publications, bibliometrics, and books are ALWAYS set before building $db_fields
        // These 3 sections MUST be saved correctly
        if (!isset($publications)) {
            $publications = encodeFacultyJson([]);
        }
        if (!isset($bibliometrics)) {
            $bibliometrics = encodeFacultyJson([]);
        }
        if (!isset($books)) {
            $books = encodeFacultyJson([]);
        }
        if (!isset($publications_count)) {
            $publications_count = 0;
        }
        if (!isset($bibliometrics_count)) {
            $bibliometrics_count = 0;
        }
        if (!isset($books_count)) {
            $books_count = 0;
        }
        
        // CRITICAL: Verify counts match actual data arrays
        // Use the higher of: POST count, actual data count, or detected index
        $pub_array = json_decode($publications, true) ?: [];
        $bib_array = json_decode($bibliometrics, true) ?: [];
        $book_array = json_decode($books, true) ?: [];
        
        $actual_pub_count = count($pub_array);
        $actual_bib_count = count($bib_array);
        $actual_book_count = count($book_array);
        
        // Get POST counts (preserve user input)
        $post_pub_count = (int)($_POST['publications_count'] ?? 0);
        $post_bib_count = (int)($_POST['bibliometrics_count'] ?? 0);
        $post_books_count = (int)($_POST['books_count'] ?? 0);
        
        // Use the maximum of: POST count, actual data count
        // This ensures count is preserved even if data collection has issues
        $publications_count = max($post_pub_count, $actual_pub_count);
        $bibliometrics_count = max($post_bib_count, $actual_bib_count);
        $books_count = max($post_books_count, $actual_book_count);
        
        // CRITICAL: Log the decision
        error_log("[FacultyOutput] CRITICAL - Count verification:");
        error_log("[FacultyOutput] - publications: POST=$post_pub_count, actual=$actual_pub_count, final=$publications_count");
        error_log("[FacultyOutput] - bibliometrics: POST=$post_bib_count, actual=$actual_bib_count, final=$bibliometrics_count");
        error_log("[FacultyOutput] - books: POST=$post_books_count, actual=$actual_book_count, final=$books_count");
        
        if ($post_pub_count > 0 && $actual_pub_count === 0) {
            error_log("[FacultyOutput] WARNING - POST publications_count=$post_pub_count but no data collected! Using POST count.");
        }
        if ($post_bib_count > 0 && $actual_bib_count === 0) {
            error_log("[FacultyOutput] WARNING - POST bibliometrics_count=$post_bib_count but no data collected! Using POST count.");
        }
        if ($post_books_count > 0 && $actual_book_count === 0) {
            error_log("[FacultyOutput] WARNING - POST books_count=$post_books_count but no data collected! Using POST count.");
        }
        
        // CRITICAL: Log final values before saving
        error_log("[FacultyOutput] CRITICAL - Final values before save:");
        error_log("[FacultyOutput] CRITICAL - publications_count=$publications_count, publications length=" . strlen($publications));
        error_log("[FacultyOutput] CRITICAL - bibliometrics_count=$bibliometrics_count, bibliometrics length=" . strlen($bibliometrics));
        error_log("[FacultyOutput] CRITICAL - books_count=$books_count, books length=" . strlen($books));
        
        $db_fields = [
            'recognitions' => $recognitions,
            'other_recognitions' => $other_recognitions,
            'infra_funding' => $infra_funding,
            'innovation_courses' => $innovation_courses,
            'innovation_training' => $innovation_training,
            'research_staff_male' => $research_staff_male,
            'research_staff_female' => $research_staff_female,
            'research_staff_other' => $research_staff_other,
            'research_staff_details' => $research_staff_json,
            'sponsored_projects_total' => $sponsored_projects_total,
            'sponsored_projects_agencies' => $sponsored_projects_agencies,
            'sponsored_amount_agencies' => $sponsored_amount_agencies,
            'sponsored_projects_industries' => $sponsored_projects_industries,
            'sponsored_amount_industries' => $sponsored_amount_industries,
            'patents_published_2022' => $patents_published_2022,
            'patents_published_2023' => $patents_published_2023,
            'patents_published_2024' => $patents_published_2024,
            'patents_granted_2022' => $patents_granted_2022,
            'patents_granted_2023' => $patents_granted_2023,
            'patents_granted_2024' => $patents_granted_2024,
            'copyrights_published' => $copyrights_published,
            'copyrights_granted' => $copyrights_granted,
            'designs_published' => $designs_published,
            'designs_granted' => $designs_granted,
            'tot_count' => $tot_count,
            'patents_count' => $patents_count,
            'awards_count' => $awards_count,
            'awards' => $awards,
            'projects_count' => $projects_count,
            'projects' => $projects,
            'training_count' => $training_count,
            'trainings' => $trainings,
            // CRITICAL: These 3 fields MUST be included - they are the problem sections
            'publications_count' => $publications_count,
            'publications' => $publications,
            'bibliometrics_count' => $bibliometrics_count,
            'bibliometrics' => $bibliometrics,
            'books_count' => $books_count,
            'books' => $books,
            'total_dpiit_startups' => $total_dpiit_startups,
            'dpiit_startup_details' => $dpiit_json,
            'total_vc_investments' => $total_vc_investments,
            'vc_investment_details' => $vc_json,
            'total_seed_funding' => $total_seed_funding,
            'seed_funding_details' => $seed_json,
            'total_fdi_investments' => $total_fdi_investments,
            'fdi_investment_details' => $fdi_json,
            'total_innovation_grants' => $total_innovation_grants,
            'innovation_grants_details' => $grants_json,
            'total_trl_innovations' => $total_trl_innovations,
            'trl_innovations_details' => $trl_json,
            'total_turnover_achievements' => $total_turnover_achievements,
            'turnover_achievements_details' => $turnover_json,
            'total_forbes_alumni' => $total_forbes_alumni,
            'forbes_alumni_details' => $forbes_json,
            'patent_details' => $patent_details_json,
            'desc_initiative' => $desc_initiative,
            'desc_impact' => $desc_impact,
            'desc_collaboration' => $desc_collaboration,
            'desc_plan' => $desc_plan,
            'desc_recognition' => $desc_recognition
        ];
        
        // CRITICAL: Double-verify these 3 fields are in $db_fields - ALWAYS ensure they exist
        // This prevents data loss if fields are accidentally omitted
        if (!isset($db_fields['publications_count']) || !isset($db_fields['publications'])) {
            error_log("[FacultyOutput] CRITICAL ERROR - publications fields missing from db_fields! Adding them now.");
            $db_fields['publications_count'] = $publications_count;
            $db_fields['publications'] = $publications;
        }
        // CRITICAL: Ensure publications is not null - always save as '[]' if empty
        if (empty($db_fields['publications']) || $db_fields['publications'] === 'null' || $db_fields['publications'] === null) {
            $db_fields['publications'] = '[]';
            $db_fields['publications_count'] = 0;
            error_log("[FacultyOutput] CRITICAL - Fixed publications to empty array (was null or empty)");
        }
        
        if (!isset($db_fields['bibliometrics_count']) || !isset($db_fields['bibliometrics'])) {
            error_log("[FacultyOutput] CRITICAL ERROR - bibliometrics fields missing from db_fields! Adding them now.");
            $db_fields['bibliometrics_count'] = $bibliometrics_count;
            $db_fields['bibliometrics'] = $bibliometrics;
        }
        // CRITICAL: Ensure bibliometrics is not null - always save as '[]' if empty
        if (empty($db_fields['bibliometrics']) || $db_fields['bibliometrics'] === 'null' || $db_fields['bibliometrics'] === null) {
            $db_fields['bibliometrics'] = '[]';
            $db_fields['bibliometrics_count'] = 0;
            error_log("[FacultyOutput] CRITICAL - Fixed bibliometrics to empty array (was null or empty)");
        }
        
        if (!isset($db_fields['books_count']) || !isset($db_fields['books'])) {
            error_log("[FacultyOutput] CRITICAL ERROR - books fields missing from db_fields! Adding them now.");
            $db_fields['books_count'] = $books_count;
            $db_fields['books'] = $books;
        }
        // CRITICAL: Ensure books is not null - always save as '[]' if empty
        if (empty($db_fields['books']) || $db_fields['books'] === 'null' || $db_fields['books'] === null) {
            $db_fields['books'] = '[]';
            $db_fields['books_count'] = 0;
            error_log("[FacultyOutput] CRITICAL - Fixed books to empty array (was null or empty)");
        }
        
        // CRITICAL: Log final values being saved to database
        error_log("[FacultyOutput] CRITICAL - Saving to database:");
        error_log("[FacultyOutput] CRITICAL - publications_count=" . $db_fields['publications_count'] . ", publications length=" . strlen($db_fields['publications']));
        error_log("[FacultyOutput] CRITICAL - bibliometrics_count=" . $db_fields['bibliometrics_count'] . ", bibliometrics length=" . strlen($db_fields['bibliometrics']));
        error_log("[FacultyOutput] CRITICAL - books_count=" . $db_fields['books_count'] . ", books length=" . strlen($db_fields['books']));

        if ($existing_data) {
            $update_columns = array_keys($db_fields);
            $set_clause = implode(', ', array_map(function ($col) {
                return "$col = ?";
            }, $update_columns));
            $update_query = "UPDATE faculty_output SET $set_clause WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            if (!$stmt) {
                throw new Exception('Failed to prepare update statement: ' . mysqli_error($conn));
            }
            $update_values = array_values($db_fields);
            $update_values[] = (int)$existing_data['id'];
            bindParamsDynamic($stmt, $update_values);
        } else {
            $insert_data = array_merge([
                'DEPT_ID' => (int)$dept_id,
                'A_YEAR' => $academic_year
            ], $db_fields);
            $insert_columns = array_keys($insert_data);
            $placeholders = implode(', ', array_fill(0, count($insert_columns), '?'));
            $insert_query = "INSERT INTO faculty_output (" . implode(', ', $insert_columns) . ") VALUES ($placeholders)";
            $stmt = mysqli_prepare($conn, $insert_query);
            if (!$stmt) {
                throw new Exception('Failed to prepare insert statement: ' . mysqli_error($conn));
            }
            $insert_values = array_values($insert_data);
            bindParamsDynamic($stmt, $insert_values);
        }

        // DEBUG: Log SQL query details - CRITICAL for troubleshooting
        error_log("[FacultyOutput] DEBUG - SQL query type: " . ($existing_data ? 'UPDATE' : 'INSERT'));
        error_log("[FacultyOutput] DEBUG - publications_count in db_fields: " . ($db_fields['publications_count'] ?? 'NOT SET'));
        error_log("[FacultyOutput] DEBUG - bibliometrics_count in db_fields: " . ($db_fields['bibliometrics_count'] ?? 'NOT SET'));
        error_log("[FacultyOutput] DEBUG - books_count in db_fields: " . ($db_fields['books_count'] ?? 'NOT SET'));
        error_log("[FacultyOutput] DEBUG - Total db_fields keys: " . count($db_fields));
        error_log("[FacultyOutput] DEBUG - publications JSON length in db_fields: " . (isset($db_fields['publications']) ? strlen($db_fields['publications']) : 'NOT SET'));
        error_log("[FacultyOutput] DEBUG - bibliometrics JSON length in db_fields: " . (isset($db_fields['bibliometrics']) ? strlen($db_fields['bibliometrics']) : 'NOT SET'));
        error_log("[FacultyOutput] DEBUG - books JSON length in db_fields: " . (isset($db_fields['books']) ? strlen($db_fields['books']) : 'NOT SET'));
        
        // CRITICAL: Log descriptive fields
        error_log("[FacultyOutput] DEBUG - desc_initiative in db_fields: " . (isset($db_fields['desc_initiative']) ? ('"' . substr($db_fields['desc_initiative'], 0, 50) . '..." (' . strlen($db_fields['desc_initiative']) . ' chars)') : 'NOT SET'));
        error_log("[FacultyOutput] DEBUG - desc_impact in db_fields: " . (isset($db_fields['desc_impact']) ? ('"' . substr($db_fields['desc_impact'], 0, 50) . '..." (' . strlen($db_fields['desc_impact']) . ' chars)') : 'NOT SET'));
        error_log("[FacultyOutput] DEBUG - desc_collaboration in db_fields: " . (isset($db_fields['desc_collaboration']) ? ('"' . substr($db_fields['desc_collaboration'], 0, 50) . '..." (' . strlen($db_fields['desc_collaboration']) . ' chars)') : 'NOT SET'));
        error_log("[FacultyOutput] DEBUG - desc_plan in db_fields: " . (isset($db_fields['desc_plan']) ? ('"' . substr($db_fields['desc_plan'], 0, 50) . '..." (' . strlen($db_fields['desc_plan']) . ' chars)') : 'NOT SET'));
        error_log("[FacultyOutput] DEBUG - desc_recognition in db_fields: " . (isset($db_fields['desc_recognition']) ? ('"' . substr($db_fields['desc_recognition'], 0, 50) . '..." (' . strlen($db_fields['desc_recognition']) . ' chars)') : 'NOT SET'));
        
        // CRITICAL: Log the actual SQL statement for debugging
        if ($existing_data) {
            error_log("[FacultyOutput] DEBUG - UPDATE query: UPDATE faculty_output SET " . implode(', ', array_map(function ($col) { return "$col = ?"; }, array_keys($db_fields))) . " WHERE id = ?");
        } else {
            error_log("[FacultyOutput] DEBUG - INSERT query: INSERT INTO faculty_output (" . implode(', ', array_keys(array_merge(['DEPT_ID' => (int)$dept_id, 'A_YEAR' => $academic_year], $db_fields))) . ") VALUES (...)");
        }

        if (mysqli_stmt_execute($stmt)) {
            // CRITICAL: Clear and recalculate score cache after data save
            require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
            clearDepartmentScoreCache($dept_id, $academic_year, true);
            
            // Set success flag
            $success = true;
            
            // CRITICAL: Log success with detailed data verification
            error_log("[FacultyOutput] CRITICAL - SQL executed successfully");
            error_log("[FacultyOutput] CRITICAL - Verified saved data:");
            error_log("[FacultyOutput] CRITICAL - publications_count=" . $db_fields['publications_count'] . ", publications JSON length=" . strlen($db_fields['publications']));
            error_log("[FacultyOutput] CRITICAL - bibliometrics_count=" . $db_fields['bibliometrics_count'] . ", bibliometrics JSON length=" . strlen($db_fields['bibliometrics']));
            error_log("[FacultyOutput] CRITICAL - books_count=" . $db_fields['books_count'] . ", books JSON length=" . strlen($db_fields['books']));
            
            // Verify the data was actually saved by querying back
            $verify_id = $existing_data ? (int)$existing_data['id'] : mysqli_insert_id($conn);
            if ($verify_id > 0) {
                $verify_query = "SELECT publications_count, bibliometrics_count, books_count, 
                                LENGTH(publications) as pub_len, LENGTH(bibliometrics) as bib_len, LENGTH(books) as book_len 
                                FROM faculty_output WHERE id = ?";
                $verify_stmt = mysqli_prepare($conn, $verify_query);
                if ($verify_stmt) {
                    mysqli_stmt_bind_param($verify_stmt, "i", $verify_id);
                    mysqli_stmt_execute($verify_stmt);
                    $verify_result = mysqli_stmt_get_result($verify_stmt);
                    if ($verify_row = mysqli_fetch_assoc($verify_result)) {
                        error_log("[FacultyOutput] CRITICAL - Database verification:");
                        error_log("[FacultyOutput] CRITICAL - Saved publications_count=" . $verify_row['publications_count'] . ", JSON length=" . $verify_row['pub_len']);
                        error_log("[FacultyOutput] CRITICAL - Saved bibliometrics_count=" . $verify_row['bibliometrics_count'] . ", JSON length=" . $verify_row['bib_len']);
                        error_log("[FacultyOutput] CRITICAL - Saved books_count=" . $verify_row['books_count'] . ", JSON length=" . $verify_row['book_len']);
                    }
                    mysqli_free_result($verify_result);
                    mysqli_stmt_close($verify_stmt);
                }
            }
            
            // Close the statement
            mysqli_stmt_close($stmt);
            
            // JSON data is already encoded above (before database queries)
            
            // Temporary document processing removed - all uploads are now permanent via common_upload_handler
            $_SESSION['success'] = $existing_data ? 'Data updated successfully! Form is now locked.' : 'Data submitted successfully! Form is now locked.';
            
            // Temporary file cleanup removed - all uploads are now permanent
            
            // CRITICAL: Clear ALL output buffers completely before redirect
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            // CRITICAL: Use proper header redirect (like StudentSupport.php)
            if (!headers_sent()) {
                header('Location: FacultyOutput.php');
                exit();
            } else {
                // Fallback to JavaScript if headers already sent - but clear buffers first
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
            echo '<script>window.location.href = "FacultyOutput.php";</script>';
            exit();
            }
        } else {
            // DEBUG: Log SQL execution failure - CRITICAL for troubleshooting
            $sql_error = mysqli_stmt_error($stmt);
            $conn_error = mysqli_error($conn);
            $errno = mysqli_errno($conn);
            error_log("[FacultyOutput] DEBUG - SQL execution FAILED!");
            error_log("[FacultyOutput] DEBUG - MySQL Error Number: $errno");
            error_log("[FacultyOutput] DEBUG - Statement error: $sql_error");
            error_log("[FacultyOutput] DEBUG - Connection error: $conn_error");
            error_log("[FacultyOutput] DEBUG - SQL query that failed: " . ($existing_data ? 'UPDATE' : 'INSERT'));
            error_log("[FacultyOutput] DEBUG - Values count: " . count($db_fields) . (isset($update_values) ? ", update_values count: " . count($update_values) : '') . (isset($insert_values) ? ", insert_values count: " . count($insert_values) : ''));
            
            // Close statement even on error
            mysqli_stmt_close($stmt);
            
            // CRITICAL: Create detailed error message
            $error_msg = "Failed to execute SQL statement.\n";
            $error_msg .= "MySQL Error #$errno: $conn_error\n";
            $error_msg .= "Statement Error: $sql_error\n";
            $error_msg .= "Query Type: " . ($existing_data ? 'UPDATE' : 'INSERT') . "\n";
            $error_msg .= "Publications Count: " . ($db_fields['publications_count'] ?? 'NOT SET') . "\n";
            $error_msg .= "Bibliometrics Count: " . ($db_fields['bibliometrics_count'] ?? 'NOT SET') . "\n";
            $error_msg .= "Books Count: " . ($db_fields['books_count'] ?? 'NOT SET') . "\n";
            
            throw new Exception($error_msg);
            }
            
            // Data is already loaded from unified faculty_output table
            // No need to refresh data here since we're redirecting
        
    } catch (Exception $e) {
        error_log("FacultyOutput Error: " . $e->getMessage());
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: FacultyOutput.php');
        }
        exit;
    }
}
// Temporary file cleanup functions removed - all uploads are now permanent via common_upload_handler

// Handle cleared=1 redirect gracefully (prevent infinite loop)
if (isset($_GET['cleared']) && $_GET['cleared'] == '1') {
    // Just show success message if in session, then remove query param
    if (isset($_SESSION['success'])) {
        // Message will be shown in the page
    }
    // Redirect to clean URL to prevent infinite loop
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Location: FacultyOutput.php');
        exit();
    }
}

// ============================================================================
// NORMAL PAGE LOAD CONTINUES - Include headers and display form
// ============================================================================

require('unified_header.php');

// Get dept_id from $userInfo (set by session.php via unified_header.php)
// Re-check and load existing data if not already loaded
// Safety check: ensure $userInfo is available
if (!isset($userInfo) || empty($userInfo)) {
    // Try to get from session if not available
    if (function_exists('getCurrentDepartmentId')) {
        $dept_id = getCurrentDepartmentId();
    } else {
        $dept_id = $_SESSION['dept_id'] ?? 0;
    }
} else {
    $dept_id = $userInfo['DEPT_ID'] ?? getCurrentDepartmentId() ?? 0;
}

// Only redirect if we absolutely can't get dept_id - otherwise show form with error
if (!$dept_id) {
    $error_message = 'Department ID not found. Please login again.';
    // Don't redirect - let the form show with error message
    error_log("[FacultyOutput] Warning: dept_id not found, but showing form with error message");
}

// Re-check for existing data if not already loaded (if it wasn't available before unified_header.php)
if (!isset($existing_data) && $dept_id > 0 && isset($conn)) {
    $existing_data_query = "SELECT * FROM faculty_output WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
    $existing_stmt = mysqli_prepare($conn, $existing_data_query);
    if ($existing_stmt) {
        mysqli_stmt_bind_param($existing_stmt, "is", $dept_id, $A_YEAR);
        mysqli_stmt_execute($existing_stmt);
        $existing_result = mysqli_stmt_get_result($existing_stmt);
        if ($existing_result && mysqli_num_rows($existing_result) > 0) {
            $existing_row = mysqli_fetch_assoc($existing_result);
            mysqli_free_result($existing_result); // CRITICAL: Free result set
            if (!isset($form_locked) || !$form_locked) {
                $form_locked = true;
            }
            if (!isset($existing_data)) {
                $existing_data = $existing_row;
            }
            if (!isset($editRow)) {
                $editRow = $existing_row;
            }
        } else {
            if ($existing_result) {
                mysqli_free_result($existing_result); // CRITICAL: Free result set even if empty
        }
        }
        mysqli_stmt_close($existing_stmt); // CRITICAL: Close statement
    }
}
?>

<div class="container-fluid" style="max-width: 100%; overflow-x: hidden;">
    <div class="main-content-area">
        <div class="glass-card">
        <form class="modern-form" method="POST" id="facultyForm" action="<?php echo basename($_SERVER['PHP_SELF']); ?>">
            <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
            
                <div class="page-header">
                    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <div>
                            <h1 class="page-title">
                                <i class="fas fa-chart-line me-3"></i>Faculty Output
                            </h1>
                        </div>
                        <a href="export_page_pdf.php?page=FacultyOutput" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                            <i class="fas fa-file-pdf"></i> Download as PDF
                        </a>
                    </div>
            </div>
            
            <div class="alert alert-info">
                <h6><strong><i class="fas fa-info-circle me-2"></i>Instructions:</strong></h6>
                <ul class="mb-0">
                    <li><strong class="text-danger">MANDATORY:</strong> The <strong>"Descriptive Summaries & Future Plans"</strong> section is compulsory - you must fill this to submit the form</li>
                    <li><strong>If you have NO data:</strong> Simply enter "No data available for this academic year" in the description and submit the form with zeros in other fields</li>
                    <li><strong>Optional fields:</strong> Other sections are optional - enter data only if applicable, or leave empty/enter 0</li>
                </ul>
            </div>
            
            
            <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

            <?php if ($form_locked && $existing_data): ?>
                <div class="alert alert-info">
                    <i class="fas fa-lock me-2"></i><strong>Form Status:</strong> Data has been submitted for academic year <?php echo htmlspecialchars($A_YEAR, ENT_QUOTES, 'UTF-8'); ?>. Form is locked. Click "Update" to modify existing data.
        </div>
        <?php endif; ?>

<script>
// ============================================================================
// CRITICAL: Define functions BEFORE form HTML to prevent ReferenceError
// These functions are called in inline event handlers (onkeypress, oninput)
// ============================================================================
function preventNonNumericInput(event) {
    if (!event) return;
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

function validatePDF(input) {
    if (!input || !input.files || !input.files[0]) return true;
    const file = input.files[0];
    const fileExtension = file.name.split('.').pop().toLowerCase();
    if (fileExtension !== 'pdf') {
        alert('Please select a PDF file only.');
        input.value = '';
        return false;
    }
    if (file.size > 10 * 1024 * 1024) {
        alert('File size must be less than 10MB.');
        input.value = '';
        return false;
    }
    return true;
}

// Stub functions - will be overwritten by full implementations
// Use var so it can be overwritten by the real function
// Stub function - will be replaced immediately by real implementation below
var generateAwardFields = function() {
    // Call real implementation if available
    if (typeof window._generateAwardFieldsFull === 'function') {
        return window._generateAwardFieldsFull();
    }
    // Fallback if not ready (shouldn't happen since it's defined right after)
    setTimeout(function() {
        if (typeof window._generateAwardFieldsFull === 'function') {
            window._generateAwardFieldsFull();
        }
    }, 50);
};
window.generateAwardFields = generateAwardFields;

function generateProjectFields() {
    if (typeof window.generateProjectFields === 'function' && window.generateProjectFields !== generateProjectFields) {
        return window.generateProjectFields();
    }
    if (typeof window._generateProjectFieldsFull === 'function') {
        return window._generateProjectFieldsFull();
    }
    setTimeout(generateProjectFields, 50);
}

function generateTrainingFields() {
    if (typeof window.generateTrainingFields === 'function' && window.generateTrainingFields !== generateTrainingFields) {
        return window.generateTrainingFields();
    }
    if (typeof window._generateTrainingFieldsFull === 'function') {
        return window._generateTrainingFieldsFull();
    }
    setTimeout(generateTrainingFields, 50);
}

function generatePublicationFields() {
    if (typeof window.generatePublicationFields === 'function' && window.generatePublicationFields !== generatePublicationFields) {
        return window.generatePublicationFields();
    }
    if (typeof window._generatePublicationFieldsFull === 'function') {
        return window._generatePublicationFieldsFull();
    }
    setTimeout(generatePublicationFields, 50);
}

function generateBibliometricFields() {
    if (typeof window.generateBibliometricFields === 'function' && window.generateBibliometricFields !== generateBibliometricFields) {
        return window.generateBibliometricFields();
    }
    if (typeof window._generateBibliometricFieldsFull === 'function') {
        return window._generateBibliometricFieldsFull();
    }
    setTimeout(generateBibliometricFields, 50);
}

function generateBookFields() {
    if (typeof window.generateBookFields === 'function' && window.generateBookFields !== generateBookFields) {
        return window.generateBookFields();
    }
    if (typeof window._generateBookFieldsFull === 'function') {
        return window._generateBookFieldsFull();
    }
    setTimeout(generateBookFields, 50);
}

// Stub functions - will be replaced immediately by real implementations below
var generateResearchStaffFields = function() {
    if (typeof window._generateResearchStaffFieldsFull === 'function') {
        return window._generateResearchStaffFieldsFull();
    }
    setTimeout(function() {
        if (typeof window._generateResearchStaffFieldsFull === 'function') {
            window._generateResearchStaffFieldsFull();
        }
    }, 50);
};
window.generateResearchStaffFields = generateResearchStaffFields;

var generateDpiitFields = function() {
    if (typeof window._generateDpiitFieldsFull === 'function') {
        return window._generateDpiitFieldsFull();
    }
    setTimeout(function() {
        if (typeof window._generateDpiitFieldsFull === 'function') {
            window._generateDpiitFieldsFull();
        }
    }, 50);
};
window.generateDpiitFields = generateDpiitFields;

var generateVcFields = function() {
    if (typeof window._generateVcFieldsFull === 'function') {
        return window._generateVcFieldsFull();
    }
    setTimeout(function() {
        if (typeof window._generateVcFieldsFull === 'function') {
            window._generateVcFieldsFull();
        }
    }, 50);
};
window.generateVcFields = generateVcFields;

var generateSeedFields = function() {
    if (typeof window._generateSeedFieldsFull === 'function') {
        return window._generateSeedFieldsFull();
    }
    setTimeout(function() {
        if (typeof window._generateSeedFieldsFull === 'function') {
            window._generateSeedFieldsFull();
        }
    }, 50);
};
window.generateSeedFields = generateSeedFields;

var generateFdiFields = function() {
    if (typeof window._generateFdiFieldsFull === 'function') {
        return window._generateFdiFieldsFull();
    }
    setTimeout(function() {
        if (typeof window._generateFdiFieldsFull === 'function') {
            window._generateFdiFieldsFull();
        }
    }, 50);
};
window.generateFdiFields = generateFdiFields;

var generateGrantFields = function() {
    if (typeof window._generateGrantFieldsFull === 'function') {
        return window._generateGrantFieldsFull();
    }
    setTimeout(function() {
        if (typeof window._generateGrantFieldsFull === 'function') {
            window._generateGrantFieldsFull();
        }
    }, 50);
};
window.generateGrantFields = generateGrantFields;

var generateTrlFields = function() {
    if (typeof window._generateTrlFieldsFull === 'function') {
        return window._generateTrlFieldsFull();
    }
    setTimeout(function() {
        if (typeof window._generateTrlFieldsFull === 'function') {
            window._generateTrlFieldsFull();
        }
    }, 50);
};
window.generateTrlFields = generateTrlFields;

var generateTurnoverFields = function() {
    if (typeof window._generateTurnoverFieldsFull === 'function') {
        return window._generateTurnoverFieldsFull();
    }
    setTimeout(function() {
        if (typeof window._generateTurnoverFieldsFull === 'function') {
            window._generateTurnoverFieldsFull();
        }
    }, 50);
};
window.generateTurnoverFields = generateTurnoverFields;

var generateForbesFields = function() {
    if (typeof window._generateForbesFieldsFull === 'function') {
        return window._generateForbesFieldsFull();
    }
    setTimeout(function() {
        if (typeof window._generateForbesFieldsFull === 'function') {
            window._generateForbesFieldsFull();
        }
    }, 50);
};
window.generateForbesFields = generateForbesFields;

var generatePatentFields = function() {
    if (typeof window._generatePatentFieldsFull === 'function') {
        return window._generatePatentFieldsFull();
    }
    setTimeout(function() {
        if (typeof window._generatePatentFieldsFull === 'function') {
            window._generatePatentFieldsFull();
        }
    }, 50);
};
window.generatePatentFields = generatePatentFields;

var calculatePatentTotals = function() {
    if (typeof window._calculatePatentTotalsFull === 'function') {
        return window._calculatePatentTotalsFull();
    }
    setTimeout(function() {
        if (typeof window._calculatePatentTotalsFull === 'function') {
            window._calculatePatentTotalsFull();
        }
    }, 50);
};
window.calculatePatentTotals = calculatePatentTotals;

// ============================================================================
// REAL IMPLEMENTATION OF generateAwardFields - Define early so it's available immediately
// ============================================================================
var generateAwardFieldsReal = function() {
    const awardsElement = document.getElementById('awards_count');
    if (!awardsElement) return;
    const count = parseInt(awardsElement.value) || 0;
    const container = document.getElementById('awards_container');
    if (!container) return;
    
    // Simple field value capture/restore (if functions not available yet)
    const captureValues = function(container) {
        const inputs = container.querySelectorAll('input, select, textarea');
        const values = {};
        inputs.forEach(input => {
            if (input.id) values[input.id] = input.value;
        });
        return values;
    };
    
    const restoreValues = function(container, values) {
        if (!values || !container || Object.keys(values).length === 0) return;
        // Use setTimeout to ensure DOM is ready after innerHTML replacement
        // Increased delay to ensure all fields are rendered before restoring values
        setTimeout(function() {
            const inputs = container.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                if (input.id && values[input.id] !== undefined && values[input.id] !== '') {
                    input.value = values[input.id];
                    // Trigger change event for select elements to update visual state
                    if (input.tagName === 'SELECT') {
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    // Trigger input event for text inputs
                    if (input.tagName === 'INPUT' && input.type === 'text') {
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            });
        }, 100);
    };
    
    // Check form lock state
    const updateBtn = document.querySelector('button[onclick*="enableUpdate"]');
    const formIsLocked = updateBtn && updateBtn.style.display !== 'none';
    
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValues(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const awardDiv = document.createElement('div');
        awardDiv.className = 'award-item mb-3 p-3 border rounded';
        const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
        awardDiv.innerHTML = 
            '<h6 class="text-primary mb-3">Award/Fellowship ' + i + '</h6>' +
            '<div class="form-group">' +
                '<label for="award_recognition_body_' + i + '" class="form-label"><strong>Recognition Body Type ' + i + '</strong></label>' +
                '<select class="form-select" id="award_recognition_body_' + i + '" name="award_recognition_body_' + i + '" ' + readonlyAttr + '>' +
                    '<option value="">Select Recognition Body Type</option>' +
                    '<option value="Government">By Government recognized bodies</option>' +
                    '<option value="Non-Government">By Non-Government recognized bodies</option>' +
                '</select>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="award_name_' + i + '" class="form-label">Full Name(s) of Recipient(s) ' + i + '</label>' +
                    '<input type="text" class="form-control" id="award_name_' + i + '" name="award_name_' + i + '" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="award_level_' + i + '" class="form-label">Level ' + i + '</label>' +
                    '<select class="form-select" id="award_level_' + i + '" name="award_level_' + i + '" ' + readonlyAttr + '>' +
                        '<option value="">Select Level</option>' +
                        '<option value="State">State</option>' +
                        '<option value="National">National</option>' +
                        '<option value="International">International</option>' +
                    '</select>' +
                '</div>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="award_title_' + i + '" class="form-label">Name of Award/Fellowship ' + i + '</label>' +
                '<input type="text" class="form-control" id="award_title_' + i + '" name="award_title_' + i + '" ' + readonlyAttr + '>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="award_agency_' + i + '" class="form-label">Issuing Agency ' + i + '</label>' +
                '<input type="text" class="form-control" id="award_agency_' + i + '" name="award_agency_' + i + '" ' + readonlyAttr + '>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="award_agency_address_' + i + '" class="form-label">Address of Award Agency ' + i + '</label>' +
                '<textarea class="form-control" id="award_agency_address_' + i + '" name="award_agency_address_' + i + '" rows="2" ' + readonlyAttr + '></textarea>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="award_agency_email_' + i + '" class="form-label">Email ID of Agency ' + i + '</label>' +
                    '<input type="email" class="form-control" id="award_agency_email_' + i + '" name="award_agency_email_' + i + '" placeholder="Enter agency email address" pattern="[a-z0-9._%+\\-]+@[a-z0-9.\\-]+\\.[a-z]{2,}" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="award_agency_contact_' + i + '" class="form-label">Contact of Agency ' + i + '</label>' +
                    '<input type="text" class="form-control" id="award_agency_contact_' + i + '" name="award_agency_contact_' + i + '" placeholder="Enter 10-digit contact number" maxlength="10" oninput="validateContactNumber(this)" ' + readonlyAttr + '>' +
                    '<div class="invalid-feedback" id="contact-feedback-' + i + '" style="display: none;">Please enter exactly 10 digits</div>' +
                '</div>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="award_date_' + i + '" class="form-label">Date of Award ' + i + '</label>' +
                '<input type="date" class="form-control" id="award_date_' + i + '" name="award_date_' + i + '" ' + readonlyAttr + '>' +
            '</div>';
        container.appendChild(awardDiv);
    }
    
    restoreValues(container, previousValues);
    
    // Contact fields now use oninput="validateContactNumber(this)" directly in HTML
};

// Store the real implementation immediately
window._generateAwardFieldsFull = generateAwardFieldsReal;
generateAwardFields = generateAwardFieldsReal;
window.generateAwardFields = generateAwardFieldsReal;

// ============================================================================
// ALL OTHER GENERATOR FUNCTIONS - Define early so they're available immediately
// ============================================================================

// Helper functions for all generators
var captureValuesHelper = function(container) {
    const inputs = container.querySelectorAll('input, select, textarea');
    const values = {};
    inputs.forEach(input => {
        if (input.id) values[input.id] = input.value;
        if (input.name) values[input.name] = input.value;
    });
    return values;
};

var restoreValuesHelper = function(container, values) {
    if (!values || !container || Object.keys(values).length === 0) return;
    // Use setTimeout to ensure DOM is ready after innerHTML replacement
    // Increased delay to ensure all fields are rendered before restoring values
    setTimeout(function() {
        const inputs = container.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            if (input.id && values[input.id] !== undefined && values[input.id] !== '') {
                input.value = values[input.id];
                // Trigger change event for select elements to update visual state
                if (input.tagName === 'SELECT') {
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
                // Trigger input event for text inputs
                if (input.tagName === 'INPUT' && input.type === 'text') {
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            } else if (input.name && values[input.name] !== undefined && values[input.name] !== '') {
                input.value = values[input.name];
                if (input.tagName === 'SELECT') {
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
                if (input.tagName === 'INPUT' && input.type === 'text') {
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
        });
    }, 100);
};

var getFormLockState = function() {
    const updateBtn = document.querySelector('button[onclick*="enableUpdate"]');
    return updateBtn && updateBtn.style.display !== 'none';
};

// Generate Project Fields
var generateProjectFieldsReal = function() {
    const element = document.getElementById('projects_count');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('projects_container');
    if (!container) return;
    
    const formIsLocked = getFormLockState();
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'project-item mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Project ' + i + '</h6>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="project_type_' + i + '" class="form-label">Project Type ' + i + '</label>' +
                    '<select class="form-select" id="project_type_' + i + '" name="project_type_' + i + '" ' + readonlyAttr + '>' +
                        '<option value="">Select Type</option>' +
                        '<option value="Govt-Sponsored">Government Sponsored Research</option>' +
                        '<option value="Non-Govt-Sponsored">Non-Government Sponsored Research</option>' +
                        '<option value="Consultancy">Consultancy</option>' +
                    '</select>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="project_title_' + i + '" class="form-label">Project Title ' + i + '</label>' +
                    '<input type="text" class="form-control" id="project_title_' + i + '" name="project_title_' + i + '" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="project_agency_' + i + '" class="form-label">Sponsoring/Consulting Agency ' + i + '</label>' +
                '<input type="text" class="form-control" id="project_agency_' + i + '" name="project_agency_' + i + '" ' + readonlyAttr + '>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="project_investigators_' + i + '" class="form-label">Investigator/Consultant Name(s) ' + i + '</label>' +
                '<textarea class="form-control" id="project_investigators_' + i + '" name="project_investigators_' + i + '" rows="2" ' + readonlyAttr + '></textarea>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-md-4 form-group">' +
                    '<label for="project_start_date_' + i + '" class="form-label">Start Date ' + i + '</label>' +
                    '<input type="date" class="form-control" id="project_start_date_' + i + '" name="project_start_date_' + i + '" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-4 form-group">' +
                    '<label for="project_end_date_' + i + '" class="form-label">End Date ' + i + '</label>' +
                    '<input type="date" class="form-control" id="project_end_date_' + i + '" name="project_end_date_' + i + '" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-4 form-group">' +
                    '<label for="project_amount_' + i + '" class="form-label">Amount Released (INR Lakhs) ' + i + '</label>' +
                    '<input type="number" step="0.01" class="form-control" id="project_amount_' + i + '" name="project_amount_' + i + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateProjectFieldsFull = generateProjectFieldsReal;
generateProjectFields = generateProjectFieldsReal;
window.generateProjectFields = generateProjectFieldsReal;

// Generate Training Fields
var generateTrainingFieldsReal = function() {
    const element = document.getElementById('training_count');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('training_container');
    if (!container) return;
    
    const formIsLocked = getFormLockState();
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'training-item mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Training Program ' + i + '</h6>' +
            '<div class="form-group">' +
                '<label for="training_name_' + i + '" class="form-label">Name of the Training Programme ' + i + '</label>' +
                '<input type="text" class="form-control" id="training_name_' + i + '" name="training_name_' + i + '" ' + readonlyAttr + '>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="training_corp_' + i + '" class="form-label">Corporate Name(s) ' + i + '</label>' +
                '<input type="text" class="form-control" id="training_corp_' + i + '" name="training_corp_' + i + '" ' + readonlyAttr + '>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="training_revenue_' + i + '" class="form-label">Revenue Generated (INR Lakhs) ' + i + '</label>' +
                    '<input type="number" step="0.01" class="form-control" id="training_revenue_' + i + '" name="training_revenue_' + i + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="training_participants_' + i + '" class="form-label">Number of Participants ' + i + '</label>' +
                    '<input type="number" class="form-control" id="training_participants_' + i + '" name="training_participants_' + i + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateTrainingFieldsFull = generateTrainingFieldsReal;
generateTrainingFields = generateTrainingFieldsReal;
window.generateTrainingFields = generateTrainingFieldsReal;

// Generate Publication Fields
var generatePublicationFieldsReal = function() {
    const element = document.getElementById('publications_count');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('publications_container');
    if (!container) return;
    
    // CRITICAL: Check both form lock state AND formUnlocked variable
    // If formUnlocked is true (Update was clicked), fields should NOT be disabled
    const formIsLocked = getFormLockState() && !(typeof formUnlocked !== 'undefined' && formUnlocked);
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'publication-item mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Publication ' + i + '</h6>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="pub_type_' + i + '" class="form-label">Publication Type ' + i + '</label>' +
                    '<select class="form-select" id="pub_type_' + i + '" name="pub_type_' + i + '" ' + readonlyAttr + '>' +
                        '<option value="">Select Type</option>' +
                        '<option value="Journal">Journal (Scopus/Web of Sciences)</option>' +
                        '<option value="Conference">Conference</option>' +
                        '<option value="ISSN_Journals">ISSN Journals + Special Issue Articles</option>' +
                        '<option value="Art_Dept">Art Dept (Applied/Fine/Performing Arts)</option>' +
                    '</select>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="pub_title_' + i + '" class="form-label">Title of Research/Article ' + i + '</label>' +
                    '<input type="text" class="form-control" id="pub_title_' + i + '" name="pub_title_' + i + '" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="pub_venue_' + i + '" class="form-label">Journal/Conference/Media Name ' + i + '</label>' +
                    '<input type="text" class="form-control" id="pub_venue_' + i + '" name="pub_venue_' + i + '" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="pub_indexed_' + i + '" class="form-label">Supported By/Indexed In ' + i + '</label>' +
                    '<input type="text" class="form-control" id="pub_indexed_' + i + '" name="pub_indexed_' + i + '" placeholder="e.g., Scopus, WoS, UGC Listed, ISSN" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="pub_authors_' + i + '" class="form-label">Author(s)/Editor(s) Name(s) ' + i + '</label>' +
                '<textarea class="form-control" id="pub_authors_' + i + '" name="pub_authors_' + i + '" rows="2" ' + readonlyAttr + '></textarea>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="pub_date_' + i + '" class="form-label">Month and Year of Publication ' + i + '</label>' +
                    '<input type="month" class="form-control" id="pub_date_' + i + '" name="pub_date_' + i + '" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="pub_url_' + i + '" class="form-label">URL (if applicable) ' + i + '</label>' +
                    '<input type="url" class="form-control" id="pub_url_' + i + '" name="pub_url_' + i + '" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generatePublicationFieldsFull = generatePublicationFieldsReal;
generatePublicationFields = generatePublicationFieldsReal;
window.generatePublicationFields = generatePublicationFieldsReal;

// Generate Bibliometric Fields
var generateBibliometricFieldsReal = function() {
    const element = document.getElementById('bibliometrics_count');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('bibliometrics_container');
    if (!container) return;
    
    // CRITICAL: Check both form lock state AND formUnlocked variable
    // If formUnlocked is true (Update was clicked), fields should NOT be disabled
    const formIsLocked = getFormLockState() && !(typeof formUnlocked !== 'undefined' && formUnlocked);
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'bibliometric-item mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Teacher ' + i + '</h6>' +
            '<div class="form-group">' +
                '<label for="bib_teacher_name_' + i + '" class="form-label">Teacher Name ' + i + '</label>' +
                '<input type="text" class="form-control" id="bib_teacher_name_' + i + '" name="bib_teacher_name_' + i + '" ' + readonlyAttr + '>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="bib_impact_factor_' + i + '" class="form-label">Impact Factor ' + i + '</label>' +
                    '<input type="number" step="0.01" class="form-control" id="bib_impact_factor_' + i + '" name="bib_impact_factor_' + i + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="bib_citations_' + i + '" class="form-label">Total Citations ' + i + '</label>' +
                    '<input type="number" class="form-control" id="bib_citations_' + i + '" name="bib_citations_' + i + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="bib_h_index_' + i + '" class="form-label">h-index ' + i + '</label>' +
                '<input type="number" class="form-control" id="bib_h_index_' + i + '" name="bib_h_index_' + i + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateBibliometricFieldsFull = generateBibliometricFieldsReal;
generateBibliometricFields = generateBibliometricFieldsReal;
window.generateBibliometricFields = generateBibliometricFieldsReal;

// Generate Book Fields
var generateBookFieldsReal = function() {
    const element = document.getElementById('books_count');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('books_container');
    if (!container) return;
    
    // CRITICAL: Check both form lock state AND formUnlocked variable
    // If formUnlocked is true (Update was clicked), fields should NOT be disabled
    const formIsLocked = getFormLockState() && !(typeof formUnlocked !== 'undefined' && formUnlocked);
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'book-item mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Book/Chapter/MOOC ' + i + '</h6>' +
            '<div class="form-group">' +
                '<label for="book_type_' + i + '" class="form-label">Type ' + i + '</label>' +
                '<select class="form-select" id="book_type_' + i + '" name="book_type_' + i + '" ' + readonlyAttr + '>' +
                    '<option value="">Select Type</option>' +
                    '<option value="Book">Book (Authored/Edited)</option>' +
                    '<option value="Chapter">Chapter</option>' +
                    '<option value="MOOC">MOOC</option>' +
                '</select>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="book_title_' + i + '" class="form-label">Title ' + i + '</label>' +
                '<input type="text" class="form-control" id="book_title_' + i + '" name="book_title_' + i + '" ' + readonlyAttr + '>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="book_authors_' + i + '" class="form-label">Author(s)/Editor(s) Name(s) ' + i + '</label>' +
                '<textarea class="form-control" id="book_authors_' + i + '" name="book_authors_' + i + '" rows="2" ' + readonlyAttr + '></textarea>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-md-4 form-group">' +
                    '<label for="book_month_' + i + '" class="form-label">Month ' + i + '</label>' +
                    '<select class="form-select" id="book_month_' + i + '" name="book_month_' + i + '" ' + readonlyAttr + '>' +
                        '<option value="">Select Month</option>' +
                        '<option value="01">January</option><option value="02">February</option><option value="03">March</option>' +
                        '<option value="04">April</option><option value="05">May</option><option value="06">June</option>' +
                        '<option value="07">July</option><option value="08">August</option><option value="09">September</option>' +
                        '<option value="10">October</option><option value="11">November</option><option value="12">December</option>' +
                    '</select>' +
                '</div>' +
                '<div class="col-md-4 form-group">' +
                    '<label for="book_year_' + i + '" class="form-label">Year ' + i + '</label>' +
                    '<input type="number" class="form-control" id="book_year_' + i + '" name="book_year_' + i + '" min="2000" max="2099" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-4 form-group">' +
                    '<label for="book_publisher_' + i + '" class="form-label">Publisher ' + i + '</label>' +
                    '<input type="text" class="form-control" id="book_publisher_' + i + '" name="book_publisher_' + i + '" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateBookFieldsFull = generateBookFieldsReal;
generateBookFields = generateBookFieldsReal;
window.generateBookFields = generateBookFieldsReal;

// Generate Research Staff Fields
var generateResearchStaffFieldsReal = function() {
    const maleElement = document.getElementById('research_staff_male');
    const femaleElement = document.getElementById('research_staff_female');
    const otherElement = document.getElementById('research_staff_other');
    
    const maleCount = maleElement ? parseInt(maleElement.value) || 0 : 0;
    const femaleCount = femaleElement ? parseInt(femaleElement.value) || 0 : 0;
    const otherCount = otherElement ? parseInt(otherElement.value) || 0 : 0;
    const totalCount = maleCount + femaleCount + otherCount;
    
    const container = document.getElementById('research_staff_container');
    if (!container) return;
    
    const formIsLocked = getFormLockState();
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    const previousValues = captureValuesHelper(container);
    container.innerHTML = '';
    
    if (totalCount === 0) return;
    
    let fieldIndex = 0;
    
    // Generate fields for male research staff
    for (let i = 1; i <= maleCount; i++) {
        const div = document.createElement('div');
        div.className = 'research-staff-item mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Research Staff ' + (fieldIndex + 1) + ' (Male)</h6>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="research_staff_agency_' + (fieldIndex + 1) + '" class="form-label">Agency Sponsoring</label>' +
                    '<input type="text" class="form-control" id="research_staff_agency_' + (fieldIndex + 1) + '" name="research_staff_agency_' + (fieldIndex + 1) + '" placeholder="Enter agency name" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="research_staff_amount_' + (fieldIndex + 1) + '" class="form-label">Amount Received (INR Lakhs)</label>' +
                    '<input type="number" step="0.01" class="form-control" id="research_staff_amount_' + (fieldIndex + 1) + '" name="research_staff_amount_' + (fieldIndex + 1) + '" min="0" placeholder="Enter amount" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                    '<input type="hidden" id="research_staff_gender_' + (fieldIndex + 1) + '" name="research_staff_gender_' + (fieldIndex + 1) + '" value="male">' +
                '</div>' +
            '</div>';
        container.appendChild(div);
        fieldIndex++;
    }
    
    // Generate fields for female research staff
    for (let i = 1; i <= femaleCount; i++) {
        const div = document.createElement('div');
        div.className = 'research-staff-item mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Research Staff ' + (fieldIndex + 1) + ' (Female)</h6>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="research_staff_agency_' + (fieldIndex + 1) + '" class="form-label">Agency Sponsoring</label>' +
                    '<input type="text" class="form-control" id="research_staff_agency_' + (fieldIndex + 1) + '" name="research_staff_agency_' + (fieldIndex + 1) + '" placeholder="Enter agency name" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="research_staff_amount_' + (fieldIndex + 1) + '" class="form-label">Amount Received (INR Lakhs)</label>' +
                    '<input type="number" step="0.01" class="form-control" id="research_staff_amount_' + (fieldIndex + 1) + '" name="research_staff_amount_' + (fieldIndex + 1) + '" min="0" placeholder="Enter amount" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                    '<input type="hidden" id="research_staff_gender_' + (fieldIndex + 1) + '" name="research_staff_gender_' + (fieldIndex + 1) + '" value="female">' +
                '</div>' +
            '</div>';
        container.appendChild(div);
        fieldIndex++;
    }
    
    // Generate fields for other research staff
    for (let i = 1; i <= otherCount; i++) {
        const div = document.createElement('div');
        div.className = 'research-staff-item mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Research Staff ' + (fieldIndex + 1) + ' (Other)</h6>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="research_staff_agency_' + (fieldIndex + 1) + '" class="form-label">Agency Sponsoring</label>' +
                    '<input type="text" class="form-control" id="research_staff_agency_' + (fieldIndex + 1) + '" name="research_staff_agency_' + (fieldIndex + 1) + '" placeholder="Enter agency name" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="research_staff_amount_' + (fieldIndex + 1) + '" class="form-label">Amount Received (INR Lakhs)</label>' +
                    '<input type="number" step="0.01" class="form-control" id="research_staff_amount_' + (fieldIndex + 1) + '" name="research_staff_amount_' + (fieldIndex + 1) + '" min="0" placeholder="Enter amount" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                    '<input type="hidden" id="research_staff_gender_' + (fieldIndex + 1) + '" name="research_staff_gender_' + (fieldIndex + 1) + '" value="other">' +
                '</div>' +
            '</div>';
        container.appendChild(div);
        fieldIndex++;
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateResearchStaffFieldsFull = generateResearchStaffFieldsReal;
generateResearchStaffFields = generateResearchStaffFieldsReal;
window.generateResearchStaffFields = generateResearchStaffFieldsReal;

// Generate Patent Fields
var generatePatentFieldsReal = function() {
    const countEl = document.getElementById('patents_count');
    if (!countEl) return;
    const count = parseInt(countEl.value) || 0;
    const container = document.getElementById('patents_container');
    if (!container) return;
    
    const formIsLocked = getFormLockState();
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'patent-item mb-4 p-4 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Patent Details ' + i + '</h6>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="patent_app_number_' + i + '" class="form-label">Patent Application Number ' + i + ' <span class="text-danger">*</span></label>' +
                    '<input type="text" class="form-control" id="patent_app_number_' + i + '" name="patent_app_number_' + i + '" placeholder="e.g., 202241048822" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="patent_status_' + i + '" class="form-label">Status of Patent ' + i + ' <span class="text-danger">*</span></label>' +
                    '<select class="form-select" id="patent_status_' + i + '" name="patent_status_' + i + '" ' + readonlyAttr + '>' +
                        '<option value="">Select Status</option>' +
                        '<option value="Published">Published</option>' +
                        '<option value="Granted">Granted</option>' +
                    '</select>' +
                '</div>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="patent_inventors_' + i + '" class="form-label">Inventor(s) Name ' + i + ' <span class="text-danger">*</span></label>' +
                '<textarea class="form-control" id="patent_inventors_' + i + '" name="patent_inventors_' + i + '" rows="2" placeholder="Enter all inventor names" ' + readonlyAttr + '></textarea>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="patent_title_' + i + '" class="form-label">Title of the Patent ' + i + ' <span class="text-danger">*</span></label>' +
                '<textarea class="form-control" id="patent_title_' + i + '" name="patent_title_' + i + '" rows="2" placeholder="Enter the complete title" ' + readonlyAttr + '></textarea>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="patent_applicants_' + i + '" class="form-label">Applicant(s) Name ' + i + ' <span class="text-danger">*</span></label>' +
                '<textarea class="form-control" id="patent_applicants_' + i + '" name="patent_applicants_' + i + '" rows="2" placeholder="Enter all applicant names" ' + readonlyAttr + '></textarea>' +
            '</div>' +
            '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                    '<label for="patent_filed_date_' + i + '" class="form-label">Patent Filed Date ' + i + ' <span class="text-danger">*</span></label>' +
                    '<input type="date" class="form-control" id="patent_filed_date_' + i + '" name="patent_filed_date_' + i + '" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                    '<label for="patent_published_date_' + i + '" class="form-label">Patent Published/Granted Date ' + i + ' <span class="text-danger">*</span></label>' +
                    '<input type="date" class="form-control" id="patent_published_date_' + i + '" name="patent_published_date_' + i + '" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="patent_number_' + i + '" class="form-label">Patent Publication/Granted Number ' + i + '</label>' +
                '<input type="text" class="form-control" id="patent_number_' + i + '" name="patent_number_' + i + '" placeholder="e.g., 479281 or leave empty" ' + readonlyAttr + '>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="patent_assignees_' + i + '" class="form-label">Assignee(s) Name (Institute Affiliation) ' + i + ' <span class="text-danger">*</span></label>' +
                '<input type="text" class="form-control" id="patent_assignees_' + i + '" name="patent_assignees_' + i + '" placeholder="e.g., University of Mumbai" ' + readonlyAttr + '>' +
            '</div>' +
            '<div class="form-group">' +
                '<label for="patent_url_' + i + '" class="form-label">Patent URL (Proof Link) ' + i + ' <span class="text-danger">*</span></label>' +
                '<input type="url" class="form-control" id="patent_url_' + i + '" name="patent_url_' + i + '" placeholder="https://..." ' + readonlyAttr + '>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generatePatentFieldsFull = generatePatentFieldsReal;
generatePatentFields = generatePatentFieldsReal;
window.generatePatentFields = generatePatentFieldsReal;

// Calculate Patent Totals function
var calculatePatentTotalsReal = function() {
    const published2022 = parseInt(document.getElementById('patents_published_2022')?.value || 0);
    const published2023 = parseInt(document.getElementById('patents_published_2023')?.value || 0);
    const published2024 = parseInt(document.getElementById('patents_published_2024')?.value || 0);
    const granted2022 = parseInt(document.getElementById('patents_granted_2022')?.value || 0);
    const granted2023 = parseInt(document.getElementById('patents_granted_2023')?.value || 0);
    const granted2024 = parseInt(document.getElementById('patents_granted_2024')?.value || 0);

    const totalPublished = published2022 + published2023 + published2024;
    const totalGranted = granted2022 + granted2023 + granted2024;
    const totalPatents = totalPublished + totalGranted;

    const totalPublishedEl = document.getElementById('total_published');
    const totalGrantedEl = document.getElementById('total_granted');
    const patentsCountEl = document.getElementById('patents_count');
    
    if (totalPublishedEl) totalPublishedEl.value = totalPublished;
    if (totalGrantedEl) totalGrantedEl.value = totalGranted;
    if (patentsCountEl) patentsCountEl.value = totalPatents;
    
    // Generate patent fields automatically based on the total count
    if (typeof generatePatentFields === 'function') {
        generatePatentFields();
    }
};
window._calculatePatentTotalsFull = calculatePatentTotalsReal;
calculatePatentTotals = calculatePatentTotalsReal;
window.calculatePatentTotals = calculatePatentTotalsReal;

// Generate DPIIT Fields
var generateDpiitFieldsReal = function() {
    const element = document.querySelector('input[name="total_dpiit_startups"]');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('dpiit_startups_container');
    if (!container) return;
    
    const formIsLocked = getFormLockState();
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'dynamic-entry mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">DPIIT Startup ' + i + '</h6>' +
            '<div class="row">' +
                '<div class="col-md-4">' +
                    '<label for="dpiit_startup_name_' + i + '" class="form-label">Startup Name</label>' +
                    '<input type="text" class="form-control" id="dpiit_startup_name_' + i + '" name="dpiit_startup_name_' + i + '" placeholder="Enter startup name" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-4">' +
                    '<label for="dpiit_registration_no_' + i + '" class="form-label">DPIIT Registration No.</label>' +
                    '<input type="text" class="form-control" id="dpiit_registration_no_' + i + '" name="dpiit_registration_no_' + i + '" placeholder="e.g., DIPP73270" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-4">' +
                    '<label for="dpiit_year_recognition_' + i + '" class="form-label">Year of Recognition</label>' +
                    '<input type="number" class="form-control" id="dpiit_year_recognition_' + i + '" name="dpiit_year_recognition_' + i + '" placeholder="e.g., 2023" min="2000" max="' + new Date().getFullYear() + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateDpiitFieldsFull = generateDpiitFieldsReal;
generateDpiitFields = generateDpiitFieldsReal;
window.generateDpiitFields = generateDpiitFieldsReal;

// Generate VC Fields
var generateVcFieldsReal = function() {
    const element = document.querySelector('input[name="total_vc_investments"]');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('vc_investments_container');
    if (!container) return;
    
    const formIsLocked = getFormLockState();
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'dynamic-entry mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">VC Investment ' + i + '</h6>' +
            '<div class="row">' +
                '<div class="col-md-3">' +
                    '<label for="vc_startup_name_' + i + '" class="form-label">Startup Name</label>' +
                    '<input type="text" class="form-control" id="vc_startup_name_' + i + '" name="vc_startup_name_' + i + '" placeholder="Enter startup name" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="vc_dpiit_no_' + i + '" class="form-label">DPIIT No.</label>' +
                    '<input type="text" class="form-control" id="vc_dpiit_no_' + i + '" name="vc_dpiit_no_' + i + '" placeholder="e.g., DIPP117363" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="vc_amount_' + i + '" class="form-label">Amount Received (₹ Lakhs)</label>' +
                    '<input type="number" step="0.01" class="form-control" id="vc_amount_' + i + '" name="vc_amount_' + i + '" placeholder="e.g., 50.00" min="0" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="vc_organization_' + i + '" class="form-label">Organization</label>' +
                    '<input type="text" class="form-control" id="vc_organization_' + i + '" name="vc_organization_' + i + '" placeholder="e.g., Individual" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>' +
            '<div class="row mt-2">' +
                '<div class="col-md-3">' +
                    '<label for="vc_year_' + i + '" class="form-label">Year of Receiving</label>' +
                    '<input type="number" class="form-control" id="vc_year_' + i + '" name="vc_year_' + i + '" placeholder="e.g., 2023" min="2000" max="' + new Date().getFullYear() + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="vc_achievement_' + i + '" class="form-label">Achievement Level</label>' +
                    '<input type="text" class="form-control" id="vc_achievement_' + i + '" name="vc_achievement_' + i + '" placeholder="e.g., Actual system proven" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="vc_type_' + i + '" class="form-label">Type of Investment</label>' +
                    '<input type="text" class="form-control" id="vc_type_' + i + '" name="vc_type_' + i + '" placeholder="e.g., Equity" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateVcFieldsFull = generateVcFieldsReal;
generateVcFields = generateVcFieldsReal;
window.generateVcFields = generateVcFieldsReal;

// Generate Seed Fields
var generateSeedFieldsReal = function() {
    const element = document.querySelector('input[name="total_seed_funding"]');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('seed_funding_container');
    if (!container) return;
    
    const formIsLocked = getFormLockState();
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'dynamic-entry mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Seed Funding ' + i + '</h6>' +
            '<div class="row">' +
                '<div class="col-md-3">' +
                    '<label for="seed_startup_name_' + i + '" class="form-label">Startup Name</label>' +
                    '<input type="text" class="form-control" id="seed_startup_name_' + i + '" name="seed_startup_name_' + i + '" placeholder="Enter startup name" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="seed_dpiit_no_' + i + '" class="form-label">DPIIT No.</label>' +
                    '<input type="text" class="form-control" id="seed_dpiit_no_' + i + '" name="seed_dpiit_no_' + i + '" placeholder="e.g., DIPP167282" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="seed_amount_' + i + '" class="form-label">Seed Funding (₹ Lakhs)</label>' +
                    '<input type="number" step="0.01" class="form-control" id="seed_amount_' + i + '" name="seed_amount_' + i + '" placeholder="e.g., 10.00" min="0" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="seed_organization_' + i + '" class="form-label">Government Organization</label>' +
                    '<input type="text" class="form-control" id="seed_organization_' + i + '" name="seed_organization_' + i + '" placeholder="e.g., Startup India Seed" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>' +
            '<div class="row mt-2">' +
                '<div class="col-md-3">' +
                    '<label for="seed_year_' + i + '" class="form-label">Year of Receiving</label>' +
                    '<input type="number" class="form-control" id="seed_year_' + i + '" name="seed_year_' + i + '" placeholder="e.g., 2023" min="2000" max="' + new Date().getFullYear() + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="seed_achievement_' + i + '" class="form-label">Achievement Level</label>' +
                    '<input type="text" class="form-control" id="seed_achievement_' + i + '" name="seed_achievement_' + i + '" placeholder="e.g., system/sub system" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="seed_type_' + i + '" class="form-label">Type of Investment</label>' +
                    '<input type="text" class="form-control" id="seed_type_' + i + '" name="seed_type_' + i + '" placeholder="e.g., Grant" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateSeedFieldsFull = generateSeedFieldsReal;
generateSeedFields = generateSeedFieldsReal;
window.generateSeedFields = generateSeedFieldsReal;

// Generate FDI Fields
var generateFdiFieldsReal = function() {
    const element = document.querySelector('input[name="total_fdi_investments"]');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('fdi_investments_container');
    if (!container) return;
    
    const formIsLocked = getFormLockState();
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'dynamic-entry mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">FDI Investment ' + i + '</h6>' +
            '<div class="row">' +
                '<div class="col-md-3">' +
                    '<label for="fdi_startup_name_' + i + '" class="form-label">Startup Name</label>' +
                    '<input type="text" class="form-control" id="fdi_startup_name_' + i + '" name="fdi_startup_name_' + i + '" placeholder="Enter startup name" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="fdi_dpiit_no_' + i + '" class="form-label">DPIIT No.</label>' +
                    '<input type="text" class="form-control" id="fdi_dpiit_no_' + i + '" name="fdi_dpiit_no_' + i + '" placeholder="e.g., DIPP117363" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="fdi_amount_' + i + '" class="form-label">FDI Amount (₹ Lakhs)</label>' +
                    '<input type="number" step="0.01" class="form-control" id="fdi_amount_' + i + '" name="fdi_amount_' + i + '" placeholder="e.g., 50.00" min="0" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="fdi_organization_' + i + '" class="form-label">Organization</label>' +
                    '<input type="text" class="form-control" id="fdi_organization_' + i + '" name="fdi_organization_' + i + '" placeholder="e.g., Foreign Investor" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>' +
            '<div class="row mt-2">' +
                '<div class="col-md-3">' +
                    '<label for="fdi_year_' + i + '" class="form-label">Year of Receiving</label>' +
                    '<input type="number" class="form-control" id="fdi_year_' + i + '" name="fdi_year_' + i + '" placeholder="e.g., 2023" min="2000" max="' + new Date().getFullYear() + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="fdi_achievement_' + i + '" class="form-label">Achievement Level</label>' +
                    '<input type="text" class="form-control" id="fdi_achievement_' + i + '" name="fdi_achievement_' + i + '" placeholder="e.g., Actual system proven" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="fdi_type_' + i + '" class="form-label">Type of Investment</label>' +
                    '<input type="text" class="form-control" id="fdi_type_' + i + '" name="fdi_type_' + i + '" placeholder="e.g., Equity" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateFdiFieldsFull = generateFdiFieldsReal;
generateFdiFields = generateFdiFieldsReal;
window.generateFdiFields = generateFdiFieldsReal;

// Generate Grant Fields
var generateGrantFieldsReal = function() {
    const element = document.querySelector('input[name="total_innovation_grants"]');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('innovation_grants_container');
    if (!container) return;
    
    const formIsLocked = getFormLockState();
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'dynamic-entry mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Innovation Grant ' + i + '</h6>' +
            '<div class="row">' +
                '<div class="col-md-3">' +
                    '<label for="grant_organization_' + i + '" class="form-label">Government Organization</label>' +
                    '<input type="text" class="form-control" id="grant_organization_' + i + '" name="grant_organization_' + i + '" placeholder="e.g., Department of Science" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="grant_program_' + i + '" class="form-label">Program/Scheme Name</label>' +
                    '<input type="text" class="form-control" id="grant_program_' + i + '" name="grant_program_' + i + '" placeholder="e.g., NIDHI-Seed Support" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="grant_amount_' + i + '" class="form-label">Grant Amount (₹ Lakhs)</label>' +
                    '<input type="number" step="0.01" class="form-control" id="grant_amount_' + i + '" name="grant_amount_' + i + '" placeholder="e.g., 307.50" min="0" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="grant_year_' + i + '" class="form-label">Year of Receiving</label>' +
                    '<input type="number" class="form-control" id="grant_year_' + i + '" name="grant_year_' + i + '" placeholder="e.g., 2023" min="2000" max="' + new Date().getFullYear() + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateGrantFieldsFull = generateGrantFieldsReal;
generateGrantFields = generateGrantFieldsReal;
window.generateGrantFields = generateGrantFieldsReal;

// Generate TRL Fields
var generateTrlFieldsReal = function() {
    const element = document.querySelector('input[name="total_trl_innovations"]');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('trl_innovations_container');
    if (!container) return;
    
    const formIsLocked = getFormLockState();
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'dynamic-entry mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">TRL Innovation ' + i + '</h6>' +
            '<div class="row">' +
                '<div class="col-md-3">' +
                    '<label for="trl_startup_name_' + i + '" class="form-label">Startup Name</label>' +
                    '<input type="text" class="form-control" id="trl_startup_name_' + i + '" name="trl_startup_name_' + i + '" placeholder="Enter startup name" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="trl_dpiit_no_' + i + '" class="form-label">DPIIT No.</label>' +
                    '<input type="text" class="form-control" id="trl_dpiit_no_' + i + '" name="trl_dpiit_no_' + i + '" placeholder="e.g., DIPP73270" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="trl_stage_' + i + '" class="form-label">Stage Level</label>' +
                    '<select class="form-control" id="trl_stage_' + i + '" name="trl_stage_' + i + '" ' + readonlyAttr + '>' +
                        '<option value="">Select Level</option>' +
                        '<option value="Level 1">Level 1</option>' +
                        '<option value="Level 2">Level 2</option>' +
                        '<option value="Level 3">Level 3</option>' +
                        '<option value="Level 4">Level 4</option>' +
                        '<option value="Level 5">Level 5</option>' +
                        '<option value="Level 6">Level 6</option>' +
                        '<option value="Level 7">Level 7</option>' +
                        '<option value="Level 8">Level 8</option>' +
                        '<option value="Level 9">Level 9</option>' +
                    '</select>' +
                '</div>' +
            '</div>' +
            '<div class="row mt-2">' +
                '<div class="col-md-12">' +
                    '<label for="trl_innovation_name_' + i + '" class="form-label">Innovation Name</label>' +
                    '<textarea class="form-control" id="trl_innovation_name_' + i + '" name="trl_innovation_name_' + i + '" rows="2" placeholder="Describe the innovation..." ' + readonlyAttr + '></textarea>' +
                '</div>' +
                '<div class="col-md-12 mt-2">' +
                    '<label for="trl_achievement_' + i + '" class="form-label">Achievement Level</label>' +
                    '<input type="text" class="form-control" id="trl_achievement_' + i + '" name="trl_achievement_' + i + '" placeholder="e.g., Actual system proven" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateTrlFieldsFull = generateTrlFieldsReal;
generateTrlFields = generateTrlFieldsReal;
window.generateTrlFields = generateTrlFieldsReal;

// Generate Turnover Fields
var generateTurnoverFieldsReal = function() {
    const element = document.querySelector('input[name="total_turnover_achievements"]');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('turnover_achievements_container');
    if (!container) return;
    
    const formIsLocked = getFormLockState();
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'dynamic-entry mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Turnover Achievement ' + i + '</h6>' +
            '<div class="row">' +
                '<div class="col-md-4">' +
                    '<label for="turnover_startup_name_' + i + '" class="form-label">Startup Name</label>' +
                    '<input type="text" class="form-control" id="turnover_startup_name_' + i + '" name="turnover_startup_name_' + i + '" placeholder="Enter startup name" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-4">' +
                    '<label for="turnover_dpiit_no_' + i + '" class="form-label">DPIIT No.</label>' +
                    '<input type="text" class="form-control" id="turnover_dpiit_no_' + i + '" name="turnover_dpiit_no_' + i + '" placeholder="e.g., DIPP65825" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-4">' +
                    '<label for="turnover_amount_' + i + '" class="form-label">Company\'s Turnover</label>' +
                    '<input type="text" class="form-control" id="turnover_amount_' + i + '" name="turnover_amount_' + i + '" placeholder="e.g., INR. 1.09 Cr." ' + readonlyAttr + '>' +
                '</div>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateTurnoverFieldsFull = generateTurnoverFieldsReal;
generateTurnoverFields = generateTurnoverFieldsReal;
window.generateTurnoverFields = generateTurnoverFieldsReal;

// Generate Forbes Fields
var generateForbesFieldsReal = function() {
    const element = document.querySelector('input[name="total_forbes_alumni"]');
    if (!element) return;
    const count = parseInt(element.value) || 0;
    const container = document.getElementById('forbes_alumni_container');
    if (!container) return;
    
    const formIsLocked = getFormLockState();
    const readonlyAttr = formIsLocked ? 'readonly disabled' : '';
    // Only capture existing values if container already has fields (user is changing count)
    // Don't capture on initial load from database - those values will be set separately
    const hasExistingFields = container.querySelectorAll('input, select, textarea').length > 0;
    const previousValues = hasExistingFields ? captureValuesHelper(container) : {};
    container.innerHTML = '';
    
    for (let i = 1; i <= count; i++) {
        const div = document.createElement('div');
        div.className = 'dynamic-entry mb-3 p-3 border rounded';
        div.innerHTML = 
            '<h6 class="text-primary mb-3">Forbes Alumni ' + i + '</h6>' +
            '<div class="row">' +
                '<div class="col-md-3">' +
                    '<label for="forbes_program_' + i + '" class="form-label">Program Name</label>' +
                    '<input type="text" class="form-control" id="forbes_program_' + i + '" name="forbes_program_' + i + '" placeholder="Enter program name" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="forbes_year_passing_' + i + '" class="form-label">Year of Passing</label>' +
                    '<input type="number" class="form-control" id="forbes_year_passing_' + i + '" name="forbes_year_passing_' + i + '" placeholder="e.g., 2015" min="1950" max="' + new Date().getFullYear() + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="forbes_company_' + i + '" class="form-label">Founder Company</label>' +
                    '<input type="text" class="form-control" id="forbes_company_' + i + '" name="forbes_company_' + i + '" placeholder="Enter company name" ' + readonlyAttr + '>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<label for="forbes_year_founded_' + i + '" class="form-label">Year Founded</label>' +
                    '<input type="number" class="form-control" id="forbes_year_founded_' + i + '" name="forbes_year_founded_' + i + '" placeholder="e.g., 2018" min="1950" max="' + new Date().getFullYear() + '" onkeypress="preventNonNumericInput(event)" ' + readonlyAttr + '>' +
                '</div>' +
            '</div>';
        container.appendChild(div);
    }
    
    restoreValuesHelper(container, previousValues);
};
window._generateForbesFieldsFull = generateForbesFieldsReal;
generateForbesFields = generateForbesFieldsReal;
window.generateForbesFields = generateForbesFieldsReal;

// Make validateContactNumber available globally immediately
window.validateContactNumber = function(input) {
    if (!input) return;
    
    let value = input.value;
    // Remove any non-numeric characters
    value = value.replace(/[^0-9]/g, '');
    
    // Limit to 10 digits
    if (value.length > 10) {
        value = value.substring(0, 10);
    }
    
    input.value = value;
    
    // Get feedback element
    const inputId = input.id || '';
    const feedbackId = inputId.replace(/[^0-9]/g, '');
    const feedbackElement = document.getElementById('contact-feedback-' + feedbackId);
    
    // Remove existing validation classes
    input.classList.remove('is-valid', 'is-invalid');
    
    if (value.length === 0) {
        // Empty field - no validation styling
        input.style.borderColor = '';
        input.removeAttribute('title');
        if (feedbackElement) feedbackElement.style.display = 'none';
        return;
    }
    
    if (value.length === 10) {
        input.classList.add('is-valid');
        input.style.borderColor = '#28a745'; // Green border
        input.removeAttribute('title');
        if (feedbackElement) feedbackElement.style.display = 'none';
    } else {
        input.classList.add('is-invalid');
        input.style.borderColor = '#dc3545'; // Red border
        input.setAttribute('title', 'Please enter exactly 10 digits');
        if (feedbackElement) {
            feedbackElement.textContent = 'Please enter exactly 10 digits';
            feedbackElement.style.display = 'block';
        }
    }
};

// Make validatePDF available globally immediately
window.validatePDF = function(input) {
    var file = input.files && input.files[0];
    if (file) {
        var fileExtension = file.name.split('.').pop().toLowerCase();
        if (fileExtension !== 'pdf') {
            alert('Please select a PDF file only.');
            input.value = '';
            return false;
        }
        if (file.size > 10 * 1024 * 1024) { // 10MB limit
            alert('File size must be less than 10MB.');
            input.value = '';
            return false;
        }
    }
    return true;
};

// Make uploadDocument available globally immediately
window.uploadDocument = function(fileId, srno) {
    try {
        var fileInput = document.getElementById(fileId);
        var statusDiv = document.getElementById(fileId + '_status');
        
        if (!fileInput) {
            alert('❌ File input not found. Please refresh the page.');
            return;
        }
        
        if (!statusDiv) {
            alert('❌ Status div not found. Please refresh the page.');
            return;
        }
        
        // Check if form is locked (file input is disabled)
        if (fileInput.disabled) {
            // Form is locked, just check existing document status to view it
            if (typeof window.checkDocumentStatus === 'function') {
                window.checkDocumentStatus(fileId, srno);
            } else if (typeof checkDocumentStatus === 'function') {
                checkDocumentStatus(fileId, srno);
            }
            return;
        }
    
        if (!fileInput.files || !fileInput.files[0]) {
            alert('Please select a file to upload.');
            return;
        }
        
        // Validate PDF (inline validation)
        var file = fileInput.files[0];
        var fileExtension = file.name.split('.').pop().toLowerCase();
        if (fileExtension !== 'pdf') {
            alert('Please select a PDF file only.');
            fileInput.value = '';
            return;
        }
        if (file.size > 10 * 1024 * 1024) { // 10MB limit
            alert('File size must be less than 10MB.');
            fileInput.value = '';
            return;
        }
        
        // Get CSRF token from form
        var csrfTokenInput = document.querySelector('input[name="csrf_token"]');
        if (!csrfTokenInput) {
            alert('Security token not found. Please refresh the page.');
            return;
        }
        
        var formData = new FormData();
        formData.append('upload_document', '1');
        formData.append('file_id', fileId);
        formData.append('srno', srno);
        formData.append('document', file);
        formData.append('csrf_token', csrfTokenInput.value);
        
        // Show loading state
        statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Uploading...';
        statusDiv.className = 'mt-2 text-info';
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .catch(error => {
            // CRITICAL: Handle fetch errors (network, CORS, etc.) - return error object
            return { 
                ok: false, 
                text: () => Promise.resolve(JSON.stringify({ 
                    success: false, 
                    message: 'Network error: ' + (error.message || 'Failed to connect to server') 
                })) 
            };
        })
        .then(function(response) {
            // CRITICAL: Ensure response is valid before processing
            if (!response || typeof response.text !== 'function') {
                return { success: false, message: 'Upload failed: Invalid server response' };
            }
            
            // Always try to parse as JSON regardless of Content-Type
            return response.text().then(function(text) {
                var trimmed = text.trim();
                
                // CRITICAL: Handle empty responses - return object, don't throw
                if (!trimmed || trimmed.length === 0) {
                    return { success: false, message: 'Empty response from server. Please try again.' };
                }
                
                try {
                    var parsed = JSON.parse(trimmed);
                    // CRITICAL: Ensure we always return an object
                    if (!parsed || typeof parsed !== 'object') {
                        return { success: false, message: 'Invalid response format. Please try again.' };
                    }
                    return parsed;
                } catch (e) {
                    // CRITICAL: Return object instead of throwing - prevents loops
                    return { success: false, message: 'Invalid response format: ' + trimmed.substring(0, 100) };
                }
            }).catch(textError => {
                // CRITICAL: Handle text() parsing errors
                return { success: false, message: 'Upload failed: Error reading server response' };
            });
        })
        .then(function(data) {
            // CRITICAL: Ensure data exists and is an object
            if (!data || typeof data !== 'object') {
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Invalid response from server. Please try again.</span>';
                statusDiv.className = 'mt-2 text-danger';
                return;
            }
            
            if (data && data.success) {
                // Document uploaded - show simple message with View/Delete buttons
                // CRITICAL: Escape single quotes in fileId to prevent JavaScript syntax errors
                const escapedFileId = (fileId || '').replace(/'/g, "\\'");
                statusDiv.innerHTML = 
                    '<span class="text-success me-2">✅ Document uploaded</span>' +
                    '<a href="' + data.file_path + '" target="_blank" class="btn btn-sm btn-outline-primary ms-1"><i class="fas fa-eye"></i> View</a>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger ms-1" onclick="deleteDocument(\'' + escapedFileId + '\', ' + srno + ')"><i class="fas fa-trash"></i> Delete</button>';
                statusDiv.className = 'mt-2 text-success';
                
                // Clear the file input
                fileInput.value = '';
                
                // Refresh document status
                setTimeout(function() {
                    if (typeof window.checkDocumentStatus === 'function') {
                        window.checkDocumentStatus(fileId, srno);
                    } else if (typeof checkDocumentStatus === 'function') {
                        checkDocumentStatus(fileId, srno);
                    }
                }, 500);
            } else {
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">' + (data.message || 'Upload failed') + '</span>';
                statusDiv.className = 'mt-2 text-danger';
                
                // Check if redirect is needed
                if (data.redirect) {
                    setTimeout(function() {
                        window.location.href = data.redirect;
                    }, 2000);
                }
            }
        })
        .catch(function(error) {
            // CRITICAL: Handle errors gracefully - return resolved promise to prevent unhandled rejection
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">❌ Upload failed: ' + (error.message || 'Unknown error') + '</span>';
            statusDiv.className = 'mt-2 text-danger';
            
            // Return resolved promise to prevent unhandled promise rejection
            return Promise.resolve({ success: false, message: 'Upload failed: ' + (error.message || 'Unknown error') });
        });
        
    } catch (error) {
        alert('❌ Upload Function Error: ' + error.message);
    }
};

// Make deleteDocument available globally immediately
window.deleteDocument = function(fileId, srno) {
    if (!confirm('Are you sure you want to delete this document?')) {
        return;
    }
    
    var statusDiv = document.getElementById(fileId + '_status');
    
    if (!statusDiv) {
        return;
    }
    
    // Show loading state
    statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Deleting...';
    statusDiv.className = 'mt-2 text-info';
    
    fetch('?delete_doc=1&srno=' + srno, {
        method: 'GET'
    })
    .catch(error => {
        // CRITICAL: Handle fetch errors (network, CORS, etc.) - return error object
        return { ok: false, text: () => Promise.resolve(JSON.stringify({ success: false, message: 'Network error: ' + (error.message || 'Failed to connect to server') })) };
    })
    .then(function(response) {
        // CRITICAL: Ensure response is valid before processing
        if (!response || typeof response.text !== 'function') {
            return { success: false, message: 'Delete failed: Invalid server response' };
        }
        
        // CRITICAL #3: Handle empty responses in JS - return object, don't throw
            return response.text().then(function(text) {
                var trimmed = text.trim();
            
            // Check if response is HTML (starts with <!DOCTYPE or <html)
            if (trimmed.startsWith('<!DOCTYPE') || trimmed.startsWith('<html') || trimmed.startsWith('<')) {
                return { success: false, message: 'Invalid server response: Received HTML instead of JSON. Please refresh and try again.' };
            }
            
            // If empty response, return error object instead of throwing
            if (!trimmed || trimmed === '') {
                return { success: false, message: 'Empty server response. Please try again.' };
                    }
            
            // Try to parse as JSON
            try {
                return JSON.parse(trimmed);
                } catch (e) {
                // CRITICAL #5: Return error response instead of throwing
                return { success: false, message: 'Invalid server response. Please refresh and try again.' };
                }
        }).catch(textError => {
            // CRITICAL: Handle text() parsing errors
            return { success: false, message: 'Delete failed: Error reading server response' };
            });
    })
    .then(function(data) {
        // CRITICAL #3: Handle null/undefined data gracefully
        if (!data || typeof data !== 'object') {
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Invalid server response</span>';
            statusDiv.className = 'mt-2 text-danger';
            return;
        }
        
        if (data.success) {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
        } else {
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">' + (data.message || 'Delete failed') + '</span>';
            statusDiv.className = 'mt-2 text-danger';
        }
    })
    .catch(function(error) {
        // CRITICAL #5: Handle errors gracefully - return object, don't throw
        statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Delete failed: ' + (error.message || 'Unknown error') + '</span>';
        statusDiv.className = 'mt-2 text-danger';
    });
};
</script>

        <fieldset class="mb-5">
            <legend class="h5">1. Awards, Recognitions, and Fellowships</legend>
            <div class="form-group">
                <label for="awards_count" class="form-label">Number of Full-time teachers who received awards, recognition, and fellowships at the State, National, International Level from the Government & Non-Government recognized bodies</label>
                <input type="number" class="form-control" id="awards_count" name="awards_count" min="0"
                    value="<?php echo $existing_data ? ($existing_data['awards_count'] ?: 0) : ''; ?>" 
                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                    onkeypress="preventNonNumericInput(event)" oninput="generateAwardFields()">
                <small class="form-text text-muted">Enter the number and individual fields will appear below.</small>
            </div>
            <div id="awards_container">
                <!-- Dynamic award fields will be generated here -->
            </div>
            
            <!-- PDF Upload for Awards -->
            <div class="form-group mt-4 pdf-upload-section">
                <h6 class="form-label mb-3">Supporting Documents for Awards <span class="text-danger">*</span></h6>
                <div class="row">
                    <div class="col-md-6">
                        <label for="awards_state_govt" class="form-label">State Level Awards (Government Recognized Bodies)</label>
                            <div class="input-group">
                                <input type="file" class="form-control" id="awards_state_govt" name="awards_state_govt" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                                <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('awards_state_govt', 1)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                            </div>
                        <div id="awards_state_govt_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="awards_national_govt" class="form-label">National Level Awards (Government Recognized Bodies)</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="awards_national_govt" name="awards_national_govt" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('awards_national_govt', 2)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="awards_national_govt_status" class="mt-2"></div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-6">
                        <label for="awards_international_govt" class="form-label">International Level Awards (Government Recognized Bodies)</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="awards_international_govt" name="awards_international_govt" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('awards_international_govt', 3)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="awards_international_govt_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="awards_international_fellowship" class="form-label">International Fellowship for Advanced Studies/Research</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="awards_international_fellowship" name="awards_international_fellowship" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('awards_international_fellowship', 4)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="awards_international_fellowship_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </fieldset>


        <!-- Research Staff Section -->
        <fieldset class="mb-5">
            <legend class="h5">2. Research Staff Details Of University Faculty</legend>
            
            <!-- Total Research Staff Count -->
            <div class="form-group mb-4">
                <h6 class="form-label mb-3">Total Number of Research Staff</h6>
                <div class="row">
                    <div class="col-md-4">
                        <label for="research_staff_male" class="form-label">Male</label>
                        <input type="number" class="form-control" id="research_staff_male" name="research_staff_male" min="0"
                            value="<?php echo isset($existing_data['research_staff_male']) ? (int)$existing_data['research_staff_male'] : '0'; ?>" 
                            placeholder="Enter count" <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                            onkeypress="preventNonNumericInput(event)" oninput="generateResearchStaffFields()">
                    </div>
                    <div class="col-md-4">
                        <label for="research_staff_female" class="form-label">Female</label>
                        <input type="number" class="form-control" id="research_staff_female" name="research_staff_female" min="0"
                            value="<?php echo isset($existing_data['research_staff_female']) ? (int)$existing_data['research_staff_female'] : '0'; ?>" 
                            placeholder="Enter count" <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                            onkeypress="preventNonNumericInput(event)" oninput="generateResearchStaffFields()">
                    </div>
                    <div class="col-md-4">
                        <label for="research_staff_other" class="form-label">Other</label>
                        <input type="number" class="form-control" id="research_staff_other" name="research_staff_other" min="0"
                            value="<?php echo isset($existing_data['research_staff_other']) ? (int)$existing_data['research_staff_other'] : '0'; ?>" 
                            placeholder="Enter count" <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                            onkeypress="preventNonNumericInput(event)" oninput="generateResearchStaffFields()">
                    </div>
                </div>
                <small class="form-text text-muted">Enter the number of research staff and individual details will appear below.</small>
            </div>
            
            <!-- Dynamic Research Staff Details -->
            <div id="research_staff_container">
                <!-- Dynamic research staff fields will be generated here -->
            </div>
        </fieldset>



        <fieldset class="mb-5">
            <legend class="h5">3. Sponsored Research & Consultancy Projects Individual Details</legend>
            <div class="form-group">
                <label for="projects_count" class="form-label">Number of Sponsored Research & Consultancy Projects</label>
                <input type="number" class="form-control" id="projects_count" name="projects_count" min="0"
                    value="<?php echo $existing_data ? ($existing_data['projects_count'] ?: 0) : ''; ?>" 
                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                    onkeypress="preventNonNumericInput(event)" oninput="generateProjectFields()">
                <small class="form-text text-muted">Enter the number and individual fields will appear below.</small>
            </div>
            <div id="projects_container">
                <!-- Dynamic project fields will be generated here -->
            </div>
            
            <!-- PDF Upload for Projects -->
            <div class="form-group mt-4 pdf-upload-section">
                <h6 class="form-label mb-3">Supporting Documents for Projects <span class="text-danger">*</span></h6>
                <div class="row">
                    <div class="col-md-6">
                        <label for="projects_non_govt" class="form-label">Non-Government Sponsored Projects</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="projects_non_govt" name="projects_non_govt" accept=".pdf" onchange="validatePDF(this)">
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('projects_non_govt', 5)">Upload</button>
                        </div>
                        <div id="projects_non_govt_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="projects_govt" class="form-label">Government Sponsored Projects</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="projects_govt" name="projects_govt" accept=".pdf" onchange="validatePDF(this)">
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('projects_govt', 6)">Upload</button>
                        </div>
                        <div id="projects_govt_status" class="mt-2"></div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-6">
                        <label for="projects_consultancy" class="form-label">Consultancy Projects</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="projects_consultancy" name="projects_consultancy" accept=".pdf" onchange="validatePDF(this)">
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('projects_consultancy', 7)">Upload</button>
                        </div>
                        <div id="projects_consultancy_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
            
        </fieldset>

        <fieldset class="mb-5">
            <legend class="h5">4. Corporate Training Programs</legend>
            <div class="form-group">
                <label for="training_count" class="form-label">Number of Corporate Training Programs</label>
                <input type="number" class="form-control" id="training_count" name="training_count" min="0"
                    value="<?php echo $existing_data ? ($existing_data['training_count'] ?: 0) : ''; ?>" 
                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                    onkeypress="preventNonNumericInput(event)" oninput="generateTrainingFields()">
                <small class="form-text text-muted">Enter the number and individual fields will appear below.</small>
            </div>
            <div id="training_container">
                <!-- Dynamic training fields will be generated here -->
            </div>
            
            <!-- PDF Upload for Training Programs -->
            <div class="form-group mt-4 pdf-upload-section">
                <h6 class="form-label mb-3">Supporting Documents for Corporate Training Programs <span class="text-danger">*</span></h6>
                <div class="row">
                    <div class="col-md-6">
                        <label for="training_corporate" class="form-label">Corporate Training Programs Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="training_corporate" name="training_corporate" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('training_corporate', 8)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="training_corporate_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </fieldset>

        <fieldset class="mb-5">
            <legend class="h5">5. Recognitions & Infrastructure Development</legend>
            <div class="form-group mb-3">
                <h6 class="form-label mb-3">Department Recognitions by Government Agencies (UGC-SAP, DST-FIST,
                    etc.)</h6>
                <div class="form-check row m-3">
                    <input class="form-check-input row " type="checkbox" name="recognitions[]" value="UGC-SAP"
                        id="rec_ugc_sap" <?php echo $form_locked ? 'disabled' : ''; ?>>
                    <label class="form-check-label " for="rec_ugc_sap">UGC-SAP</label>
                </div>
                <div class="form-check row m-3">
                    <input class="form-check-input row" type="checkbox" name="recognitions[]" value="UGC-CAS"
                        id="rec_ugc_cas" <?php echo $form_locked ? 'disabled' : ''; ?>>
                    <label class="form-check-label" for="rec_ugc_cas">UGC-CAS</label>
                </div>
                <div class="form-check row m-3">
                    <input class="form-check-input row" type="checkbox" name="recognitions[]" value="DST-FIST"
                        id="rec_dst_fist" <?php echo $form_locked ? 'disabled' : ''; ?>>
                    <label class="form-check-label" for="rec_dst_fist">DST-FIST</label>
                </div>
                <div class="form-check row m-3">
                    <input class="form-check-input row" type="checkbox" name="recognitions[]" value="DBT" id="rec_dbt" <?php echo $form_locked ? 'disabled' : ''; ?>>
                    <label class="form-check-label" for="rec_dbt">DBT</label>
                </div>
                <div class="form-check row m-3">
                    <input class="form-check-input row" type="checkbox" name="recognitions[]" value="ICSSR"
                        id="rec_icssr" <?php echo $form_locked ? 'disabled' : ''; ?>>
                    <label class="form-check-label" for="rec_icssr">ICSSR</label>
                </div>
            </div>
            <div class="form-group">
                <label for="other_recognitions" class="form-label">Any Other Recognitions
                    (comma-separated)</label>
                <input type="text" class="form-control" id="other_recognitions" name="other_recognitions"
                    value="<?php echo htmlspecialchars($existing_data ? ($existing_data['other_recognitions'] ?: '-') : '', ENT_QUOTES, 'UTF-8'); ?>" 
                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                    placeholder="Enter any other recognitions separated by commas">
            </div>
            <div class="form-group">
                <label for="infra_funding" class="form-label">Total Funding Received for Infrastructure
                    Development
                    (in INR Lakhs)</label>
                <input type="number" step="0.01" class="form-control" id="infra_funding" name="infra_funding" min="0"
                    value="<?php echo $existing_data ? ($existing_data['infra_funding'] ?: 0) : ''; ?>" 
                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                    onkeypress="preventNonNumericInput(event)">
            </div>
            
            <!-- PDF Upload for Recognitions & Infrastructure -->
            <div class="form-group mt-4 pdf-upload-section">
                <h6 class="form-label mb-3">Supporting Documents for Recognitions & Infrastructure <span class="text-danger">*</span></h6>
                <div class="row">
                    <div class="col-md-6">
                        <label for="recognitions_infra" class="form-label">Department Recognitions & Infrastructure Funding</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="recognitions_infra" name="recognitions_infra" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('recognitions_infra', 9)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="recognitions_infra_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
            
        </fieldset>

        <fieldset class="mb-5">
            <legend class="h5">6. Innovation & Startup Ecosystem Details</legend>
            
            <!-- Important Instructions -->
            <div class="alert alert-info mb-4">
                <h6><i class="fas fa-info-circle me-2"></i>Important Instructions:</h6>
                <ul class="mb-2">
                    <li><strong>Enter values in all fields:</strong> If not applicable, enter zero (0)</li>
                    <li><strong>DPIIT Recognition:</strong> Only startups recognized by DPIIT Startup India</li>
                    <li><strong>Investment Data:</strong> Include all VC, Seed, and FDI investments received</li>
                    <li><strong>Technology Readiness:</strong> Based on TRL levels 1-9</li>
                    <li><strong>Turnover Achievement:</strong> Startups with ₹50+ lakhs turnover only</li>
                </ul>
            </div>

            <!-- DPIIT Startup Recognition -->
            <div class="mb-4 innovation-section">
                <div class="section-header">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-building me-2"></i>🏢 DPIIT Startup Recognition
                        <span class="badge bg-primary ms-2">DPIIT</span>
                    </h6>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="total_dpiit_startups" class="form-label"><b>Total DPIIT Recognized Startups</b></label>
                        <input type="number" class="form-control" id="total_dpiit_startups" name="total_dpiit_startups" min="0" 
                               value="<?php echo $existing_data ? ($existing_data['total_dpiit_startups'] ?: 0) : ''; ?>" 
                               <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                               onkeypress="preventNonNumericInput(event)" oninput="generateDpiitFields()">
                    </div>
                </div>
                <div id="dpiit_startups_container" class="dynamic-container">
                    <!-- Dynamic fields will be generated here -->
                </div>
            </div>

            <!-- VC Investment -->
            <div class="mb-4 innovation-section">
                <div class="section-header">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-chart-line me-2"></i>💰 VC Investment
                        <span class="badge bg-success ms-2">VC</span>
                    </h6>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="total_vc_investments" class="form-label"><b>Total VC Investments</b></label>
                        <input type="number" class="form-control" id="total_vc_investments" name="total_vc_investments" min="0" 
                               value="<?php echo $existing_data ? ($existing_data['total_vc_investments'] ?: 0) : ''; ?>" 
                               <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                               onkeypress="preventNonNumericInput(event)" oninput="generateVcFields()">
                    </div>
                </div>
                <div id="vc_investments_container" class="dynamic-container">
                    <!-- Dynamic fields will be generated here -->
                </div>
            </div>

            <!-- Seed Funding -->
            <div class="mb-4 innovation-section">
                <div class="section-header">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-seedling me-2"></i>🌱 Seed Funding
                        <span class="badge bg-info ms-2">SEED</span>
                    </h6>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="total_seed_funding" class="form-label"><b>Total Seed Funding</b></label>
                        <input type="number" class="form-control" id="total_seed_funding" name="total_seed_funding" min="0" 
                               value="<?php echo $existing_data ? ($existing_data['total_seed_funding'] ?: 0) : ''; ?>" 
                               <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                               onkeypress="preventNonNumericInput(event)" oninput="generateSeedFields()">
                    </div>
                </div>
                <div id="seed_funding_container" class="dynamic-container">
                    <!-- Dynamic fields will be generated here -->
                </div>
            </div>

            <!-- FDI Investment -->
            <div class="mb-4 innovation-section">
                <div class="section-header">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-globe me-2"></i>🌍 FDI Investment
                        <span class="badge bg-warning ms-2">FDI</span>
                    </h6>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="total_fdi_investments" class="form-label"><b>Total FDI Investments</b></label>
                        <input type="number" class="form-control" id="total_fdi_investments" name="total_fdi_investments" min="0" 
                               value="<?php echo $existing_data ? ($existing_data['total_fdi_investments'] ?: 0) : ''; ?>" 
                               <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                               onkeypress="preventNonNumericInput(event)" oninput="generateFdiFields()">
                    </div>
                </div>
                <div id="fdi_investments_container" class="dynamic-container">
                    <!-- Dynamic fields will be generated here -->
                </div>
            </div>

            <!-- Innovation Grants -->
            <div class="mb-4 innovation-section">
                <div class="section-header">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-landmark me-2"></i>🏛️ Innovation Grants from Government
                        <span class="badge bg-secondary ms-2">GRANTS</span>
                    </h6>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="total_innovation_grants" class="form-label"><b>Total Innovation Grants</b></label>
                        <input type="number" class="form-control" id="total_innovation_grants" name="total_innovation_grants" min="0" 
                               value="<?php echo $existing_data ? ($existing_data['total_innovation_grants'] ?: 0) : ''; ?>" 
                               <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                               onkeypress="preventNonNumericInput(event)" oninput="generateGrantFields()">
                    </div>
                </div>
                <div id="innovation_grants_container" class="dynamic-container">
                    <!-- Dynamic fields will be generated here -->
                </div>
            </div>

            <!-- Technology Readiness Levels -->
            <div class="mb-4 innovation-section">
                <div class="section-header">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-microscope me-2"></i>🔬 Technology Readiness Levels
                        <span class="badge bg-dark ms-2">TRL</span>
                    </h6>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="total_trl_innovations" class="form-label"><b>Total TRL Innovations</b></label>
                        <input type="number" class="form-control" id="total_trl_innovations" name="total_trl_innovations" min="0" 
                               value="<?php echo $existing_data ? ($existing_data['total_trl_innovations'] ?: 0) : ''; ?>" 
                               <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                               onkeypress="preventNonNumericInput(event)" oninput="generateTrlFields()">
                    </div>
                </div>
                <div id="trl_innovations_container" class="dynamic-container">
                    <!-- Dynamic fields will be generated here -->
                </div>
            </div>

            <!-- Turnover Achievement -->
            <div class="mb-4 innovation-section">
                <div class="section-header">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-trophy me-2"></i>📈 Turnover Achievement (₹50+ Lakhs)
                        <span class="badge bg-danger ms-2">TURNOVER</span>
                    </h6>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="total_turnover_achievements" class="form-label"><b>Total Turnover Achievements</b></label>
                        <input type="number" class="form-control" id="total_turnover_achievements" name="total_turnover_achievements" min="0" 
                               value="<?php echo $existing_data ? ($existing_data['total_turnover_achievements'] ?: 0) : ''; ?>" 
                               <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                               onkeypress="preventNonNumericInput(event)" oninput="generateTurnoverFields()">
                    </div>
                </div>
                <div id="turnover_achievements_container" class="dynamic-container">
                    <!-- Dynamic fields will be generated here -->
                </div>
            </div>

            <!-- Alumni Forbes 500 -->
            <div class="mb-4 innovation-section">
                <div class="section-header">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-graduation-cap me-2"></i>🎓 Alumni Forbes 500 Founders
                        <span class="badge bg-purple ms-2">FORBES</span>
                    </h6>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="total_forbes_alumni" class="form-label"><b>Total Forbes Alumni</b></label>
                        <input type="number" class="form-control" id="total_forbes_alumni" name="total_forbes_alumni" min="0" 
                               value="<?php echo $existing_data ? ($existing_data['total_forbes_alumni'] ?: 0) : ''; ?>" 
                               <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                               onkeypress="preventNonNumericInput(event)" oninput="generateForbesFields()">
                    </div>
                </div>
                <div id="forbes_alumni_container" class="dynamic-container">
                    <!-- Dynamic fields will be generated here -->
                </div>
            </div>
            
            <!-- PDF Upload for Innovation & Startup Data -->
            <div class="form-group mt-4 pdf-upload-section">
                <h6 class="form-label mb-3">Supporting Documents for Innovation & Startup Ecosystem <span class="text-danger">*</span></h6>
                <div class="alert alert-warning">
                    <strong>Document Requirements:</strong>
                    <ul class="mb-0">
                        <li>DPIIT Recognition certificates</li>
                        <li>Investment agreements and proof of funding</li>
                        <li>Government grant letters</li>
                        <li>Technology readiness level documentation</li>
                        <li>Turnover certificates and financial statements</li>
                        <li>Alumni founder verification documents</li>
                    </ul>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="dpiit_certificates" class="form-label">DPIIT Recognition Certificates</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="dpiit_certificates" name="dpiit_certificates" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('dpiit_certificates', 10)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="dpiit_certificates_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="investment_agreements" class="form-label">Investment Agreements & Proof of Funding</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="investment_agreements" name="investment_agreements" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('investment_agreements', 11)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="investment_agreements_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="grant_letters" class="form-label">Government Grant Letters</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="grant_letters" name="grant_letters" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('grant_letters', 12)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="grant_letters_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="trl_documentation" class="form-label">Technology Readiness Level Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="trl_documentation" name="trl_documentation" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('trl_documentation', 13)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="trl_documentation_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="turnover_certificates" class="form-label">Turnover Certificates & Financial Statements</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="turnover_certificates" name="turnover_certificates" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('turnover_certificates', 14)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="turnover_certificates_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="alumni_verification" class="form-label">Alumni Founder Verification Documents</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="alumni_verification" name="alumni_verification" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('alumni_verification', 15)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="alumni_verification_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </fieldset>
            <fieldset class="mb-5">
            <legend class="h5">7. Patent Details for NIRF 2025 Verification</legend>
            
            <!-- Important Notices -->
            <div class="alert alert-info mb-4">
                <h6><i class="fas fa-info-circle me-2"></i>Important Guidelines for Patent Submission:</h6>
                <ul class="mb-2">
                    <li><strong>Only Utility Patents</strong> (Published & Granted during 2022-2024) should be provided</li>
                    <li><strong>Exclude:</strong> Design Patents, Trademarks, Copyrights, and Filed Patents only</li>
                    <li><strong>Proof Required:</strong> Submit source proofs (screenshots, PDFs, images) from databases like InPASS, WIPO, USPTO, Espacenet, Derwent Innovation, etc.</li>
                    <li><strong>URL Links:</strong> Provide direct URL/Website links to patent documents</li>
                </ul>
            </div>

            <!-- Summary Counts -->
            <div class="row mb-4">
                <div class="col-md-3 form-group">
                    <label for="patents_published_2022" class="form-label">Published 2022</label>
                    <input type="number" class="form-control" id="patents_published_2022" name="patents_published_2022" min="0"
                        value="<?php echo $existing_data ? ($existing_data['patents_published_2022'] ?: 0) : ''; ?>" 
                        <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                        onkeypress="preventNonNumericInput(event)" oninput="calculatePatentTotals()">
                </div>
                <div class="col-md-3 form-group">
                    <label for="patents_published_2023" class="form-label">Published 2023</label>
                    <input type="number" class="form-control" id="patents_published_2023" name="patents_published_2023" min="0"
                        value="<?php echo $existing_data ? ($existing_data['patents_published_2023'] ?: 0) : ''; ?>" 
                        <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                        onkeypress="preventNonNumericInput(event)" oninput="calculatePatentTotals()">
                </div>
                <div class="col-md-3 form-group">
                    <label for="patents_published_2024" class="form-label">Published 2024</label>
                    <input type="number" class="form-control" id="patents_published_2024" name="patents_published_2024" min="0"
                        value="<?php echo $existing_data ? ($existing_data['patents_published_2024'] ?: 0) : ''; ?>" 
                        <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                        onkeypress="preventNonNumericInput(event)" oninput="calculatePatentTotals()">
                </div>
                <div class="col-md-3 form-group">
                    <label for="patents_granted_2022" class="form-label">Granted 2022</label>
                    <input type="number" class="form-control" id="patents_granted_2022" name="patents_granted_2022" min="0"
                        value="<?php echo $existing_data ? ($existing_data['patents_granted_2022'] ?: 0) : ''; ?>" 
                        <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                        onkeypress="preventNonNumericInput(event)" oninput="calculatePatentTotals()">
                </div>
            </div>
            <div class="row mb-4">
                <div class="col-md-3 form-group">
                    <label for="patents_granted_2023" class="form-label">Granted 2023</label>
                    <input type="number" class="form-control" id="patents_granted_2023" name="patents_granted_2023" min="0"
                        value="<?php echo $existing_data ? ($existing_data['patents_granted_2023'] ?: 0) : ''; ?>" 
                        <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                        onkeypress="preventNonNumericInput(event)" oninput="calculatePatentTotals()">
                </div>
                <div class="col-md-3 form-group">
                    <label for="patents_granted_2024" class="form-label">Granted 2024</label>
                    <input type="number" class="form-control" id="patents_granted_2024" name="patents_granted_2024" min="0"
                        value="<?php echo $existing_data ? ($existing_data['patents_granted_2024'] ?: 0) : ''; ?>" 
                        <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                        onkeypress="preventNonNumericInput(event)" oninput="calculatePatentTotals()">
                </div>
                <div class="col-md-3 form-group">
                    <label for="total_published" class="form-label">Total Published (2022-2024)</label>
                    <input type="number" class="form-control" id="total_published" name="total_published" min="0" readonly disabled
                        value="<?php echo $existing_data ? ($existing_data['total_published'] ?: 0) : '0'; ?>" 
                        style="background-color: #f8f9fa; cursor: not-allowed;" tabindex="-1">
                    <small class="form-text text-muted">System calculated - cannot be edited</small>
                </div>
                <div class="col-md-3 form-group">
                    <label for="total_granted" class="form-label">Total Granted (2022-2024)</label>
                    <input type="number" class="form-control" id="total_granted" name="total_granted" min="0" readonly disabled
                        value="<?php echo $existing_data ? ($existing_data['total_granted'] ?: 0) : '0'; ?>" 
                        style="background-color: #f8f9fa; cursor: not-allowed;" tabindex="-1">
                    <small class="form-text text-muted">System calculated - cannot be edited</small>
                </div>
            </div>
            
            <!-- Additional IPR Fields -->
            <div class="row mb-4">
                <div class="col-md-3 form-group">
                    <label for="copyrights_published" class="form-label">Number of Copyrights Published</label>
                    <input type="number" class="form-control" id="copyrights_published" name="copyrights_published" min="0"
                        value="<?php echo $existing_data ? ($existing_data['copyrights_published'] ?: 0) : ''; ?>" 
                        <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                        onkeypress="preventNonNumericInput(event)">
                </div>
                <div class="col-md-3 form-group">
                    <label for="copyrights_granted" class="form-label">Number of Copyrights Granted</label>
                    <input type="number" class="form-control" id="copyrights_granted" name="copyrights_granted" min="0"
                        value="<?php echo $existing_data ? ($existing_data['copyrights_granted'] ?: 0) : ''; ?>" 
                        <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                        onkeypress="preventNonNumericInput(event)">
                </div>
                <div class="col-md-3 form-group">
                    <label for="designs_published" class="form-label">Number of Designs Published</label>
                    <input type="number" class="form-control" id="designs_published" name="designs_published" min="0"
                        value="<?php echo $existing_data ? ($existing_data['designs_published'] ?: 0) : ''; ?>" 
                        <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                        onkeypress="preventNonNumericInput(event)">
                </div>
                <div class="col-md-3 form-group">
                    <label for="designs_granted" class="form-label">Number of Designs Granted</label>
                    <input type="number" class="form-control" id="designs_granted" name="designs_granted" min="0"
                        value="<?php echo $existing_data ? ($existing_data['designs_granted'] ?: 0) : ''; ?>" 
                        <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                        onkeypress="preventNonNumericInput(event)">
                </div>
            </div>
            <div class="row mb-4">
                <div class="col-md-6 form-group">
                    <label for="tot_count" class="form-label">Number of ToT (Transfer of Technology)</label>
                    <input type="number" class="form-control" id="tot_count" name="tot_count" min="0"
                        value="<?php echo $existing_data ? ($existing_data['tot_count'] ?: 0) : ''; ?>" 
                        <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                        onkeypress="preventNonNumericInput(event)">
                </div>
            </div>

            <!-- Dynamic Patent Details -->
            <div class="form-group">
                <label for="patents_count" class="form-label">Number of Patent Details to Submit</label>
                <input type="number" class="form-control" id="patents_count" name="patents_count" min="0" readonly disabled
                    value="<?php echo $existing_data ? ($existing_data['patents_count'] ?: 0) : ''; ?>" 
                    style="background-color: #f8f9fa;">
                <small class="form-text text-muted">This field is automatically calculated based on the total number of published and granted patents.</small>
            </div>
            <div id="patents_container">
                <!-- Dynamic patent fields will be generated here -->
            </div>
            
            <!-- PDF Upload for Patents -->
            <div class="form-group mt-4 pdf-upload-section">
                <h6 class="form-label mb-3">Supporting Documents for Patents & IPR <span class="text-danger">*</span></h6>
                <div class="row">
                    <div class="col-md-6">
                        <label for="patents_ipr" class="form-label">IPR (Patents/Copyright/Trademarks/Designs) & ToT</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="patents_ipr" name="patents_ipr" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('patents_ipr', 16)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="patents_ipr_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="patents_filed" class="form-label">Patents Filed Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="patents_filed" name="patents_filed" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('patents_filed', 17)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="patents_filed_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="patents_published" class="form-label">Patents Published Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="patents_published" name="patents_published" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('patents_published', 18)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="patents_published_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="patents_granted" class="form-label">Patents Granted Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="patents_granted" name="patents_granted" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('patents_granted', 19)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="patents_granted_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="copyrights_docs" class="form-label">Copyrights Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="copyrights_docs" name="copyrights_docs" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('copyrights_docs', 20)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="copyrights_docs_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="designs_docs" class="form-label">Designs Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="designs_docs" name="designs_docs" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('designs_docs', 21)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="designs_docs_status" class="mt-2"></div>
                    </div>
                    </div>
                </div>
            </div>
        </fieldset>

        <fieldset class="mb-5">
            <legend class="h5">8. Research Publications (Journals, Conferences, UGC Listed, ISSN, Art Exhibitions & More)</legend>
            <div class="form-group">
                <label for="publications_count" class="form-label">Number of Research Publications</label>
                <?php
                // CRITICAL: Use actual count from JSON data if available, fallback to database field
                // Use the higher value if both exist to ensure count is preserved
                $publications_count_display = 0;
                $json_count = 0;
                $db_count = 0;
                
                if ($existing_data && !empty($existing_data['publications'])) {
                    $pub_data = json_decode($existing_data['publications'], true);
                    if ($pub_data && is_array($pub_data)) {
                        $json_count = count($pub_data);
                    }
                }
                if ($existing_data && isset($existing_data['publications_count'])) {
                    $db_count = (int)$existing_data['publications_count'];
                }
                
                // Use the maximum of both to ensure count is preserved
                $publications_count_display = max($json_count, $db_count);
                ?>
                <input type="number" class="form-control" id="publications_count" name="publications_count" min="0"
                    value="<?php echo $publications_count_display; ?>" 
                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                    onkeypress="preventNonNumericInput(event)" oninput="generatePublicationFields()">
                <small class="form-text text-muted">Include: Scopus/Web of Sciences journals, UGC listed non-Scopus journals, ISSN journals, special issue articles, art exhibitions, theatre performances, and self-learning materials. Enter the number and individual fields will appear below.</small>
            </div>
            <div id="publications_container">
                <!-- Dynamic publication fields will be generated here -->
            </div>
            
            <!-- PDF Upload for Publications -->
            <div class="form-group mt-4 pdf-upload-section">
                <h6 class="form-label mb-3">Supporting Documents for Publications <span class="text-danger">*</span></h6>
                <div class="row">
                    <div class="col-md-6">
                        <label for="publications_scopus" class="form-label">Scopus/Web of Sciences Journals</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="publications_scopus" name="publications_scopus" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('publications_scopus', 22)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="publications_scopus_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="publications_conference" class="form-label">Conference Publications</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="publications_conference" name="publications_conference" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('publications_conference', 23)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="publications_conference_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="publications_ugc" class="form-label">UGC Listed Non-Scopus Journals</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="publications_ugc" name="publications_ugc" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('publications_ugc', 24)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="publications_ugc_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="publications_issn" class="form-label">ISSN Journals & Special Issues</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="publications_issn" name="publications_issn" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('publications_issn', 25)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="publications_issn_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="publications_art" class="form-label">Art Exhibitions & Theatre Performances</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="publications_art" name="publications_art" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('publications_art', 26)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="publications_art_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </fieldset>

        <fieldset class="mb-5">
            <legend class="h5">9. Bibliometrics & h-index (Per Teacher)</legend>
            <div class="form-group">
                <label for="bibliometrics_count" class="form-label">Number of Teachers with Bibliometric Data</label>
                <?php
                // CRITICAL: Use actual count from JSON data if available, fallback to database field
                // Use the higher value if both exist to ensure count is preserved
                $bibliometrics_count_display = 0;
                $json_count = 0;
                $db_count = 0;
                
                if ($existing_data && !empty($existing_data['bibliometrics'])) {
                    $bib_data = json_decode($existing_data['bibliometrics'], true);
                    if ($bib_data && is_array($bib_data)) {
                        $json_count = count($bib_data);
                    }
                }
                if ($existing_data && isset($existing_data['bibliometrics_count'])) {
                    $db_count = (int)$existing_data['bibliometrics_count'];
                }
                
                // Use the maximum of both to ensure count is preserved
                $bibliometrics_count_display = max($json_count, $db_count);
                ?>
                <input type="number" class="form-control" id="bibliometrics_count" name="bibliometrics_count" min="0"
                    value="<?php echo $bibliometrics_count_display; ?>" 
                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                    onkeypress="preventNonNumericInput(event)" oninput="generateBibliometricFields()">
                <small class="form-text text-muted">Enter the number and individual fields will appear below.</small>
            </div>
            <div id="bibliometrics_container">
                <!-- Dynamic bibliometric fields will be generated here -->
            </div>
            
            <!-- PDF Upload for Bibliometrics & h-index -->
            <div class="form-group mt-4 pdf-upload-section">
                <h6 class="form-label mb-3">Supporting Documents for Bibliometrics & h-index <span class="text-danger">*</span></h6>
                <div class="row">
                    <div class="col-md-6">
                        <label for="bibliometrics_impact" class="form-label">Impact Factor & Bibliometrics</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="bibliometrics_impact" name="bibliometrics_impact" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('bibliometrics_impact', 27)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="bibliometrics_impact_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="bibliometrics_hindex" class="form-label">h-index Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="bibliometrics_hindex" name="bibliometrics_hindex" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('bibliometrics_hindex', 28)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="bibliometrics_hindex_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </fieldset>

        <fieldset class="mb-5">
            <legend class="h5">10. Books, Chapters, and MOOCs</legend>
            <div class="form-group">
                <label for="books_count" class="form-label">Number of Books/Chapters/MOOCs (Authored, Edited, or Created)</label>
                <?php
                // CRITICAL: Use actual count from JSON data if available, fallback to database field
                // Use the higher value if both exist to ensure count is preserved
                $books_count_display = 0;
                $json_count = 0;
                $db_count = 0;
                
                if ($existing_data && !empty($existing_data['books'])) {
                    $book_data = json_decode($existing_data['books'], true);
                    if ($book_data && is_array($book_data)) {
                        $json_count = count($book_data);
                    }
                }
                if ($existing_data && isset($existing_data['books_count'])) {
                    $db_count = (int)$existing_data['books_count'];
                }
                
                // Use the maximum of both to ensure count is preserved
                $books_count_display = max($json_count, $db_count);
                ?>
                <input type="number" class="form-control" id="books_count" name="books_count" min="0"
                    value="<?php echo $books_count_display; ?>" 
                    <?php echo $form_locked ? 'readonly disabled' : ''; ?>
                    onkeypress="preventNonNumericInput(event)" oninput="generateBookFields()">
                <small class="form-text text-muted">Enter the number of books authored, chapters written, or MOOCs created within the current academic year. Individual fields will appear below.</small>
            </div>
            <div id="books_container">
                <!-- Dynamic book fields will be generated here -->
            </div>
            
            <!-- PDF Upload for Books -->
            <div class="form-group mt-4 pdf-upload-section">
                <h6 class="form-label mb-3">Supporting Documents for Books & MOOCs <span class="text-danger">*</span></h6>
                <div class="row">
                    <div class="col-md-6">
                        <label for="books_moocs" class="form-label">Books, Chapters & MOOCs Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="books_moocs" name="books_moocs" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('books_moocs', 29)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="books_moocs_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </fieldset>

        <fieldset class="mb-5">
            <legend class="h5">11. Descriptive Summaries & Future Plans</legend>
            <div class="form-group">
                <label for="desc_initiative" class="form-label">Major research or innovation initiative <span class="text-danger">*</span></label>
                <textarea class="form-control" id="desc_initiative" name="desc_initiative" rows="6" maxlength="1000"
                    oninput="updateCounter(this, 'counter1')" required placeholder="Describe your major research or innovation initiative..." <?php echo $form_locked ? 'readonly disabled' : ''; ?>><?php echo htmlspecialchars($existing_data ? ($existing_data['desc_initiative'] ?: '-') : '-', ENT_QUOTES, 'UTF-8'); ?></textarea>
                <div id="counter1" class="char-counter">1000 characters remaining</div>
            </div>
            <div class="form-group">
                <label for="desc_impact" class="form-label">Measurable impact of the initiative <span class="text-danger">*</span></label>
                <textarea class="form-control" id="desc_impact" name="desc_impact" rows="6" maxlength="1000"
                    oninput="updateCounter(this, 'counter2')" required placeholder="Describe the measurable impact of your initiative..." <?php echo $form_locked ? 'readonly disabled' : ''; ?>><?php echo htmlspecialchars($existing_data ? ($existing_data['desc_impact'] ?: '-') : '-', ENT_QUOTES, 'UTF-8'); ?></textarea>
                <div id="counter2" class="char-counter">1000 characters remaining</div>
            </div>
            <div class="form-group">
                <label for="desc_collaboration" class="form-label">Industry collaboration models <span class="text-danger">*</span></label>
                <textarea class="form-control" id="desc_collaboration" name="desc_collaboration" rows="6"
                    maxlength="1000" oninput="updateCounter(this, 'counter3')" required placeholder="Describe your industry collaboration models..." <?php echo $form_locked ? 'readonly disabled' : ''; ?>><?php echo htmlspecialchars($existing_data ? ($existing_data['desc_collaboration'] ?: '-') : '-', ENT_QUOTES, 'UTF-8'); ?></textarea>
                <div id="counter3" class="char-counter">1000 characters remaining</div>
            </div>
            <div class="form-group">
                <label for="desc_plan" class="form-label">Plan to sustain and scale the initiative (next 2
                    years) <span class="text-danger">*</span></label>
                <textarea class="form-control" id="desc_plan" name="desc_plan" rows="6" maxlength="1000"
                    oninput="updateCounter(this, 'counter4')" required placeholder="Describe your plan to sustain and scale the initiative..." <?php echo $form_locked ? 'readonly disabled' : ''; ?>><?php echo htmlspecialchars($existing_data ? ($existing_data['desc_plan'] ?: '-') : '-', ENT_QUOTES, 'UTF-8'); ?></textarea>
                <div id="counter4" class="char-counter">1000 characters remaining</div>
            </div>
            <div class="form-group">
                <label for="desc_recognition" class="form-label">Why should your department be recognized for
                    Excellence in Research & Innovation? <span class="text-danger">*</span></label>
                <textarea class="form-control" id="desc_recognition" name="desc_recognition" rows="8" maxlength="2000"
                    oninput="updateCounter(this, 'counter5')" required placeholder="Explain why your department should be recognized for excellence in research & innovation..." <?php echo $form_locked ? 'readonly disabled' : ''; ?>><?php echo htmlspecialchars($existing_data ? ($existing_data['desc_recognition'] ?: '-') : '-', ENT_QUOTES, 'UTF-8'); ?></textarea>
                <div id="counter5" class="char-counter">2000 characters remaining</div>
            </div>
            
            <!-- PDF Upload for Descriptive Summaries -->
            <div class="form-group mt-4 pdf-upload-section">
                <h6 class="form-label mb-3">Supporting Documents for Descriptive Summaries <span class="text-danger">*</span></h6>
                <div class="row">
                    <div class="col-md-6">
                        <label for="desc_initiative_doc" class="form-label">Research Initiative Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="desc_initiative_doc" name="desc_initiative_doc" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('desc_initiative_doc', 30)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="desc_initiative_doc_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="desc_collaboration_doc" class="form-label">Industry Collaboration Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="desc_collaboration_doc" name="desc_collaboration_doc" accept=".pdf" onchange="validatePDF(this)" <?php echo $form_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('desc_collaboration_doc', 31)" <?php echo $form_locked ? 'disabled' : ''; ?>><?php echo $form_locked ? 'View PDF' : 'Upload'; ?></button>
                        </div>
                        <div id="desc_collaboration_doc_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </fieldset>


        <div class="text-center">
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <?php if ($form_locked): ?>
                    <!-- Form is locked - show Update and Clear Data buttons -->
                    <button type="button" class="btn btn-warning btn-lg px-5" id="updateBtn" onclick="if(typeof enableUpdate !== 'undefined' && typeof enableUpdate === 'function') { enableUpdate(); } else { alert('Please wait for page to fully load.'); }">
                        <i class="fas fa-edit me-2"></i>Update Data
                    </button>
                    <button type="submit" name="update_faculty" class="btn btn-success btn-lg px-5" id="saveBtn" style="display:none;">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                    <button type="button" class="btn btn-secondary btn-lg px-5" id="cancelBtn" style="display:none;" onclick="if(typeof cancelUpdate !== 'undefined' && typeof cancelUpdate === 'function') { cancelUpdate(); } else { location.reload(); }">
                        <i class="fas fa-times me-2"></i>Cancel Update
                    </button>
                    <a href="?action=clear_data" class="btn btn-danger btn-lg px-5" id="clearBtn"
                       onclick="return confirmClearData()">
                        <i class="fas fa-trash me-2"></i>Clear Data
                    </a>
                <?php else: ?>
                    <!-- Form is unlocked - show Submit button -->
                    <button type="submit" name="save_faculty" class="btn btn-primary btn-lg px-5">
                        <i class="fas fa-paper-plane me-2"></i>Submit Data
                    </button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Hidden JSON fields for dynamic sections 8, 9, 10 -->
        <textarea name="publications_json" id="publications_json" style="display:none;"></textarea>
        <textarea name="bibliometrics_json" id="bibliometrics_json" style="display:none;"></textarea>
        <textarea name="books_json" id="books_json" style="display:none;"></textarea>
            </form>
        </div>
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
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.05);
        position: relative;
        transition: all 0.3s ease;
    }
    
    fieldset:hover {
        box-shadow: 0 0.75rem 1.5rem rgba(0, 0, 0, 0.08);
        border-color: #d1d5db;
    }

    legend {
        font-size: 1.4rem;
        font-weight: 700;
        color: #1a365d;
        width: auto;
        padding: 0.75rem 1.5rem;
        border-bottom: none;
        white-space: nowrap;
        overflow: visible;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white !important;
        border-radius: 0.75rem;
        box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.1);
        margin-bottom: 1.5rem;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    .glass-card {
        background: #fff;
        border-radius: 1.5rem;
        box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.1);
        padding: 3rem 2.5rem;
        margin-bottom: 2rem;
        border: 1px solid #e3e6ea;
    }

    .modern-form {
        width: 100%;
    }

    .page-header {
        text-align: center;
        margin-bottom: 2rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid #e3e6ea;
    }

    .page-title {
        font-size: 2.5rem;
        font-weight: 700;
        color: inherit;
        margin: 0;
    }

    .page-title i {
        color: #3498db;
    }

    /* Ensure all h5 elements in legends have proper styling */
    legend.h5 {
        color: white !important;
        font-weight: 700;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
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
        background-color: #ffffff !important;
        color: #212529 !important;
        cursor: text !important;
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
    
    /* Checkbox styling */
    .form-check-input {
        width: 1.25rem !important;
        height: 1.25rem !important;
        margin-top: 0.125rem !important;
        margin-right: 0.5rem !important;
        cursor: pointer !important;
        flex-shrink: 0 !important;
    }
    
    .form-check-input:checked {
        background-color: #667eea !important;
        border-color: #667eea !important;
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25) !important;
    }
    
    .form-check-input:checked[type="checkbox"] {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='none' stroke='%23fff' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='m6 10 3 3 6-6'/%3e%3c/svg%3e") !important;
    }
    
    .form-check-input:focus {
        border-color: #667eea !important;
        outline: 0 !important;
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25) !important;
    }
    
    .form-check-input:hover {
        border-color: #cbd5e0 !important;
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

    .input-group-text {
        background-color: #e9ecef;
        border-color: #ced4da;
        font-weight: 500;
        min-width: 40px;
        justify-content: center;
    }

    .dynamic-entry {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border: 2px solid #e3e6ea;
        border-radius: 1rem;
        margin-bottom: 2rem;
        padding: 2rem 1.5rem 1.5rem 1.5rem;
        position: relative;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
    }

    .dynamic-entry:hover {
        box-shadow: 0 1rem 2rem rgba(102, 126, 234, 0.1);
        border-color: #667eea;
        transform: translateY(-2px);
    }

    .dynamic-entry h5 {
        font-size: 1.2rem;
        font-weight: 700;
        color: #667eea;
        margin-bottom: 1.5rem;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    .patent-item {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border: 2px solid #e3e6ea;
        border-radius: 1rem;
        margin-bottom: 2rem;
        padding: 2rem 1.5rem 1.5rem 1.5rem;
        position: relative;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
    }

    .patent-item:hover {
        box-shadow: 0 1rem 2rem rgba(102, 126, 234, 0.1);
        border-color: #667eea;
        transform: translateY(-2px);
    }

    .patent-item h6 {
        font-size: 1.1rem;
        font-weight: 700;
        color: #667eea;
        margin-bottom: 1.5rem;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    .remove-btn,
    .dynamic-entry .btn-danger {
        position: absolute;
        top: 1rem;
        right: 1rem;
        z-index: 2;
    }


    .char-counter {
        font-size: 0.92em;
        color: #888;
        margin-top: 0.25rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }
    
    .form-group label {
        font-weight: 600;
        color: #2d3748;
        margin-bottom: 0.5rem;
        display: block;
    }
    
    .form-text {
        font-size: 0.875rem;
        color: #6b7280;
        margin-top: 0.25rem;
    }

    /* Innovation Section Styling */
    .innovation-section {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border: 2px solid #e3e6ea;
        border-radius: 1rem;
        padding: 2rem;
        margin-bottom: 2rem;
        transition: all 0.3s ease;
    }

    .innovation-section:hover {
        box-shadow: 0 1rem 2rem rgba(102, 126, 234, 0.1);
        border-color: #667eea;
        transform: translateY(-2px);
    }

    .section-header {
        border-bottom: 2px solid #e3e6ea;
        padding-bottom: 1rem;
        margin-bottom: 1.5rem;
    }

    .section-header h6 {
        font-size: 1.3rem;
        font-weight: 700;
        color: #1a365d;
        margin: 0;
    }

    .dynamic-container {
        min-height: 50px;
    }

    .badge {
        font-size: 0.75rem;
        padding: 0.5rem 0.75rem;
        border-radius: 0.5rem;
    }

    .bg-purple {
        background-color: #6f42c1 !important;
    }

    /* Enhanced form styling for innovation sections */
    .innovation-section .form-control {
        border: 2px solid #e3e6ea;
        border-radius: 0.75rem;
        padding: 0.75rem 1rem;
        font-size: 0.95rem;
        transition: all 0.3s ease;
    }

    .innovation-section .form-control:focus {
        border-color: #667eea !important;
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25) !important;
    }

    .innovation-section .form-label {
        font-weight: 600;
        color: #2d3748;
        margin-bottom: 0.5rem;
    }

    .innovation-section .form-text {
        font-size: 0.8rem;
        color: #6b7280;
        margin-top: 0.25rem;
        font-style: italic;
    }

    .char-counter {
        font-size: 0.875rem;
        color: #6b7280;
        margin-top: 0.25rem;
        font-weight: 500;
    }

    /* Additional modern touches */
    .alert {
        border-radius: 1rem;
        border: none;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
    }
    
    .alert-success {
        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
        color: #155724;
    }
    
    .alert-danger {
        background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
        color: #721c24;
    }
    
    .alert-info {
        background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
        color: #0c5460;
    }
    
    .text-center h1 {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        font-weight: 800;
        margin-bottom: 1rem;
    }
    
    .text-muted {
        color: #6b7280 !important;
        font-size: 1.1rem;
    }

    /* PDF Upload Styling */
    .pdf-upload-section {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border: 2px solid #e3e6ea;
        border-radius: 1rem;
        padding: 1.5rem;
        margin-top: 1rem;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.05);
    }
    
    .pdf-upload-section .form-label {
        font-weight: 600;
        color: #2d3748;
        margin-bottom: 0.5rem;
    }
    
    .pdf-upload-section .input-group {
        margin-bottom: 0.5rem;
    }
    
    .pdf-upload-section .btn-outline-primary {
        border-color: #667eea;
        color: #667eea;
        transition: all 0.3s ease;
    }
    
    .pdf-upload-section .btn-outline-primary:hover {
        background-color: #667eea;
        border-color: #667eea;
        color: white;
    }
    
    .pdf-upload-section .btn-outline-danger {
        border-color: #dc3545;
        color: #dc3545;
        transition: all 0.3s ease;
    }
    
    .pdf-upload-section .btn-outline-danger:hover {
        background-color: #dc3545;
        border-color: #dc3545;
        color: white;
    }
    
    .pdf-upload-section .btn-outline-primary {
        border-color: #007bff;
        color: #007bff;
        transition: all 0.3s ease;
    }
    
    .pdf-upload-section .btn-outline-primary:hover {
        background-color: #007bff;
        border-color: #007bff;
        color: white;
    }

    @media (max-width: 767px) {
        .container {
            padding: 1.5rem 1rem;
        }

        fieldset {
            padding: 1.5rem 1rem 1rem 1rem !important;
        }

        .dynamic-entry {
            padding: 1.5rem 1rem 1rem 1rem;
        }
        
        .pdf-upload-section {
            padding: 1rem;
        }
    }

    /* Soft Message Styles */
    .soft-message {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        z-index: 9999;
        max-width: 400px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        animation: slideInRight 0.3s ease-out;
    }

    .soft-message.success {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    .soft-message.error {
        background: linear-gradient(135deg, #ef4444, #dc2626);
    }

    .soft-message.info {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
    }

    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    /* Fix checkbox styling - center the checkmarks */
    .form-check-input {
        text-align: center !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    
    .form-check-input:checked {
        background-position: center !important;
        background-size: 12px 12px !important;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- ============================================================================
     JAVASCRIPT FUNCTIONS
     ============================================================================ -->
<script>
    // ============================================================================
    // UTILITY FUNCTIONS - DEFINED FIRST SO THEY'RE AVAILABLE FOR INLINE HANDLERS
    // ============================================================================
    
    // Execute immediately when script loads - store real implementations as soon as they're defined
    (function() {
        // This IIFE ensures we can set up storage immediately
    })();
    
    // Prevent non-numeric input in number fields - MUST be defined early
    function preventNonNumericInput(event) {
        const input = event.target;
        
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
    
    // ============================================================================
    // DYNAMIC FORM FUNCTIONS
    // ============================================================================
    
    function addEntry(containerId, htmlContent) {
        const container = document.getElementById(containerId);
        const newEntry = document.createElement('div');
        newEntry.className = 'dynamic-entry';
        newEntry.innerHTML = htmlContent;
        container.appendChild(newEntry);
    }

    function removeEntry(button) {
        button.closest('.dynamic-entry').remove();
    }

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

    function restoreFieldValues(container, values) {
        if (!container || !values || Object.keys(values).length === 0) {
            return;
        }
        // CRITICAL: Increased delay to ensure DOM is fully ready after innerHTML replacement
        // This prevents race conditions where data loading might be interrupted
        setTimeout(() => {
            container.querySelectorAll('input, textarea, select').forEach(element => {
                if (element.name && Object.prototype.hasOwnProperty.call(values, element.name)) {
                    element.value = values[element.name] || '';
                    // For select elements, trigger change event
                    if (element.tagName === 'SELECT') {
                        element.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            });
        }, 50); // CRITICAL: Increased from 10ms to 50ms for better DOM readiness
    }

    // Store full implementation
    window._generateResearchStaffFieldsFull = function() {
        const maleElement = document.getElementById('research_staff_male');
        const femaleElement = document.getElementById('research_staff_female');
        const otherElement = document.getElementById('research_staff_other');
        
        const maleCount = maleElement ? parseInt(maleElement.value) || 0 : 0;
        const femaleCount = femaleElement ? parseInt(femaleElement.value) || 0 : 0;
        const otherCount = otherElement ? parseInt(otherElement.value) || 0 : 0;
        const totalCount = maleCount + femaleCount + otherCount;
        
        const container = document.getElementById('research_staff_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        if (totalCount === 0) return;
        
        let fieldIndex = 0;
        
        // Generate fields for male research staff
        for (let i = 1; i <= maleCount; i++) {
            const staffDiv = document.createElement('div');
            staffDiv.className = 'research-staff-item mb-3 p-3 border rounded';
            staffDiv.innerHTML = 
                '<h6 class="text-primary mb-3">Research Staff ' + (fieldIndex + 1) + ' (Male)</h6>' +
                '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                '<label for="research_staff_agency_' + (fieldIndex + 1) + '" class="form-label">Agency Sponsoring</label>' +
                '<input type="text" class="form-control" id="research_staff_agency_' + (fieldIndex + 1) + '" name="research_staff_agency_' + (fieldIndex + 1) + '" placeholder="Enter agency name" required>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                '<label for="research_staff_amount_' + (fieldIndex + 1) + '" class="form-label">Amount Received (INR Lakhs)</label>' +
                '<input type="number" step="0.01" class="form-control" id="research_staff_amount_' + (fieldIndex + 1) + '" name="research_staff_amount_' + (fieldIndex + 1) + '" min="0" placeholder="Enter amount" required>' +
                '<input type="hidden" id="research_staff_gender_' + (fieldIndex + 1) + '" name="research_staff_gender_' + (fieldIndex + 1) + '" value="male">' +
                '</div>' +
                '</div>';
            container.appendChild(staffDiv);
            fieldIndex++;
        }
        
        // Generate fields for female research staff
        for (let i = 1; i <= femaleCount; i++) {
            const staffDiv = document.createElement('div');
            staffDiv.className = 'research-staff-item mb-3 p-3 border rounded';
            staffDiv.innerHTML = 
                '<h6 class="text-primary mb-3">Research Staff ' + (fieldIndex + 1) + ' (Female)</h6>' +
                '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                '<label for="research_staff_agency_' + (fieldIndex + 1) + '" class="form-label">Agency Sponsoring</label>' +
                '<input type="text" class="form-control" id="research_staff_agency_' + (fieldIndex + 1) + '" name="research_staff_agency_' + (fieldIndex + 1) + '" placeholder="Enter agency name" required>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                '<label for="research_staff_amount_' + (fieldIndex + 1) + '" class="form-label">Amount Received (INR Lakhs)</label>' +
                '<input type="number" step="0.01" class="form-control" id="research_staff_amount_' + (fieldIndex + 1) + '" name="research_staff_amount_' + (fieldIndex + 1) + '" min="0" placeholder="Enter amount" required>' +
                '<input type="hidden" id="research_staff_gender_' + (fieldIndex + 1) + '" name="research_staff_gender_' + (fieldIndex + 1) + '" value="female">' +
                '</div>' +
                '</div>';
            container.appendChild(staffDiv);
            fieldIndex++;
        }
        
        // Generate fields for other research staff
        for (let i = 1; i <= otherCount; i++) {
            const staffDiv = document.createElement('div');
            staffDiv.className = 'research-staff-item mb-3 p-3 border rounded';
            staffDiv.innerHTML = 
                '<h6 class="text-primary mb-3">Research Staff ' + (fieldIndex + 1) + ' (Other)</h6>' +
                '<div class="row">' +
                '<div class="col-md-6 form-group">' +
                '<label for="research_staff_agency_' + (fieldIndex + 1) + '" class="form-label">Agency Sponsoring</label>' +
                '<input type="text" class="form-control" id="research_staff_agency_' + (fieldIndex + 1) + '" name="research_staff_agency_' + (fieldIndex + 1) + '" placeholder="Enter agency name" required>' +
                '</div>' +
                '<div class="col-md-6 form-group">' +
                '<label for="research_staff_amount_' + (fieldIndex + 1) + '" class="form-label">Amount Received (INR Lakhs)</label>' +
                '<input type="number" step="0.01" class="form-control" id="research_staff_amount_' + (fieldIndex + 1) + '" name="research_staff_amount_' + (fieldIndex + 1) + '" min="0" placeholder="Enter amount" required>' +
                '<input type="hidden" id="research_staff_gender_' + (fieldIndex + 1) + '" name="research_staff_gender_' + (fieldIndex + 1) + '" value="other">' +
                '</div>' +
                '</div>';
            container.appendChild(staffDiv);
            fieldIndex++;
        }
        
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
    }

    // NOTE: generateAwardFields is already defined in the early script block (before HTML form)
    // The real implementation is available immediately and stored in window._generateAwardFieldsFull
    // No need to redefine it here - it's already working

    function generateProjectFields() {
        const projectsElement = document.getElementById('projects_count');
        const count = projectsElement ? parseInt(projectsElement.value) || 0 : 0;
        const container = document.getElementById('projects_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const projectDiv = document.createElement('div');
            projectDiv.className = 'project-item mb-3 p-3 border rounded';
            projectDiv.innerHTML = 
                '<h6 class="text-primary mb-3">Project ' + i + '</h6>' +
                '<div class="row">' +
                    '<div class="col-md-6 form-group">' +
                        '<label for="project_type_' + i + '" class="form-label">Project Type ' + i + '</label>' +
                        '<select class="form-select" id="project_type_' + i + '" name="project_type_' + i + '" ' + ((isFormLocked && !formUnlocked) ? 'readonly disabled' : '') + '>' +
                            '<option value="">Select Type</option>' +
                            '<option value="Govt-Sponsored">Government Sponsored Research</option>' +
                            '<option value="Non-Govt-Sponsored">Non-Government Sponsored Research</option>' +
                            '<option value="Consultancy">Consultancy</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="col-md-6 form-group">' +
                        '<label for="project_title_' + i + '" class="form-label">Project Title ' + i + '</label>' +
                        '<input type="text" class="form-control" id="project_title_' + i + '" name="project_title_' + i + '" ' + ((isFormLocked && !formUnlocked) ? 'readonly disabled' : '') + '>' +
                    '</div>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="project_agency_' + i + '" class="form-label">Sponsoring/Consulting Agency ' + i + '</label>' +
                    '<input type="text" class="form-control" id="project_agency_' + i + '" name="project_agency_' + i + '" ' + ((isFormLocked && !formUnlocked) ? 'readonly disabled' : '') + '>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="project_investigators_' + i + '" class="form-label">Investigator/Consultant Name(s) ' + i + '</label>' +
                    '<textarea class="form-control" id="project_investigators_' + i + '" name="project_investigators_' + i + '" rows="2" ' + ((isFormLocked && !formUnlocked) ? 'readonly disabled' : '') + '></textarea>' +
                '</div>' +
                '<div class="row">' +
                    '<div class="col-md-4 form-group">' +
                        '<label for="project_start_date_' + i + '" class="form-label">Start Date ' + i + '</label>' +
                        '<input type="date" class="form-control" id="project_start_date_' + i + '" name="project_start_date_' + i + '" ' + ((isFormLocked && !formUnlocked) ? 'readonly disabled' : '') + '>' +
                    '</div>' +
                    '<div class="col-md-4 form-group">' +
                        '<label for="project_end_date_' + i + '" class="form-label">End Date ' + i + '</label>' +
                        '<input type="date" class="form-control" id="project_end_date_' + i + '" name="project_end_date_' + i + '" ' + ((isFormLocked && !formUnlocked) ? 'readonly disabled' : '') + '>' +
                    '</div>' +
                    '<div class="col-md-4 form-group">' +
                        '<label for="project_amount_' + i + '" class="form-label">Amount Released in Current Academic Year (INR Lakhs) ' + i + '</label>' +
                        '<input type="number" step="0.01" class="form-control" id="project_amount_' + i + '" name="project_amount_' + i + '" ' +
                               ((isFormLocked && !formUnlocked) ? 'readonly disabled' : '') + ' onkeypress="preventNonNumericInput(event)">' +
                    '</div>' +
                '</div>';
            container.appendChild(projectDiv);
        }
        
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
        
        // Enable all fields after generating them (for update mode) - only if not locked
        if (!isFormLocked || formUnlocked) {
            setTimeout(() => {
                enableAllFields();
            }, 100);
        }
    }

    // Store full implementation
    window._generateTrainingFieldsFull = function() {
        const trainingElement = document.getElementById('training_count');
        const count = trainingElement ? parseInt(trainingElement.value) || 0 : 0;
        const container = document.getElementById('training_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const trainingDiv = document.createElement('div');
            trainingDiv.className = 'training-item mb-3 p-3 border rounded';
            trainingDiv.innerHTML = `
                <h6 class="text-primary mb-3">Training Program ${i}</h6>
                <div class="form-group">
                    <label for="training_name_${i}" class="form-label">Name of the Training Programme ${i}</label>
                    <input type="text" class="form-control" id="training_name_${i}" name="training_name_${i}">
                </div>
                <div class="form-group">
                    <label for="training_corp_${i}" class="form-label">Corporate Name(s) ${i}</label>
                    <input type="text" class="form-control" id="training_corp_${i}" name="training_corp_${i}">
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="training_revenue_${i}" class="form-label">Revenue Generated (INR Lakhs) ${i}</label>
                        <input type="number" step="0.01" class="form-control" id="training_revenue_${i}" name="training_revenue_${i}" onkeypress="preventNonNumericInput(event)">
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="training_participants_${i}" class="form-label">Number of Participants ${i}</label>
                        <input type="number" class="form-control" id="training_participants_${i}" name="training_participants_${i}" onkeypress="preventNonNumericInput(event)">
                    </div>
                </div>
            `;
            container.appendChild(trainingDiv);
        }
        
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
        
        // Enable all fields after generating them (for update mode) - only if not locked
        if (!isFormLocked || formUnlocked) {
            setTimeout(() => {
                enableAllFields();
            }, 100);
        }
    };
    window.generateTrainingFields = window._generateTrainingFieldsFull;
    
    // Also store for stub functions
    window._generateTrainingFieldsFull = window._generateTrainingFieldsFull;

    // Store full implementation
    window._generatePublicationFieldsFull = function() {
        const publicationsElement = document.getElementById('publications_count');
        const count = publicationsElement ? parseInt(publicationsElement.value) || 0 : 0;
        const container = document.getElementById('publications_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const publicationDiv = document.createElement('div');
            publicationDiv.className = 'publication-item mb-3 p-3 border rounded';
            // CRITICAL: Never add readonly/disabled attributes - always allow editing
            publicationDiv.innerHTML = `
                <h6 class="text-primary mb-3">Publication ${i}</h6>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="pub_type_${i}" class="form-label">Publication Type ${i}</label>
                        <select class="form-select" id="pub_type_${i}" name="pub_type_${i}">
                            <option value="">Select Type</option>
                            <option value="Journal">Journal (Scopus/Web of Sciences)</option>
                            <option value="Conference">Conference</option>
                            <option value="ISSN_Journals">ISSN Journals + Special Issue Articles</option>
                            <option value="Art_Dept">Art Dept (Applied/Fine/Performing Arts)</option>
                        </select>
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="pub_title_${i}" class="form-label">Title of Research/Article/Editing Paper ${i}</label>
                        <input type="text" class="form-control" id="pub_title_${i}" name="pub_title_${i}">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="pub_venue_${i}" class="form-label">Journal/Conference/Media Name ${i}</label>
                        <input type="text" class="form-control" id="pub_venue_${i}" name="pub_venue_${i}">
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="pub_indexed_${i}" class="form-label">Supported By/Indexed In (Scopus, WoS, IEEE, UGC, ISSN, etc.) ${i}</label>
                        <input type="text" class="form-control" id="pub_indexed_${i}" name="pub_indexed_${i}" placeholder="e.g., Scopus, WoS, UGC Listed, ISSN, etc.">
                    </div>
                </div>
                <div class="form-group">
                    <label for="pub_authors_${i}" class="form-label">Author(s)/Editor(s) Name(s) ${i}</label>
                    <textarea class="form-control" id="pub_authors_${i}" name="pub_authors_${i}" rows="2" placeholder="Enter author names or editor names as applicable"></textarea>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="pub_date_${i}" class="form-label">Month and Year of Publication ${i}</label>
                        <input type="month" class="form-control" id="pub_date_${i}" name="pub_date_${i}">
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="pub_url_${i}" class="form-label">URL (if applicable) ${i}</label>
                        <input type="url" class="form-control" id="pub_url_${i}" name="pub_url_${i}">
                    </div>
                </div>
            `;
            container.appendChild(publicationDiv);
        }
        
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
        
        // Enable all fields after generating them (for update mode) - only if not locked
        if (!isFormLocked || formUnlocked) {
            setTimeout(() => {
                enableAllFields();
            }, 100);
        }
    }
    // Assign immediately - CRITICAL: Must assign after definition
    window.generatePublicationFields = window._generatePublicationFieldsFull;
    
    // Store full implementation (this is a duplicate definition - keeping for compatibility)
    window._generateBibliometricFieldsFull = function() {
        const bibliometricsElement = document.getElementById('bibliometrics_count');
        const count = bibliometricsElement ? parseInt(bibliometricsElement.value) || 0 : 0;
        const container = document.getElementById('bibliometrics_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const bibliometricDiv = document.createElement('div');
            bibliometricDiv.className = 'bibliometric-item mb-3 p-3 border rounded';
            // CRITICAL: Never add readonly/disabled attributes - always allow editing
            bibliometricDiv.innerHTML = `
                <h6 class="text-primary mb-3">Teacher ${i} Bibliometric Data</h6>
                <div class="row">
                    <div class="col-md-4 form-group">
                        <label for="bib_teacher_name_${i}" class="form-label">Teacher's Name ${i}</label>
                        <input type="text" class="form-control" id="bib_teacher_name_${i}" name="bib_teacher_name_${i}">
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="bib_impact_factor_${i}" class="form-label">Cumulative Impact Factor ${i}</label>
                        <input type="number" step="0.01" class="form-control" id="bib_impact_factor_${i}" name="bib_impact_factor_${i}" onkeypress="preventNonNumericInput(event)">
                    </div>
                    <div class="col-md-3 form-group">
                        <label for="bib_citations_${i}" class="form-label">Total Citations ${i}</label>
                        <input type="number" class="form-control" id="bib_citations_${i}" name="bib_citations_${i}" onkeypress="preventNonNumericInput(event)">
                    </div>
                    <div class="col-md-2 form-group">
                        <label for="bib_h_index_${i}" class="form-label">h-index ${i}</label>
                        <input type="number" class="form-control" id="bib_h_index_${i}" name="bib_h_index_${i}" onkeypress="preventNonNumericInput(event)">
                    </div>
                </div>
            `;
            container.appendChild(bibliometricDiv);
        }
        
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
        
        // Enable all fields after generating them (for update mode) - only if not locked
        if (!isFormLocked || formUnlocked) {
            setTimeout(() => {
                enableAllFields();
            }, 100);
        }
    }
    // Assign immediately - CRITICAL: Must assign after definition
    window.generateBibliometricFields = window._generateBibliometricFieldsFull;

    // Store full implementation
    window._generateBookFieldsFull = function() {
        const booksElement = document.getElementById('books_count');
        const count = booksElement ? parseInt(booksElement.value) || 0 : 0;
        const container = document.getElementById('books_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const bookDiv = document.createElement('div');
            bookDiv.className = 'book-item mb-3 p-3 border rounded';
            // CRITICAL: Never add readonly/disabled attributes - always allow editing
            bookDiv.innerHTML = `
                <h6 class="text-primary mb-3">Book/Chapter/MOOC ${i} (Authored, Edited, or Created)</h6>
                <div class="form-group">
                    <label for="book_type_${i}" class="form-label">Type ${i}</label>
                    <select class="form-select" id="book_type_${i}" name="book_type_${i}">
                        <option value="">Select Type</option>
                        <option value="Authored Book">Authored Reference Book</option>
                        <option value="Edited Book">Edited Book</option>
                        <option value="Chapter">Chapter in Edited Volume</option>
                        <option value="Translated Book">Translated Book</option>
                        <option value="MOOC">MOOC (Created)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="book_title_${i}" class="form-label">Title ${i}</label>
                    <input type="text" class="form-control" id="book_title_${i}" name="book_title_${i}">
                </div>
                <div class="form-group">
                    <label for="book_authors_${i}" class="form-label">Author(s)/Editor(s)/Creator(s) Name(s) ${i}</label>
                    <textarea class="form-control" id="book_authors_${i}" name="book_authors_${i}" rows="2" placeholder="Enter names of authors, editors, or creators"></textarea>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="book_month_${i}" class="form-label">Month ${i}</label>
                        <select class="form-control" id="book_month_${i}" name="book_month_${i}">
                            <option value="">Select Month</option>
                            <option value="01">January</option>
                            <option value="02">February</option>
                            <option value="03">March</option>
                            <option value="04">April</option>
                            <option value="05">May</option>
                            <option value="06">June</option>
                            <option value="07">July</option>
                            <option value="08">August</option>
                            <option value="09">September</option>
                            <option value="10">October</option>
                            <option value="11">November</option>
                            <option value="12">December</option>
                        </select>
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="book_year_${i}" class="form-label">Year ${i}</label>
                        <select class="form-control" id="book_year_${i}" name="book_year_${i}">
                            <option value="">Select Year</option>
                            <?php
                            $currentYear = date("Y");
                            for ($year = $currentYear; $year >= 2000; $year--) {
                                echo "<option value='$year'>$year</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="book_publisher_${i}" class="form-label">Publisher / Platform / MOOC Platform (e.g., SWAYAM, NPTEL, Coursera) ${i}</label>
                    <input type="text" class="form-control" id="book_publisher_${i}" name="book_publisher_${i}" placeholder="Enter publisher name or MOOC platform">
                </div>
            `;
            container.appendChild(bookDiv);
        }
        
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
        
        // Enable all fields after generating them (for update mode) - only if not locked
        if (!isFormLocked || formUnlocked) {
            setTimeout(() => {
                enableAllFields();
            }, 100);
        }
    }

    // Store full implementation
    window._calculatePatentTotalsFull = function() {
        const published2022 = parseInt(document.getElementById('patents_published_2022').value) || 0;
        const published2023 = parseInt(document.getElementById('patents_published_2023').value) || 0;
        const published2024 = parseInt(document.getElementById('patents_published_2024').value) || 0;
        const granted2022 = parseInt(document.getElementById('patents_granted_2022').value) || 0;
        const granted2023 = parseInt(document.getElementById('patents_granted_2023').value) || 0;
        const granted2024 = parseInt(document.getElementById('patents_granted_2024').value) || 0;

        const totalPublished = published2022 + published2023 + published2024;
        const totalGranted = granted2022 + granted2023 + granted2024;
        const totalPatents = totalPublished + totalGranted;

        document.getElementById('total_published').value = totalPublished;
        document.getElementById('total_granted').value = totalGranted;
        document.getElementById('patents_count').value = totalPatents;
        
        // Generate patent fields automatically based on the total count
        if (typeof window._generatePatentFieldsFull === 'function') {
            window._generatePatentFieldsFull();
        }
    };

    // Store full implementation
    window._generatePatentFieldsFull = function() {
        const count = parseInt(document.getElementById('patents_count').value) || 0;
        const container = document.getElementById('patents_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const patentDiv = document.createElement('div');
            patentDiv.className = 'patent-item mb-4 p-4 border rounded';
            patentDiv.innerHTML = `
                <h6 class="text-primary mb-3">Patent Details ${i}</h6>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="patent_app_number_${i}" class="form-label">Patent Application Number ${i} <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="patent_app_number_${i}" name="patent_app_number_${i}" 
                               placeholder="e.g., 202241048822" required>
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="patent_status_${i}" class="form-label">Status of Patent ${i} <span class="text-danger">*</span></label>
                        <select class="form-select" id="patent_status_${i}" name="patent_status_${i}" required>
                            <option value="">Select Status</option>
                            <option value="Published">Published</option>
                            <option value="Granted">Granted</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="patent_inventors_${i}" class="form-label">Inventor(s) Name ${i} <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="patent_inventors_${i}" name="patent_inventors_${i}" rows="2" 
                              placeholder="Enter all inventor names separated by semicolons" required></textarea>
                </div>
                <div class="form-group">
                    <label for="patent_title_${i}" class="form-label">Title of the Patent ${i} <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="patent_title_${i}" name="patent_title_${i}" rows="2" 
                              placeholder="Enter the complete title of the patent" required></textarea>
                </div>
                <div class="form-group">
                    <label for="patent_applicants_${i}" class="form-label">Applicant(s) Name ${i} <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="patent_applicants_${i}" name="patent_applicants_${i}" rows="2" 
                              placeholder="Enter all applicant names separated by semicolons" required></textarea>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="patent_filed_date_${i}" class="form-label">Patent Filed Date ${i} <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="patent_filed_date_${i}" name="patent_filed_date_${i}" required>
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="patent_published_date_${i}" class="form-label">Patent Published/Granted Date ${i} <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="patent_published_date_${i}" name="patent_published_date_${i}" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="patent_number_${i}" class="form-label">Patent Publication/Granted Number ${i}</label>
                    <input type="text" class="form-control" id="patent_number_${i}" name="patent_number_${i}" 
                           placeholder="e.g., 479281 or leave empty if not available">
                </div>
                <div class="form-group">
                    <label for="patent_assignees_${i}" class="form-label">Assignee(s) Name (Institute Affiliation) ${i} <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="patent_assignees_${i}" name="patent_assignees_${i}" 
                           placeholder="e.g., University of Mumbai" required>
                </div>
                <div class="form-group">
                    <label for="patent_url_${i}" class="form-label">Patent URL (Proof Link) ${i} <span class="text-danger">*</span></label>
                    <input type="url" class="form-control" id="patent_url_${i}" name="patent_url_${i}" 
                           placeholder="https://drive.google.com/file/d/... or direct patent database URL" required>
                    <small class="form-text text-muted">Provide direct URL to patent document or Google Drive link with proof</small>
                </div>
            `;
            container.appendChild(patentDiv);
        }
        
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
        
        // Enable all fields after generating them (for update mode) - only if not locked
        if (!isFormLocked || formUnlocked) {
            setTimeout(() => {
                enableAllFields();
            }, 100);
        }
    }
    // Assign immediately
    window.generateBookFields = window._generateBookFieldsFull;
    window.generatePatentFields = window._generatePatentFieldsFull;

    function updateCounter(textarea, counterId) {
        const maxLength = textarea.getAttribute('maxlength');
        const currentLength = textarea.value.length;
        const remaining = maxLength - currentLength;
        document.getElementById(counterId).innerText = `${remaining} characters remaining`;
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


    // ============================================================================
    // PDF UPLOAD FUNCTIONS
    // ============================================================================
    
    function validatePDF(input) {
        const file = input.files[0];
        if (file) {
            const fileExtension = file.name.split('.').pop().toLowerCase();
            if (fileExtension !== 'pdf') {
                alert('Please select a PDF file only.');
                input.value = '';
                return false;
            }
            if (file.size > 10 * 1024 * 1024) { // 10MB limit
                alert('File size must be less than 10MB.');
                input.value = '';
                return false;
            }
        }
        return true;
    }
    
    // Store full implementation for uploadDocument
    window._uploadDocumentFull = function(fileId, srno) {
        // Check if form is locked before proceeding
        if (isFormLocked && !formUnlocked) {
            alert('⚠️ Form is locked. Please click "Update Data" to enable editing and uploads.');
            return;
        }
        
        debugLog('Upload document started:', { fileId, srno });
        
        try {
            const fileInput = document.getElementById(fileId);
            const statusDiv = document.getElementById(fileId + '_status');
            
            if (!fileInput) {
                errorLog('File input not found:', fileId);
                alert('❌ File input not found. Please refresh the page.');
                return;
            }
            
            if (!statusDiv) {
                errorLog('Status div not found:', fileId + '_status');
                alert('❌ Status div not found. Please refresh the page.');
                return;
            }
            
            // Check if form is locked (file input is disabled)
            if (fileInput.disabled || (isFormLocked && !formUnlocked)) {
                // Form is locked, just check existing document status
                checkDocumentStatus(fileId, srno);
                return;
            }
        
            if (!fileInput.files[0]) {
                alert('Please select a file to upload.');
                return;
            }
            
            if (!validatePDF(fileInput)) {
                return;
            }
            
            // Get CSRF token from form
            const csrfTokenInput = document.querySelector('input[name="csrf_token"]');
            if (!csrfTokenInput) {
                alert('Security token not found. Please refresh the page.');
                return;
            }
            
            const formData = new FormData();
            formData.append('upload_document', '1');
            formData.append('file_id', fileId);
            formData.append('srno', srno);
            formData.append('document', fileInput.files[0]);
            formData.append('csrf_token', csrfTokenInput.value);
            
            // Show loading state
            statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Uploading...';
            statusDiv.className = 'mt-2 text-info';
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                // Always try to parse as JSON regardless of Content-Type
                return response.text().then(text => {
                    const trimmed = text.trim();
                    
                    // CRITICAL: Handle empty responses - return object, don't throw
                    if (!trimmed || trimmed.length === 0) {
                        return { success: false, message: 'Empty response from server. Please try again.' };
                    }
                    
                    try {
                        const json = JSON.parse(trimmed);
                        // CRITICAL: Ensure we always return an object
                        if (!json || typeof json !== 'object') {
                            return { success: false, message: 'Invalid response format. Please try again.' };
                        }
                        return json;
                    } catch (e) {
                        // CRITICAL: Return object instead of throwing - prevents loops
                        return { success: false, message: 'Invalid response format: ' + trimmed.substring(0, 100) };
                    }
                });
            })
            .catch(error => {
                // CRITICAL: Handle fetch errors (network, CORS, etc.) - return error object
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Upload failed: ' + (error.message || 'Network error') + '</span>';
                statusDiv.className = 'mt-2 text-danger';
                if (fileInput) fileInput.disabled = false;
                // Return error object to prevent unhandled rejection
                return { success: false, message: 'Network error: ' + (error.message || 'Failed to connect to server') };
            })
            .then(data => {
                // CRITICAL: Ensure data exists and is an object
                if (!data || typeof data !== 'object') {
                    statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Invalid response from server. Please try again.</span>';
                    statusDiv.className = 'mt-2 text-danger';
                    if (fileInput) fileInput.disabled = false;
                    return;
                }
                
                if (data && data.success) {
                    // Document uploaded - show simple message with View/Delete buttons
                    // CRITICAL: Escape single quotes in fileId to prevent JavaScript syntax errors
                    const escapedFileId = (fileId || '').replace(/'/g, "\\'");
                    statusDiv.innerHTML = 
                        '<span class="text-success me-2">✅ Document uploaded</span>' +
                        '<a href="' + data.file_path + '" target="_blank" class="btn btn-sm btn-outline-primary ms-1"><i class="fas fa-eye"></i> View</a>' +
                        '<button type="button" class="btn btn-sm btn-outline-danger ms-1" onclick="deleteDocument(\'' + escapedFileId + '\', ' + srno + ')"><i class="fas fa-trash"></i> Delete</button>';
                    statusDiv.className = 'mt-2 text-success';
                    
                    // Clear the file input
                    fileInput.value = '';
                    
                    // Refresh document status
                    setTimeout(() => {
                        checkDocumentStatus(fileId, srno);
                    }, 500);
                } else {
                    statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">${data.message}</span>`;
                    statusDiv.className = 'mt-2 text-danger';
                    
                    // Check if redirect is needed
                    if (data.redirect) {
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 2000);
                    }
                }
            })
            .catch(error => {
                errorLog('Upload error:', error);
                statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">❌ Upload failed: ${error.message}</span>`;
                statusDiv.className = 'mt-2 text-danger';
                alert('❌ Upload Error: ' + error.message + '\n\nPlease check the console for more details.');
            });
            
        } catch (error) {
            errorLog('Upload function error:', error);
            alert('❌ Upload Function Error: ' + error.message + '\n\nPlease check the console for more details.');
        }
    };
    // Assign to global function (only if not already set by early script)
    if (typeof window.uploadDocument === 'undefined' || !window.uploadDocument) {
        window.uploadDocument = window._uploadDocumentFull;
    }
    
    function deleteDocument(fileId, srno) {
        if (!confirm('Are you sure you want to delete this document?')) {
            return;
        }
        
        const statusDiv = document.getElementById(fileId + '_status');
        
        if (!statusDiv) {
            return;
        }
        
        // Show loading state
        statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Deleting...';
        statusDiv.className = 'mt-2 text-info';
        
        fetch(`?delete_doc=1&srno=${srno}`, {
            method: 'GET'
        })
        .catch(error => {
            // CRITICAL: Handle fetch errors (network, CORS, etc.) - return error object
            return { 
                ok: false, 
                text: () => Promise.resolve(JSON.stringify({ 
                    success: false, 
                    message: 'Network error: ' + (error.message || 'Failed to connect to server') 
                })) 
            };
        })
        .then(response => {
            // CRITICAL: Ensure response is valid before processing
            if (!response || typeof response.text !== 'function') {
                return { success: false, message: 'Delete failed: Invalid server response' };
            }
            
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                return response.json().catch(function(e) {
                    return { success: false, message: 'Invalid response format' };
                });
            } else {
                return response.text().then(function(text) {
                    var trimmed = text.trim();
                    // CRITICAL: Handle empty responses - return object, don't throw
                    if (!trimmed || trimmed.length === 0) {
                        return { success: false, message: 'Empty response from server' };
                    }
                    try {
                        var parsed = JSON.parse(trimmed);
                        // CRITICAL: Ensure we always return an object
                        if (!parsed || typeof parsed !== 'object') {
                            return { success: false, message: 'Invalid response format' };
                        }
                        return parsed;
                    } catch (e) {
                        // CRITICAL: Return object instead of throwing - prevents loops
                        return { success: false, message: 'Invalid response format: ' + trimmed.substring(0, 100) };
                    }
                }).catch(textError => {
                    // CRITICAL: Handle text() parsing errors
                    return { success: false, message: 'Delete failed: Error reading server response' };
                });
            }
        })
        .then(data => {
            // CRITICAL #3: Handle null/undefined data gracefully
            if (!data || typeof data !== 'object') {
                statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Invalid server response</span>';
                statusDiv.className = 'mt-2 text-danger';
                return;
            }
            
            if (data.success) {
                statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                statusDiv.className = 'mt-2 text-muted';
            } else {
                statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">${data.message}</span>`;
                statusDiv.className = 'mt-2 text-danger';
            }
        })
        .catch(error => {
            // CRITICAL: Handle errors gracefully - return resolved promise to prevent unhandled rejection
            statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Delete failed: ${error.message || 'Unknown error'}</span>`;
            statusDiv.className = 'mt-2 text-danger';
            
            // Return resolved promise to prevent unhandled promise rejection
            return Promise.resolve({ success: false, message: 'Delete failed: ' + (error.message || 'Unknown error') });
        });
    }
    
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
    
    // ============================================================================
    // LOAD EXISTING DOCUMENTS - Uses batch endpoint (31 queries → 1 query)
    // ============================================================================
    let documentsLoading = false;
    let documentsLoadAttempted = false;
    
    function loadExistingDocuments() {
        // CRITICAL: Guard against duplicate calls - prevent multiple simultaneous loads
        if (documentsLoading || documentsLoadAttempted) {
            return; // Already loading or already attempted - DO NOT RETRY
        }
        documentsLoading = true;
        documentsLoadAttempted = true; // NEVER reset this flag
        
        // Document mapping for batch response processing
        const documentMap = {
            'awards_state_govt': 1,
            'awards_national_govt': 2,
            'awards_international_govt': 3,
            'awards_international_fellowship': 4,
            'projects_non_govt': 5,
            'projects_govt': 6,
            'projects_consultancy': 7,
            'training_corporate': 8,
            'recognitions_infra': 9,
            'dpiit_certificates': 10,
            'investment_agreements': 11,
            'grant_letters': 12,
            'trl_documentation': 13,
            'turnover_certificates': 14,
            'alumni_verification': 15,
            'patents_ipr': 16,
            'patents_filed': 17,
            'patents_published': 18,
            'patents_granted': 19,
            'copyrights_docs': 20,
            'designs_docs': 21,
            'publications_scopus': 22,
            'publications_conference': 23,
            'publications_ugc': 24,
            'publications_issn': 25,
            'publications_art': 26,
            'bibliometrics_impact': 27,
            'bibliometrics_hindex': 28,
            'books_moocs': 29,
            'desc_initiative_doc': 30,
            'desc_collaboration_doc': 31
        };
        
        // Show loading state for all status divs
        Object.keys(documentMap).forEach(fileId => {
            const statusDiv = document.getElementById(fileId + '_status');
            if (statusDiv) {
                statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Checking...';
                statusDiv.className = 'mt-2 text-info';
            }
        });
        
        // CRITICAL: Use batch endpoint - ONE request instead of 31 separate requests
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
        
        executeWithRateLimit(() => {
            return fetch('?check_all_docs=1', {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                cache: 'no-cache',
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    return { success: false, message: 'HTTP error: ' + response.status, documents: {} };
                }
                return response.text().then(text => {
                    const trimmed = text.trim();
                    if (!trimmed || trimmed === '') {
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
            documentsLoading = false; // Reset loading flag, but keep attempted flag
            
            if (data.success && data.documents) {
                // Process batch response - update all documents at once
                Object.keys(documentMap).forEach(fileId => {
                    const srno = documentMap[fileId];
                    const statusDiv = document.getElementById(fileId + '_status');
                    if (!statusDiv) return;
                    
                    const docData = data.documents[srno];
                    if (docData && docData.success) {
                        updateDocumentStatusUI(fileId, srno, docData.file_path, docData.file_name);
                    } else {
                        statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                        statusDiv.className = 'mt-2 text-muted';
                    }
                });
            } else {
                // Error loading documents - show error message
                Object.keys(documentMap).forEach(fileId => {
                    const statusDiv = document.getElementById(fileId + '_status');
                    if (statusDiv) {
                        const errorMsg = data.message || 'Error loading documents';
                        statusDiv.innerHTML = '<span class="text-muted">' + errorMsg + '</span>';
                        statusDiv.className = 'mt-2 text-muted';
                    }
                });
            }
        })
        .catch(error => {
            documentsLoading = false; // Reset loading flag on error
            // Show error for all status divs
            Object.keys(documentMap).forEach(fileId => {
                const statusDiv = document.getElementById(fileId + '_status');
                if (statusDiv) {
                    statusDiv.innerHTML = '<span class="text-muted">Error loading documents</span>';
                    statusDiv.className = 'mt-2 text-muted';
                }
            });
        });
    }
    
    // Helper function to update document status UI
    function updateDocumentStatusUI(fileId, srno, filePath, fileName) {
        const statusDiv = document.getElementById(fileId + '_status');
        if (!statusDiv) return;
        
        // Check if form is locked
        const fileInput = document.getElementById(fileId);
        const isFormCurrentlyLocked = fileInput && fileInput.disabled;
        const isUpdateMode = typeof formUnlocked !== 'undefined' && formUnlocked;
        
        if (filePath) {
            // Show delete button if form is NOT locked OR if form is unlocked (update mode)
            // CRITICAL: Escape single quotes in fileId to prevent JavaScript syntax errors
            const escapedFileId = (fileId || '').replace(/'/g, "\\'");
            var deleteButton = '';
            if (!isFormCurrentlyLocked || isUpdateMode) {
                deleteButton = '<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument(\'' + escapedFileId + '\', ' + srno + ')"><i class="fas fa-trash"></i> Delete</button>';
            }
            
            statusDiv.innerHTML = 
                '<span class="text-success me-2">✅ Document uploaded</span>' +
                '<a href="' + filePath + '" target="_blank" class="btn btn-sm btn-outline-primary ms-1"><i class="fas fa-eye"></i> View</a>' +
                deleteButton;
            statusDiv.className = 'mt-2 text-success';
        } else {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
        }
    }

    // Force refresh all document statuses (used when Update is clicked)
    function refreshAllDocumentStatuses() {
        // Reset the attempted flag to allow reload
        documentsLoadAttempted = false;
        
        // Clear existing status divs first
        const statusDivs = document.querySelectorAll('[id$="_status"]');
        statusDivs.forEach(div => {
            div.innerHTML = '<span class="text-muted">Checking...</span>';
            div.className = 'mt-2 text-muted';
        });
        
        // Then reload all documents
        loadExistingDocuments();
    }
    
    // Track active check requests to prevent duplicate calls
    const activeDocumentChecks = new Set();
    
    // Make checkDocumentStatus globally accessible (for individual document checks)
    window.checkDocumentStatus = function(fileId, srno) {
        const statusDiv = document.getElementById(fileId + '_status');
        if (!statusDiv) {
            return;
        }
        
        // CRITICAL: Prevent duplicate checks for the same document - remove active checks on errors to prevent loops
        const checkKey = `${fileId}_${srno}`;
        if (activeDocumentChecks.has(checkKey)) {
            return; // Already checking this document
        }
        
        // Add to active checks - prevent loops
        activeDocumentChecks.add(checkKey);
        
        // Show loading state
        statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Checking...';
        statusDiv.className = 'mt-2 text-info';
        
        // CRITICAL: Add request timeout with AbortController
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
        
        executeWithRateLimit(() => {
            return fetch(`?check_doc=1&file_id=${encodeURIComponent(fileId)}&srno=${srno}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                },
                cache: 'no-cache',
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                return response;
            })
            .catch(error => {
                clearTimeout(timeoutId);
                // CRITICAL: Handle fetch errors (network, CORS, timeout, etc.) - remove from active checks - prevent loops
                activeDocumentChecks.delete(checkKey);
                if (error.name === 'AbortError') {
                    // Return error object for timeout
                    return { 
                        ok: false, 
                        text: () => Promise.resolve(JSON.stringify({ 
                            success: false, 
                            message: 'Request timeout' 
                        })) 
                    };
                }
                // Return error object instead of throwing
                return { 
                    ok: false, 
                    text: () => Promise.resolve(JSON.stringify({ 
                        success: false, 
                        message: 'Network error: ' + (error.message || 'Failed to connect to server') 
                    })) 
                };
            });
        })
        .then(response => {
            // CRITICAL: Ensure response is valid before processing
            if (!response || typeof response.text !== 'function') {
                activeDocumentChecks.delete(checkKey);
                return { success: false, message: 'Check failed: Invalid server response' };
            }
            
            // CRITICAL: Check HTTP status - remove from active checks on error - prevent loops
            if (!response.ok) {
                activeDocumentChecks.delete(checkKey);
                // Return error object instead of throwing
                return { success: false, message: 'HTTP error: ' + response.status };
            }
            
            // Always try to parse as JSON regardless of Content-Type
            return response.text().then(text => {
                const trimmed = text.trim();
                
                // CRITICAL: Handle empty response gracefully - return object, don't throw
                if (!trimmed || trimmed === '') {
                    // Remove from active checks - prevent loops
                    activeDocumentChecks.delete(checkKey);
                    // Return error object instead of throwing
                    return { success: false, message: 'Empty server response' };
                }
                
                try {
                    const json = JSON.parse(trimmed);
                    // Validate JSON structure
                    if (typeof json !== 'object' || json === null) {
                        throw new Error('Invalid JSON structure');
                    }
                    // CRITICAL: Remove from active checks on success - prevent loops
                    activeDocumentChecks.delete(checkKey);
                    return json;
                } catch (e) {
                    // Remove from active checks on parse error - prevent loops
                    activeDocumentChecks.delete(checkKey);
                    // Return error object instead of throwing to prevent error loops
                    return { success: false, message: 'Invalid server response' };
                }
            }).catch(textError => {
                // CRITICAL: Handle text() parsing errors
                activeDocumentChecks.delete(checkKey);
                return { success: false, message: 'Check failed: Error reading server response' };
            });
        })
        .catch(error => {
            // CRITICAL: Remove from active checks on ANY error - prevent loops
            activeDocumentChecks.delete(checkKey);
            // CRITICAL: Return resolved promise with error object - prevent unhandled promise rejection
            return Promise.resolve({ success: false, message: 'Network error: ' + (error.message || 'Unknown error') });
        })
        .then(data => {
            // CRITICAL: Validate data structure - handle invalid responses gracefully
            // Note: activeDocumentChecks already removed above in success/error paths
            if (!data || typeof data !== 'object') {
                statusDiv.innerHTML = '<span class="text-muted">❌ Error checking document</span>';
                statusDiv.className = 'mt-2 text-danger';
                return;
            }
            
            // Check if form is locked
            const fileInput = document.getElementById(fileId);
            const isFormCurrentlyLocked = fileInput && fileInput.disabled;
            const isUpdateMode = typeof formUnlocked !== 'undefined' && formUnlocked;
            
        if (data.success && data.file_path) {
            // All documents are now permanent - no temporary storage
            // Permanent document
            
            // Show delete button if form is NOT locked OR if form is unlocked (update mode)
            // CRITICAL: Escape single quotes in fileId to prevent JavaScript syntax errors
            const escapedFileId = (fileId || '').replace(/'/g, "\\'");
            var deleteButton = '';
            
            if (!isFormCurrentlyLocked || isUpdateMode) {
                deleteButton = '<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument(\'' + escapedFileId + '\', ' + srno + ')"><i class="fas fa-trash"></i> Delete</button>';
            }
            
            statusDiv.innerHTML = 
                '<span class="text-success me-2">✅ Document uploaded</span>' +
                '<a href="' + data.file_path + '" target="_blank" class="btn btn-sm btn-outline-primary ms-1"><i class="fas fa-eye"></i> View</a>' +
                deleteButton;
            statusDiv.className = 'mt-2 text-success';
        } else {
            // Show message if provided, otherwise default message
            const message = data.message || 'No document uploaded';
            statusDiv.innerHTML = '<span class="text-muted">' + message + '</span>';
            statusDiv.className = 'mt-2 text-muted';
        }
        })
        .catch(error => {
            // Fallback catch - should rarely be reached now since we handle errors earlier
            activeDocumentChecks.delete(checkKey);
            statusDiv.innerHTML = '<span class="text-muted">Unable to check document status</span>';
                    statusDiv.className = 'mt-2 text-muted';
                });
            }

    // ============================================================================
    // SINGLE INITIALIZATION POINT - Prevent duplicate calls
    // ============================================================================
    let pageInitialized = false;
    
    function initializePage() {
        if (pageInitialized) {
            return; // Already initialized - prevent duplicate calls
        }
        pageInitialized = true;
        
        // Load documents ONCE
        loadExistingDocuments();
        
        // Apply lock state after page loads with delay to ensure DOM is ready
        setTimeout(() => {
            if (typeof applyLockState === 'function') {
                applyLockState();
                
                // If form is locked, ensure all generated fields are also locked
                const isFormLocked = <?php echo isset($form_locked) && $form_locked ? 'true' : 'false'; ?>;
                const formUnlocked = <?php echo isset($formUnlocked) && $formUnlocked ? 'true' : 'false'; ?>;
                if (isFormLocked && !formUnlocked && typeof lockAllDynamicFields === 'function') {
                    lockAllDynamicFields();
                }
            }
        }, 500);
    }
    
    // Use window.onload as primary initialization
    window.onload = function() {
        initializePage();
    };
    
    // Use DOMContentLoaded as fallback ONLY if window.onload hasn't fired
    document.addEventListener('DOMContentLoaded', function() {
        // Only initialize if not already initialized
        if (!pageInitialized) {
            // Small delay to ensure window.onload has a chance to fire first
            setTimeout(function() {
                if (!pageInitialized) {
                    initializePage();
                }
            }, 50);
        }
    });
    
    // ============================================================================
    // ERROR HANDLING & LOGGING
    // ============================================================================
    
    // Global error handler
    window.addEventListener('error', function(e) {
        // Show user-friendly error message
        alert('?? JavaScript Error: ' + e.message);
    });
    
    // Global unhandled promise rejection handler
    window.addEventListener('unhandledrejection', function(e) {
        alert('?? Network Error: ' + (e.reason.message || e.reason));
    });
    
    // Debug logging function (silent)
    function debugLog(message, data = null) {
        // Silent - no console output
    }
    
    // Error logging function (silent)
    function errorLog(message, error = null) {
        // Silent - no console output
    }
    
    // ============================================================================
    // FORM VALIDATION
    // ============================================================================
    
    function validateForm() {
        debugLog('Form validation started...');
        
        try {
        // Check required fields for section 12
        const requiredFields = [
            { id: 'desc_initiative', name: 'Major research or innovation initiative' },
            { id: 'desc_impact', name: 'Measurable impact of the initiative' },
            { id: 'desc_collaboration', name: 'Industry collaboration models' },
            { id: 'desc_plan', name: 'Plan to sustain and scale the initiative' },
            { id: 'desc_recognition', name: 'Why should your department be recognized for Excellence in Research & Innovation?' }
        ];
        
        let isValid = true;
        let missingFields = [];
        
            requiredFields.forEach(field => {
            const element = document.getElementById(field.id);
            if (!element) {
                    errorLog('Required field element not found:', field.id);
                isValid = false;
                    missingFields.push(field.name + ' (element not found)');
                return;
            }
            
            if (element.value.trim() === '') {
                isValid = false;
                missingFields.push(field.name);
                element.classList.add('is-invalid');
                element.style.borderColor = '#dc3545';
            } else {
                element.classList.remove('is-invalid');
                element.style.borderColor = '';
            }
        });
        
        if (!isValid) {
                errorLog('Validation failed. Missing fields:', missingFields);
                alert('? Please fill in all required fields:\n\n� ' + missingFields.join('\n� '));
                return false;
            }
            
            debugLog('Form validation passed!');
            return true;
            
        } catch (error) {
            errorLog('Validation error:', error);
            alert('? Validation Error: ' + error.message + '\n\nPlease check the console for more details.');
            return false;
        }
    }

    // ============================================================================
    // ACTION FUNCTIONS
    // ============================================================================
    
    function confirmClearData() {
        if (confirm('Are you sure you want to clear all data for academic year <?php echo $A_YEAR; ?>? This action cannot be undone!')) {
            return true;
        }
        return false;
    }

    // ============================================================================
    // FORM STATE MANAGEMENT
    // ============================================================================
    
    // Form lock state management (like StudentSupport.php)
    let isFormLocked = <?php echo (isset($form_locked) && $form_locked && isset($existing_data) && $existing_data) ? 'true' : 'false'; ?>;
    var formUnlocked = false; // Use var for global scope
    
    function setFormDisabled(disabled) {
        const form = document.getElementById('facultyForm');
        if (!form) return;
        
        // Disable/enable all form inputs (including file inputs)
        form.querySelectorAll('input, textarea, select').forEach(element => {
            // Skip system-calculated fields (always disabled)
            if (element.id === 'total_published' || element.id === 'total_granted' || element.id === 'patents_count') {
                return;
            }
            // Skip hidden/system fields
            if (element.name === 'academic_year' || element.name === 'dept_info' || element.name === 'csrf_token') {
                return;
            }
            // Apply disabled/readonly state
            element.disabled = disabled;
            if (disabled) {
                element.setAttribute('readonly', 'readonly');
                // Special handling for file inputs
                if (element.type === 'file') {
                    element.style.opacity = '0.5';
                    element.style.cursor = 'not-allowed';
                }
            } else {
                element.removeAttribute('readonly');
                // Restore file inputs
                if (element.type === 'file') {
                    element.style.opacity = '1';
                    element.style.cursor = 'pointer';
                }
            }
        });
        
        // Disable/enable all upload buttons
        form.querySelectorAll('button[onclick*="uploadDocument"]').forEach(button => {
            button.disabled = disabled;
            if (disabled) {
                button.style.opacity = '0.5';
                button.style.cursor = 'not-allowed';
                button.title = 'Form is locked. Click Update to edit.';
            } else {
                button.style.opacity = '1';
                button.style.cursor = 'pointer';
                button.title = '';
            }
        });
        
        // Disable/enable checkboxes
        form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            checkbox.disabled = disabled;
            if (disabled) {
                checkbox.style.opacity = '0.5';
                checkbox.style.cursor = 'not-allowed';
            } else {
                checkbox.style.opacity = '1';
                checkbox.style.cursor = 'pointer';
            }
        });
    }
    
    // Cancel update function - reverts form back to locked state
    function cancelUpdate() {
        formUnlocked = false;
        
        // Show the Update Data button and Clear Data button
        const updateBtn = document.getElementById('updateBtn');
        const saveBtn = document.getElementById('saveBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const clearBtn = document.getElementById('clearBtn');
        
        if (updateBtn) updateBtn.style.display = 'inline-block';
        if (saveBtn) saveBtn.style.display = 'none';
        if (cancelBtn) cancelBtn.style.display = 'none';
        if (clearBtn) clearBtn.style.display = 'inline-block'; // Show Clear Data again
        
        // Lock all form fields
        setFormDisabled(true);
        
        // Lock dynamically generated fields
        lockAllDynamicFields();
        
        // Reload page to restore original values (prevent data inconsistency)
        if (confirm('Are you sure you want to cancel? All unsaved changes will be lost.')) {
            window.location.reload();
        }
    }
    
    // Make cancelUpdate available globally
    window.cancelUpdate = cancelUpdate;
    
    function applyLockState() {
        const disabled = isFormLocked && !formUnlocked;
        setFormDisabled(disabled);
        
        const submitBtn = document.getElementById('submitBtn');
        const updateBtn = document.getElementById('updateBtn');
        const saveBtn = document.getElementById('saveBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const clearBtn = document.getElementById('clearBtn');
        
        if (isFormLocked) {
            if (submitBtn) submitBtn.style.display = 'none';
            if (updateBtn) {
                updateBtn.style.display = disabled ? 'inline-block' : 'none';
                updateBtn.disabled = false;
            }
            if (saveBtn) saveBtn.style.display = disabled ? 'none' : 'inline-block';
            if (cancelBtn) {
                cancelBtn.style.display = disabled ? 'none' : 'inline-block';
                cancelBtn.disabled = false;
            }
            if (clearBtn) {
                clearBtn.style.display = disabled ? 'inline-block' : 'none'; // Hide when in update mode
                clearBtn.disabled = false;
            }
        } else {
            if (submitBtn) submitBtn.style.display = 'inline-block';
            if (updateBtn) updateBtn.style.display = 'none';
            if (saveBtn) saveBtn.style.display = 'none';
            if (cancelBtn) cancelBtn.style.display = 'none';
            if (clearBtn) clearBtn.style.display = 'none';
        }
        
        // Also lock dynamically generated fields
        if (disabled) {
            lockAllDynamicFields();
        }
    }
    
    function lockAllDynamicFields() {
        const containers = [
            'awards_container', 'projects_container', 'training_container',
            'publications_container', 'bibliometrics_container', 'books_container',
            'research_staff_container', 'patents_container',
            'dpiit_startups_container', 'vc_investments_container',
            'seed_funding_container', 'fdi_investments_container',
            'innovation_grants_container', 'trl_innovations_container',
            'turnover_achievements_container', 'forbes_alumni_container'
        ];
        
        containers.forEach(containerId => {
            const container = document.getElementById(containerId);
            if (container) {
                container.querySelectorAll('input, textarea, select, button').forEach(element => {
                    // Skip buttons that are not upload buttons
                    if (element.tagName === 'BUTTON') {
                        // Check if onclick exists and contains uploadDocument
                        let hasUploadHandler = false;
                        if (element.onclick) {
                            try {
                                const onclickStr = element.onclick.toString ? element.onclick.toString() : String(element.onclick);
                                hasUploadHandler = onclickStr.includes('uploadDocument');
                            } catch (e) {
                                // If toString fails, check the onclick attribute
                                hasUploadHandler = element.getAttribute('onclick') && element.getAttribute('onclick').includes('uploadDocument');
                            }
                        } else if (element.getAttribute('onclick')) {
                            hasUploadHandler = element.getAttribute('onclick').includes('uploadDocument');
                        }
                        if (!hasUploadHandler) {
                            return; // Skip this button
                        }
                    }
                    element.disabled = true;
                    if (element.tagName !== 'BUTTON') {
                        element.setAttribute('readonly', 'readonly');
                    }
                    element.style.opacity = '0.5';
                    element.style.cursor = 'not-allowed';
                });
            }
        });
    }
    
    // Enhanced function to enable all fields including dynamically generated ones
    function enableAllFields() {
        const inputs = document.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            // Never enable system-calculated fields
            if (input.id === 'total_published' || input.id === 'total_granted' || input.id === 'patents_count') {
                return; // Skip these fields
            }
            // Skip Academic Year and Department fields - keep them non-editable
            if (input.name === 'academic_year' || input.name === 'dept_info') {
                return;
            }
            input.removeAttribute('readonly');
            input.disabled = false;
            input.style.pointerEvents = 'auto';
            input.style.cursor = input.type === 'file' ? 'pointer' : (input.tagName === 'TEXTAREA' ? 'text' : 'text');
            input.style.backgroundColor = input.type === 'file' ? '' : '#fff';
            input.style.opacity = '1';
            
            // Special handling for textareas
            if (input.tagName === 'TEXTAREA') {
                input.style.resize = 'vertical';
            }
        });
        
        // Also enable all file upload buttons
        document.querySelectorAll('button[onclick*="uploadDocument"]').forEach(btn => {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.pointerEvents = 'auto';
            // Update button text if it says "View PDF"
            if (btn.textContent.includes('View PDF')) {
                btn.textContent = btn.textContent.replace('View PDF', 'Upload');
            }
        });
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

    // Duplicate function removed - using the one defined earlier

    // Validate Contact of Agency field (exactly 10 digits) - uses validateContactNumber
    window.validateContactAgency = function(input) {
        validateContactNumber(input);
    }

    // Form validation and submission handler
    // DEPRECATED: validateAndSubmitForm is no longer used - form submits directly via event listener
    // This function is kept for backward compatibility but always returns true
    function validateAndSubmitForm() {
        // Always allow form submission - form handler uses addEventListener instead
        return true;
    }

    // ============================================================================
    // INNOVATION & STARTUP ECOSYSTEM DYNAMIC FIELD GENERATORS
    // ============================================================================

    // Real-time year validation function
    function validateYearRealTime(input) {
        let value = input.value;
        
        // Remove any non-numeric characters
        value = value.replace(/[^0-9]/g, '');
        
        // Limit to 4 digits
        if (value.length > 4) {
            value = value.substring(0, 4);
        }
        
        input.value = value;
        
        // Real-time validation feedback
        const currentYear = new Date().getFullYear();
        const year = parseInt(value);
        
        // Remove existing validation classes
        input.classList.remove('is-valid', 'is-invalid');
        
        if (value.length === 4) {
            if (year >= 2000 && year <= currentYear) {
                input.classList.add('is-valid');
                input.setCustomValidity('');
            } else {
                input.classList.add('is-invalid');
                input.setCustomValidity(`Year must be between 2000 and ${currentYear}`);
            }
        } else if (value.length > 0) {
            input.classList.add('is-invalid');
            input.setCustomValidity('Please enter exactly 4-digit year');
        } else {
            input.setCustomValidity('');
        }
    }

    // Store full implementation
    window._generateDpiitFieldsFull = function() {
        const dpiitElement = document.querySelector('input[name="total_dpiit_startups"]');
        const count = dpiitElement ? parseInt(dpiitElement.value) || 0 : 0;
        const container = document.getElementById('dpiit_startups_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const div = document.createElement('div');
            div.className = 'dynamic-entry mb-3 p-3 border rounded';
            div.innerHTML = `
                <h6 class="text-primary mb-3">DPIIT Startup ${i}</h6>
                <div class="row">
                    <div class="col-md-4">
                        <label for="dpiit_startup_name_${i}" class="form-label">Startup Name</label>
                        <input type="text" class="form-control" id="dpiit_startup_name_${i}" name="dpiit_startup_name_${i}" placeholder="Enter startup name" required>
                    </div>
                    <div class="col-md-4">
                        <label for="dpiit_registration_no_${i}" class="form-label">DPIIT Registration No.</label>
                        <input type="text" class="form-control" id="dpiit_registration_no_${i}" name="dpiit_registration_no_${i}" placeholder="e.g., DIPP73270" required>
                    </div>
                    <div class="col-md-4">
                        <label for="dpiit_year_recognition_${i}" class="form-label">Year of Recognition</label>
                        <input type="number" class="form-control" id="dpiit_year_recognition_${i}" name="dpiit_year_recognition_${i}" placeholder="e.g., 2023" min="2000" max="<?php echo date('Y'); ?>" maxlength="4" oninput="validateYearRealTime(this)" required>
                        <small class="form-text text-muted">Enter exactly 4-digit year (2000-<?php echo date('Y'); ?>)</small>
                    </div>
                </div>
            `;
            container.appendChild(div);
        }
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
    }
    // Assign immediately so stub functions can use it
    window.generateDpiitFields = window._generateDpiitFieldsFull;

    // Store full implementation
    window._generateVcFieldsFull = function() {
        const vcElement = document.querySelector('input[name="total_vc_investments"]');
        const count = vcElement ? parseInt(vcElement.value) || 0 : 0;
        const container = document.getElementById('vc_investments_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const div = document.createElement('div');
            div.className = 'dynamic-entry mb-3 p-3 border rounded';
            div.innerHTML = `
                <h6 class="text-primary mb-3">VC Investment ${i}</h6>
                <div class="row">
                    <div class="col-md-3">
                        <label for="vc_startup_name_${i}" class="form-label">Startup Name</label>
                        <input type="text" class="form-control" id="vc_startup_name_${i}" name="vc_startup_name_${i}" placeholder="Enter startup name" required>
                    </div>
                    <div class="col-md-3">
                        <label for="vc_dpiit_no_${i}" class="form-label">DPIIT No.</label>
                        <input type="text" class="form-control" id="vc_dpiit_no_${i}" name="vc_dpiit_no_${i}" placeholder="e.g., DIPP117363" required>
                    </div>
                    <div class="col-md-3">
                        <label for="vc_amount_${i}" class="form-label">Amount Received (? Lakhs)</label>
                        <input type="number" step="0.01" class="form-control" id="vc_amount_${i}" name="vc_amount_${i}" placeholder="e.g., 50.00" min="0" required>
                        <small class="form-text text-muted">Enter amount in lakhs (e.g., ?50,00,000 = 50.00)</small>
                    </div>
                    <div class="col-md-3">
                        <label for="vc_organization_${i}" class="form-label">Organization</label>
                        <input type="text" class="form-control" id="vc_organization_${i}" name="vc_organization_${i}" placeholder="e.g., Individual" required>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
                        <label for="vc_year_${i}" class="form-label">Year of Receiving</label>
                        <input type="number" class="form-control" id="vc_year_${i}" name="vc_year_${i}" placeholder="e.g., 2023" min="2000" max="<?php echo date('Y'); ?>" maxlength="4" oninput="validateYearRealTime(this)" required>
                        <small class="form-text text-muted">Enter exactly 4-digit year (2000-<?php echo date('Y'); ?>)</small>
                    </div>
                    <div class="col-md-3">
                        <label for="vc_achievement_${i}" class="form-label">Achievement Level</label>
                        <input type="text" class="form-control" id="vc_achievement_${i}" name="vc_achievement_${i}" placeholder="e.g., Actual system proven" required>
                    </div>
                    <div class="col-md-3">
                        <label for="vc_type_${i}" class="form-label">Type of Investment</label>
                        <input type="text" class="form-control" id="vc_type_${i}" name="vc_type_${i}" placeholder="e.g., Equity" required>
                    </div>
                </div>
            `;
            container.appendChild(div);
        }
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
    }
    // Assign immediately
    window.generateVcFields = window._generateVcFieldsFull;

    // Store full implementation
    window._generateSeedFieldsFull = function() {
        const seedElement = document.querySelector('input[name="total_seed_funding"]');
        const count = seedElement ? parseInt(seedElement.value) || 0 : 0;
        const container = document.getElementById('seed_funding_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const div = document.createElement('div');
            div.className = 'dynamic-entry mb-3 p-3 border rounded';
            div.innerHTML = `
                <h6 class="text-primary mb-3">Seed Funding ${i}</h6>
                <div class="row">
                    <div class="col-md-3">
                        <label for="seed_startup_name_${i}" class="form-label">Startup Name</label>
                        <input type="text" class="form-control" id="seed_startup_name_${i}" name="seed_startup_name_${i}" placeholder="Enter startup name" required>
                    </div>
                    <div class="col-md-3">
                        <label for="seed_dpiit_no_${i}" class="form-label">DPIIT No.</label>
                        <input type="text" class="form-control" id="seed_dpiit_no_${i}" name="seed_dpiit_no_${i}" placeholder="e.g., DIPP167282" required>
                    </div>
                    <div class="col-md-3">
                        <label for="seed_amount_${i}" class="form-label">Seed Funding (? Lakhs)</label>
                        <input type="number" step="0.01" class="form-control" id="seed_amount_${i}" name="seed_amount_${i}" placeholder="e.g., 10.00" min="0" required>
                        <small class="form-text text-muted">Enter amount in lakhs (e.g., ?10,00,000 = 10.00)</small>
                    </div>
                    <div class="col-md-3">
                        <label for="seed_organization_${i}" class="form-label">Government Organization</label>
                        <input type="text" class="form-control" id="seed_organization_${i}" name="seed_organization_${i}" placeholder="e.g., Startup India Seed" required>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
                        <label for="seed_year_${i}" class="form-label">Year of Receiving</label>
                        <input type="number" class="form-control" id="seed_year_${i}" name="seed_year_${i}" placeholder="e.g., 2023" min="2000" max="<?php echo date('Y'); ?>" maxlength="4" oninput="validateYearRealTime(this)" required>
                        <small class="form-text text-muted">Enter exactly 4-digit year (2000-<?php echo date('Y'); ?>)</small>
                    </div>
                    <div class="col-md-3">
                        <label for="seed_achievement_${i}" class="form-label">Achievement Level</label>
                        <input type="text" class="form-control" id="seed_achievement_${i}" name="seed_achievement_${i}" placeholder="e.g., system/sub system" required>
                    </div>
                    <div class="col-md-3">
                        <label for="seed_type_${i}" class="form-label">Type of Investment</label>
                        <input type="text" class="form-control" id="seed_type_${i}" name="seed_type_${i}" placeholder="e.g., Grant" required>
                    </div>
                </div>
            `;
            container.appendChild(div);
        }
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
    }
    // Assign immediately
    window.generateSeedFields = window._generateSeedFieldsFull;

    // Store full implementation
    window._generateGrantFieldsFull = function() {
        const grantElement = document.querySelector('input[name="total_innovation_grants"]');
        const count = grantElement ? parseInt(grantElement.value) || 0 : 0;
        const container = document.getElementById('innovation_grants_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const div = document.createElement('div');
            div.className = 'dynamic-entry mb-3 p-3 border rounded';
            div.innerHTML = `
                <h6 class="text-primary mb-3">Innovation Grant ${i}</h6>
                <div class="row">
                    <div class="col-md-3">
                        <label for="grant_organization_${i}" class="form-label">Government Organization</label>
                        <input type="text" class="form-control" id="grant_organization_${i}" name="grant_organization_${i}" placeholder="e.g., Department of Science and Technology" required>
                    </div>
                    <div class="col-md-3">
                        <label for="grant_program_${i}" class="form-label">Program/Scheme Name</label>
                        <input type="text" class="form-control" id="grant_program_${i}" name="grant_program_${i}" placeholder="e.g., NIDHI-Seed Support Program" required>
                    </div>
                    <div class="col-md-3">
                        <label for="grant_amount_${i}" class="form-label">Grant Amount (? Lakhs)</label>
                        <input type="number" step="0.01" class="form-control" id="grant_amount_${i}" name="grant_amount_${i}" placeholder="e.g., 307.50" min="0" required>
                        <small class="form-text text-muted">Enter amount in lakhs (e.g., ?3,07,50,000 = 307.50)</small>
                    </div>
                    <div class="col-md-3">
                        <label for="grant_year_${i}" class="form-label">Year of Receiving</label>
                        <input type="number" class="form-control" id="grant_year_${i}" name="grant_year_${i}" placeholder="e.g., 2023" min="2000" max="<?php echo date('Y'); ?>" maxlength="4" oninput="validateYearRealTime(this)" required>
                        <small class="form-text text-muted">Enter exactly 4-digit year (2000-<?php echo date('Y'); ?>)</small>
                    </div>
                </div>
            `;
            container.appendChild(div);
        }
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
    }
    // Assign immediately
    window.generateGrantFields = window._generateGrantFieldsFull;

    // Store full implementation
    window._generateFdiFieldsFull = function() {
        const fdiElement = document.querySelector('input[name="total_fdi_investments"]');
        const count = fdiElement ? parseInt(fdiElement.value) || 0 : 0;
        const container = document.getElementById('fdi_investments_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const div = document.createElement('div');
            div.className = 'dynamic-entry mb-3 p-3 border rounded';
            div.innerHTML = `
                <h6 class="text-primary mb-3">FDI Investment ${i}</h6>
                <div class="row">
                    <div class="col-md-3">
                        <label for="fdi_startup_name_${i}" class="form-label">Startup Name</label>
                        <input type="text" class="form-control" id="fdi_startup_name_${i}" name="fdi_startup_name_${i}" placeholder="Enter startup name" required>
                    </div>
                    <div class="col-md-3">
                        <label for="fdi_dpiit_no_${i}" class="form-label">DPIIT No.</label>
                        <input type="text" class="form-control" id="fdi_dpiit_no_${i}" name="fdi_dpiit_no_${i}" placeholder="e.g., DIPP117363" required>
                    </div>
                    <div class="col-md-3">
                        <label for="fdi_amount_${i}" class="form-label">FDI Amount (? Lakhs)</label>
                        <input type="number" step="0.01" class="form-control" id="fdi_amount_${i}" name="fdi_amount_${i}" placeholder="e.g., 50.00" min="0" required>
                        <small class="form-text text-muted">Enter amount in lakhs (e.g., ?50,00,000 = 50.00)</small>
                    </div>
                    <div class="col-md-3">
                        <label for="fdi_organization_${i}" class="form-label">Organization</label>
                        <input type="text" class="form-control" id="fdi_organization_${i}" name="fdi_organization_${i}" placeholder="e.g., Foreign Investor" required>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
                        <label for="fdi_year_${i}" class="form-label">Year of Receiving</label>
                        <input type="number" class="form-control" id="fdi_year_${i}" name="fdi_year_${i}" placeholder="e.g., 2023" min="2000" max="<?php echo date('Y'); ?>" maxlength="4" oninput="validateYearRealTime(this)" required>
                        <small class="form-text text-muted">Enter exactly 4-digit year (2000-<?php echo date('Y'); ?>)</small>
                    </div>
                    <div class="col-md-3">
                        <label for="fdi_achievement_${i}" class="form-label">Achievement Level</label>
                        <input type="text" class="form-control" id="fdi_achievement_${i}" name="fdi_achievement_${i}" placeholder="e.g., Actual system proven" required>
                    </div>
                    <div class="col-md-3">
                        <label for="fdi_type_${i}" class="form-label">Type of Investment</label>
                        <input type="text" class="form-control" id="fdi_type_${i}" name="fdi_type_${i}" placeholder="e.g., Equity" required>
                    </div>
                </div>
            `;
            container.appendChild(div);
        }
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
    }
    // Assign immediately
    window.generateFdiFields = window._generateFdiFieldsFull;

    // Store full implementation
    window._generateTrlFieldsFull = function() {
        const trlElement = document.querySelector('input[name="total_trl_innovations"]');
        const count = trlElement ? parseInt(trlElement.value) || 0 : 0;
        const container = document.getElementById('trl_innovations_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const div = document.createElement('div');
            div.className = 'dynamic-entry mb-3 p-3 border rounded';
            div.innerHTML = `
                <h6 class="text-primary mb-3">TRL Innovation ${i}</h6>
                <div class="row">
                    <div class="col-md-3">
                        <label for="trl_startup_name_${i}" class="form-label">Startup Name</label>
                        <input type="text" class="form-control" id="trl_startup_name_${i}" name="trl_startup_name_${i}" placeholder="Enter startup name" required>
                    </div>
                    <div class="col-md-3">
                        <label for="trl_dpiit_no_${i}" class="form-label">DPIIT No.</label>
                        <input type="text" class="form-control" id="trl_dpiit_no_${i}" name="trl_dpiit_no_${i}" placeholder="e.g., DIPP73270" required>
                    </div>
                    <div class="col-md-3">
                        <label for="trl_stage_${i}" class="form-label">Stage Level</label>
                        <select class="form-control" id="trl_stage_${i}" name="trl_stage_${i}" required>
                            <option value="">Select Level</option>
                            <option value="Level 1">Level 1</option>
                            <option value="Level 2">Level 2</option>
                            <option value="Level 3">Level 3</option>
                            <option value="Level 4">Level 4</option>
                            <option value="Level 5">Level 5</option>
                            <option value="Level 6">Level 6</option>
                            <option value="Level 7">Level 7</option>
                            <option value="Level 8">Level 8</option>
                            <option value="Level 9">Level 9</option>
                        </select>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-12">
                        <label for="trl_innovation_name_${i}" class="form-label">Innovation Name</label>
                        <textarea class="form-control" id="trl_innovation_name_${i}" name="trl_innovation_name_${i}" rows="2" placeholder="Describe the innovation..." required></textarea>
                    </div>
                    <div class="col-md-12 mt-2">
                        <label for="trl_achievement_${i}" class="form-label">Achievement Level</label>
                        <input type="text" class="form-control" id="trl_achievement_${i}" name="trl_achievement_${i}" placeholder="e.g., Actual system proven" required>
                    </div>
                </div>
            `;
            container.appendChild(div);
        }
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
    }
    // Assign immediately
    window.generateTrlFields = window._generateTrlFieldsFull;

    // Store full implementation
    window._generateTurnoverFieldsFull = function() {
        const turnoverElement = document.querySelector('input[name="total_turnover_achievements"]');
        const count = turnoverElement ? parseInt(turnoverElement.value) || 0 : 0;
        const container = document.getElementById('turnover_achievements_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const div = document.createElement('div');
            div.className = 'dynamic-entry mb-3 p-3 border rounded';
            div.innerHTML = `
                <h6 class="text-primary mb-3">Turnover Achievement ${i}</h6>
                <div class="row">
                    <div class="col-md-4">
                        <label for="turnover_startup_name_${i}" class="form-label">Startup Name</label>
                        <input type="text" class="form-control" id="turnover_startup_name_${i}" name="turnover_startup_name_${i}" placeholder="Enter startup name" required>
                    </div>
                    <div class="col-md-4">
                        <label for="turnover_dpiit_no_${i}" class="form-label">DPIIT No.</label>
                        <input type="text" class="form-control" id="turnover_dpiit_no_${i}" name="turnover_dpiit_no_${i}" placeholder="e.g., DIPP65825" required>
                    </div>
                    <div class="col-md-4">
                        <label for="turnover_amount_${i}" class="form-label">Company's Turnover</label>
                        <input type="text" class="form-control" id="turnover_amount_${i}" name="turnover_amount_${i}" placeholder="e.g., INR. 1.09 Cr." required>
                    </div>
                </div>
            `;
            container.appendChild(div);
        }
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
    }
    // Assign immediately
    window.generateTurnoverFields = window._generateTurnoverFieldsFull;

    // Store full implementation
    window._generateForbesFieldsFull = function() {
        const forbesElement = document.querySelector('input[name="total_forbes_alumni"]');
        const count = forbesElement ? parseInt(forbesElement.value) || 0 : 0;
        const container = document.getElementById('forbes_alumni_container');
        if (!container) return; // Safety check
        const previousValues = captureFieldValues(container);
        container.innerHTML = '';
        
        for (let i = 1; i <= count; i++) {
            const div = document.createElement('div');
            div.className = 'dynamic-entry mb-3 p-3 border rounded';
            div.innerHTML = `
                <h6 class="text-primary mb-3">Forbes Alumni ${i}</h6>
                <div class="row">
                    <div class="col-md-3">
                        <label for="forbes_program_${i}" class="form-label">Program Name</label>
                        <input type="text" class="form-control" id="forbes_program_${i}" name="forbes_program_${i}" placeholder="Enter program name" required>
                    </div>
                    <div class="col-md-3">
                        <label for="forbes_year_passing_${i}" class="form-label">Year of Passing</label>
                        <input type="number" class="form-control" id="forbes_year_passing_${i}" name="forbes_year_passing_${i}" placeholder="e.g., 2015" min="1950" max="<?php echo date('Y'); ?>" maxlength="4" oninput="validateYearRealTime(this)" required>
                        <small class="form-text text-muted">Enter exactly 4-digit year (1950-<?php echo date('Y'); ?>)</small>
                    </div>
                    <div class="col-md-3">
                        <label for="forbes_company_${i}" class="form-label">Founder Company</label>
                        <input type="text" class="form-control" id="forbes_company_${i}" name="forbes_company_${i}" placeholder="Enter company name" required>
                    </div>
                    <div class="col-md-3">
                        <label for="forbes_year_founded_${i}" class="form-label">Year Founded</label>
                        <input type="number" class="form-control" id="forbes_year_founded_${i}" name="forbes_year_founded_${i}" placeholder="e.g., 2018" min="1950" max="<?php echo date('Y'); ?>" maxlength="4" oninput="validateYearRealTime(this)" required>
                        <small class="form-text text-muted">Enter exactly 4-digit year (1950-<?php echo date('Y'); ?>)</small>
                    </div>
                </div>
            `;
            container.appendChild(div);
        }
        restoreFieldValues(container, previousValues);
        if (isFormLocked && !formUnlocked) lockAllDynamicFields();
    }
    // Assign immediately
    window.generateForbesFields = window._generateForbesFieldsFull;
    window.generatePatentFields = window._generatePatentFieldsFull;

    // ============================================================================
    // FORM SUBMISSION DEBUGGER
    // ============================================================================
    
    function debugFormSubmission() {
        debugLog('=== FORM SUBMISSION DEBUG ===');
        
        const form = document.getElementById('facultyForm');
        if (!form) {
            errorLog('Form element not found!');
            return;
        }
        
        debugLog('Form found:', {
            action: form.action,
            method: form.method,
            id: form.id,
            className: form.className
        });
        
        // Check submit buttons
        const submitButtons = form.querySelectorAll('button[type="submit"]');
        debugLog('Submit buttons found:', submitButtons.length);
        submitButtons.forEach((btn, index) => {
            debugLog(`Submit button ${index}:`, {
                name: btn.name,
                value: btn.value,
                text: btn.textContent.trim(),
                disabled: btn.disabled
            });
        });
        
        // Check required fields
        const requiredFields = ['desc_initiative', 'desc_impact', 'desc_collaboration', 'desc_plan', 'desc_recognition'];
        requiredFields.forEach(fieldId => {
            const element = document.getElementById(fieldId);
            if (element) {
                debugLog(`Required field ${fieldId}:`, {
                    value: element.value,
                    isEmpty: element.value.trim() === '',
                    required: element.required
                });
            } else {
                errorLog(`Required field not found: ${fieldId}`);
            }
        });
        
        // Check startup/innovation fields
        const startupFields = ['total_dpiit_startups', 'total_vc_investments', 'total_seed_funding', 'total_fdi_investments', 'total_innovation_grants', 'total_trl_innovations', 'total_turnover_achievements', 'total_forbes_alumni'];
        startupFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                debugLog(`Startup field ${fieldId}:`, field.value);
            } else {
                errorLog(`Startup field not found: ${fieldId}`);
            }
        });
        
        // Check dynamic field data
        const dpiitStartupNames = document.querySelectorAll('input[name="dpiit_startup_name[]"]');
        debugLog('DPIIT startup names found:', dpiitStartupNames.length);
        dpiitStartupNames.forEach((input, index) => {
            debugLog(`DPIIT startup ${index + 1}:`, input.value);
        });
        
        const vcStartupNames = document.querySelectorAll('input[name="vc_startup_name[]"]');
        debugLog('VC startup names found:', vcStartupNames.length);
        vcStartupNames.forEach((input, index) => {
            debugLog(`VC startup ${index + 1}:`, input.value);
        });
        
        // Check form data
        const formData = new FormData(form);
        debugLog('Form data entries:');
        for (let [key, value] of formData.entries()) {
            debugLog(`  ${key}:`, value);
        }
    }
    // ============================================================================
    // FORM LOCKING FUNCTION
    // ============================================================================
    
    function disableForm() {
        // Disable all form inputs
        const form = document.getElementById('facultyForm');
        if (form) {
            const inputs = form.querySelectorAll('input, textarea, select, button');
            inputs.forEach(input => {
                if (input.type !== 'button' || input.onclick) {
                    input.disabled = true;
                }
            });
            
            // Add visual indication
            form.style.opacity = '0.7';
            form.style.pointerEvents = 'none';
            
            // Show lock message
            const lockMessage = document.createElement('div');
            lockMessage.className = 'alert alert-info text-center';
            lockMessage.innerHTML = '<i class="fas fa-lock me-2"></i>Form has been submitted successfully and is now locked.';
            form.parentNode.insertBefore(lockMessage, form);
        }
    }
    // Enable update function (like StudentSupport.php)
    function enableUpdate() {
        // Ensure all dynamic fields are visible and populated
        formUnlocked = true;
        
        // Show the Save Changes button and Cancel Update button
        const updateBtn = document.getElementById('updateBtn');
        const saveBtn = document.getElementById('saveBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const clearBtn = document.getElementById('clearBtn');
        
        if (updateBtn) updateBtn.style.display = 'none';
        if (saveBtn) saveBtn.style.display = 'inline-block';
        if (cancelBtn) cancelBtn.style.display = 'inline-block';
        if (clearBtn) clearBtn.style.display = 'none'; // Hide Clear Data when in update mode
        
        // Unlock all form fields
        setFormDisabled(false);
        
        // Enable all fields including dynamically generated ones
        enableAllFields();
        
        // CRITICAL: Enable count fields FIRST before regenerating fields
        // Count fields must be enabled so they submit their values
        const publicationsCountEl = document.getElementById('publications_count');
        if (publicationsCountEl) {
            publicationsCountEl.disabled = false;
            publicationsCountEl.readOnly = false;
            publicationsCountEl.removeAttribute('readonly');
            publicationsCountEl.removeAttribute('disabled');
        }
        
        const bibliometricsCountEl = document.getElementById('bibliometrics_count');
        if (bibliometricsCountEl) {
            bibliometricsCountEl.disabled = false;
            bibliometricsCountEl.readOnly = false;
            bibliometricsCountEl.removeAttribute('readonly');
            bibliometricsCountEl.removeAttribute('disabled');
        }
        
        const booksCountEl = document.getElementById('books_count');
        if (booksCountEl) {
            booksCountEl.disabled = false;
            booksCountEl.readOnly = false;
            booksCountEl.removeAttribute('readonly');
            booksCountEl.removeAttribute('disabled');
        }
        
        // CRITICAL: Regenerate all dynamic fields AFTER enabling count fields
        // This ensures fields are generated with enabled state
        // FIXED: Reload data after regenerating fields to prevent missing data (like NEPInitiatives.php)
        setTimeout(function() {
            if (publicationsCountEl && typeof generatePublicationFields === 'function') {
                generatePublicationFields();
                
                // CRITICAL: Reload publication data from database after regenerating fields with retry mechanism
                <?php if (isset($editRow['publications']) && !empty($editRow['publications']) && $editRow['publications'] !== 'null'): 
                    $pub_data = json_decode($editRow['publications'], true);
                    // CRITICAL: Type cast to integer for security (UNIFIED_SECURITY_GUIDE.md requirement)
                    $pub_count = is_array($pub_data) ? (int)count($pub_data) : 0;
                    $base_delay = 400;
                    $dynamic_delay = (int)($base_delay + max(0, ($pub_count - 10) * 20));
                ?>
                function populatePublicationsDataRetry() {
                    try {
                        const publicationsDataJson = <?php echo json_encode($editRow['publications'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                        if (publicationsDataJson) {
                            const publicationsData = JSON.parse(publicationsDataJson);
                            if (Array.isArray(publicationsData) && publicationsData.length > 0) {
                                // CRITICAL: Check if ALL fields exist (especially for 20+ publications)
                                let allFieldsReady = true;
                                for (let idx = 0; idx < publicationsData.length; idx++) {
                                    const i = idx + 1;
                                    if (!document.getElementById('pub_title_' + i)) {
                                        allFieldsReady = false;
                                        break;
                                    }
                                }
                                if (!allFieldsReady) {
                                    // Retry after 100ms if fields not ready (for 20+ publications)
                                    setTimeout(populatePublicationsDataRetry, 100);
                                    return;
                                }
                                // All fields ready - populate data
                                publicationsData.forEach(function(pub, index) {
                                    const i = index + 1;
                                    const pubTitleEl = document.getElementById('pub_title_' + i);
                                    if (pubTitleEl) pubTitleEl.value = (pub.title || '');
                                    const pubTypeEl = document.getElementById('pub_type_' + i);
                                    if (pubTypeEl) pubTypeEl.value = (pub.type || '');
                                    const pubVenueEl = document.getElementById('pub_venue_' + i);
                                    if (pubVenueEl) pubVenueEl.value = (pub.venue || '');
                                    const pubIndexedEl = document.getElementById('pub_indexed_' + i);
                                    if (pubIndexedEl) pubIndexedEl.value = (pub.indexed || '');
                                    const pubAuthorsEl = document.getElementById('pub_authors_' + i);
                                    if (pubAuthorsEl) pubAuthorsEl.value = (pub.authors || '');
                                    const pubDateEl = document.getElementById('pub_date_' + i);
                                    if (pubDateEl) pubDateEl.value = (pub.date || '');
                                    const pubUrlEl = document.getElementById('pub_url_' + i);
                                    if (pubUrlEl) pubUrlEl.value = (pub.url || '');
                                });
                            }
                        }
                    } catch (e) {
                        console.warn('Failed to reload publication data:', e);
                    }
                }
                setTimeout(populatePublicationsDataRetry, <?php echo $dynamic_delay; ?>); // CRITICAL: Dynamic delay with retry (supports 25+ publications)
                <?php endif; ?>
            }
            if (bibliometricsCountEl && typeof generateBibliometricFields === 'function') {
                generateBibliometricFields();
                
                // CRITICAL: Reload bibliometrics data from database after regenerating fields
                <?php if (isset($editRow['bibliometrics']) && !empty($editRow['bibliometrics']) && $editRow['bibliometrics'] !== 'null'): ?>
                setTimeout(function() {
                    try {
                        const bibliometricsDataJson = <?php echo json_encode($editRow['bibliometrics'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                        if (bibliometricsDataJson) {
                            const bibliometricsData = JSON.parse(bibliometricsDataJson);
                            if (Array.isArray(bibliometricsData) && bibliometricsData.length > 0) {
                                bibliometricsData.forEach(function(bib, index) {
                                    const i = index + 1;
                                    const bibTeacherNameEl = document.getElementById('bib_teacher_name_' + i);
                                    if (bibTeacherNameEl) bibTeacherNameEl.value = (bib.name || '');
                                    const bibImpactFactorEl = document.getElementById('bib_impact_factor_' + i);
                                    if (bibImpactFactorEl) bibImpactFactorEl.value = (bib.impact_factor || '');
                                    const bibCitationsEl = document.getElementById('bib_citations_' + i);
                                    if (bibCitationsEl) bibCitationsEl.value = (bib.citations || '');
                                    const bibHIndexEl = document.getElementById('bib_h_index_' + i);
                                    if (bibHIndexEl) bibHIndexEl.value = (bib.h_index || '');
                                });
                            }
                        }
                    } catch (e) {
                        console.warn('Failed to reload bibliometrics data:', e);
                    }
                }, 400); // CRITICAL: Longer delay to ensure ALL fields are fully generated
                <?php endif; ?>
            }
            if (booksCountEl && typeof generateBookFields === 'function') {
                generateBookFields();
                
                // CRITICAL: Reload books data from database after regenerating fields
                <?php if (isset($editRow['books']) && !empty($editRow['books']) && $editRow['books'] !== 'null'): ?>
                setTimeout(function() {
                    try {
                        const booksDataJson = <?php echo json_encode($editRow['books'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                        if (booksDataJson) {
                            const booksData = JSON.parse(booksDataJson);
                            if (Array.isArray(booksData) && booksData.length > 0) {
                                booksData.forEach(function(book, index) {
                                    const i = index + 1;
                                    const bookTitleEl = document.getElementById('book_title_' + i);
                                    if (bookTitleEl) bookTitleEl.value = (book.title || '');
                                    const bookTypeEl = document.getElementById('book_type_' + i);
                                    if (bookTypeEl) {
                                        bookTypeEl.value = (book.type || '');
                                        bookTypeEl.dispatchEvent(new Event('change', { bubbles: true }));
                                    }
                                    const bookAuthorsEl = document.getElementById('book_authors_' + i);
                                    if (bookAuthorsEl) bookAuthorsEl.value = (book.authors || '');
                                    const bookMonthEl = document.getElementById('book_month_' + i);
                                    if (bookMonthEl) {
                                        bookMonthEl.value = (book.month || '');
                                        bookMonthEl.dispatchEvent(new Event('change', { bubbles: true }));
                                    }
                                    const bookYearEl = document.getElementById('book_year_' + i);
                                    if (bookYearEl) {
                                        bookYearEl.value = (book.year || '');
                                        bookYearEl.dispatchEvent(new Event('change', { bubbles: true }));
                                    }
                                    const bookPublisherEl = document.getElementById('book_publisher_' + i);
                                    if (bookPublisherEl) bookPublisherEl.value = (book.publisher || '');
                                });
                            }
                        }
                    } catch (e) {
                        console.warn('Failed to reload books data:', e);
                    }
                }, 400); // CRITICAL: Longer delay to ensure ALL fields are fully generated
                <?php endif; ?>
            }
        }, 200); // CRITICAL: Increased to 200ms for better sequencing with many publications
        
        // Unlock dynamic fields in containers
        const containers = [
            'awards_container', 'projects_container', 'training_container',
            'publications_container', 'bibliometrics_container', 'books_container',
            'research_staff_container', 'patents_container',
            'dpiit_startups_container', 'vc_investments_container',
            'seed_funding_container', 'fdi_investments_container',
            'innovation_grants_container', 'trl_innovations_container',
            'turnover_achievements_container', 'forbes_alumni_container'
        ];
        
        containers.forEach(containerId => {
            const container = document.getElementById(containerId);
            if (container) {
                container.querySelectorAll('input, textarea, select, button').forEach(element => {
                    // Skip system-calculated fields
                    if (element.id === 'total_published' || element.id === 'total_granted' || element.id === 'patents_count') {
                        return;
                    }
                    element.removeAttribute('readonly');
                    element.disabled = false;
                    element.style.opacity = '1';
                    element.style.cursor = element.type === 'file' ? 'pointer' : 'text';
                    element.style.pointerEvents = 'auto';
                    element.style.backgroundColor = element.type === 'file' ? '' : '#fff';
                });
            }
        });
        
        // Remove readonly and disabled from all form inputs
        const form = document.getElementById('facultyForm');
        if (form) {
            form.querySelectorAll('input, textarea, select').forEach(element => {
                // Skip system-calculated fields
                if (element.id === 'total_published' || element.id === 'total_granted' || element.id === 'patents_count') {
                    return;
                }
                // Skip hidden/system fields
                if (element.name === 'academic_year' || element.name === 'dept_info' || element.name === 'csrf_token') {
                    return;
                }
                element.removeAttribute('readonly');
                element.disabled = false;
                if (element.style) {
                    element.style.opacity = '1';
                    element.style.pointerEvents = 'auto';
                    element.style.cursor = element.type === 'file' ? 'pointer' : 'text';
                    element.style.backgroundColor = element.type === 'file' ? '' : '#fff';
                }
            });
            
            // Enable file upload buttons
            form.querySelectorAll('button[onclick*="uploadDocument"]').forEach(btn => {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.pointerEvents = 'auto';
            });
        }
        
        // Re-apply lock state (which should now enable everything since formUnlocked is true)
        applyLockState();
        
        // Refresh all document statuses to show delete buttons in update mode
        // Use longer delay to prevent rapid async calls that might trigger extension errors
        setTimeout(function() {
            try {
                if (typeof refreshAllDocumentStatuses === 'function') {
                    refreshAllDocumentStatuses(); // This function resets the attempted flag
                }
            } catch (error) {
                // Silently handle errors (often from browser extensions)
            }
        }, 1000);
        
        // Show soft message instead of alert popup
        if (typeof showSuccessMessage === 'function') {
            showSuccessMessage('Form unlocked. Make your changes and click "Save Changes" to update the record.');
        }
    }
    // Clear form function
    function clearForm() {
        const form = document.querySelector('form');
        if (form) {
            form.reset();
            
            // Clear file inputs specifically
            const fileInputs = document.querySelectorAll('input[type="file"]');
            fileInputs.forEach(input => {
                input.value = '';
            });
            
            // Clear file status displays
            const statusDivs = document.querySelectorAll('[id$="_status"]');
            statusDivs.forEach(div => {
                div.innerHTML = '<span class="text-muted">No document uploaded</span>';
                div.className = 'mt-2 text-muted';
            });
        }
    }
    
    // Clear file status displays
    function clearFileStatusDisplays() {
        const statusDivs = document.querySelectorAll('[id$="_status"]');
        statusDivs.forEach(div => {
            div.innerHTML = '<span class="text-muted">No document uploaded</span>';
            div.className = 'mt-2 text-muted';
        });
    }
    
    // ============================================================================
    // FORM SUBMISSION HANDLER - REWRITTEN FOR RELIABILITY
    // ============================================================================
    
    (function() {
        function attachFacultySubmitHandler() {
            const form = document.getElementById('facultyForm');
            // Find buttons using multiple methods for reliability
            const submitBtn = form ? (form.querySelector('button[name="save_faculty"]') || document.querySelector('button[name="save_faculty"]')) : null;
            const updateBtn = form ? (form.querySelector('button[name="update_faculty"]') || document.querySelector('button[name="update_faculty"]')) : null;
            
            if (!form) {
                return;
            }
            
            form.addEventListener('submit', function(e) {
                
                // CRITICAL: Declare reusable arrays at the top of the function
                const descFields = ['desc_initiative', 'desc_impact', 'desc_collaboration', 'desc_plan', 'desc_recognition'];
                
                // CRITICAL: Enable ALL disabled/readonly fields BEFORE submission
                // Disabled/readonly fields are NOT included in POST data!
                const allInputs = form.querySelectorAll('input, textarea, select');
                let enabledCount = 0;
                allInputs.forEach(input => {
                    // Skip system-calculated fields and hidden system fields
                    if (input.id === 'total_published' || input.id === 'total_granted' || input.id === 'patents_count') {
                        return;
                    }
                    if (input.name === 'academic_year' || input.name === 'dept_info' || input.name === 'csrf_token') {
                        return;
                    }
                    // CRITICAL: Force enable by removing both attributes FIRST
                    // Sometimes attributes persist even after setting disabled = false
                    if (input.type !== 'hidden' && input.type !== 'file') {
                    input.removeAttribute('readonly');
                        input.removeAttribute('disabled');
                        input.readOnly = false;
                    input.disabled = false;
                        
                        // Double-check and force enable again
                        if (input.hasAttribute('readonly') || input.readOnly || input.hasAttribute('disabled') || input.disabled) {
                        input.removeAttribute('readonly');
                        input.removeAttribute('disabled');
                        input.readOnly = false;
                        input.disabled = false;
                            enabledCount++;
                        }
                    }
                });
                
                // CRITICAL: Specifically enable all fields in sections 8, 9, 10 containers
                const containers = ['publications_container', 'bibliometrics_container', 'books_container'];
                containers.forEach(containerId => {
                    const container = document.getElementById(containerId);
                    if (container) {
                        const fields = container.querySelectorAll('input, textarea, select');
                        fields.forEach(field => {
                            // Force enable multiple times to ensure it works
                            field.removeAttribute('readonly');
                            field.removeAttribute('disabled');
                            field.readOnly = false;
                            field.disabled = false;
                            
                            // Double-check and force again
                            if (field.hasAttribute('readonly') || field.readOnly || field.hasAttribute('disabled') || field.disabled) {
                                field.removeAttribute('readonly');
                                field.removeAttribute('disabled');
                                field.readOnly = false;
                                field.disabled = false;
                            }
                            enabledCount++;
                        });
                    }
                });
                
                // CRITICAL: Enable section 11 (Descriptive Summaries) fields
                // Note: descFields is already declared at the top of this function
                descFields.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) {
                        // Force enable multiple times to ensure it works
                        field.removeAttribute('readonly');
                        field.removeAttribute('disabled');
                        field.readOnly = false;
                        field.disabled = false;
                        
                        // Double-check and force again
                        if (field.hasAttribute('readonly') || field.readOnly || field.hasAttribute('disabled') || field.disabled) {
                            field.removeAttribute('readonly');
                            field.removeAttribute('disabled');
                            field.readOnly = false;
                            field.disabled = false;
                        }
                        
                        // Ensure field is in form
                        if (!field.form || field.form !== form) {
                            // Try to set form attribute
                            if (!field.hasAttribute('form')) {
                                field.setAttribute('form', form.id);
                            }
                        }
                        
                        enabledCount++;
                    }
                });
                
                // CRITICAL: Verify descriptive fields are in FormData
                const testFormData = new FormData(form);
                descFields.forEach(fieldId => {
                    const value = testFormData.get(fieldId);
                    // Verification done silently
                });
                
                // CRITICAL: Ensure count inputs for sections 8, 9, 10 are enabled AND have correct integer values
                const countInputs = ['publications_count', 'bibliometrics_count', 'books_count'];
                countInputs.forEach(countId => {
                    const countInput = document.getElementById(countId);
                    if (countInput) {
                        countInput.removeAttribute('readonly');
                        countInput.removeAttribute('disabled');
                        countInput.readOnly = false;
                        countInput.disabled = false;
                        
                        // CRITICAL: Ensure value is an integer, not a string concatenation
                        const currentValue = countInput.value;
                        const intValue = parseInt(currentValue, 10);
                        if (!isNaN(intValue) && String(intValue) !== currentValue) {
                            countInput.value = intValue;
                        }
                        
                    }
                });
                
                // Verify count inputs have correct values before creating FormData
                const pubCountEl = document.getElementById('publications_count');
                const bibCountEl = document.getElementById('bibliometrics_count');
                const booksCountEl = document.getElementById('books_count');
                
                // CRITICAL: Ensure fields are generated if count > 0 but no fields exist
                // This prevents data loss when count is set but fields weren't generated
                // CRITICAL: If fields need to be generated, prevent default and generate them first
                let needsFieldGeneration = false;
                
                if (pubCountEl) {
                    const pubCount = parseInt(pubCountEl.value) || 0;
                    if (pubCount > 0) {
                        const pubContainer = document.getElementById('publications_container');
                        const pubFields = pubContainer ? pubContainer.querySelectorAll('[name^="pub_"]').length : 0;
                        if (pubFields === 0) {
                            if (typeof generatePublicationFields === 'function') {
                                generatePublicationFields();
                                needsFieldGeneration = true;
                            }
                        }
                    }
                }
                if (bibCountEl) {
                    const bibCount = parseInt(bibCountEl.value) || 0;
                    if (bibCount > 0) {
                        const bibContainer = document.getElementById('bibliometrics_container');
                        const bibFields = bibContainer ? bibContainer.querySelectorAll('[name^="bib_"]').length : 0;
                        if (bibFields === 0) {
                            if (typeof generateBibliometricFields === 'function') {
                                generateBibliometricFields();
                                needsFieldGeneration = true;
                            }
                        }
                    }
                }
                if (booksCountEl) {
                    const booksCount = parseInt(booksCountEl.value) || 0;
                    if (booksCount > 0) {
                        const booksContainer = document.getElementById('books_container');
                        const booksFields = booksContainer ? booksContainer.querySelectorAll('[name^="book_"]').length : 0;
                        if (booksFields === 0) {
                            if (typeof generateBookFields === 'function') {
                                generateBookFields();
                                needsFieldGeneration = true;
                            }
                        }
                    }
                }
                
                // CRITICAL: If fields were generated, wait a moment for them to be added to DOM, then submit
                if (needsFieldGeneration) {
                    e.preventDefault(); // Prevent immediate submission
                    setTimeout(() => {
                        form.submit(); // Submit after fields are generated
                    }, 100); // Short delay to allow DOM to update
                    return; // Exit early - form will submit via setTimeout
                }
                
                // Ensure count values are integers
                if (pubCountEl) {
                    const pubVal = parseInt(pubCountEl.value, 10);
                    if (!isNaN(pubVal) && String(pubVal) !== pubCountEl.value) {
                        pubCountEl.value = pubVal;
                    }
                }
                if (bibCountEl) {
                    const bibVal = parseInt(bibCountEl.value, 10);
                    if (!isNaN(bibVal) && String(bibVal) !== bibCountEl.value) {
                        bibCountEl.value = bibVal;
                    }
                }
                if (booksCountEl) {
                    const booksVal = parseInt(booksCountEl.value, 10);
                    if (!isNaN(booksVal) && String(booksVal) !== booksCountEl.value) {
                        booksCountEl.value = booksVal;
                    }
                }
                
                // Show loading state on button
                let activeBtn = null;
                if (submitBtn && submitBtn.offsetParent !== null && !submitBtn.disabled) {
                    activeBtn = submitBtn;
                } else if (updateBtn && updateBtn.offsetParent !== null && !updateBtn.disabled) {
                    activeBtn = updateBtn;
                }
                
                if (activeBtn) {
                    const originalHtml = activeBtn.innerHTML;
                    activeBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
                    activeBtn.disabled = true;
                    
                    // Re-enable after 10 seconds if still on page (fallback)
                    setTimeout(() => {
                        if (activeBtn && activeBtn.disabled) {
                            activeBtn.disabled = false;
                            activeBtn.innerHTML = originalHtml;
                        }
                    }, 10000);
                }
                
                // CRITICAL: Final verification - ensure all critical fields are enabled
                const finalCheck = ['publications_count', 'bibliometrics_count', 'books_count', 
                                   'desc_initiative', 'desc_impact', 'desc_collaboration', 'desc_plan', 'desc_recognition'];
                finalCheck.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) {
                        if (field.disabled || field.readOnly || field.hasAttribute('disabled') || field.hasAttribute('readonly')) {
                            field.removeAttribute('readonly');
                            field.removeAttribute('disabled');
                            field.readOnly = false;
                            field.disabled = false;
                        }
                    }
                });
                
                // CRITICAL: Ensure submit button is included in form before submission
                // When using form.submit(), the button is NOT included automatically
                // Determine which button should be used based on form state
                let buttonName = null;
                let buttonValue = '1';
                
                // Check if update button exists (form is locked)
                if (updateBtn || form.querySelector('button[name="update_faculty"]')) {
                    buttonName = 'update_faculty';
                } 
                // Check if save button exists (form is unlocked)
                else if (submitBtn || form.querySelector('button[name="save_faculty"]')) {
                    buttonName = 'save_faculty';
                }
                
                // CRITICAL: Add hidden input to ensure button name/value is in POST
                if (buttonName) {
                    // Remove existing hidden input if any
                    const existingHidden = form.querySelector(`input[name="${buttonName}"][type="hidden"]`);
                    if (existingHidden) {
                        existingHidden.remove();
                    }
                    
                    // Add hidden input with button name/value
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = buttonName;
                    hiddenInput.value = buttonValue;
                    form.appendChild(hiddenInput);
                } else {
                    // Last resort: Check form lock state from PHP
                    const isFormLocked = <?php echo isset($form_locked) && $form_locked ? 'true' : 'false'; ?>;
                    if (isFormLocked) {
                        buttonName = 'update_faculty';
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = buttonName;
                        hiddenInput.value = '1';
                        form.appendChild(hiddenInput);
                    } else {
                        buttonName = 'save_faculty';
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = buttonName;
                        hiddenInput.value = '1';
                        form.appendChild(hiddenInput);
                    }
                }
                
                // ============================================================================
                // CRITICAL: Collect dynamic JSON data from sections 8, 9, 10 before submission
                // ============================================================================
                // === COLLECT PUBLICATIONS ===
                const publications = [];
                const pubCount = parseInt(document.getElementById('publications_count')?.value) || 0;
                for (let i = 1; i <= pubCount; i++) {
                    const title = document.getElementById(`pub_title_${i}`)?.value.trim() || '';
                    const type = document.getElementById(`pub_type_${i}`)?.value || '';
                    const venue = document.getElementById(`pub_venue_${i}`)?.value.trim() || '';
                    const indexed = document.getElementById(`pub_indexed_${i}`)?.value.trim() || '';
                    const authors = document.getElementById(`pub_authors_${i}`)?.value.trim() || '';
                    const date = document.getElementById(`pub_date_${i}`)?.value || '';
                    const url = document.getElementById(`pub_url_${i}`)?.value.trim() || '';
                    
                    // Include if ANY field has data OR if count > 0 (user set count, so save even if partially empty)
                    if (pubCount > 0 || title || type || venue || indexed || authors || date || url) {
                        publications.push({ 
                            title, 
                            type, 
                            venue, 
                            indexed, 
                            authors, 
                            date, 
                            url 
                        });
                    }
                }
                const publicationsJson = JSON.stringify(publications);
                document.getElementById('publications_json').value = publicationsJson;
                
                // === COLLECT BIBLIOMETRICS ===
                const bibliometrics = [];
                const bibCount = parseInt(document.getElementById('bibliometrics_count')?.value) || 0;
                for (let i = 1; i <= bibCount; i++) {
                    const name = document.getElementById(`bib_teacher_name_${i}`)?.value.trim() || '';
                    const impact = document.getElementById(`bib_impact_factor_${i}`)?.value || '';
                    const citations = document.getElementById(`bib_citations_${i}`)?.value || '';
                    const hindex = document.getElementById(`bib_h_index_${i}`)?.value || '';
                    
                    // Include if ANY field has data OR if count > 0
                    if (bibCount > 0 || name || impact || citations || hindex) {
                        bibliometrics.push({ 
                            name, 
                            impact_factor: impact, 
                            citations, 
                            h_index: hindex 
                        });
                    }
                }
                const bibliometricsJson = JSON.stringify(bibliometrics);
                document.getElementById('bibliometrics_json').value = bibliometricsJson;
                
                // === COLLECT BOOKS ===
                const books = [];
                const bookCount = parseInt(document.getElementById('books_count')?.value) || 0;
                for (let i = 1; i <= bookCount; i++) {
                    const title = document.getElementById(`book_title_${i}`)?.value.trim() || '';
                    const type = document.getElementById(`book_type_${i}`)?.value || '';
                    const authors = document.getElementById(`book_authors_${i}`)?.value.trim() || '';
                    const month = document.getElementById(`book_month_${i}`)?.value || '';
                    const year = document.getElementById(`book_year_${i}`)?.value || '';
                    const publisher = document.getElementById(`book_publisher_${i}`)?.value.trim() || '';
                    
                    // CRITICAL: Only include if at least ONE field has actual data (not just count > 0)
                    // This prevents saving empty entries when user sets count but doesn't fill fields
                    if (title || type || authors || month || year || publisher) {
                        books.push({ 
                            title, 
                            type, 
                            authors, 
                            month, 
                            year, 
                            publisher 
                        });
                    }
                }
                const booksJson = JSON.stringify(books);
                document.getElementById('books_json').value = booksJson;
                
                
                // Allow form to submit normally - no delay needed
                // Form will submit with all collected data
            });
            
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', attachFacultySubmitHandler);
        } else {
            attachFacultySubmitHandler();
        }
    })();
    
    // ============================================================================
    // ASSIGN FULL IMPLEMENTATIONS TO GLOBAL FUNCTION NAMES
    // ============================================================================
    // Store full implementations for stub functions - the real functions are defined above
    // We need to overwrite the stub functions with the real implementations
    // This code runs after all function definitions are complete
    (function assignFullFunctions() {
        // Store references to the real implementations before they get overwritten
        const realFunctions = {};
        
        // Check if functions are defined (they should be by this point in the script)
        // We'll assign them directly to replace the stubs
        try {
            // The real implementations are in the same scope, so we can access them
            // Store them with different names first, then assign
            if (typeof generateAwardFields !== 'undefined' && generateAwardFields.toString().indexOf('setTimeout') === -1) {
                // This is the real implementation, not the stub
                realFunctions.generateAwardFields = generateAwardFields;
            }
        } catch(e) {}
        
        // Assign after DOM is ready
        function doAssign() {
            // Direct assignment - the real functions will overwrite the stubs
            // They're already defined above in this script block
            if (typeof window._generateAwardFieldsFull !== 'undefined') {
                window.generateAwardFields = window._generateAwardFieldsFull;
            }
            if (typeof window._generateProjectFieldsFull !== 'undefined') {
                window.generateProjectFields = window._generateProjectFieldsFull;
            }
            if (typeof window._generateTrainingFieldsFull !== 'undefined') {
                window.generateTrainingFields = window._generateTrainingFieldsFull;
            }
            if (typeof window._generatePublicationFieldsFull !== 'undefined') {
                window.generatePublicationFields = window._generatePublicationFieldsFull;
            }
            if (typeof window._generateBibliometricFieldsFull !== 'undefined') {
                window.generateBibliometricFields = window._generateBibliometricFieldsFull;
            }
            if (typeof window._generateBookFieldsFull !== 'undefined') {
                window.generateBookFields = window._generateBookFieldsFull;
            }
            if (typeof window._generateResearchStaffFieldsFull !== 'undefined') {
                window.generateResearchStaffFields = window._generateResearchStaffFieldsFull;
            }
            if (typeof window._generateDpiitFieldsFull !== 'undefined') {
                window.generateDpiitFields = window._generateDpiitFieldsFull;
            }
            if (typeof window._generateVcFieldsFull !== 'undefined') {
                window.generateVcFields = window._generateVcFieldsFull;
            }
            if (typeof window._generateSeedFieldsFull !== 'undefined') {
                window.generateSeedFields = window._generateSeedFieldsFull;
            }
            if (typeof window._generateFdiFieldsFull !== 'undefined') {
                window.generateFdiFields = window._generateFdiFieldsFull;
            }
            if (typeof window._generateGrantFieldsFull !== 'undefined') {
                window.generateGrantFields = window._generateGrantFieldsFull;
            }
            if (typeof window._generateTrlFieldsFull !== 'undefined') {
                window.generateTrlFields = window._generateTrlFieldsFull;
            }
            if (typeof window._generateTurnoverFieldsFull !== 'undefined') {
                window.generateTurnoverFields = window._generateTurnoverFieldsFull;
            }
            if (typeof window._generateForbesFieldsFull !== 'undefined') {
                window.generateForbesFields = window._generateForbesFieldsFull;
            }
            if (typeof window._generatePatentFieldsFull !== 'undefined') {
                window.generatePatentFields = window._generatePatentFieldsFull;
            }
            if (typeof window._calculatePatentTotalsFull !== 'undefined') {
                window.calculatePatentTotals = window._calculatePatentTotalsFull;
            }
        }
        
        // Run immediately and also on DOMContentLoaded for safety
        setTimeout(doAssign, 100);
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', doAssign);
        }
    })();
    
    // Store full implementations immediately after they're defined (before this assignment code)
    // This must happen AFTER the function definitions but BEFORE the assignment above

    // Initialize on page load - only load documents once, with delay
    document.addEventListener('DOMContentLoaded', function() {
        // CRITICAL: Generate fields for publications, bibliometrics, and books based on count field values
        // This ensures fields are visible even if data exists in database
        function tryGenerateFields() {
            // Helper function to try calling field generation with multiple fallbacks
            function callGenerateFunction(fnName, displayName) {
                try {
                    if (typeof window[fnName] === 'function' && window[fnName].toString().indexOf('setTimeout') === -1) {
                        window[fnName]();
                        return true;
                    } else if (typeof window['_' + fnName + 'Full'] === 'function') {
                        window['_' + fnName + 'Full']();
                        return true;
                    }
                } catch(e) {
                    // Silently handle errors
                }
                return false;
            }
            
            // Generate publication fields if count > 0
            const pubCountEl = document.getElementById('publications_count');
            if (pubCountEl) {
                const count = parseInt(pubCountEl.value) || 0;
                if (count > 0) {
                    if (!callGenerateFunction('generatePublicationFields', 'Publication Fields')) {
                        // Retry after delay if function not ready
                        setTimeout(function() {
                            callGenerateFunction('generatePublicationFields', 'Publication Fields (retry)');
                        }, 300);
                    }
                }
            }
            
            // Generate bibliometric fields if count > 0
            const bibCountEl = document.getElementById('bibliometrics_count');
            if (bibCountEl) {
                const count = parseInt(bibCountEl.value) || 0;
                if (count > 0) {
                    if (!callGenerateFunction('generateBibliometricFields', 'Bibliometric Fields')) {
                        // Retry after delay if function not ready
                        setTimeout(function() {
                            callGenerateFunction('generateBibliometricFields', 'Bibliometric Fields (retry)');
                        }, 300);
                    }
                }
            }
            
            // Generate book fields if count > 0
            const bookCountEl = document.getElementById('books_count');
            if (bookCountEl) {
                const count = parseInt(bookCountEl.value) || 0;
                if (count > 0) {
                    if (!callGenerateFunction('generateBookFields', 'Book Fields')) {
                        // Retry after delay if function not ready
                        setTimeout(function() {
                            callGenerateFunction('generateBookFields', 'Book Fields (retry)');
                        }, 300);
                    }
                }
            }
        }
        
        // Try immediately, then again after delays to ensure functions are loaded
        setTimeout(tryGenerateFields, 500);
        setTimeout(tryGenerateFields, 1000);
        setTimeout(tryGenerateFields, 1500);
        
        // NOTE: loadExistingDocuments() and applyLockState() are now handled by initializePage()
        // which is called from window.onload and DOMContentLoaded (single initialization point)
        
        // Existing data loading is handled by PHP-generated code above (if $editRow exists)
        
        // Clear form if it was successfully submitted
        <?php if (isset($_SESSION['clear_form'])): ?>
            clearForm();
            <?php unset($_SESSION['clear_form']); ?>
        <?php endif; ?>
    });
    
    // Populate form fields when editing (like StudentSupport.php - use PHP-generated code)
    <?php if ($editRow): ?>
    document.addEventListener('DOMContentLoaded', function() {
        // Generate all fields first (synchronously, no delays)
        <?php
        // Load basic scalar fields first
        $basic_fields = [
            'other_recognitions', 'infra_funding', 'innovation_courses', 'innovation_training',
            'patents_published_2022', 'patents_published_2023', 'patents_published_2024',
            'patents_granted_2022', 'patents_granted_2023', 'patents_granted_2024',
            'copyrights_published', 'copyrights_granted', 'designs_published', 'designs_granted', 'tot_count',
            'desc_initiative', 'desc_impact', 'desc_collaboration', 'desc_plan', 'desc_recognition',
            'research_staff_male', 'research_staff_female', 'research_staff_other',
            'sponsored_projects_total', 'sponsored_projects_agencies', 'sponsored_amount_agencies',
            'sponsored_projects_industries', 'sponsored_amount_industries',
            'total_dpiit_startups', 'total_vc_investments', 'total_seed_funding',
            'total_fdi_investments', 'total_innovation_grants', 'total_trl_innovations',
            'total_turnover_achievements', 'total_forbes_alumni',
            'awards_count', 'projects_count', 'training_count',
            'publications_count', 'bibliometrics_count', 'books_count', 'patents_count'
        ];
        foreach ($basic_fields as $field) {
            if (isset($editRow[$field])) {
                // CRITICAL: Use json_encode to properly escape JavaScript strings (handles quotes, newlines, etc.)
                $value_js = json_encode($editRow[$field], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
                echo "if (document.querySelector('[name=\"$field\"]')) document.querySelector('[name=\"$field\"]').value = $value_js;\n";
            }
        }
        
        // Trigger patent totals calculation after loading patent counts
        if (isset($editRow['patents_published_2022']) || isset($editRow['patents_published_2023']) || isset($editRow['patents_published_2024']) ||
            isset($editRow['patents_granted_2022']) || isset($editRow['patents_granted_2023']) || isset($editRow['patents_granted_2024'])) {
            echo "setTimeout(function() {\n";
            echo "    if (typeof calculatePatentTotals === 'function') calculatePatentTotals();\n";
            echo "}, 100);\n";
        }
        
        // Load recognitions checkboxes
        if (!empty($editRow['recognitions'])) {
            $recognitions = json_decode($editRow['recognitions'], true);
            if ($recognitions && is_array($recognitions)) {
                foreach ($recognitions as $rec) {
                    $rec_escaped = htmlspecialchars($rec, ENT_QUOTES, 'UTF-8');
                    echo "if (document.querySelector('input[value=\"$rec_escaped\"]')) document.querySelector('input[value=\"$rec_escaped\"]').checked = true;\n";
                }
            }
        }
        
        // Load research staff details
        // Generate research staff fields
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['research_staff_details'])) {
            $research_staff_data = json_decode($editRow['research_staff_details'], true);
            if ($research_staff_data && is_array($research_staff_data) && count($research_staff_data) > 0) {
                // Set the count inputs first before generating fields
                $male_count = (int)($editRow['research_staff_male'] ?? 0);
                $female_count = (int)($editRow['research_staff_female'] ?? 0);
                $other_count = (int)($editRow['research_staff_other'] ?? 0);
                echo "setTimeout(function() {\n";
                echo "    const researchStaffMaleEl = document.getElementById('research_staff_male');\n";
                echo "    const researchStaffFemaleEl = document.getElementById('research_staff_female');\n";
                echo "    const researchStaffOtherEl = document.getElementById('research_staff_other');\n";
                echo "    if (researchStaffMaleEl) researchStaffMaleEl.value = $male_count;\n";
                echo "    if (researchStaffFemaleEl) researchStaffFemaleEl.value = $female_count;\n";
                echo "    if (researchStaffOtherEl) researchStaffOtherEl.value = $other_count;\n";
                echo "    if (typeof generateResearchStaffFields === 'function') {\n";
                echo "        generateResearchStaffFields();\n";
                echo "        // CRITICAL: Load data AFTER fields are generated\n";
                echo "        setTimeout(function() {\n";
                $research_staff_json = json_encode($research_staff_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "            const researchStaffData = " . $research_staff_json . ";\n";
                echo "            if (Array.isArray(researchStaffData) && researchStaffData.length > 0) {\n";
                echo "                researchStaffData.forEach(function(staff, index) {\n";
                echo "                    const i = index + 1;\n";
                echo "                    const agencyField = document.getElementById('research_staff_agency_' + i);\n";
                echo "                    if (agencyField) agencyField.value = (staff.agency || '');\n";
                echo "                    const amountField = document.getElementById('research_staff_amount_' + i);\n";
                echo "                    if (amountField) amountField.value = (staff.amount || '');\n";
                echo "                    const genderField = document.getElementById('research_staff_gender_' + i);\n";
                echo "                    if (genderField) genderField.value = (staff.gender || 'male');\n";
                echo "                });\n";
                echo "            }\n";
                echo "        }, 400); // CRITICAL: Longer delay to ensure ALL fields are fully generated\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        
        // Generate award fields
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['awards'])) {
            $awards_data = json_decode($editRow['awards'], true);
            if ($awards_data && is_array($awards_data) && count($awards_data) > 0) {
                echo "setTimeout(function() {\n";
                echo "    const awardsCountEl = document.getElementById('awards_count');\n";
                echo "    if (awardsCountEl) {\n";
                echo "        awardsCountEl.value = " . count($awards_data) . ";\n";
                echo "        if (typeof generateAwardFields === 'function') {\n";
                echo "            generateAwardFields();\n";
                echo "            // CRITICAL: Load data AFTER fields are generated\n";
                echo "            setTimeout(function() {\n";
                $awards_json = json_encode($awards_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const awardsData = " . $awards_json . ";\n";
                echo "                if (Array.isArray(awardsData) && awardsData.length > 0) {\n";
                echo "                    awardsData.forEach(function(award, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const awardNameEl = document.getElementById('award_name_' + i);\n";
                echo "                        if (awardNameEl) awardNameEl.value = (award.name || '');\n";
                echo "                        const awardRecognitionBodyEl = document.getElementById('award_recognition_body_' + i);\n";
                echo "                        if (awardRecognitionBodyEl) awardRecognitionBodyEl.value = (award.recognition_body || '');\n";
                echo "                        const awardLevelEl = document.getElementById('award_level_' + i);\n";
                echo "                        if (awardLevelEl) awardLevelEl.value = (award.level || '');\n";
                echo "                        const awardTitleEl = document.getElementById('award_title_' + i);\n";
                echo "                        if (awardTitleEl) awardTitleEl.value = (award.title || '');\n";
                echo "                        const awardAgencyEl = document.getElementById('award_agency_' + i);\n";
                echo "                        if (awardAgencyEl) awardAgencyEl.value = (award.agency || '');\n";
                echo "                        const awardDateEl = document.getElementById('award_date_' + i);\n";
                echo "                        if (awardDateEl) awardDateEl.value = (award.date || '');\n";
                echo "                        const awardAgencyAddressEl = document.getElementById('award_agency_address_' + i);\n";
                echo "                        if (awardAgencyAddressEl) awardAgencyAddressEl.value = (award.agency_address || '');\n";
                echo "                        const awardAgencyEmailEl = document.getElementById('award_agency_email_' + i);\n";
                echo "                        if (awardAgencyEmailEl) awardAgencyEmailEl.value = (award.agency_email || '');\n";
                echo "                        const awardAgencyContactEl = document.getElementById('award_agency_contact_' + i);\n";
                echo "                        if (awardAgencyContactEl) awardAgencyContactEl.value = (award.agency_contact || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400); // CRITICAL: Longer delay to ensure ALL fields are fully generated\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        
        // Generate project fields
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['projects'])) {
            $projects_data = json_decode($editRow['projects'], true);
            if ($projects_data && is_array($projects_data) && count($projects_data) > 0) {
                echo "setTimeout(function() {\n";
                echo "    const projectsCountEl = document.getElementById('projects_count');\n";
                echo "    if (projectsCountEl) {\n";
                echo "        projectsCountEl.value = " . count($projects_data) . ";\n";
                echo "        if (typeof generateProjectFields === 'function') {\n";
                echo "            generateProjectFields();\n";
                echo "            // CRITICAL: Load data AFTER fields are generated\n";
                echo "            setTimeout(function() {\n";
                $projects_json = json_encode($projects_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const projectsData = " . $projects_json . ";\n";
                echo "                if (Array.isArray(projectsData) && projectsData.length > 0) {\n";
                echo "                    projectsData.forEach(function(project, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const projectTitleEl = document.getElementById('project_title_' + i);\n";
                echo "                        if (projectTitleEl) projectTitleEl.value = (project.title || '');\n";
                echo "                        const projectTypeEl = document.getElementById('project_type_' + i);\n";
                echo "                        if (projectTypeEl) projectTypeEl.value = (project.type || '');\n";
                echo "                        const projectAgencyEl = document.getElementById('project_agency_' + i);\n";
                echo "                        if (projectAgencyEl) projectAgencyEl.value = (project.agency || '');\n";
                echo "                        const projectInvestigatorsEl = document.getElementById('project_investigators_' + i);\n";
                echo "                        if (projectInvestigatorsEl) projectInvestigatorsEl.value = (project.investigators || '');\n";
                echo "                        const projectStartDateEl = document.getElementById('project_start_date_' + i);\n";
                echo "                        if (projectStartDateEl) projectStartDateEl.value = (project.start_date || '');\n";
                echo "                        const projectEndDateEl = document.getElementById('project_end_date_' + i);\n";
                echo "                        if (projectEndDateEl) projectEndDateEl.value = (project.end_date || '');\n";
                echo "                        const projectAmountEl = document.getElementById('project_amount_' + i);\n";
                echo "                        if (projectAmountEl) projectAmountEl.value = (project.amount || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400); // CRITICAL: Longer delay to ensure ALL fields are fully generated\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        
        // Generate training fields
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['trainings'])) {
            $trainings_data = json_decode($editRow['trainings'], true);
            if ($trainings_data && is_array($trainings_data) && count($trainings_data) > 0) {
                echo "setTimeout(function() {\n";
                echo "    const trainingCountEl = document.getElementById('training_count');\n";
                echo "    if (trainingCountEl) {\n";
                echo "        trainingCountEl.value = " . count($trainings_data) . ";\n";
                echo "        if (typeof generateTrainingFields === 'function') {\n";
                echo "            generateTrainingFields();\n";
                echo "            // CRITICAL: Load data AFTER fields are generated\n";
                echo "            setTimeout(function() {\n";
                $trainings_json = json_encode($trainings_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const trainingsData = " . $trainings_json . ";\n";
                echo "                if (Array.isArray(trainingsData) && trainingsData.length > 0) {\n";
                echo "                    trainingsData.forEach(function(training, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const trainingNameEl = document.getElementById('training_name_' + i);\n";
                echo "                        if (trainingNameEl) trainingNameEl.value = (training.name || '');\n";
                echo "                        const trainingCorpEl = document.getElementById('training_corp_' + i);\n";
                echo "                        if (trainingCorpEl) trainingCorpEl.value = (training.corp || '');\n";
                echo "                        const trainingRevenueEl = document.getElementById('training_revenue_' + i);\n";
                echo "                        if (trainingRevenueEl) trainingRevenueEl.value = (training.revenue || '');\n";
                echo "                        const trainingParticipantsEl = document.getElementById('training_participants_' + i);\n";
                echo "                        if (trainingParticipantsEl) trainingParticipantsEl.value = (training.participants || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400); // CRITICAL: Longer delay to ensure ALL fields are fully generated\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        
        // Generate publication fields
        // CRITICAL: Check both 'publications' field and ensure it's valid JSON
        // FIXED: Use consolidated approach with dynamic delay and retry mechanism for 20+ publications
        if (isset($editRow['publications']) && !empty($editRow['publications']) && $editRow['publications'] !== 'null') {
            $publications_data = json_decode($editRow['publications'], true);
            // CRITICAL: Ensure decoded data is valid array with data
            if ($publications_data && is_array($publications_data) && count($publications_data) > 0) {
                // CRITICAL: Type cast to integer for security (UNIFIED_SECURITY_GUIDE.md requirement)
                $pub_count = (int)count($publications_data);
                // CRITICAL: Dynamic delay based on count - more publications need more time to render
                // Base delay: 400ms, add 20ms per publication beyond 10 (e.g., 20 pubs = 400 + 200 = 600ms)
                $base_delay = 400;
                $dynamic_delay = (int)($base_delay + max(0, ($pub_count - 10) * 20));
                // CRITICAL: Generate fields FIRST with longer timeout to ensure DOM is ready
                echo "setTimeout(function() {\n";
                echo "    const publicationsCountEl = document.getElementById('publications_count');\n";
                echo "    if (publicationsCountEl) {\n";
                echo "        publicationsCountEl.value = " . $pub_count . ";\n";
                echo "        if (typeof generatePublicationFields === 'function') {\n";
                echo "            generatePublicationFields();\n";
                echo "            // CRITICAL: Load data AFTER fields are generated with retry mechanism for 20+ publications\n";
                echo "            function populatePublicationsData() {\n";
                // Encode publications data as JSON for JavaScript
                $publications_json = json_encode($publications_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const publicationsData = " . $publications_json . ";\n";
                echo "                if (Array.isArray(publicationsData) && publicationsData.length > 0) {\n";
                echo "                    let allFieldsReady = true;\n";
                echo "                    // Check if ALL fields exist (especially for 20+ publications)\n";
                echo "                    for (let idx = 0; idx < publicationsData.length; idx++) {\n";
                echo "                        const i = idx + 1;\n";
                echo "                        if (!document.getElementById('pub_title_' + i)) {\n";
                echo "                            allFieldsReady = false;\n";
                echo "                            break;\n";
                echo "                        }\n";
                echo "                    }\n";
                echo "                    if (!allFieldsReady) {\n";
                echo "                        // Retry after 100ms if fields not ready (for 20+ publications)\n";
                echo "                        setTimeout(populatePublicationsData, 100);\n";
                echo "                        return;\n";
                echo "                    }\n";
                echo "                    // All fields ready - populate data\n";
                echo "                    publicationsData.forEach(function(pub, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const pubTitleEl = document.getElementById('pub_title_' + i);\n";
                echo "                        if (pubTitleEl) pubTitleEl.value = (pub.title || '');\n";
                echo "                        const pubTypeEl = document.getElementById('pub_type_' + i);\n";
                echo "                        if (pubTypeEl) pubTypeEl.value = (pub.type || '');\n";
                echo "                        const pubVenueEl = document.getElementById('pub_venue_' + i);\n";
                echo "                        if (pubVenueEl) pubVenueEl.value = (pub.venue || '');\n";
                echo "                        const pubIndexedEl = document.getElementById('pub_indexed_' + i);\n";
                echo "                        if (pubIndexedEl) pubIndexedEl.value = (pub.indexed || '');\n";
                echo "                        const pubAuthorsEl = document.getElementById('pub_authors_' + i);\n";
                echo "                        if (pubAuthorsEl) pubAuthorsEl.value = (pub.authors || '');\n";
                echo "                        const pubDateEl = document.getElementById('pub_date_' + i);\n";
                echo "                        if (pubDateEl) pubDateEl.value = (pub.date || '');\n";
                echo "                        const pubUrlEl = document.getElementById('pub_url_' + i);\n";
                echo "                        if (pubUrlEl) pubUrlEl.value = (pub.url || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }\n";
                echo "            setTimeout(populatePublicationsData, " . $dynamic_delay . "); // CRITICAL: Dynamic delay based on count (supports 25+ publications)\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 300); // CRITICAL: Increased to 300ms for better DOM readiness with many publications\n";
            }
        }
        
        // Generate bibliometric fields
        // CRITICAL: Check both 'bibliometrics' field and ensure it's valid JSON
        // FIXED: Use consolidated approach to prevent race conditions and missing data (like publications)
        if (isset($editRow['bibliometrics']) && !empty($editRow['bibliometrics']) && $editRow['bibliometrics'] !== 'null') {
            $bibliometrics_data = json_decode($editRow['bibliometrics'], true);
            // CRITICAL: Ensure decoded data is valid array with data
            if ($bibliometrics_data && is_array($bibliometrics_data) && count($bibliometrics_data) > 0) {
                // CRITICAL: Generate fields FIRST with longer timeout to ensure DOM is ready
                echo "setTimeout(function() {\n";
                echo "    const bibliometricsCountEl = document.getElementById('bibliometrics_count');\n";
                echo "    if (bibliometricsCountEl) {\n";
                echo "        bibliometricsCountEl.value = " . count($bibliometrics_data) . ";\n";
                echo "        if (typeof generateBibliometricFields === 'function') {\n";
                echo "            generateBibliometricFields();\n";
                echo "            // CRITICAL: Load data AFTER fields are generated (like publications)\n";
                echo "            setTimeout(function() {\n";
                // Encode bibliometrics data as JSON for JavaScript
                $bibliometrics_json = json_encode($bibliometrics_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const bibliometricsData = " . $bibliometrics_json . ";\n";
                echo "                if (Array.isArray(bibliometricsData) && bibliometricsData.length > 0) {\n";
                echo "                    bibliometricsData.forEach(function(bib, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const bibTeacherNameEl = document.getElementById('bib_teacher_name_' + i);\n";
                echo "                        if (bibTeacherNameEl) bibTeacherNameEl.value = (bib.name || '');\n";
                echo "                        const bibImpactFactorEl = document.getElementById('bib_impact_factor_' + i);\n";
                echo "                        if (bibImpactFactorEl) bibImpactFactorEl.value = (bib.impact_factor || '');\n";
                echo "                        const bibCitationsEl = document.getElementById('bib_citations_' + i);\n";
                echo "                        if (bibCitationsEl) bibCitationsEl.value = (bib.citations || '');\n";
                echo "                        const bibHIndexEl = document.getElementById('bib_h_index_' + i);\n";
                echo "                        if (bibHIndexEl) bibHIndexEl.value = (bib.h_index || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400); // CRITICAL: Longer delay (400ms) to ensure ALL fields are fully generated (supports 25+ bibliometrics)\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 300); // CRITICAL: Increased to 300ms for better DOM readiness with many bibliometrics\n";
            }
        }
        
        // Generate book fields
        // CRITICAL: Check both 'books' field and ensure it's valid JSON
        if (isset($editRow['books']) && !empty($editRow['books']) && $editRow['books'] !== 'null') {
            $books_data = json_decode($editRow['books'], true);
            // CRITICAL: Filter out entries where ALL fields are empty (saved by mistake when count > 0 but no data entered)
            if ($books_data && is_array($books_data)) {
                $books_data = array_filter($books_data, function($book) {
                    // Keep entry if at least one field has non-empty value
                    return !empty($book['title']) || !empty($book['type']) || !empty($book['authors']) || 
                           !empty($book['month']) || !empty($book['year']) || !empty($book['publisher']);
                });
                // Re-index array after filtering
                $books_data = array_values($books_data);
            }
            // CRITICAL: Ensure decoded data is valid array with data AFTER filtering
            // FIXED: Use consolidated approach to prevent race conditions and missing data (like publications and bibliometrics)
            if ($books_data && is_array($books_data) && count($books_data) > 0) {
                // CRITICAL: Generate fields FIRST with longer timeout to ensure DOM is ready
                echo "setTimeout(function() {\n";
                echo "    const booksCountEl = document.getElementById('books_count');\n";
                echo "    if (booksCountEl) {\n";
                echo "        booksCountEl.value = " . count($books_data) . ";\n";
                echo "        if (typeof generateBookFields === 'function') {\n";
                echo "            generateBookFields();\n";
                echo "        } else if (typeof window._generateBookFieldsFull === 'function') {\n";
                echo "            window._generateBookFieldsFull();\n";
                echo "        }\n";
                echo "        // CRITICAL: Load data AFTER fields are generated\n";
                echo "        setTimeout(function() {\n";
                // Encode books data as JSON for JavaScript
                $books_json = json_encode($books_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "            const booksData = " . $books_json . ";\n";
                echo "            if (Array.isArray(booksData) && booksData.length > 0) {\n";
                echo "                booksData.forEach(function(book, index) {\n";
                echo "                    const i = index + 1;\n";
                echo "                    const bookTitleEl = document.getElementById('book_title_' + i);\n";
                echo "                    if (bookTitleEl) bookTitleEl.value = (book.title || '');\n";
                echo "                    const bookTypeEl = document.getElementById('book_type_' + i);\n";
                echo "                    if (bookTypeEl) {\n";
                echo "                        bookTypeEl.value = (book.type || '');\n";
                echo "                        bookTypeEl.dispatchEvent(new Event('change', { bubbles: true }));\n";
                echo "                    }\n";
                echo "                    const bookAuthorsEl = document.getElementById('book_authors_' + i);\n";
                echo "                    if (bookAuthorsEl) bookAuthorsEl.value = (book.authors || '');\n";
                echo "                    const bookMonthEl = document.getElementById('book_month_' + i);\n";
                echo "                    if (bookMonthEl) {\n";
                echo "                        bookMonthEl.value = (book.month || '');\n";
                echo "                        bookMonthEl.dispatchEvent(new Event('change', { bubbles: true }));\n";
                echo "                    }\n";
                echo "                    const bookYearEl = document.getElementById('book_year_' + i);\n";
                echo "                    if (bookYearEl) {\n";
                echo "                        bookYearEl.value = (book.year || '');\n";
                echo "                        bookYearEl.dispatchEvent(new Event('change', { bubbles: true }));\n";
                echo "                    }\n";
                echo "                    const bookPublisherEl = document.getElementById('book_publisher_' + i);\n";
                echo "                    if (bookPublisherEl) bookPublisherEl.value = (book.publisher || '');\n";
                echo "                });\n";
                echo "            }\n";
                echo "        }, 400); // CRITICAL: Longer delay (400ms) to ensure ALL fields are fully generated (supports 25+ books)\n";
                echo "    }\n";
                echo "}, 350); // CRITICAL: Increased to 350ms for better DOM readiness with many books\n";
            }
        }
        
        // Generate patent fields
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['patent_details'])) {
            $patents_data = json_decode($editRow['patent_details'], true);
            if ($patents_data && is_array($patents_data) && count($patents_data) > 0) {
                echo "setTimeout(function() {\n";
                echo "    const patentsCountEl = document.getElementById('patents_count');\n";
                echo "    if (patentsCountEl) {\n";
                echo "        patentsCountEl.value = " . count($patents_data) . ";\n";
                echo "        if (typeof generatePatentFields === 'function') {\n";
                echo "            generatePatentFields();\n";
                echo "            // CRITICAL: Load data AFTER fields are generated\n";
                echo "            setTimeout(function() {\n";
                $patents_json = json_encode($patents_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const patentsData = " . $patents_json . ";\n";
                echo "                if (Array.isArray(patentsData) && patentsData.length > 0) {\n";
                echo "                    patentsData.forEach(function(patent, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const patentAppNumberEl = document.getElementById('patent_app_number_' + i);\n";
                echo "                        if (patentAppNumberEl) patentAppNumberEl.value = (patent.app_number || '');\n";
                echo "                        const patentStatusEl = document.getElementById('patent_status_' + i);\n";
                echo "                        if (patentStatusEl) patentStatusEl.value = (patent.status || '');\n";
                echo "                        const patentInventorsEl = document.getElementById('patent_inventors_' + i);\n";
                echo "                        if (patentInventorsEl) patentInventorsEl.value = (patent.inventors || '');\n";
                echo "                        const patentTitleEl = document.getElementById('patent_title_' + i);\n";
                echo "                        if (patentTitleEl) patentTitleEl.value = (patent.title || '');\n";
                echo "                        const patentApplicantsEl = document.getElementById('patent_applicants_' + i);\n";
                echo "                        if (patentApplicantsEl) patentApplicantsEl.value = (patent.applicants || '');\n";
                echo "                        const patentFiledDateEl = document.getElementById('patent_filed_date_' + i);\n";
                echo "                        if (patentFiledDateEl) patentFiledDateEl.value = (patent.filed_date || '');\n";
                echo "                        const patentPublishedDateEl = document.getElementById('patent_published_date_' + i);\n";
                echo "                        if (patentPublishedDateEl) patentPublishedDateEl.value = (patent.published_date || '');\n";
                echo "                        const patentNumberEl = document.getElementById('patent_number_' + i);\n";
                echo "                        if (patentNumberEl) patentNumberEl.value = (patent.patent_number || '');\n";
                echo "                        const patentAssigneesEl = document.getElementById('patent_assignees_' + i);\n";
                echo "                        if (patentAssigneesEl) patentAssigneesEl.value = (patent.assignees || '');\n";
                echo "                        const patentUrlEl = document.getElementById('patent_url_' + i);\n";
                echo "                        if (patentUrlEl) patentUrlEl.value = (patent.url || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400); // CRITICAL: Longer delay to ensure ALL fields are fully generated\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        
        // Load DPIIT data
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['dpiit_startup_details'])) {
            $dpiit_data = json_decode($editRow['dpiit_startup_details'], true);
            if ($dpiit_data && is_array($dpiit_data) && count($dpiit_data) > 0) {
                echo "setTimeout(function() {\n";
                echo "    const dpiitCountEl = document.querySelector('input[name=\"total_dpiit_startups\"]');\n";
                echo "    if (dpiitCountEl) {\n";
                echo "        dpiitCountEl.value = " . count($dpiit_data) . ";\n";
                echo "        if (typeof generateDpiitFields === 'function') {\n";
                echo "            generateDpiitFields();\n";
                echo "            setTimeout(function() {\n";
                $dpiit_json = json_encode($dpiit_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const dpiitData = " . $dpiit_json . ";\n";
                echo "                if (Array.isArray(dpiitData) && dpiitData.length > 0) {\n";
                echo "                    dpiitData.forEach(function(dpiit, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const dpiitNameEl = document.getElementById('dpiit_startup_name_' + i);\n";
                echo "                        if (dpiitNameEl) dpiitNameEl.value = (dpiit.startup_name || '');\n";
                echo "                        const dpiitRegEl = document.getElementById('dpiit_registration_no_' + i);\n";
                echo "                        if (dpiitRegEl) dpiitRegEl.value = (dpiit.dpiit_registration_no || '');\n";
                echo "                        const dpiitYearEl = document.getElementById('dpiit_year_recognition_' + i);\n";
                echo "                        if (dpiitYearEl) dpiitYearEl.value = (dpiit.year_of_recognition || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400);\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        
        // Load VC Investment data
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['vc_investment_details'])) {
            $vc_data = json_decode($editRow['vc_investment_details'], true);
            if ($vc_data && is_array($vc_data) && count($vc_data) > 0) {
                echo "setTimeout(function() {\n";
                echo "    const vcCountEl = document.querySelector('input[name=\"total_vc_investments\"]');\n";
                echo "    if (vcCountEl) {\n";
                echo "        vcCountEl.value = " . count($vc_data) . ";\n";
                echo "        if (typeof generateVcFields === 'function') {\n";
                echo "            generateVcFields();\n";
                echo "            setTimeout(function() {\n";
                $vc_json = json_encode($vc_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const vcData = " . $vc_json . ";\n";
                echo "                if (Array.isArray(vcData) && vcData.length > 0) {\n";
                echo "                    vcData.forEach(function(vc, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const vcNameEl = document.getElementById('vc_startup_name_' + i);\n";
                echo "                        if (vcNameEl) vcNameEl.value = (vc.startup_name || '');\n";
                echo "                        const vcDpiitEl = document.getElementById('vc_dpiit_no_' + i);\n";
                echo "                        if (vcDpiitEl) vcDpiitEl.value = (vc.dpiit_no || '');\n";
                echo "                        const vcAmtEl = document.getElementById('vc_amount_' + i);\n";
                echo "                        if (vcAmtEl) vcAmtEl.value = (vc.received_amount_rs || '');\n";
                echo "                        const vcOrgEl = document.getElementById('vc_organization_' + i);\n";
                echo "                        if (vcOrgEl) vcOrgEl.value = (vc.organization || '');\n";
                echo "                        const vcYearEl = document.getElementById('vc_year_' + i);\n";
                echo "                        if (vcYearEl) vcYearEl.value = (vc.year_of_receiving || '');\n";
                echo "                        const vcAchieveEl = document.getElementById('vc_achievement_' + i);\n";
                echo "                        if (vcAchieveEl) vcAchieveEl.value = (vc.achievement_level || '');\n";
                echo "                        const vcTypeEl = document.getElementById('vc_type_' + i);\n";
                echo "                        if (vcTypeEl) vcTypeEl.value = (vc.type_of_investment || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400);\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        
        // Load Seed Funding data
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['seed_funding_details'])) {
            $seed_data = json_decode($editRow['seed_funding_details'], true);
            if ($seed_data && is_array($seed_data) && count($seed_data) > 0) {
                echo "setTimeout(function() {\n";
                echo "    const seedCountEl = document.querySelector('input[name=\"total_seed_funding\"]');\n";
                echo "    if (seedCountEl) {\n";
                echo "        seedCountEl.value = " . count($seed_data) . ";\n";
                echo "        if (typeof generateSeedFields === 'function') {\n";
                echo "            generateSeedFields();\n";
                echo "            setTimeout(function() {\n";
                $seed_json = json_encode($seed_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const seedData = " . $seed_json . ";\n";
                echo "                if (Array.isArray(seedData) && seedData.length > 0) {\n";
                echo "                    seedData.forEach(function(seed, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const seedNameEl = document.getElementById('seed_startup_name_' + i);\n";
                echo "                        if (seedNameEl) seedNameEl.value = (seed.startup_name || '');\n";
                echo "                        const seedDpiitEl = document.getElementById('seed_dpiit_no_' + i);\n";
                echo "                        if (seedDpiitEl) seedDpiitEl.value = (seed.dpiit_no || '');\n";
                echo "                        const seedAmtEl = document.getElementById('seed_amount_' + i);\n";
                echo "                        if (seedAmtEl) seedAmtEl.value = (seed.seed_funding_received || '');\n";
                echo "                        const seedOrgEl = document.getElementById('seed_organization_' + i);\n";
                echo "                        if (seedOrgEl) seedOrgEl.value = (seed.government_organization || '');\n";
                echo "                        const seedYearEl = document.getElementById('seed_year_' + i);\n";
                echo "                        if (seedYearEl) seedYearEl.value = (seed.year_of_receiving || '');\n";
                echo "                        const seedAchieveEl = document.getElementById('seed_achievement_' + i);\n";
                echo "                        if (seedAchieveEl) seedAchieveEl.value = (seed.achievement_level || '');\n";
                echo "                        const seedTypeEl = document.getElementById('seed_type_' + i);\n";
                echo "                        if (seedTypeEl) seedTypeEl.value = (seed.type_of_investment || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400);\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        
        // Load FDI Investment data
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['fdi_investment_details'])) {
            $fdi_data = json_decode($editRow['fdi_investment_details'], true);
            if ($fdi_data && is_array($fdi_data) && count($fdi_data) > 0) {
                echo "setTimeout(function() {\n";
                echo "    const fdiCountEl = document.querySelector('input[name=\"total_fdi_investments\"]');\n";
                echo "    if (fdiCountEl) {\n";
                echo "        fdiCountEl.value = " . count($fdi_data) . ";\n";
                echo "        if (typeof generateFdiFields === 'function') {\n";
                echo "            generateFdiFields();\n";
                echo "            setTimeout(function() {\n";
                $fdi_json = json_encode($fdi_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const fdiData = " . $fdi_json . ";\n";
                echo "                if (Array.isArray(fdiData) && fdiData.length > 0) {\n";
                echo "                    fdiData.forEach(function(fdi, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const fdiNameEl = document.getElementById('fdi_startup_name_' + i);\n";
                echo "                        if (fdiNameEl) fdiNameEl.value = (fdi.startup_name || '');\n";
                echo "                        const fdiDpiitEl = document.getElementById('fdi_dpiit_no_' + i);\n";
                echo "                        if (fdiDpiitEl) fdiDpiitEl.value = (fdi.dpiit_no || '');\n";
                echo "                        const fdiAmtEl = document.getElementById('fdi_amount_' + i);\n";
                echo "                        if (fdiAmtEl) fdiAmtEl.value = (fdi.fdi_investment_received || '');\n";
                echo "                        const fdiOrgEl = document.getElementById('fdi_organization_' + i);\n";
                echo "                        if (fdiOrgEl) fdiOrgEl.value = (fdi.organization || '');\n";
                echo "                        const fdiYearEl = document.getElementById('fdi_year_' + i);\n";
                echo "                        if (fdiYearEl) fdiYearEl.value = (fdi.year_of_receiving || '');\n";
                echo "                        const fdiAchieveEl = document.getElementById('fdi_achievement_' + i);\n";
                echo "                        if (fdiAchieveEl) fdiAchieveEl.value = (fdi.achievement_level || '');\n";
                echo "                        const fdiTypeEl = document.getElementById('fdi_type_' + i);\n";
                echo "                        if (fdiTypeEl) fdiTypeEl.value = (fdi.type_of_investment || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400);\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        
        // Load TRL Innovations data
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['trl_innovations_details'])) {
            $trl_data = json_decode($editRow['trl_innovations_details'], true);
            if ($trl_data && is_array($trl_data) && count($trl_data) > 0) {
                echo "setTimeout(function() {\n";
                echo "    const trlCountEl = document.querySelector('input[name=\"total_trl_innovations\"]');\n";
                echo "    if (trlCountEl) {\n";
                echo "        trlCountEl.value = " . count($trl_data) . ";\n";
                echo "        if (typeof generateTrlFields === 'function') {\n";
                echo "            generateTrlFields();\n";
                echo "            setTimeout(function() {\n";
                $trl_json = json_encode($trl_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const trlData = " . $trl_json . ";\n";
                echo "                if (Array.isArray(trlData) && trlData.length > 0) {\n";
                echo "                    trlData.forEach(function(trl, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const trlNameEl = document.getElementById('trl_startup_name_' + i);\n";
                echo "                        if (trlNameEl) trlNameEl.value = (trl.startup_name || '');\n";
                echo "                        const trlDpiitEl = document.getElementById('trl_dpiit_no_' + i);\n";
                echo "                        if (trlDpiitEl) trlDpiitEl.value = (trl.dpiit_no || '');\n";
                echo "                        const trlInnovEl = document.getElementById('trl_innovation_name_' + i);\n";
                echo "                        if (trlInnovEl) trlInnovEl.value = (trl.innovation_name || '');\n";
                echo "                        const trlStageEl = document.getElementById('trl_stage_' + i);\n";
                echo "                        if (trlStageEl) trlStageEl.value = (trl.stage_level || '');\n";
                echo "                        const trlAchieveEl = document.getElementById('trl_achievement_' + i);\n";
                echo "                        if (trlAchieveEl) trlAchieveEl.value = (trl.achievement_level || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400);\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        
        // Load Turnover Achievement data
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['turnover_achievements_details'])) {
            $turnover_data = json_decode($editRow['turnover_achievements_details'], true);
            if ($turnover_data && is_array($turnover_data) && count($turnover_data) > 0) {
                echo "setTimeout(function() {\n";
                echo "    const turnoverCountEl = document.querySelector('input[name=\"total_turnover_achievements\"]');\n";
                echo "    if (turnoverCountEl) {\n";
                echo "        turnoverCountEl.value = " . count($turnover_data) . ";\n";
                echo "        if (typeof generateTurnoverFields === 'function') {\n";
                echo "            generateTurnoverFields();\n";
                echo "            setTimeout(function() {\n";
                $turnover_json = json_encode($turnover_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const turnoverData = " . $turnover_json . ";\n";
                echo "                if (Array.isArray(turnoverData) && turnoverData.length > 0) {\n";
                echo "                    turnoverData.forEach(function(turnover, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const turnoverNameEl = document.getElementById('turnover_startup_name_' + i);\n";
                echo "                        if (turnoverNameEl) turnoverNameEl.value = (turnover.startup_name || '');\n";
                echo "                        const turnoverDpiitEl = document.getElementById('turnover_dpiit_no_' + i);\n";
                echo "                        if (turnoverDpiitEl) turnoverDpiitEl.value = (turnover.dpiit_no || '');\n";
                echo "                        const turnoverAmtEl = document.getElementById('turnover_amount_' + i);\n";
                echo "                        if (turnoverAmtEl) turnoverAmtEl.value = (turnover.company_turnover || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400);\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        
        // Load Forbes Alumni data
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['forbes_alumni_details'])) {
            $forbes_data = json_decode($editRow['forbes_alumni_details'], true);
            if ($forbes_data && is_array($forbes_data) && count($forbes_data) > 0) {
                echo "setTimeout(function() {\n";
                echo "    const forbesCountEl = document.querySelector('input[name=\"total_forbes_alumni\"]');\n";
                echo "    if (forbesCountEl) {\n";
                echo "        forbesCountEl.value = " . count($forbes_data) . ";\n";
                echo "        if (typeof generateForbesFields === 'function') {\n";
                echo "            generateForbesFields();\n";
                echo "            setTimeout(function() {\n";
                $forbes_json = json_encode($forbes_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const forbesData = " . $forbes_json . ";\n";
                echo "                if (Array.isArray(forbesData) && forbesData.length > 0) {\n";
                echo "                    forbesData.forEach(function(forbes, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const forbesProgEl = document.getElementById('forbes_program_' + i);\n";
                echo "                        if (forbesProgEl) forbesProgEl.value = (forbes.program_name || '');\n";
                echo "                        const forbesYearPassEl = document.getElementById('forbes_year_passing_' + i);\n";
                echo "                        if (forbesYearPassEl) forbesYearPassEl.value = (forbes.year_of_passing || '');\n";
                echo "                        const forbesCompEl = document.getElementById('forbes_company_' + i);\n";
                echo "                        if (forbesCompEl) forbesCompEl.value = (forbes.founder_company_name || '');\n";
                echo "                        const forbesYearFoundEl = document.getElementById('forbes_year_founded_' + i);\n";
                echo "                        if (forbesYearFoundEl) forbesYearFoundEl.value = (forbes.year_founded || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400);\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        
        // Load Innovation Grants data
        // FIXED: Use consolidated approach to prevent race conditions and missing data
        if (!empty($editRow['innovation_grants_details'])) {
            $grants_data = json_decode($editRow['innovation_grants_details'], true);
            if ($grants_data && is_array($grants_data) && count($grants_data) > 0) {
                echo "setTimeout(function() {\n";
                echo "    const grantsCountEl = document.querySelector('input[name=\"total_innovation_grants\"]');\n";
                echo "    if (grantsCountEl) {\n";
                echo "        grantsCountEl.value = " . count($grants_data) . ";\n";
                echo "        if (typeof generateGrantFields === 'function') {\n";
                echo "            generateGrantFields();\n";
                echo "            setTimeout(function() {\n";
                $grants_json = json_encode($grants_data, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "                const grantsData = " . $grants_json . ";\n";
                echo "                if (Array.isArray(grantsData) && grantsData.length > 0) {\n";
                echo "                    grantsData.forEach(function(grant, index) {\n";
                echo "                        const i = index + 1;\n";
                echo "                        const grantOrgEl = document.getElementById('grant_organization_' + i);\n";
                echo "                        if (grantOrgEl) grantOrgEl.value = (grant.government_organization || '');\n";
                echo "                        const grantProgEl = document.getElementById('grant_program_' + i);\n";
                echo "                        if (grantProgEl) grantProgEl.value = (grant.program_scheme_name || '');\n";
                echo "                        const grantAmtEl = document.getElementById('grant_amount_' + i);\n";
                echo "                        if (grantAmtEl) grantAmtEl.value = (grant.grant_amount || '');\n";
                echo "                        const grantYearEl = document.getElementById('grant_year_' + i);\n";
                echo "                        if (grantYearEl) grantYearEl.value = (grant.year_of_receiving || '');\n";
                echo "                    });\n";
                echo "                }\n";
                echo "            }, 400);\n";
                echo "        }\n";
                echo "    }\n";
                echo "}, 200);\n";
            }
        }
        ?>
        
        // Apply lock state after all fields are generated and populated
        // Use a longer delay to ensure all data is loaded (timeouts can go up to 650ms+)
        setTimeout(function() {
            // Generate all dynamic fields first to ensure containers exist
            if (typeof generateDpiitFields === 'function') generateDpiitFields();
            if (typeof generateVcFields === 'function') generateVcFields();
            if (typeof generateSeedFields === 'function') generateSeedFields();
            if (typeof generateFdiFields === 'function') generateFdiFields();
            if (typeof generateGrantFields === 'function') generateGrantFields();
            if (typeof generateTrlFields === 'function') generateTrlFields();
            if (typeof generateTurnoverFields === 'function') generateTurnoverFields();
            if (typeof generateForbesFields === 'function') generateForbesFields();
            
            // Wait a bit more for data population, then apply lock
        setTimeout(function() {
            applyLockState();
            }, 300);
        }, 800);
    });
    <?php endif; ?>
    
</script>
<?php 
require "unified_footer.php"; 
// Connection is closed in unified_footer.php, don't close again
?>