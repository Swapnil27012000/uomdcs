<?php
// dept_login/unified_header.php - Unified Header for Department Forms
// Start output buffering early to prevent headers already sent errors
if (!ob_get_level()) {
    ob_start();
}
// Only load session.php if $userInfo is not already set (prevent double include)
if (!isset($userInfo) || empty($userInfo)) {
    require('session.php');
}
error_reporting(0);

// Load CSRF utilities for use across pages
if (file_exists(__DIR__ . '/csrf.php')) {
	require_once __DIR__ . '/csrf.php';
	// Ensure CSRF token is generated early
	if (function_exists('csrf_token')) {
		csrf_token(); // This will generate token if it doesn't exist
	}
}

// Load common functions including getAcademicYear()
if (file_exists(__DIR__ . '/common_functions.php')) {
	require_once __DIR__ . '/common_functions.php';
}

// Department information is now available from session.php:
// $userInfo - Full user data from database
// $email - User email  
// $permission - User permission
// $table - Source table (department_master)

// Safety check for $userInfo
if (!isset($userInfo) || empty($userInfo)) {
    // If $userInfo is not set, try to get from session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // If still not set, try to get from $_SESSION directly
    if ((!isset($userInfo) || empty($userInfo)) && isset($_SESSION['user_info'])) {
        $userInfo = $_SESSION['user_info'];
    }
    // If still not set, redirect to login (but allow some pages to work with minimal data)
    if (!isset($userInfo) || empty($userInfo)) {
        // Only redirect if we're not already on a page that can handle missing data
        $current_page = basename($_SERVER['PHP_SELF']);
        if (!in_array($current_page, ['dashboard.php', 'PlacementDetails.php', 'Consolidated_Score.php'])) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                header('Location: ../unified_login.php');
            }
            exit;
        }
    }
}

$dept_id = $userInfo['DEPT_ID'] ?? 0;
$dept_code = $userInfo['DEPT_COLL_NO'] ?? '';
$dept_name = $userInfo['DEPT_NAME'] ?? '';

// Academic year - use centralized function
// CRITICAL: Always calculate fresh to ensure correct year
if (function_exists('getAcademicYear')) {
    $A_YEAR = getAcademicYear();
} else {
    // Fallback calculation - ensure it matches the function logic
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    // If month >= 7 (July onwards), academic year is current_year to current_year+1
    // If month < 7 (January to June), academic year is (current_year-2) to (current_year-1)
    if ($current_month >= 7) {
        $A_YEAR = $current_year . '-' . ($current_year + 1);
    } else {
        $A_YEAR = ($current_year - 2) . '-' . ($current_year - 1);
    }
}

// DEBUG: Uncomment to see what date PHP sees (remove after fixing)
// $debug_date = date('Y-m-d');
// $debug_month = (int)date('n');
// error_log("DEBUG Academic Year: Date=$debug_date, Month=$debug_month, A_YEAR=$A_YEAR");

// Don't close PHP tag to prevent whitespace issues - just output HTML directly
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UoM Centralized DCS Ranking Portal</title>
    <?php if (function_exists('csrf_token')): ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom Unified Design CSS -->
    <link rel="stylesheet" href="assets/css/unified-design.css">
    
    <!-- Favicon -->
    <link rel="icon" href="assets/img/mumbai-university-removebg-preview.png" type="image/png">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>

