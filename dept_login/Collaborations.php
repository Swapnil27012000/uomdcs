<?php
// Collaborations - Collaborations form
// Start output buffering at the very beginning
ob_start();

// Set timezone before any date operations
if (!date_default_timezone_get() || date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

// ============================================================================
// CRITICAL: Handle AJAX requests FIRST - BEFORE ANY INCLUDES OR OUTPUT
// ============================================================================

// Handle PDF uploads - MUST BE FIRST
// CRITICAL: Follow DOCUMENT_HANDLING_GUIDE.md
if (isset($_POST['upload_document'])) {
    // CRITICAL #1: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session and load config FIRST (minimal requirements for AJAX)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config for database connection - suppress any output
    ob_start();
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
    
    // Clear all output buffers again
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Suppress all errors and warnings
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    // CRITICAL #4: Set proper JSON headers with cache control - MUST be before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Initialize response in variable - build response first, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $file_id = $_POST['file_id'] ?? '';
        $srno = (int)($_POST['srno'] ?? 0);
    
        // Get department ID from session
        $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
        if (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found. Please login again.'];
        } else {
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
            
            // Route through common_upload_handler.php
            require_once(__DIR__ . '/common_upload_handler.php');
            
            // Set global variables for common_upload_handler.php
            $GLOBALS['dept_id'] = $dept_id;
            $GLOBALS['A_YEAR'] = $A_YEAR;
            
            // Use common upload handler
            // Structure: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/collaborations/
            $result = handleDocumentUpload('collaborations', 'Collaborations', [
                'upload_dir' => dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/collaborations/",
                'max_size' => 10,
                'document_title' => 'Collaborations Documentation',
                'srno' => $srno,
                'file_id' => $file_id
            ]);
            
            // CRITICAL: Ensure result is an array and normalize file path for web access
            if (!is_array($result)) {
                $response = ['success' => false, 'message' => 'Invalid response from upload handler'];
            } else {
                // Normalize file path to web-accessible format (same as StudentSupport.php)
                if ($result['success'] && isset($result['file_path'])) {
                    $web_path = $result['file_path'];
                    $project_root = dirname(__DIR__);
                    
                    // Convert absolute paths to relative web paths
                    if (strpos($web_path, $project_root) === 0) {
                        $web_path = str_replace([$project_root . '/', $project_root . '\\'], '', $web_path);
                    }
                    $web_path = str_replace('\\', '/', $web_path);
                    if (strpos($web_path, 'uploads/') === 0) {
                        $web_path = '../' . $web_path;
                    }
                    $result['file_path'] = $web_path;
                }
                $response = $result;
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()];
    } catch (Error $e) {
        $response = ['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()];
    }
    
    // CRITICAL #1: Clear ALL output buffers before final output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // CRITICAL #2: Output response once at the end
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle PDF deletion - MUST BE FIRST
if (isset($_POST['delete_document'])) {
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
        $srno = (int)($_POST['srno'] ?? 0);
        
        $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
        if (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found.'];
        } elseif ($srno <= 0) {
            $response = ['success' => false, 'message' => 'Invalid serial number.'];
        } else {
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
            
            // CRITICAL FIX: Query with fallback to check current year AND previous year
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
            
            // Get file path using prepared statement - MUST include section_name to match upload handler
            $section_name = 'Collaborations';
            $get_file_query = "SELECT file_path, academic_year FROM supporting_documents 
                              WHERE dept_id = ? AND page_section = 'collaborations' 
                              AND serial_number = ? AND section_name = ? 
                              AND (academic_year = ? OR academic_year = ?) AND status = 'active' 
                              ORDER BY academic_year DESC, id DESC LIMIT 1";
            $stmt = mysqli_prepare($conn, $get_file_query);
            if (!$stmt) {
                $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
            } else {
                mysqli_stmt_bind_param($stmt, 'iisss', $dept_id, $srno, $section_name, $A_YEAR, $prev_year);
                if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if ($result && mysqli_num_rows($result) > 0) {
                        $row = mysqli_fetch_assoc($result);
                        $file_path = $row['file_path'];
                        $doc_year = $row['academic_year'] ?? $A_YEAR;
                        mysqli_free_result($result);  // CRITICAL: Free result
                        mysqli_stmt_close($stmt);
                        
                        // Delete file from filesystem (use document's actual academic year)
                        $project_root = dirname(__DIR__);
                        $physical_path = $file_path;
                        
                        // Handle different path formats
                        if (strpos($file_path, $project_root) === 0) {
                            // Already absolute path
                            $physical_path = $file_path;
                        } elseif (strpos($file_path, '../') === 0) {
                            $physical_path = $project_root . '/' . str_replace('../', '', $file_path);
                        } elseif (strpos($file_path, 'uploads/') === 0) {
                            $physical_path = $project_root . '/' . $file_path;
                        } else {
                            // Fallback: construct path using document's academic year
                            $filename = basename($file_path);
                            $physical_path = $project_root . "/uploads/{$doc_year}/DEPARTMENT/{$dept_id}/collaborations/{$filename}";
                        }
                        $physical_path = str_replace('\\', '/', $physical_path);
                        
                        if ($physical_path && file_exists($physical_path)) {
                            @unlink($physical_path);
                        }
                        
                        // Soft delete using prepared statement (use document's actual academic year)
                        $section_name = 'Collaborations';
                        $delete_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE academic_year = ? AND dept_id = ? AND page_section = 'collaborations' AND serial_number = ? AND section_name = ? AND status = 'active'";
                        $delete_stmt = mysqli_prepare($conn, $delete_query);
                        if (!$delete_stmt) {
                            $response = ['success' => false, 'message' => 'Database error: Failed to prepare delete query'];
                        } else {
                            mysqli_stmt_bind_param($delete_stmt, 'siis', $doc_year, $dept_id, $srno, $section_name);
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
                        mysqli_stmt_close($stmt);
                        $response = ['success' => false, 'message' => 'Document not found'];
                    }
                } else {
                    mysqli_stmt_close($stmt);
                    $response = ['success' => false, 'message' => 'Database error: Failed to execute query'];
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
// This replaces 5 individual queries with a single efficient query
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
        $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
        if (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found'];
        } else {
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
                           WHERE dept_id = ? AND page_section = 'collaborations' 
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
                                $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept_id . '/collaborations/' . $filename;
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
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle document status check - MUST BE FIRST
// CRITICAL: Follow all checklist items to prevent crashes and unnecessary requests
if (isset($_GET['check_doc'])) {
    // CRITICAL #1: Clear ALL output buffers FIRST - prevent any output before JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session and load config FIRST (silently)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    ob_start();
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
    
    // Clear any remaining output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // CRITICAL #4: Set proper JSON headers with cache control - MUST be before any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Initialize response in variable - build response first, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $srno = (int)($_GET['srno'] ?? 0);
        
        $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
        if (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found.'];
        } elseif ($srno <= 0) {
            $response = ['success' => false, 'message' => 'Invalid serial number.'];
        } else {
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
            
            // Use prepared statement - MUST include section_name to match upload handler
            $section_name = 'Collaborations';
            $check_query = "SELECT file_path, file_name, upload_date, id FROM supporting_documents WHERE academic_year = ? AND dept_id = ? AND page_section = 'collaborations' AND serial_number = ? AND section_name = ? AND status = 'active' LIMIT 1";
            $stmt = mysqli_prepare($conn, $check_query);
            if (!$stmt) {
                $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
            } else {
                mysqli_stmt_bind_param($stmt, 'siis', $A_YEAR, $dept_id, $srno, $section_name);
                if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if ($result && mysqli_num_rows($result) > 0) {
                        $row = mysqli_fetch_assoc($result);
                        mysqli_free_result($result);  // CRITICAL: Free result
                        mysqli_stmt_close($stmt);
                        
                        // Convert to web-accessible path (same robust logic as StudentSupport.php)
                        $file_path = $row['file_path'];
                        $project_root = dirname(__DIR__);
                        if (strpos($file_path, $project_root) === 0) {
                            $file_path = str_replace([$project_root . '/', $project_root . '\\'], '', $file_path);
                        }
                        $file_path = str_replace('\\', '/', $file_path);
                        if (strpos($file_path, 'uploads/') === 0) {
                            $file_path = '../' . $file_path;
                        }
                        
                        $response = [
                            'success' => true,
                            'file_path' => $file_path,
                            'file_name' => $row['file_name'] ?? basename($file_path),
                            'upload_date' => $row['upload_date'] ?? date('Y-m-d H:i:s'),
                            'document_id' => $row['id'] ?? 0
                        ];
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
// NORMAL PAGE LOAD - Include session first for POST handling
// ============================================================================

require('session.php');

error_reporting(0);
ini_set('display_errors', 0);
    
    // Get department ID from session (already verified in session.php)
    $dept = $userInfo['DEPT_ID'];

if (!$dept) {
        throw new Exception('Department ID not found. Please contact administrator.');
    }
    
$dept_code = $dept_info['DEPT_COLL_NO'];
$dept_name = $dept_info['DEPT_NAME'];

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
// Database key - use full academic year string (e.g., "2024-2025")
$A_YEAR_DB = $A_YEAR;

// Initialize form locked status
$form_locked = false;
$submitted = false;

// Initialize variables
$b = array_fill(1, 5, 0);
$total_collab = 0;
$marks = 0;

// ============================================================================
// HANDLE FORM SUBMISSION - MUST BE BEFORE unified_header.php
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    // Suppress error output during POST processing
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    // CSRF validation
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
        if (function_exists('validate_csrf')) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (empty($csrf) || !validate_csrf($csrf)) {
                $_SESSION['error'] = 'Security token validation failed. Please refresh and try again.';
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                if (!headers_sent()) {
                    header('Location: Collaborations.php');
                }
                exit;
            }
        }
    }
    
    // Verify database connection
    if (!isset($conn) || !$conn) {
        $_SESSION['error'] = 'Database connection not available. Please try again.';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: Collaborations.php');
        }
        exit;
    }
    
    // Ensure dept and A_YEAR are set
    if (!$dept || empty($A_YEAR)) {
        $_SESSION['error'] = 'Invalid department or academic year. Please refresh the page.';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: Collaborations.php');
        }
        exit;
    }
    
    try {
        // Get form data with proper validation
        $record_id = isset($_POST['record_id']) ? (int)$_POST['record_id'] : 0;
        
        // Validate and sanitize B section values (must be non-negative integers)
        for ($i = 1; $i <= 5; $i++) {
            $b[$i] = isset($_POST["b$i"]) ? max(0, (int)$_POST["b$i"]) : 0;
        }
        
        // Calculate totals
        $total_collab = array_sum($b);
        // MARKS column removed - no longer needed
        $submitted = true;
        
        // Use A_YEAR already calculated at top of file

        // Check if this is an update or insert
        if ($record_id > 0) {
            // UPDATE query using prepared statement - MARKS column removed
            $query = "UPDATE `collaborations` SET 
                `B1` = ?, `B2` = ?, `B3` = ?, `B4` = ?, `B5` = ?, `TOTAL_COLLAB` = ?
                WHERE `id` = ? AND `DEPT_ID` = ? AND `A_YEAR` = ?";
            
            $stmt = mysqli_prepare($conn, $query);
            if ($stmt) {
                // Types: B1(i), B2(i), B3(i), B4(i), B5(i), TOTAL_COLLAB(i), id(i), DEPT_ID(i), A_YEAR(s)
                mysqli_stmt_bind_param($stmt, 'iiiiiiiis', $b[1], $b[2], $b[3], $b[4], $b[5], $total_collab, $record_id, $dept, $A_YEAR_DB);
                if (mysqli_stmt_execute($stmt)) {
                    $affected_rows = mysqli_stmt_affected_rows($stmt);
                    mysqli_stmt_close($stmt);
                    
                    // CRITICAL: Clear and recalculate score cache after data update
                    require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
                    clearDepartmentScoreCache($dept, $A_YEAR_DB, true);
                    
                    $_SESSION['success'] = 'Collaboration Data Updated Successfully! Form is now locked.';
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Location: Collaborations.php');
                    }
                    exit;
    } else {
                    $error_message = 'Error updating data: ' . mysqli_stmt_error($stmt);
                    mysqli_stmt_close($stmt);
                    $_SESSION['error'] = $error_message;
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Location: Collaborations.php');
    }
    exit;
}
            } else {
                $error_message = 'Database query preparation failed: ' . mysqli_error($conn);
                $_SESSION['error'] = $error_message;
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                if (!headers_sent()) {
                    header('Location: Collaborations.php');
                }
                exit;
            }
        } else {
            // Check if row exists for this dept and year
            $check_query = "SELECT id FROM collaborations WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
            $check_stmt = mysqli_prepare($conn, $check_query);
            $existing_record_id = 0;
            if ($check_stmt) {
                mysqli_stmt_bind_param($check_stmt, 'is', $dept, $A_YEAR_DB);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                if ($check_result && mysqli_num_rows($check_result) > 0) {
                    $check_row = mysqli_fetch_assoc($check_result);
                    $existing_record_id = (int)$check_row['id'];
                }
                mysqli_stmt_close($check_stmt);
            }
            
            if ($existing_record_id > 0) {
                // UPDATE existing row - MARKS column removed
                $query = "UPDATE `collaborations` SET 
                    `B1` = ?, `B2` = ?, `B3` = ?, `B4` = ?, `B5` = ?, `TOTAL_COLLAB` = ?
                    WHERE `id` = ? AND `DEPT_ID` = ? AND `A_YEAR` = ?";
                
                $stmt = mysqli_prepare($conn, $query);
                if ($stmt) {
                    // Types: B1(i), B2(i), B3(i), B4(i), B5(i), TOTAL_COLLAB(i), id(i), DEPT_ID(i), A_YEAR(s)
                    mysqli_stmt_bind_param($stmt, 'iiiiiiiis', $b[1], $b[2], $b[3], $b[4], $b[5], $total_collab, $existing_record_id, $dept, $A_YEAR_DB);
                    if (mysqli_stmt_execute($stmt)) {
                        $affected_rows = mysqli_stmt_affected_rows($stmt);
                        mysqli_stmt_close($stmt);
                        
                        // CRITICAL: Clear and recalculate score cache after data update
                        require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
                        clearDepartmentScoreCache($dept, $A_YEAR_DB, true);
                        
                        $_SESSION['success'] = 'Collaboration Data Updated Successfully! Form is now locked.';
                        while (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                        if (!headers_sent()) {
                            header('Location: Collaborations.php');
                        }
                        exit;
                    } else {
                        $error_message = 'Error updating data: ' . mysqli_stmt_error($stmt);
                        mysqli_stmt_close($stmt);
                        $_SESSION['error'] = $error_message;
                        while (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                        if (!headers_sent()) {
                            header('Location: Collaborations.php');
                        }
                        exit;
                    }
                } else {
                    $error_message = 'Database query preparation failed: ' . mysqli_error($conn);
                    $_SESSION['error'] = $error_message;
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Location: Collaborations.php');
                    }
                    exit;
                }
            } else {
                // CRITICAL: Double-check if data already exists (prevent race condition duplicates)
                $check_duplicate_query = "SELECT id FROM collaborations WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
                $check_duplicate_stmt = mysqli_prepare($conn, $check_duplicate_query);
                $duplicate_exists = false;
                if ($check_duplicate_stmt) {
                    mysqli_stmt_bind_param($check_duplicate_stmt, 'is', $dept, $A_YEAR);
                    mysqli_stmt_execute($check_duplicate_stmt);
                    $check_duplicate_result = mysqli_stmt_get_result($check_duplicate_stmt);
                    if ($check_duplicate_result && mysqli_num_rows($check_duplicate_result) > 0) {
                        $duplicate_exists = true;
                    }
                    if ($check_duplicate_result) {
                        mysqli_free_result($check_duplicate_result);
                    }
                    mysqli_stmt_close($check_duplicate_stmt);
                }
                
                if ($duplicate_exists) {
                    $_SESSION['error'] = "Data already exists for academic year $A_YEAR. Form is locked. Use Edit button to modify existing data.";
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Location: Collaborations.php');
                    }
                    exit;
                }
                
                // INSERT new row - MARKS column removed
                $query = "INSERT INTO `collaborations` 
                    (`A_YEAR`, `DEPT_ID`, `B1`, `B2`, `B3`, `B4`, `B5`, `TOTAL_COLLAB`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = mysqli_prepare($conn, $query);
                if ($stmt) {
                    // Types: A_YEAR(s), DEPT_ID(i), B1(i), B2(i), B3(i), B4(i), B5(i), TOTAL_COLLAB(i)
                    mysqli_stmt_bind_param($stmt, 'siiiiiii', $A_YEAR_DB, $dept, $b[1], $b[2], $b[3], $b[4], $b[5], $total_collab);
                    if (mysqli_stmt_execute($stmt)) {
                        $insert_id = mysqli_insert_id($conn);
                        mysqli_stmt_close($stmt);
                        
                        // CRITICAL: Clear and recalculate score cache after data insert
                        require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
                        clearDepartmentScoreCache($dept, $A_YEAR_DB, true);
                        
                        $_SESSION['success'] = 'Collaboration Data Submitted Successfully! Form is now locked.';
                        while (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                        if (!headers_sent()) {
                            header('Location: Collaborations.php');
                        }
                        exit;
                    } else {
                        $error_message = 'Error saving data: ' . mysqli_stmt_error($stmt);
                        mysqli_stmt_close($stmt);
                        $_SESSION['error'] = $error_message;
                        while (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                        if (!headers_sent()) {
                            header('Location: Collaborations.php');
                        }
                        exit;
                    }
                } else {
                    $error_message = 'Database query preparation failed: ' . mysqli_error($conn);
                    $_SESSION['error'] = $error_message;
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Location: Collaborations.php');
                    }
                    exit;
                }
            }
        }
        
    } catch (Throwable $e) {
        $error_message = 'Error: ' . $e->getMessage();
        $_SESSION['error'] = 'An error occurred while processing your request. Please try again.';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: Collaborations.php');
        }
        exit;
    }
}

// ============================================================================
// NORMAL PAGE LOAD CONTINUES - Include headers and display form
// ============================================================================

require('unified_header.php');

// Initialize message variables from session
$success_message = isset($_SESSION['success']) ? $_SESSION['success'] : '';
$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);

// Handle edit/delete actions
$action = isset($_GET['action']) ? $_GET['action'] : '';
$edit_id = isset($_GET['ID']) ? (int) $_GET['ID'] : 0;
$editRow = null;

// Handle clear data action - use prepared statement and delete PDF files
if ($action === 'clear') {
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
    }
    
    // Get all PDF files for this dept and year from supporting_documents
    $get_files_query = "SELECT file_path FROM supporting_documents WHERE academic_year = ? AND dept_id = ? AND page_section = 'collaborations' AND status = 'active'";
    $get_files_stmt = mysqli_prepare($conn, $get_files_query);
    if ($get_files_stmt) {
        mysqli_stmt_bind_param($get_files_stmt, 'si', $A_YEAR, $dept);
        mysqli_stmt_execute($get_files_stmt);
        $files_result = mysqli_stmt_get_result($get_files_stmt);
        
        while ($file_row = mysqli_fetch_assoc($files_result)) {
            $file_path = $file_row['file_path'];
            // Delete file from filesystem
            $physical_path = strpos($file_path, '../') === 0 ? dirname(__DIR__) . '/' . str_replace('../', '', $file_path) : (strpos($file_path, 'uploads/') === 0 ? dirname(__DIR__) . '/' . $file_path : $file_path);
            if (file_exists($physical_path)) {
                @unlink($physical_path);
            }
        }
        mysqli_stmt_close($get_files_stmt);
    }
    
    // Soft delete all documents
    $delete_docs_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE academic_year = ? AND dept_id = ? AND page_section = 'collaborations' AND status = 'active'";
    $delete_docs_stmt = mysqli_prepare($conn, $delete_docs_query);
    if ($delete_docs_stmt) {
        mysqli_stmt_bind_param($delete_docs_stmt, 'si', $A_YEAR, $dept);
        mysqli_stmt_execute($delete_docs_stmt);
        mysqli_stmt_close($delete_docs_stmt);
    }
    
    $clear_query = "DELETE FROM collaborations WHERE DEPT_ID = ? AND A_YEAR = ?";
    $stmt = mysqli_prepare($conn, $clear_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $dept, $A_YEAR_DB);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $_SESSION['success'] = 'Data cleared successfully! All PDF files have been deleted.';
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                header('Location: Collaborations.php');
            }
            exit;
        }
        mysqli_stmt_close($stmt);
    }
}

if ($action === 'delete' && $edit_id) {
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
    }
    
    $delete_query = "DELETE FROM collaborations WHERE id = ? AND DEPT_ID = ?";
    $stmt = mysqli_prepare($conn, $delete_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $edit_id, $dept);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $_SESSION['success'] = 'Record Deleted Successfully!';
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                header('Location: Collaborations.php');
            }
            exit;
        }
        mysqli_stmt_close($stmt);
    }
}

