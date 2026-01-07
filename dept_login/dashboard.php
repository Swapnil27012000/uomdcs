<?php
// dept_login/dashboard.php - Department Dashboard
// error_reporting(0);
// require('session.php');
// require('unified_header.php');

// Use absolute/dir-relative requires to avoid include_path issues
require_once(__DIR__ . '/session.php');
require_once(__DIR__ . '/unified_header.php');

// Load progress functions
require_once(__DIR__ . '/../common_progress_functions.php');

// Get department progress
$dept_id = $userInfo['DEPT_ID'] ?? 0;
$academic_year = $A_YEAR ?? getAcademicYear();
$department_progress = null;

if ($dept_id > 0) {
    try {
        $department_progress = calculateDepartmentProgress($dept_id, $academic_year);
        // Ensure result is an array
        if (!is_array($department_progress)) {
            $department_progress = null;
        }
    } catch (Exception $e) {
        error_log("Department dashboard progress error: " . $e->getMessage());
        $department_progress = [
            'progress_percentage' => 0,
            'completed_forms' => 0,
            'total_forms' => 17,
            'form_status' => []
        ];
    }
}

?>

<!-- Dashboard Content -->
<div class="page-header">
    <h1 class="page-title">
        <i class="fas fa-tachometer-alt"></i>Dashboard
    </h1>
    <p class="page-subtitle">Welcome, <?php echo htmlspecialchars($dept_name ?? '', ENT_QUOTES, 'UTF-8'); ?> - UoM Centralized DCS Ranking Portal</p>
</div>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'success'): ?>
    <div class="alert alert-success animate-fadeIn">
        <i class="fas fa-check-circle"></i>
        Password reset successfully!
    </div>
    <script>
        setTimeout(function() {
            window.location.href = "dashboard.php";
        }, 3000);
    </script>
<?php endif; ?>