<body>
    <div class="app-container">
        <!-- Sidebar -->
        <nav class="sidebar">
            <div class="sidebar-header">
                <a href="dashboard.php" class="sidebar-logo">
                    <img src="assets/img/mumbai-university-removebg-preview.png" alt="UoM Logo">
                    <div class="sidebar-title">
                        UoM Centralized<br>DCS Ranking Portal
                    </div>
                </a>
            </div>
            
            <div class="sidebar-nav">
                <a href="dashboard.php" class="nav-item" data-page="dashboard">
                    <i class="fas fa-tachometer-alt"></i>Dashboard
                </a>
                <a href="profile.php" class="nav-item" data-page="profile">
                    <i class="fas fa-user-circle"></i>Profile
                </a>
                <a href="DetailsOfDepartment.php" class="nav-item" data-page="department-details">
                    <i class="fas fa-building"></i>Department Details
                </a>
                <a href="Programmes_Offered.php" class="nav-item" data-page="programmes">
                    <i class="fas fa-graduation-cap"></i>Programmes Offered
                </a>
                <a href="ExecutiveDevelopment.php" class="nav-item" data-page="executive-development">
                    <i class="fas fa-chart-line"></i>Executive Development Program
                </a>
                <a href="IntakeActualStrength.php" class="nav-item" data-page="student-intake">
                    <i class="fas fa-user-graduate"></i>Student Enrolment
                </a>
                <a href="PlacementDetails.php" class="nav-item" data-page="placement">
                    <i class="fas fa-briefcase"></i>Placement
                </a>
                <a href="SalaryDetails.php" class="nav-item" data-page="salary">
                    <i class="fas fa-money-bill-wave"></i>Salary
                </a>
                <a href="EmployerDetails.php" class="nav-item" data-page="employer">
                    <i class="fas fa-industry"></i>Employer
                </a>
                <a href="phd.php" class="nav-item" data-page="phd">
                    <i class="fas fa-user-graduate"></i>PhD
                </a>
                <a href="FacultyDetails.php" class="nav-item" data-page="faculty-details">
                    <i class="fas fa-chalkboard-teacher"></i>Faculty Details
                </a>
                <a href="AcademicPeers.php" class="nav-item" data-page="academic-peers">
                    <i class="fas fa-users"></i>Academic Peers
                </a>
                <a href="FacultyOutput.php" class="nav-item" data-page="faculty-output">
                    <i class="fas fa-microscope"></i>Faculty Output
                </a>
                <a href="NEPInitiatives.php" class="nav-item" data-page="nep-initiatives">
                    <i class="fas fa-lightbulb"></i>NEP Initiatives
                </a>
                <a href="Departmental_Governance.php" class="nav-item" data-page="departmental-governance">
                    <i class="fas fa-cogs"></i>Departmental Governance
                </a>
                <a href="StudentSupport.php" class="nav-item" data-page="student-support">
                    <i class="fas fa-hands-helping"></i>Student Support
                </a>
                <a href="ConferencesWorkshops.php" class="nav-item" data-page="conferences">
                    <i class="fas fa-calendar-alt"></i>Conferences and Workshops
                </a>
                <a href="Collaborations.php" class="nav-item" data-page="collaborations">
                    <i class="fas fa-handshake"></i>Collaborations
                </a>
                <a href="Consolidated_Score.php" class="nav-item" data-page="consolidated-score">
                    <i class="fas fa-chart-line"></i>Consolidated Score
                </a>
                <a href="logout.php" class="nav-item logout">
                    <i class="fas fa-sign-out-alt"></i>Logout
                </a>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="app-header">
                <div class="header-content">
                    <div class="page-info">
                        <div class="academic-year">
                            <i class="fas fa-calendar-alt me-2"></i>Academic Year: <?php 
                            // Ensure A_YEAR is set
                            if (empty($A_YEAR)) {
                                if (function_exists('getAcademicYear')) {
                                    $A_YEAR = getAcademicYear();
                                } else {
                                    $current_year = (int)date('Y');
                                    $current_month = (int)date('n');
                                    $A_YEAR = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 2) . '-' . ($current_year - 1);
                                }
                            }
                            echo htmlspecialchars($A_YEAR, ENT_QUOTES, 'UTF-8'); 
                            ?>
                        </div>
                        <div class="department-info">
                            <i class="fas fa-building me-2"></i><?php echo htmlspecialchars($dept_code ?? '', ENT_QUOTES, 'UTF-8') . ' - ' . htmlspecialchars($dept_name ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                    
                    <div class="user-profile">
                        <div class="user-avatar">
                            <?php echo htmlspecialchars(strtoupper(substr($email ?? '', 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="user-dropdown">
                            <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown">
                                <span><?php echo htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                <i class="fas fa-chevron-down"></i>
                            </a>
                            <div class="dropdown-menu">
                                <a href="profile.php" class="dropdown-item">
                                    <i class="fas fa-user"></i>Profile
                                </a>
                                <a href="Changepwd.php" class="dropdown-item">
                                    <i class="fas fa-key"></i>Change Password
                                </a>
                                <div class="dropdown-divider"></div>
                                <a href="logout.php" class="dropdown-item danger">
                                    <i class="fas fa-sign-out-alt"></i>Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Content Wrapper -->
            <div class="content-wrapper">