if ($action === 'edit' && $edit_id) {
    $edit_query = "SELECT * FROM collaborations WHERE id = ? AND DEPT_ID = ?";
    $stmt = mysqli_prepare($conn, $edit_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $edit_id, $dept);
        mysqli_stmt_execute($stmt);
        $edit_result = mysqli_stmt_get_result($stmt);
    if ($edit_result && mysqli_num_rows($edit_result) > 0) {
        $editRow = mysqli_fetch_assoc($edit_result);
    }
        mysqli_stmt_close($stmt);
    }
}

// Check if data already exists for this department and year (use prepared statement)
$existing_data_query = "SELECT * FROM collaborations WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
$existing_stmt = mysqli_prepare($conn, $existing_data_query);
if ($existing_stmt) {
    mysqli_stmt_bind_param($existing_stmt, 'is', $dept, $A_YEAR_DB);
    mysqli_stmt_execute($existing_stmt);
    $existing_result = mysqli_stmt_get_result($existing_stmt);
if ($existing_result && mysqli_num_rows($existing_result) > 0) {
    $existing_row = mysqli_fetch_assoc($existing_result);
    $form_locked = true;
    // Populate form with existing B section data
    if (!$editRow) {
        $editRow = $existing_row; // Use existing_row to populate form
    }
    $b[1] = (int)($existing_row['B1'] ?? 0);
    $b[2] = (int)($existing_row['B2'] ?? 0);
    $b[3] = (int)($existing_row['B3'] ?? 0);
    $b[4] = (int)($existing_row['B4'] ?? 0);
    $b[5] = (int)($existing_row['B5'] ?? 0);
    $total_collab = (int)($existing_row['TOTAL_COLLAB'] ?? 0);
    // MARKS column removed - no longer used
    $submitted = true;
    mysqli_free_result($existing_result); // CRITICAL: Free result
} else {
    if ($existing_result) {
        mysqli_free_result($existing_result); // CRITICAL: Free result even if empty
    }
}
mysqli_stmt_close($existing_stmt);
}

