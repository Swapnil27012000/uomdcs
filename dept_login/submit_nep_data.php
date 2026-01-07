<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration
// $server = "localhost";
// $user = "root";
// $pass = "";
// $database = "test_ranking";

$server = "database-ranking-mu.ctqcaks44o4u.ap-south-1.rds.amazonaws.com";
$user = "admin";
$pass = "sO77NWrPV0f0Yi8AuhG5";
$database = "u257276344_Nirf_Test";

// Connect to database
$conn = mysqli_connect($server, $user, $pass, $database);

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . mysqli_connect_error()
    ]);
    exit;
}

// Set charset
mysqli_set_charset($conn, "utf8");

try {
    // Start transaction
    mysqli_autocommit($conn, false);
    
    // Get form data
    $academic_year = mysqli_real_escape_string($conn, $_POST['academic_year'] ?? '');
    $submission_date = mysqli_real_escape_string($conn, $_POST['submission_date'] ?? '');
    $total_score = (int)($_POST['total_score'] ?? 0);
    $selected_activities = json_decode($_POST['selected_activities'] ?? '[]', true);
    
    // Create uploads directory if it doesn't exist
    $upload_base_dir = 'uploads/nep_activities/';
    $year_dir = $upload_base_dir . $academic_year . '/';
    $submission_id = uniqid('NEP_' . date('Y'), true);
    $submission_dir = $year_dir . $submission_id . '/';
    
    if (!is_dir($submission_dir)) {
        mkdir($submission_dir, 0755, true);
    }
    
    // Create subdirectories
    mkdir($submission_dir . 'sections/', 0755, true);
    mkdir($submission_dir . 'individual/', 0755, true);
    
    // Insert main submission record
    $insert_submission = "INSERT INTO nep_submissions (
        submission_id, 
        academic_year, 
        submission_date, 
        total_score,
        upload_directory,
        created_at
    ) VALUES (?, ?, ?, ?, ?, NOW())";
    
    $stmt = mysqli_prepare($conn, $insert_submission);
    mysqli_stmt_bind_param($stmt, "sssis", $submission_id, $academic_year, $submission_date, $total_score, $submission_dir);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Failed to insert submission record: " . mysqli_error($conn));
    }
    
    $submission_db_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    
    // Process selected activities
    $insert_activity = "INSERT INTO nep_selected_activities (
        submission_id, 
        checkbox_id, 
        section_id, 
        activity_index, 
        activity_text,
        created_at
    ) VALUES (?, ?, ?, ?, ?, NOW())";
    
    $stmt_activity = mysqli_prepare($conn, $insert_activity);
    
    foreach ($selected_activities as $activity) {
        $checkbox_id = mysqli_real_escape_string($conn, $activity['checkbox_id']);
        $section_id = mysqli_real_escape_string($conn, $activity['section_id']);
        $activity_index = (int)$activity['activity_index'];
        $activity_text = mysqli_real_escape_string($conn, $activity['activity_text']);
        
        mysqli_stmt_bind_param($stmt_activity, "ississ", $submission_db_id, $checkbox_id, $section_id, $activity_index, $activity_text);
        
        if (!mysqli_stmt_execute($stmt_activity)) {
            throw new Exception("Failed to insert activity: " . mysqli_error($conn));
        }
    }
    mysqli_stmt_close($stmt_activity);
    
    // Process file uploads
    $file_paths = [];
    $insert_file = "INSERT INTO nep_uploaded_files (
        submission_id,
        file_type,
        reference_id,
        original_filename,
        stored_filename,
        file_path,
        file_size,
        mime_type,
        upload_date
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt_file = mysqli_prepare($conn, $insert_file);
    
    // Process section files
    foreach ($_FILES as $key => $file) {
        if (strpos($key, 'section_') === 0) {
            // Extract section info
            preg_match('/section_(\w+)_file_(\d+)/', $key, $matches);
            if ($matches) {
                $section_id = $matches[1];
                $file_index = $matches[2];
                
                $file_info_key = $key . '_info';
                $file_info = json_decode($_POST[$file_info_key] ?? '{}', true);
                
                if ($file['error'] === UPLOAD_ERR_OK) {
                    $original_filename = $file_info['name'] ?? $file['name'];
                    $file_extension = pathinfo($original_filename, PATHINFO_EXTENSION);
                    $stored_filename = $section_id . '_' . $file_index . '_' . time() . '.' . $file_extension;
                    $file_path = $submission_dir . 'sections/' . $stored_filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $file_path)) {
                        mysqli_stmt_bind_param($stmt_file, "isssssss", 
                            $submission_db_id,
                            'section',
                            $section_id,
                            $original_filename,
                            $stored_filename,
                            $file_path,
                            $file['size'],
                            $file['type']
                        );
                        
                        if (!mysqli_stmt_execute($stmt_file)) {
                            throw new Exception("Failed to insert file record: " . mysqli_error($conn));
                        }
                        
                        $file_paths[] = $file_path;
                    }
                }
            }
        }
        // Process individual checkbox files
        elseif (strpos($key, 'individual_') === 0) {
            // Extract checkbox info
            preg_match('/individual_(\w+_\d+)_file_(\d+)/', $key, $matches);
            if ($matches) {
                $checkbox_id = $matches[1];
                $file_index = $matches[2];
                
                $file_info_key = $key . '_info';
                $file_info = json_decode($_POST[$file_info_key] ?? '{}', true);
                
                if ($file['error'] === UPLOAD_ERR_OK) {
                    $original_filename = $file_info['name'] ?? $file['name'];
                    $file_extension = pathinfo($original_filename, PATHINFO_EXTENSION);
                    $stored_filename = $checkbox_id . '_' . $file_index . '_' . time() . '.' . $file_extension;
                    $file_path = $submission_dir . 'individual/' . $stored_filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $file_path)) {
                        mysqli_stmt_bind_param($stmt_file, "isssssss", 
                            $submission_db_id,
                            'individual',
                            $checkbox_id,
                            $original_filename,
                            $stored_filename,
                            $file_path,
                            $file['size'],
                            $file['type']
                        );
                        
                        if (!mysqli_stmt_execute($stmt_file)) {
                            throw new Exception("Failed to insert file record: " . mysqli_error($conn));
                        }
                        
                        $file_paths[] = $file_path;
                    }
                }
            }
        }
    }
    
    mysqli_stmt_close($stmt_file);
    
    // Commit transaction
    mysqli_commit($conn);
    
    echo json_encode([
        'success' => true,
        'message' => 'Data successfully submitted',
        'submission_id' => $submission_id,
        'files_uploaded' => count($file_paths),
        'upload_directory' => $submission_dir
    ]);
    
} catch (Exception $e) {
    // Rollback transaction
    mysqli_rollback($conn);
    
    // Clean up uploaded files on error
    if (isset($file_paths)) {
        foreach ($file_paths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    
} finally {
    mysqli_close($conn);
}

// Function to validate file types
function validateFileType($filename) {
    $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
    $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($file_extension, $allowed_extensions);
}

// Function to get safe filename
function getSafeFilename($filename) {
    // Remove any directory path info
    $filename = basename($filename);
    // Replace spaces with underscores
    $filename = str_replace(' ', '_', $filename);
    // Remove any characters that aren't alphanumeric, underscore, hyphen, or dot
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    return $filename;
}
?>
