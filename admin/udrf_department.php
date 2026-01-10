<?php
/**
 * Admin UDRF Data - Step 3: Department Detail (Read-Only)
 * Copied from Chairman_login/department.php
 */

// Set timezone FIRST
if (!date_default_timezone_get() || date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

// CRITICAL: Disable error display in production (Security Guide Section 15)
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering
if (ob_get_level() === 0) {
    ob_start();
}

try {
    require('session.php');
    require('udrf_functions.php');
    require('../Expert_comty_login/data_fetcher.php');
    // expert_functions.php is already included via udrf_functions.php
} catch (Exception $e) {
    ob_end_clean();
    error_log("Error loading udrf_department.php: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    header('Location: udrf_dashboard.php');
    exit;
} catch (Error $e) {
    ob_end_clean();
    error_log("Fatal error in udrf_department.php: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    header('Location: udrf_dashboard.php');
    exit;
}

// CRITICAL: Validate and sanitize input (Security Guide Section 5)
$dept_id_raw = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
$dept_id = resolveDepartmentId($dept_id_raw);
$cat_id = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
$category_name_raw = isset($_GET['name']) ? trim($_GET['name']) : '';
$category_name = !empty($category_name_raw) ? htmlspecialchars(urldecode($category_name_raw), ENT_QUOTES, 'UTF-8') : '';

if (!$dept_id) {
    error_log("Invalid department ID in udrf_department.php");
    header('Location: udrf_dashboard.php');
    exit;
}

// Load common functions for getAcademicYear()
if (file_exists(__DIR__ . '/../common_functions.php')) {
    require_once(__DIR__ . '/../common_functions.php');
} elseif (file_exists(__DIR__ . '/../common_progress_functions.php')) {
    require_once(__DIR__ . '/../common_progress_functions.php');
}

$academic_year = getAcademicYear();

// CRITICAL: Check connection before use (Security Guide Section 12)
if (!isset($conn) || !$conn || !@mysqli_ping($conn)) {
    error_log("Database connection unavailable in udrf_department.php");
    header('Location: udrf_dashboard.php');
    exit;
}

// Fetch department info
$dept_query = "SELECT 
        COALESCE(dn.collname, dm.DEPT_NAME) AS dept_name,
        dm.DEPT_COLL_NO AS dept_code,
        COALESCE(dp.category, bd.TYPE) AS category_label
    FROM department_master dm
    LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
    LEFT JOIN department_profiles dp ON dp.A_YEAR = ? 
        AND CAST(dp.dept_id AS UNSIGNED) IN (dm.DEPT_ID, dm.DEPT_COLL_NO)
    LEFT JOIN brief_details_of_the_department bd ON bd.DEPT_ID = dm.DEPT_ID AND bd.A_YEAR = ?
    WHERE dm.DEPT_ID = ?
    LIMIT 1";
$dept_stmt = $conn->prepare($dept_query);
$dept_name = 'Unknown';
$dept_code = '';
if ($dept_stmt) {
    $dept_stmt->bind_param("ssi", $academic_year, $academic_year, $dept_id);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    if ($dept_row = $dept_result->fetch_assoc()) {
        $dept_name = $dept_row['dept_name'] ?? $dept_name;
        $dept_code = $dept_row['dept_code'] ?? '';
    }
    if ($dept_result) {
        mysqli_free_result($dept_result);
    }
    $dept_stmt->close();
}

// Fetch ALL department data
$dept_data = fetchAllDepartmentData($dept_id, $academic_year);
$documents = fetchAllSupportingDocuments($dept_id, $academic_year);
$grouped_docs = groupDocumentsBySection($documents);

// CRITICAL: Use the same calculation method as Consolidated_Score.php
// Include section files to get accurate calculated values (same as department_review.php)
require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');

// Set variables needed by section files
$email = $userInfo['EMAIL'] ?? '';
$is_locked = false;
$is_readonly = true;
$is_department_view = false;
$is_chairman_view = false;
$expert_scores = [
    'section_1' => 0,
    'section_2' => 0,
    'section_3' => 0,
    'section_4' => 0,
    'section_5' => 0,
    'total' => 0
];
$auto_scores = [
    'section_1' => 0,
    'section_2' => 0,
    'section_3' => 0,
    'section_4' => 0,
    'section_5' => 0,
    'total' => 0
];

// Use output buffering to capture and discard HTML output from section files
ob_start();

// Include section files from Expert_comty_login directory
include(__DIR__ . '/../Expert_comty_login/section1_faculty_output.php');
if (isset($section_1_auto_total_calculated)) {
    $auto_scores['section_1'] = $section_1_auto_total_calculated;
}

include(__DIR__ . '/../Expert_comty_login/section2_nep_initiatives.php');
// Section II doesn't set $section_2_auto_total, so calculate it using the function
$sec2 = $dept_data['section_2'] ?? [];
$auto_scores['section_2'] = calculateSection2FromArray($sec2);

include(__DIR__ . '/../Expert_comty_login/section3_governance.php');
if (isset($section_3_auto_total)) {
    $auto_scores['section_3'] = $section_3_auto_total;
}

include(__DIR__ . '/../Expert_comty_login/section4_student_support.php');
if (isset($section_4_auto_total)) {
    $auto_scores['section_4'] = $section_4_auto_total;
}

include(__DIR__ . '/../Expert_comty_login/section5_conferences.php');
if (isset($section_5_auto_total)) {
    $auto_scores['section_5'] = $section_5_auto_total;
}

// Discard all HTML output from section files
ob_end_clean();

// CRITICAL: Recalculate total after all sections are included
$auto_scores['total'] = (float)$auto_scores['section_1'] + (float)$auto_scores['section_2'] + (float)$auto_scores['section_3'] + (float)$auto_scores['section_4'] + (float)$auto_scores['section_5'];

error_log("[Admin UDRF Department] FINAL SCORES BREAKDOWN for dept_id=$dept_id:");
error_log("  Section I (Faculty Output): " . $auto_scores['section_1']);
error_log("  Section II (NEP Initiatives): " . $auto_scores['section_2']);
error_log("  Section III (Departmental Governance): " . $auto_scores['section_3']);
error_log("  Section IV (Student Support): " . $auto_scores['section_4']);
error_log("  Section V (Conferences & Collaborations): " . $auto_scores['section_5']);
error_log("  TOTAL: " . $auto_scores['total'] . " / 725");

// Get all experts for this category (now supports 2 experts per category)
$expert_emails = getAllExpertsForCategory($category_name);
$expert_reviews = [];
$expert_1_review = null;
$expert_2_review = null;

// Fetch reviews from all experts
foreach ($expert_emails as $idx => $expert_email) {
    $review = getExpertReview($expert_email, $dept_id, $academic_year);
    if ($review) {
        $expert_reviews[] = $review;
        if ($idx === 0) {
            $expert_1_review = $review;
        } elseif ($idx === 1) {
            $expert_2_review = $review;
        }
    }
}

// Use first expert's review for backward compatibility (for item scores, narrative scores)
$expert_review = $expert_1_review ?? ($expert_2_review ?? null);

// Parse Expert 1 and Expert 2 item scores separately for section displays
$expert_1_item_scores = [];
$expert_2_item_scores = [];
$expert_1_narrative_scores = [];
$expert_2_narrative_scores = [];
if ($expert_1_review && !empty($expert_1_review['expert_item_scores'])) {
    $item_scores_json_1 = is_string($expert_1_review['expert_item_scores']) 
        ? $expert_1_review['expert_item_scores'] 
        : json_encode($expert_1_review['expert_item_scores']);
    $expert_1_item_scores = json_decode($item_scores_json_1, true) ?? [];
}
if ($expert_2_review && !empty($expert_2_review['expert_item_scores'])) {
    $item_scores_json_2 = is_string($expert_2_review['expert_item_scores']) 
        ? $expert_2_review['expert_item_scores'] 
        : json_encode($expert_2_review['expert_item_scores']);
    $expert_2_item_scores = json_decode($item_scores_json_2, true) ?? [];
}
if ($expert_1_review && !empty($expert_1_review['expert_narrative_scores'])) {
    $narrative_scores_json_1 = is_string($expert_1_review['expert_narrative_scores']) 
        ? $expert_1_review['expert_narrative_scores'] 
        : json_encode($expert_1_review['expert_narrative_scores']);
    $expert_1_narrative_scores = json_decode($narrative_scores_json_1, true) ?? [];
}
if ($expert_2_review && !empty($expert_2_review['expert_narrative_scores'])) {
    $narrative_scores_json_2 = is_string($expert_2_review['expert_narrative_scores']) 
        ? $expert_2_review['expert_narrative_scores'] 
        : json_encode($expert_2_review['expert_narrative_scores']);
    $expert_2_narrative_scores = json_decode($narrative_scores_json_2, true) ?? [];
}

// Parse individual item scores from JSON (if stored) - use first expert's data
$expert_item_scores = [];
$expert_narrative_scores = [];
if ($expert_review) {
    if (!empty($expert_review['expert_item_scores'])) {
        $item_scores_json = is_string($expert_review['expert_item_scores']) 
            ? $expert_review['expert_item_scores'] 
            : json_encode($expert_review['expert_item_scores']);
        $expert_item_scores = json_decode($item_scores_json, true) ?? [];
    }
    if (!empty($expert_review['expert_narrative_scores'])) {
        $narrative_scores_json = is_string($expert_review['expert_narrative_scores']) 
            ? $expert_review['expert_narrative_scores'] 
            : json_encode($expert_review['expert_narrative_scores']);
        $expert_narrative_scores = json_decode($narrative_scores_json, true) ?? [];
    }
}

// Calculate Expert 1 scores
$expert_1_scores = [
    'section_1' => $expert_1_review ? (float)($expert_1_review['expert_score_section_1'] ?? 0) : 0,
    'section_2' => $expert_1_review ? (float)($expert_1_review['expert_score_section_2'] ?? 0) : 0,
    'section_3' => $expert_1_review ? (float)($expert_1_review['expert_score_section_3'] ?? 0) : 0,
    'section_4' => $expert_1_review ? (float)($expert_1_review['expert_score_section_4'] ?? 0) : 0,
    'section_5' => $expert_1_review ? (float)($expert_1_review['expert_score_section_5'] ?? 0) : 0,
];
$expert_1_scores['total'] = array_sum($expert_1_scores);

// Calculate Expert 2 scores
$expert_2_scores = [
    'section_1' => $expert_2_review ? (float)($expert_2_review['expert_score_section_1'] ?? 0) : 0,
    'section_2' => $expert_2_review ? (float)($expert_2_review['expert_score_section_2'] ?? 0) : 0,
    'section_3' => $expert_2_review ? (float)($expert_2_review['expert_score_section_3'] ?? 0) : 0,
    'section_4' => $expert_2_review ? (float)($expert_2_review['expert_score_section_4'] ?? 0) : 0,
    'section_5' => $expert_2_review ? (float)($expert_2_review['expert_score_section_5'] ?? 0) : 0,
];
$expert_2_scores['total'] = array_sum($expert_2_scores);

// Calculate Average scores (average of both experts)
// Always divide by 2 if both experts exist, otherwise by 1
$expert_avg_scores = [
    'section_1' => 0,
    'section_2' => 0,
    'section_3' => 0,
    'section_4' => 0,
    'section_5' => 0,
];
$expert_count = 0;
if ($expert_1_review) $expert_count++;
if ($expert_2_review) $expert_count++;

// Always use 2 as divisor if both reviews exist (even if one has 0 score)
if ($expert_1_review && $expert_2_review) {
    $expert_count = 2; // Force count to 2 if both exist
}

if ($expert_count > 0) {
    foreach (['section_1', 'section_2', 'section_3', 'section_4', 'section_5'] as $section) {
        $sum = $expert_1_scores[$section] + $expert_2_scores[$section];
        $expert_avg_scores[$section] = $sum / $expert_count;
    }
    $expert_avg_scores['total'] = ($expert_1_scores['total'] + $expert_2_scores['total']) / $expert_count;
}

// Set flags for read-only mode
$is_readonly = true;
$is_chairman_view = true; // Use same view mode as chairman (read-only)
$is_locked = false;

// For backward compatibility, use average scores as expert_scores
$expert_scores = $expert_avg_scores;
$expert_scores['total'] = array_sum($expert_scores);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Review - <?php echo htmlspecialchars($dept_name); ?> - Admin UDRF</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/styles.css" />
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
        }
        
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        
        .section-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 3px solid var(--primary-color);
        }
        
        .subsection-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--secondary-color);
            margin: 2rem 0 1rem;
        }
        
        .data-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
            gap: 1rem;
            padding: 1rem;
            align-items: center;
            border-bottom: 1px solid #e0e0e0;
            transition: background 0.3s;
        }
        
        .data-grid:hover {
            background: #f8f9fa;
        }
        
        .data-grid.header {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
            gap: 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 600;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            align-items: center;
        }
        
        .data-grid.header > div {
            text-align: center;
        }
        
        .data-grid.header > div:first-child {
            text-align: left;
        }
        
        .field-label {
            font-weight: 600;
            color: #333;
        }
        
        .dept-value {
            color: #666;
            padding: 0.5rem;
            background: #e3f2fd;
            border-radius: 5px;
            text-align: center;
        }
        
        .auto-score {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            padding: 0.75rem;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            color: #1976d2;
        }
        
        .expert-input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd !important;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s;
            background-color: #f9fafb !important;
            color: #6b7280 !important;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        .expert-score {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            padding: 0.75rem;
            border-radius: 8px;
            text-align: center;
            font-weight: 700;
            color: #2e7d32;
        }
        
        .read-only-badge {
            color: #6b7280;
            font-style: italic;
            padding: 0.5rem 0.75rem;
            background: #f3f4f6;
            border-radius: 6px;
            text-align: center;
            font-size: 0.9rem;
            border: 1px solid #d1d5db;
        }
        
        .score-summary {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 15px;
            padding: 2rem;
            margin: 2rem 0;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .score-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid #dee2e6;
            align-items: center;
        }
        
        .score-row > div {
            text-align: center;
        }
        
        .score-row > div:first-child {
            text-align: left;
        }
        
        .score-row.total {
            border-top: 3px solid var(--primary-color);
            font-weight: 700;
            font-size: 1.3rem;
            padding-top: 1.5rem;
            margin-top: 1rem;
        }
        
        .no-data {
            text-align: center;
            padding: 3rem;
            color: #999;
            font-style: italic;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <div class="d-flex" id="wrapper">
        <!-- Sidebar -->
        <?php include('sidebar.php'); ?>
        <!-- /#sidebar-wrapper -->

        <!-- Page Content -->
        <div id="page-content-wrapper">
            <nav class="navbar navbar-expand-lg navbar-light bg-transparent py-4 px-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-align-left primary-text fs-4 me-3" id="menu-toggle"></i>
                    <h2 class="fs-2 m-0">UDRF Data</h2>
                </div>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
                    aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle second-text fw-bold" href="#" id="navbarDropdown"
                                role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?>
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
                                <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="container-fluid px-4">
                <h4 class="mt-4">
                    <a href="udrf_category.php?cat_id=<?php echo $cat_id; ?>&name=<?php echo urlencode($category_name); ?>" class="btn btn-sm btn-primary mb-2">
                        <i class="fas fa-arrow-left"></i> Back to Departments
                    </a>
                </h4>
                <h4 class="mt-2">Department Review (Read-Only)</h4>
                <h5><?php echo htmlspecialchars($dept_name, ENT_QUOTES, 'UTF-8'); ?> (Code: <?php echo htmlspecialchars($dept_code, ENT_QUOTES, 'UTF-8'); ?>)</h5>
                <p class="text-muted">Academic Year: <strong><?php echo htmlspecialchars($academic_year, ENT_QUOTES, 'UTF-8'); ?></strong></p>

    <!-- Score Summary Card -->
    <div class="card mt-4">
        <div class="card-body">
        <h2 class="section-title"><i class="fas fa-chart-bar"></i> Score Summary</h2>
        <?php if ($expert_review && $expert_scores['total'] > 0): ?>
        <div class="alert alert-info mb-3">
            <i class="fas fa-info-circle"></i> <strong>Note:</strong> Individual item scores are not stored in the database. 
            Only section totals are saved. The "Expert Score" column for individual items shows the auto-calculated score. 
            The actual expert-reviewed scores are shown in the <strong>Consolidated Score Summary</strong> below.
        </div>
        <?php endif; ?>
        <?php
        // CRITICAL: Preserve Section I value with narrative scores
        $preserved_section1_score = isset($auto_scores['section_1']) ? (float)$auto_scores['section_1'] : 0;
        
        // Ensure total is recalculated with current values - CRITICAL: Use explicit float casting
        $auto_scores['total'] = (float)$auto_scores['section_1'] + (float)$auto_scores['section_2'] + (float)$auto_scores['section_3'] + (float)$auto_scores['section_4'] + (float)$auto_scores['section_5'];
        ?>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Section</th>
                        <th>Dept Auto Score</th>
                        <th>Expert 1 Score</th>
                        <th>Expert 2 Score</th>
                        <th>Average Score</th>
                        <th>Max Marks</th>
                        <th>Difference (Avg - Auto)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sections = [
                        'section_1' => ['label' => 'Section I: Faculty Output & Research', 'max' => 300],
                        'section_2' => ['label' => 'Section II: NEP Initiatives', 'max' => 100],
                        'section_3' => ['label' => 'Section III: Departmental Governance & Practices (12 items)', 'max' => 110],
                        'section_4' => ['label' => 'Section IV: Student Support & Progression (15 items)', 'max' => 140],
                        'section_5' => ['label' => 'Section V: Conferences & Collaborations', 'max' => 75],
                    ];
                    foreach ($sections as $key => $section):
                        // CRITICAL: For Section I, use preserved value with narrative scores
                        if ($key === 'section_1' && isset($preserved_section1_score)) {
                            $auto = $preserved_section1_score;
                        } else {
                            $auto = $auto_scores[$key] ?? 0;
                        }
                        $expert1 = $expert_1_scores[$key] ?? 0;
                        $expert2 = $expert_2_scores[$key] ?? 0;
                        $expert_avg = $expert_avg_scores[$key] ?? 0;
                        $diff = $expert_avg - $auto;
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($section['label']); ?></td>
                            <td><strong><?php echo number_format($auto, 2); ?></strong></td>
                            <td><strong style="color: <?php echo $expert1 > 0 ? 'var(--primary-color)' : '#6b7280'; ?>;"><?php echo number_format($expert1, 2); ?></strong></td>
                            <td><strong style="color: <?php echo $expert2 > 0 ? 'var(--primary-color)' : '#6b7280'; ?>;"><?php echo number_format($expert2, 2); ?></strong></td>
                            <td><strong style="color: var(--success-color);"><?php echo number_format($expert_avg, 2); ?></strong></td>
                            <td><?php echo $section['max']; ?></td>
                            <td style="color: <?php echo $diff > 0 ? 'var(--success-color)' : ($diff < 0 ? 'var(--danger-color)' : '#6b7280'); ?>;">
                                <?php echo ($diff >= 0 ? '+' : '') . number_format($diff, 2); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-info">
                        <th>TOTAL</th>
                        <th><strong><?php echo number_format($auto_scores['total'] ?? 0, 2); ?></strong></th>
                        <th><strong style="color: <?php echo $expert_1_scores['total'] > 0 ? 'var(--primary-color)' : '#6b7280'; ?>;"><?php echo number_format($expert_1_scores['total'], 2); ?></strong></th>
                        <th><strong style="color: <?php echo $expert_2_scores['total'] > 0 ? 'var(--primary-color)' : '#6b7280'; ?>;"><?php echo number_format($expert_2_scores['total'], 2); ?></strong></th>
                        <th><strong style="color: var(--success-color);"><?php echo number_format($expert_avg_scores['total'], 2); ?></strong></th>
                        <th>725</th>
                        <th style="color: <?php 
                            $total_diff = $expert_avg_scores['total'] - ($auto_scores['total'] ?? 0);
                            echo $total_diff > 0 ? 'var(--success-color)' : ($total_diff < 0 ? 'var(--danger-color)' : '#6b7280'); 
                        ?>;">
                            <?php echo ($total_diff >= 0 ? '+' : '') . number_format($total_diff, 2); ?>
                        </th>
                    </tr>
                </tbody>
            </table>
        </div>
        </div>
    </div>
    
    <!-- Include ALL Sections (read-only mode) -->
    <div class="card mt-4">
        <div class="card-body">
    <?php 
    try {
        // CRITICAL: Store Section I value BEFORE including section1_faculty_output.php
        $section1_before_include = $auto_scores['section_1'];
        
        include('../Expert_comty_login/section1_faculty_output.php');
        
        // CRITICAL: Restore Section I value AFTER including section1_faculty_output.php
        $auto_scores['section_1'] = $section1_before_include;
        
        // Recalculate total with preserved Section I value - CRITICAL: Use explicit float casting
        $auto_scores['total'] = (float)$auto_scores['section_1'] + (float)$auto_scores['section_2'] + (float)$auto_scores['section_3'] + (float)$auto_scores['section_4'] + (float)$auto_scores['section_5'];
        include('../Expert_comty_login/section2_nep_initiatives.php');
        include('../Expert_comty_login/section3_governance.php');
        include('../Expert_comty_login/section4_student_support.php');
        // Update auto_scores['section_4'] with the accurate value calculated in section4_student_support.php
        if (isset($section_4_auto_total)) {
            $auto_scores['section_4'] = $section_4_auto_total;
            // Recalculate total with corrected Section IV value - CRITICAL: Use explicit float casting
            $auto_scores['total'] = (float)$auto_scores['section_1'] + (float)$auto_scores['section_2'] + (float)$auto_scores['section_3'] + (float)$auto_scores['section_4'] + (float)$auto_scores['section_5'];
        }
        include('../Expert_comty_login/section5_conferences.php');
        // Update auto_scores['section_5'] with the accurate value calculated in section5_conferences.php if available
        if (isset($section_5_auto_total)) {
            $auto_scores['section_5'] = $section_5_auto_total;
            // Recalculate total with corrected Section V value
            $auto_scores['total'] = (float)$auto_scores['section_1'] + (float)$auto_scores['section_2'] + (float)$auto_scores['section_3'] + (float)$auto_scores['section_4'] + (float)$auto_scores['section_5'];
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error loading section: ' . htmlspecialchars($e->getMessage()) . '</div>';
        error_log("Error including section files: " . $e->getMessage());
    } catch (Error $e) {
        echo '<div class="alert alert-danger">Fatal error loading section: ' . htmlspecialchars($e->getMessage()) . '</div>';
        error_log("Fatal error including section files: " . $e->getMessage());
    }
    ?>
        </div>
    </div>
            </div>
        </div>
        <!-- /#page-content-wrapper -->
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var el = document.getElementById("wrapper");
        var toggleButton = document.getElementById("menu-toggle");

        toggleButton.onclick = function() {
            el.classList.toggle("toggled");
        };
    </script>
    
    <!-- Footer -->
    <?php include '../footer_main.php'; ?>
</body>
</html>