// Show success/error messages
if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="container-fluid">
    <div class="glass-card">
        <form class="modern-form" method="post" id="mainForm" onsubmit="return Validate()" onkeydown="return handleKeyDown(event)">
                <div class="page-header">
                    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <div>
                            <h1 class="page-title">
                                <i class="fas fa-network-wired me-3"></i>Collaborations
                            </h1>
                        </div>
                        <a href="export_page_pdf.php?page=Collaborations" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                            <i class="fas fa-file-pdf"></i> Download as PDF
                        </a>
                    </div>
                </div>
        
        <?php 
        // Display Skip Form Button
        require_once(__DIR__ . '/skip_form_component.php');
        $has_existing_data = ($existing_row !== null);
        displaySkipFormButton('collaborations', 'Collaborations', $A_YEAR, $has_existing_data);
        ?>
        
        <!-- Show edit mode indicator -->
        <?php if ($editRow): ?>
            <div class="alert alert-warning">
                <strong>Edit Mode:</strong> You are editing record ID <?= $editRow['id'] ?>
            </div>
        <?php endif; ?>
        
        <!-- Form Status Alert -->
        <?php if ($form_locked): ?>
            <div class="alert alert-info">
                <i class="fas fa-lock me-2"></i><strong>Form Status:</strong> Data has been submitted for academic year <?php echo htmlspecialchars($A_YEAR, ENT_QUOTES, 'UTF-8'); ?>. 
                Form is locked. Use "Update" button to modify existing data.
            </div>
        <?php else: ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><strong>Form Status:</strong> Form is ready for data entry for academic year <?php echo htmlspecialchars($A_YEAR, ENT_QUOTES, 'UTF-8'); ?>.
            </div>
        <?php endif; ?>

        <!-- Collaborations Section -->
        <div class="card mb-4 p-3">
            <h4 class="mb-3">Collaborations</h4>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 8%;">Sr. No.</th>
                            <th style="width: 70%;">Particulars</th>
                            <th style="width: 22%;">Number</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>Number of Industry collaborations for Programs and their output</td>
                            <td><input type="number" class="form-control text-center" name="b1" min="0" value="<?= ($editRow || (isset($existing_row) && $form_locked)) ? (int)(($editRow ?? $existing_row ?? [])['B1'] ?? 0) : '0' ?>" onkeyup="calculateTotalB()" <?php echo $form_locked ? 'disabled' : ''; ?>></td>
                        </tr>
                        <tr>
                            <td>2</td>
                            <td>Number of National Academic collaborations for Programs and their output</td>
                            <td><input type="number" class="form-control text-center" name="b2" min="0" value="<?= ($editRow || (isset($existing_row) && $form_locked)) ? (int)(($editRow ?? $existing_row ?? [])['B2'] ?? 0) : '0' ?>" onkeyup="calculateTotalB()" <?php echo $form_locked ? 'disabled' : ''; ?>></td>
                        </tr>
                        <tr>
                            <td>3</td>
                            <td>Number of Government/Semi-Government Collaboration Projects/Programs</td>
                            <td><input type="number" class="form-control text-center" name="b3" min="0" value="<?= ($editRow || (isset($existing_row) && $form_locked)) ? (int)(($editRow ?? $existing_row ?? [])['B3'] ?? 0) : '0' ?>" onkeyup="calculateTotalB()" <?php echo $form_locked ? 'disabled' : ''; ?>></td>
                        </tr>
                        <tr>
                            <td>4</td>
                            <td>Number of International Academic collaborations for Programs and their output</td>
                            <td><input type="number" class="form-control text-center" name="b4" min="0" value="<?= ($editRow || (isset($existing_row) && $form_locked)) ? (int)(($editRow ?? $existing_row ?? [])['B4'] ?? 0) : '0' ?>" onkeyup="calculateTotalB()" <?php echo $form_locked ? 'disabled' : ''; ?>></td>
                        </tr>
                        <tr>
                            <td>5</td>
                            <td>Number of Outreach/Social Activity Collaborations and their output</td>
                            <td><input type="number" class="form-control text-center" name="b5" min="0" value="<?= ($editRow || (isset($existing_row) && $form_locked)) ? (int)(($editRow ?? $existing_row ?? [])['B5'] ?? 0) : '0' ?>" onkeyup="calculateTotalB()" <?php echo $form_locked ? 'disabled' : ''; ?>></td>
                        </tr>
                        <tr class="table-info">
                            <td colspan="2" class="text-end fw-bold">Total</td>
                            <td><input type="text" class="form-control text-center fw-bold" id="totalB" readonly value="<?= ($editRow || (isset($existing_row) && $form_locked)) ? (int)(($editRow ?? $existing_row ?? [])['TOTAL_COLLAB'] ?? 0) : '0' ?>"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PDF Upload Sections -->
        <div class="form-group mt-4 pdf-upload-section">
            <label class="form-label">Supporting Documents for Industry Collaborations <span class="text-danger">*</span></label>
            <div class="row">
                <div class="col-md-6">
                    <label class="form-label">Industry Collaborations Documentation</label>
                    <div class="input-group">
                        <input type="file" class="form-control" id="industry_collaborations_pdf" name="industry_collaborations_pdf" accept=".pdf" 
                               <?php echo $form_locked ? 'disabled' : ''; ?> onchange="validatePDF(this)">
                        <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('industry_collaborations_pdf', 1)" 
                                <?php echo $form_locked ? 'disabled' : ''; ?>>Upload</button>
                    </div>
                    <div id="industry_collaborations_pdf_status" class="mt-2"></div>
                </div>
            </div>
        </div>

        <div class="form-group mt-4 pdf-upload-section">
            <label class="form-label">Supporting Documents for National Academic Collaborations <span class="text-danger">*</span></label>
            <div class="row">
                <div class="col-md-6">
                    <label class="form-label">National Academic Collaborations Documentation</label>
                    <div class="input-group">
                        <input type="file" class="form-control" id="national_academic_pdf" name="national_academic_pdf" accept=".pdf" 
                               <?php echo $form_locked ? 'disabled' : ''; ?> onchange="validatePDF(this)">
                        <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('national_academic_pdf', 2)" 
                                <?php echo $form_locked ? 'disabled' : ''; ?>>Upload</button>
                    </div>
                    <div id="national_academic_pdf_status" class="mt-2"></div>
                </div>
            </div>
        </div>

        <div class="form-group mt-4 pdf-upload-section">
            <label class="form-label">Supporting Documents for Government Collaborations <span class="text-danger">*</span></label>
            <div class="row">
                <div class="col-md-6">
                    <label class="form-label">Government/Semi-Government Collaboration Projects Documentation</label>
                    <div class="input-group">
                        <input type="file" class="form-control" id="government_collaborations_pdf" name="government_collaborations_pdf" accept=".pdf" 
                               <?php echo $form_locked ? 'disabled' : ''; ?> onchange="validatePDF(this)">
                        <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('government_collaborations_pdf', 3)" 
                                <?php echo $form_locked ? 'disabled' : ''; ?>>Upload</button>
                    </div>
                    <div id="government_collaborations_pdf_status" class="mt-2"></div>
                </div>
            </div>
        </div>

        <div class="form-group mt-4 pdf-upload-section">
            <label class="form-label">Supporting Documents for International Academic Collaborations <span class="text-danger">*</span></label>
            <div class="row">
                <div class="col-md-6">
                    <label class="form-label">International Academic Collaborations Documentation</label>
                    <div class="input-group">
                        <input type="file" class="form-control" id="international_academic_pdf" name="international_academic_pdf" accept=".pdf" 
                               <?php echo $form_locked ? 'disabled' : ''; ?> onchange="validatePDF(this)">
                        <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('international_academic_pdf', 4)" 
                                <?php echo $form_locked ? 'disabled' : ''; ?>>Upload</button>
                    </div>
                    <div id="international_academic_pdf_status" class="mt-2"></div>
                </div>
            </div>
        </div>

        <div class="form-group mt-4 pdf-upload-section">
            <label class="form-label">Supporting Documents for Outreach/Social Collaborations <span class="text-danger">*</span></label>
            <div class="row">
                <div class="col-md-6">
                    <label class="form-label">Outreach/Social Activity Collaborations Documentation</label>
                    <div class="input-group">
                        <input type="file" class="form-control" id="outreach_social_pdf" name="outreach_social_pdf" accept=".pdf" 
                               <?php echo $form_locked ? 'disabled' : ''; ?> onchange="validatePDF(this)">
                        <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('outreach_social_pdf', 5)" 
                                <?php echo $form_locked ? 'disabled' : ''; ?>>Upload</button>
                    </div>
                    <div id="outreach_social_pdf_status" class="mt-2"></div>
                </div>
            </div>
        </div>

        <?php if (isset($existing_row)): ?>
            <input type="hidden" name="record_id" value="<?= $existing_row['id'] ?>">
        <?php endif; ?>
        
        <?php if (file_exists(__DIR__ . '/csrf.php')): ?>
            <?php require_once(__DIR__ . '/csrf.php'); ?>
            <?php if (function_exists('csrf_field')): ?>
                <?= csrf_field() ?>
            <?php endif; ?>
        <?php endif; ?>

        <div class="text-center">
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <?php if ($form_locked): ?>
                    <button type="button" class="btn btn-warning btn-lg" onclick="enableUpdate()">
                        <i class="fas fa-edit me-2"></i>Update
                    </button>
                    <button type="submit" class="btn btn-success btn-lg" id="updateBtn" name="submit" style="display:none;">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                    <button type="button" class="btn btn-danger btn-lg" 
                            onclick="confirmClearData()">
                        <i class="fas fa-trash me-2"></i>Clear Data
                    </button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary btn-lg" name="submit">
                        <i class="fas fa-paper-plane me-2"></i>Submit Details
                    </button>
                <?php endif; ?>
            </div>
        </div>
            </form>
        </div>
    </div>


