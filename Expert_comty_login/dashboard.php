<?php
// Expert_comty_login/dashboard.php - Expert Committee Dashboard
// Set timezone FIRST
if (!date_default_timezone_get() || date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Start output buffering
if (ob_get_level() === 0) {
    ob_start();
}

try {
require('session.php');
    require('expert_functions.php');
    require('data_fetcher.php');
    
    // Try to load chairman functions (optional - for remarks feature)
    $chairman_functions_loaded = false;
    $chairman_functions_path = __DIR__ . '/../Chairman_login/functions.php';
    if (file_exists($chairman_functions_path)) {
        try {
            require_once($chairman_functions_path);
            $chairman_functions_loaded = true;
        } catch (Exception $e) {
            error_log("Could not load chairman functions: " . $e->getMessage());
        }
    }
    
    // Ensure $email is set (from session.php)
    if (!isset($email) || empty($email)) {
        // Fallback to session if $email not set
        $email = $_SESSION['admin_username'] ?? '';
        if (empty($email)) {
            header('Location: ../unified_login.php');
            exit;
        }
    }
    
    // Ensure $conn is available
    if (!isset($conn) || !$conn) {
        require_once(__DIR__ . '/../config.php');
    }
    
    // Get chairman remarks for this expert (with error handling)
    $unread_remarks_count = 0;
    $expert_remarks = [];
    if ($chairman_functions_loaded) {
        try {
            if (function_exists('getUnreadRemarksCount') && function_exists('getExpertRemarks')) {
                $unread_remarks_count = getUnreadRemarksCount($email);
                $expert_remarks = getExpertRemarks($email);
            }
        } catch (Exception $e) {
            error_log("Error loading chairman remarks: " . $e->getMessage());
            // Continue without remarks if functions fail
            $unread_remarks_count = 0;
            $expert_remarks = [];
        } catch (Error $e) {
            error_log("Fatal error loading chairman remarks: " . $e->getMessage());
            // Continue without remarks if functions fail
            $unread_remarks_count = 0;
            $expert_remarks = [];
        }
    }
} catch (Exception $e) {
    ob_end_clean();
    die("Error loading dashboard: " . $e->getMessage() . "<br>File: " . $e->getFile() . "<br>Line: " . $e->getLine());
} catch (Error $e) {
    ob_end_clean();
    die("Fatal error: " . $e->getMessage() . "<br>File: " . $e->getFile() . "<br>Line: " . $e->getLine());
}

// Get expert's assigned category (each expert has one category)
$expert_category = getExpertCategory($email);

// Initialize variables
$no_category = false;
$departments = [];
$academic_year = getAcademicYear();
$total_depts = 0;
$pending_count = 0;
$completed_count = 0;
$in_progress_count = 0;

if (!$expert_category) {
    // If no category assigned, show message
    $no_category = true;
    $departments = [];
} else {
    $no_category = false;
    
    // Get all departments for this expert's category
    $departments = getDepartmentsByCategory($expert_category, $academic_year);
    
    // Debug: Log what we found
    error_log("[Expert Dashboard] Expert: $email, Category: $expert_category");
    error_log("[Expert Dashboard] Academic Year: $academic_year");
    error_log("[Expert Dashboard] Found " . count($departments) . " departments");
    
    // If no departments found, log actual categories in database for debugging
    if (empty($departments)) {
        global $conn;
        // Check department_profiles (primary source for categories)
        $debug_query = "SELECT DISTINCT 
                        dp.category as category_name,
                        COUNT(DISTINCT dp.dept_id) as count 
                       FROM department_profiles dp
                       WHERE dp.A_YEAR = ? 
                         AND dp.category IS NOT NULL
                         AND dp.category != ''
                       GROUP BY dp.category
                       ORDER BY dp.category";
        $debug_stmt = $conn->prepare($debug_query);
        if ($debug_stmt) {
            $debug_stmt->bind_param("s", $academic_year);
            $debug_stmt->execute();
            $debug_result = $debug_stmt->get_result();
            $actual_categories = [];
            while ($debug_row = $debug_result->fetch_assoc()) {
                $actual_categories[] = $debug_row['category_name'] . " (" . $debug_row['count'] . " depts)";
            }
            $debug_stmt->close();
            error_log("[Expert Dashboard] Actual categories in department_profiles: " . implode(", ", $actual_categories));
            error_log("[Expert Dashboard] Expert's category: '$expert_category' (length: " . strlen($expert_category) . ")");
        }
    }
    
    // Get review status for each department
    if (!empty($departments) && is_array($departments)) {
        foreach ($departments as &$dept) {
            try {
                $status = getDepartmentReviewStatus($email, $dept['DEPT_ID'], $academic_year);
                $dept['review_status'] = $status['status'] ?? 'pending';
                $dept['is_locked'] = $status['is_locked'] ?? false;
                
                // CRITICAL: Use centralized calculation function for consistency
                // This ensures all sections are calculated using the same logic as review_complete.php
                $dept_data_temp = fetchAllDepartmentData($dept['DEPT_ID'], $academic_year);
                $scores = recalculateAllSectionsFromData($dept['DEPT_ID'], $academic_year, $dept_data_temp, true);
                
                $dept['dept_total_score'] = $scores['total'] ?? 0;
                
                error_log("[Expert Dashboard] Dept " . $dept['DEPT_ID'] . " FINAL SCORES BREAKDOWN:");
                error_log("  Section I (Faculty Output): " . $scores['section_1']);
                error_log("  Section II (NEP Initiatives): " . $scores['section_2']);
                error_log("  Section III (Departmental Governance): " . $scores['section_3']);
                error_log("  Section IV (Student Support): " . $scores['section_4']);
                error_log("  Section V (Conferences & Collaborations): " . $scores['section_5']);
                error_log("  TOTAL: " . $scores['total'] . " / 725");
                
                // Note: Cache is already updated by recalculateAllSectionsFromData(), no need to update again
                
                // Get expert scores if review exists
                $review = getExpertReview($email, $dept['DEPT_ID'], $academic_year);
                $dept['expert_total_score'] = $review ? (float)($review['expert_total_score'] ?? 0) : null;
            } catch (Exception $e) {
                error_log("Error processing department {$dept['DEPT_ID']}: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                $dept['review_status'] = 'pending';
                $dept['is_locked'] = false;
                // Try to get scores even if there's an error
                try {
                    $scores_fallback = getDepartmentScores($dept['DEPT_ID'], $academic_year);
                    $dept['dept_total_score'] = $scores_fallback['total'] ?? 0;
                } catch (Exception $e2) {
                    error_log("Error getting fallback scores for dept {$dept['DEPT_ID']}: " . $e2->getMessage());
                    $dept['dept_total_score'] = 0;
                }
                $dept['expert_total_score'] = null;
            }
        }
        unset($dept);
        
        // Sort departments by expert score (descending), then by dept score if expert score is null
        // Safety: Ensure departments is an array before sorting
        if (is_array($departments) && count($departments) > 0) {
            try {
                usort($departments, function($a, $b) {
                    // Safety: Ensure arrays exist
                    if (!is_array($a) || !is_array($b)) {
                        return 0;
                    }
                    
                    // CRITICAL: Ranking is based on expert_total_score (consolidated score), not dept_auto_score
                    $score_a = isset($a['expert_total_score']) && $a['expert_total_score'] !== null 
                        ? (float)$a['expert_total_score'] 
                        : (float)($a['dept_total_score'] ?? 0);
                    $score_b = isset($b['expert_total_score']) && $b['expert_total_score'] !== null 
                        ? (float)$b['expert_total_score'] 
                        : (float)($b['dept_total_score'] ?? 0);
                    
                    // Prioritize departments with expert scores (consolidated scores)
                    $a_has_expert = isset($a['expert_total_score']) && $a['expert_total_score'] !== null;
                    $b_has_expert = isset($b['expert_total_score']) && $b['expert_total_score'] !== null;
                    
                    if ($a_has_expert && !$b_has_expert) {
                        return -1; // a comes first (has expert score)
                    }
                    if (!$a_has_expert && $b_has_expert) {
                        return 1; // b comes first (has expert score)
                    }
                    
                    return $score_b <=> $score_a; // Descending order
                });
            } catch (Exception $e) {
                error_log("Error sorting expert departments: " . $e->getMessage());
                // Continue with unsorted array if sorting fails
            }
        }
        
        // Count statistics
        $total_depts = count($departments);
        $pending_count = count(array_filter($departments, function($d) { return ($d['review_status'] ?? 'pending') === 'pending'; }));
        $completed_count = count(array_filter($departments, function($d) { return ($d['review_status'] ?? 'pending') === 'completed' || ($d['is_locked'] ?? false); }));
        $in_progress_count = count(array_filter($departments, function($d) { return ($d['review_status'] ?? 'pending') === 'in_progress'; }));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expert Committee Dashboard - MU NIRF Portal</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .dashboard-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            margin: 2rem auto;
            padding: 2rem;
            max-width: 1400px;
        }
        .header-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            border-left: 4px solid;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
        }
        .stat-card.pending { border-left-color: #ffc107; }
        .stat-card.in-progress { border-left-color: #17a2b8; }
        .stat-card.completed { border-left-color: #28a745; }
        .stat-card.total { border-left-color: #667eea; }
        .dept-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border-left: 4px solid #e0e0e0;
        }
        .dept-card:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            transform: translateX(5px);
        }
        .dept-card.pending { border-left-color: #ffc107; }
        .dept-card.in_progress { border-left-color: #17a2b8; }
        .dept-card.completed { border-left-color: #28a745; }
        .dept-card.locked { border-left-color: #6c757d; background: #f8f9fa; }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        .status-badge.in_progress { background: #d1ecf1; color: #0c5460; }
        .status-badge.completed { background: #d4edda; color: #155724; }
        .status-badge.locked { background: #e2e3e5; color: #383d41; }
        .score-display {
            font-size: 1.1rem;
            font-weight: 600;
        }
        .score-dept { color: #667eea; }
        .score-expert { color: #28a745; }
        .btn-review {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-review:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
            color: white;
        }
        .btn-review.locked {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
        }
        .btn-review.locked:hover {
            background: linear-gradient(135deg, #5a6268 0%, #6c757d 100%);
        }
        
        /* Chairman Remarks section removed - now on dedicated notifications page */
    </style>
</head>
<body>
    <!-- Header -->
    <header class="bg-white shadow-lg mb-4">
        <div class="container-fluid px-4 py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <img src="../assets/img/mumbai-university-removebg-preview.png" class="me-3" style="height: 50px;" alt="MU Logo">
                    <div>
                        <h1 class="h4 mb-0 text-gray-800">Expert Committee Dashboard</h1>
                        <small class="text-muted">Category: <?php echo htmlspecialchars($expert_category ?? 'Not Assigned'); ?></small>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <a href="notifications.php" class="btn btn-warning btn-sm position-relative">
                        <i class="fas fa-bell"></i> Notifications
                        <?php if ($unread_remarks_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?php echo $unread_remarks_count; ?>
                        </span>
                        <?php endif; ?>
                    </a>
                    <span class="text-muted">Welcome, <?php echo htmlspecialchars($email); ?></span>
                    <a href="Changepwd.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-key me-1"></i>Change Password
                    </a>
                    <a href="../logout.php" class="btn btn-danger btn-sm">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container-fluid">
        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['remark_marked'])): ?>
            <div class="alert alert-success alert-dismissible fade show mx-auto" style="max-width: 1400px; margin-top: 1rem;" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['remark_marked']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['remark_marked']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['remark_acknowledged'])): ?>
            <div class="alert alert-success alert-dismissible fade show mx-auto" style="max-width: 1400px; margin-top: 1rem;" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['remark_acknowledged']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['remark_acknowledged']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['remark_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mx-auto" style="max-width: 1400px; margin-top: 1rem;" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['remark_error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['remark_error']); ?>
        <?php endif; ?>
        
        <?php if ($no_category): ?>
            <div class="dashboard-container">
                <div class="alert alert-warning text-center">
                    <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                    <h4>No Category Assigned</h4>
                    <p>Your account has not been assigned to any department category yet. Please contact the administrator.</p>
                </div>
            </div>
        <?php else: ?>
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="stat-card total">
                        <div class="d-flex justify-content-between align-items-center">
                    <div>
                                <h6 class="text-muted mb-1">Total Departments</h6>
                                <h3 class="mb-0"><?php echo $total_depts; ?></h3>
                            </div>
                            <i class="fas fa-building fa-2x text-primary"></i>
                    </div>
                </div>
            </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card pending">
                        <div class="d-flex justify-content-between align-items-center">
                    <div>
                                <h6 class="text-muted mb-1">Pending Reviews</h6>
                                <h3 class="mb-0"><?php echo $pending_count; ?></h3>
                    </div>
                            <i class="fas fa-clock fa-2x text-warning"></i>
                </div>
            </div>
        </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card in-progress">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">In Progress</h6>
                                <h3 class="mb-0"><?php echo $in_progress_count; ?></h3>
                            </div>
                            <i class="fas fa-spinner fa-2x text-info"></i>
                </div>
            </div>
                    </div>
                <div class="col-md-3 mb-3">
                    <div class="stat-card completed">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Completed</h6>
                                <h3 class="mb-0"><?php echo $completed_count; ?></h3>
                    </div>
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                    </div>
                </div>
            </div>
        </div>

            <!-- Departments List -->
            <div class="dashboard-container">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="mb-0">
                        <i class="fas fa-list"></i> Departments - <?php echo htmlspecialchars($expert_category); ?>
                    </h2>
                    <div>
                        <span class="badge bg-primary">Academic Year: <?php echo htmlspecialchars($academic_year); ?></span>
            </div>
        </div>

                <?php if (empty($departments)): ?>
                    <div class="alert alert-warning text-center">
                        <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                        <h5>No Departments Found</h5>
                        <p>No departments found for the selected category(s) in academic year <?php echo htmlspecialchars($academic_year); ?>.</p>
                        <hr>
                        <p class="mb-2"><strong>Debugging Info:</strong></p>
                        <p class="small mb-1">Expert Category: <code><?php echo htmlspecialchars($expert_category); ?></code></p>
                        <p class="small mb-1">Category Length: <?php echo strlen($expert_category); ?> characters</p>
                        <p class="small">
                            <strong>Check:</strong><br>
                            1. Do departments exist in <code>brief_details_of_the_department</code> table?<br>
                            2. Do category names match exactly?<br>
                            3. Is academic year correct? (Current: <?php echo htmlspecialchars($academic_year); ?>)
                        </p>
                        <button class="btn btn-sm btn-info mt-2" onclick="location.reload()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Dept Code</th>
                            <th>Department Name</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Dept Score</th>
                            <th>Expert Score</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $dept): ?>
                            <tr class="<?php echo $dept['is_locked'] ? 'table-secondary' : ''; ?>">
                                <td><strong><?php echo htmlspecialchars($dept['DEPT_CODE']); ?></strong></td>
                                <td><?php echo htmlspecialchars($dept['DEPT_NAME']); ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($dept['CATEGORY'] ?? '-'); ?></span>
                                </td>
                                        <td>
                                            <span class="status-badge <?php echo $dept['is_locked'] ? 'locked' : $dept['review_status']; ?>">
                                                <?php 
                                                if ($dept['is_locked']) {
                                                    echo '<i class="fas fa-lock"></i> Locked';
                                                } else {
                                                    echo ucfirst(str_replace('_', ' ', $dept['review_status']));
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="score-display score-dept">
                                                <?php echo number_format($dept['dept_total_score'], 2); ?> / 725
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($dept['expert_total_score'] !== null): ?>
                                                <span class="score-display score-expert">
                                                    <?php echo number_format($dept['expert_total_score'], 2); ?> / 725
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="review_complete.php?dept_id=<?php echo $dept['DEPT_ID']; ?>" 
                                               class="btn btn-review btn-sm <?php echo $dept['is_locked'] ? 'locked' : ''; ?>">
                                                <i class="fas fa-clipboard-check"></i> 
                                                <?php 
                                                if ($dept['is_locked']) {
                                                    echo '<i class="fas fa-lock"></i> View Review (Locked)';
                                                } else {
                                                    echo $dept['review_status'] === 'pending' ? 'Start Review' : 'View/Edit Review';
                                                }
                                                ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Chairman Remarks section removed - now available on dedicated Notifications page -->
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Remarks-related JavaScript removed - now handled on dedicated notifications page -->
</body>
</html>
