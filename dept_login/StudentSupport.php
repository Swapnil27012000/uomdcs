<?php
// StudentSupport - Student support form
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
if (isset($_POST['upload_document'])) {
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
        $file_id = $_POST['file_id'] ?? '';
        $srno = (int)($_POST['srno'] ?? 0);
    
        // Get department ID from session
        $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
        if (!$dept_id) {
            echo json_encode(['success' => false, 'message' => 'Department ID not found. Please login again.']);
            exit;
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
                // CRITICAL: July onwards (month >= 7) = new year (2025-2026), Jan-June = old year (2024-2025)
                if ($current_month >= 7) {
                    $A_YEAR = $current_year . '-' . ($current_year + 1);
                } else {
                    $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
                }
            }
            // A_YEAR is now VARCHAR(20), can store full "2024-2025" format
            $A_YEAR_DB = $A_YEAR;  // Use full year string
        
        // Route through common_upload_handler.php
        require_once(__DIR__ . '/common_upload_handler.php');
        
        // Set global variables for common_upload_handler.php
        $GLOBALS['dept_id'] = $dept_id;
        $GLOBALS['A_YEAR'] = $A_YEAR;
        
        // Use common upload handler
        // Structure: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/Student_support/
        $result = handleDocumentUpload('student_support', 'Student Support', [
            'upload_dir' => dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept_id}/Student_support/",
            'max_size' => 10,
            'document_title' => 'Student Support Documentation',
            'srno' => $srno,
            'file_id' => $file_id
        ]);
        
        // Clear buffers before output
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Ensure headers are set
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
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
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            if ($current_month >= 7) {
    $A_YEAR = $current_year . '-' . ($current_year + 1);
} else {
    $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
}
            // A_YEAR is now VARCHAR(20), can store full "2024-2025" format
            $A_YEAR_DB = $A_YEAR;  // Use full year string
            
            // Get file path using prepared statement
            $get_file_query = "SELECT file_path FROM supporting_documents WHERE academic_year = ? AND dept_id = ? AND page_section = 'student_support' AND serial_number = ? AND status = 'active' LIMIT 1";
            $stmt_get = mysqli_prepare($conn, $get_file_query);
            if (!$stmt_get) {
                $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
            } else {
                mysqli_stmt_bind_param($stmt_get, 'sii', $A_YEAR, $dept_id, $srno);
                if (mysqli_stmt_execute($stmt_get)) {
                    $result_get = mysqli_stmt_get_result($stmt_get);
                    
                    if ($result_get && mysqli_num_rows($result_get) > 0) {
                        $row = mysqli_fetch_assoc($result_get);
                        $file_path = $row['file_path'];
                        mysqli_free_result($result_get);  // CRITICAL: Free result
                        mysqli_stmt_close($stmt_get);
        
                        // Delete file from filesystem
                        $physical_path = strpos($file_path, '../') === 0 ? dirname(__DIR__) . '/' . str_replace('../', '', $file_path) : (strpos($file_path, 'uploads/') === 0 ? dirname(__DIR__) . '/' . $file_path : $file_path);
                        $physical_path = str_replace('\\', '/', $physical_path);
                        if ($physical_path && file_exists($physical_path)) {
                            @unlink($physical_path);
                        }
                        
                        // Soft delete using prepared statement
                        $delete_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE academic_year = ? AND dept_id = ? AND page_section = 'student_support' AND serial_number = ? AND status = 'active'";
                        $stmt_del = mysqli_prepare($conn, $delete_query);
                        if ($stmt_del) {
                            mysqli_stmt_bind_param($stmt_del, 'sii', $A_YEAR, $dept_id, $srno);
                            if (mysqli_stmt_execute($stmt_del)) {
                                $response = ['success' => true, 'message' => 'Document deleted successfully'];
                            } else {
                                $response = ['success' => false, 'message' => 'Database error: Failed to execute delete query'];
                            }
                            mysqli_stmt_close($stmt_del);  // CRITICAL: Close statement
                        } else {
                            $response = ['success' => false, 'message' => 'Database error: Failed to prepare delete query'];
                        }
                    } else {
                        if ($result_get) {
                            mysqli_free_result($result_get);  // CRITICAL: Free result even if empty
                        }
                        mysqli_stmt_close($stmt_get);
                        $response = ['success' => false, 'message' => 'Document not found'];
                    }
                } else {
                    mysqli_stmt_close($stmt_get);
                    $response = ['success' => false, 'message' => 'Database error: Failed to execute query'];
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
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
            $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
            if (!$dept_id) {
                $response = ['success' => false, 'message' => 'Department ID not found'];
            } else {
                $current_year = (int)date('Y');
                $current_month = (int)date('n');
                if ($current_month >= 7) {
    $academic_year = $current_year . '-' . ($current_year + 1);
} else {
    $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
}
                
                // CRITICAL FIX: Query with fallback to check current year AND previous year
                // This handles cases where documents were uploaded in previous academic year
                $batch_query = "SELECT serial_number, file_path, file_name, upload_date, id, academic_year
                               FROM supporting_documents 
                               WHERE dept_id = ? AND page_section = 'student_support' 
                               AND (academic_year = ? OR academic_year = ?) AND status = 'active'
                               ORDER BY academic_year DESC, id DESC";
                $stmt = mysqli_prepare($conn, $batch_query);
                
                if (!$stmt) {
                    $response = ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
                } else {
                    // Calculate previous academic year for fallback
                    $prev_year = '';
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
                                    $file_path = '../uploads/' . $doc_year . '/DEPARTMENT/' . $dept_id . '/Student_support/' . $filename;
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
        
        // CRITICAL: Verify connection exists and is alive after loading config
        if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
            // Connection failed or timed out - return proper error response
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(503); // Service Unavailable (NOT 404)
            }
            echo json_encode(['success' => false, 'message' => 'Database connection unavailable. Please refresh the page and try again.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        
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
                $current_year = (int)date('Y');
                $current_month = (int)date('n');
                if ($current_month >= 7) {
    $A_YEAR = $current_year . '-' . ($current_year + 1);
} else {
    $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
}
                
                // Use prepared statement
                $check_query = "SELECT file_path, file_name, upload_date, id FROM supporting_documents WHERE academic_year = ? AND dept_id = ? AND page_section = 'student_support' AND serial_number = ? AND status = 'active' LIMIT 1";
                $stmt = mysqli_prepare($conn, $check_query);
                if (!$stmt) {
                    $response = ['success' => false, 'message' => 'Database error'];
                } else {
                    mysqli_stmt_bind_param($stmt, 'sii', $A_YEAR, $dept_id, $srno);
                    if (mysqli_stmt_execute($stmt)) {
                        $result = mysqli_stmt_get_result($stmt);
                        
                        if ($result && mysqli_num_rows($result) > 0) {
                            $row = mysqli_fetch_assoc($result);
                            mysqli_free_result($result);  // CRITICAL: Free result
                            mysqli_stmt_close($stmt);
                            
                            // Convert to web-accessible path
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
    // FIXED: A_YEAR column is now VARCHAR(20), can store full "2024-2025" format
    $A_YEAR_DB = $A_YEAR;  // Use full year string

// Initialize variables
$form_locked = false;

// ============================================================================
// HANDLE FORM SUBMISSION - MUST BE BEFORE unified_header.php
// ============================================================================
if (isset($_POST['submit'])) {
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
                    header('Location: StudentSupport.php');
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
            header('Location: StudentSupport.php');
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
            header('Location: StudentSupport.php');
        }
        exit;
    }
    
    try {
    
    // Get form data with proper validation
    $record_id = isset($_POST['record_id']) ? (int)$_POST['record_id'] : 0;

        // Process Research Fellows data
        $jrfs_count = isset($_POST['jrfs_count']) ? (int)$_POST['jrfs_count'] : 0;
        $srfs_count = isset($_POST['srfs_count']) ? (int)$_POST['srfs_count'] : 0;
        $post_doctoral_count = isset($_POST['post_doctoral_count']) ? (int)$_POST['post_doctoral_count'] : 0;
        $research_associates_count = isset($_POST['research_associates_count']) ? (int)$_POST['research_associates_count'] : 0;
        $other_research_count = isset($_POST['other_research_count']) ? (int)$_POST['other_research_count'] : 0;
        
        // Process JRFs data
        $jrfs_data = [];
        if ($jrfs_count > 0) {
    for ($i = 1; $i <= $jrfs_count; $i++) {
                $name = isset($_POST["jrf_name_$i"]) ? trim($_POST["jrf_name_$i"]) : '';
                $fellowship = isset($_POST["jrf_fellowship_$i"]) ? trim($_POST["jrf_fellowship_$i"]) : '';
                if (!empty($name) || !empty($fellowship)) {
                    $jrfs_data[] = [
                        'name' => $name,
                        'fellowship' => $fellowship
                    ];
                }
            }
        }
        
        // Process SRFs data
        $srfs_data = [];
        if ($srfs_count > 0) {
    for ($i = 1; $i <= $srfs_count; $i++) {
                $name = isset($_POST["srf_name_$i"]) ? trim($_POST["srf_name_$i"]) : '';
                $fellowship = isset($_POST["srf_fellowship_$i"]) ? trim($_POST["srf_fellowship_$i"]) : '';
                if (!empty($name) || !empty($fellowship)) {
                    $srfs_data[] = [
                        'name' => $name,
                        'fellowship' => $fellowship
                    ];
                }
            }
        }
        
        // Process Post Doctoral Fellows data
        $post_doctoral_data = [];
        if ($post_doctoral_count > 0) {
    for ($i = 1; $i <= $post_doctoral_count; $i++) {
                $name = isset($_POST["post_doctoral_name_$i"]) ? trim($_POST["post_doctoral_name_$i"]) : '';
                $fellowship = isset($_POST["post_doctoral_fellowship_$i"]) ? trim($_POST["post_doctoral_fellowship_$i"]) : '';
                if (!empty($name) || !empty($fellowship)) {
                    $post_doctoral_data[] = [
                        'name' => $name,
                        'fellowship' => $fellowship
                    ];
                }
            }
        }
        
        // Process Research Associates data
        $research_associates_data = [];
        if ($research_associates_count > 0) {
    for ($i = 1; $i <= $research_associates_count; $i++) {
                $name = isset($_POST["research_associate_name_$i"]) ? trim($_POST["research_associate_name_$i"]) : '';
                $fellowship = isset($_POST["research_associate_fellowship_$i"]) ? trim($_POST["research_associate_fellowship_$i"]) : '';
                if (!empty($name) || !empty($fellowship)) {
                    $research_associates_data[] = [
                        'name' => $name,
                        'fellowship' => $fellowship
                    ];
                }
            }
        }
        
        // Process Other Research Fellows data
        $other_research_data = [];
        if ($other_research_count > 0) {
    for ($i = 1; $i <= $other_research_count; $i++) {
                $name = isset($_POST["other_research_name_$i"]) ? trim($_POST["other_research_name_$i"]) : '';
                $fellowship = isset($_POST["other_research_fellowship_$i"]) ? trim($_POST["other_research_fellowship_$i"]) : '';
                if (!empty($name) || !empty($fellowship)) {
            $other_research_data[] = [
                        'name' => $name,
                        'fellowship' => $fellowship
                    ];
                }
            }
        }
        
        // Process Research Activity data
        $research_activity_type = isset($_POST['research_activity_type']) ? trim($_POST['research_activity_type']) : '';
        $research_activities_count = isset($_POST['research_activities_count']) ? (int)$_POST['research_activities_count'] : 0;
        $research_activity_other = isset($_POST['research_activity_other']) ? trim($_POST['research_activity_other']) : '';
        $research_activities_data = [];
        if ($research_activities_count > 0) {
    for ($i = 1; $i <= $research_activities_count; $i++) {
                $student_name = isset($_POST["research_student_name_$i"]) ? trim($_POST["research_student_name_$i"]) : '';
                if (!empty($student_name)) {
                    $research_activities_data[] = [
                        'student_name' => $student_name
                    ];
                }
            }
        }
        
        // Process Awards data
        $awards_count = isset($_POST['awards_count']) ? (int)$_POST['awards_count'] : 0;
        $awards_data = [];
        if ($awards_count > 0) {
    for ($i = 1; $i <= $awards_count; $i++) {
                $category = isset($_POST["award_category_$i"]) ? trim($_POST["award_category_$i"]) : '';
                $level = isset($_POST["award_level_$i"]) ? trim($_POST["award_level_$i"]) : '';
                $awardee_name = isset($_POST["awardee_name_$i"]) ? trim($_POST["awardee_name_$i"]) : '';
                $award_name = isset($_POST["award_name_$i"]) ? trim($_POST["award_name_$i"]) : '';
                if (!empty($category) || !empty($level) || !empty($awardee_name) || !empty($award_name)) {
                    $awards_data[] = [
                        'category' => $category,
                        'level' => $level,
                        'awardee_name' => $awardee_name,
                        'award_name' => $award_name
                    ];
                }
            }
        }
        
        // Process Support Initiatives data
        $support_initiatives_count = isset($_POST['support_initiatives_count']) ? (int)$_POST['support_initiatives_count'] : 0;
        $support_initiatives_data = [];
        if ($support_initiatives_count > 0) {
    for ($i = 1; $i <= $support_initiatives_count; $i++) {
                $initiative_name = isset($_POST["support_initiative_name_$i"]) ? trim($_POST["support_initiative_name_$i"]) : '';
                $description = isset($_POST["support_initiative_description_$i"]) ? trim($_POST["support_initiative_description_$i"]) : '';
                if (!empty($initiative_name) || !empty($description)) {
                    $support_initiatives_data[] = [
                        'initiative_name' => $initiative_name,
                        'description' => $description
                    ];
                }
            }
        }
        
        // Process Internship/OJT data
        $internship_count = isset($_POST['internship_count']) ? (int)$_POST['internship_count'] : 0;
        $internship_data = [];
        if ($internship_count > 0) {
    for ($i = 1; $i <= $internship_count; $i++) {
                $program_name = isset($_POST["internship_program_name_$i"]) ? trim($_POST["internship_program_name_$i"]) : '';
                $male_count = isset($_POST["internship_male_$i"]) ? (int)$_POST["internship_male_$i"] : 0;
                $female_count = isset($_POST["internship_female_$i"]) ? (int)$_POST["internship_female_$i"] : 0;
                if (!empty($program_name) || $male_count > 0 || $female_count > 0) {
            $internship_data[] = [
                        'program_name' => $program_name,
                        'male_count' => $male_count,
                        'female_count' => $female_count
                    ];
                }
            }
        }
        
        
        // Store data as JSON (prepared statements handle escaping)
        $jrfs_json = json_encode($jrfs_data);
        $srfs_json = json_encode($srfs_data);
        $post_doctoral_json = json_encode($post_doctoral_data);
        $research_associates_json = json_encode($research_associates_data);
        $other_research_json = json_encode($other_research_data);
        $research_activities_json = json_encode($research_activities_data);
        $awards_json = json_encode($awards_data);
        $support_initiatives_json = json_encode($support_initiatives_data);
        $internship_json = json_encode($internship_data);
        
        // Get academic year and department (already set from session.php)
        $current_year = (int)date("Y");
        $current_month = (int)date("n");
        if ($current_month >= 7) {
    $A_YEAR = $current_year . '-' . ($current_year + 1);
} else {
    $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
}
        // FIXED: A_YEAR column is now VARCHAR(20), can store full "2024-2025" format
        $A_YEAR_DB = $A_YEAR;  // Use full year string
        $dept = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
        
        if (!$dept) {
            throw new Exception('Department ID not found. Please login again.');
        }
        
        // Check if this is an update or insert
    if ($record_id > 0) {
            // UPDATE query using prepared statements
        $query = "UPDATE `studentsupport` SET 
                    `jrfs_count` = ?,
                    `srfs_count` = ?,
                    `post_doctoral_count` = ?,
                    `research_associates_count` = ?,
                    `other_research_count` = ?,
                    `jrfs_data` = ?,
                    `srfs_data` = ?,
                    `post_doctoral_data` = ?,
                    `research_associates_data` = ?,
                    `other_research_data` = ?,
                    `research_activity_type` = ?,
                    `research_activities_count` = ?,
                    `research_activity_other` = ?,
                    `research_activities_data` = ?,
                    `awards_count` = ?,
                    `awards_data` = ?,
                    `support_initiatives_count` = ?,
                    `support_initiatives_data` = ?,
                    `internship_count` = ?,
                    `internship_data` = ?
                    WHERE `id` = ? AND `dept` = ?";
            
            $stmt = mysqli_prepare($conn, $query);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'iiiiissssssissisisisii', 
                    $jrfs_count, $srfs_count, $post_doctoral_count, $research_associates_count, $other_research_count,
                    $jrfs_json, $srfs_json, $post_doctoral_json, $research_associates_json, $other_research_json,
                    $research_activity_type, $research_activities_count, $research_activity_other, $research_activities_json,
                    $awards_count, $awards_json, $support_initiatives_count, $support_initiatives_json,
                    $internship_count, $internship_json, $record_id, $dept);
                
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    
                    // CRITICAL: Clear and recalculate score cache after data update
                    require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
                    clearDepartmentScoreCache($dept, $A_YEAR_DB, true);
                    
                    $_SESSION['success'] = 'Data Updated Successfully! Form is now locked.';
                    // CRITICAL: Log success for debugging
                    error_log("StudentSupport: Data updated successfully for dept=$dept, A_YEAR=$A_YEAR_DB, record_id=$record_id");
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    if (!headers_sent()) {
                        header('Location: StudentSupport.php');
                    }
                    exit;
    } else {
                    $error = mysqli_stmt_error($stmt);
                    mysqli_stmt_close($stmt);
                    // CRITICAL: Log error for debugging
                    error_log("StudentSupport: Update failed - " . $error . " | dept=$dept, A_YEAR=$A_YEAR_DB, record_id=$record_id");
                    throw new Exception('Update failed: ' . $error);
                }
            } else {
                throw new Exception('Prepare failed: ' . mysqli_error($conn));
            }
        } else {
            // Check if row exists for this dept and year
            $check_query = "SELECT id FROM studentsupport WHERE dept = ? AND A_YEAR = ? LIMIT 1";
            $check_stmt = mysqli_prepare($conn, $check_query);
            $existing_record_id = 0;
            if ($check_stmt) {
                mysqli_stmt_bind_param($check_stmt, 'is', $dept, $A_YEAR_DB);  // Changed to 'is' (int, string)
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                if ($check_result && mysqli_num_rows($check_result) > 0) {
                    $check_row = mysqli_fetch_assoc($check_result);
                    $existing_record_id = (int)$check_row['id'];
                    mysqli_free_result($check_result); // CRITICAL: Free result
                } else {
                    if ($check_result) {
                        mysqli_free_result($check_result); // CRITICAL: Free result even if empty
                    }
                }
                mysqli_stmt_close($check_stmt);
            }
            
            if ($existing_record_id > 0) {
                // UPDATE existing row
                $query = "UPDATE `studentsupport` SET 
                        `jrfs_count` = ?,
                        `srfs_count` = ?,
                        `post_doctoral_count` = ?,
                        `research_associates_count` = ?,
                        `other_research_count` = ?,
                        `jrfs_data` = ?,
                        `srfs_data` = ?,
                        `post_doctoral_data` = ?,
                        `research_associates_data` = ?,
                        `other_research_data` = ?,
                        `research_activity_type` = ?,
                        `research_activities_count` = ?,
                        `research_activity_other` = ?,
                        `research_activities_data` = ?,
                        `awards_count` = ?,
                        `awards_data` = ?,
                        `support_initiatives_count` = ?,
                        `support_initiatives_data` = ?,
                        `internship_count` = ?,
                        `internship_data` = ?
                        WHERE `id` = ? AND `dept` = ?";
                
                $stmt = mysqli_prepare($conn, $query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'iiiiissssssissisisisii', 
                        $jrfs_count, $srfs_count, $post_doctoral_count, $research_associates_count, $other_research_count,
                        $jrfs_json, $srfs_json, $post_doctoral_json, $research_associates_json, $other_research_json,
                        $research_activity_type, $research_activities_count, $research_activity_other, $research_activities_json,
                        $awards_count, $awards_json, $support_initiatives_count, $support_initiatives_json,
                        $internship_count, $internship_json, $existing_record_id, $dept);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        mysqli_stmt_close($stmt);
                        $_SESSION['success'] = 'Data Updated Successfully! Form is now locked.';
                        // CRITICAL: Log success for debugging
                        error_log("StudentSupport: Data updated successfully for dept=$dept, A_YEAR=$A_YEAR_DB, existing_record_id=$existing_record_id");
                        while (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                        if (!headers_sent()) {
                            header('Location: StudentSupport.php');
                        }
                        exit;
                    } else {
                        $error = mysqli_stmt_error($stmt);
                        mysqli_stmt_close($stmt);
                        // CRITICAL: Log error for debugging
                        error_log("StudentSupport: Update failed - " . $error . " | dept=$dept, A_YEAR=$A_YEAR_DB, existing_record_id=$existing_record_id");
                        throw new Exception('Update failed: ' . $error);
                    }
                } else {
                    throw new Exception('Prepare failed: ' . mysqli_error($conn));
                }
            } else {
                // CRITICAL: Double-check if data already exists (prevent race condition duplicates)
                $check_duplicate_query = "SELECT id FROM studentsupport WHERE dept = ? AND A_YEAR = ? LIMIT 1";
                $check_duplicate_stmt = mysqli_prepare($conn, $check_duplicate_query);
                $duplicate_exists = false;
                if ($check_duplicate_stmt) {
                    mysqli_stmt_bind_param($check_duplicate_stmt, 'is', $dept, $A_YEAR_DB);  // Changed to 'is' (int, string)
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
                        header('Location: StudentSupport.php');
                    }
                    exit;
                }
                
                // INSERT new row
        $query = "INSERT INTO `studentsupport` 
            (`dept`, `A_YEAR`, `jrfs_count`, `srfs_count`, `post_doctoral_count`, `research_associates_count`, 
            `other_research_count`, `jrfs_data`, `srfs_data`, `post_doctoral_data`, `research_associates_data`, 
            `other_research_data`, `research_activity_type`, `research_activities_count`, `research_activity_other`, `research_activities_data`, `awards_count`, `awards_data`, `support_initiatives_count`, `support_initiatives_data`, `internship_count`, `internship_data`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = mysqli_prepare($conn, $query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'isiiiiissssssissisisis',  // Changed 2nd param from 'i' to 's' (A_YEAR now string)
                        $dept, $A_YEAR_DB,
                        $jrfs_count, $srfs_count, $post_doctoral_count, $research_associates_count, $other_research_count,
                        $jrfs_json, $srfs_json, $post_doctoral_json, $research_associates_json, $other_research_json,
                        $research_activity_type, $research_activities_count, $research_activity_other, $research_activities_json,
                        $awards_count, $awards_json, $support_initiatives_count, $support_initiatives_json,
                        $internship_count, $internship_json);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $inserted_id = mysqli_insert_id($conn);
                        mysqli_stmt_close($stmt);
                        
                        // CRITICAL: Clear and recalculate score cache after data insert
                        require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
                        clearDepartmentScoreCache($dept, $A_YEAR_DB, true);
                        
                        $_SESSION['success'] = 'Data Submitted Successfully! Form is now locked.';
                        // CRITICAL: Log success for debugging
                        error_log("StudentSupport: Data inserted successfully for dept=$dept, A_YEAR=$A_YEAR_DB, inserted_id=$inserted_id");
                        while (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                        if (!headers_sent()) {
                            header('Location: StudentSupport.php');
                        }
                        exit;
        } else {
                        $error = mysqli_stmt_error($stmt);
                        mysqli_stmt_close($stmt);
                        // CRITICAL: Log error for debugging
                        error_log("StudentSupport: Insert failed - " . $error . " | dept=$dept, A_YEAR=$A_YEAR_DB");
                        throw new Exception('Insert failed: ' . $error);
                    }
                } else {
                    throw new Exception('Prepare failed: ' . mysqli_error($conn));
                }
            }
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Location: StudentSupport.php');
        }
        exit;
    }
}


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
    $get_files_query = "SELECT file_path FROM supporting_documents WHERE academic_year = ? AND dept_id = ? AND page_section = 'student_support' AND status = 'active'";
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
    $delete_docs_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE academic_year = ? AND dept_id = ? AND page_section = 'student_support' AND status = 'active'";
    $delete_docs_stmt = mysqli_prepare($conn, $delete_docs_query);
    if ($delete_docs_stmt) {
        mysqli_stmt_bind_param($delete_docs_stmt, 'si', $A_YEAR, $dept);
        mysqli_stmt_execute($delete_docs_stmt);
        mysqli_stmt_close($delete_docs_stmt);
    }
    
    $clear_query = "DELETE FROM studentsupport WHERE dept = ? AND A_YEAR = ?";
    $stmt = mysqli_prepare($conn, $clear_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $dept, $A_YEAR_DB);  // Changed to 'is' (int, string)
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $_SESSION['success'] = 'Data cleared successfully!';
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                header('Location: StudentSupport.php');
            }
            exit;
        }
        mysqli_stmt_close($stmt);
    }
}