<script>
// Handle Enter key press to prevent form submission
function handleKeyDown(event) {
    // If Enter key is pressed and the target is a number input field
    if (event.key === 'Enter' && event.target.type === 'number') {
        // Prevent form submission
        event.preventDefault();
        return false;
    }
    return true;
}

// Calculate total for Section B
function calculateTotalB() {
    let total = 0;
    for (let i = 1; i <= 5; i++) {
        const value = parseInt(document.querySelector(`input[name="b${i}"]`).value) || 0;
        total += value;
    }
    document.getElementById('totalB').value = total;
}

// Form validation
function Validate() {
    // Basic validation - allow all submissions for now
    return true;
}

// Initialize calculations on page load
// CRITICAL: Prevent duplicate page initialization
let pageInitialized = false;

// Initialize page - SINGLE initialization point
function initializePage() {
    if (pageInitialized) {
        return; // Already initialized - prevent duplicate calls
    }
    pageInitialized = true;
    
    calculateTotalB();
    loadExistingDocuments();
}

// Use window.onload as primary initialization
window.onload = function() {
    initializePage();
};

// Use DOMContentLoaded as fallback ONLY if window.onload hasn't fired
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize if not already initialized (window.onload might fire first)
    if (!pageInitialized) {
        // Small delay to ensure window.onload has a chance to fire first
        setTimeout(function() {
            if (!pageInitialized) {
                initializePage();
            }
        }, 50);
    }
});

