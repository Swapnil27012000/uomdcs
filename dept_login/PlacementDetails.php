<?php
// PlacementDetails - Placement details form

// ============================================================================
// CRITICAL: Handle skip_form_complete FIRST - BEFORE ANY INCLUDES OR OUTPUT
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'skip_form_complete') {
    // Clear output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load session for userInfo
    if (!isset($userInfo) && file_exists(__DIR__ . '/session.php')) {
        ob_start();
        require_once(__DIR__ . '/session.php');
        ob_end_clean();
    }
    
    // Ensure userInfo is available
    if (!isset($userInfo) && isset($_SESSION['userInfo'])) {
        $userInfo = $_SESSION['userInfo'];
    }
    
    // Handle skip action
    require_once(__DIR__ . '/skip_form_component.php');
    // skip_form_component.php will handle and exit
}

// ============================================================================
// CRITICAL: Handle document uploads FIRST - BEFORE ANY INCLUDES OR OUTPUT
// ============================================================================
if (isset($_POST['upload_document'])) {
    // Disable error display
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Clear output buffer FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Load config FIRST (minimal requirements for AJAX) - Check if connection exists first
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        require_once(__DIR__ . '/../config.php');
    }
    
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load session for user info
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    
    // Load CSRF utilities
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
        // Ensure CSRF token is generated in session before validation
        if (function_exists('csrf_token')) {
            csrf_token(); // Generate token if it doesn't exist
        }
    }
    
    // Set headers BEFORE any output
    header('Content-Type: application/json; charset=UTF-8');
    
    try {
        // Validate CSRF token
        if (function_exists('validate_csrf')) {
            $csrf_token = $_POST['csrf_token'] ?? '';
            if (empty($csrf_token)) {
                throw new Exception('Security token missing. Please refresh the page and try again.');
            }
            if (!validate_csrf($csrf_token)) {
                throw new Exception('Security token validation failed. Please refresh the page and try again.');
            }
        }
        
        // Get department ID from session (already verified in session.php)
        $dept = $userInfo['DEPT_ID'] ?? 0;
        
        if (!$dept) {
            throw new Exception('Department ID not found. Please contact administrator.');
        }
        
        $document_type = $_POST['document_type'] ?? '';
        $program_id = $_POST['program_code'] ?? ''; // This is actually the program ID, not code

        // Validate program_id is provided
        if (empty($program_id)) {
            throw new Exception('Program selection is required for document upload.');
        }
        
        // Fetch the actual programme_code from the database using the program ID
        $prog_query = "SELECT programme_code FROM programmes WHERE id = ? AND DEPT_ID = ? LIMIT 1";
        $prog_stmt = mysqli_prepare($conn, $prog_query);
        if (!$prog_stmt) {
            throw new Exception('Database error: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($prog_stmt, 'ii', $program_id, $dept);
        mysqli_stmt_execute($prog_stmt);
        $prog_result = mysqli_stmt_get_result($prog_stmt);
        
        if (!$prog_result || mysqli_num_rows($prog_result) === 0) {
            mysqli_stmt_close($prog_stmt);
            throw new Exception('Program not found or access denied.');
        }
        
        $prog_row = mysqli_fetch_assoc($prog_result);
        $program_code = $prog_row['programme_code']; // Actual programme_code (e.g., '456', '477')
        mysqli_stmt_close($prog_stmt);
        
        // Get academic year for folder structure - use centralized function
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
        // Suppress any warnings/notices from the include
        ob_start();
        require_once(__DIR__ . '/common_upload_handler.php');
        $include_output = ob_get_clean();
        
        // If there was any output from the include, log it but don't send it
        if (!empty($include_output)) {
            error_log("PlacementDetails upload: Unexpected output from common_upload_handler.php: " . substr($include_output, 0, 200));
        }
        
        // Set global variables for common_upload_handler.php
        $GLOBALS['dept_id'] = $dept;
        $GLOBALS['A_YEAR'] = $A_YEAR;
        
        // Check if function exists
        if (!function_exists('handleDocumentUpload')) {
            throw new Exception('Upload handler function not available. Please contact administrator.');
        }
        
        // Map document types to section names and serial numbers
        $section_map = [
            'placement_document' => ['name' => 'Placement Details', 'serial' => 1],
            'exam_qualification' => ['name' => 'Exam Qualifications', 'serial' => 2], 
            'higher_studies' => ['name' => 'Higher Studies', 'serial' => 3]
        ];

        $section_info = $section_map[$document_type] ?? ['name' => 'Placement Details', 'serial' => 1];
        $section_name = $section_info['name'];
        $srno = $section_info['serial']; // Unique serial per document type (1, 2, 3)
        $file_id = $document_type;
        
        // Modify section_name to include program_code for uniqueness (e.g., "Placement Details_PROG_456")
        // This ensures each program has its own unique documents in the supporting_documents table
        $unique_section_name = $section_name . '_PROG_' . $program_code;

        // Use common upload handler with program-specific directory
        // Structure: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/placement_details/{program_code}/
        // Pass the modified section_name directly to ensure uniqueness per program
        try {
            $result = handleDocumentUpload('placement_details', $unique_section_name, [
                'upload_dir' => dirname(__DIR__) . "/uploads/{$A_YEAR}/DEPARTMENT/{$dept}/placement_details/{$program_code}/",
                'max_size' => 10,
                'document_title' => $section_name . ' Documentation',
                'srno' => $srno,
                'file_id' => $file_id,
                'program_id' => $program_code
            ]);
            
            // Validate result
            if (!is_array($result)) {
                throw new Exception('Invalid response from upload handler. Please try again.');
            }
            
            // Clear buffers before output
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            // Ensure headers are set
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            
            // Normalize file path to web-accessible format (same as ExecutiveDevelopment.php)
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
            
            // Clear buffers before output
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            // Ensure headers are set
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-cache, must-revalidate');
            }
            
            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-cache, must-revalidate');
            }
            error_log("PlacementDetails upload error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Error $e) {
            // Catch fatal errors too
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-cache, must-revalidate');
            }
            error_log("PlacementDetails upload fatal error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
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
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_doc'])) {
    // CRITICAL: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session and load config FIRST (silently)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    ob_start();
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        require_once(__DIR__ . '/../config.php');
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
    
    // Set headers BEFORE any output
    if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $srno = (int)($_GET['srno'] ?? $_POST['srno'] ?? 0);
        $document_id = (int)($_GET['document_id'] ?? $_POST['document_id'] ?? 0);
        
        $dept_id = $userInfo['DEPT_ID'] ?? 0;
        if (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found.'];
        } elseif ($document_id > 0) {
            // Delete by document ID (safer)
            $get_file_query = "SELECT file_path, program_id FROM supporting_documents WHERE id = ? AND dept_id = ? AND page_section = 'placement_details' AND status = 'active' LIMIT 1";
            $stmt_get = mysqli_prepare($conn, $get_file_query);
            if ($stmt_get) {
                mysqli_stmt_bind_param($stmt_get, "ii", $document_id, $dept_id);
                if (mysqli_stmt_execute($stmt_get)) {
                    $result_get = mysqli_stmt_get_result($stmt_get);
                    if ($result_get && mysqli_num_rows($result_get) > 0) {
                        $row = mysqli_fetch_assoc($result_get);
                        $file_path = $row['file_path'];
                        $program_id = $row['program_id'] ?? '';
                        mysqli_free_result($result_get);
                        mysqli_stmt_close($stmt_get);
                        
                        // Delete physical file
                        $project_root = dirname(__DIR__);
                        $phys_path = $file_path;
                        if (strpos($phys_path, '../') === 0) {
                            $phys_path = $project_root . '/' . str_replace('../', '', $phys_path);
                        } elseif (strpos($phys_path, 'uploads/') === 0) {
                            $phys_path = $project_root . '/' . $phys_path;
                        } elseif (strpos($phys_path, $project_root) !== 0) {
                            $current_year = (int)date('Y');
                            $current_month = (int)date('n');
                            if ($current_month >= 7) {
    $a_year = $current_year . '-' . ($current_year + 1);
} else {
    $a_year = ($current_year - 2) . '-' . ($current_year - 1);
}
                            $filename = basename($phys_path);
                            $phys_path = $project_root . "/uploads/{$a_year}/DEPARTMENT/{$dept_id}/placement_details/" . ($program_id ?: '') . "/{$filename}";
                        }
                        $phys_path = str_replace('\\', '/', $phys_path);
                        
                        if (file_exists($phys_path)) {
                            @unlink($phys_path);
                        }
                        
                        // Soft delete in database
                        $delete_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE id = ? AND dept_id = ?";
                        $stmt_del = mysqli_prepare($conn, $delete_query);
                        if ($stmt_del) {
                            mysqli_stmt_bind_param($stmt_del, "ii", $document_id, $dept_id);
                            if (mysqli_stmt_execute($stmt_del)) {
                                $response = ['success' => true, 'message' => 'Document deleted successfully.'];
                            } else {
                                $response = ['success' => false, 'message' => 'Failed to delete document.'];
                            }
                            mysqli_stmt_close($stmt_del);
                        } else {
                            $response = ['success' => false, 'message' => 'Database error.'];
                        }
                    } else {
                        mysqli_free_result($result_get);
                        mysqli_stmt_close($stmt_get);
                        $response = ['success' => false, 'message' => 'Document not found.'];
                    }
                } else {
                    mysqli_stmt_close($stmt_get);
                    $response = ['success' => false, 'message' => 'Database error.'];
        }
            } else {
                $response = ['success' => false, 'message' => 'Database error.'];
            }
        } elseif ($srno > 0) {
            // Fallback: delete by serial number
        $current_year = (int)date('Y');
        $current_month = (int)date('n');
        if ($current_month >= 7) {
    $A_YEAR = $current_year . '-' . ($current_year + 1);
} else {
    $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
}
        
        $delete_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE academic_year = ? AND dept_id = ? AND page_section = 'placement_details' AND serial_number = ? AND status = 'active'";
        $stmt = mysqli_prepare($conn, $delete_query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sis", $A_YEAR, $dept_id, $srno);
            if (mysqli_stmt_execute($stmt)) {
                    $response = ['success' => true, 'message' => 'Document deleted successfully.'];
            } else {
                    $response = ['success' => false, 'message' => 'Failed to delete document.'];
            }
            mysqli_stmt_close($stmt);
        } else {
                $response = ['success' => false, 'message' => 'Database error.'];
            }
        } else {
            $response = ['success' => false, 'message' => 'Invalid parameters.'];
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

// Check if record exists for selected program and academic year - MUST BE BEFORE HTML OUTPUT
if (isset($_GET['check_exists']) && $_GET['check_exists'] == '1') {
    // Disable error display
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session FIRST
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    
    // Load session for user info
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    
    // Clear buffers again after includes
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Set headers BEFORE any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    
    $program_code = isset($_GET['program_code']) ? (int)$_GET['program_code'] : 0;
    if ($program_code <= 0) {
        echo json_encode(['success' => false, 'exists' => false]);
        exit;
    }
    
    $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
    if (!$dept_id) {
        echo json_encode(['success' => false, 'exists' => false, 'message' => 'Department ID not found']);
        exit;
    }
    
    // Calculate academic year
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    if ($current_month >= 7) {
    $a_year = $current_year . '-' . ($current_year + 1);
} else {
    $a_year = ($current_year - 2) . '-' . ($current_year - 1);
}
    
    // Check if record exists for this program, academic year, and department
    $check_query = "SELECT ID FROM placement_details WHERE PROGRAM_CODE = ? AND A_YEAR = ? AND DEPT_ID = ? LIMIT 1";
    $check_stmt = mysqli_prepare($conn, $check_query);
    if ($check_stmt) {
        mysqli_stmt_bind_param($check_stmt, 'isi', $program_code, $a_year, $dept_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        mysqli_stmt_close($check_stmt);
        if ($check_result && ($check_row = mysqli_fetch_assoc($check_result))) {
            echo json_encode(['success' => true, 'exists' => true, 'id' => $check_row['ID']]);
        } else {
            echo json_encode(['success' => true, 'exists' => false]);
        }
    } else {
        echo json_encode(['success' => false, 'exists' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// Inline edit: fetch a record for editing - MUST BE BEFORE HTML OUTPUT
if (isset($_GET['fetch']) && $_GET['fetch'] == '1') {
    // Disable error display
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session FIRST
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    
    // Load session for user info
    if (!isset($userInfo) || empty($userInfo)) {
        if (file_exists(__DIR__ . '/session.php')) {
            require_once(__DIR__ . '/session.php');
        }
    }
    
    // Clear buffers again after includes
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Set headers BEFORE any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        exit;
    }
    
    $dept_id = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;
    if (!$dept_id) {
        echo json_encode(['success' => false, 'message' => 'Department ID not found']);
        exit;
    }
    
    $q = mysqli_prepare($conn, "SELECT * FROM placement_details WHERE ID = ? AND DEPT_ID = ?");
    mysqli_stmt_bind_param($q, 'ii', $id, $dept_id);
    mysqli_stmt_execute($q);
    $res = mysqli_stmt_get_result($q);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        // Also fetch documents for this record
        $documents = [];
        
        // Get the actual programme_code from programmes table (PROGRAM_CODE in placement_details is the ID)
        $prog_code_query = "SELECT programme_code FROM programmes WHERE id = ? AND DEPT_ID = ? LIMIT 1";
        $prog_code_stmt = mysqli_prepare($conn, $prog_code_query);
        $actual_programme_code = '';
        if ($prog_code_stmt) {
            mysqli_stmt_bind_param($prog_code_stmt, 'ii', $row['PROGRAM_CODE'], $dept_id);
            mysqli_stmt_execute($prog_code_stmt);
            $prog_code_result = mysqli_stmt_get_result($prog_code_stmt);
            if ($prog_code_result && ($prog_code_row = mysqli_fetch_assoc($prog_code_result))) {
                $actual_programme_code = $prog_code_row['programme_code'];
                mysqli_free_result($prog_code_result);
            } else {
                if ($prog_code_result) {
                    mysqli_free_result($prog_code_result);
                }
            }
            mysqli_stmt_close($prog_code_stmt);
        }
        
        // Calculate academic year - use centralized function if available
        if (function_exists('getAcademicYear')) {
            $a_year = getAcademicYear();
        } else {
            // Fallback calculation - matches getAcademicYear() logic
            $current_year = (int)date('Y');
            $current_month = (int)date('n');
            if ($current_month >= 7) {
                $a_year = $current_year . '-' . ($current_year + 1);
            } else {
                $a_year = ($current_year - 2) . '-' . ($current_year - 1);
            }
        }
        
        // Get Placement Document - check with modified section_name format
        $modified_section_name_placement = 'Placement Details_PROG_' . $actual_programme_code;
        $placement_query = "SELECT * FROM supporting_documents WHERE dept_id = ? AND serial_number = 1 AND program_id = ? AND (section_name = ? OR section_name = ?) AND academic_year = ? AND status = 'active' LIMIT 1";
        $stmt_placement = mysqli_prepare($conn, $placement_query);
        if ($stmt_placement && !empty($actual_programme_code)) {
            $section_name_placement = 'Placement Details';
            mysqli_stmt_bind_param($stmt_placement, 'issss', $dept_id, $actual_programme_code, $modified_section_name_placement, $section_name_placement, $a_year);
            mysqli_stmt_execute($stmt_placement);
            $placement_res = mysqli_stmt_get_result($stmt_placement);
            if ($placement_res && ($placement_doc = mysqli_fetch_assoc($placement_res))) {
                $placement_path = $placement_doc['file_path'];
                // Ensure correct path format for view link
                // Handle paths: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/placement_details/{program_code}/FILENAME.pdf
                // Convert absolute paths to relative web paths
                $project_root = dirname(__DIR__);
                if ($placement_path && (strpos($placement_path, $project_root) === 0)) {
                    $placement_path = str_replace($project_root . '/', '', $placement_path);
                }
                // Normalize path separators
                $placement_path = str_replace('\\', '/', $placement_path);
                // Ensure path starts with ../ for web access
                if (strpos($placement_path, 'uploads/') === 0) {
                    $placement_path = '../' . $placement_path;
                } elseif (strpos($placement_path, '../') !== 0 && strpos($placement_path, 'http') !== 0) {
                    // Reconstruct if needed - ensure it includes program_code folder
                    if (!empty($actual_programme_code) && strpos($placement_path, 'placement_details/' . $actual_programme_code . '/') === false) {
                        // Use a_year from record or getAcademicYear() - don't recalculate
                        if (empty($a_year)) {
                            if (function_exists('getAcademicYear')) {
                                $a_year = getAcademicYear();
                            } else {
                                $current_year = (int)date('Y');
                                $current_month = (int)date('n');
                                if ($current_month >= 7) {
                                    $a_year = $current_year . '-' . ($current_year + 1);
                                } else {
                                    $a_year = ($current_year - 2) . '-' . ($current_year - 1);
                                }
                            }
                        }
                        $filename = basename($placement_path);
                        $placement_path = '../uploads/' . $a_year . '/DEPARTMENT/' . $dept_id . '/placement_details/' . $actual_programme_code . '/' . $filename;
                    } else {
                        $placement_path = '../' . $placement_path;
                    }
                }
                $documents['placement'] = [
                    'id' => $placement_doc['id'],
                    'path' => $placement_path,
                    'name' => basename($placement_path)
                ];
                mysqli_free_result($placement_res);
            } else {
                if ($placement_res) {
                    mysqli_free_result($placement_res);
                }
            }
            mysqli_stmt_close($stmt_placement);
        }
        
        // Get Exam Qualification Document - check with modified section_name format
        $modified_section_name_exam = 'Exam Qualifications_PROG_' . $actual_programme_code;
        $exam_query = "SELECT * FROM supporting_documents WHERE dept_id = ? AND serial_number = 2 AND program_id = ? AND (section_name = ? OR section_name = ?) AND academic_year = ? AND status = 'active' LIMIT 1";
        $stmt_exam = mysqli_prepare($conn, $exam_query);
        if ($stmt_exam && !empty($actual_programme_code)) {
            $section_name_exam = 'Exam Qualifications';
            mysqli_stmt_bind_param($stmt_exam, 'issss', $dept_id, $actual_programme_code, $modified_section_name_exam, $section_name_exam, $a_year);
            mysqli_stmt_execute($stmt_exam);
            $exam_res = mysqli_stmt_get_result($stmt_exam);
            if ($exam_res && ($exam_doc = mysqli_fetch_assoc($exam_res))) {
                $exam_path = $exam_doc['file_path'];
                // Ensure correct path format for view link
                // Handle paths: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/placement_details/{program_code}/FILENAME.pdf
                // Convert absolute paths to relative web paths
                $project_root = dirname(__DIR__);
                if ($exam_path && (strpos($exam_path, $project_root) === 0)) {
                    $exam_path = str_replace($project_root . '/', '', $exam_path);
                }
                // Normalize path separators
                $exam_path = str_replace('\\', '/', $exam_path);
                // Ensure path starts with ../ for web access
                if (strpos($exam_path, 'uploads/') === 0) {
                    $exam_path = '../' . $exam_path;
                } elseif (strpos($exam_path, '../') !== 0 && strpos($exam_path, 'http') !== 0) {
                    // Reconstruct if needed - ensure it includes program_code folder
                    if (!empty($actual_programme_code) && strpos($exam_path, 'placement_details/' . $actual_programme_code . '/') === false) {
                        // Use a_year from record or getAcademicYear() - don't recalculate
                        if (empty($a_year)) {
                            if (function_exists('getAcademicYear')) {
                                $a_year = getAcademicYear();
                            } else {
                                $current_year = (int)date('Y');
                                $current_month = (int)date('n');
                                if ($current_month >= 7) {
                                    $a_year = $current_year . '-' . ($current_year + 1);
                                } else {
                                    $a_year = ($current_year - 2) . '-' . ($current_year - 1);
                                }
                            }
                        }
                        $filename = basename($exam_path);
                        $exam_path = '../uploads/' . $a_year . '/DEPARTMENT/' . $dept_id . '/placement_details/' . $actual_programme_code . '/' . $filename;
                    } else {
                        $exam_path = '../' . $exam_path;
                    }
                }
                $documents['exam'] = [
                    'id' => $exam_doc['id'],
                    'path' => $exam_path,
                    'name' => basename($exam_path)
                ];
                mysqli_free_result($exam_res);
            } else {
                if ($exam_res) {
                    mysqli_free_result($exam_res);
                }
            }
            mysqli_stmt_close($stmt_exam);
        }
        
        // Get Higher Studies Document - check with modified section_name format
        $modified_section_name_higher = 'Higher Studies_PROG_' . $actual_programme_code;
        $higher_query = "SELECT * FROM supporting_documents WHERE dept_id = ? AND serial_number = 3 AND program_id = ? AND (section_name = ? OR section_name = ?) AND academic_year = ? AND status = 'active' LIMIT 1";
        $stmt_higher = mysqli_prepare($conn, $higher_query);
        if ($stmt_higher && !empty($actual_programme_code)) {
            $section_name_higher = 'Higher Studies';
            mysqli_stmt_bind_param($stmt_higher, 'issss', $dept_id, $actual_programme_code, $modified_section_name_higher, $section_name_higher, $a_year);
            mysqli_stmt_execute($stmt_higher);
            $higher_res = mysqli_stmt_get_result($stmt_higher);
            if ($higher_res && ($higher_doc = mysqli_fetch_assoc($higher_res))) {
                $higher_path = $higher_doc['file_path'];
                // Ensure correct path format for view link
                // Handle paths: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/placement_details/{program_code}/FILENAME.pdf
                // Convert absolute paths to relative web paths
                $project_root = dirname(__DIR__);
                if ($higher_path && (strpos($higher_path, $project_root) === 0)) {
                    $higher_path = str_replace($project_root . '/', '', $higher_path);
                }
                // Normalize path separators
                $higher_path = str_replace('\\', '/', $higher_path);
                // Ensure path starts with ../ for web access
                if (strpos($higher_path, 'uploads/') === 0) {
                    $higher_path = '../' . $higher_path;
                } elseif (strpos($higher_path, '../') !== 0 && strpos($higher_path, 'http') !== 0) {
                    // Reconstruct if needed - ensure it includes program_code folder
                    if (!empty($actual_programme_code) && strpos($higher_path, 'placement_details/' . $actual_programme_code . '/') === false) {
                        // Use a_year from record or getAcademicYear() - don't recalculate
                        if (empty($a_year)) {
                            if (function_exists('getAcademicYear')) {
                                $a_year = getAcademicYear();
                            } else {
                                $current_year = (int)date('Y');
                                $current_month = (int)date('n');
                                if ($current_month >= 7) {
                                    $a_year = $current_year . '-' . ($current_year + 1);
                                } else {
                                    $a_year = ($current_year - 2) . '-' . ($current_year - 1);
                                }
                            }
                        }
                        $filename = basename($higher_path);
                        $higher_path = '../uploads/' . $a_year . '/DEPARTMENT/' . $dept_id . '/placement_details/' . $actual_programme_code . '/' . $filename;
                    } else {
                        $higher_path = '../' . $higher_path;
                    }
                }
                $documents['higher'] = [
                    'id' => $higher_doc['id'],
                    'path' => $higher_path,
                    'name' => basename($higher_path)
                ];
                mysqli_free_result($higher_res);
            } else {
                if ($higher_res) {
                    mysqli_free_result($higher_res);
                }
            }
            mysqli_stmt_close($stmt_higher);
        }
        
        mysqli_free_result($res);
        mysqli_stmt_close($q);
        echo json_encode(['success' => true, 'record' => $row, 'documents' => $documents]);
    } else {
        if ($res) {
            mysqli_free_result($res);
        }
        if ($q) {
            mysqli_stmt_close($q);
        }
        echo json_encode(['success' => false, 'message' => 'Record not found']);
    }
    exit;
}

// ============================================================================
// HANDLE check_all_docs - BATCH endpoint to get ALL document statuses in ONE query
// This replaces individual queries with a single efficient query (like ExecutiveDevelopment.php)
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
        $program_code = $_GET['program_code'] ?? '';
        
        if (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found'];
        } elseif (empty($program_code)) {
            $response = ['success' => false, 'message' => 'Program code is required'];
        } else {
            // Get academic year - use centralized function
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
                if ($current_month >= 7) {
                    $academic_year = $current_year . '-' . ($current_year + 1);
                } else {
                    $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
                }
            }
            
            // CRITICAL: Single query to get ALL documents at once for this program
            // Query for all three document types (placement, exam, higher studies) for this program
            $batch_query = "SELECT serial_number, file_path, file_name, upload_date, id, section_name 
                           FROM supporting_documents 
                           WHERE academic_year = ? AND dept_id = ? AND page_section = 'placement_details' 
                           AND (program_id = ? OR section_name LIKE ?) AND status = 'active'";
            $stmt = mysqli_prepare($conn, $batch_query);
            
            if (!$stmt) {
                $response = ['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)];
            } else {
                $program_pattern = '%_PROG_' . $program_code;
                mysqli_stmt_bind_param($stmt, 'siss', $academic_year, $dept_id, $program_code, $program_pattern);
                
                if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    $documents = [];
                    $project_root = dirname(__DIR__);
                    
                    while ($row = mysqli_fetch_assoc($result)) {
                        // Convert to web-accessible path
                        $file_path = $row['file_path'];
                        if (strpos($file_path, $project_root) === 0) {
                            $file_path = str_replace([$project_root . '/', $project_root . '\\'], '', $file_path);
                        }
                        $file_path = str_replace('\\', '/', $file_path);
                        if (strpos($file_path, 'uploads/') === 0) {
                            $file_path = '../' . $file_path;
                        }
                        
                        // Map serial_number to document type
                        $srno = (int)$row['serial_number'];
                        $documents[$srno] = [
                            'success' => true,
                            'file_path' => $file_path,
                            'file_name' => $row['file_name'] ?? basename($file_path),
                            'upload_date' => $row['upload_date'] ?? date('Y-m-d H:i:s'),
                            'document_id' => $row['id']
                        ];
                    }
                    
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
    
    // Load config silently
    ob_start();
    if (!isset($conn) || !$conn) {
        if (file_exists(__DIR__ . '/../config.php')) {
        require_once(__DIR__ . '/../config.php');
    }
    }
    ob_end_clean();
    
    // Load session silently
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
        $file_id = $_GET['file_id'] ?? '';
        $program_code = $_GET['program_code'] ?? '';
        
        if ($srno <= 0 || empty($file_id)) {
            $response = ['success' => false, 'message' => 'Invalid serial number or file ID'];
        } else {
        $dept_id = $userInfo['DEPT_ID'] ?? 0;
        if (!$dept_id) {
                $response = ['success' => false, 'message' => 'Department ID not found. Please login again.'];
            } else {
        // Get academic year - use centralized function if available
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
            if ($current_month >= 7) {
                $academic_year = $current_year . '-' . ($current_year + 1);
            } else {
                $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
            }
        }
    
                // Build query - try to match by program_code if provided, otherwise get first match
                if (!empty($program_code)) {
                    // Try unique section_name first
                    $unique_section_name = '';
                    if ($srno == 1) {
                        $unique_section_name = 'Placement Details_PROG_' . $program_code;
                    } elseif ($srno == 2) {
                        $unique_section_name = 'Exam Qualifications_PROG_' . $program_code;
                    } elseif ($srno == 3) {
                        $unique_section_name = 'Higher Studies_PROG_' . $program_code;
                    }
        
                    $get_file_query = "SELECT file_path, file_name, upload_date, program_id, id FROM supporting_documents 
                        WHERE academic_year = ? AND dept_id = ? AND page_section = 'placement_details' AND serial_number = ? 
                        AND (section_name = ? OR program_id = ?) AND status = 'active' 
            ORDER BY id DESC LIMIT 1";
        $stmt = mysqli_prepare($conn, $get_file_query);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "sisss", $academic_year, $dept_id, $srno, $unique_section_name, $program_code);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                    } else {
                        $result = false;
                    }
                } else {
                    // Fallback: get first match by serial number
                    $get_file_query = "SELECT file_path, file_name, upload_date, program_id, id FROM supporting_documents 
                        WHERE academic_year = ? AND dept_id = ? AND page_section = 'placement_details' AND serial_number = ? AND status = 'active' 
                        ORDER BY id DESC LIMIT 1";
                    $stmt = mysqli_prepare($conn, $get_file_query);
                    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "sis", $academic_year, $dept_id, $srno);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
                    } else {
                        $result = false;
                    }
                }
        
                if ($stmt && $result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
                    mysqli_free_result($result);
                    mysqli_stmt_close($stmt);
                    
            $relative_path = $row['file_path'];
            $program_id_from_db = $row['program_id'] ?? '';
            
                    // Build web-accessible path
            $project_root = dirname(__DIR__);
                    if ($relative_path && (strpos($relative_path, $project_root) === 0)) {
                        $relative_path = str_replace([$project_root . '/', $project_root . '\\'], '', $relative_path);
                }
                    
                    $relative_path = str_replace('\\', '/', trim($relative_path));
                    $relative_path = ltrim($relative_path, './\\/');
                    
            if (strpos($relative_path, 'uploads/') === 0) {
                $web_path = '../' . $relative_path;
            } elseif (strpos($relative_path, '../') === 0) {
                        $web_path = $relative_path;
                    } elseif (strpos($relative_path, 'http') === 0) {
                $web_path = $relative_path;
            } else {
                    $filename = basename($relative_path);
                        $web_path = '../uploads/' . $academic_year . '/DEPARTMENT/' . $dept_id . '/placement_details/' . ($program_id_from_db ?: $program_code) . '/' . $filename;
                }
                    
            $web_path = str_replace('\\', '/', $web_path);
            
                    $response = [
                'success' => true,
                'file_path' => $web_path,
                'file_name' => $row['file_name'] ?? basename($web_path),
                'upload_date' => $row['upload_date'] ?? date('Y-m-d H:i:s'),
                        'is_temp' => false,
                        'document_id' => $row['id'] ?? 0
                    ];
        } else {
                    if ($result) {
                        mysqli_free_result($result);
                    }
                    if ($stmt) {
            mysqli_stmt_close($stmt);
                    }
                    $response = ['success' => false, 'message' => 'No document found'];
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    } catch (Error $e) {
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
// NORMAL PAGE LOAD - Do all processing BEFORE unified_header.php
// ============================================================================
// Start output buffering if not already started
if (ob_get_level() === 0) { ob_start(); }
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1); // Still log errors to error_log file

// Require session.php first (before unified_header.php)
require_once(__DIR__ . '/session.php');
// Note: config.php is already loaded by session.php -> session_verification.php

// Load common functions to get getAcademicYear if needed
// Note: We don't load common_functions.php here because it has getDepartmentInfo($conn) 
// which conflicts with our local getDepartmentInfo($conn, $dept_id) function
if (!function_exists('getAcademicYear')) {
    if (file_exists(__DIR__ . '/common_functions.php')) {
        require_once(__DIR__ . '/common_functions.php');
    }
}

// Initialize CSRF token early
if (file_exists(__DIR__ . '/csrf.php')) {
    require_once __DIR__ . '/csrf.php';
    // Ensure token is generated
    if (function_exists('csrf_token')) {
        csrf_token(); // This will generate token if it doesn't exist
    }
}

//Academic year logic - use centralized function
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

$dept = $userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0;

// If dept is empty, set to 0 and show error message on page instead of redirecting
if (empty($dept)) {
    $dept = 0;
}

// Get department details using database query directly (safer than function call)
$dept_info = null;
$dept_code = '';
$dept_name = '';
if ($dept > 0 && isset($conn) && $conn) {
    $dept_query = "SELECT DEPT_ID, DEPT_COLL_NO, DEPT_NAME FROM department_master WHERE DEPT_ID = ? LIMIT 1";
    $dept_stmt = mysqli_prepare($conn, $dept_query);
    if ($dept_stmt) {
        mysqli_stmt_bind_param($dept_stmt, 'i', $dept);
        mysqli_stmt_execute($dept_stmt);
        $dept_result = mysqli_stmt_get_result($dept_stmt);
        if ($dept_result && mysqli_num_rows($dept_result) > 0) {
            $dept_info = mysqli_fetch_assoc($dept_result);
            $dept_code = $dept_info['DEPT_COLL_NO'] ?? '';
            $dept_name = $dept_info['DEPT_NAME'] ?? '';
        }
        if ($dept_result) {
            mysqli_free_result($dept_result);
        }
        mysqli_stmt_close($dept_stmt);
    }
}

// Now require unified_header.php AFTER all processing (like ExecutiveDevelopment.php)
require('unified_header.php');

// Note: getDepartmentInfo function removed - we use direct database queries instead
// This avoids conflicts with common_functions.php which has getDepartmentInfo($conn)

// Safe redirect helper that falls back to JS if headers already sent
function safe_redirect($url) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    echo '<script>window.location.href = ' . json_encode($url) . ';</script>';
    exit;
}


// Handle document deletion (by ID) - SAFE DELETE WITH PROPER RESOURCE CLEANUP
if (isset($_GET['delete_document'])) {
    // CRITICAL: Clear ALL output buffers FIRST
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
    if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
        require_once(__DIR__ . '/../config.php');
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
    
    // Set headers BEFORE any output
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $document_id = (int)($_GET['document_id'] ?? 0);
        $dept_id = $userInfo['DEPT_ID'] ?? $dept ?? 0;

        if ($document_id <= 0) {
            $response = ['success' => false, 'message' => 'Invalid document ID'];
        } elseif (!$dept_id) {
            $response = ['success' => false, 'message' => 'Department ID not found'];
        } else {
            // Get file path and program_id from database using prepared statement
            $get_file_query = "SELECT file_path, program_id FROM supporting_documents WHERE id = ? AND dept_id = ? AND page_section = 'placement_details' AND status = 'active' LIMIT 1";
            $stmt_get = mysqli_prepare($conn, $get_file_query);
            if ($stmt_get) {
                mysqli_stmt_bind_param($stmt_get, 'ii', $document_id, $dept_id);
                if (mysqli_stmt_execute($stmt_get)) {
                    $result_get = mysqli_stmt_get_result($stmt_get);
                    if ($result_get && mysqli_num_rows($result_get) > 0) {
                        $row = mysqli_fetch_assoc($result_get);
                        $file_path = $row['file_path'];
                        $program_id = $row['program_id'] ?? '';
                        mysqli_free_result($result_get);
                        mysqli_stmt_close($stmt_get);
                        
                        // Delete physical file safely
                        $project_root = dirname(__DIR__);
                        $phys_path = $file_path;
                        
                        // Convert relative to absolute path
                        if (strpos($phys_path, '../') === 0) {
                            $phys_path = $project_root . '/' . str_replace('../', '', $phys_path);
                        } elseif (strpos($phys_path, 'uploads/') === 0) {
                            $phys_path = $project_root . '/' . $phys_path;
                        } elseif (strpos($phys_path, $project_root) !== 0) {
                            // Reconstruct path
                        $current_year = (int)date('Y');
                        $current_month = (int)date('n');
                        if ($current_month >= 7) {
    $a_year = $current_year . '-' . ($current_year + 1);
} else {
    $a_year = ($current_year - 2) . '-' . ($current_year - 1);
}
                            $filename = basename($phys_path);
                            $phys_path = $project_root . "/uploads/{$a_year}/DEPARTMENT/{$dept_id}/placement_details/" . ($program_id ?: '') . "/{$filename}";
                        }
                        $phys_path = str_replace('\\', '/', $phys_path);
                        
                        // Try to delete file (suppress errors if file doesn't exist)
                        if ($phys_path && file_exists($phys_path)) {
                            @unlink($phys_path);
        }

                        // Soft delete from database using prepared statement
                        $delete_query = "UPDATE supporting_documents SET status = 'deleted', updated_date = CURRENT_TIMESTAMP WHERE id = ? AND dept_id = ?";
                        $stmt_del = mysqli_prepare($conn, $delete_query);
                        if ($stmt_del) {
                            mysqli_stmt_bind_param($stmt_del, 'ii', $document_id, $dept_id);
                            if (mysqli_stmt_execute($stmt_del)) {
                                $response = ['success' => true, 'message' => 'Document deleted successfully'];
                            } else {
                                $response = ['success' => false, 'message' => 'Database error: Failed to update record'];
                            }
                            mysqli_stmt_close($stmt_del);
                        } else {
                            $response = ['success' => false, 'message' => 'Database error: Failed to prepare delete query'];
                        }
                    } else {
                        if ($result_get) {
                            mysqli_free_result($result_get);
                        }
                        mysqli_stmt_close($stmt_get);
                        $response = ['success' => false, 'message' => 'Document not found'];
                    }
        } else {
                    mysqli_stmt_close($stmt_get);
                    $response = ['success' => false, 'message' => 'Database error: Failed to execute query'];
        }
    } else {
                $response = ['success' => false, 'message' => 'Database error: Failed to prepare query'];
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

// Create new record only when not editing
// Allow both normal form posts and AJAX posts (which may not send 'submit')

// IMPORTANT: Check for EDIT first (has record_id), then check for INSERT (no record_id)
// Inline edit: update existing record when record_id provided
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_id']) && $_POST['record_id'] !== '') {
    // CSRF validation for edit submission
    if (file_exists(__DIR__ . '/csrf.php')) { 
        require_once __DIR__ . '/csrf.php'; 
        if (function_exists('csrf_token')) {
            csrf_token();
        }
        if (function_exists('validate_csrf')) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (empty($csrf)) {
            } elseif (!validate_csrf($csrf)) {
                safe_redirect('PlacementDetails.php?error=csrf');
                exit;
            } else {
            }
        }
    }
    
    $record_id = (int)$_POST['record_id'];
    $p_name = $_POST['p_name'];
    
    if (empty($p_name)) {
        safe_redirect('PlacementDetails.php?error=program');
        exit;
    }
    
    // Try to get program name from hidden field first (from form submission), otherwise fetch from database
    $programme_name = $_POST['program_name_hidden'] ?? '';
    
    if (empty($programme_name)) {
        // Fallback: fetch from database
        $qProg = mysqli_prepare($conn, "SELECT id, programme_name FROM programmes WHERE id = ? AND DEPT_ID = ?");
        if (!$qProg) {
            safe_redirect('PlacementDetails.php?error=update');
            exit;
        }
        mysqli_stmt_bind_param($qProg, 'ii', $p_name, $dept);
        mysqli_stmt_execute($qProg);
        $result = mysqli_stmt_get_result($qProg);
        if ($result && ($row = mysqli_fetch_assoc($result))) {
            $p_code = (int)$row['id'];
            $programme_name = $row['programme_name'];
        } else {
            mysqli_stmt_close($qProg);
            safe_redirect('PlacementDetails.php?error=program');
            exit;
        }
        mysqli_stmt_close($qProg);
    } else {
        // Use the program name from form and get program code from p_name
        $p_code = (int)$p_name;
    }
    
    if (!empty($programme_name)) {
        
        // Get final year intake from programmes table (last non-zero year capacity)
        $final_year_intake = 0;
        $finalYearStmt = mysqli_prepare($conn, "SELECT year_1_capacity, year_2_capacity, year_3_capacity, year_4_capacity, year_5_capacity, year_6_capacity, intake_capacity FROM programmes WHERE id = ? AND DEPT_ID = ? LIMIT 1");
        if ($finalYearStmt) {
            mysqli_stmt_bind_param($finalYearStmt, 'ii', $p_code, $dept);
            mysqli_stmt_execute($finalYearStmt);
            $finalYearRes = mysqli_stmt_get_result($finalYearStmt);
            if ($finalYearRes && ($finalYearRow = mysqli_fetch_assoc($finalYearRes))) {
                // Check from year_6 down to year_1 to find the last non-zero year
                for ($year = 6; $year >= 1; $year--) {
                    $year_capacity = (int)($finalYearRow['year_' . $year . '_capacity'] ?? 0);
                    if ($year_capacity > 0) {
                        $final_year_intake = $year_capacity;
                        break;
                    }
                }
                // If no year-wise capacity found, fallback to intake_capacity
                if ($final_year_intake == 0) {
                    $final_year_intake = (int)($finalYearRow['intake_capacity'] ?? 0);
                }
                mysqli_free_result($finalYearRes);
            }
            mysqli_stmt_close($finalYearStmt);
        }
        // Use final year intake instead of total intake_capacity
        $no_of_students = $final_year_intake;
        $no_of_students_late_entry = (int)($_POST['no_of_students_late_entry'] ?? 0);
        $no_of_students_admitted_final_year = (int)($_POST['no_of_students_admitted_final_year'] ?? 0);
        $no_of_students_graduted = (int)($_POST['no_of_students_graduted'] ?? 0);
        $no_of_students_placed = (int)($_POST['no_of_students_placed'] ?? 0);
        $no_of_students_higher_studies = (int)($_POST['no_of_students_higher_studies'] ?? 0);
        $students_qualifying_exams = (int)($_POST['students_qualifying_exams'] ?? 0);
        
        // Validate that admitted final year students don't exceed final year intake
        if ($no_of_students_admitted_final_year > $final_year_intake) {
            $final_year_intake_escaped = htmlspecialchars((string)$final_year_intake, ENT_QUOTES, 'UTF-8');
            echo "<script>alert('No. of Students Admitted in Final Year cannot exceed Total Final Year Intake Count (" . $final_year_intake_escaped . ").'); window.location.href='PlacementDetails.php';</script>";
            exit;
        }

        // Server-side validation checks
        if ($no_of_students < 0 || $no_of_students_late_entry < 0 || $no_of_students_admitted_final_year < 0 || $no_of_students_graduted < 0 || $no_of_students_placed < 0 || $no_of_students_higher_studies < 0 || $students_qualifying_exams < 0) {
            echo "<script>alert('Values cannot be negative.'); window.location.href='PlacementDetails.php';</script>";
            exit;
        }
        if ($no_of_students_graduted > $no_of_students) {
            echo "<script>alert('Graduated cannot exceed Total students.'); window.location.href='PlacementDetails.php';</script>";
            exit;
        }
        if ($no_of_students_placed > $no_of_students_graduted) {
            echo "<script>alert('Placed cannot exceed Graduated.'); window.location.href='PlacementDetails.php';</script>";
            exit;
        }
        if ($no_of_students_higher_studies > $no_of_students) {
            echo "<script>alert('Higher studies cannot exceed Total students.'); window.location.href='PlacementDetails.php';</script>";
            exit;
        }
        if ($students_qualifying_exams > $no_of_students) {
            echo "<script>alert('Qualifying exams cannot exceed Total students.'); window.location.href='PlacementDetails.php';</script>";
            exit;
        }

        // Check if STUDENTS_QUALIFYING_EXAMS column exists
        $cols = [];
        $descRes = mysqli_query($conn, "DESCRIBE placement_details");
        if ($descRes) {
            while ($c = mysqli_fetch_assoc($descRes)) { $cols[] = $c['Field']; }
            mysqli_free_result($descRes); // CRITICAL: Free result set
        }
        $hasExams = in_array('STUDENTS_QUALIFYING_EXAMS', $cols, true);
        $hasFinalYear = in_array('NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR', $cols, true);
        
        // Get final year intake for validation
        $final_year_intake = 0;
        $finalYearStmt = mysqli_prepare($conn, "SELECT year_1_capacity, year_2_capacity, year_3_capacity, year_4_capacity, year_5_capacity, year_6_capacity, intake_capacity FROM programmes WHERE id = ? AND DEPT_ID = ? LIMIT 1");
        if ($finalYearStmt) {
            mysqli_stmt_bind_param($finalYearStmt, 'ii', $p_code, $dept);
            mysqli_stmt_execute($finalYearStmt);
            $finalYearRes = mysqli_stmt_get_result($finalYearStmt);
            if ($finalYearRes && ($finalYearRow = mysqli_fetch_assoc($finalYearRes))) {
                // Check from year_6 down to year_1 to find the last non-zero year
                for ($year = 6; $year >= 1; $year--) {
                    $year_capacity = (int)($finalYearRow['year_' . $year . '_capacity'] ?? 0);
                    if ($year_capacity > 0) {
                        $final_year_intake = $year_capacity;
                        break;
                    }
                }
                // If no year-wise capacity found, fallback to intake_capacity
                if ($final_year_intake == 0) {
                    $final_year_intake = (int)($finalYearRow['intake_capacity'] ?? 0);
                }
                mysqli_free_result($finalYearRes);
            }
            mysqli_stmt_close($finalYearStmt);
        }
        // Use final year intake instead of total intake_capacity
        $no_of_students = $final_year_intake;
        $no_of_students_admitted_final_year = (int)($_POST['no_of_students_admitted_final_year'] ?? 0);
        
        // Validate that admitted final year students don't exceed final year intake
        if ($no_of_students_admitted_final_year > $final_year_intake) {
            $final_year_intake_escaped = htmlspecialchars((string)$final_year_intake, ENT_QUOTES, 'UTF-8');
            echo "<script>alert('No. of Students Admitted in Final Year cannot exceed Total Final Year Intake Count (" . $final_year_intake_escaped . ").');</script>"; exit;
        }
        
        if ($hasExams && $hasFinalYear) {
            $upd = mysqli_prepare($conn, "UPDATE placement_details SET PROGRAM_CODE=?, PROGRAM_NAME=?, TOTAL_NO_OF_STUDENT=?, NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY=?, NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR=?, TOTAL_NUM_OF_STUDENTS_GRADUATED=?, TOTAL_NUM_OF_STUDENTS_PLACED=?, NUM_OF_STUDENTS_IN_HIGHER_STUDIES=?, STUDENTS_QUALIFYING_EXAMS=?, updated_date=CURRENT_TIMESTAMP WHERE ID=? AND DEPT_ID=?");
            mysqli_stmt_bind_param($upd, 'isiiiiiiiii', $p_code, $programme_name, $no_of_students, $no_of_students_late_entry, $no_of_students_admitted_final_year, $no_of_students_graduted, $no_of_students_placed, $no_of_students_higher_studies, $students_qualifying_exams, $record_id, $dept);
        } elseif ($hasExams) {
            $upd = mysqli_prepare($conn, "UPDATE placement_details SET PROGRAM_CODE=?, PROGRAM_NAME=?, TOTAL_NO_OF_STUDENT=?, NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY=?, TOTAL_NUM_OF_STUDENTS_GRADUATED=?, TOTAL_NUM_OF_STUDENTS_PLACED=?, NUM_OF_STUDENTS_IN_HIGHER_STUDIES=?, STUDENTS_QUALIFYING_EXAMS=?, updated_date=CURRENT_TIMESTAMP WHERE ID=? AND DEPT_ID=?");
            mysqli_stmt_bind_param($upd, 'isiiiiiiii', $p_code, $programme_name, $no_of_students, $no_of_students_late_entry, $no_of_students_graduted, $no_of_students_placed, $no_of_students_higher_studies, $students_qualifying_exams, $record_id, $dept);
        } elseif ($hasFinalYear) {
            $upd = mysqli_prepare($conn, "UPDATE placement_details SET PROGRAM_CODE=?, PROGRAM_NAME=?, TOTAL_NO_OF_STUDENT=?, NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY=?, NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR=?, TOTAL_NUM_OF_STUDENTS_GRADUATED=?, TOTAL_NUM_OF_STUDENTS_PLACED=?, NUM_OF_STUDENTS_IN_HIGHER_STUDIES=?, updated_date=CURRENT_TIMESTAMP WHERE ID=? AND DEPT_ID=?");
            mysqli_stmt_bind_param($upd, 'isiiiiiiii', $p_code, $programme_name, $no_of_students, $no_of_students_late_entry, $no_of_students_admitted_final_year, $no_of_students_graduted, $no_of_students_placed, $no_of_students_higher_studies, $record_id, $dept);
        } else {
            $upd = mysqli_prepare($conn, "UPDATE placement_details SET PROGRAM_CODE=?, PROGRAM_NAME=?, TOTAL_NO_OF_STUDENT=?, NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY=?, TOTAL_NUM_OF_STUDENTS_GRADUATED=?, TOTAL_NUM_OF_STUDENTS_PLACED=?, NUM_OF_STUDENTS_IN_HIGHER_STUDIES=?, updated_date=CURRENT_TIMESTAMP WHERE ID=? AND DEPT_ID=?");
            mysqli_stmt_bind_param($upd, 'isiiiiiii', $p_code, $programme_name, $no_of_students, $no_of_students_late_entry, $no_of_students_graduted, $no_of_students_placed, $no_of_students_higher_studies, $record_id, $dept);
        }
        
        if (!$upd) {
            safe_redirect('PlacementDetails.php?error=update');
            exit;
        }
        
        if (mysqli_stmt_execute($upd)) {
            safe_redirect('PlacementDetails.php?updated=1');
        } else {
            safe_redirect('PlacementDetails.php?error=update');
        }
        mysqli_stmt_close($upd);
    } else {
        safe_redirect('PlacementDetails.php?error=program');
    }
    exit; // Important: exit after edit to prevent insert handler from running
}

// Create new record only when not editing (no record_id)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['p_name']) && (!isset($_POST['record_id']) || $_POST['record_id'] === '')) {
    // CSRF validation for form submission
    if (file_exists(__DIR__ . '/csrf.php')) { 
        require_once __DIR__ . '/csrf.php'; 
        if (function_exists('csrf_token')) {
            csrf_token(); // Generate if missing
        }
        if (function_exists('validate_csrf')) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (empty($csrf)) {
                safe_redirect('PlacementDetails.php?error=csrf&msg=token_missing');
                exit;
            }
            if (!validate_csrf($csrf)) {
                safe_redirect('PlacementDetails.php?error=csrf&msg=token_invalid');
                exit;
            }
        }
    }
    
    $p_name = $_POST['p_name'];

    // Fetch selected programme
    // Try to get program name from hidden field first (from form submission), otherwise fetch from database
    $programme_name = $_POST['program_name_hidden'] ?? '';
    
    if (empty($programme_name)) {
        // Fallback: fetch from database
    $progStmt = mysqli_prepare($conn, "SELECT id, programme_name FROM programmes WHERE id = ? AND DEPT_ID = ? LIMIT 1");
        if (!$progStmt) {
            safe_redirect('PlacementDetails.php?error=program');
            exit;
        }
    mysqli_stmt_bind_param($progStmt, 'ii', $p_name, $dept);
    mysqli_stmt_execute($progStmt);
    $progRes = mysqli_stmt_get_result($progStmt);
    if ($progRes && ($row = mysqli_fetch_assoc($progRes))) {
        $p_code = (int)$row['id'];
        $programme_name = $row['programme_name'];
        mysqli_free_result($progRes); // CRITICAL: Free result set
        mysqli_stmt_close($progStmt); // CRITICAL: Close statement
        } else {
            if ($progRes) {
                mysqli_free_result($progRes); // CRITICAL: Free result set even if empty
            }
            mysqli_stmt_close($progStmt); // CRITICAL: Close statement
            safe_redirect('PlacementDetails.php?error=program');
            exit;
        }
    } else {
        // Use the program name from form and get program code from p_name
        $p_code = (int)$p_name;
    }
    
    if (!empty($programme_name)) {

        // Get final year intake from programmes table (last non-zero year capacity)
        $final_year_intake = 0;
        $finalYearStmt = mysqli_prepare($conn, "SELECT year_1_capacity, year_2_capacity, year_3_capacity, year_4_capacity, year_5_capacity, year_6_capacity, intake_capacity FROM programmes WHERE id = ? AND DEPT_ID = ? LIMIT 1");
        if ($finalYearStmt) {
            mysqli_stmt_bind_param($finalYearStmt, 'ii', $p_code, $dept);
            mysqli_stmt_execute($finalYearStmt);
            $finalYearRes = mysqli_stmt_get_result($finalYearStmt);
            if ($finalYearRes && ($finalYearRow = mysqli_fetch_assoc($finalYearRes))) {
                // Check from year_6 down to year_1 to find the last non-zero year
                for ($year = 6; $year >= 1; $year--) {
                    $year_capacity = (int)($finalYearRow['year_' . $year . '_capacity'] ?? 0);
                    if ($year_capacity > 0) {
                        $final_year_intake = $year_capacity;
                        break;
                    }
                }
                // If no year-wise capacity found, fallback to intake_capacity
                if ($final_year_intake == 0) {
                    $final_year_intake = (int)($finalYearRow['intake_capacity'] ?? 0);
                }
                mysqli_free_result($finalYearRes);
            }
            mysqli_stmt_close($finalYearStmt);
        }
        // Use final year intake instead of total intake_capacity
        $no_of_students = $final_year_intake;
        $no_of_students_late_entry = (int)($_POST['no_of_students_late_entry'] ?? 0);
        $no_of_students_admitted_final_year = (int)($_POST['no_of_students_admitted_final_year'] ?? 0);
        $no_of_students_graduted = (int)($_POST['no_of_students_graduted'] ?? 0);
        $no_of_students_placed = (int)($_POST['no_of_students_placed'] ?? 0);
        $no_of_students_higher_studies = (int)($_POST['no_of_students_higher_studies'] ?? 0);
        $students_qualifying_exams = (int)($_POST['students_qualifying_exams'] ?? 0);
        
        // Validate that admitted final year students don't exceed final year intake
        if ($no_of_students_admitted_final_year > $final_year_intake) {
            $final_year_intake_escaped = htmlspecialchars((string)$final_year_intake, ENT_QUOTES, 'UTF-8');
            echo "<script>alert('No. of Students Admitted in Final Year cannot exceed Total Final Year Intake Count (" . $final_year_intake_escaped . ").');</script>"; exit;
        }

        // Server-side validation
        if ($no_of_students < 0 || $no_of_students_late_entry < 0 || $no_of_students_graduted < 0 || $no_of_students_placed < 0 || $no_of_students_higher_studies < 0 || $students_qualifying_exams < 0) {
            echo "<script>alert('Values cannot be negative.');</script>"; exit;
        }
        if ($no_of_students_graduted > $no_of_students) { echo "<script>alert('Graduated cannot exceed Total students.');</script>"; exit; }
        if ($no_of_students_placed > $no_of_students_graduted) { echo "<script>alert('Placed cannot exceed Graduated.');</script>"; exit; }
        if ($no_of_students_higher_studies > $no_of_students) { echo "<script>alert('Higher studies cannot exceed Total students.');</script>"; exit; }
        if ($students_qualifying_exams > $no_of_students) { echo "<script>alert('Qualifying exams cannot exceed Total students.');</script>"; exit; }

        // Detect optional columns (backward compatibility)
        $cols = [];
        $descRes = mysqli_query($conn, "DESCRIBE placement_details");
        if ($descRes) {
            while ($c = mysqli_fetch_assoc($descRes)) { $cols[] = $c['Field']; }
            mysqli_free_result($descRes); // CRITICAL: Free result set
        }
        $hasExams = in_array('STUDENTS_QUALIFYING_EXAMS', $cols, true);
        $hasFinalYear = in_array('NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR', $cols, true);

        // Get final year intake from programmes table (last non-zero year capacity)
        $final_year_intake = 0;
        $finalYearStmt = mysqli_prepare($conn, "SELECT year_1_capacity, year_2_capacity, year_3_capacity, year_4_capacity, year_5_capacity, year_6_capacity, intake_capacity FROM programmes WHERE id = ? AND DEPT_ID = ? LIMIT 1");
        if ($finalYearStmt) {
            mysqli_stmt_bind_param($finalYearStmt, 'ii', $p_code, $dept);
            mysqli_stmt_execute($finalYearStmt);
            $finalYearRes = mysqli_stmt_get_result($finalYearStmt);
            if ($finalYearRes && ($finalYearRow = mysqli_fetch_assoc($finalYearRes))) {
                // Check from year_6 down to year_1 to find the last non-zero year
                for ($year = 6; $year >= 1; $year--) {
                    $year_capacity = (int)($finalYearRow['year_' . $year . '_capacity'] ?? 0);
                    if ($year_capacity > 0) {
                        $final_year_intake = $year_capacity;
                        break;
                    }
                }
                // If no year-wise capacity found, fallback to intake_capacity
                if ($final_year_intake == 0) {
                    $final_year_intake = (int)($finalYearRow['intake_capacity'] ?? 0);
                }
                mysqli_free_result($finalYearRes);
            }
            mysqli_stmt_close($finalYearStmt);
        }
        // Use final year intake instead of total intake_capacity
        $no_of_students = $final_year_intake;
        $no_of_students_admitted_final_year = (int)($_POST['no_of_students_admitted_final_year'] ?? 0);
        
        // Validate that admitted final year students don't exceed final year intake
        if ($no_of_students_admitted_final_year > $final_year_intake) {
            $final_year_intake_escaped = htmlspecialchars((string)$final_year_intake, ENT_QUOTES, 'UTF-8');
            echo "<script>alert('No. of Students Admitted in Final Year cannot exceed Total Final Year Intake Count (" . $final_year_intake_escaped . ").');</script>"; exit;
        }

        // Build SQL dynamically based on available columns
        // UNIQUE KEY is on (A_YEAR, PROGRAM_NAME, DEPT_ID), so ON DUPLICATE KEY UPDATE will match on these
        if ($hasExams && $hasFinalYear) {
            $sql = "INSERT INTO placement_details
                (A_YEAR, DEPT_ID, PROGRAM_CODE, PROGRAM_NAME, TOTAL_NO_OF_STUDENT, NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY, NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR, TOTAL_NUM_OF_STUDENTS_GRADUATED, TOTAL_NUM_OF_STUDENTS_PLACED, NUM_OF_STUDENTS_IN_HIGHER_STUDIES, STUDENTS_QUALIFYING_EXAMS)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                PROGRAM_CODE = VALUES(PROGRAM_CODE),
                TOTAL_NO_OF_STUDENT = VALUES(TOTAL_NO_OF_STUDENT),
                NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY = VALUES(NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY),
                NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR = VALUES(NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR),
                TOTAL_NUM_OF_STUDENTS_GRADUATED = VALUES(TOTAL_NUM_OF_STUDENTS_GRADUATED),
                TOTAL_NUM_OF_STUDENTS_PLACED = VALUES(TOTAL_NUM_OF_STUDENTS_PLACED),
                NUM_OF_STUDENTS_IN_HIGHER_STUDIES = VALUES(NUM_OF_STUDENTS_IN_HIGHER_STUDIES),
                STUDENTS_QUALIFYING_EXAMS = VALUES(STUDENTS_QUALIFYING_EXAMS),
                updated_date = CURRENT_TIMESTAMP";
        } elseif ($hasExams) {
            $sql = "INSERT INTO placement_details
                (A_YEAR, DEPT_ID, PROGRAM_CODE, PROGRAM_NAME, TOTAL_NO_OF_STUDENT, NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY, TOTAL_NUM_OF_STUDENTS_GRADUATED, TOTAL_NUM_OF_STUDENTS_PLACED, NUM_OF_STUDENTS_IN_HIGHER_STUDIES, STUDENTS_QUALIFYING_EXAMS)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                PROGRAM_CODE = VALUES(PROGRAM_CODE),
                TOTAL_NO_OF_STUDENT = VALUES(TOTAL_NO_OF_STUDENT),
                NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY = VALUES(NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY),
                TOTAL_NUM_OF_STUDENTS_GRADUATED = VALUES(TOTAL_NUM_OF_STUDENTS_GRADUATED),
                TOTAL_NUM_OF_STUDENTS_PLACED = VALUES(TOTAL_NUM_OF_STUDENTS_PLACED),
                NUM_OF_STUDENTS_IN_HIGHER_STUDIES = VALUES(NUM_OF_STUDENTS_IN_HIGHER_STUDIES),
                STUDENTS_QUALIFYING_EXAMS = VALUES(STUDENTS_QUALIFYING_EXAMS),
                updated_date = CURRENT_TIMESTAMP";
        } elseif ($hasFinalYear) {
            $sql = "INSERT INTO placement_details
                (A_YEAR, DEPT_ID, PROGRAM_CODE, PROGRAM_NAME, TOTAL_NO_OF_STUDENT, NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY, NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR, TOTAL_NUM_OF_STUDENTS_GRADUATED, TOTAL_NUM_OF_STUDENTS_PLACED, NUM_OF_STUDENTS_IN_HIGHER_STUDIES)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                PROGRAM_CODE = VALUES(PROGRAM_CODE),
                TOTAL_NO_OF_STUDENT = VALUES(TOTAL_NO_OF_STUDENT),
                NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY = VALUES(NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY),
                NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR = VALUES(NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR),
                TOTAL_NUM_OF_STUDENTS_GRADUATED = VALUES(TOTAL_NUM_OF_STUDENTS_GRADUATED),
                TOTAL_NUM_OF_STUDENTS_PLACED = VALUES(TOTAL_NUM_OF_STUDENTS_PLACED),
                NUM_OF_STUDENTS_IN_HIGHER_STUDIES = VALUES(NUM_OF_STUDENTS_IN_HIGHER_STUDIES),
                updated_date = CURRENT_TIMESTAMP";
        } else {
            $sql = "INSERT INTO placement_details
                (A_YEAR, DEPT_ID, PROGRAM_CODE, PROGRAM_NAME, TOTAL_NO_OF_STUDENT, NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY, TOTAL_NUM_OF_STUDENTS_GRADUATED, TOTAL_NUM_OF_STUDENTS_PLACED, NUM_OF_STUDENTS_IN_HIGHER_STUDIES)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                PROGRAM_CODE = VALUES(PROGRAM_CODE),
                TOTAL_NO_OF_STUDENT = VALUES(TOTAL_NO_OF_STUDENT),
                NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY = VALUES(NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY),
                TOTAL_NUM_OF_STUDENTS_GRADUATED = VALUES(TOTAL_NUM_OF_STUDENTS_GRADUATED),
                TOTAL_NUM_OF_STUDENTS_PLACED = VALUES(TOTAL_NUM_OF_STUDENTS_PLACED),
                NUM_OF_STUDENTS_IN_HIGHER_STUDIES = VALUES(NUM_OF_STUDENTS_IN_HIGHER_STUDIES),
                updated_date = CURRENT_TIMESTAMP";
        }

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            $error_msg = mysqli_error($conn);
            $error_msg_escaped = htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8');
            echo "<script>alert('Server error preparing query: " . $error_msg_escaped . "'); window.location.href='PlacementDetails.php?error=save';</script>";
            exit;
        }
        if ($hasExams && $hasFinalYear) {
            mysqli_stmt_bind_param(
                $stmt,
                'siisiiiiiii',
                $A_YEAR,
                $dept,
                $p_code,
                $programme_name,
                $no_of_students,
                $no_of_students_late_entry,
                $no_of_students_admitted_final_year,
                $no_of_students_graduted,
                $no_of_students_placed,
                $no_of_students_higher_studies,
                $students_qualifying_exams
            );
        } elseif ($hasExams) {
            mysqli_stmt_bind_param(
                $stmt,
                'siisiiiiii',
                $A_YEAR,
                $dept,
                $p_code,
                $programme_name,
                $no_of_students,
                $no_of_students_late_entry,
                $no_of_students_graduted,
                $no_of_students_placed,
                $no_of_students_higher_studies,
                $students_qualifying_exams
            );
        } elseif ($hasFinalYear) {
            mysqli_stmt_bind_param(
                $stmt,
                'siisiiiiii',
                $A_YEAR,
                $dept,
                $p_code,
                $programme_name,
                $no_of_students,
                $no_of_students_late_entry,
                $no_of_students_admitted_final_year,
                $no_of_students_graduted,
                $no_of_students_placed,
                $no_of_students_higher_studies
            );
        } else {
            mysqli_stmt_bind_param(
                $stmt,
                'siisiiiii',
                $A_YEAR,
                $dept,
                $p_code,
                $programme_name,
                $no_of_students,
                $no_of_students_late_entry,
                $no_of_students_graduted,
                $no_of_students_placed,
                $no_of_students_higher_studies
            );
        }
        if (!mysqli_stmt_execute($stmt)) {
            $error_msg = mysqli_stmt_error($stmt);
            $error_msg_escaped = htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8');
            echo "<script>alert('Database error: " . $error_msg_escaped . "'); window.location.href='PlacementDetails.php?error=save';</script>";
            exit;
        }
        
        // CRITICAL: Clear and recalculate score cache after data save
        require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
        clearDepartmentScoreCache($dept, $A_YEAR, true);
        
        $affected = mysqli_stmt_affected_rows($stmt);
        safe_redirect('PlacementDetails.php?success=1');
    } else {
        safe_redirect('PlacementDetails.php?error=program');
    }
}

// Duplicate handler removed - already handled above before unified_header.php

if(isset($_GET['action'])) {
    $action=$_GET['action'];
if($action == 'delete') {
        $id = isset($_GET['ID']) ? (int)$_GET['ID'] : 0;
        $del = mysqli_prepare($conn, "DELETE FROM placement_details WHERE ID = ? AND DEPT_ID = ?");
        mysqli_stmt_bind_param($del, 'ii', $id, $dept);
        if (mysqli_stmt_execute($del)) { safe_redirect('PlacementDetails.php'); }
        safe_redirect('PlacementDetails.php?error=delete');
    }
}
?>
<div class="container-fluid" style="max-width: 100%; overflow-x: hidden;">
    <div class="main-content-area">
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-briefcase me-3"></i>Placement Details
                    </h1>
                    <p class="page-subtitle">Manage student placement information and statistics</p>
                </div>
                <a href="export_page_pdf.php?page=PlacementDetails" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">Data saved successfully.</div>
        <?php elseif (isset($_GET['updated'])): ?>
            <div class="alert alert-success">Record updated successfully.</div>
        <?php elseif (isset($_GET['error'])): ?>
            <div class="alert alert-danger">
                <?php 
                    $emap = [
                        'save' => 'There was an error saving data. Please try again.',
                        'program' => 'Invalid programme selected.',
                        'update' => 'There was an error updating the record.',
                        'delete' => 'There was an error deleting the record.',
                        'csrf' => 'Security validation failed. Please refresh the page and try again.'
                    ];
                    $code = $_GET['error'];
                    echo isset($emap[$code]) ? $emap[$code] : 'An error occurred.';
                ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form class="modern-form" method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" enctype="multipart/form-data" autocomplete="off">
                    <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                    <input type="hidden" name="record_id" id="record_id" value="">
                    <!-- Important Instructions -->
                    <div class="alert alert-warning">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i><b>Important Guidelines:</b></h5>
                        <ul class="mb-0">
                            <li><b>Enter accurate placement statistics for each program</b></li>
                            <li><b>Include all students who secured employment after graduation</b></li>
                            <li><b>Higher studies include students pursuing further education</b></li>
                            <li><b>Upload supporting documents for verification</b></li>
                        </ul>
                    </div>

                    <?php 
                    // Display Skip Form Button for departments with NO placement data
                    require_once(__DIR__ . '/skip_form_component.php');
                    $check_existing_query = "SELECT ID FROM placement_details WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1";
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
                    displaySkipFormButton('placement', 'Placement Details', $A_YEAR, $has_existing_data);
                    ?>

                    <?php if ($dept <= 0): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Error:</strong> Department ID not found. Please <a href="../unified_login.php">login again</a>.
                        </div>
                    <?php else: ?>
                        <!-- Form Status Alert -->
                        <?php if ($form_locked): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-lock me-2"></i><strong>Form Status:</strong> Data has been submitted for academic year <?php echo htmlspecialchars($A_YEAR, ENT_QUOTES, 'UTF-8'); ?>. 
                                Form is locked. Use "Edit" button to modify existing records.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><strong>Form Status:</strong> Form is ready for data entry for academic year <?php echo htmlspecialchars($A_YEAR, ENT_QUOTES, 'UTF-8'); ?>.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <!-- Program Selection Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-graduation-cap me-2"></i>Program Selection
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="program_select" class="form-label"><b>Select Program *</b></label>
                                    <select name="p_name" id="program_select" class="form-control" required <?php echo $form_locked ? 'disabled' : ''; ?>>
                                        <option value="">Select Program</option>
                                        <?php 
                                        // CRITICAL: Use prepared statement to prevent SQL injection
                                        $dept = (int)$dept; // Type cast for safety
                                        $sql = "SELECT id, programme_name, programme_type, intake_capacity, year_1_capacity, year_2_capacity, year_3_capacity, year_4_capacity, year_5_capacity, year_6_capacity FROM programmes WHERE DEPT_ID = ? ORDER BY programme_name ASC";
                                        $prog_stmt = mysqli_prepare($conn, $sql);
                                        if ($prog_stmt) {
                                            mysqli_stmt_bind_param($prog_stmt, 'i', $dept);
                                            mysqli_stmt_execute($prog_stmt);
                                            $result = mysqli_stmt_get_result($prog_stmt);
                                            if ($result) {
                                                while ($row = mysqli_fetch_assoc($result)) {
                                                    // Calculate final year intake (last non-zero year capacity)
                                                    $final_year_intake = 0;
                                                    // Check from year_6 down to year_1 to find the last non-zero year
                                                    for ($year = 6; $year >= 1; $year--) {
                                                        $year_capacity = (int)($row['year_' . $year . '_capacity'] ?? 0);
                                                        if ($year_capacity > 0) {
                                                            $final_year_intake = $year_capacity;
                                                            break;
                                                        }
                                                    }
                                                    // If no year-wise capacity found, fallback to intake_capacity
                                                    if ($final_year_intake == 0) {
                                                        $final_year_intake = (int)($row['intake_capacity'] ?? 0);
                                                    }
                                                    
                                                    $selected = (isset($p_name) && $p_name == $row['id']) ? 'selected' : '';
                                                    echo '<option value="' . htmlspecialchars($row['id']) . '" data-name="' . htmlspecialchars($row['programme_name']) . '" data-capacity="' . $final_year_intake . '" ' . $selected . '>' . htmlspecialchars($row['programme_name']) . ' (' . htmlspecialchars($row['programme_type']) . ')</option>';
                                                }
                                                mysqli_free_result($result); // CRITICAL: Free result set
                                            }
                                            mysqli_stmt_close($prog_stmt); // CRITICAL: Close statement
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="total_students" class="form-label"><b>Total Final Year Intake Count *</b></label>
                                    <input type="number" name="no_of_students" id="total_students" class="form-control" placeholder="Auto-filled from Final Year Intake" required min="0" max="999999" readonly>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="no_of_students_admitted_final_year" class="form-label"><b>No. of Students Admitted in Final Year *</b></label>
                                    <input type="number" name="no_of_students_admitted_final_year" id="no_of_students_admitted_final_year" class="form-control" placeholder="Enter Count (max: Final Year Intake)" required min="0" max="999999" onkeypress="return /[0-9]/.test(String.fromCharCode(event.keyCode));" onchange="validateFinalYearAdmitted()">
                                    <small class="form-text text-muted">Cannot exceed the Total Final Year Intake Count above</small>
                                    <div id="final_year_error" class="text-danger small mt-1" style="display: none;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Student Statistics Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-chart-bar me-2"></i>Student Statistics
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="no_of_students_late_entry" class="form-label"><b>No. of Students Admitted through Lateral Entry *</b></label>
                                    <input type="number" name="no_of_students_late_entry" id="no_of_students_late_entry" class="form-control" placeholder="Enter Count" required min="0" max="999999" onkeypress="return /[0-9]/.test(String.fromCharCode(event.keyCode));">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="no_of_students_graduted" class="form-label"><b>No. of Students Graduated (PASSED) *</b></label>
                                    <input type="number" name="no_of_students_graduted" id="no_of_students_graduted" class="form-control" placeholder="Enter Count" required min="0" max="999999" onkeypress="return /[0-9]/.test(String.fromCharCode(event.keyCode));">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="no_of_students_placed" class="form-label"><b>No. of Students Placed *</b></label>
                                    <input type="number" name="no_of_students_placed" id="no_of_students_placed" class="form-control" placeholder="Enter Count" required min="0" max="999999" onkeypress="return /[0-9]/.test(String.fromCharCode(event.keyCode));">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="no_of_students_higher_studies" class="form-label"><b>No. of Students in Higher Studies *</b></label>
                                    <input type="number" name="no_of_students_higher_studies" id="no_of_students_higher_studies" class="form-control" placeholder="Enter Count" required min="0" max="999999" onkeypress="return /[0-9]/.test(String.fromCharCode(event.keyCode));">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label for="students_qualifying_exams" class="form-label"><b>No. of Students Qualifying in State/National/International Level Examinations *</b></label>
                                    <input type="number" name="students_qualifying_exams" id="students_qualifying_exams" class="form-control" placeholder="Enter Count" required min="0" max="999999" onkeypress="return /[0-9]/.test(String.fromCharCode(event.keyCode));">
                                    <div class="form-text">Include NET/SLET/GATE/GMAT/CAT/GRE/TOEFL/IELTS/UPSC/MPSC/Bank/CA etc.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Document Upload Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-file-pdf me-2"></i>Supporting Documentation
                        </h3>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i><strong>Document Required:</strong> 
                            Upload supporting documents for placement statistics, exam qualifications, and higher studies information
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                <label for="placement_document_file" class="form-label"><b>Placement Document *</b></label>
                                    <input type="file" class="form-control mb-2" id="placement_document_file" name="placement_document_file" accept=".pdf">
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="uploadDocument('placement_document', 'placement_document_file', 'placement_upload_status')">
                                        <i class="fas fa-upload me-1"></i>Upload
                                    </button>
                                <div id="placement_upload_status" class="mt-2"></div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                <label for="exam_qualification_file" class="form-label"><b>Exam Qualification Document *</b></label>
                                    <input type="file" class="form-control mb-2" id="exam_qualification_file" name="exam_qualification_file" accept=".pdf">
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="uploadDocument('exam_qualification', 'exam_qualification_file', 'exam_upload_status')">
                                        <i class="fas fa-upload me-1"></i>Upload
                                    </button>
                                <div id="exam_upload_status" class="mt-2"></div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                <label for="higher_studies_file" class="form-label"><b>Higher Studies Document *</b></label>
                                    <input type="file" class="form-control mb-2" id="higher_studies_file" name="higher_studies_file" accept=".pdf">
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="uploadDocument('higher_studies', 'higher_studies_file', 'higher_studies_upload_status')">
                                        <i class="fas fa-upload me-1"></i>Upload
                                    </button>
                                <div id="higher_studies_upload_status" class="mt-2"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="text-center mt-4">
                        <div class="d-flex flex-wrap justify-content-center gap-3">
                            <button type="submit" id="submitBtn" class="btn btn-primary btn-lg px-5">
                                <i class="fas fa-paper-plane me-2"></i>Submit Data
                            </button>
                            <button type="button" id="updateBtn" class="btn btn-warning btn-lg px-5" style="display:none;" onclick="unlockFormForEdit()">
                                <i class="fas fa-edit me-2"></i>Update
                            </button>
                            <button type="button" id="cancelUpdateBtn" class="btn btn-secondary btn-lg px-5" style="display:none;" onclick="cancelUpdate()">
                                <i class="fas fa-times me-2"></i>Cancel Update
                            </button>
                            <button type="button" id="cancelEditBtn" class="btn btn-secondary btn-lg px-5" style="display:none;" onclick="cancelEdit()">Cancel Edit</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    <!-- Show Entered Data -->
    <div class="card">
        <div class="card-body">
            <h3 class="fs-4 mb-3 text-center" id="msg"><b>You Have Entered the Following Data</b></h3>
            <div class="table-responsive" style="max-width: 100%; overflow-x: auto;">
                <table class="table bg-white rounded shadow-sm table-hover" style="min-width: 1500px;">
                    <thead>
                        <tr>
                            <th scope="col">Academic Year</th>
                            <th scope="col">Program Name</th>
                            <th scope="col">Total Final Year Intake</th>
                            <th scope="col">Admitted in Final Year</th>
                            <th scope="col">No. of Students Admitted through Lateral Entry</th>
                            <th scope="col">No. of Students Graduated (PASSED)</th>
                            <th scope="col">No. of Students Placed</th>
                            <th scope="col">No. of Students in Higher Studies</th>
                            <th scope="col">Students Qualifying in Exams</th>
                            <th scope="col">Placement Document</th>
                            <th scope="col">Exam Qualification Document</th>
                            <th scope="col">Higher Studies Document</th>
                            <th scope="col">Edit</th>
                            <th scope="col">Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                // CRITICAL: Use prepared statement to prevent SQL injection
                $dept = (int)$dept; // Type cast for safety
                $record_query = "SELECT * FROM placement_details WHERE DEPT_ID = ? AND A_YEAR = ? ORDER BY id DESC";
                $record_stmt = mysqli_prepare($conn, $record_query);
                if ($record_stmt) {
                    mysqli_stmt_bind_param($record_stmt, 'is', $dept, $A_YEAR);
                    mysqli_stmt_execute($record_stmt);
                    $Record = mysqli_stmt_get_result($record_stmt);
                    if ($Record) {
                        while ($row = mysqli_fetch_array($Record)) {
                    ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['A_YEAR'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['PROGRAM_NAME'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['TOTAL_NO_OF_STUDENT'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR'] ?? '0', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['TOTAL_NUM_OF_STUDENTS_GRADUATED'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['TOTAL_NUM_OF_STUDENTS_PLACED'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['NUM_OF_STUDENTS_IN_HIGHER_STUDIES'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['STUDENTS_QUALIFYING_EXAMS'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php
                        // Get the actual programme_code from programmes table
                        $prog_code_query = "SELECT programme_code FROM programmes WHERE id = ? AND DEPT_ID = ? LIMIT 1";
                        $prog_code_stmt = mysqli_prepare($conn, $prog_code_query);
                        $actual_programme_code = '';
                        if ($prog_code_stmt) {
                            mysqli_stmt_bind_param($prog_code_stmt, 'ii', $row['PROGRAM_CODE'], $dept);
                            mysqli_stmt_execute($prog_code_stmt);
                            $prog_code_result = mysqli_stmt_get_result($prog_code_stmt);
                            if ($prog_code_result && ($prog_code_row = mysqli_fetch_assoc($prog_code_result))) {
                                $actual_programme_code = $prog_code_row['programme_code'];
                            }
                            mysqli_stmt_close($prog_code_stmt);
                        }
                        
                        // Get Placement document from supporting_documents table (serial_number = 1, program_id = programme_code string)
                        // Try with modified section_name first (includes _PROG_program_code), then fallback to original
                        $placement_query = "SELECT * FROM supporting_documents WHERE dept_id = ? AND serial_number = 1 AND program_id = ? AND (section_name = ? OR section_name = ?) AND academic_year = ? AND status = 'active' LIMIT 1";
                        $stmt_placement = mysqli_prepare($conn, $placement_query);
                        $placement_doc = null;
                        if ($stmt_placement && !empty($actual_programme_code)) {
                            $modified_section_name = 'Placement Details_PROG_' . $actual_programme_code;
                            $section_name_placement = 'Placement Details';
                            // Use A_YEAR from row (stored in database) - it should match the current academic year
                            $a_year_placement = $row['A_YEAR'] ?? $A_YEAR;
                            mysqli_stmt_bind_param($stmt_placement, 'issss', $dept, $actual_programme_code, $modified_section_name, $section_name_placement, $a_year_placement);
                            mysqli_stmt_execute($stmt_placement);
                            $placement_result = mysqli_stmt_get_result($stmt_placement);
                        $placement_doc = mysqli_fetch_assoc($placement_result);
                            mysqli_stmt_close($stmt_placement);
                        }
                        // Also try querying without programme_code match if document wasn't found (fallback)
                        // This handles cases where document was uploaded but programme_code might not match exactly
                        if (!$placement_doc && !empty($actual_programme_code)) {
                            $placement_query_fallback = "SELECT * FROM supporting_documents WHERE dept_id = ? AND serial_number = 1 AND section_name = 'Placement Details' AND academic_year = ? AND status = 'active' AND (program_id = ? OR program_id LIKE ?) LIMIT 1";
                            $stmt_placement_fallback = mysqli_prepare($conn, $placement_query_fallback);
                            if ($stmt_placement_fallback) {
                                $program_like = '%' . $actual_programme_code . '%';
                                $a_year_placement_fallback = $row['A_YEAR'];
                                mysqli_stmt_bind_param($stmt_placement_fallback, 'isss', $dept, $a_year_placement_fallback, $actual_programme_code, $program_like);
                                mysqli_stmt_execute($stmt_placement_fallback);
                                $placement_result_fallback = mysqli_stmt_get_result($stmt_placement_fallback);
                                $placement_doc = mysqli_fetch_assoc($placement_result_fallback);
                                mysqli_stmt_close($stmt_placement_fallback);
                            }
                        }

                        if ($placement_doc): ?>
                            <div class="document-actions">
                                <?php 
                                $placement_path = $placement_doc['file_path'];
                                // Ensure correct path format for view link
                                // Handle paths: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/placement_details/{program_code}/FILENAME.pdf
                                $project_root = dirname(__DIR__);
                                if ($placement_path && (strpos($placement_path, $project_root) === 0)) {
                                    $placement_path = str_replace($project_root . '/', '', $placement_path);
                                }
                                $placement_path = str_replace('\\', '/', $placement_path);
                                if (strpos($placement_path, 'uploads/') === 0) {
                                    $placement_path = '../' . $placement_path;
                                } elseif (strpos($placement_path, '../') !== 0 && strpos($placement_path, 'http') !== 0) {
                                    if (!empty($actual_programme_code) && strpos($placement_path, 'placement_details/' . $actual_programme_code . '/') === false) {
                                        $a_year_placement_display = $row['A_YEAR'];
                                        $filename = basename($placement_path);
                                        $placement_path = '../uploads/' . $a_year_placement_display . '/DEPARTMENT/' . $dept . '/placement_details/' . $actual_programme_code . '/' . $filename;
                                    } else {
                                        $placement_path = '../' . $placement_path;
                                    }
                                }
                                ?>
                                <a href="<?php echo htmlspecialchars($placement_path); ?>" target="_blank" class="btn btn-sm btn-outline-info me-1">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        <?php else: ?>
                            <small class="text-muted">No document</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        // Get Exam Qualification document from supporting_documents table (serial_number = 2, program_id = programme_code string)
                        // Reuse $actual_programme_code from above
                        $exam_query = "SELECT * FROM supporting_documents WHERE dept_id = ? AND serial_number = 2 AND program_id = ? AND (section_name = ? OR section_name = ?) AND academic_year = ? AND status = 'active' LIMIT 1";
                        $stmt_exam = mysqli_prepare($conn, $exam_query);
                        $exam_doc = null;
                        if ($stmt_exam && !empty($actual_programme_code)) {
                            $modified_section_name_exam = 'Exam Qualifications_PROG_' . $actual_programme_code;
                            $section_name_exam = 'Exam Qualifications';
                            // Use A_YEAR from row (stored in database) - it should match the current academic year
                            $a_year_exam = $row['A_YEAR'] ?? $A_YEAR;
                            mysqli_stmt_bind_param($stmt_exam, 'issss', $dept, $actual_programme_code, $modified_section_name_exam, $section_name_exam, $a_year_exam);
                            mysqli_stmt_execute($stmt_exam);
                            $exam_result = mysqli_stmt_get_result($stmt_exam);
                        $exam_doc = mysqli_fetch_assoc($exam_result);
                            mysqli_stmt_close($stmt_exam);
                        }
                        // Fallback query if document not found
                        if (!$exam_doc && !empty($actual_programme_code)) {
                            $exam_query_fallback = "SELECT * FROM supporting_documents WHERE dept_id = ? AND serial_number = 2 AND section_name = 'Exam Qualifications' AND academic_year = ? AND status = 'active' AND (program_id = ? OR program_id LIKE ?) LIMIT 1";
                            $stmt_exam_fallback = mysqli_prepare($conn, $exam_query_fallback);
                            if ($stmt_exam_fallback) {
                                $program_like = '%' . $actual_programme_code . '%';
                                $a_year_exam_fallback = $row['A_YEAR'];
                                mysqli_stmt_bind_param($stmt_exam_fallback, 'isss', $dept, $a_year_exam_fallback, $actual_programme_code, $program_like);
                                mysqli_stmt_execute($stmt_exam_fallback);
                                $exam_result_fallback = mysqli_stmt_get_result($stmt_exam_fallback);
                                $exam_doc = mysqli_fetch_assoc($exam_result_fallback);
                                mysqli_stmt_close($stmt_exam_fallback);
                            }
                        }

                        if ($exam_doc): ?>
                            <div class="document-actions">
                                <?php 
                                $exam_path = $exam_doc['file_path'];
                                // Ensure correct path format for view link
                                // Handle paths: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/placement_details/{program_code}/FILENAME.pdf
                                $project_root = dirname(__DIR__);
                                if ($exam_path && (strpos($exam_path, $project_root) === 0)) {
                                    $exam_path = str_replace($project_root . '/', '', $exam_path);
                                }
                                $exam_path = str_replace('\\', '/', $exam_path);
                                if (strpos($exam_path, 'uploads/') === 0) {
                                    $exam_path = '../' . $exam_path;
                                } elseif (strpos($exam_path, '../') !== 0 && strpos($exam_path, 'http') !== 0) {
                                    if (!empty($actual_programme_code) && strpos($exam_path, 'placement_details/' . $actual_programme_code . '/') === false) {
                                        $a_year_exam_display = $row['A_YEAR'];
                                        $filename = basename($exam_path);
                                        $exam_path = '../uploads/' . $a_year_exam_display . '/DEPARTMENT/' . $dept . '/placement_details/' . $actual_programme_code . '/' . $filename;
                                    } else {
                                        $exam_path = '../' . $exam_path;
                                    }
                                }
                                ?>
                                <a href="<?php echo htmlspecialchars($exam_path); ?>" target="_blank" class="btn btn-sm btn-outline-info me-1">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        <?php else: ?>
                            <small class="text-muted">No document</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        // Get Higher Studies document from supporting_documents table (serial_number = 3, program_id = programme_code string)
                        // Reuse $actual_programme_code from above
                        $higher_studies_query = "SELECT * FROM supporting_documents WHERE dept_id = ? AND serial_number = 3 AND program_id = ? AND (section_name = ? OR section_name = ?) AND academic_year = ? AND status = 'active' LIMIT 1";
                        $stmt_higher = mysqli_prepare($conn, $higher_studies_query);
                        $higher_studies_doc = null;
                        if ($stmt_higher && !empty($actual_programme_code)) {
                            $modified_section_name_higher = 'Higher Studies_PROG_' . $actual_programme_code;
                            $section_name_higher = 'Higher Studies';
                            // Use A_YEAR from row (stored in database) - it should match the current academic year
                            $a_year_higher = $row['A_YEAR'] ?? $A_YEAR;
                            mysqli_stmt_bind_param($stmt_higher, 'issss', $dept, $actual_programme_code, $modified_section_name_higher, $section_name_higher, $a_year_higher);
                            mysqli_stmt_execute($stmt_higher);
                            $higher_studies_result = mysqli_stmt_get_result($stmt_higher);
                        $higher_studies_doc = mysqli_fetch_assoc($higher_studies_result);
                            mysqli_stmt_close($stmt_higher);
                        }
                        // Fallback query if document not found
                        if (!$higher_studies_doc && !empty($actual_programme_code)) {
                            $higher_studies_query_fallback = "SELECT * FROM supporting_documents WHERE dept_id = ? AND serial_number = 3 AND section_name = 'Higher Studies' AND academic_year = ? AND status = 'active' AND (program_id = ? OR program_id LIKE ?) LIMIT 1";
                            $stmt_higher_fallback = mysqli_prepare($conn, $higher_studies_query_fallback);
                            if ($stmt_higher_fallback) {
                                $program_like = '%' . $actual_programme_code . '%';
                                $a_year_higher_fallback = $row['A_YEAR'];
                                mysqli_stmt_bind_param($stmt_higher_fallback, 'isss', $dept, $a_year_higher_fallback, $actual_programme_code, $program_like);
                                mysqli_stmt_execute($stmt_higher_fallback);
                                $higher_studies_result_fallback = mysqli_stmt_get_result($stmt_higher_fallback);
                                $higher_studies_doc = mysqli_fetch_assoc($higher_studies_result_fallback);
                                mysqli_stmt_close($stmt_higher_fallback);
                            }
                        }

                        if ($higher_studies_doc): ?>
                            <div class="document-actions">
                                <?php 
                                $hs_path = $higher_studies_doc['file_path'];
                                // Ensure correct path format for view link
                                // Handle paths: uploads/{A_YEAR}/DEPARTMENT/{dept_id}/placement_details/{program_code}/FILENAME.pdf
                                $project_root = dirname(__DIR__);
                                if ($hs_path && (strpos($hs_path, $project_root) === 0)) {
                                    $hs_path = str_replace($project_root . '/', '', $hs_path);
                                }
                                $hs_path = str_replace('\\', '/', $hs_path);
                                if (strpos($hs_path, 'uploads/') === 0) {
                                    $hs_path = '../' . $hs_path;
                                } elseif (strpos($hs_path, '../') !== 0 && strpos($hs_path, 'http') !== 0) {
                                    if (!empty($actual_programme_code) && strpos($hs_path, 'placement_details/' . $actual_programme_code . '/') === false) {
                                        $a_year_hs_display = $row['A_YEAR'];
                                        $filename = basename($hs_path);
                                        $hs_path = '../uploads/' . $a_year_hs_display . '/DEPARTMENT/' . $dept . '/placement_details/' . $actual_programme_code . '/' . $filename;
                                    } else {
                                        $hs_path = '../' . $hs_path;
                                    }
                                }
                                ?>
                                <a href="<?php echo htmlspecialchars($hs_path); ?>" target="_blank" class="btn btn-sm btn-outline-info me-1">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        <?php else: ?>
                            <small class="text-muted">No document</small>
                        <?php endif; ?>
                    </td>
                    <td><button type="button" class="dbutton" onclick="editRecord(<?php echo (int)$row['ID'];?>)">Edit</button></td>
                    <td><a class="dbutton" href="PlacementDetails.php?action=delete&ID=<?php echo (int)$row['ID']; ?>">Delete</a></td>
                </tr>
                <?php
                        }
                        mysqli_free_result($Record); // CRITICAL: Free result set
                    }
                    mysqli_stmt_close($record_stmt); // CRITICAL: Close statement
                }
                    ?>                            
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
<style>
    :root {
        --primary-color: #667eea;
        --secondary-color: #764ba2;
        --success-color: #28a745;
        --danger-color: #dc3545;
        --warning-color: #ffc107;
        --info-color: #17a2b8;
        --light-color: #f8f9fa;
        --dark-color: #343a40;
        --border-radius: 1rem;
        --box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.1);
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * {
        box-sizing: border-box;
    }

    body {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        min-height: 100vh;
        margin: 0;
        padding: 2rem 0;
    }

    .container-fluid {
        max-width: 100%;
        margin: 0 auto;
        padding: 0 1rem;
        overflow-x: hidden;
    }

    .div {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border-radius: var(--border-radius);
        box-shadow: var(--box-shadow);
        padding: 3rem;
        margin-bottom: 2rem;
        border: 1px solid rgba(255, 255, 255, 0.2);
        position: relative;
        overflow: hidden;
    }

    .div::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    }

    .form-label {
        font-weight: 600;
        color: var(--dark-color);
        margin-bottom: 0.75rem;
        font-size: 0.95rem;
        letter-spacing: 0.025em;
    }

    .form-control {
        border-radius: 0.75rem !important;
        border: 2px solid #e2e8f0 !important;
        background: #fff !important;
        font-size: 1rem;
        padding: 0.875rem 1.25rem !important;
        transition: var(--transition);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        font-weight: 500;
    }

    .form-control:focus {
        border-color: var(--primary-color) !important;
        box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.15) !important;
        background: #fff !important;
        transform: translateY(-2px);
    }

    .form-control:read-only {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%) !important;
        border-color: #dee2e6 !important;
        cursor: not-allowed;
    }

    .btn {
        font-weight: 600;
        letter-spacing: 0.05em;
        border-radius: 0.75rem;
        transition: var(--transition);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        border: none;
        padding: 0.75rem 1.5rem;
        font-size: 0.95rem;
        position: relative;
        overflow: hidden;
    }

    .btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s;
    }

    .btn:hover::before {
        left: 100%;
    }

    .btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
    }

    .btn-outline-primary {
        border: 2px solid var(--primary-color);
        color: var(--primary-color);
        background: transparent;
    }

    .btn-outline-primary:hover {
        background: var(--primary-color);
        color: white;
    }

    .btn-outline-info {
        border: 2px solid var(--info-color);
        color: var(--info-color);
        background: transparent;
    }

    .btn-outline-info:hover {
        background: var(--info-color);
        color: white;
    }

    .btn-outline-danger {
        border: 2px solid var(--danger-color);
        color: var(--danger-color);
        background: transparent;
    }

    .btn-outline-danger:hover {
        background: var(--danger-color);
        color: white;
    }

    .submit {
        background: linear-gradient(135deg, var(--success-color), #20c997);
        color: white;
        padding: 1rem 2rem;
        font-size: 1.1rem;
        border-radius: 0.75rem;
        border: none;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
        transition: var(--transition);
        width: 100%;
        margin-top: 2rem;
    }

    .submit:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(40, 167, 69, 0.4);
    }

    .upload-section {
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
        border: 2px solid rgba(102, 126, 234, 0.1);
        border-radius: var(--border-radius);
        padding: 2rem;
        margin-bottom: 2rem;
        position: relative;
        overflow: hidden;
    }

    .upload-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    }

    .upload-section h6 {
        color: var(--primary-color);
        font-weight: 700;
        font-size: 1.1rem;
        margin-bottom: 1rem;
    }

    .upload-status {
        margin-top: 1rem;
        font-size: 0.9rem;
        font-weight: 500;
    }

    .upload-status .text-success {
        color: var(--success-color) !important;
        font-weight: 600;
    }

    .upload-status .text-danger {
        color: var(--danger-color) !important;
        font-weight: 600;
    }

    .upload-status .text-info {
        color: var(--info-color) !important;
        font-weight: 600;
    }

    .document-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        justify-content: center;
    }

    .document-actions .btn {
        font-size: 0.8rem;
        padding: 0.5rem 1rem;
        min-width: auto;
        border-radius: 0.5rem;
    }

    .alert {
        border-radius: var(--border-radius);
        border: none;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .alert-info {
        background: linear-gradient(135deg, rgba(23, 162, 184, 0.1) 0%, rgba(23, 162, 184, 0.05) 100%);
        border-left: 4px solid var(--info-color);
        color: var(--dark-color);
    }

    .table {
        background: rgba(255, 255, 255, 0.9);
        border-radius: var(--border-radius);
        overflow: hidden;
        box-shadow: var(--box-shadow);
    }

    .table thead th {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        font-weight: 600;
        border: none;
        padding: 1rem;
        text-align: center;
        font-size: 0.9rem;
        letter-spacing: 0.025em;
    }

    .table tbody td {
        padding: 1rem;
        border-color: rgba(0, 0, 0, 0.05);
        vertical-align: middle;
        text-align: center;
    }

    .table tbody tr:hover {
        background: rgba(102, 126, 234, 0.05);
    }

    .dbutton {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.85rem;
        transition: var(--transition);
        display: inline-block;
    }

    .dbutton:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
        color: white;
        text-decoration: none;
    }

    .form-text {
        color: #6c757d;
        font-size: 0.85rem;
        margin-top: 0.5rem;
        font-weight: 500;
    }

    .fs-4 {
        font-size: 2rem !important;
        font-weight: 700;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 2rem;
    }

    .mb-3 {
        margin-bottom: 1.5rem !important;
    }

    .mb-4 {
        margin-bottom: 2rem !important;
    }

    .my-5 {
        margin: 3rem 0 !important;
    }

    @media (max-width: 768px) {
        .div {
            padding: 1.5rem;
            margin: 1rem;
        }
        
        .container-fluid {
            padding: 0 0.5rem;
        }
        
        .table {
            font-size: 0.85rem;
        }
        
        .table thead th,
        .table tbody td {
            padding: 0.75rem 0.5rem;
        }
    }

    /* Loading animation */
    .loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        border-top-color: #fff;
        animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    /* Success animation */
    @keyframes successPulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }

    .success-animation {
        animation: successPulse 0.6s ease-in-out;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function validateForm() {
        // Check if program is selected
        const programSelect = document.getElementById('program_select');
        if (!programSelect || !programSelect.value) {
            alert('Please select a program.');
            if (programSelect) programSelect.focus();
            return false;
        }
        
        // Ensure total auto-filled - remove readonly before validation
        const totalEl = document.getElementById('total_students');
        if (totalEl) {
            // Remove readonly attribute to allow form submission
            if (totalEl.hasAttribute('readonly')) {
            totalEl.removeAttribute('readonly');
            }
            // Validate that total students has a value
            const totalValue = Number(totalEl.value || 0);
            if (!totalEl.value || totalValue <= 0) {
                alert('Please enter Total No. of Students.');
                totalEl.focus();
                totalEl.required = true;
                return false;
            }
        } else {
            alert('Total students field not found. Please refresh the page.');
            return false;
        }
        
        // Basic numeric sanity checks (non-negative)
        const ids = ['no_of_students','no_of_students_late_entry','no_of_students_graduted','no_of_students_placed','no_of_students_higher_studies','students_qualifying_exams'];
        const fieldValues = {};
        for (const id of ids) {
            const el = document.querySelector(`[name="${id}"]`);
            if (el) {
                const v = Number(el.value || 0);
                fieldValues[id] = v;
                if (isNaN(v) || v < 0) {
                    // Show error in UI instead of alert
                    showFieldError(el, 'Please enter a non-negative number.');
                    el.focus();
                    return false;
                }
            }
        }
        
        // Logical validation checks - show errors on page instead of alert popups
        const totalStudents = fieldValues['no_of_students'] || 0;
        const graduated = fieldValues['no_of_students_graduted'] || 0;
        const placed = fieldValues['no_of_students_placed'] || 0;
        const higherStudies = fieldValues['no_of_students_higher_studies'] || 0;
        const qualifyingExams = fieldValues['students_qualifying_exams'] || 0;
        
        // Validate: Graduated cannot exceed Total students
        if (graduated > totalStudents) {
            const el = document.querySelector('[name="no_of_students_graduted"]');
            if (el) {
                showFieldError(el, 'Graduated students cannot exceed Total students (' + totalStudents + ').');
                el.focus();
                return false;
            }
        }
        
        // Validate: Placed cannot exceed Graduated
        if (placed > graduated) {
            const el = document.querySelector('[name="no_of_students_placed"]');
            if (el) {
                showFieldError(el, 'Placed students cannot exceed Graduated students (' + graduated + ').');
                el.focus();
                return false;
            }
        }
        
        // Validate: Higher studies cannot exceed Total students
        if (higherStudies > totalStudents) {
            const el = document.querySelector('[name="no_of_students_higher_studies"]');
            if (el) {
                showFieldError(el, 'Higher studies students cannot exceed Total students (' + totalStudents + ').');
                el.focus();
                return false;
            }
        }
        
        // Validate: Qualifying exams cannot exceed Total students
        if (qualifyingExams > totalStudents) {
            const el = document.querySelector('[name="students_qualifying_exams"]');
            if (el) {
                showFieldError(el, 'Qualifying exams students cannot exceed Total students (' + totalStudents + ').');
                el.focus();
                return false;
            }
        }
        
        return true;
    }

    // Validate final year admitted students
    function validateFinalYearAdmitted() {
        const finalYearField = document.getElementById('no_of_students_admitted_final_year');
        const totalField = document.getElementById('total_students');
        const errorDiv = document.getElementById('final_year_error');
        
        if (!finalYearField || !totalField) return;
        
        const finalYearIntake = parseInt(totalField.value) || 0;
        const admittedValue = parseInt(finalYearField.value) || 0;
        
        if (finalYearIntake > 0 && admittedValue > finalYearIntake) {
            if (errorDiv) {
                errorDiv.textContent = 'Cannot exceed Total Final Year Intake Count (' + finalYearIntake + ')';
                errorDiv.style.display = 'block';
            }
            finalYearField.setCustomValidity('Cannot exceed Total Final Year Intake Count');
            finalYearField.style.borderColor = '#dc3545';
        } else {
            if (errorDiv) {
                errorDiv.style.display = 'none';
            }
            finalYearField.setCustomValidity('');
            finalYearField.style.borderColor = '';
        }
    }
    
    // Auto-fill Total No. of Students from programme Intake Capacity
    // AND check if record already exists for this program
    (function wireProgrammeCapacity(){
        const sel = document.getElementById('program_select');
        const total = document.getElementById('total_students');
        if (!sel || !total) return;
        
        const applyCap = () => {
            const opt = sel.options[sel.selectedIndex];
            const cap = opt ? Number(opt.getAttribute('data-capacity') || 0) : 0;
            if (cap > 0) {
                total.value = cap;
                total.setAttribute('readonly', 'readonly');
                // Set max attribute for final year admitted field
                const finalYearField = document.getElementById('no_of_students_admitted_final_year');
                if (finalYearField) {
                    finalYearField.setAttribute('max', cap);
                    // Re-validate if there's already a value
                    validateFinalYearAdmitted();
                }
            } else {
                total.value = '';
                total.removeAttribute('readonly');
            }
        };
        
        // Check if record exists for selected program
        const checkExistingRecord = () => {
            const programCode = sel.value;
            const recordId = document.getElementById('record_id');
            const currentRecordId = recordId ? recordId.value : '';
            
            if (!programCode) {
                // If no program selected, clear edit mode
                if (currentRecordId) {
                    cancelEdit();
                }
                return;
            }
            
            // Skip check if we're already editing a record OR if we're loading a record (to prevent reload loops)
            if (currentRecordId || sel.hasAttribute('data-loading-record')) {
                return;
            }
            
            // Check if record exists
            fetch('?check_exists=1&program_code=' + programCode)
                .then(response => {
                    // First check if response is actually JSON
                    const contentType = response.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        return response.json();
                    } else {
                        // If not JSON, get text and try to parse
                        return response.text().then(text => {
                            throw new Error('Invalid response format - expected JSON but got HTML/text');
                        });
                    }
                })
                .then(data => {
                    if (data.success && data.exists && data.id) {
                        showNotification('This program already has placement data. Loading existing record...', 'info');
                        // Automatically load the existing record (will lock form)
                        setTimeout(() => {
                            editRecord(data.id);
                        }, 500);
                    } else {
                        // Record doesn't exist, ensure we're in insert mode
                        if (currentRecordId) {
                            // We were in edit mode but now selected a different program without existing record
                            // Clear the form completely
                            cancelEdit();
                            // Clear all form fields
                            const lateEntryField = document.getElementById('no_of_students_late_entry');
                            const graduatedField = document.getElementById('no_of_students_graduted');
                            const placedField = document.getElementById('no_of_students_placed');
                            const higherStudiesField = document.getElementById('no_of_students_higher_studies');
                            const examsField = document.getElementById('students_qualifying_exams');
                            if (lateEntryField) lateEntryField.value = '';
                            if (graduatedField) graduatedField.value = '';
                            if (placedField) placedField.value = '';
                            if (higherStudiesField) higherStudiesField.value = '';
                            if (examsField) examsField.value = '';
                            // Clear document actions
                            clearDocumentActions();
                        }
                    }
                })
                .catch(e => {
                    // Don't show error to user, just log it
                });
        };
        
        sel.addEventListener('change', function() {
            applyCap();
            // Reset document loading flag when program changes
            documentsLoadAttempted = false;
            // Check for existing record after a short delay to ensure capacity is set first
            setTimeout(checkExistingRecord, 100);
            // Load documents for selected program
            if (sel.value) {
                setTimeout(() => {
                    loadExistingDocuments(sel.value);
                }, 200);
            }
        });
        
        // initial set
        applyCap();
        
        // Load documents for initially selected program
        if (sel.value) {
            setTimeout(() => {
                loadExistingDocuments(sel.value);
            }, 500);
        }
    })();

    function uploadDocument(documentType, fileInputId, statusDivId) {
        const fileInput = document.getElementById(fileInputId);
        const statusDiv = document.getElementById(statusDivId);
        const programSelect = document.getElementById('program_select');

        if (!fileInput.files[0]) {
            showNotification('Please select a file to upload.', 'warning');
            return;
        }

        if (!programSelect.value) {
            showNotification('Please select a program first.', 'warning');
            return;
        }

        const formData = new FormData();
        formData.append('document', fileInput.files[0]);
        formData.append('upload_document', '1');
        formData.append('document_type', documentType);
        formData.append('program_code', programSelect.value);
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta && csrfMeta.content) {
            formData.append('csrf_token', csrfMeta.content);
        } else {
            showNotification('Security token missing. Please refresh the page.', 'error');
            return;
        }

        // Show loading animation
        statusDiv.innerHTML = '<span class="text-info"><div class="loading"></div> Uploading...</span>';

        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(response => {
                // CRITICAL #3: Handle empty responses in JS - return object, don't throw
                return response.text().then(text => {
                    const trimmed = text.trim();
                    
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
                });
            })
            .then(data => {
                // CRITICAL #3: Handle null/undefined data gracefully
                if (!data || typeof data !== 'object') {
                    statusDiv.innerHTML = '<span class="text-danger">❌ Error: Invalid server response</span>';
                    showNotification('Upload failed: Invalid server response', 'error');
                    return;
                }
                
                // Re-enable controls
                fileInput.disabled = false;
                
                if (data.success && data.file_path) {
                    const viewPath = data.file_path.startsWith('../') ? data.file_path : '../' + data.file_path;
                    // Use document_id from response, or generate temporary ID if not available
                    // CRITICAL: Ensure documentId is valid - check for undefined/null/empty
                    let documentId = data.document_id || data.id;
                    if (!documentId || documentId === 'undefined' || documentId === 'null' || documentId === '') {
                        // Generate temporary ID if no valid ID provided
                        documentId = 'temp_' + Date.now();
                    } else {
                        // Ensure documentId is a valid number or temp string
                        const docIdNum = parseInt(documentId, 10);
                        if (isNaN(docIdNum) || docIdNum <= 0) {
                            // If not a valid number, treat as temp
                            documentId = 'temp_' + Date.now();
                        } else {
                            documentId = docIdNum; // Use numeric ID
                        }
                    }
                    
                    // Check if form is locked (Update button visible means form is locked)
                    const updateBtn = document.getElementById('updateBtn');
                    const isFormLocked = updateBtn && updateBtn.style.display !== 'none';
                    
                    // Build view and delete buttons based on lock state
                    // CRITICAL: Escape viewPath and documentId to prevent JavaScript syntax errors
                    const escapedViewPath = String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    const escapedDocumentId = String(documentId).replace(/'/g, "\\'").replace(/"/g, '\\"');
                    let viewButton = `<a href="${escapedViewPath}" target="_blank" class="btn btn-sm btn-outline-primary me-2" rel="noopener noreferrer"><i class="fas fa-eye"></i> View</a>`;
                    
                    // Only show delete button if form is unlocked
                    let deleteButton = '';
                    if (!isFormLocked) {
                        deleteButton = `<button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteDocument('${escapedDocumentId}')"><i class="fas fa-trash"></i> Delete</button>`;
                    }
                    
                    statusDiv.innerHTML = `
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-success">✅ Document uploaded</span>
                            ${viewButton}
                            ${deleteButton}
                        </div>
                    `;
                    statusDiv.className = 'mt-2 text-success';
                    
                    fileInput.value = ''; // Clear the file input
                    showNotification('Document uploaded successfully!', 'success');
                } else {
                    // Show message from server if available, otherwise default message
                    const message = data.message || 'Upload failed. Please try again.';
                    statusDiv.innerHTML = `<span class="text-danger">❌ ${message}</span>`;
                    statusDiv.className = 'mt-2 text-danger';
                    showNotification('Upload failed: ' + message, 'error');
                }
            })
            .catch(error => {
                // CRITICAL #3: Handle errors gracefully - return object, don't throw
                statusDiv.innerHTML = '<span class="text-danger">❌ Upload failed: ' + (error.message || 'Unknown error') + '</span>';
                statusDiv.className = 'mt-2 text-danger';
                showNotification('Upload failed: ' + (error.message || 'Unknown error'), 'error');
                fileInput.disabled = false;
            });
    }

    function deleteDocument(documentId) {
        // Convert to string for consistent handling
        const docIdStr = String(documentId);
                    
        // Handle both valid document IDs and temporary IDs (from upload before form submission)
        if (!documentId || documentId === 0 || documentId === '0' || docIdStr.startsWith('temp_')) {
            // Temporary document - just clear from UI
            const statusDivs = document.querySelectorAll('[id*="_status"]');
            statusDivs.forEach(div => {
                // Check for both quoted and unquoted documentId in onclick
                const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
                if (deleteBtn || (div.textContent && div.textContent.includes('Document uploaded'))) {
                    div.innerHTML = '<span class="text-muted">No document uploaded</span>';
                    div.className = 'mt-2 text-muted';
                }
            });
            showNotification('Document removed successfully', 'success');
            return;
        }
        
        if (!confirm('Are you sure you want to delete this document?')) {
            return;
                    }
                    
        // Show loading state
        const statusDivs = document.querySelectorAll('[id*="_status"]');
        statusDivs.forEach(div => {
            // Check for both quoted and unquoted documentId in onclick
            const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
            if (deleteBtn) {
                deleteBtn.disabled = true;
                deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            }
        });
        
        // Convert to integer for server request - handle string documentIds
        let docIdInt;
        if (typeof documentId === 'string' && documentId.trim() !== '') {
            docIdInt = parseInt(documentId.trim(), 10);
        } else if (typeof documentId === 'number') {
            docIdInt = Math.floor(documentId);
        } else {
            showNotification('Invalid document ID: ' + documentId, 'error');
            // Re-enable buttons
            statusDivs.forEach(div => {
                const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
                if (deleteBtn) {
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
                }
            });
            return;
        }
        
        if (isNaN(docIdInt) || docIdInt <= 0) {
            showNotification('Invalid document ID: ' + documentId, 'error');
            // Re-enable buttons
            statusDivs.forEach(div => {
                const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
                if (deleteBtn) {
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
                }
            });
            return;
        }
        
        fetch('?delete_document=1&document_id=' + docIdInt)
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
                // Re-enable delete buttons
                statusDivs.forEach(div => {
                    // Check for both quoted and unquoted documentId in onclick
                    const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
                    if (deleteBtn) {
                        deleteBtn.disabled = false;
                        deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
                    }
                });
                
                // CRITICAL #3: Handle null/undefined data gracefully
                if (!data || typeof data !== 'object') {
                    showNotification('Delete failed: Invalid server response', 'error');
                    return;
                }
                
                    if (data.success) {
                        showNotification('Document deleted successfully!', 'success');
                    
                    // Remove the document from the UI
                        statusDivs.forEach(div => {
                        // Check for both quoted and unquoted documentId in onclick
                        const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
                        if (deleteBtn || (div.textContent && div.textContent.includes('Document uploaded'))) {
                                div.innerHTML = '<span class="text-muted">No document uploaded</span>';
                            div.className = 'mt-2 text-muted';
                            }
                        });
                    } else {
                    // Show message from server if available, otherwise default message
                    const message = data.message || 'Delete failed. Please try again.';
                    showNotification('Delete failed: ' + message, 'error');
                    }
                })
                .catch(error => {
                // CRITICAL #5: Handle errors gracefully - return object, don't throw
                
                // Re-enable delete buttons on error
                statusDivs.forEach(div => {
                    // Check for both quoted and unquoted documentId in onclick
                    const deleteBtn = div.querySelector(`button[onclick*="deleteDocument('${docIdStr}')"], button[onclick*="deleteDocument(${docIdStr})"]`);
                    if (deleteBtn) {
                        deleteBtn.disabled = false;
                        deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
                    }
                });
                
                // Show error notification
                const errorMsg = error && error.message ? error.message : 'Unknown error';
                showNotification('Delete failed: ' + errorMsg, 'error');
                
                // Return resolved promise to prevent unhandled promise rejection
                return Promise.resolve({ success: false, message: 'Delete failed: ' + errorMsg });
                });
    }

    // Function to show field validation errors inline
    function showFieldError(field, message) {
        // Remove any existing error message for this field
        clearFieldError(field);
        
        // Create error message element
        const errorDiv = document.createElement('div');
        errorDiv.className = 'field-error text-danger small mt-1';
        errorDiv.style.color = '#dc3545';
        errorDiv.style.fontSize = '0.875rem';
        errorDiv.style.display = 'block';
        errorDiv.textContent = message;
        
        // Add red border to field
        if (field) {
            field.style.borderColor = '#dc3545';
            field.style.borderWidth = '2px';
            
            // Insert error message after the field's parent (assuming it's in a div)
            const parent = field.closest('.form-group') || field.parentElement;
            if (parent) {
                parent.appendChild(errorDiv);
            }
        }
    }
    
    // Function to clear field error
    function clearFieldError(field) {
        if (!field) return;
        
        // Remove error message
        const parent = field.closest('.form-group') || field.parentElement;
        if (parent) {
            const errorDiv = parent.querySelector('.field-error');
            if (errorDiv) {
                errorDiv.remove();
            }
        }
        
        // Remove red border
        field.style.borderColor = '';
        field.style.borderWidth = '';
    }
    
    // Clear all field errors when user starts typing
    document.addEventListener('DOMContentLoaded', function() {
        const inputs = document.querySelectorAll('input[type="number"], input[type="text"]');
        inputs.forEach(input => {
            input.addEventListener('input', function() {
                clearFieldError(this);
            });
        });
    });
    
    // Load existing documents function (like ExecutiveDevelopment.php)
    let documentsLoading = false;
    let documentsLoadAttempted = false;

    function loadExistingDocuments(programCode) {
        if (!programCode) {
            return;
        }
        
        if (documentsLoading || documentsLoadAttempted) {
            return; 
        }
        documentsLoading = true;
        documentsLoadAttempted = true;
        
        // CRITICAL: Add request timeout with AbortController
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
        
        // CRITICAL: Use consolidated endpoint - ONE request instead of individual calls
        fetch('?check_all_docs=1&program_code=' + encodeURIComponent(programCode), {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            cache: 'no-cache',
            signal: controller.signal
        })
        .then(response => {
            clearTimeout(timeoutId);
            if (!response.ok) {
                documentsLoading = false;
                documentsLoadAttempted = false;
                return { success: false, message: 'HTTP error: ' + response.status, documents: {} };
            }
            return response.text().then(text => {
                const trimmed = text.trim();
                if (!trimmed) {
                    documentsLoading = false;
                    documentsLoadAttempted = false;
                    return { success: false, message: 'Empty server response', documents: {} };
                }
                try {
                    return JSON.parse(trimmed);
                } catch (e) {
                    documentsLoading = false;
                    documentsLoadAttempted = false;
                    return { success: false, message: 'Invalid server response', documents: {} };
                }
            });
        })
        .catch(error => {
            clearTimeout(timeoutId);
            documentsLoading = false;
            documentsLoadAttempted = false;
            if (error.name === 'AbortError') {
                return { success: false, message: 'Request timeout', documents: {} };
            }
            return { success: false, message: 'Network error: ' + (error.message || 'Unknown error'), documents: {} };
        })
        .then(data => {
            if (data.success && data.documents) {
                // Map of fileId to srno for updating UI (matching the actual file input IDs)
                const documentMap = {
                    'placement_document_file': 1,
                    'exam_qualification_file': 2,
                    'higher_studies_file': 3
                };
    
                // Update all document statuses from batch response
                // data.documents is an object keyed by serial_number (srno)
                Object.keys(documentMap).forEach(fileId => {
                    const srno = documentMap[fileId];
                    const docData = data.documents[srno];
                    if (docData && docData.success) {
                        // Update both upload_status divs and document_actions containers
                        const statusDiv = document.getElementById('placement_upload_status');
                        const examStatusDiv = document.getElementById('exam_upload_status');
                        const higherStatusDiv = document.getElementById('higher_studies_upload_status');
                        
                        if (srno === 1 && statusDiv) {
                            updateDocumentStatusUI('placement_document', srno, docData.file_path, docData.file_name, statusDiv);
                        } else if (srno === 2 && examStatusDiv) {
                            updateDocumentStatusUI('exam_qualification', srno, docData.file_path, docData.file_name, examStatusDiv);
                        } else if (srno === 3 && higherStatusDiv) {
                            updateDocumentStatusUI('higher_studies', srno, docData.file_path, docData.file_name, higherStatusDiv);
                        }
                    }
                });
            }
            documentsLoading = false;
        })
        .catch(error => {
            console.error('Network error loading all documents:', error);
            documentsLoading = false;
            documentsLoadAttempted = false;
        });
    }

    // Helper function to update document status UI from consolidated response (like ExecutiveDevelopment.php)
    function updateDocumentStatusUI(fileId, srno, filePath, fileName, statusDivElement) {
        const statusDiv = statusDivElement || document.getElementById(fileId + '_upload_status');
        if (!statusDiv) return;

        const fileInput = document.getElementById(fileId + '_file');
        const updateBtn = document.getElementById('updateBtn');
        const isFormLocked = updateBtn && updateBtn.style.display !== 'none';
        
        let actionButtons = '';
        if (isFormLocked) {
            // Form is locked, only show view button
            actionButtons = '';
        } else {
            // Form is unlocked, show delete and view buttons
            // CRITICAL: Escape fileId to prevent JavaScript syntax errors
            const escapedFileId = String(fileId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            actionButtons = `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocument('${escapedFileId}', ${srno})">
                <i class="fas fa-trash"></i> Delete
            </button>`;
        }
        
        let viewPath = filePath || '';
        // Normalize path for web access (same robust logic as ExecutiveDevelopment.php)
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
        const escapedViewPath = viewPath ? String(viewPath).replace(/"/g, '&quot;').replace(/'/g, '&#39;') : '';
        const viewButton = escapedViewPath ? `<a href="${escapedViewPath}" target="_blank" class="btn btn-sm btn-outline-primary ms-1" rel="noopener noreferrer">
                <i class="fas fa-eye"></i> View
            </a>` : '';
        
        statusDiv.innerHTML = `
            <div class="d-flex align-items-center flex-wrap">
                <i class="fas fa-check-circle text-success me-2"></i>
                <span class="text-success me-2">Document uploaded</span>
                ${actionButtons}
                ${viewButton}
            </div>
        `;
        statusDiv.className = 'mt-2 text-success';
    }

    function showNotification(message, type = 'info') {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.notification');
        existingNotifications.forEach(notification => notification.remove());

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            color: white;
            font-weight: 600;
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            max-width: 300px;
        `;

        // Set background color based on type
        const colors = {
            success: 'linear-gradient(135deg, #28a745, #20c997)',
            error: 'linear-gradient(135deg, #dc3545, #e74c3c)',
            warning: 'linear-gradient(135deg, #ffc107, #f39c12)',
            info: 'linear-gradient(135deg, #17a2b8, #3498db)'
        };
        notification.style.background = colors[type] || colors.info;

        notification.innerHTML = `
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: white; font-size: 1.2rem; cursor: pointer; margin-left: auto;">×</button>
            </div>
        `;

        // Add to page
        document.body.appendChild(notification);

        // Animate in
        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 100);

        // Auto remove after 3 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }
        }, 3000);
    }

    // Inline edit: load record and populate form
    function editRecord(id) {
        fetch('?fetch=1&id=' + id)
            .then(response => {
                // First check if response is actually JSON
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    return response.json();
                } else {
                    // If not JSON, get text and try to parse
                    return response.text().then(text => {
                        throw new Error('Invalid response format - expected JSON but got HTML/text');
                    });
                }
            })
            .then(data => {
                if (!data.success) { 
                    showNotification(data.message || 'Failed to load record', 'error'); 
                    return; 
                }
                const rec = data.record;
                // Set record id and lock form
                document.getElementById('record_id').value = rec.ID;
                currentRecordId = rec.ID;
                
                // Set programme select based on PROGRAM_CODE (which is actually the program ID)
                const sel = document.getElementById('program_select');
                if (sel) {
                    // Find the option that matches the PROGRAM_CODE (program ID)
                    const options = sel.options;
                    let foundOption = null;
                    for (let i = 0; i < options.length; i++) {
                        if (options[i].value == rec.PROGRAM_CODE) {
                            foundOption = options[i];
                            break;
                        }
                    }
                    
                    if (foundOption) {
                        // Set flag to prevent checkExistingRecord from running when we set the value
                        sel.setAttribute('data-loading-record', 'true');
                    sel.value = rec.PROGRAM_CODE;
                        // Program select should remain enabled and changeable (user requirement)
                        sel.disabled = false;
                        sel.removeAttribute('readonly');
                        sel.style.backgroundColor = '';
                        // Manually set capacity without triggering change event (to avoid checkExistingRecord loop)
                        setTimeout(() => {
                            const opt = sel.options[sel.selectedIndex];
                            const cap = opt ? Number(opt.getAttribute('data-capacity') || 0) : 0;
                            const totalEl = document.getElementById('total_students');
                            if (totalEl && cap > 0) {
                                totalEl.value = cap;
                                totalEl.setAttribute('readonly', 'readonly');
                            }
                            // Remove flag after a delay
                            setTimeout(() => {
                                sel.removeAttribute('data-loading-record');
                            }, 200);
                        }, 50);
                    } else {
                        // Still keep it enabled
                        sel.disabled = false;
                        sel.removeAttribute('readonly');
                        sel.style.backgroundColor = '';
                        sel.removeAttribute('data-loading-record');
                }
                }
                
                // Store original data for cancel update functionality
                window.originalRecordData = JSON.parse(JSON.stringify(rec));
                window.originalDocumentsData = data.documents ? JSON.parse(JSON.stringify(data.documents)) : null;
                
                // Populate numeric fields
                setValue('no_of_students', rec.TOTAL_NO_OF_STUDENT);
                setValue('no_of_students_admitted_final_year', rec.NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR ?? 0);
                setValue('no_of_students_late_entry', rec.NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY);
                setValue('no_of_students_graduted', rec.TOTAL_NUM_OF_STUDENTS_GRADUATED);
                setValue('no_of_students_placed', rec.TOTAL_NUM_OF_STUDENTS_PLACED);
                setValue('no_of_students_higher_studies', rec.NUM_OF_STUDENTS_IN_HIGHER_STUDIES);
                setValue('students_qualifying_exams', rec.STUDENTS_QUALIFYING_EXAMS ?? 0);
                
                // LOCK FORM - Make all fields readonly
                lockFormForEdit();
                
                // Display existing documents with view/delete buttons
                if (data.documents) {
                    displayDocument('placement', data.documents.placement, 'placement_document_actions');
                    displayDocument('exam', data.documents.exam, 'exam_document_actions');
                    displayDocument('higher', data.documents.higher, 'higher_document_actions');
                } else {
                    // Clear document actions if no documents
                    clearDocumentActions();
                }
                
                // Also load documents in upload status divs (like ExecutiveDevelopment.php)
                // Get program code from selected option
                const programSelect = document.getElementById('program_select');
                if (programSelect && programSelect.value) {
                    // Reset document loading flag to allow reload
                    if (typeof documentsLoadAttempted !== 'undefined') {
                        documentsLoadAttempted = false;
                    }
                    setTimeout(() => {
                        if (typeof loadExistingDocuments === 'function') {
                            loadExistingDocuments(programSelect.value);
                        }
                    }, 300);
                }
                
                // Toggle buttons - Show Update button, hide Submit
                const submitBtn = document.getElementById('submitBtn');
                const updateBtn = document.getElementById('updateBtn');
                const cancelBtn = document.getElementById('cancelEditBtn');
                if (submitBtn) submitBtn.style.display = 'none';
                if (updateBtn) updateBtn.style.display = 'inline-block';
                if (cancelBtn) cancelBtn.style.display = 'inline-block';
                
                // Scroll to form
                window.scrollTo({ top: 0, behavior: 'smooth' });
            })
            .catch(e => {
                showNotification('Failed to load record: ' + e.message, 'error');
            });
    }

    function setValue(name, value) {
        const el = document.querySelector(`[name="${name}"]`);
        if (el) el.value = value ?? '';
    }

    // Display document with view/delete buttons based on form lock state
    function displayDocument(type, doc, containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        if (doc && doc.id && doc.path) {
            // Check if form is locked (Update button visible means form is locked)
            const updateBtn = document.getElementById('updateBtn');
            const isFormLocked = updateBtn && updateBtn.style.display !== 'none';
            
            // CRITICAL: Escape paths and IDs to prevent XSS and JavaScript syntax errors
            const escapedPath = String(doc.path).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            const escapedName = String(doc.name || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            const escapedContainerId = String(containerId).replace(/'/g, "\\'").replace(/"/g, '\\"');
            
            let actionButtons = '';
            if (isFormLocked) {
                // Form is locked - only show View button
                actionButtons = `
                    <a href="${escapedPath}" target="_blank" class="btn btn-sm btn-outline-primary ms-1" rel="noopener noreferrer">
                        <i class="fas fa-eye"></i> View
                    </a>
                `;
            } else {
                // Form is unlocked - show both View and Delete buttons
                actionButtons = `
                    <a href="${escapedPath}" target="_blank" class="btn btn-sm btn-outline-primary ms-1" rel="noopener noreferrer">
                        <i class="fas fa-eye"></i> View
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="deleteDocumentInEdit(${doc.id}, '${escapedContainerId}')">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                `;
            }
            
            container.innerHTML = `
                <div class="document-actions mt-2 p-2 border rounded bg-light">
                    <small class="text-muted d-block mb-2"><strong>Current document:</strong></small>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <span class="text-truncate d-inline-block" style="max-width: calc(100% - 180px); min-width: 0;" title="${escapedName}">
                            <i class="fas fa-file-pdf text-danger me-1"></i>${escapedName}
                        </span>
                        <div class="d-flex gap-2 ms-auto flex-shrink-0">
                            ${actionButtons}
                        </div>
                    </div>
                </div>
            `;
        } else {
            container.innerHTML = '';
        }
    }

    // Check if form has any data entered (to prevent data loss on reload)
    function checkFormHasData() {
        const fields = [
            'no_of_students',
            'no_of_students_late_entry',
            'no_of_students_graduted',
            'no_of_students_placed',
            'no_of_students_higher_studies',
            'students_qualifying_exams'
        ];
        
        for (const fieldName of fields) {
            const field = document.querySelector(`[name="${fieldName}"]`);
            if (field && field.value && field.value.trim() !== '' && Number(field.value) > 0) {
                return true;
            }
        }
        
        return false;
    }
    
    // Refresh document display after upload (fetches from server to get correct ID)
    function refreshDocumentDisplay(recordId, docType, containerId) {
        fetch('?fetch=1&id=' + recordId)
            .then(response => {
                return response.text().then(text => {
                    const trimmed = text.trim();
                    if (!trimmed) {
                        return { success: false, message: 'Empty server response', documents: null };
                    }
                    try {
                        return JSON.parse(trimmed);
                    } catch (e) {
                        return { success: false, message: 'Invalid server response', documents: null };
                    }
                });
            })
            .then(data => {
                if (data.success && data.documents) {
                    const docMap = {
                        'placement': data.documents.placement,
                        'exam': data.documents.exam,
                        'higher': data.documents.higher
                    };
                    const doc = docMap[docType];
                    if (doc && containerId) {
                        displayDocument(docType, doc, containerId);
                    }
                }
            })
            .catch(e => {
                // Silently fail
            });
    }
    
    // Clear all document actions (used when canceling edit)
    function clearDocumentActions() {
        const placementContainer = document.getElementById('placement_document_actions');
        const examContainer = document.getElementById('exam_document_actions');
        const higherContainer = document.getElementById('higher_document_actions');
        if (placementContainer) placementContainer.innerHTML = '';
        if (examContainer) examContainer.innerHTML = '';
        if (higherContainer) higherContainer.innerHTML = '';
    }

    // Delete document in edit mode (reloads the edit view to refresh document list)
    function deleteDocumentInEdit(documentId, containerId) {
        // Convert to string for consistent handling
        const docIdStr = String(documentId);
        
        // Handle both valid document IDs and temporary IDs (from upload before form submission)
        if (!documentId || documentId === 0 || documentId === '0' || docIdStr.startsWith('temp_')) {
            // Temporary document - just clear from UI
            const container = document.getElementById(containerId);
            if (container) {
                container.innerHTML = '';
            }
            showNotification('Document removed successfully', 'success');
            return;
        }
        
        if (!confirm('Are you sure you want to delete this document?')) {
            return;
        }
        
        // Show loading state
        const container = document.getElementById(containerId);
        if (container) {
            container.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Deleting...';
        }
        
        // Convert to integer for server request - handle string documentIds
        let docIdInt;
        if (typeof documentId === 'string' && documentId.trim() !== '') {
            docIdInt = parseInt(documentId.trim(), 10);
        } else if (typeof documentId === 'number') {
            docIdInt = Math.floor(documentId);
        } else {
            showNotification('Invalid document ID: ' + documentId, 'error');
            if (container) {
                container.innerHTML = '';
            }
            return;
        }
        
        if (isNaN(docIdInt) || docIdInt <= 0) {
            showNotification('Invalid document ID: ' + documentId, 'error');
            if (container) {
                container.innerHTML = '';
            }
            return;
        }
        
        fetch('?delete_document=1&document_id=' + docIdInt)
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
                    showNotification('Delete failed: Invalid server response', 'error');
                    if (container) {
                        container.innerHTML = '';
                    }
                    return;
                }
                
                    if (data.success) {
                        showNotification('Document deleted successfully!', 'success');
                        // Reload the record to refresh document list
                        const recordId = document.getElementById('record_id').value;
                        if (recordId) {
                            setTimeout(() => {
                                editRecord(recordId);
                            }, 500);
                    } else {
                        // Clear container if no record ID
                        if (container) {
                            container.innerHTML = '';
                        }
                        }
                    } else {
                    // Show message from server if available, otherwise default message
                    const message = data.message || 'Delete failed. Please try again.';
                    showNotification('Delete failed: ' + message, 'error');
                    if (container) {
                        container.innerHTML = '';
                    }
                    }
                })
                .catch(error => {
                // CRITICAL #5: Handle errors gracefully - return object, don't throw
                
                if (container) {
                    container.innerHTML = '';
                }
                
                // Show error notification
                const errorMsg = error && error.message ? error.message : 'Unknown error';
                showNotification('Delete failed: ' + errorMsg, 'error');
                
                // Return resolved promise to prevent unhandled promise rejection
                return Promise.resolve({ success: false, message: 'Delete failed: ' + errorMsg });
            });
    }

    // Lock form for edit mode (readonly state)
    function lockFormForEdit() {
        // Keep total_students readonly (auto-filled from final year intake)
        const totalField = document.getElementById('no_of_students');
        if (totalField) {
            totalField.setAttribute('readonly', 'readonly');
            totalField.style.backgroundColor = '#f8f9fa';
        }
        
        // Lock all other fields
        const fieldsToLock = ['no_of_students_admitted_final_year', 'no_of_students_late_entry', 'no_of_students_graduted', 
                             'no_of_students_placed', 'no_of_students_higher_studies', 'students_qualifying_exams'];
        fieldsToLock.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.setAttribute('readonly', 'readonly');
                field.style.backgroundColor = '#f0f0f0';
                field.style.cursor = 'not-allowed';
            }
        });
        
        // Lock program select
        const sel = document.getElementById('program_select');
        if (sel) {
            sel.setAttribute('readonly', 'readonly');
            sel.style.backgroundColor = '#f0f0f0';
            sel.disabled = true;
        }
    }
    
    // Unlock form for editing
    function unlockFormForEdit() {
        // Keep total_students readonly (auto-filled)
        const totalField = document.getElementById('no_of_students');
        if (totalField) {
            totalField.setAttribute('readonly', 'readonly');
            totalField.style.backgroundColor = '#f8f9fa';
        }
        
        // Unlock all other fields for editing
        const fieldsToUnlock = ['no_of_students_admitted_final_year', 'no_of_students_late_entry', 'no_of_students_graduted', 
                               'no_of_students_placed', 'no_of_students_higher_studies', 'students_qualifying_exams'];
        fieldsToUnlock.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.removeAttribute('readonly');
                field.style.backgroundColor = '';
                field.style.cursor = '';
            }
        });
        
        // Unlock program select
        const sel = document.getElementById('program_select');
        if (sel) {
            sel.removeAttribute('readonly');
            sel.style.backgroundColor = '';
            sel.disabled = false;
        }
        
        // Toggle buttons - Hide Update, Show Save and Cancel Update
        const submitBtn = document.getElementById('submitBtn');
        const updateBtn = document.getElementById('updateBtn');
        const cancelUpdateBtn = document.getElementById('cancelUpdateBtn');
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Save';
            submitBtn.style.display = 'inline-block';
        }
        if (updateBtn) updateBtn.style.display = 'none';
        if (cancelUpdateBtn) cancelUpdateBtn.style.display = 'inline-block';
        
        // Refresh document displays to show delete buttons now that form is unlocked
        const recordId = document.getElementById('record_id').value;
        if (recordId) {
            refreshAllDocuments(recordId);
        }
        
        showNotification('Form unlocked for editing. Make your changes and click Save.', 'info');
    }
    
    // Refresh all document displays to update button visibility
    function refreshAllDocuments(recordId) {
        fetch('?fetch=1&id=' + recordId)
            .then(response => {
                return response.text().then(text => {
                    const trimmed = text.trim();
                    if (!trimmed) {
                        return { success: false, message: 'Empty server response', documents: null };
                    }
                    try {
                        return JSON.parse(trimmed);
                    } catch (e) {
                        return { success: false, message: 'Invalid server response', documents: null };
                    }
                });
            })
            .then(data => {
                if (data.success && data.documents) {
                    // Update document displays with current lock state
                    displayDocument('placement', data.documents.placement, 'placement_document_actions');
                    displayDocument('exam', data.documents.exam, 'exam_document_actions');
                    displayDocument('higher', data.documents.higher, 'higher_document_actions');
                }
            })
            .catch(error => {
                // Silently fail - documents will update on next page load
            });
    }
    
    // Cancel update - restore original data and lock form
    function cancelUpdate() {
        if (!window.originalRecordData) {
            showNotification('No original data to restore', 'warning');
            return;
        }
        
        const rec = window.originalRecordData;
        
        // Restore original values
        setValue('no_of_students', rec.TOTAL_NO_OF_STUDENT);
        setValue('no_of_students_admitted_final_year', rec.NUM_OF_STUDENTS_ADMITTED_FINAL_YEAR ?? 0);
        setValue('no_of_students_late_entry', rec.NUM_OF_STUDENTS_ADMITTED_LATERAL_ENTRY);
        setValue('no_of_students_graduted', rec.TOTAL_NUM_OF_STUDENTS_GRADUATED);
        setValue('no_of_students_placed', rec.TOTAL_NUM_OF_STUDENTS_PLACED);
        setValue('no_of_students_higher_studies', rec.NUM_OF_STUDENTS_IN_HIGHER_STUDIES);
        setValue('students_qualifying_exams', rec.STUDENTS_QUALIFYING_EXAMS ?? 0);
        
        // Restore original documents
        if (window.originalDocumentsData) {
            displayDocument('placement', window.originalDocumentsData.placement, 'placement_document_actions');
            displayDocument('exam', window.originalDocumentsData.exam, 'exam_document_actions');
            displayDocument('higher', window.originalDocumentsData.higher, 'higher_document_actions');
        }
        
        // Lock form again
        lockFormForEdit();
        
        // Toggle buttons - Show Update, Hide Save and Cancel Update
        const submitBtn = document.getElementById('submitBtn');
        const updateBtn = document.getElementById('updateBtn');
        const cancelUpdateBtn = document.getElementById('cancelUpdateBtn');
        if (submitBtn) submitBtn.style.display = 'none';
        if (updateBtn) updateBtn.style.display = 'inline-block';
        if (cancelUpdateBtn) cancelUpdateBtn.style.display = 'none';
        
        // Refresh document displays to hide delete buttons (form is locked again)
        const recordId = document.getElementById('record_id').value;
        if (recordId) {
            refreshAllDocuments(recordId);
        }
        
        showNotification('Changes cancelled. Form locked again.', 'info');
    }
    
    function cancelEdit() {
        // Clear hidden id
        document.getElementById('record_id').value = '';
        currentRecordId = null;
        window.originalRecordData = null;
        window.originalDocumentsData = null;
        
        // Clear document actions
        clearDocumentActions();
        // Reset form
        const form = document.querySelector('form.modern-form');
        if (form) form.reset();
        
        // Unlock all fields
        const fieldsToUnlock = ['no_of_students', 'no_of_students_admitted_final_year', 'no_of_students_late_entry', 'no_of_students_graduted', 
                               'no_of_students_placed', 'no_of_students_higher_studies', 'students_qualifying_exams'];
        fieldsToUnlock.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.removeAttribute('readonly');
                field.style.backgroundColor = '';
                field.style.cursor = '';
            }
        });
        
        // Unlock program select
        const sel = document.getElementById('program_select');
        if (sel) {
            sel.removeAttribute('readonly');
            sel.style.backgroundColor = '';
            sel.disabled = false;
        }
        
        // Restore buttons
        const submitBtn = document.getElementById('submitBtn');
        const updateBtn = document.getElementById('updateBtn');
        const cancelUpdateBtn = document.getElementById('cancelUpdateBtn');
        const cancelBtn = document.getElementById('cancelEditBtn');
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Submit Data';
            submitBtn.style.display = 'inline-block';
        }
        if (updateBtn) updateBtn.style.display = 'none';
        if (cancelUpdateBtn) cancelUpdateBtn.style.display = 'none';
        if (cancelBtn) cancelBtn.style.display = 'none';
        showNotification('Edit cancelled', 'info');
    }

    // Completely rewritten form submission handler - SIMPLE AND DIRECT
    (function wireFormSubmission(){
        const form = document.querySelector('form.modern-form');
        const submitBtn = document.getElementById('submitBtn');
        
        if (!form || !submitBtn) {
            return;
        }
        
        
        // Handle submit button click - just log, form submit event will handle validation
        submitBtn.addEventListener('click', function(e){
        });
        
        // Handle form submit event - validate here (this is the correct place)
        form.addEventListener('submit', function(e){
            
            // Check if form is locked
            const updateBtn = document.getElementById('updateBtn');
            const isLocked = updateBtn && updateBtn.style.display !== 'none';
            
            // If form is locked, prevent submission
            if (isLocked) {
                e.preventDefault();
                showNotification('Please click "Update" button first to unlock the form for editing.', 'warning');
                return false;
            }
            
            // Remove readonly from total_students if needed
            const totalEl = document.getElementById('total_students');
            if (totalEl && totalEl.hasAttribute('readonly')) {
                totalEl.removeAttribute('readonly');
            }
            
            // Validate program selection and ensure program name is included
            const programSelect = document.getElementById('program_select');
            if (!programSelect || !programSelect.value) {
                e.preventDefault();
                alert('Please select a program.');
                if (programSelect) programSelect.focus();
                return false;
            }
            
            // Get program name from the selected option's data-name attribute
            const selectedOption = programSelect.options[programSelect.selectedIndex];
            const programName = selectedOption ? selectedOption.getAttribute('data-name') : '';
            
            // Add hidden field with program name if it doesn't exist (needed for UPDATE handler)
            let programNameInput = document.getElementById('program_name_hidden');
            if (!programNameInput) {
                programNameInput = document.createElement('input');
                programNameInput.type = 'hidden';
                programNameInput.id = 'program_name_hidden';
                programNameInput.name = 'program_name_hidden';
                form.appendChild(programNameInput);
        }
            programNameInput.value = programName;
            
            
            // Validate total students
            if (totalEl) {
                const totalValue = Number(totalEl.value || 0);
                if (!totalEl.value || totalValue <= 0) {
                    e.preventDefault();
                    alert('Please enter Total No. of Students.');
                    totalEl.focus();
                    return false;
                }
            }
            
            // Validate all numeric fields are non-negative
            const fieldNames = [
                'no_of_students',
                'no_of_students_late_entry', 
                'no_of_students_graduted',
                'no_of_students_placed',
                'no_of_students_higher_studies',
                'students_qualifying_exams'
            ];
            
            for (const fieldName of fieldNames) {
                const field = form.querySelector(`[name="${fieldName}"]`);
                if (field) {
                    const value = Number(field.value || 0);
                    if (isNaN(value) || value < 0) {
                        e.preventDefault();
                        alert('Please enter valid non-negative numbers only.');
                        field.focus();
                        return false;
                    }
                }
            }
            
            
            // Don't disable button yet - it can prevent form submission
            // Instead, change text and disable after a tiny delay
            const originalHtml = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
            
            // Disable button after form starts submitting (prevents double submission)
            setTimeout(function() {
                submitBtn.disabled = true;
            }, 50);
            
            // Don't prevent default - allow form to submit naturally
            // The form will POST to the server now
        });
        
    })();
</script>

<?php
require "unified_footer.php";
?>