if ($action === 'delete' && $edit_id) {
    $delete_query = "DELETE FROM studentsupport WHERE id = ? AND dept = ?";
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
                header('Location: StudentSupport.php');
            }
            exit;
        }
        mysqli_stmt_close($stmt);
    }
}

if ($action === 'edit' && $edit_id) {
    $edit_query = "SELECT * FROM studentsupport WHERE id = ? AND dept = ?";
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
$existing_data_query = "SELECT * FROM studentsupport WHERE dept = ? AND A_YEAR = ? LIMIT 1";
$existing_stmt = mysqli_prepare($conn, $existing_data_query);
if ($existing_stmt) {
    mysqli_stmt_bind_param($existing_stmt, 'is', $dept, $A_YEAR_DB);  // Changed to 'is' (int, string)
    mysqli_stmt_execute($existing_stmt);
    $existing_result = mysqli_stmt_get_result($existing_stmt);
    if ($existing_result && mysqli_num_rows($existing_result) > 0) {
        $existing_row = mysqli_fetch_assoc($existing_result);
        $form_locked = true; // Lock form if data exists
        // Populate form with existing data
        if (!$editRow) {
            $editRow = $existing_row;
        }
        mysqli_free_result($existing_result); // CRITICAL: Free result
    } else {
        if ($existing_result) {
            mysqli_free_result($existing_result); // CRITICAL: Free result even if empty
        }
    }
    mysqli_stmt_close($existing_stmt);
}

// Initialize message variables from session
$success_message = isset($_SESSION['success']) ? $_SESSION['success'] : '';
$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);