// PDF Upload Functions
function validatePDF(input) {
    const file = input.files[0];
    if (file) {
        const fileExtension = file.name.split('.').pop().toLowerCase();
        if (fileExtension !== 'pdf') {
            alert('Please select a valid PDF file.');
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

function uploadDocument(fileId, srno) {
    const fileInput = document.getElementById(fileId);
    const statusDiv = document.getElementById(fileId + '_status');
    
    if (!fileInput.files[0]) {
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
    
    const formData = new FormData();
    formData.append('upload_document', '1');
    formData.append('file_id', fileId);
    formData.append('srno', srno);
    formData.append('document', fileInput.files[0]);
    formData.append('csrf_token', csrfToken);
    
    statusDiv.innerHTML = '<span class="text-info">Uploading...</span>';
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .catch(error => {
        // CRITICAL: Handle fetch errors (network, CORS, etc.) - return error object
        console.error('[Collaborations] Fetch error for upload:', error);
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
            console.error('[Collaborations] Invalid response object:', response);
            return { success: false, message: 'Upload failed: Invalid server response' };
        }
        
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
        }).catch(textError => {
            // CRITICAL: Handle text() parsing errors
            console.error('[Collaborations] Error reading response text:', textError);
            return { success: false, message: 'Upload failed: Error reading server response' };
        });
    })
    .then(data => {
        // CRITICAL #3: Handle null/undefined data gracefully
        if (!data || typeof data !== 'object') {
            statusDiv.innerHTML = '<span class="text-danger">Upload failed: Invalid server response</span>';
            statusDiv.className = 'mt-2 text-danger';
            return;
        }
        
        if (data.success) {
            // Check if form is locked by checking any input field's disabled state
            const testInput = document.querySelector('input[type="number"]:not([name="academic_year"]):not([name="dept_info"])');
            const isFormLocked = testInput ? testInput.disabled : <?php echo $form_locked ? 'true' : 'false'; ?>;
            
            // Check form lock status dynamically
            const formInput = document.querySelector('input[name="b1"]');
            const isFormLockedNow = formInput ? formInput.disabled : false;
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            var deleteButton = isFormLockedNow ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>`;
            
            // Ensure file path is web-accessible (same robust logic as StudentSupport.php)
            let viewPath = data.file_path || '';
            
            // Convert absolute paths to relative web paths (same as StudentSupport.php)
            if (viewPath && (viewPath.startsWith('/home/') || viewPath.startsWith('C:/') || viewPath.startsWith('C:\\'))) {
                // Extract relative path from absolute path (case-insensitive match for directory names)
                const match = viewPath.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)$/i);
                if (match) {
                    viewPath = '../' + match[1];
                }
            } else if (viewPath && !viewPath.startsWith('../') && !viewPath.startsWith('http')) {
                if (viewPath.startsWith('uploads/')) {
                    viewPath = '../' + viewPath;
                } else if (viewPath.includes('uploads/')) {
                    const match = viewPath.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)/i);
                    if (match) {
                        viewPath = '../' + match[1];
                    }
                }
            }
            
            // CRITICAL: Escape viewPath for HTML attribute to prevent XSS and syntax errors
            const escapedViewPath = viewPath ? String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
            statusDiv.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <span class="text-success">Document uploaded successfully</span>
                    ${deleteButton}
                    ${escapedViewPath ? `<a href="${escapedViewPath}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                        <i class="fas fa-eye"></i> View
                    </a>` : ''}
                </div>
            `;
            statusDiv.className = 'mt-2 text-success';
        } else {
            statusDiv.innerHTML = `<span class="text-danger">Upload failed: ${data.message}</span>`;
            statusDiv.className = 'mt-2 text-danger';
        }
    })
    .catch(error => {
        // CRITICAL #5: Handle errors gracefully - return object, don't throw
        statusDiv.innerHTML = `<span class="text-danger">Upload failed: ${error.message || 'Unknown error'}</span>`;
        statusDiv.className = 'mt-2 text-danger';
    });
}

function deleteDocument(fileId, srno) {
    if (!confirm('Are you sure you want to delete this document?')) {
        return;
    }
    
    const statusDiv = document.getElementById(fileId + '_status');
    const formData = new FormData();
    formData.append('delete_document', '1');
    formData.append('file_id', fileId);
    formData.append('srno', srno);
    
    statusDiv.innerHTML = '<span class="text-info">Deleting...</span>';
    
    fetch('', {
        method: 'POST',
        body: formData
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
            
            // Check if response is HTML (starts with <!DOCTYPE or <html)
            if (trimmed.startsWith('<!DOCTYPE') || trimmed.startsWith('<html') || trimmed.startsWith('<')) {
                return { success: false, message: 'Invalid server response: Received HTML instead of JSON. Please refresh and try again.' };
            }
            
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
            // Clear the file input
            const fileInput = document.getElementById(fileId);
            if (fileInput) {
                fileInput.value = '';
            }
        } else {
            statusDiv.innerHTML = `<span class="text-danger">Delete failed: ${data.message || 'Delete failed'}</span>`;
            statusDiv.className = 'mt-2 text-danger';
        }
    })
    .catch(error => {
        // CRITICAL #5: Handle errors gracefully - return object, don't throw
        statusDiv.innerHTML = `<span class="text-danger">Delete failed: ${error.message || 'Unknown error'}</span>`;
        statusDiv.className = 'mt-2 text-danger';
        
        // Return resolved promise to prevent unhandled promise rejection
        return Promise.resolve({ success: false, message: 'Delete failed: ' + (error.message || 'Unknown error') });
    });
}

