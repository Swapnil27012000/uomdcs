<?php
/**
 * AAQA Dashboard - Department Management with Progress Tracking
 */

require('session.php');
require('functions.php');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$academic_year = getAcademicYear();
error_log("AAQA Dashboard: Academic Year = $academic_year");

// Get departments with error handling
try {
    $departments = getAllDepartmentsWithProgress($academic_year);
    if (!is_array($departments)) {
        $departments = [];
        error_log("AAQA Dashboard: getAllDepartmentsWithProgress returned non-array");
    }
} catch (Exception $e) {
    error_log("AAQA Dashboard Error: " . $e->getMessage());
    $departments = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AAQA Dashboard - University of Mumbai</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --accent-red: #ef4444;
            --bg-light: #f8fafc;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: white;
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
            max-width: 1600px;
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
            max-width: 1600px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: #6b7280;
            margin-bottom: 2rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        .progress-bar-container {
            min-width: 150px;
        }
        
        .progress {
            height: 24px;
            border-radius: 12px;
            background: #e5e7eb;
            overflow: hidden;
            position: relative;
        }
        
        .progress-bar {
            height: 100%;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .progress-bar.bg-success {
            background: var(--accent-green) !important;
        }
        
        .progress-bar.bg-warning {
            background: var(--accent-amber) !important;
        }
        
        .progress-bar.bg-danger {
            background: var(--accent-red) !important;
        }
        
        .progress-text {
            position: absolute;
            width: 100%;
            text-align: center;
            color: #1f2937;
            font-size: 0.75rem;
            font-weight: 600;
            z-index: 1;
        }
        
        .btn-edit {
            background: var(--accent-amber);
            color: white;
        }
        
        .btn-edit:hover {
            background: #d97706;
            color: white;
        }
        
        table.dataTable {
            border-collapse: separate;
            border-spacing: 0;
        }
        
        table.dataTable thead th {
            background: #f8fafc;
            color: var(--primary-blue);
            font-weight: 600;
            border-bottom: 2px solid #e5e7eb;
            padding: 1rem;
        }
        
        table.dataTable tbody td {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }
        
        table.dataTable tbody tr:hover {
            background: #f8fafc;
        }
        
        .badge-category {
            background: #e0e7ff;
            color: var(--primary-blue);
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .quick-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .btn-quick {
            background: var(--primary-blue);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-quick:hover {
            background: #1e40af;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #d1d5db;
            margin-bottom: 1rem;
        }
        
        .empty-state h3 {
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-blue);
            text-decoration: none;
            margin-top: 1rem;
            font-weight: 500;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .container-main {
                padding: 0 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="top-bar-content">
            <h1><i class="fas fa-clipboard-check me-2"></i>AAQA Dashboard</h1>
            <div class="d-flex align-items-center gap-3">
                <span><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'AAQA User'); ?></span>
                <a href="Changepwd.php" class="btn-logout">
                    <i class="fas fa-key me-1"></i>Change Password
                </a>
                <a href="../logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>
    </div>
    
    <div class="container-main">
        <h1 class="page-title">Department Management & Progress Tracking</h1>
        <p class="page-subtitle">Academic Year: <strong><?php echo htmlspecialchars($academic_year); ?></strong></p>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="AddDepartmentDetails.php" class="btn-quick">
                <i class="fas fa-plus-circle"></i>Add Department
            </a>
            <a href="EditDepartmentDetails.php" class="btn-quick">
                <i class="fas fa-edit"></i>Edit Department
            </a>
            <a href="AllDepartmentDetails.php" class="btn-quick">
                <i class="fas fa-list"></i>All Departments
            </a>
            <a href="delete_department.php" class="btn-quick" style="background: #ef4444;">
                <i class="fas fa-trash-alt"></i>Delete Department
            </a>
            <a href="dashboard.php" class="btn-quick" style="background: #6b7280;">
                <i class="fas fa-sync-alt"></i>Refresh
            </a>
        </div>
        
        <!-- Statistics -->
        <?php
        $total_depts = count($departments);
        $completed_depts = count(array_filter($departments, function($d) { return $d['progress']['progress_percentage'] >= 100; }));
        $in_progress = count(array_filter($departments, function($d) { 
            $p = $d['progress']['progress_percentage']; 
            return $p > 0 && $p < 100; 
        }));
        $not_started = count(array_filter($departments, function($d) { return $d['progress']['progress_percentage'] == 0; }));
        $avg_progress = $total_depts > 0 ? round(array_sum(array_column(array_column($departments, 'progress'), 'progress_percentage')) / $total_depts, 1) : 0;
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_depts; ?></div>
                <div class="stat-label">Total Departments</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: var(--accent-green);"><?php echo $completed_depts; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: var(--accent-amber);"><?php echo $in_progress; ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: var(--accent-red);"><?php echo $not_started; ?></div>
                <div class="stat-label">Not Started</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $avg_progress; ?>%</div>
                <div class="stat-label">Average Progress</div>
            </div>
        </div>
        
        <!-- Departments Table -->
        <div class="table-container">
            <?php if (empty($departments)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Departments Found</h3>
                    <p>The system is ready to track department progress. Departments will appear here once they are added to the system.</p>
                    <p class="text-muted">Academic Year: <strong><?php echo htmlspecialchars($academic_year); ?></strong></p>
                    <a href="AddDepartmentDetails.php" class="btn-quick">
                        <i class="fas fa-plus-circle"></i>Add Your First Department
                    </a>
                </div>
            <?php else: ?>
                <table id="departmentsTable" class="table table-hover">
                    <thead>
                        <tr>
                            <th>Dept Code</th>
                            <th>Department Name</th>
                            <th>Category</th>
                            <th>Email</th>
                            <th>Progress</th>
                            <th>Completed Forms</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $dept): 
                            $progress = isset($dept['progress']) ? $dept['progress']['progress_percentage'] : 0;
                            $completed = isset($dept['progress']) ? $dept['progress']['completed_forms'] : 0;
                            $total = isset($dept['progress']) ? $dept['progress']['total_forms'] : 0;
                            
                            // Determine progress bar color
                            $progress_class = 'bg-success';
                            if ($progress < 50) {
                                $progress_class = 'bg-danger';
                            } elseif ($progress < 100) {
                                $progress_class = 'bg-warning';
                            }
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($dept['DEPT_CODE'] ?? '-'); ?></strong></td>
                                <td><?php echo htmlspecialchars($dept['DEPT_NAME'] ?? '-'); ?></td>
                                <td><span class="badge-category"><?php echo htmlspecialchars($dept['CATEGORY'] ?? 'Uncategorized'); ?></span></td>
                                <td><?php echo htmlspecialchars($dept['EMAIL'] ?? '-'); ?></td>
                                <td>
                                    <div class="progress-bar-container">
                                        <div class="progress">
                                            <div class="progress-bar <?php echo $progress_class; ?>" style="width: <?php echo $progress; ?>%"></div>
                                            <div class="progress-text"><?php echo $progress; ?>%</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <small><?php echo $completed; ?> / <?php echo $total; ?> forms</small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#departmentsTable').DataTable({
                responsive: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[4, 'desc']], // Sort by progress descending
                columnDefs: []
            });
        });
    </script>
</body>
</html>
