<?php
/**
 * Verification Committee Dashboard
 * Shows categories with progress tracking
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display on production, but log
ini_set('log_errors', 1);

require('session.php');
require('functions.php');

// Check if getAcademicYear function exists
if (!function_exists('getAcademicYear')) {
    error_log("ERROR: getAcademicYear function not found. Check if expert_functions.php is included.");
    die("System error: Required functions not available. Please contact administrator.");
}

$academic_year = getAcademicYear();
$email = $_SESSION['admin_username'] ?? '';

if (empty($email)) {
    error_log("ERROR: admin_username not set in session");
    header('Location: ../index.php');
    exit;
}

$role_name = getCommitteeRoleName($email);

// If no role found, show helpful message
if (!$role_name) {
    error_log("WARNING: No role found for email: $email. User may not be assigned to verification committee.");
    // Show message but don't die - allow admin to see the issue
    $role_name = 'Not Assigned';
}

$categories = [];
try {
    $categories = getCategoriesWithVerificationProgress($email, $role_name, $academic_year);
    if (!is_array($categories)) {
        $categories = [];
    }
} catch (Exception $e) {
    error_log("ERROR in getCategoriesWithVerificationProgress: " . $e->getMessage());
    $categories = [];
}

// Calculate overall progress
$total_depts = 0;
$reviewed_depts = 0;
$pending_depts = 0;
foreach ($categories as $cat) {
    $total_depts += $cat['total_departments'];
    $reviewed_depts += $cat['reviewed'];
    $pending_depts += $cat['pending'];
}

$progress_percentage = $total_depts > 0 ? round(($reviewed_depts / $total_depts) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification Committee Dashboard - University of Mumbai</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --bg-light: #f8fafc;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: var(--bg-light);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: #1f2937;
            line-height: 1.6;
        }
        
        .top-bar {
            background: var(--primary-blue);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .top-bar-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .top-bar h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }
        
        .role-badge {
            background: rgba(255,255,255,0.2);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .btn-logout {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 0.5rem 1.5rem;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-logout:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        .container-main {
            max-width: 1400px;
            margin: 3rem auto;
            padding: 0 2rem;
        }
        
        .progress-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .progress-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .stat-box {
            text-align: center;
            padding: 1.5rem;
            background: var(--bg-light);
            border-radius: 8px;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-blue);
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        
        .progress-bar-container {
            margin-top: 1.5rem;
            background: #e5e7eb;
            border-radius: 10px;
            height: 30px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(135deg, var(--accent-green) 0%, #059669 100%);
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }
        
        .category-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            border-left: 4px solid var(--primary-blue);
        }
        
        .category-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .category-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
        }
        
        .category-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 1rem;
        }
        
        .category-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
        }
        
        .category-count {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-blue);
        }
        
        .category-progress {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .btn-view {
            background: var(--primary-blue);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
            margin-top: 1rem;
        }
        
        .btn-view:hover {
            background: #1e40af;
            transform: translateY(-1px);
        }
        
        .badge-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-reviewed {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="top-bar-content">
            <div>
                <h1><i class="fas fa-check-circle"></i> Verification Committee Dashboard</h1>
                    <div style="margin-top: 0.5rem; font-size: 0.875rem; opacity: 0.9;">
                        <span class="role-badge"><?php echo htmlspecialchars($role_name ?? 'Verification Committee'); ?></span>
                        <span style="margin-left: 1rem;"><?php echo htmlspecialchars($email); ?></span>
                    </div>
                    <?php if (!$role_name || $role_name === 'Not Assigned'): ?>
                        <div style="margin-top: 0.5rem; padding: 0.75rem; background: rgba(255,193,7,0.2); border-left: 4px solid #ffc107; border-radius: 4px;">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Notice:</strong> You are not assigned to any verification committee role. Please contact administrator to assign you a role (HRDC, AAQA, etc.).
                        </div>
                    <?php endif; ?>
            </div>
            <a href="../logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
    
    <div class="container-main">
        <!-- Overall Progress Card -->
        <div class="progress-card">
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--primary-blue); margin-bottom: 1rem;">
                <i class="fas fa-chart-line"></i> Overall Review Progress
            </h2>
            <div class="progress-stats">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $total_depts; ?></div>
                    <div class="stat-label">Total Departments</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number" style="color: var(--accent-green);"><?php echo $reviewed_depts; ?></div>
                    <div class="stat-label">Reviewed</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number" style="color: var(--accent-amber);"><?php echo $pending_depts; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $progress_percentage; ?>%</div>
                    <div class="stat-label">Completion</div>
                </div>
            </div>
            <div class="progress-bar-container">
                <div class="progress-bar" style="width: <?php echo $progress_percentage; ?>%;">
                    <?php echo $progress_percentage; ?>%
                </div>
            </div>
        </div>
        
        <!-- Categories -->
        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--primary-blue); margin-bottom: 1.5rem;">
            <i class="fas fa-folder-open"></i> Categories
        </h2>
        
        <?php if (empty($categories)): ?>
            <div class="alert alert-warning" style="background: #fff3cd; border: 1px solid #ffc107; padding: 1.5rem; border-radius: 8px;">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>No Categories Available</strong>
                <p class="mb-0 mt-2">You don't have any items assigned for verification. Please contact administrator to assign items to your role.</p>
            </div>
        <?php else: ?>
        <div class="categories-grid">
            <?php foreach ($categories as $category): ?>
                <div class="category-card" onclick="window.location.href='category.php?cat_id=<?php echo $category['id']; ?>&name=<?php echo urlencode($category['name']); ?>'">
                    <div class="category-number"><?php echo $category['id']; ?></div>
                    <div class="category-name"><?php echo htmlspecialchars($category['name']); ?></div>
                    <div class="category-stats">
                        <div>
                            <i class="fas fa-building"></i>
                            <span class="category-count"><?php echo $category['total_departments']; ?></span>
                            <span style="color: #6b7280; margin-left: 0.5rem;">Departments</span>
                        </div>
                        <div class="category-progress">
                            <?php if ($category['reviewed'] > 0): ?>
                                <span class="badge-status badge-reviewed">
                                    <i class="fas fa-check"></i> <?php echo $category['reviewed']; ?> Reviewed
                                </span>
                            <?php endif; ?>
                            <?php if ($category['pending'] > 0): ?>
                                <span class="badge-status badge-pending" style="margin-left: 0.5rem;">
                                    <i class="fas fa-clock"></i> <?php echo $category['pending']; ?> Pending
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button class="btn-view" type="button">
                        <i class="fas fa-arrow-right"></i> View Departments
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Footer -->
     <?php include '../footer_main.php';?>
</body>
</html>
