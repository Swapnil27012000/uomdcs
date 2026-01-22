<?php
/**
 * Verification Committee - Category View
 * Lists all departments in a category
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require('session.php');
    require('functions.php');
} catch (Exception $e) {
    error_log("Error loading verification committee category page: " . $e->getMessage());
    die("Error loading page. Please contact administrator.");
}

$academic_year = getAcademicYear();
$email = $_SESSION['admin_username'] ?? '';
$role_name = getCommitteeRoleName($email);

$cat_id = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
$category_name = isset($_GET['name']) ? urldecode($_GET['name']) : '';

if (!$cat_id || empty($category_name)) {
    header('Location: dashboard.php');
    exit;
}

$departments = getDepartmentsInCategoryForVerification($category_name, $email, $role_name, $academic_year);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($category_name); ?> - Verification Committee</title>
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
        
        body {
            background: var(--bg-light);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
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
        
        .container-main {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #3b82f6 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        
        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 1rem;
        }
        
        .btn-back:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        .table-responsive {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            max-height: 600px;
            overflow-y: auto;
        }
        
        table {
            width: 100%;
        }
        
        thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        th {
            color: white;
            font-weight: 600;
            padding: 1rem;
            font-size: 0.875rem;
        }
        
        td {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .badge {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-not-verified {
            background: #e5e7eb;
            color: #6b7280;
        }
        
        .badge-verified {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-partially {
            background: #fef3c7;
            color: #92400e;
        }
        
        .btn-view {
            background: var(--primary-blue);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            font-size: 0.875rem;
        }
        
        .btn-view:hover {
            background: #1e40af;
            color: white;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="top-bar-content">
            <div>
                <h1><i class="fas fa-check-circle"></i> Verification Committee</h1>
                <div style="margin-top: 0.5rem; font-size: 0.875rem; opacity: 0.9;">
                    <span><?php echo htmlspecialchars($role_name ?? 'Verification Committee'); ?></span>
                </div>
            </div>
            <a href="../logout.php" class="btn-logout" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: white; padding: 0.5rem 1.5rem; border-radius: 6px; text-decoration: none;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
    
    <div class="container-main">
        <div class="page-header">
            <a href="dashboard.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <h1 style="font-size: 1.75rem; font-weight: 700; color: white; margin-bottom: 0.5rem;">
                <?php echo htmlspecialchars($category_name); ?>
            </h1>
            <p style="color: white; font-weight: 600; font-size: 1rem;">Academic Year: <strong><?php echo htmlspecialchars($academic_year); ?></strong></p>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Department Name</th>
                        <th>Department Code</th>
                        <th>Verification Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($departments)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 3rem; color: #6b7280;">
                                <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 1rem; display: block; opacity: 0.5;"></i>
                                No departments found in this category
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($departments as $dept): ?>
                            <tr>
                                <td style="font-weight: 600; color: var(--primary-blue);">
                                    <?php echo htmlspecialchars($dept['DEPT_NAME']); ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($dept['DEPT_CODE']); ?></strong>
                                </td>
                                <td>
                                    <?php 
                                    $status = $dept['verification_status'] ?? 'not_verified';
                                    if ($status === 'verified'): 
                                    ?>
                                        <span class="badge badge-verified">
                                            <i class="fas fa-check-circle"></i> Verified
                                        </span>
                                    <?php elseif ($status === 'partially_verified'): ?>
                                        <span class="badge badge-partially">
                                            <i class="fas fa-clock"></i> Partially Verified
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-not-verified">
                                            <i class="fas fa-clock"></i> Not Verified
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="department.php?dept_id=<?php echo $dept['DEPT_ID']; ?>&cat_id=<?php echo $cat_id; ?>&name=<?php echo urlencode($category_name); ?>" 
                                       class="btn-view">
                                        <i class="fas fa-eye"></i> Review
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
