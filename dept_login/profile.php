<?php
// dept_login/profile.php - Department Profile Management
require('session.php');

// Handle form submission FIRST - before any includes to avoid HTML output
// CRITICAL: Follow all checklist items to prevent crashes and ensure proper JSON responses
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    // CRITICAL #1: Clear ALL output buffers FIRST
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Load config silently
    ob_start();
    if (!isset($conn) || !$conn) {
    require_once('../config.php');
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
    
    // Load CSRF utilities silently
    ob_start();
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
    }
    ob_end_clean();
    
    // Clear buffers again
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
    
    // CRITICAL #2: Build response in variable, output once at end
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        // Validate CSRF token
        if (!function_exists('validate_csrf') || !validate_csrf($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security token validation failed. Please refresh the page and try again.');
        }
        // Department information is available from session.php
        if (!$userInfo || !$userInfo['DEPT_COLL_NO']) {
            throw new Exception('Department ID not found. Please contact administrator.');
        }
        
        $dept_id = $userInfo['DEPT_COLL_NO']; // Use DEPT_COLL_NO (department number) for department_profiles table
        $dept_name = $userInfo['DEPT_NAME'];
        
        // CORRECTED: Calculate academic year properly
        // Academic year logic: July onwards = current-to-next, before July = (current-2)-to-(current-1)
        $current_year = (int)date("Y");
        $current_month = (int)date("n");
        if ($current_month >= 7) {
            $academic_year = $current_year . '-' . ($current_year + 1);
        } else {
            $academic_year = ($current_year - 2) . '-' . ($current_year - 1);
        }
        
        if ($_POST['action'] == 'save_profile') {
            // Validate and sanitize input
            $year_of_establishment = isset($_POST['year_of_establishment']) ? intval($_POST['year_of_establishment']) : 0;
            $category = isset($_POST['category']) ? trim($_POST['category']) : '';
            $areas_of_research = isset($_POST['areas_of_research']) ? trim($_POST['areas_of_research']) : '';
            
            // Validate required fields
            $errors = array();
            if ($year_of_establishment < 1900 || $year_of_establishment > date('Y')) {
                $errors[] = "Year of establishment must be between 1900 and " . date('Y');
            }
            if (empty($category)) {
                $errors[] = "Department category is required";
            }
            if (empty($areas_of_research)) {
                $errors[] = "Areas of research is required";
            }
            
            if (empty($errors)) {
                // Check if record already exists (UPDATE SAME ROW - Only year changes)
                // NOTE: Checking by dept_id ONLY (not A_YEAR) to update same record each year
                // CRITICAL: Use string binding since dept_id stores DEPT_COLL_NO (VARCHAR)
                $check_query = "SELECT id FROM department_profiles WHERE dept_id = ?";
                $stmt_check = mysqli_prepare($conn, $check_query);
                if (!$stmt_check) {
                    throw new Exception('Prepare failed: ' . mysqli_error($conn));
                }
                mysqli_stmt_bind_param($stmt_check, "s", $dept_id);
                if (!mysqli_stmt_execute($stmt_check)) {
                    $error = mysqli_stmt_error($stmt_check);
                    mysqli_stmt_close($stmt_check);
                    throw new Exception('Query execution failed: ' . $error);
                }
                $check_result = mysqli_stmt_get_result($stmt_check);
                
                if ($check_result && mysqli_num_rows($check_result) > 0) {
                    // Update existing record (UPDATE SAME ROW - Only dept_id in WHERE clause)
                    $update_query = "UPDATE department_profiles SET 
                                     year_of_establishment = ?, areas_of_research = ?, category = ?, 
                                     A_YEAR = ?, profile_completed = 1, updated_at = NOW()
                                     WHERE dept_id = ?";
                    
                    $stmt_update = mysqli_prepare($conn, $update_query);
                    if (!$stmt_update) {
                        mysqli_free_result($check_result);  // CRITICAL: Free result
                        mysqli_stmt_close($stmt_check);  // CRITICAL: Close statement
                        throw new Exception('Prepare failed: ' . mysqli_error($conn));
                    }
                    // CRITICAL: Use string binding for dept_id since it stores DEPT_COLL_NO (VARCHAR)
                    mysqli_stmt_bind_param($stmt_update, "issss", $year_of_establishment, $areas_of_research, $category, $academic_year, $dept_id);
                    
                    if (mysqli_stmt_execute($stmt_update)) {
                        mysqli_free_result($check_result);  // CRITICAL: Free result
                        mysqli_stmt_close($stmt_check);  // CRITICAL: Close statement
                        mysqli_stmt_close($stmt_update);  // CRITICAL: Close statement
                        $response = ['success' => true, 'message' => 'Profile updated successfully!'];
                    } else {
                        $error = mysqli_stmt_error($stmt_update);
                        mysqli_free_result($check_result);  // CRITICAL: Free result
                        mysqli_stmt_close($stmt_check);  // CRITICAL: Close statement
                        mysqli_stmt_close($stmt_update);
                        throw new Exception('Error updating profile: ' . $error);
                    }
                } else {
                    // Insert new record
                    $insert_query = "INSERT INTO department_profiles 
                                     (dept_id, department_name, year_of_establishment, category, areas_of_research, 
                                      profile_completed, A_YEAR, created_at, updated_at) 
                                     VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), NOW())";
                    
                    $stmt_insert = mysqli_prepare($conn, $insert_query);
                    if (!$stmt_insert) {
                        if ($check_result) {
                            mysqli_free_result($check_result);  // CRITICAL: Free result
                        }
                        mysqli_stmt_close($stmt_check);  // CRITICAL: Close statement
                        throw new Exception('Prepare failed: ' . mysqli_error($conn));
                    }
                    // CRITICAL: Use string binding for dept_id since it stores DEPT_COLL_NO (VARCHAR)
                    mysqli_stmt_bind_param($stmt_insert, "ssisss", $dept_id, $dept_name, $year_of_establishment, $category, $areas_of_research, $academic_year);
                    
                    if (mysqli_stmt_execute($stmt_insert)) {
                        if ($check_result) {
                            mysqli_free_result($check_result);  // CRITICAL: Free result
                        }
                        mysqli_stmt_close($stmt_check);  // CRITICAL: Close statement
                        mysqli_stmt_close($stmt_insert);  // CRITICAL: Close statement
                        $response = ['success' => true, 'message' => 'Profile created successfully!'];
                    } else {
                        $error = mysqli_stmt_error($stmt_insert);
                        if ($check_result) {
                            mysqli_free_result($check_result);  // CRITICAL: Free result
                        }
                        mysqli_stmt_close($stmt_check);  // CRITICAL: Close statement
                        mysqli_stmt_close($stmt_insert);
                        throw new Exception('Error creating profile: ' . $error);
                    }
                }
                
            } else {
                throw new Exception('Validation errors: ' . implode(", ", $errors));
            }
        }
        
    } catch (Exception $e) {
        // CRITICAL #2: Build error response in variable
        $response = ['success' => false, 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        // CRITICAL #2: Build error response in variable for fatal errors
        $response = ['success' => false, 'message' => 'Form submission failed due to server error. Please try again.'];
    } catch (Error $e) {
        // CRITICAL #2: Build error response in variable for fatal errors
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

// Now require unified_header.php for normal page display
require('unified_header.php');

// Academic year is already set in unified_header.php

// Use department information from unified_header.php (already loaded)
$dept = $dept_code;  // Use DEPT_COLL_NO (9998) for department_profiles table - this is the department number
$dept_name = $dept_name;  // DEPT_NAME is already available from unified_header.php
// $dept_id is the database mapping ID (120) - used for department_master queries

// Get additional department details for display from department_master table
$dept_query = "SELECT HOD_NAME, EMAIL, HOD_EMAIL FROM department_master WHERE DEPT_ID = ?";
$stmt = mysqli_prepare($conn, $dept_query);
mysqli_stmt_bind_param($stmt, "i", $dept_id);
mysqli_stmt_execute($stmt);
$dept_result = mysqli_stmt_get_result($stmt);

if (!$dept_result) {
    echo "<div class='alert alert-danger'>Database Error: " . mysqli_error($conn) . "</div>";
    exit;
}

$dept_info_additional = mysqli_fetch_assoc($dept_result);
if (!$dept_info_additional) {
    echo "<div class='alert alert-danger'>Error: Department not found in database.</div>";
    exit;
}

$hod_name = $dept_info_additional['HOD_NAME'];
$dept_email = $dept_info_additional['EMAIL'];
$hod_email = $dept_info_additional['HOD_EMAIL'];

// Get existing profile data from department_profiles table
// NOTE: Checking by dept_id ONLY (not A_YEAR) to load same record each year
$existing_data = null;
$existing_query = "SELECT * FROM department_profiles WHERE dept_id = ?";
$stmt = mysqli_prepare($conn, $existing_query);
mysqli_stmt_bind_param($stmt, "i", $dept);
mysqli_stmt_execute($stmt);
$existing_result = mysqli_stmt_get_result($stmt);
if ($existing_result && mysqli_num_rows($existing_result) > 0) {
    $existing_data = mysqli_fetch_assoc($existing_result);
}


// Add smooth messaging system
function showSmoothMessage($message, $type = 'success') {
    $icon = $type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle';
    $bgColor = $type === 'success' ? 'linear-gradient(135deg, #10b981, #059669)' : 'linear-gradient(135deg, #ef4444, #dc2626)';
    
    echo "<div id='smooth-message' class='smooth-message' style='background: $bgColor;'>";
    echo "<i class='$icon'></i>";
    echo "<span>$message</span>";
    echo "</div>";
    
    echo "<script>
        setTimeout(function() {
            const message = document.getElementById('smooth-message');
            if (message) {
                message.style.opacity = '0';
                message.style.transform = 'translateY(-20px)';
                setTimeout(() => message.remove(), 500);
            }
        }, 2000);
    </script>";
}
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
        min-height: 100vh;
        overflow-x: hidden;
    }
    
    .profile-container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 2rem 1rem;
        position: relative;
    }
    
    .floating-shapes {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: 1;
    }
    
    .shape {
        position: absolute;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        animation: float 6s ease-in-out infinite;
    }
    
    .shape:nth-child(1) {
        width: 80px;
        height: 80px;
        top: 20%;
        left: 10%;
        animation-delay: 0s;
    }
    
    .shape:nth-child(2) {
        width: 120px;
        height: 120px;
        top: 60%;
        right: 10%;
        animation-delay: 2s;
    }
    
    .shape:nth-child(3) {
        width: 60px;
        height: 60px;
        top: 80%;
        left: 20%;
        animation-delay: 4s;
    }
    
    @keyframes float {
        0%, 100% { transform: translateY(0px) rotate(0deg); }
        50% { transform: translateY(-20px) rotate(180deg); }
    }
    
    .profile-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border-radius: 2rem;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        padding: 3rem;
        position: relative;
        z-index: 10;
        border: 1px solid rgba(255, 255, 255, 0.2);
        overflow: hidden;
    }
    
    .profile-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 6px;
        background: linear-gradient(90deg, #667eea, #764ba2, #f093fb);
        border-radius: 2rem 2rem 0 0;
    }
    
    .profile-header {
        text-align: center;
        margin-bottom: 3rem;
        position: relative;
    }
    
    .profile-icon {
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.5rem;
        box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        animation: pulse 2s infinite;
    }
    
    .profile-icon i {
        font-size: 2rem;
        color: white;
    }
    
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }
    
    .profile-title {
        font-size: 2.5rem;
        font-weight: 800;
        background: linear-gradient(135deg, #667eea, #764ba2);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 0.5rem;
        letter-spacing: -0.02em;
    }
    
    .profile-subtitle {
        color: #64748b;
        font-size: 1.1rem;
        font-weight: 500;
        margin-bottom: 1rem;
    }
    
    .academic-year-badge {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        padding: 0.75rem 1.5rem;
        border-radius: 50px;
        font-size: 0.9rem;
        font-weight: 600;
        display: inline-block;
        box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        animation: glow 2s ease-in-out infinite alternate;
    }
    
    @keyframes glow {
        from { box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3); }
        to { box-shadow: 0 8px 30px rgba(102, 126, 234, 0.5); }
    }
    
    .info-section {
        background: linear-gradient(135deg, #f8fafc, #e2e8f0);
        border-radius: 1.5rem;
        padding: 2rem;
        margin-bottom: 2.5rem;
        border-left: 5px solid #667eea;
        position: relative;
        overflow: hidden;
    }
    
    .info-section::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 100px;
        height: 100px;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
        border-radius: 50%;
        transform: translate(30px, -30px);
    }
    
    .info-title {
        font-size: 1.3rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
    }
    
    .info-title i {
        margin-right: 0.75rem;
        color: #667eea;
        font-size: 1.2rem;
    }
    
    .info-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 0;
        border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        transition: all 0.3s ease;
    }
    
    .info-item:last-child {
        border-bottom: none;
    }
    
    .info-item:hover {
        background: rgba(102, 126, 234, 0.05);
        border-radius: 0.75rem;
        padding-left: 1rem;
        padding-right: 1rem;
        transform: translateX(5px);
    }
    
    .info-label {
        font-weight: 600;
        color: #475569;
        font-size: 1rem;
    }
    
    .info-value {
        color: #1e293b;
        font-weight: 700;
        font-size: 1.1rem;
        background: linear-gradient(135deg, #667eea, #764ba2);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    
    .form-section {
        background: rgba(255, 255, 255, 0.8);
        border-radius: 1.5rem;
        padding: 2.5rem;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.3);
    }
    
    .form-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 2rem;
        text-align: center;
        position: relative;
    }
    
    .form-title::after {
        content: '';
        position: absolute;
        bottom: -0.5rem;
        left: 50%;
        transform: translateX(-50%);
        width: 60px;
        height: 3px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        border-radius: 2px;
    }
    
    .form-group {
        margin-bottom: 2rem;
        position: relative;
    }
    
    .form-label {
        font-weight: 600;
        color: #374151;
        margin-bottom: 0.75rem;
        font-size: 1rem;
        display: flex;
        align-items: center;
        transition: color 0.3s ease;
    }
    
    .form-label i {
        margin-right: 0.5rem;
        color: #667eea;
        font-size: 1.1rem;
    }
    
    .form-control {
        width: 100%;
        padding: 1rem 1.25rem;
        border: 2px solid #e2e8f0;
        border-radius: 1rem;
        font-size: 1rem;
        font-weight: 500;
        background: rgba(255, 255, 255, 0.9);
        transition: all 0.3s ease;
        backdrop-filter: blur(10px);
        position: relative;
    }
    
    .form-control:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        background: rgba(255, 255, 255, 1);
        transform: translateY(-2px);
    }
    
    .form-control:hover {
        border-color: #94a3b8;
        transform: translateY(-1px);
    }
    
    .form-control[readonly] {
        background-color: #f8f9fa !important;
        cursor: not-allowed !important;
        opacity: 0.8;
    }
    
    .form-control[disabled] {
        background-color: #f8f9fa !important;
        cursor: not-allowed !important;
        opacity: 0.8;
    }
    
    .alert {
        border-radius: 1rem;
        border: none;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        margin-bottom: 1.5rem;
    }
    
    .alert-success {
        background: linear-gradient(135deg, #d4edda, #c3e6cb);
        color: #155724;
        border-left: 4px solid #28a745;
    }
    
    .alert-danger {
        background: linear-gradient(135deg, #f8d7da, #f5c6cb);
        color: #721c24;
        border-left: 4px solid #dc3545;
    }
    
    .alert-info {
        background: linear-gradient(135deg, #d1ecf1, #bee5eb);
        color: #0c5460;
        border-left: 4px solid #17a2b8;
    }
    
    .form-locked-message {
        margin-bottom: 1.5rem;
    }
    
    .form-text {
        color: #64748b;
        font-size: 0.875rem;
        margin-top: 0.5rem;
        font-weight: 500;
    }
    
    .btn-update {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        padding: 1rem 2.5rem;
        border-radius: 1rem;
        font-size: 1.1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        position: relative;
        overflow: hidden;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        width: 100%;
        margin-top: 1rem;
    }
    
    .btn-update::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s;
    }
    
    .btn-update:hover::before {
        left: 100%;
    }
    
    .btn-update:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 35px rgba(102, 126, 234, 0.4);
    }
    
    .btn-update:active {
        transform: translateY(-1px);
    }
    
    .success-message {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        padding: 1.5rem;
        border-radius: 1rem;
        margin-top: 2rem;
        text-align: center;
        font-weight: 600;
        box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
        animation: slideIn 0.5s ease-out;
    }
    
    @keyframes slideIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .loading-spinner {
        display: none;
        width: 20px;
        height: 20px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        border-top-color: white;
        animation: spin 1s ease-in-out infinite;
        margin-right: 0.5rem;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    @media (max-width: 768px) {
        .profile-container {
            padding: 1rem 0.5rem;
        }
        
        .profile-card {
            padding: 2rem 1.5rem;
        }
        
        .profile-title {
            font-size: 2rem;
        }
        
        .form-section {
            padding: 2rem 1.5rem;
        }
    }
    
    .fade-in {
        animation: fadeIn 0.8s ease-out;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    /* Smooth Message Styles */
    .smooth-message {
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
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .smooth-message i {
        font-size: 1.2rem;
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
</style>

<div class="floating-shapes">
    <div class="shape"></div>
    <div class="shape"></div>
    <div class="shape"></div>
</div>

<div class="profile-container fade-in">
    <div class="profile-card">
        <div class="profile-header">
            <div class="profile-icon">
                <i class="fas fa-university"></i>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div>
                    <h1 class="profile-title">Department Profile</h1>
                    <p class="profile-subtitle">Manage your department information and research areas</p>
                </div>
                <a href="export_page_pdf.php?page=profile" target="_blank" class="btn btn-warning" style="margin-left: 20px; white-space: nowrap;">
                    <i class="fas fa-file-pdf"></i> Download as PDF
                </a>
            </div>
        </div>

        <!-- Department Information Section -->
        <div class="info-section">
            <h5 class="info-title">
                <i class="fas fa-info-circle"></i>
                Department Information
            </h5>
            <div class="mb-3">
                <label class="form-label" style="margin-bottom: 6px;">Head of Department</label>
                <input type="text" name="hod_name" value="<?php echo htmlspecialchars($hod_name ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="form-control" style="margin-top: 0;" id="hod_name_input" readonly>
                <div class="form-text">This information is automatically updated from Department Details</div>
            </div>
            <div class="mb-3">
                <label class="form-label" style="margin-bottom: 6px;">HoD Email</label>
                <input type="email" name="hod_email" value="<?php echo htmlspecialchars($hod_email ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="form-control" style="margin-top: 0;" id="hod_email_input" readonly>
                <div class="form-text">This information was provided by the nodal officer and cannot be changed</div>
            </div>
            <div class="mb-3">
                <label class="form-label" style="margin-bottom: 6px;">Department Email</label>
                <input type="email" name="dept_email" value="<?php echo htmlspecialchars($dept_email ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="form-control" style="margin-top: 0;" id="dept_email_input" readonly>
                <div class="form-text">This information was provided by the nodal officer and cannot be changed</div>
            </div>
        </div>

        <!-- Profile Form -->
        <div class="form-section">
            <h3 class="form-title">Update Profile Information</h3>
            
            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
            <?php showSmoothMessage($success_message, 'success'); ?>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
            <?php showSmoothMessage($error_message, 'error'); ?>
            <?php endif; ?>
            
            <form method="POST" id="profileForm">
                <?php if (function_exists('csrf_field')): ?>
                    <?php echo csrf_field(); ?>
                <?php endif; ?>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-calendar-alt"></i>
                                Year of Establishment
                            </label>
                            <input type="number" 
                                   name="year_of_establishment" 
                                   class="form-control" 
                                   value="<?php echo isset($existing_data['year_of_establishment']) ? htmlspecialchars($existing_data['year_of_establishment'], ENT_QUOTES, 'UTF-8') : ''; ?>" 
                                   min="1900" 
                                   max="<?php echo date('Y'); ?>" 
                                   maxlength="4"
                                   oninput="validateYearRealTime(this)"
                                   placeholder="e.g., 1950" 
                                   <?php echo ($existing_data && !empty($existing_data['year_of_establishment'])) ? 'readonly' : 'required'; ?>>
                            <div class="form-text">Enter exactly 4-digit year when your department was established (1900-<?php echo date('Y'); ?>)</div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-tags"></i>
                                Department Category
                            </label>
                            <select name="category" class="form-control" required <?php echo ($existing_data && !empty($existing_data['category'])) ? 'data-readonly="true" style="pointer-events: none; background-color: #f8f9fa; opacity: 0.8;"' : ''; ?>>
                                <option value="">Select Category</option>
                                <?php
                                $departments_query = "SELECT * FROM departments ORDER BY department_id ASC";
                                $departments_result = mysqli_query($conn, $departments_query);
                                if ($departments_result) {
                                    while ($info = mysqli_fetch_array($departments_result, MYSQLI_ASSOC)) {
                                        $d_id = $info['department_id'];
                                        $d_name = htmlspecialchars($info['department_name']);
                                        $selected = (isset($existing_data['category']) && $existing_data['category'] == $info['department_name']) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($info['department_name']) . "' $selected>$d_name</option>";
                                    }
                                } else {
                                }
                                ?>
                            </select>
                            <div class="form-text">Select the category your department belongs to</div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-microscope"></i>
                        Areas of Research
                    </label>
                    <textarea name="areas_of_research" 
                              class="form-control" 
                              rows="5" 
                              placeholder="e.g., Artificial Intelligence, Machine Learning, Data Science, Quantum Computing, Renewable Energy, Environmental Science, etc.&#10;&#10;Please list the main research areas and specializations of your department."
                              <?php echo ($existing_data && !empty($existing_data['areas_of_research'])) ? 'readonly' : ''; ?>><?php echo isset($existing_data['areas_of_research']) ? htmlspecialchars($existing_data['areas_of_research']) : ''; ?></textarea>
                    <div class="form-text">Describe the main research areas and specializations of your department</div>
                </div>

                <?php if ($existing_data && !empty($existing_data['year_of_establishment']) && !empty($existing_data['areas_of_research'])): ?>
                    <!-- Form is locked - show edit button -->
                    <div class="form-locked-message">
                        <div class="alert alert-info">
                            <i class="fas fa-lock me-2"></i>
                            <strong>Profile Completed!</strong> Your department profile has been successfully updated. Click "Edit Profile" to make changes.
                        </div>
                    </div>
                    <button type="button" class="btn-update" onclick="toggleEditMode()" id="editBtn">
                        <i class="fas fa-edit me-2"></i>
                        Edit Profile
                    </button>
                    <button type="submit" name="update_profile" class="btn-update" id="updateBtn" style="display: none;">
                        <span class="loading-spinner"></span>
                        <i class="fas fa-save me-2"></i>
                        Update Profile
                    </button>
                <?php else: ?>
                    <!-- Form is unlocked - show update button -->
                    <button type="submit" name="update_profile" class="btn-update" id="submitBtn">
                        <span class="loading-spinner"></span>
                        <i class="fas fa-save me-2"></i>
                        <?php echo $existing_data ? 'Update Profile' : 'Create Profile'; ?>
                    </button>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<script>
    // Toggle edit mode function
    function toggleEditMode() {
        const formInputs = document.querySelectorAll('input[name="year_of_establishment"], textarea[name="areas_of_research"], select[name="category"]');
        const editBtn = document.getElementById('editBtn');
        const updateBtn = document.getElementById('updateBtn');
        const lockedMessage = document.querySelector('.form-locked-message');
        
        // Toggle readonly/disabled state
        formInputs.forEach(input => {
            if (input.hasAttribute('readonly')) {
                input.removeAttribute('readonly');
                input.style.backgroundColor = 'rgba(255, 255, 255, 1)';
                input.style.cursor = 'text';
            } else if (input.hasAttribute('disabled')) {
                input.removeAttribute('disabled');
                input.style.backgroundColor = 'rgba(255, 255, 255, 1)';
                input.style.cursor = 'pointer';
            } else if (input.hasAttribute('data-readonly')) {
                // Handle SELECT elements with data-readonly (can't use readonly attribute)
                input.removeAttribute('data-readonly');
                input.style.pointerEvents = 'auto';
                input.style.backgroundColor = 'rgba(255, 255, 255, 1)';
                input.style.opacity = '1';
                input.style.cursor = 'pointer';
            }
        });
        
        // Toggle button visibility
        editBtn.style.display = 'none';
        updateBtn.style.display = 'block';
        
        // Hide locked message
        if (lockedMessage) {
            lockedMessage.style.display = 'none';
        }
        
        // Add required attributes back
        document.querySelector('input[name="year_of_establishment"]').setAttribute('required', '');
        document.querySelector('select[name="category"]').setAttribute('required', '');
        
        // Focus on first input
        document.querySelector('input[name="year_of_establishment"]').focus();
    }

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
            if (year >= 1900 && year <= currentYear) {
                input.classList.add('is-valid');
                input.setCustomValidity('');
            } else {
                input.classList.add('is-invalid');
                input.setCustomValidity(`Year must be between 1900 and ${currentYear}`);
            }
        } else if (value.length > 0) {
            input.classList.add('is-invalid');
            input.setCustomValidity('Please enter exactly 4-digit year');
        } else {
            input.setCustomValidity('');
        }
    }

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('profileForm');
    const submitBtn = document.getElementById('submitBtn') || document.querySelector('button[type="submit"]');
    const spinner = document.querySelector('.loading-spinner');
    
    // Handle form submission with AJAX like DetailsOfDepartment.php
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
        
        if (submitBtn) {
            submitBtn.innerHTML = '<span class="loading-spinner" style="display:inline-block;"></span>Saving...';
            submitBtn.disabled = true;
        }
        
        // Prepare form data
        const formData = new FormData(this);
        formData.append('action', 'save_profile');
        
        // Submit via AJAX
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (submitBtn) {
                submitBtn.disabled = false;
            }
            
            if (data.success) {
                // Show success message
                showMessage(data.message, 'success');
                // Reload page immediately to show updated data
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                // Show error message
                showMessage(data.message, 'error');
                if (submitBtn) {
                    submitBtn.innerHTML = originalBtnText;
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            }
            showMessage('An error occurred. Please try again.', 'error');
        });
    });
    
    // Function to show messages
    function showMessage(message, type) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'alert alert-' + (type === 'success' ? 'success' : 'danger');
        messageDiv.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-triangle') + ' me-2"></i>' + message;
        messageDiv.style.position = 'fixed';
        messageDiv.style.top = '20px';
        messageDiv.style.right = '20px';
        messageDiv.style.zIndex = '9999';
        messageDiv.style.minWidth = '300px';
        
        document.body.appendChild(messageDiv);
        
        setTimeout(() => {
            messageDiv.remove();
        }, 5000);
    }
    
    // Add smooth animations to form elements
    const formElements = document.querySelectorAll('.form-control');
    formElements.forEach(element => {
        element.addEventListener('focus', function() {
            this.parentElement.style.transform = 'translateY(-2px)';
        });
        
        element.addEventListener('blur', function() {
            this.parentElement.style.transform = 'translateY(0)';
        });
    });
    
    // Add hover effects to info items
    const infoItems = document.querySelectorAll('.info-item');
    infoItems.forEach(item => {
        item.addEventListener('mouseenter', function() {
            this.style.transform = 'translateX(10px)';
        });
        
        item.addEventListener('mouseleave', function() {
            this.style.transform = 'translateX(0)';
        });
    });
});
</script>

<?php require "unified_footer.php"; ?>