// ============================================================================
// NORMAL PAGE LOAD CONTINUES - Include headers and display form
// ============================================================================

require('unified_header.php');
?>

<div class="container-fluid" style="max-width: 100%; overflow-x: hidden;">
    <div class="main-content-area">
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-hands-helping me-3"></i>Student Support
                    </h1>
                    <p class="page-subtitle">Manage student support programs and research fellowships</p>
                </div>
                <a href="export_page_pdf.php?page=StudentSupport" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
            </div>
        </div>

        <?php 
        // Display Skip Form Button
        require_once(__DIR__ . '/skip_form_component.php');
        // Check if any student support data exists for this academic year
        $check_existing_query = "SELECT id FROM studentsupport WHERE dept = ? AND A_YEAR = ? LIMIT 1";
        $check_existing_stmt = mysqli_prepare($conn, $check_existing_query);
        $has_existing_data = false;
        if ($check_existing_stmt) {
            mysqli_stmt_bind_param($check_existing_stmt, "is", $dept, $A_YEAR);
            mysqli_stmt_execute($check_existing_stmt);
            $check_existing_result = mysqli_stmt_get_result($check_existing_stmt);
            if ($check_existing_result && mysqli_num_rows($check_existing_result) > 0) {
                $has_existing_data = true;
            }
            if ($check_existing_result) {
                mysqli_free_result($check_existing_result);
            }
            mysqli_stmt_close($check_existing_stmt);
        }
        displaySkipFormButton('student_support', 'Student Support', $A_YEAR, $has_existing_data);
        ?>

        <div class="card">
            <div class="card-body">
                <form class="modern-form" method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" id="mainForm" onsubmit="return Validate()" onkeydown="return handleKeyDown(event)">
                    <?php if ($editRow && isset($editRow['id'])): ?>
                        <input type="hidden" name="record_id" value="<?php echo (int)$editRow['id']; ?>">
                    <?php endif; ?>
                    <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                    <!-- Important Instructions -->
                    <div class="alert alert-warning">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i><b>Important Guidelines:</b></h5>
                        <ul class="mb-0">
                            <li><b>Enter accurate data for all research fellows and support programs</b></li>
                            <li><b>Include all JRFs, SRFs, Post Doctoral Fellows, and Research Associates</b></li>
                            <li><b>Upload supporting documents for verification</b></li>
                            <li><b>If not applicable, enter 0 for numeric fields</b></li>
                        </ul>
                    </div>
        
        <?php if ($form_locked): ?>
            <div class="alert alert-info">
                <i class="fas fa-lock me-2"></i><strong>Form Status:</strong> Data for the current academic year is locked. Click <em>Update</em> to make changes, or <em>Clear Data</em> to start over.
                </div>
        <?php endif; ?>
        
        <!-- Research Fellows Section -->
        <div class="card mb-4 p-3">
            <h4 class="mb-3">Number of JRFs, SRFs, Post Doctoral Fellows, Research Associates, and other research fellows in the Department / Schools enrolled during the last year</h4>
            
            
            
            <!-- JRFs -->
            <div class="mb-4">
                <h5>Junior Research Fellows (JRFs)</h5>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="fw-bold">Number of JRFs</label>
                        <input type="number" class="form-control" name="jrfs_count" id="jrfs_count" min="0" value="<?php echo $editRow ? (int)$editRow['jrfs_count'] : '0';?>" onchange="generateJrfFields()" onkeyup="generateJrfFields()" <?php echo $form_locked ? 'disabled' : ''; ?>>
                    </div>
                </div>
                <div id="jrf_fields_container">
                    <!-- Dynamic JRF fields will be generated here -->
                </div>
            </div>

            <!-- SRFs -->
            <div class="mb-4">
                <h5>Senior Research Fellows (SRFs)</h5>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="fw-bold">Number of SRFs</label>
                        <input type="number" class="form-control" name="srfs_count" id="srfs_count" min="0" value="<?php echo $editRow ? (int)$editRow['srfs_count'] : '0';?>" onchange="generateSrfFields()" onkeyup="generateSrfFields()" <?php echo $form_locked ? 'disabled' : ''; ?>>
                    </div>
                </div>
                <div id="srf_fields_container">
                    <!-- Dynamic SRF fields will be generated here -->
                </div>
            </div>
            
            <!-- Post Doctoral Fellows -->
            <div class="mb-4">
                <h5>Post Doctoral Fellows</h5>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="fw-bold">Number of Post Doctoral Fellows</label>
                        <input type="number" class="form-control" name="post_doctoral_count" id="post_doctoral_count" min="0" value="<?php echo $editRow ? (int)$editRow['post_doctoral_count'] : '0';?>" onchange="generatePostDocFields()" onkeyup="generatePostDocFields()" <?php echo $form_locked ? 'disabled' : ''; ?>>
                    </div>
                </div>
                <div id="post_doctoral_fields_container">
                    <!-- Dynamic Post Doctoral fields will be generated here -->
                </div>
            </div>

            <!-- Research Associates -->
            <div class="mb-4">
                <h5>Research Associates</h5>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="fw-bold">Number of Research Associates</label>
                        <input type="number" class="form-control" name="research_associates_count" id="research_associates_count" min="0" value="<?php echo $editRow ? (int)$editRow['research_associates_count'] : '0';?>" onchange="generateResearchAssociateFields()" onkeyup="generateResearchAssociateFields()" <?php echo $form_locked ? 'disabled' : ''; ?>>
                    </div>
                </div>
                <div id="research_associates_fields_container">
                    <!-- Dynamic Research Associates fields will be generated here -->
                </div>
            </div>

            <!-- Other Research Fellows -->
            <div class="mb-4">
                <h5>Other Research Fellows</h5>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="fw-bold">Number of Other Research Fellows</label>
                        <input type="number" class="form-control" name="other_research_count" id="other_research_count" min="0" value="<?php echo $editRow ? (int)$editRow['other_research_count'] : '0';?>" onchange="generateOtherResearchFields()" onkeyup="generateOtherResearchFields()" <?php echo $form_locked ? 'disabled' : ''; ?>>
                    </div>
                </div>
                <div id="other_research_fields_container">
                    <!-- Dynamic Other Research fields will be generated here -->
                </div>
            </div>

            <!-- PDF Upload Section for Research Fellows -->
            <div class="form-group mt-4 pdf-upload-section">
                <label class="form-label">Supporting Documents for Research Fellows <span class="text-danger">*</span></label>
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label">Research Fellows Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="research_fellows_pdf" name="research_fellows_pdf" accept=".pdf" 
                                   <?php echo $form_locked ? 'disabled' : ''; ?> onchange="validatePDF(this)">
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('research_fellows_pdf', 2)" 
                                    <?php echo $form_locked ? 'disabled' : ''; ?>>Upload</button>
                        </div>
                        <div id="research_fellows_pdf_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Students Research Activity Section -->
        <div class="card mb-4 p-3">
            <h4 class="mb-3">Students Research Activity</h4>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="fw-bold">Students Research Activity</label>
                    <select class="form-control" name="research_activity_type" id="research_activity_type">
                        <option value="">Select Activity Type</option>
                        <option value="research_publications" <?php echo ($editRow && $editRow['research_activity_type'] == 'research_publications') ? 'selected' : ''; ?>>Research Publications/Award at State Level Avishkar /Anveshan Award / National Conference Presentation Award</option>
                        <option value="any_other" <?php echo ($editRow && $editRow['research_activity_type'] == 'any_other') ? 'selected' : ''; ?>>Any other</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="fw-bold">No. of Listed Students Research Activities</label>
                    <input type="number" class="form-control" name="research_activities_count" id="research_activities_count" min="0" value="<?php echo $editRow ? (int)$editRow['research_activities_count'] : '0';?>" onchange="generateResearchActivityFields()" onkeyup="generateResearchActivityFields()" <?php echo $form_locked ? 'disabled' : ''; ?>>
                </div>
            </div>
            <div id="research_activity_other_container" class="mb-3" style="display:none;">
                <label class="fw-bold">Please specify other activity</label>
                <input type="text" class="form-control" name="research_activity_other" placeholder="Enter other research activity" value="<?php echo $editRow ? htmlspecialchars($editRow['research_activity_other']) : '';?>">
            </div>
            <div id="research_activity_fields_container">
                <!-- Dynamic Research Activity fields will be generated here -->
            </div>
            
            <!-- PDF Upload Section for Research Activity -->
            <div class="form-group mt-4 pdf-upload-section">
                <label class="form-label">Supporting Documents for Research Activity <span class="text-danger">*</span></label>
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label">Research Activity Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="research_activity_pdf" name="research_activity_pdf" accept=".pdf" 
                                   <?php echo $form_locked ? 'disabled' : ''; ?> onchange="validatePDF(this)">
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('research_activity_pdf', 13)" 
                                    <?php echo $form_locked ? 'disabled' : ''; ?>>Upload</button>
                        </div>
                        <div id="research_activity_pdf_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Awards & Achievements Section -->
        <div class="card mb-4 p-3">
            <h4 class="mb-3">Awards & Achievements</h4>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="fw-bold">Number of Awards/Medals in sports and culture</label>
                    <input type="number" class="form-control" name="awards_count" id="awards_count" min="0" value="<?php echo $editRow ? (int)$editRow['awards_count'] : '0';?>" onchange="generateAwardFields()" onkeyup="generateAwardFields()" <?php echo $form_locked ? 'disabled' : ''; ?>>
                </div>
            </div>
            <div id="award_fields_container">
                <!-- Dynamic Award fields will be generated here -->
            </div>
            
            <!-- PDF Upload Section for Awards & Achievements -->
            <div class="form-group mt-4 pdf-upload-section">
                <label class="form-label">Supporting Documents for Awards & Achievements <span class="text-danger">*</span></label>
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label">Sports Activities Awards</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="awards_sports_pdf" name="awards_sports_pdf" accept=".pdf" 
                                   <?php echo $form_locked ? 'disabled' : ''; ?> onchange="validatePDF(this)">
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('awards_sports_pdf', 14)" 
                                    <?php echo $form_locked ? 'disabled' : ''; ?>>Upload</button>
                        </div>
                        <div id="awards_sports_pdf_status" class="mt-2"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cultural Activities Awards</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="awards_cultural_pdf" name="awards_cultural_pdf" accept=".pdf" 
                                   <?php echo $form_locked ? 'disabled' : ''; ?> onchange="validatePDF(this)">
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('awards_cultural_pdf', 15)" 
                                    <?php echo $form_locked ? 'disabled' : ''; ?>>Upload</button>
                        </div>
                        <div id="awards_cultural_pdf_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Support Initiatives Section -->
        <div class="card mb-4 p-3">
            <h4 class="mb-3">Various Support Initiatives for Enrichment of Campus Life and Academic Growth of Students</h4>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="fw-bold">Total No of Listed Support Initiatives for Enrichment of Campus Life and Academic Growth of Students</label>
                    <input type="number" class="form-control" name="support_initiatives_count" id="support_initiatives_count" min="0" value="<?php echo $editRow ? (int)$editRow['support_initiatives_count'] : '0';?>" onchange="generateSupportInitiativeFields()" onkeyup="generateSupportInitiativeFields()" <?php echo $form_locked ? 'disabled' : ''; ?>>
                </div>
            </div>
            <div id="support_initiative_fields_container">
                <!-- Dynamic Support Initiative fields will be generated here -->
            </div>
            
            <!-- PDF Upload Section for Support Initiatives -->
            <div class="form-group mt-4 pdf-upload-section">
                <label class="form-label">Supporting Documents for Support Initiatives <span class="text-danger">*</span></label>
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label">Support Initiatives Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="support_initiatives_pdf" name="support_initiatives_pdf" accept=".pdf" 
                                   <?php echo $form_locked ? 'disabled' : ''; ?> onchange="validatePDF(this)">
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('support_initiatives_pdf', 7)" 
                                    <?php echo $form_locked ? 'disabled' : ''; ?>>Upload</button>
                        </div>
                        <div id="support_initiatives_pdf_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Internship/OJT Section -->
        <div class="card mb-4 p-3">
            <h4 class="mb-3">Number of Internship/ OJT of students in the last year</h4>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="fw-bold">Number of Programs with Internship/OJT</label>
                    <input type="number" class="form-control" name="internship_count" id="internship_count" min="0" value="<?php echo $editRow ? (int)$editRow['internship_count'] : '0';?>" onchange="generateInternshipFields()" onkeyup="generateInternshipFields()" <?php echo $form_locked ? 'disabled' : ''; ?>>
                </div>
            </div>
            <div id="internship_fields_container">
                <!-- Dynamic Internship fields will be generated here -->
            </div>
            
            <!-- PDF Upload Section for Internship/OJT -->
            <div class="form-group mt-4 pdf-upload-section">
                <label class="form-label">Supporting Documents for Internship/OJT <span class="text-danger">*</span></label>
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label">Internship/OJT Documentation</label>
                        <div class="input-group">
                            <input type="file" class="form-control" id="internship_pdf" name="internship_pdf" accept=".pdf" 
                                   <?php echo $form_locked ? 'disabled' : ''; ?> onchange="validatePDF(this)">
                            <button type="button" class="btn btn-outline-primary" onclick="uploadDocument('internship_pdf', 8)" 
                                    <?php echo $form_locked ? 'disabled' : ''; ?>>Upload</button>
                        </div>
                        <div id="internship_pdf_status" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </div>


        <div class="d-flex justify-content-center flex-wrap gap-3 mt-4">
            <?php if ($form_locked): ?>
                <button type="button" class="btn btn-warning px-4" id="updateBtn" onclick="enableUpdate()">
                    <i class="fas fa-edit me-2"></i>Update
                    </button>
                <button type="submit" name="submit" class="btn btn-success px-4" id="saveBtn" style="display:none;">
                    <i class="fas fa-save me-2"></i>Save Changes
                </button>
                <button type="button" class="btn btn-secondary px-4" id="cancelBtn" style="display:none;" onclick="cancelUpdate()">
                    <i class="fas fa-times me-2"></i>Cancel Update
                </button>
                <button type="button" class="btn btn-danger px-4" id="clearBtn" onclick="confirmClearData()">
                    <i class="fas fa-trash me-2"></i>Clear Data
                </button>
            <?php else: ?>
                <button type="submit" name="submit" class="btn btn-primary px-4" id="submitBtn">
                    <i class="fas fa-paper-plane me-2"></i>Submit Details
                </button>
                <?php endif; ?>
        </div>
                </form>
            </div>
        </div>
    </div>