// Track active check requests to prevent duplicate calls and unlimited database requests
const activeDocumentChecks = new Set();

function checkDocumentStatus(fileId, srno) {
    const statusDiv = document.getElementById(fileId + '_status');
    if (!statusDiv) {
        return;
    }
    
    // CRITICAL: Prevent duplicate checks for the same document - prevent unlimited database requests
    const checkKey = `${fileId}_${srno}`;
    if (activeDocumentChecks.has(checkKey)) {
        return; // Already checking this document
    }
    
    // Add to active checks
    activeDocumentChecks.add(checkKey);
    
    // CRITICAL: Add request timeout with AbortController
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
    
    executeWithRateLimit(() => {
        return fetch(`?check_doc=1&srno=${srno}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            },
            cache: 'no-cache',
            signal: controller.signal
        })
        .then(response => {
            clearTimeout(timeoutId);
            if (!response.ok) {
                activeDocumentChecks.delete(checkKey);
                return { success: false, message: 'HTTP error: ' + response.status };
            }
            return response;
        })
        .catch(error => {
            clearTimeout(timeoutId);
            // CRITICAL: Handle fetch errors (network, CORS, timeout, etc.) - return error object
            activeDocumentChecks.delete(checkKey);
            if (error.name === 'AbortError') {
                return { 
                    ok: false, 
                    text: () => Promise.resolve(JSON.stringify({ 
                        success: false, 
                        message: 'Request timeout' 
                    })) 
                };
            }
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
            console.error('[Collaborations] Invalid response object:', response);
            return { success: false, message: 'Check failed: Invalid server response' };
        }
        
        // CRITICAL #3: Handle empty responses in JS - return object, don't throw
        return response.text().then(text => {
            const trimmed = text.trim();
            
            // If empty response, return error object instead of throwing
            if (!trimmed || trimmed === '') {
                activeDocumentChecks.delete(checkKey);
                return { success: false, message: 'No document found' };
            }
            
            // Try to parse as JSON
            try {
                const json = JSON.parse(trimmed);
                activeDocumentChecks.delete(checkKey);
                return json;
            } catch (e) {
                // CRITICAL #5: Return error response instead of throwing
                activeDocumentChecks.delete(checkKey);
                return { success: false, message: 'Invalid server response' };
            }
        }).catch(textError => {
            // CRITICAL: Handle text() parsing errors
            activeDocumentChecks.delete(checkKey);
            console.error('[Collaborations] Error reading response text:', textError);
            return { success: false, message: 'Check failed: Error reading server response' };
        });
        });
    })
    .then(data => {
        // CRITICAL #3: Handle null/undefined data gracefully
        if (!data || typeof data !== 'object') {
            return;
        }
        
        if (data.success && data.file_path) {
            // Check if form is locked by checking any input field's disabled state
            const testInput = document.querySelector('input[type="number"]:not([name="academic_year"]):not([name="dept_info"])');
            const isFormLocked = testInput ? testInput.disabled : <?php echo $form_locked ? 'true' : 'false'; ?>;
            
            // Check form lock status dynamically
            const formInput = document.querySelector('input[name="b1"]');
            const isFormLockedNow = formInput ? formInput.disabled : false;
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId3 = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            var deleteButton = isFormLockedNow ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId3}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>`;
            
            // Ensure correct path format for view link
            let viewPath = data.file_path;
            // If path doesn't start with '../' and starts with 'uploads/', prepend '../'
            if (viewPath && !viewPath.startsWith('../') && viewPath.startsWith('uploads/')) {
                viewPath = '../' + viewPath;
            }
            // If path is absolute, try to convert to relative
            if (viewPath && (viewPath.startsWith('/home/') || viewPath.startsWith('C:/') || viewPath.startsWith('C:\\'))) {
                // Extract relative path if possible
                const match = viewPath.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)$/);
                if (match) {
                    viewPath = '../' + match[1];
                }
            }
            
            // CRITICAL: Escape viewPath for HTML attribute to prevent XSS and syntax errors
            const escapedViewPath3 = viewPath ? String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
            statusDiv.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <span class="text-success">Document uploaded</span>
                    ${deleteButton}
                    <a href="${escapedViewPath3}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
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
        // CRITICAL: Remove from active checks on ANY error - prevent loops
        activeDocumentChecks.delete(checkKey);
        // Return resolved promise with error object - prevent unhandled promise rejection
        return Promise.resolve({ success: false, message: 'Network error: ' + (error.message || 'Unknown error') });
    })
    .then(data => {
        // CRITICAL: Handle null/undefined data gracefully
        if (!data || typeof data !== 'object') {
        statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
        statusDiv.className = 'mt-2 text-muted';
            return;
        }
        
        // Process the data (existing logic continues here)
        if (data.success && data.file_path) {
            // Check if form is locked by checking any input field's disabled state
            const testInput = document.querySelector('input[type="number"]:not([name="academic_year"]):not([name="dept_info"])');
            const isFormLocked = testInput ? testInput.disabled : <?php echo $form_locked ? 'true' : 'false'; ?>;
            
            // Check form lock status dynamically
            const formInput = document.querySelector('input[name="b1"]');
            const isFormLockedNow = formInput ? formInput.disabled : false;
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId3 = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            var deleteButton = isFormLockedNow ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId3}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>`;
            
            // Ensure correct path format for view link
            let viewPath = data.file_path;
            // If path doesn't start with '../' and starts with 'uploads/', prepend '../'
            if (viewPath && !viewPath.startsWith('../') && viewPath.startsWith('uploads/')) {
                viewPath = '../' + viewPath;
            }
            // If path is absolute, try to convert to relative
            if (viewPath && (viewPath.startsWith('/home/') || viewPath.startsWith('C:/') || viewPath.startsWith('C:\\'))) {
                // Extract relative path if possible
                const match = viewPath.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)$/);
                if (match) {
                    viewPath = '../' + match[1];
                }
            }
            
            // CRITICAL: Escape viewPath for HTML attribute to prevent XSS and syntax errors
            const escapedViewPath3 = viewPath ? String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
            statusDiv.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <span class="text-success">Document uploaded</span>
                    ${deleteButton}
                    <a href="${escapedViewPath3}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                        <i class="fas fa-eye"></i> View
                    </a>
                </div>
            `;
            statusDiv.className = 'mt-2 text-success';
        } else {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
        }
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

// CRITICAL PERFORMANCE FIX: Load ALL documents in ONE batch request instead of 5 individual requests
// This reduces database load by 80% (5 queries → 1 query)
let documentsLoading = false;
let documentsLoadAttempted = false;

function loadExistingDocuments() {
    if (documentsLoading || documentsLoadAttempted) {
        return; 
    }
    documentsLoading = true;
    documentsLoadAttempted = true;
    
    // CRITICAL: Add request timeout with AbortController
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
    
    // CRITICAL: Use consolidated endpoint with rate limiting - ONE request instead of 5
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
            // Map of fileId to srno for updating UI
            const documentMap = {
                'industry_collaborations_pdf': 1,
                'national_academic_pdf': 2,
                'government_collaborations_pdf': 3,
                'international_academic_pdf': 4,
                'outreach_social_pdf': 5
            };
    
            // Update all document statuses from batch response
            // data.documents is an object keyed by serial_number (srno)
            Object.keys(documentMap).forEach(fileId => {
                const srno = documentMap[fileId];
                const docData = data.documents[srno];
                if (docData && docData.success) {
                    const statusDiv = document.getElementById(fileId + '_status');
                    if (statusDiv) {
                        updateDocumentStatusUI(fileId, srno, docData.file_path, docData.file_name);
                    }
                }
            });
        } else {
            console.error('Error loading all documents:', data.message || 'Unknown error');
        }
    })
    .catch(error => {
        console.error('Network error loading all documents:', error);
    })
    .finally(() => {
        documentsLoading = false;
    });
}

