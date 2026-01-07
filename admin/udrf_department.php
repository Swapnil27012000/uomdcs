<?php
/**
 * Admin UDRF Data - Step 3: Department Detail (Read-Only)
 * Copied from Chairman_login/department.php
 */

// Set timezone FIRST
if (!date_default_timezone_get() || date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

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
    die("Error loading page: " . $e->getMessage() . "<br>File: " . $e->getFile() . "<br>Line: " . $e->getLine());
} catch (Error $e) {
    ob_end_clean();
    die("Fatal error: " . $e->getMessage() . "<br>File: " . $e->getFile() . "<br>Line: " . $e->getLine());
}

$dept_id_raw = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
$dept_id = resolveDepartmentId($dept_id_raw);
$cat_id = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
$category_name = isset($_GET['name']) ? urldecode($_GET['name']) : '';

if (!$dept_id) {
    die("Valid Department ID is required");
}

// Load common functions for getAcademicYear()
if (file_exists(__DIR__ . '/../common_functions.php')) {
    require_once(__DIR__ . '/../common_functions.php');
} elseif (file_exists(__DIR__ . '/../common_progress_functions.php')) {
    require_once(__DIR__ . '/../common_progress_functions.php');
}

$academic_year = getAcademicYear();

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

// CRITICAL: Use centralized calculation function for consistency
// This ensures all sections are calculated using the same logic as review_complete.php
require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
$auto_scores = recalculateAllSectionsFromData($dept_id, $academic_year, $dept_data, true);

error_log("[Admin UDRF Department] FINAL SCORES BREAKDOWN for dept_id=$dept_id:");
error_log("  Section I (Faculty Output): " . $auto_scores['section_1']);
error_log("  Section II (NEP Initiatives): " . $auto_scores['section_2']);
error_log("  Section III (Departmental Governance): " . $auto_scores['section_3']);
error_log("  Section IV (Student Support): " . $auto_scores['section_4']);
error_log("  Section V (Conferences & Collaborations): " . $auto_scores['section_5']);
error_log("  TOTAL: " . $auto_scores['total'] . " / 725");

// Recalculate total from ALL sections - CRITICAL: Ensure all sections are summed correctly with explicit float casting
$auto_scores['total'] = (float)$auto_scores['section_1'] + (float)$auto_scores['section_2'] + (float)$auto_scores['section_3'] + (float)$auto_scores['section_4'] + (float)$auto_scores['section_5'];

// Mark narrative scores as already included to prevent double-adding when section1_faculty_output.php is included
$GLOBALS['narrative_scores_included'] = true;

// Include section4_student_support.php early to get accurate $section_4_auto_total calculation
// Use output buffering to capture calculation without rendering HTML
ob_start();
try {
    // Set a flag to prevent HTML rendering (we only need the calculation)
    $calculate_only = true;
    include('../Expert_comty_login/section4_student_support.php');
    // Update auto_scores['section_4'] with the accurate value if available
    if (isset($section_4_auto_total)) {
        $auto_scores['section_4'] = $section_4_auto_total;
        // Recalculate total with corrected Section IV value - CRITICAL: Use explicit float casting
        $auto_scores['total'] = (float)$auto_scores['section_1'] + (float)$auto_scores['section_2'] + (float)$auto_scores['section_3'] + (float)$auto_scores['section_4'] + (float)$auto_scores['section_5'];
    }
} catch (Exception $e) {
    // Ignore errors during calculation phase
    error_log("Error calculating section 4 total: " . $e->getMessage());
}
ob_end_clean();
unset($calculate_only); // Clear the flag

// Get expert review if exists
$expert_email = getExpertForCategory($category_name);
$expert_review = null;
if ($expert_email) {
    $expert_review = getExpertReview($expert_email, $dept_id, $academic_year);
}

// Parse individual item scores from JSON (if stored)
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

// Initialize expert scores array (same as review_complete.php)
$expert_scores = [
    'section_1' => ($expert_review && $expert_review['expert_score_section_1'] !== null) ? (float)$expert_review['expert_score_section_1'] : 0,
    'section_2' => ($expert_review && $expert_review['expert_score_section_2'] !== null) ? (float)$expert_review['expert_score_section_2'] : 0,
    'section_3' => ($expert_review && $expert_review['expert_score_section_3'] !== null) ? (float)$expert_review['expert_score_section_3'] : 0,
    'section_4' => ($expert_review && $expert_review['expert_score_section_4'] !== null) ? (float)$expert_review['expert_score_section_4'] : 0,
    'section_5' => ($expert_review && $expert_review['expert_score_section_5'] !== null) ? (float)$expert_review['expert_score_section_5'] : 0,
];

// Set flags for read-only mode
$is_readonly = true;
$is_chairman_view = true; // Use same view mode as chairman (read-only)
$is_locked = false;
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
                <h5><?php echo htmlspecialchars($dept_name); ?> (Code: <?php echo $dept_code; ?>)</h5>
                <p class="text-muted">Academic Year: <strong><?php echo $academic_year; ?></strong></p>

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
                        <th>Expert Score</th>
                        <th>Max Marks</th>
                        <th>Difference</th>
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
                        $expert = $expert_scores[$key] ?? 0;
                        $diff = $expert - $auto;
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($section['label']); ?></td>
                            <td><strong><?php echo number_format($auto, 2); ?></strong></td>
                            <td><strong style="color: var(--success-color);"><?php echo number_format($expert, 2); ?></strong></td>
                            <td><?php echo $section['max']; ?></td>
                            <td style="color: <?php echo $diff > 0 ? 'var(--success-color)' : ($diff < 0 ? 'var(--danger-color)' : '#6b7280'); ?>;">
                                <?php echo ($diff >= 0 ? '+' : '') . number_format($diff, 2); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-info">
                        <th>TOTAL</th>
                        <th><strong><?php echo number_format($auto_scores['total'] ?? 0, 2); ?></strong></th>
                        <th><strong style="color: var(--success-color);"><?php echo number_format($expert_scores['total'], 2); ?></strong></th>
                        <th>725</th>
                        <th style="color: <?php 
                            $total_diff = $expert_scores['total'] - ($auto_scores['total'] ?? 0);
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