</div>



<!-- Data is now displayed in the form fields above instead of a separate table -->

<script>
const isFormLocked = <?php echo $form_locked ? 'true' : 'false'; ?>;
let formUnlocked = false;

function lockDynamicFields(container) {
    if (!container || !isFormLocked || formUnlocked) {
        return;
    }
    container.querySelectorAll('input, textarea, select').forEach(element => {
        if (element.type !== 'hidden') {
            element.disabled = true;
            element.setAttribute('readonly', 'readonly');
        }
    });
}

function setFormDisabled(disabled) {
    const fieldSelectors = '#mainForm input:not([type="hidden"]), #mainForm textarea, #mainForm select';
    document.querySelectorAll(fieldSelectors).forEach(element => {
        if (element.dataset.lockIgnore === 'true') {
            return;
        }
        if (disabled) {
            element.disabled = true;
            if (element.type !== 'checkbox' && element.type !== 'radio' && element.tagName !== 'SELECT') {
                element.setAttribute('readonly', 'readonly');
            }
        } else {
            element.disabled = false;
            element.removeAttribute('readonly');
        }
    });
    document.querySelectorAll('.pdf-upload-section button, .pdf-upload-section input[type="file"]').forEach(element => {
        element.disabled = disabled;
    });
}

function applyLockState() {
    const disabled = isFormLocked && !formUnlocked;
    setFormDisabled(disabled);

    const submitBtn = document.getElementById('submitBtn');
    const updateBtn = document.getElementById('updateBtn');
    const saveBtn = document.getElementById('saveBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const clearBtn = document.getElementById('clearBtn');

    if (isFormLocked) {
        if (submitBtn) {
            submitBtn.style.display = 'none';
        }
        if (updateBtn) {
            updateBtn.style.display = disabled ? 'inline-block' : 'none';
            updateBtn.disabled = false;
        }
        if (saveBtn) {
            saveBtn.style.display = disabled ? 'none' : 'inline-block';
        }
        if (cancelBtn) {
            // Show cancel button when form is unlocked (update mode)
            cancelBtn.style.display = disabled ? 'none' : 'inline-block';
            cancelBtn.disabled = false;
        }
        if (clearBtn) {
            // Hide clear button when in update mode, show when locked
            clearBtn.style.display = disabled ? 'inline-block' : 'none';
            clearBtn.disabled = false;
        }
    } else {
        if (submitBtn) {
            submitBtn.style.display = 'inline-block';
        }
        if (updateBtn) {
            updateBtn.style.display = 'none';
        }
        if (saveBtn) {
            saveBtn.style.display = 'none';
        }
        if (cancelBtn) {
            cancelBtn.style.display = 'none';
        }
        if (clearBtn) {
            clearBtn.style.display = 'none';
        }
    }
}

function enableUpdate() {
    formUnlocked = true;
    setFormDisabled(false);
    applyLockState();
    refreshAllDocumentStatuses();
    alert('Form unlocked. Make your changes and click "Save Changes" to update the record, or "Cancel Update" to discard changes.');
}

function cancelUpdate() {
    // CRITICAL: Security - Confirm cancellation to prevent accidental data loss
    if (confirm('Are you sure you want to cancel the update? All unsaved changes will be discarded.')) {
        // Reload page to restore original locked state with original data from database
        // This ensures data integrity - no client-side manipulation, fresh data from server
        window.location.href = 'StudentSupport.php';
    }
}

function confirmClearData() {
    if (confirm('Are you sure you want to clear all data? This action cannot be undone.')) {
        window.location.href = 'StudentSupport.php?action=clear';
    }
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
    if (!container || !values) {
        return;
    }
    container.querySelectorAll('input, textarea, select').forEach(element => {
        if (element.name && Object.prototype.hasOwnProperty.call(values, element.name)) {
            element.value = values[element.name];
        }
    });
}
// Generate dynamic JRF fields
function generateJrfFields() {
    const jrfsCount = document.getElementById('jrfs_count').value;
    const container = document.getElementById('jrf_fields_container');
    if (!container) return;
    const previousValues = captureFieldValues(container);
    
    // Clear existing fields
    container.innerHTML = '';
    
    if (jrfsCount > 0) {
        for (let i = 1; i <= jrfsCount; i++) {
            const jrfCard = document.createElement('div');
            jrfCard.className = 'card mb-3 p-3';
            jrfCard.innerHTML = `
                <h6 class="mb-3">JRF ${i}</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="fw-bold">Name of JRF</label>
                        <input type="text" class="form-control" name="jrf_name_${i}" placeholder="Enter JRF name">
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">Fellowship Name</label>
                        <input type="text" class="form-control" name="jrf_fellowship_${i}" placeholder="Enter fellowship name">
                    </div>
                </div>
            `;
            container.appendChild(jrfCard);
        }
    }
    restoreFieldValues(container, previousValues);
    lockDynamicFields(container);
}

// Generate dynamic SRF fields
function generateSrfFields() {
    const srfsCount = document.getElementById('srfs_count').value;
    const container = document.getElementById('srf_fields_container');
    if (!container) return;
    const previousValues = captureFieldValues(container);
    
    // Clear existing fields
    container.innerHTML = '';
    
    if (srfsCount > 0) {
        for (let i = 1; i <= srfsCount; i++) {
            const srfCard = document.createElement('div');
            srfCard.className = 'card mb-3 p-3';
            srfCard.innerHTML = `
                <h6 class="mb-3">SRF ${i}</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="fw-bold">Name of SRF</label>
                        <input type="text" class="form-control" name="srf_name_${i}" placeholder="Enter SRF name">
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">Fellowship Name</label>
                        <input type="text" class="form-control" name="srf_fellowship_${i}" placeholder="Enter fellowship name">
                    </div>
                </div>
            `;
            container.appendChild(srfCard);
        }
    }
    restoreFieldValues(container, previousValues);
    lockDynamicFields(container);
}

// Generate dynamic Post Doctoral fields
function generatePostDocFields() {
    const postDocCount = document.getElementById('post_doctoral_count').value;
    const container = document.getElementById('post_doctoral_fields_container');
    if (!container) return;
    const previousValues = captureFieldValues(container);
    
    // Clear existing fields
    container.innerHTML = '';
    
    if (postDocCount > 0) {
        for (let i = 1; i <= postDocCount; i++) {
            const postDocCard = document.createElement('div');
            postDocCard.className = 'card mb-3 p-3';
            postDocCard.innerHTML = `
                <h6 class="mb-3">Post Doctoral Fellow ${i}</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="fw-bold">Name of Post Doctoral Fellow</label>
                        <input type="text" class="form-control" name="post_doctoral_name_${i}" placeholder="Enter fellow name">
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">Fellowship Name</label>
                        <input type="text" class="form-control" name="post_doctoral_fellowship_${i}" placeholder="Enter fellowship name">
                    </div>
                </div>
            `;
            container.appendChild(postDocCard);
        }
    }
    restoreFieldValues(container, previousValues);
    lockDynamicFields(container);
}

// Generate dynamic Research Associate fields
function generateResearchAssociateFields() {
    const researchAssocCount = document.getElementById('research_associates_count').value;
    const container = document.getElementById('research_associates_fields_container');
    if (!container) return;
    const previousValues = captureFieldValues(container);
    
    // Clear existing fields
    container.innerHTML = '';
    
    if (researchAssocCount > 0) {
        for (let i = 1; i <= researchAssocCount; i++) {
            const researchAssocCard = document.createElement('div');
            researchAssocCard.className = 'card mb-3 p-3';
            researchAssocCard.innerHTML = `
                <h6 class="mb-3">Research Associate ${i}</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="fw-bold">Name of Research Associate</label>
                        <input type="text" class="form-control" name="research_associate_name_${i}" placeholder="Enter associate name">
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">Fellowship Name</label>
                        <input type="text" class="form-control" name="research_associate_fellowship_${i}" placeholder="Enter fellowship name">
                    </div>
                </div>
            `;
            container.appendChild(researchAssocCard);
        }
    }
    restoreFieldValues(container, previousValues);
    lockDynamicFields(container);
}

// Generate dynamic Other Research fields
function generateOtherResearchFields() {
    const otherResearchCount = document.getElementById('other_research_count').value;
    const container = document.getElementById('other_research_fields_container');
    if (!container) return;
    const previousValues = captureFieldValues(container);
    
    // Clear existing fields
    container.innerHTML = '';
    
    if (otherResearchCount > 0) {
        for (let i = 1; i <= otherResearchCount; i++) {
            const otherResearchCard = document.createElement('div');
            otherResearchCard.className = 'card mb-3 p-3';
            otherResearchCard.innerHTML = `
                <h6 class="mb-3">Other Research Fellow ${i}</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="fw-bold">Name of Other Research Fellow</label>
                        <input type="text" class="form-control" name="other_research_name_${i}" placeholder="Enter fellow name">
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">Fellowship Name</label>
                        <input type="text" class="form-control" name="other_research_fellowship_${i}" placeholder="Enter fellowship name">
                    </div>
                </div>
            `;
            container.appendChild(otherResearchCard);
        }
    }
    restoreFieldValues(container, previousValues);
    lockDynamicFields(container);
}

// Generate dynamic Research Activity fields
function generateResearchActivityFields() {
    const activitiesCount = document.getElementById('research_activities_count').value;
    const container = document.getElementById('research_activity_fields_container');
    if (!container) return;
    const previousValues = captureFieldValues(container);
    
    // Clear existing fields
    container.innerHTML = '';
    
    if (activitiesCount > 0) {
        for (let i = 1; i <= activitiesCount; i++) {
            const activityCard = document.createElement('div');
            activityCard.className = 'card mb-3 p-3';
            activityCard.innerHTML = `
                <h6 class="mb-3">Student Research Activity ${i}</h6>
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="fw-bold">Name of the student</label>
                        <input type="text" class="form-control" name="research_student_name_${i}" placeholder="Enter student name">
                    </div>
                </div>
            `;
            container.appendChild(activityCard);
        }
    }
    restoreFieldValues(container, previousValues);
    lockDynamicFields(container);
}

// Generate dynamic Award fields
function generateAwardFields() {
    const awardsCount = document.getElementById('awards_count').value;
    const container = document.getElementById('award_fields_container');
    if (!container) return;
    const previousValues = captureFieldValues(container);
    
    // Clear existing fields
    container.innerHTML = '';
    
    if (awardsCount > 0) {
        for (let i = 1; i <= awardsCount; i++) {
            const awardCard = document.createElement('div');
            awardCard.className = 'card mb-3 p-3';
            awardCard.innerHTML = `
                <h6 class="mb-3">Award/Medal ${i}</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="fw-bold">Category</label>
                        <select class="form-control" name="award_category_${i}">
                            <option value="">Select Category</option>
                            <option value="sports">Sports</option>
                            <option value="cultural">Cultural</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">Level</label>
                        <select class="form-control" name="award_level_${i}">
                            <option value="">Select Level</option>
                            <option value="state">State</option>
                            <option value="national">National</option>
                            <option value="international">International</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">Awardee Name</label>
                        <input type="text" class="form-control" name="awardee_name_${i}" placeholder="Enter awardee name">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">Award Name</label>
                        <input type="text" class="form-control" name="award_name_${i}" placeholder="Enter award name">
                    </div>
                </div>
            `;
            container.appendChild(awardCard);
        }
    }
    restoreFieldValues(container, previousValues);
    lockDynamicFields(container);
}

// Generate dynamic Support Initiative fields
function generateSupportInitiativeFields() {
    const initiativesCount = document.getElementById('support_initiatives_count').value;
    const container = document.getElementById('support_initiative_fields_container');
    if (!container) return;
    const previousValues = captureFieldValues(container);
    
    // Clear existing fields
    container.innerHTML = '';
    
    if (initiativesCount > 0) {
        for (let i = 1; i <= initiativesCount; i++) {
            const initiativeCard = document.createElement('div');
            initiativeCard.className = 'card mb-3 p-3';
            initiativeCard.innerHTML = `
                <h6 class="mb-3">Support Initiative ${i}</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="fw-bold">Initiative Name</label>
                        <input type="text" class="form-control" name="support_initiative_name_${i}" placeholder="Enter initiative name">
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold">Description</label>
                        <textarea class="form-control" name="support_initiative_description_${i}" placeholder="Enter initiative description" rows="3"></textarea>
                    </div>
                </div>
            `;
            container.appendChild(initiativeCard);
        }
    }
    restoreFieldValues(container, previousValues);
    lockDynamicFields(container);
}

// Generate dynamic Internship fields
function generateInternshipFields() {
    const internshipCount = document.getElementById('internship_count').value;
    const container = document.getElementById('internship_fields_container');
    if (!container) return;
    const previousValues = captureFieldValues(container);
    
    // Clear existing fields
    container.innerHTML = '';
    
    if (internshipCount > 0) {
        for (let i = 1; i <= internshipCount; i++) {
            const internshipCard = document.createElement('div');
            internshipCard.className = 'card mb-3 p-3';
            internshipCard.innerHTML = `
                <h6 class="mb-3">Program ${i} - Internship/OJT Distribution</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="fw-bold">Program Name</label>
                        <input type="text" class="form-control" name="internship_program_name_${i}" placeholder="Enter program name">
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold">Male Students</label>
                        <input type="number" class="form-control" name="internship_male_${i}" min="0" placeholder="Enter male count">
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold">Female Students</label>
                        <input type="number" class="form-control" name="internship_female_${i}" min="0" placeholder="Enter female count">
                    </div>
                </div>
            `;
            container.appendChild(internshipCard);
        }
    }
    restoreFieldValues(container, previousValues);
    lockDynamicFields(container);
}


// CRITICAL: Track if page has already initialized to prevent duplicate initialization
let pageInitialized = false;

// Handle research activity type dropdown
document.addEventListener('DOMContentLoaded', function() {
    // CRITICAL: Only initialize if not already initialized
    if (pageInitialized) {
        return;
    }
    pageInitialized = true;
    
    const researchActivityType = document.getElementById('research_activity_type');
    const otherContainer = document.getElementById('research_activity_other_container');
    
    if (researchActivityType && otherContainer) {
        researchActivityType.addEventListener('change', function() {
            if (this.value === 'any_other') {
                otherContainer.style.display = 'block';
            } else {
                otherContainer.style.display = 'none';
            }
        });
        
        // Check if "any other" is already selected on page load
        if (researchActivityType.value === 'any_other') {
            otherContainer.style.display = 'block';
        }
    }
    
    // Load existing documents on page load with delay to prevent initial request burst
    setTimeout(function() {
        loadExistingDocuments();
        applyLockState();
    }, 300); // Delay to ensure page is fully loaded
    
    // Populate form fields when editing or when data exists (consolidated into main listener)
    <?php if ($editRow): ?>
    // Generate all fields first - this will create the field containers
    generateJrfFields();
    generateSrfFields();
    generatePostDocFields();
    generateResearchAssociateFields();
    generateOtherResearchFields();
    generateResearchActivityFields();
    generateAwardFields();
    generateSupportInitiativeFields();
    generateInternshipFields();
    
    // Use setTimeout to ensure fields are created before populating
    setTimeout(function() {
        populateFormFields();
    }, 100);
    <?php endif; ?>
});

// Also use window.onload as fallback (but only if DOMContentLoaded hasn't fired)
window.onload = function() {
    // CRITICAL: Only initialize if not already initialized
    if (pageInitialized) {
        return;
    }
    pageInitialized = true;
    
    // Load existing documents with delay to prevent initial request burst
    setTimeout(function() {
        loadExistingDocuments();
        applyLockState();
        <?php if ($editRow): ?>
        // Ensure form fields are populated if DOMContentLoaded didn't fire
        populateFormFields();
        <?php endif; ?>
    }, 400); // Increased delay to ensure page is fully loaded
};

// Function to populate all form fields from existing data
function populateFormFields() {
    <?php if ($editRow): ?>
    // Populate JRF data (handle up to 30+ fields)
    <?php 
    $jrfs_data = json_decode($editRow['jrfs_data'] ?? '[]', true);
    if ($jrfs_data && is_array($jrfs_data)) {
        foreach ($jrfs_data as $index => $jrf) {
            $i = $index + 1;
            $name = htmlspecialchars($jrf['name'] ?? '', ENT_QUOTES, 'UTF-8');
            $fellowship = htmlspecialchars($jrf['fellowship'] ?? '', ENT_QUOTES, 'UTF-8');
            echo "setTimeout(function() {";
            echo "    const nameField = document.querySelector('input[name=\"jrf_name_$i\"]');";
            echo "    const fellowshipField = document.querySelector('input[name=\"jrf_fellowship_$i\"]');";
            echo "    if (nameField) nameField.value = " . json_encode($name, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ";";
            echo "    if (fellowshipField) fellowshipField.value = " . json_encode($fellowship, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ";";
            echo "}, " . ($i * 10) . ");"; // Stagger updates to prevent UI blocking
        }
    }
    ?>
    
    // Populate SRF data
    <?php 
    $srfs_data = json_decode($editRow['srfs_data'], true);
    if ($srfs_data && is_array($srfs_data)) {
        foreach ($srfs_data as $index => $srf) {
            $i = $index + 1;
            echo "if (document.querySelector('input[name=\"srf_name_$i\"]')) {";
            echo "    document.querySelector('input[name=\"srf_name_$i\"]').value = " . json_encode($srf['name'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
            echo "if (document.querySelector('input[name=\"srf_fellowship_$i\"]')) {";
            echo "    document.querySelector('input[name=\"srf_fellowship_$i\"]').value = " . json_encode($srf['fellowship'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
        }
    }
    ?>
    
    // Populate Post Doctoral data
    <?php 
    $post_doctoral_data = json_decode($editRow['post_doctoral_data'], true);
    if ($post_doctoral_data && is_array($post_doctoral_data)) {
        foreach ($post_doctoral_data as $index => $postDoc) {
            $i = $index + 1;
            echo "if (document.querySelector('input[name=\"post_doctoral_name_$i\"]')) {";
            echo "    document.querySelector('input[name=\"post_doctoral_name_$i\"]').value = " . json_encode($postDoc['name'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
            echo "if (document.querySelector('input[name=\"post_doctoral_fellowship_$i\"]')) {";
            echo "    document.querySelector('input[name=\"post_doctoral_fellowship_$i\"]').value = " . json_encode($postDoc['fellowship'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
        }
    }
    ?>
    
    // Populate Research Associates data
    <?php 
    $research_associates_data = json_decode($editRow['research_associates_data'], true);
    if ($research_associates_data && is_array($research_associates_data)) {
        foreach ($research_associates_data as $index => $researchAssoc) {
            $i = $index + 1;
            echo "if (document.querySelector('input[name=\"research_associate_name_$i\"]')) {";
            echo "    document.querySelector('input[name=\"research_associate_name_$i\"]').value = " . json_encode($researchAssoc['name'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
            echo "if (document.querySelector('input[name=\"research_associate_fellowship_$i\"]')) {";
            echo "    document.querySelector('input[name=\"research_associate_fellowship_$i\"]').value = " . json_encode($researchAssoc['fellowship'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
        }
    }
    ?>
    
    // Populate Other Research data
    <?php 
    $other_research_data = json_decode($editRow['other_research_data'], true);
    if ($other_research_data && is_array($other_research_data)) {
        foreach ($other_research_data as $index => $otherResearch) {
            $i = $index + 1;
            echo "if (document.querySelector('input[name=\"other_research_name_$i\"]')) {";
            echo "    document.querySelector('input[name=\"other_research_name_$i\"]').value = " . json_encode($otherResearch['name'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
            echo "if (document.querySelector('input[name=\"other_research_fellowship_$i\"]')) {";
            echo "    document.querySelector('input[name=\"other_research_fellowship_$i\"]').value = " . json_encode($otherResearch['fellowship'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
        }
    }
    ?>
    
    // Populate Research Activity data
    <?php 
    $research_activities_data = json_decode($editRow['research_activities_data'], true);
    if ($research_activities_data && is_array($research_activities_data)) {
        foreach ($research_activities_data as $index => $activity) {
            $i = $index + 1;
            echo "if (document.querySelector('input[name=\"research_student_name_$i\"]')) {";
            echo "    document.querySelector('input[name=\"research_student_name_$i\"]').value = " . json_encode($activity['student_name'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
        }
    }
    ?>
    
    // Populate Awards data
    <?php 
    $awards_data = json_decode($editRow['awards_data'], true);
    if ($awards_data && is_array($awards_data)) {
        foreach ($awards_data as $index => $award) {
            $i = $index + 1;
            echo "if (document.querySelector('select[name=\"award_category_$i\"]')) {";
            echo "    document.querySelector('select[name=\"award_category_$i\"]').value = " . json_encode($award['category'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
            echo "if (document.querySelector('select[name=\"award_level_$i\"]')) {";
            echo "    document.querySelector('select[name=\"award_level_$i\"]').value = " . json_encode($award['level'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
            echo "if (document.querySelector('input[name=\"awardee_name_$i\"]')) {";
            echo "    document.querySelector('input[name=\"awardee_name_$i\"]').value = " . json_encode($award['awardee_name'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
            echo "if (document.querySelector('input[name=\"award_name_$i\"]')) {";
            echo "    document.querySelector('input[name=\"award_name_$i\"]').value = " . json_encode($award['award_name'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
        }
    }
    ?>
    
    // Populate Support Initiatives data
    <?php 
    $support_initiatives_data = json_decode($editRow['support_initiatives_data'], true);
    if ($support_initiatives_data && is_array($support_initiatives_data)) {
        foreach ($support_initiatives_data as $index => $initiative) {
            $i = $index + 1;
            echo "if (document.querySelector('input[name=\"support_initiative_name_$i\"]')) {";
            echo "    document.querySelector('input[name=\"support_initiative_name_$i\"]').value = " . json_encode($initiative['initiative_name'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
            echo "if (document.querySelector('textarea[name=\"support_initiative_description_$i\"]')) {";
            echo "    document.querySelector('textarea[name=\"support_initiative_description_$i\"]').value = " . json_encode($initiative['description'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
        }
    }
    ?>
    
    // Populate Internship data
    <?php 
    $internship_data = json_decode($editRow['internship_data'], true);
    if ($internship_data && is_array($internship_data)) {
        foreach ($internship_data as $index => $internship) {
            $i = $index + 1;
            echo "if (document.querySelector('input[name=\"internship_program_name_$i\"]')) {";
            echo "    document.querySelector('input[name=\"internship_program_name_$i\"]').value = " . json_encode($internship['program_name'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
            echo "if (document.querySelector('input[name=\"internship_male_$i\"]')) {";
            echo "    document.querySelector('input[name=\"internship_male_$i\"]').value = " . json_encode($internship['male_count'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
            echo "if (document.querySelector('input[name=\"internship_female_$i\"]')) {";
            echo "    document.querySelector('input[name=\"internship_female_$i\"]').value = " . json_encode($internship['female_count'] ?? '', JSON_UNESCAPED_UNICODE) . ";";
            echo "}";
        }
    }
    ?>
    
    applyLockState();
    <?php endif; ?>
}

// Handle Enter key press to prevent form submission
function handleKeyDown(event) {
    // If Enter key is pressed and the target is a number input field
    if (event.key === 'Enter' && event.target.type === 'number') {
        // Prevent form submission
        event.preventDefault();
        // Trigger the field generation function based on the input ID
        const inputId = event.target.id;
        switch(inputId) {
            case 'jrfs_count':
                generateJrfFields();
                break;
            case 'srfs_count':
                generateSrfFields();
                break;
            case 'post_doctoral_count':
                generatePostDocFields();
                break;
            case 'research_associates_count':
                generateResearchAssociateFields();
                break;
            case 'other_research_count':
                generateOtherResearchFields();
                break;
            case 'research_activities_count':
                generateResearchActivityFields();
                break;
            case 'awards_count':
                generateAwardFields();
                break;
            case 'support_initiatives_count':
                generateSupportInitiativeFields();
                break;
            case 'internship_count':
                generateInternshipFields();
                break;
        }
        return false;
    }
    return true;
}

function Validate() {
    // Basic validation - allow all submissions for now
    return true;
}

// PDF validation function
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

// Upload document function
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
            statusDiv.innerHTML = '<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Upload failed: Invalid server response</span>';
            statusDiv.className = 'mt-2 text-danger';
            return;
        }
        
        if (data.success) {
            const allowDelete = !isFormLocked || formUnlocked;
            // Ensure correct path format for view link
            // Handle paths: uploads/YEAR/DEPARTMENT/department_ID/Student_support/FILENAME.pdf
            let viewPath = data.file_path || '';
            // Convert absolute paths to relative web paths
            if (viewPath && (viewPath.startsWith('/home/') || viewPath.startsWith('C:/') || viewPath.startsWith('C:\\'))) {
                // Extract relative path from absolute path (case-insensitive match for directory names)
                const match = viewPath.match(/(uploads\/[\d\-]+\/DEPARTMENT\/\d+\/.+\.pdf)$/i);
                if (match) {
                    viewPath = '../' + match[1];
                }
            } else if (viewPath && !viewPath.startsWith('../') && !viewPath.startsWith('http')) {
                // Relative path - add ../ if it starts with uploads/
                if (viewPath.startsWith('uploads/')) {
                    viewPath = '../' + viewPath;
                }
            }
            
            // CRITICAL: Escape fileId and viewPath to prevent JavaScript syntax errors
            const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            const escapedViewPath = viewPath ? String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
            statusDiv.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <span class="text-success">Document uploaded successfully</span>
                    ${allowDelete ? `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno})">
                        <i class="fas fa-trash"></i> Delete
                    </button>` : ''}
                    ${escapedViewPath ? `<a href="${escapedViewPath}" target="_blank" class="btn btn-sm btn-outline-primary ms-1">
                        <i class="fas fa-eye"></i> View
                    </a>` : ''}
                </div>
            `;
            statusDiv.className = 'mt-2 text-success';
            
            // Clear the file input
            fileInput.value = '';
        } else {
            statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">${data.message}</span>`;
            statusDiv.className = 'mt-2 text-danger';
        }
    })
    .catch(error => {
        // CRITICAL #5: Handle errors gracefully - return object, don't throw
        statusDiv.innerHTML = `<i class="fas fa-exclamation-circle text-danger me-2"></i><span class="text-danger">Upload failed: ${error.message || 'Unknown error'}</span>`;
        statusDiv.className = 'mt-2 text-danger';
    });
}

// Delete document function
function deleteDocument(fileId, srno) {
    if (isFormLocked && !formUnlocked) {
        alert('The form is currently locked. Click "Update" to unlock the form before deleting documents.');
        return;
    }
    if (!confirm('Are you sure you want to delete this document?')) {
        return;
    }
    
    const statusDiv = document.getElementById(fileId + '_status');
    statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Deleting...';
    statusDiv.className = 'mt-2 text-info';
    
    fetch(`?delete_document=1`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `srno=${srno}`
    })
    .then(response => {
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
    });
}

// Track active check requests to prevent duplicate calls and unlimited database requests
const activeDocumentChecks = new Set();

// CRITICAL: Global request limiter to prevent max connection errors
let globalActiveRequests = 0;
const MAX_CONCURRENT_REQUESTS = 2; // Maximum 2 simultaneous requests globally (reduced from 3 to prevent connection exhaustion)
const requestQueue = [];
let queueProcessing = false; // Prevent multiple simultaneous queue processors

// Process queued requests when a slot becomes available
function processQueuedRequests() {
    // CRITICAL: Prevent multiple simultaneous queue processors
    if (queueProcessing) {
        return;
    }
    
    if (requestQueue.length === 0 || globalActiveRequests >= MAX_CONCURRENT_REQUESTS) {
        queueProcessing = false;
        return;
    }
    
    queueProcessing = true;
    const nextRequest = requestQueue.shift();
    globalActiveRequests++;
    
    // Execute the request
    nextRequest.fn()
        .then(nextRequest.resolve)
        .catch(nextRequest.reject)
        .finally(() => {
            globalActiveRequests--;
            queueProcessing = false;
            // Process next queued request after small delay
            if (requestQueue.length > 0 && globalActiveRequests < MAX_CONCURRENT_REQUESTS) {
                setTimeout(processQueuedRequests, 50);
            }
        });
}

// Check document status function with guards to prevent unlimited requests
function checkDocumentStatus(fileId, srno) {
    const statusDiv = document.getElementById(fileId + '_status');
    if (!statusDiv) {
        return Promise.resolve();
    }
    
    // CRITICAL: Prevent duplicate checks for the same document - prevent unlimited database requests
    const checkKey = `${fileId}_${srno}`;
    if (activeDocumentChecks.has(checkKey)) {
        return Promise.resolve(); // Already checking this document
    }
    
    // Add to active checks
    activeDocumentChecks.add(checkKey);
    
    // CRITICAL: Global rate limiting - if too many requests, queue this one
    if (globalActiveRequests >= MAX_CONCURRENT_REQUESTS) {
        // Queue the request instead of executing immediately
        return new Promise((resolve) => {
            requestQueue.push({
                fn: () => {
                    return executeDocumentCheck(fileId, srno, checkKey, statusDiv);
                },
                resolve: (data) => {
                    activeDocumentChecks.delete(checkKey);
                    processDocumentCheckResult(fileId, srno, data, statusDiv);
                    resolve(data);
                },
                reject: (error) => {
                    activeDocumentChecks.delete(checkKey);
                    const errorData = { success: false, message: error.message || 'Request failed' };
                    processDocumentCheckResult(fileId, srno, errorData, statusDiv);
                    resolve(errorData);
                }
            });
            // Process queue only if not already processing
            if (!queueProcessing) {
                setTimeout(processQueuedRequests, 50);
            }
        });
    }
    
    // Execute immediately if under limit
    return executeDocumentCheck(fileId, srno, checkKey, statusDiv);
}

// Separate function to execute the actual document check
function executeDocumentCheck(fileId, srno, checkKey, statusDiv) {
    globalActiveRequests++;
    
    // CRITICAL: Add timeout to prevent hanging requests (10 seconds max)
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
    
    return fetch(`?check_doc=1&srno=${srno}`, {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        },
        cache: 'no-cache',
        signal: controller.signal
    })
    .catch(error => {
        // Clear timeout on error
        clearTimeout(timeoutId);
        globalActiveRequests--;
        
        // Process queued requests only if not already processing
        if (!queueProcessing && requestQueue.length > 0) {
            setTimeout(processQueuedRequests, 50);
        }
        
        // CRITICAL: Remove from active checks on error - prevent loops
        activeDocumentChecks.delete(checkKey);
        
        // Handle abort (timeout) specifically
        if (error.name === 'AbortError') {
            console.warn('[StudentSupport] Document check timed out for:', fileId);
            return { success: false, message: 'Request timed out. Please refresh the page.' };
        }
        
        // Return error object instead of throwing
        return { 
            ok: false, 
            text: () => Promise.resolve(JSON.stringify({ 
                success: false, 
                message: 'Network error: ' + (error.message || 'Failed to connect to server') 
            })) 
        };
    })
    .then(response => {
        // Clear timeout on successful response
        clearTimeout(timeoutId);
        globalActiveRequests--;
        
        // Process queued requests only if not already processing
        if (!queueProcessing && requestQueue.length > 0) {
            setTimeout(processQueuedRequests, 50);
        }
        
        // CRITICAL: Ensure response is valid before processing
        if (!response || typeof response.text !== 'function') {
            activeDocumentChecks.delete(checkKey);
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
            clearTimeout(timeoutId);
            globalActiveRequests--;
            if (!queueProcessing && requestQueue.length > 0) {
                setTimeout(processQueuedRequests, 50);
            }
            return { success: false, message: 'Check failed: Error reading server response' };
        });
    })
    .catch(error => {
        // Clear timeout on error
        clearTimeout(timeoutId);
        globalActiveRequests--;
        
        // Process queued requests only if not already processing
        if (!queueProcessing && requestQueue.length > 0) {
            setTimeout(processQueuedRequests, 50);
        }
        
        // CRITICAL: Remove from active checks on ANY error - prevent loops
        activeDocumentChecks.delete(checkKey);
        
        // Handle abort (timeout) specifically
        if (error.name === 'AbortError') {
            return Promise.resolve({ success: false, message: 'Request timed out. Please refresh the page.' });
        }
        
        // Return resolved promise with error object - prevent unhandled promise rejection
        return Promise.resolve({ success: false, message: 'Network error: ' + (error.message || 'Unknown error') });
    })
    .then(data => {
        // Process result using shared function
        processDocumentCheckResult(fileId, srno, data, statusDiv);
        return data;
    });
}

// Process document check result and update UI
function processDocumentCheckResult(fileId, srno, data, statusDiv) {
    // CRITICAL: Handle null/undefined data gracefully
    if (!data || typeof data !== 'object') {
        statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
        statusDiv.className = 'mt-2 text-muted';
        return;
    }
    
    // Process the data
        if (data.success && data.file_path) {
        // Check form lock state dynamically
        var updateButton = document.querySelector('button[onclick="enableUpdate()"]');
        var isFormLocked = updateButton && updateButton.style.display !== 'none';
        // CRITICAL: Escape fileId to prevent JavaScript syntax errors
        const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
        var deleteButton = isFormLocked ? '' : `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno})">
                    <i class="fas fa-trash"></i> Delete
                </button>`;
        
        // Ensure file path is web-accessible
            let viewPath = data.file_path || '';
        if (viewPath && !viewPath.startsWith('../') && !viewPath.startsWith('http')) {
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
        const viewButton = escapedViewPath ? `<a href="${escapedViewPath}" target="_blank" class="btn btn-sm btn-outline-primary ms-1" rel="noopener noreferrer">
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
        } else {
            statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
            statusDiv.className = 'mt-2 text-muted';
        }
}

    // Track if documents are already loading to prevent duplicate calls
let documentsLoading = false;
let documentLoadTimeout = null;
// CRITICAL: Define maxTimeout constant for safety timeout (15 seconds - longer than fetch timeout)
const maxTimeout = 15000; // 15 seconds - safety timeout for document loading

// Load existing documents on page load with rate limiting to prevent unlimited database requests
function loadExistingDocuments() {
    // CRITICAL: Prevent duplicate calls - if already loading, return early
    if (documentsLoading) {
        console.log('[StudentSupport] Documents already loading, skipping duplicate call');
        return;
    }
    
    documentsLoading = true;
    
    // Clear any existing timeout
    if (documentLoadTimeout) {
        clearTimeout(documentLoadTimeout);
    }
    
    // CRITICAL FIX: Load ALL documents in ONE batch request instead of 6 individual requests
    // This reduces database load from 6 queries to just 1 query per page load
    const documentMap = {
        'research_fellows_pdf': 2,
        'support_initiatives_pdf': 7,
        'internship_pdf': 8,
        'research_activity_pdf': 13,
        'awards_sports_pdf': 14,
        'awards_cultural_pdf': 15
    };
    
    // CRITICAL: Use batch endpoint - ONE request instead of 6 individual requests
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
    
    fetch('?check_all_docs=1', {
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
            throw new Error('Network response was not ok');
        }
        
        return response.text().then(text => {
            const trimmed = text.trim();
            if (!trimmed) {
                throw new Error('Empty response from server');
            }
            return JSON.parse(trimmed);
        });
    })
    .then(data => {
        documentsLoading = false;
        
        if (data.success && data.documents) {
            // Update all document statuses from batch response
            Object.keys(documentMap).forEach(fileId => {
                const srno = documentMap[fileId];
                const statusDiv = document.getElementById(fileId + '_status');
                
                if (statusDiv) {
                    const docData = data.documents[srno];
                    if (docData && docData.success) {
                        processDocumentCheckResult(fileId, srno, docData, statusDiv);
                    } else {
                        statusDiv.innerHTML = '<span class="text-muted">No document uploaded</span>';
                        statusDiv.className = 'mt-2 text-muted';
                    }
                }
            });
        } else {
            // Fallback: If batch fails, show error but don't crash
            console.warn('[StudentSupport] Batch document check failed:', data.message || 'Unknown error');
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
        documentsLoading = false;
        
        // Handle errors gracefully
        console.warn('[StudentSupport] Error loading documents:', error);
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
    
    // Safety timeout - reset loading flag after max timeout even if something goes wrong
    setTimeout(() => {
        documentsLoading = false;
    }, maxTimeout);
    }

function refreshAllDocumentStatuses() {
    loadExistingDocuments();
    }
</script>
<?php
require "unified_footer.php";
?>