// Helper function to update document status UI from consolidated response
function updateDocumentStatusUI(fileId, srno, filePath, fileName) {
    const statusDiv = document.getElementById(fileId + '_status');
    if (!statusDiv) return;

    // Check form lock state dynamically
    const formInput = document.querySelector('input[name="b1"]');
    const isFormLockedNow = formInput ? formInput.disabled : <?php echo $form_locked ? 'true' : 'false'; ?>;
    // CRITICAL: Escape fileId to prevent JavaScript syntax errors
    const escapedFileId2 = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
    var deleteButton = isFormLockedNow ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId2}', ${srno})">
                <i class="fas fa-trash"></i> Delete
            </button>`;
    
    let viewPath = filePath || '';
    // Normalize path for web access (same robust logic as StudentSupport.php)
    if (viewPath && (viewPath.startsWith('/home/') || viewPath.startsWith('C:/') || viewPath.startsWith('C:\\'))) {
        // Extract relative path from absolute path (case-insensitive match)
        const match = viewPath.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)$/i);
        if (match) {
            viewPath = '../' + match[1];
        }
    } else if (viewPath && !viewPath.startsWith('../') && !viewPath.startsWith('http')) {
        if (viewPath.startsWith('uploads/')) {
            viewPath = '../' + viewPath;
        } else if (viewPath.includes('uploads/')) {
            const match = viewPath.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)/i);
            if (match) {
                viewPath = '../' + match[1];
            }
        }
    }
    
    // CRITICAL: Escape viewPath for HTML attribute to prevent XSS and syntax errors
    const escapedViewPath2 = viewPath ? String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
    const viewButton = escapedViewPath2 ? `<a href="${escapedViewPath2}" target="_blank" class="btn btn-sm btn-outline-primary ms-1" rel="noopener noreferrer">
                <i class="fas fa-eye"></i> View
            </a>` : '';
    
    statusDiv.innerHTML = `
        <div class="d-flex align-items-center flex-wrap">
            <i class="fas fa-check-circle text-success me-2"></i>
            <span class="text-success me-2">Document uploaded</span>
            ${deleteButton}
            ${viewButton}
        </div>
    `;
    statusDiv.className = 'mt-2 text-success';
}

function refreshAllDocumentStatuses() {
    loadExistingDocuments();
}

function enableUpdate() {
    // Enable all form fields
    const inputs = document.querySelectorAll('input[type="number"]');
    inputs.forEach(input => {
        if (input.name !== 'academic_year' && input.name !== 'dept_info') {
            input.disabled = false;
        }
    });
    
    // Enable all file inputs and upload buttons
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(input => {
        input.disabled = false;
    });
    
    const uploadButtons = document.querySelectorAll('button[onclick*="uploadDocument"]');
    uploadButtons.forEach(button => {
        button.disabled = false;
    });
    
    // Show delete buttons for uploaded PDFs
    const deleteButtons = document.querySelectorAll('button[onclick*="deleteDocument"]');
    deleteButtons.forEach(button => {
        button.style.display = 'inline-block';
    });
    
    // Show update button and hide enable button
    document.getElementById('updateBtn').style.display = 'inline-block';
    document.querySelector('button[onclick="enableUpdate()"]').style.display = 'none';
    
    // Refresh document statuses to show delete buttons
    refreshAllDocumentStatuses();
    
    // Recalculate totals
    calculateTotalB();
}

function confirmClearData() {
    if (confirm('Are you sure you want to clear all data? This action cannot be undone.')) {
        window.location.href = 'Collaborations.php?action=clear';
    }
}
</script>
<?php
require "unified_footer.php";
?>
