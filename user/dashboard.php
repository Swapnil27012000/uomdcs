<?php
// user/dashboard.php - User Dashboard
require('session.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - UoM NIRF Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h3 class="card-title mb-0">
                            <i class="fas fa-user"></i> User Dashboard
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <h5>Welcome, <?php echo htmlspecialchars($email); ?>!</h5>
                            <p class="mb-0">You are logged in as a user with <?php echo htmlspecialchars($permission); ?> permission.</p>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <i class="fas fa-info-circle fa-3x text-primary mb-3"></i>
                                        <h5>User Information</h5>
                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($email); ?></p>
                                        <p><strong>Permission:</strong> <?php echo htmlspecialchars($permission); ?></p>
                                        <p><strong>Table:</strong> <?php echo htmlspecialchars($table); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <i class="fas fa-cog fa-3x text-success mb-3"></i>
                                        <h5>Quick Actions</h5>
                                        <a href="../logout.php" class="btn btn-danger">
                                            <i class="fas fa-sign-out-alt"></i> Logout
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
                    <i class="fas fa-building"></i>Department Overview
                </h2>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <p class="mb-4">Manage your department's NIRF ranking data efficiently with our comprehensive portal. Track progress, update information, and ensure compliance with all requirements.</p>

                        <div class="d-flex gap-4">
                            <div class="text-center">
                                <div class="h3 text-primary mb-1"><?php echo $A_YEAR; ?></div>
                                <small class="text-muted">Academic Year</small>
                            </div>
                            <div class="text-center">
                                <div class="h3 text-success mb-1"><?php echo $dept_code; ?></div>
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
                            <?php echo strtoupper(substr($_SESSION['admin_username'], 0, 1)); ?>
                        </div>
                        <h5 class="mt-3 mb-1"><?php echo $_SESSION['admin_username']; ?></h5>
                        <small class="text-muted">Department Administrator</small>
                    </div>
                </div>
            </div>
        </div>

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
                        <a href="IntakeActualStrength.php" class="btn btn-outline-primary btn-sm">
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
                        <a href="FacultyDetails.php" class="btn btn-outline-primary btn-sm">
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
                        <a href="PlacementDetails.php" class="btn btn-outline-primary btn-sm">
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
                        <a href="FacultyOutput.php" class="btn btn-outline-primary btn-sm">
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

                            <a href="logout.php" class="link-item danger">
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
                    <a href="index.php" class="btn btn-primary">
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
    <!-- ***************** -->
</body>

</html>