<?php if (isset($_SESSION['admin_username']) || isset($_SESSION['dept_login'])): ?>
    <!-- Overall Progress Card -->
    <?php if ($department_progress): ?>
    <div class="card mb-6 border-primary">
        <div class="card-header bg-primary text-white">
            <h2 class="card-title mb-0">
                <i class="fas fa-chart-pie"></i>Form Completion Progress
            </h2>
        </div>
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-4 text-center">
                    <div class="progress-circle" style="width: 150px; height: 150px; margin: 0 auto; position: relative;">
                        <svg width="150" height="150" style="transform: rotate(-90deg);">
                            <circle cx="75" cy="75" r="65" stroke="#e5e7eb" stroke-width="10" fill="none"></circle>
                            <circle cx="75" cy="75" r="65" stroke="<?php echo $department_progress['progress_percentage'] >= 100 ? '#10b981' : ($department_progress['progress_percentage'] >= 50 ? '#f59e0b' : '#ef4444'); ?>" 
                                    stroke-width="10" fill="none" 
                                    stroke-dasharray="<?php echo 2 * M_PI * 65; ?>" 
                                    stroke-dashoffset="<?php echo 2 * M_PI * 65 * (1 - $department_progress['progress_percentage'] / 100); ?>"
                                    stroke-linecap="round"></circle>
                        </svg>
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                            <div class="h2 mb-0" style="color: <?php echo $department_progress['progress_percentage'] >= 100 ? '#10b981' : ($department_progress['progress_percentage'] >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                <?php echo $department_progress['progress_percentage']; ?>%
                            </div>
                            <small class="text-muted">Complete</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <h4 class="mb-3">Overall Progress</h4>
                    <div class="progress mb-3" style="height: 30px;">
                        <div class="progress-bar <?php echo $department_progress['progress_percentage'] >= 100 ? 'bg-success' : ($department_progress['progress_percentage'] >= 50 ? 'bg-warning' : 'bg-danger'); ?>" 
                             style="width: <?php echo $department_progress['progress_percentage']; ?>%">
                            <strong><?php echo $department_progress['progress_percentage']; ?>%</strong>
                        </div>
                    </div>
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="h4 text-success mb-0"><?php echo $department_progress['completed_forms']; ?></div>
                            <small class="text-muted">Completed</small>
                        </div>
                        <div class="col-4">
                            <div class="h4 text-warning mb-0"><?php echo $department_progress['total_forms'] - $department_progress['completed_forms']; ?></div>
                            <small class="text-muted">Pending</small>
                        </div>
                        <div class="col-4">
                            <div class="h4 text-info mb-0"><?php echo $department_progress['total_forms']; ?></div>
                            <small class="text-muted">Total Forms</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Department Overview -->
    <div class="card mb-6">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-building"></i>Department Overview
            </h2>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-8">
                    <p class="mb-4">Manage your department's NIRF ranking data efficiently with our comprehensive portal. Track progress, update information, and ensure compliance with all requirements.</p>

                    <div class="d-flex gap-4">
                        <div class="text-center">
                            <div class="h3 text-primary mb-1"><?php echo htmlspecialchars($A_YEAR ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                            <small class="text-muted">Academic Year</small>
                        </div>
                        <div class="text-center">
                            <div class="h3 text-success mb-1"><?php echo htmlspecialchars($dept_code ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                            <small class="text-muted">Department Code</small>
                        </div>
                        <div class="text-center">
                            <div class="h3 text-info mb-1"><?php echo date('M Y'); ?></div>
                            <small class="text-muted">Current Month</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-center">
                    <div class="user-avatar" style="width: 80px; height: 80px; font-size: 2rem; margin: 0 auto;">
                        <?php 
                        $display_name = $_SESSION['admin_username'] ?? $email ?? $userInfo['EMAIL'] ?? '';
                        echo htmlspecialchars(strtoupper(substr($display_name, 0, 1)), ENT_QUOTES, 'UTF-8'); 
                        ?>
                    </div>
                    <h5 class="mt-3 mb-1"><?php echo htmlspecialchars($_SESSION['admin_username'] ?? $email ?? $userInfo['EMAIL'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h5>
                    <small class="text-muted">Department Administrator</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Progress Overview -->
    <?php if ($department_progress && !empty($department_progress['form_status'])): ?>
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-tasks"></i>Form Completion Status
            </h3>
        </div>
        <div class="card-body">
            <div class="row">
                <?php 
                // Map form pages to table names
                $form_pages = [
                    'profile.php' => 'department_profiles',
                    'DetailsOfDepartment.php' => 'brief_details_of_the_department',
                    'Programmes_Offered.php' => 'programmes',
                    'ExecutiveDevelopment.php' => 'exec_dev',
                    'IntakeActualStrength.php' => 'intake_actual_strength',
                    'PlacementDetails.php' => 'placement_details',
                    'SalaryDetails.php' => 'salary_details',
                    'EmployerDetails.php' => 'employers_details',
                    'phd.php' => 'phd_details',
                    'FacultyDetails.php' => 'faculty_details',
                    'AcademicPeers.php' => 'academic_peers',
                    'FacultyOutput.php' => 'faculty_output',
                    'NEPInitiatives.php' => 'nepmarks',
                    'Departmental_Governance.php' => 'department_data',
                    'StudentSupport.php' => 'studentsupport',
                    'ConferencesWorkshops.php' => 'conferences_workshops',
                    'Collaborations.php' => 'collaborations'
                ];
                
                foreach ($department_progress['form_status'] as $form): 
                    $form_page = array_search($form['form_table'], $form_pages);
                    $is_completed = $form['is_completed'];
                    $badge_class = $is_completed ? 'bg-success' : 'bg-secondary';
                    $icon = $is_completed ? 'fa-check-circle' : 'fa-clock';
                ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="card border <?php echo $is_completed ? 'border-success' : 'border-secondary'; ?>">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0"><?php echo htmlspecialchars($form['form_name']); ?></h6>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                    </span>
                                </div>
                                <div class="progress mb-2" style="height: 6px;">
                                    <div class="progress-bar <?php echo $is_completed ? 'bg-success' : 'bg-secondary'; ?>" 
                                         style="width: <?php echo $is_completed ? 100 : 0; ?>%"></div>
                                </div>
                                <small class="text-muted">Weight: <?php echo $form['weight']; ?>%</small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick Stats -->
    <div class="row mb-6">
        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon mb-3">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h4 class="stat-number">Student Management</h4>
                    <p class="stat-description">Manage student intake, placements, and academic records</p>
                    <?php 
                    $intake_progress = $department_progress ? getFormProgress($dept_id, $academic_year, 'intake_actual_strength') : null;
                    $intake_badge = $intake_progress && $intake_progress['is_completed'] ? '<span class="badge bg-success mb-2"><i class="fas fa-check"></i> Completed</span>' : '<span class="badge bg-secondary mb-2"><i class="fas fa-clock"></i> Pending</span>';
                    ?>
                    <?php echo $intake_badge; ?>
                    <br>
                    <a href="IntakeActualStrength.php" class="btn btn-outline-primary btn-sm mt-2">
                        <i class="fas fa-arrow-right me-1"></i>View Details
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon mb-3">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <h4 class="stat-number">Faculty Management</h4>
                    <p class="stat-description">Track faculty details, qualifications, and research output</p>
                    <?php 
                    $faculty_progress = $department_progress ? getFormProgress($dept_id, $academic_year, 'faculty_details') : null;
                    $faculty_badge = $faculty_progress && $faculty_progress['is_completed'] ? '<span class="badge bg-success mb-2"><i class="fas fa-check"></i> Completed</span>' : '<span class="badge bg-secondary mb-2"><i class="fas fa-clock"></i> Pending</span>';
                    ?>
                    <?php echo $faculty_badge; ?>
                    <br>
                    <a href="FacultyDetails.php" class="btn btn-outline-primary btn-sm mt-2">
                        <i class="fas fa-arrow-right me-1"></i>View Details
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon mb-3">
                        <i class="fas fa-briefcase"></i>
                    </div>
                    <h4 class="stat-number">Placement & Salary</h4>
                    <p class="stat-description">Monitor placement statistics and salary information</p>
                    <?php 
                    $placement_progress = $department_progress ? getFormProgress($dept_id, $academic_year, 'placement_details') : null;
                    $placement_badge = $placement_progress && $placement_progress['is_completed'] ? '<span class="badge bg-success mb-2"><i class="fas fa-check"></i> Completed</span>' : '<span class="badge bg-secondary mb-2"><i class="fas fa-clock"></i> Pending</span>';
                    ?>
                    <?php echo $placement_badge; ?>
                    <br>
                    <a href="PlacementDetails.php" class="btn btn-outline-primary btn-sm mt-2">
                        <i class="fas fa-arrow-right me-1"></i>View Details
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon mb-3">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h4 class="stat-number">Research & Development</h4>
                    <p class="stat-description">Track research output, collaborations, and initiatives</p>
                    <?php 
                    $output_progress = $department_progress ? getFormProgress($dept_id, $academic_year, 'faculty_output') : null;
                    $output_badge = $output_progress && $output_progress['is_completed'] ? '<span class="badge bg-success mb-2"><i class="fas fa-check"></i> Completed</span>' : '<span class="badge bg-secondary mb-2"><i class="fas fa-clock"></i> Pending</span>';
                    ?>
                    <?php echo $output_badge; ?>
                    <br>
                    <a href="FacultyOutput.php" class="btn btn-outline-primary btn-sm mt-2">
                        <i class="fas fa-arrow-right me-1"></i>View Details
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row">
        <!-- Left Column -->
        <div class="col-lg-8">
            <!-- Quick Actions -->
            <div class="card mb-6">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-bolt"></i>Quick Actions
                    </h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <a href="profile.php" class="quick-action-btn">
                                <div class="action-icon">
                                    <i class="fas fa-user-edit"></i>
                                </div>
                                <div class="action-content">
                                    <h5>Update Profile</h5>
                                    <p>Manage your department profile and basic information</p>
                                    <?php 
                                    $profile_progress = $department_progress ? getFormProgress($dept_id, $academic_year, 'department_profiles') : null;
                                    if ($profile_progress): 
                                        $badge_class = $profile_progress['is_completed'] ? 'bg-success' : 'bg-secondary';
                                        $icon = $profile_progress['is_completed'] ? 'fa-check' : 'fa-clock';
                                    ?>
                                        <span class="badge <?php echo $badge_class; ?> mt-1">
                                            <i class="fas <?php echo $icon; ?>"></i> <?php echo $profile_progress['is_completed'] ? 'Completed' : 'Pending'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </div>

                        <div class="col-md-6 mb-3">
                            <a href="DetailsOfDepartment.php" class="quick-action-btn">
                                <div class="action-icon">
                                    <i class="fas fa-building"></i>
                                </div>
                                <div class="action-content">
                                    <h5>Department Details</h5>
                                    <p>Update comprehensive department information</p>
                                    <?php 
                                    $dept_progress = $department_progress ? getFormProgress($dept_id, $academic_year, 'brief_details_of_the_department') : null;
                                    if ($dept_progress): 
                                        $badge_class = $dept_progress['is_completed'] ? 'bg-success' : 'bg-secondary';
                                        $icon = $dept_progress['is_completed'] ? 'fa-check' : 'fa-clock';
                                    ?>
                                        <span class="badge <?php echo $badge_class; ?> mt-1">
                                            <i class="fas <?php echo $icon; ?>"></i> <?php echo $dept_progress['is_completed'] ? 'Completed' : 'Pending'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </div>

                        <div class="col-md-6 mb-3">
                            <a href="Programmes_Offered.php" class="quick-action-btn">
                                <div class="action-icon">
                                    <i class="fas fa-graduation-cap"></i>
                                </div>
                                <div class="action-content">
                                    <h5>Programmes Offered</h5>
                                    <p>Manage academic programmes and courses</p>
                                    <?php 
                                    $prog_progress = $department_progress ? getFormProgress($dept_id, $academic_year, 'programmes') : null;
                                    if ($prog_progress): 
                                        $badge_class = $prog_progress['is_completed'] ? 'bg-success' : 'bg-secondary';
                                        $icon = $prog_progress['is_completed'] ? 'fa-check' : 'fa-clock';
                                    ?>
                                        <span class="badge <?php echo $badge_class; ?> mt-1">
                                            <i class="fas <?php echo $icon; ?>"></i> <?php echo $prog_progress['is_completed'] ? 'Completed' : 'Pending'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </div>

                        <div class="col-md-6 mb-3">
                            <a href="SalaryDetails.php" class="quick-action-btn">
                                <div class="action-icon">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="action-content">
                                    <h5>Salary Details</h5>
                                    <p>Update faculty and staff salary information</p>
                                    <?php 
                                    $salary_progress = $department_progress ? getFormProgress($dept_id, $academic_year, 'salary_details') : null;
                                    if ($salary_progress): 
                                        $badge_class = $salary_progress['is_completed'] ? 'bg-success' : 'bg-secondary';
                                        $icon = $salary_progress['is_completed'] ? 'fa-check' : 'fa-clock';
                                    ?>
                                        <span class="badge <?php echo $badge_class; ?> mt-1">
                                            <i class="fas <?php echo $icon; ?>"></i> <?php echo $salary_progress['is_completed'] ? 'Completed' : 'Pending'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>Recent Activity
                    </h3>
                </div>
                <div class="card-body">
                    <div class="activity-list">
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="activity-content">
                                <h6>Profile Updated</h6>
                                <p class="text-muted">Department profile information was updated</p>
                                <small class="text-muted">2 hours ago</small>
                            </div>
                        </div>

                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <div class="activity-content">
                                <h6>Student Data Added</h6>
                                <p class="text-muted">New student intake information was recorded</p>
                                <small class="text-muted">1 day ago</small>
                            </div>
                        </div>

                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-file-upload"></i>
                            </div>
                            <div class="activity-content">
                                <h6>Documents Uploaded</h6>
                                <p class="text-muted">Supporting documents were uploaded successfully</p>
                                <small class="text-muted">3 days ago</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div class="col-lg-4">
            <!-- System Status -->
            <div class="card mb-6">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-server"></i>System Status
                    </h3>
                </div>
                <div class="card-body">
                    <div class="status-item">
                        <div class="status-indicator success"></div>
                        <div class="status-content">
                            <h6>Database Connection</h6>
                            <p class="text-muted">Connected and operational</p>
                        </div>
                    </div>

                    <div class="status-item">
                        <div class="status-indicator success"></div>
                        <div class="status-content">
                            <h6>File Upload System</h6>
                            <p class="text-muted">All systems operational</p>
                        </div>
                    </div>

                    <div class="status-item">
                        <div class="status-indicator warning"></div>
                        <div class="status-content">
                            <h6>Data Backup</h6>
                            <p class="text-muted">Last backup: 2 days ago</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Important Links -->
            <div class="card mb-6">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-link"></i>Important Links
                    </h3>
                </div>
                <div class="card-body">
                    <div class="link-list">
                        <a href="https://www.nirfindia.org/" target="_blank" class="link-item">
                            <i class="fas fa-external-link-alt"></i>
                            <span>NIRF Official Website</span>
                        </a>

                        <a href="https://www.univofmumbai.ac.in/" target="_blank" class="link-item">
                            <i class="fas fa-external-link-alt"></i>
                            <span>University of Mumbai</span>
                        </a>

                        <a href="Changepwd.php" class="link-item">
                            <i class="fas fa-key"></i>
                            <span>Change Password</span>
                        </a>

                        <a href="../logout.php" class="link-item danger">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Help & Support -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-question-circle"></i>Help & Support
                    </h3>
                </div>
                <div class="card-body">
                    <p class="mb-3">Need assistance? Contact our support team for help with:</p>
                    <ul class="help-list">
                        <li><i class="fas fa-check text-success"></i> Data entry guidance</li>
                        <li><i class="fas fa-check text-success"></i> Technical support</li>
                        <li><i class="fas fa-check text-success"></i> Document upload issues</li>
                        <li><i class="fas fa-check text-success"></i> System troubleshooting</li>
                    </ul>
                    <a href="mailto:support@uomdcs.univofmumbai.in" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-envelope me-1"></i>Contact Support
                    </a>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="card">
        <div class="card-body text-center">
            <div class="login-prompt">
                <i class="fas fa-lock fa-3x text-primary mb-4"></i>
                <h3>Please log in to access this page</h3>
                <p class="text-muted mb-4">You need to be logged in to view the dashboard</p>
                <a href="../unified_login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['status']) && $_SESSION['status'] == 0): ?>
    <!-- Password Reset Modal -->
    <div class="modal-overlay show" id="resetModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-key"></i>Reset Your Password
                </h3>
            </div>
            <form method="POST" action="pop_reset_password.php">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_pass" placeholder="Enter new password" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<style>
    /* Dashboard Specific Styles */
    .stat-card {
        transition: var(--transition-normal);
        border: 2px solid var(--gray-200);
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-xl);
        border-color: var(--primary-color);
    }

    .stat-icon {
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
        color: var(--white);
        font-size: 1.5rem;
    }

    .stat-number {
        font-size: var(--font-size-lg);
        font-weight: 600;
        color: var(--gray-800);
        margin-bottom: var(--spacing-2);
    }

    .stat-description {
        font-size: var(--font-size-sm);
        color: var(--gray-600);
        margin-bottom: var(--spacing-3);
    }

    .quick-action-btn {
        display: flex;
        align-items: center;
        padding: var(--spacing-4);
        background: var(--gray-50);
        border: 2px solid var(--gray-200);
        border-radius: var(--radius-lg);
        text-decoration: none;
        color: var(--gray-800);
        transition: var(--transition-normal);
        height: 100%;
    }

    .quick-action-btn:hover {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        color: var(--white);
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
    }

    .action-icon {
        width: 50px;
        height: 50px;
        background: var(--primary-color);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--white);
        font-size: 1.2rem;
        margin-right: var(--spacing-3);
        flex-shrink: 0;
    }

    .action-content h5 {
        font-size: var(--font-size-base);
        font-weight: 600;
        margin-bottom: var(--spacing-1);
    }

    .action-content p {
        font-size: var(--font-size-sm);
        color: var(--gray-600);
        margin: 0;
    }

    .activity-list {
        display: flex;
        flex-direction: column;
        gap: var(--spacing-4);
    }

    .activity-item {
        display: flex;
        align-items: flex-start;
        gap: var(--spacing-3);
        padding: var(--spacing-3);
        background: var(--gray-50);
        border-radius: var(--radius-lg);
        border: 1px solid var(--gray-200);
    }

    .activity-icon {
        width: 40px;
        height: 40px;
        background: var(--primary-color);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--white);
        font-size: var(--font-size-sm);
        flex-shrink: 0;
    }

    .activity-content h6 {
        font-size: var(--font-size-sm);
        font-weight: 600;
        color: var(--gray-800);
        margin-bottom: var(--spacing-1);
    }

    .activity-content p {
        font-size: var(--font-size-xs);
        color: var(--gray-600);
        margin-bottom: var(--spacing-1);
    }

    .status-item {
        display: flex;
        align-items: center;
        gap: var(--spacing-3);
        padding: var(--spacing-3) 0;
        border-bottom: 1px solid var(--gray-200);
    }

    .status-item:last-child {
        border-bottom: none;
    }

    .status-indicator {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .status-indicator.success {
        background: var(--success-color);
    }

    .status-indicator.warning {
        background: var(--warning-color);
    }

    .status-indicator.danger {
        background: var(--danger-color);
    }

    .status-content h6 {
        font-size: var(--font-size-sm);
        font-weight: 600;
        color: var(--gray-800);
        margin-bottom: var(--spacing-1);
    }

    .status-content p {
        font-size: var(--font-size-xs);
        color: var(--gray-600);
        margin: 0;
    }

    .link-list {
        display: flex;
        flex-direction: column;
        gap: var(--spacing-2);
    }

    .link-item {
        display: flex;
        align-items: center;
        gap: var(--spacing-2);
        padding: var(--spacing-2) var(--spacing-3);
        color: var(--gray-700);
        text-decoration: none;
        border-radius: var(--radius-md);
        transition: var(--transition-fast);
    }

    .link-item:hover {
        background: var(--gray-100);
        color: var(--gray-800);
    }

    .link-item.danger {
        color: var(--danger-color);
    }

    .link-item.danger:hover {
        background: var(--danger-color);
        color: var(--white);
    }

    .help-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .help-list li {
        display: flex;
        align-items: center;
        gap: var(--spacing-2);
        padding: var(--spacing-1) 0;
        font-size: var(--font-size-sm);
    }

    .login-prompt {
        padding: var(--spacing-8);
    }

    @media (max-width: 768px) {
        .quick-action-btn {
            flex-direction: column;
            text-align: center;
        }

        .action-icon {
            margin-right: 0;
            margin-bottom: var(--spacing-2);
        }

        .stat-card .card-body {
            padding: var(--spacing-4);
        }
    }
</style>

<?php require "unified_footer.php"; ?>

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

    body {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        min-height: 100vh;
        margin: 0;
        padding: 2rem 0;
    }

    .container-fluid {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 1rem;
    }

    .dashboard-container {
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

    .dashboard-container::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    }

    .dashboard-header {
        margin-bottom: 3rem;
        text-align: center;
    }

    .dashboard-title {
        font-size: 3rem;
        font-weight: 700;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 1rem;
    }

    .welcome-message h2 {
        color: var(--dark-color);
        font-weight: 600;
        font-size: 1.5rem;
        margin: 0;
    }

    .dashboard-card {
        background: rgba(255, 255, 255, 0.9);
        border-radius: var(--border-radius);
        box-shadow: var(--box-shadow);
        margin-bottom: 2rem;
        border: 1px solid rgba(255, 255, 255, 0.2);
        overflow: hidden;
    }

    .card-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 1.5rem;
        border: none;
    }

    .card-header h5 {
        margin: 0;
        font-weight: 600;
        font-size: 1.2rem;
    }

    .card-body {
        padding: 2rem;
    }

    .stat-card {
        display: flex;
        align-items: center;
        padding: 1.5rem;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
        border-radius: var(--border-radius);
        border: 2px solid rgba(102, 126, 234, 0.1);
        margin-bottom: 1.5rem;
        transition: var(--transition);
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        border-color: var(--primary-color);
    }

    .stat-icon {
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 1.5rem;
        color: white;
        font-size: 1.5rem;
    }

    .stat-content h3 {
        color: var(--dark-color);
        font-weight: 600;
        margin-bottom: 0.5rem;
        font-size: 1.1rem;
    }

    .stat-content p {
        color: #6c757d;
        margin-bottom: 1rem;
        font-size: 0.9rem;
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
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
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

    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
    }

    .quick-actions {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .quick-action-btn {
        display: flex;
        align-items: center;
        padding: 1rem;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
        border: 2px solid rgba(102, 126, 234, 0.1);
        border-radius: var(--border-radius);
        text-decoration: none;
        color: var(--dark-color);
        transition: var(--transition);
        font-weight: 500;
    }

    .quick-action-btn:hover {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
    }

    .quick-action-btn i {
        font-size: 1.2rem;
        margin-right: 1rem;
        width: 20px;
    }

    .login-prompt {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 400px;
    }

    .login-prompt-content {
        text-align: center;
        padding: 3rem;
        background: rgba(255, 255, 255, 0.9);
        border-radius: var(--border-radius);
        box-shadow: var(--box-shadow);
    }

    .login-prompt-content i {
        font-size: 4rem;
        color: var(--primary-color);
        margin-bottom: 1rem;
    }

    .login-prompt-content h3 {
        color: var(--dark-color);
        margin-bottom: 2rem;
        font-weight: 600;
    }

    .alert {
        border-radius: var(--border-radius);
        border: none;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .alert-success {
        background: linear-gradient(135deg, rgba(40, 167, 69, 0.1) 0%, rgba(40, 167, 69, 0.05) 100%);
        border-left: 4px solid var(--success-color);
        color: var(--dark-color);
    }

    .modal-content {
        border-radius: var(--border-radius);
        border: none;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border: none;
        border-radius: var(--border-radius) var(--border-radius) 0 0;
    }

    .modal-title {
        font-weight: 600;
    }

    .form-control {
        border-radius: 0.75rem;
        border: 2px solid #e2e8f0;
        background: #fff;
        font-size: 1rem;
        padding: 0.875rem 1.25rem;
        transition: var(--transition);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .form-control:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.15);
        background: #fff;
    }

    .form-label {
        font-weight: 600;
        color: var(--dark-color);
        margin-bottom: 0.75rem;
    }

    @media (max-width: 768px) {
        .dashboard-container {
            padding: 1.5rem;
            margin: 1rem;
        }

        .dashboard-title {
            font-size: 2rem;
        }

        .stat-card {
            flex-direction: column;
            text-align: center;
        }

        .stat-icon {
            margin-right: 0;
            margin-bottom: 1rem;
        }
    }
</style>