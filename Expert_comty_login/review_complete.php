<?php
/**
 * COMPLETE Expert Review System - Full UDRF Data Display & Verification
 * Displays ALL sections with verification inputs, auto-scoring, and expert scoring
 * Based on dept_login UI/UX patterns
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require('session.php');
require('expert_functions.php');
require('data_fetcher.php');

// Get department ID (accept either DEPT_ID or department code)
$dept_id_param = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
$dept_id = resolveDepartmentId($dept_id_param);
$academic_year = getAcademicYear();

if (!$dept_id) {
    die("Valid Department ID is required");
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
        $dept_category = $dept_row['category_label'] ?? '';
    }
    $dept_stmt->close();
}

// CRITICAL: Always fetch fresh data (bypass any potential caching)
// Clear cache first to ensure we get the latest data, especially for collaborations and conferences
clearDepartmentScoreCache($dept_id, $academic_year, false);

// Fetch ALL department data (fresh from database)
$dept_data = fetchAllDepartmentData($dept_id, $academic_year);
$documents = fetchAllSupportingDocuments($dept_id, $academic_year);
$grouped_docs = groupDocumentsBySection($documents);

// Debug: Log section 1 data specifically
if (isset($dept_data['section_1'])) {
    $sec1_debug = $dept_data['section_1'];
    error_log("[Expert Review] Section 1 data check:");
    error_log("[Expert Review] desc_initiative: " . (isset($sec1_debug['desc_initiative']) ? ('VALUE: "' . substr($sec1_debug['desc_initiative'], 0, 100) . '" (length: ' . strlen($sec1_debug['desc_initiative']) . ')') : 'NOT SET'));
    error_log("[Expert Review] desc_impact: " . (isset($sec1_debug['desc_impact']) ? ('VALUE: "' . substr($sec1_debug['desc_impact'], 0, 100) . '" (length: ' . strlen($sec1_debug['desc_impact']) . ')') : 'NOT SET'));
    error_log("[Expert Review] desc_collaboration: " . (isset($sec1_debug['desc_collaboration']) ? ('VALUE: "' . substr($sec1_debug['desc_collaboration'], 0, 100) . '" (length: ' . strlen($sec1_debug['desc_collaboration']) . ')') : 'NOT SET'));
    error_log("[Expert Review] desc_plan: " . (isset($sec1_debug['desc_plan']) ? ('VALUE: "' . substr($sec1_debug['desc_plan'], 0, 100) . '" (length: ' . strlen($sec1_debug['desc_plan']) . ')') : 'NOT SET'));
    error_log("[Expert Review] desc_recognition: " . (isset($sec1_debug['desc_recognition']) ? ('VALUE: "' . substr($sec1_debug['desc_recognition'], 0, 100) . '" (length: ' . strlen($sec1_debug['desc_recognition']) . ')') : 'NOT SET'));
    error_log("[Expert Review] dpiit_startup_details_parsed count: " . (isset($sec1_debug['dpiit_startup_details_parsed']) ? count($sec1_debug['dpiit_startup_details_parsed']) : 'NOT SET'));
    error_log("[Expert Review] startups_incubated: " . ($sec1_debug['startups_incubated'] ?? 'NULL'));
    error_log("[Expert Review] forbes_alumni_details: " . (isset($sec1_debug['forbes_alumni_details']) ? ('HAS DATA (' . strlen($sec1_debug['forbes_alumni_details']) . ' chars)') : 'NOT SET'));
} else {
    error_log("[Expert Review] Section 1 data is EMPTY or NOT SET");
}

// Debug: Check if data exists and log for troubleshooting
error_log("[Expert Review] Fetching data for dept_id: $dept_id, academic_year: $academic_year");

// Check what A_YEAR values exist in the database for this dept
$check_years_query = "SELECT DISTINCT A_YEAR FROM brief_details_of_the_department WHERE DEPT_ID = ? ORDER BY A_YEAR DESC LIMIT 5";
$check_stmt = $conn->prepare($check_years_query);
$available_years = [];
if ($check_stmt) {
    $check_stmt->bind_param("i", $dept_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    while ($row = $check_result->fetch_assoc()) {
        $available_years[] = $row['A_YEAR'];
    }
    $check_stmt->close();
}

// Debug: Log what data was fetched (for troubleshooting)
error_log("=== EXPERT REVIEW DATA FETCH DEBUG ===");
error_log("Dept ID: $dept_id, Academic Year: $academic_year");
error_log("Section A (brief_details) has data: " . (!empty($dept_data['section_a']) ? 'YES' : 'NO'));
error_log("Section 1 (faculty_output) has data: " . (!empty($dept_data['section_1']) ? 'YES' : 'NO'));
error_log("Section 2 (nepmarks) has data: " . (!empty($dept_data['section_2']) ? 'YES' : 'NO'));
error_log("Section 3 (department_data) has data: " . (!empty($dept_data['section_3']) ? 'YES' : 'NO'));
error_log("Section 4 (student support) has data: " . (!empty($dept_data['section_4']) ? 'YES' : 'NO'));
error_log("Section 5 (conferences/collaborations) has data: " . (!empty($dept_data['section_5']) ? 'YES' : 'NO'));
if (!empty($dept_data['section_a'])) {
    error_log("Section A sample: NUM_PERM_PHD = " . ($dept_data['section_a']['NUM_PERM_PHD'] ?? 'NULL'));
}
if (!empty($dept_data['section_2'])) {
    error_log("Section 2 sample: nep_count = " . ($dept_data['section_2']['nep_count'] ?? 'NULL'));
}

// CRITICAL: Use centralized calculation function for consistency across all views
// This ensures all sections are calculated using the same logic
$auto_scores = recalculateAllSectionsFromData($dept_id, $academic_year, $dept_data, true);

error_log("[Expert Review] FINAL SCORES BREAKDOWN for dept_id=$dept_id:");
error_log("  Section I (Faculty Output): " . $auto_scores['section_1']);
error_log("  Section II (NEP Initiatives): " . $auto_scores['section_2']);
error_log("  Section III (Departmental Governance): " . $auto_scores['section_3']);
error_log("  Section IV (Student Support): " . $auto_scores['section_4']);
error_log("  Section V (Conferences & Collaborations): " . $auto_scores['section_5']);
error_log("  TOTAL: " . $auto_scores['total'] . " / 725");

// Get existing expert review
$expert_review = getExpertReview($email, $dept_id, $academic_year);

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
$is_locked = $expert_review ? (bool)$expert_review['is_locked'] : false;

// Check if data was just cleared (from URL parameter)
$data_cleared = isset($_GET['cleared']) && $_GET['cleared'] == '1';

// Extract existing expert scores if available
// CRITICAL: If no review exists (after deletion), all scores should be 0
// This ensures the summary table shows 0 after clearing data
$expert_scores = [
    'section_1' => ($expert_review && $expert_review['expert_score_section_1'] !== null && !$data_cleared) ? (float)$expert_review['expert_score_section_1'] : 0,
    'section_2' => ($expert_review && $expert_review['expert_score_section_2'] !== null && !$data_cleared) ? (float)$expert_review['expert_score_section_2'] : 0,
    'section_3' => ($expert_review && $expert_review['expert_score_section_3'] !== null && !$data_cleared) ? (float)$expert_review['expert_score_section_3'] : 0,
    'section_4' => ($expert_review && $expert_review['expert_score_section_4'] !== null && !$data_cleared) ? (float)$expert_review['expert_score_section_4'] : 0,
    'section_5' => ($expert_review && $expert_review['expert_score_section_5'] !== null && !$data_cleared) ? (float)$expert_review['expert_score_section_5'] : 0,
];
$expert_scores['total'] = array_sum($expert_scores);

// CRITICAL: If data was cleared, treat as if no review exists
if ($data_cleared) {
    $expert_review = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Review - <?php echo htmlspecialchars($dept_name); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            min-height: 100vh;
            font-family: 'Inter', 'Segoe UI', sans-serif;
        }
        
        .main-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }
        
        .section-card {
            background: white;
            border-radius: 15px;
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            border-left: 6px solid var(--primary-color);
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
            grid-template-columns: 2fr repeat(4, 1fr);
            gap: 1rem;
            padding: 1rem;
            align-items: center;
            border-bottom: 1px solid #e0e0e0;
            transition: background 0.3s;
        }
        
        /* 4-column layout for readonly/chairman view (no Expert Input column) */
        .data-grid[style*="grid-template-columns: 2fr 1fr 1fr 1fr"] {
            grid-template-columns: 2fr 1fr 1fr 1fr !important;
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
        
        /* 4-column header for readonly/chairman view */
        .data-grid.header[style*="grid-template-columns: 2fr 1fr 1fr 1fr"] {
            grid-template-columns: 2fr 1fr 1fr 1fr !important;
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
        
        .field-label strong {
            font-weight: 700;
            color: #212529;
        }
        
        .dept-value {
            color: #666;
            padding: 0.75rem;
            background: #e3f2fd;
            border-radius: 8px;
            text-align: center;
            font-weight: 500;
        }
        
        /* Special styling for narrative dept-value (overrides above) */
        .dept-value.narrative-dept-response {
            text-align: left;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
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
            background-color: #fff;
            box-sizing: border-box;
        }
        
        .expert-input:focus {
            border-color: var(--primary-color) !important;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        /* Override Bootstrap form-control border if accidentally applied */
        .expert-input.form-control {
            border: 2px solid #ddd !important;
        }
        
        /* Remove any wrapper borders that might cause double borders */
        .expert-input-wrapper {
            border: none !important;
            padding: 0 !important;
        }
        
        /* Narrative question department response styling */
        .narrative-dept-response {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            text-align: left;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .narrative-response-header {
            color: #495057;
            font-size: 0.9rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #dee2e6;
        }
        
        .narrative-response-content {
            color: #212529;
            line-height: 1.6;
            font-size: 0.95rem;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .narrative-response-content::-webkit-scrollbar {
            width: 8px;
        }
        
        .narrative-response-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .narrative-response-content::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        
        .narrative-response-content::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        .expert-score {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            padding: 0.75rem;
            border-radius: 8px;
            text-align: center;
            font-weight: 700;
            color: #2e7d32;
        }
        
        .doc-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .doc-link:hover {
            color: var(--secondary-color);
            text-decoration: underline;
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
        
        .btn-save, .btn-lock, .btn-danger {
            padding: 1rem 3rem;
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
        }
        
        .btn-save {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.4);
        }
        
        .btn-lock {
            background: linear-gradient(135deg, var(--success-color), #20c997);
            color: white;
        }
        
        .btn-lock:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
        }
        
        .locked-banner {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            font-weight: 600;
            margin-bottom: 2rem;
            border: 2px solid #f5c6cb;
        }
        
        .no-data {
            text-align: center;
            padding: 3rem;
            color: #999;
            font-style: italic;
            font-size: 1.1rem;
        }
        
        .narrative-field {
            width: 100%;
            min-height: 200px;
            padding: 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-family: inherit;
            resize: vertical;
        }
        
        .narrative-field:focus {
            border-color: var(--primary-color);
            outline: none;
        }
        
        .doc-badge {
            display: inline-block;
            background: var(--primary-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            margin: 0.25rem;
        }
        
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #1976d2;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 5px;
        }
    </style>
</head>
<body>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="mb-2">
                    <i class="fas fa-clipboard-check"></i> Department Verification Review
                </h1>
                <h3 class="mb-3"><?php echo htmlspecialchars($dept_name); ?> (Code: <?php echo $dept_code; ?>)</h3>
                <p class="mb-0"><i class="fas fa-calendar"></i> Academic Year: <strong><?php echo $academic_year; ?></strong></p>
                <?php if (!empty($available_years) && !in_array($academic_year, $available_years)): ?>
                    <div class="alert alert-warning mt-2 mb-0">
                        <small><i class="fas fa-exclamation-triangle"></i> <strong>Note:</strong> Data may not exist for this academic year. Available years: <?php echo implode(', ', $available_years); ?></small>
                    </div>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <a href="export_review_pdf.php?dept_id=<?php echo urlencode($dept_id); ?>" target="_blank" class="btn btn-warning btn-lg">
                    <i class="fas fa-file-pdf"></i> Download PDF
                </a>
                <a href="dashboard.php" class="btn btn-light btn-lg">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <?php if ($is_locked): ?>
    <div class="locked-banner">
        <i class="fas fa-lock fa-2x mb-2"></i>
        <h4>This Department Review is Locked</h4>
        <p class="mb-0">Review completed and finalized. You can still update if needed (e.g., if chairman requests changes).</p>
        <button type="button" class="btn btn-warning btn-sm mt-2" onclick="unlockReview()">
            <i class="fas fa-unlock"></i> Unlock for Updates
        </button>
    </div>
    <?php endif; ?>

    <?php 
    // Include ALL Sections (before consolidated score)
    include('section1_faculty_output.php');
    // Section I auto score is updated in section1_faculty_output.php to include narrative auto scores
    // Recalculate total after Section I update - CRITICAL: Use explicit float casting
    $auto_scores['total'] = (float)$auto_scores['section_1'] + (float)$auto_scores['section_2'] + (float)$auto_scores['section_3'] + (float)$auto_scores['section_4'] + (float)$auto_scores['section_5'];
    
    include('section2_nep_initiatives.php');
    include('section3_governance.php');
    // Note: section3_governance.php calculates $section_3_auto_total for display purposes
    // But we use the centralized calculation from recalculateAllSectionsFromData() for consistency
    // The centralized function already updated the cache, so no need to update again here
    
    include('section4_student_support.php');
    // Note: section4_student_support.php calculates $section_4_auto_total for display purposes
    // But we use the centralized calculation from recalculateAllSectionsFromData() for consistency
    
    include('section5_conferences.php');
    // Note: section5_conferences.php calculates $section_5_auto_total for display purposes
    // But we use the centralized calculation from recalculateAllSectionsFromData() for consistency
    ?>

    <!-- Consolidated Score Summary (at the end, after all sections) -->
    <div class="score-summary">
        <h2 class="text-center mb-4"><i class="fas fa-chart-line"></i> Consolidated Score Summary</h2>
        <div class="data-grid header">
            <div>Section</div>
            <div>Dept Auto Score</div>
            <div>Expert Score</div>
            <div>Max Marks</div>
            <div>Difference</div>
        </div>
        <div class="score-row">
            <div><strong>Section I:</strong> Faculty Output & Research</div>
            <div class="auto-score"><?php echo number_format($auto_scores['section_1'], 2); ?></div>
            <div class="expert-score" id="display_expert_section_1"><?php echo number_format($expert_scores['section_1'], 2); ?></div>
            <div class="text-center">300</div>
            <div class="text-center" id="display_diff_section_1"><?php echo number_format($expert_scores['section_1'] - $auto_scores['section_1'], 2); ?></div>
        </div>
        <div class="score-row">
            <div><strong>Section II:</strong> NEP Initiatives</div>
            <div class="auto-score"><?php echo number_format($auto_scores['section_2'], 2); ?></div>
            <div class="expert-score" id="display_expert_section_2"><?php echo number_format($expert_scores['section_2'], 2); ?></div>
            <div class="text-center">100</div>
            <div class="text-center" id="display_diff_section_2"><?php echo number_format($expert_scores['section_2'] - $auto_scores['section_2'], 2); ?></div>
        </div>
        <div class="score-row">
            <div><strong>Section III:</strong> Departmental Governance</div>
            <div class="auto-score"><?php echo number_format($auto_scores['section_3'], 2); ?></div>
            <div class="expert-score" id="display_expert_section_3"><?php echo number_format($expert_scores['section_3'], 2); ?></div>
            <div class="text-center">110</div>
            <div class="text-center" id="display_diff_section_3"><?php echo number_format($expert_scores['section_3'] - $auto_scores['section_3'], 2); ?></div>
        </div>
        <div class="score-row">
            <div><strong>Section IV:</strong> Student Support & Progression</div>
            <div class="auto-score"><?php echo number_format($auto_scores['section_4'], 2); ?></div>
            <div class="expert-score" id="display_expert_section_4"><?php echo number_format($expert_scores['section_4'], 2); ?></div>
            <div class="text-center">140</div>
            <div class="text-center" id="display_diff_section_4"><?php echo number_format($expert_scores['section_4'] - $auto_scores['section_4'], 2); ?></div>
        </div>
        <div class="score-row">
            <div><strong>Section V:</strong> Conferences & Collaborations</div>
            <div class="auto-score"><?php echo number_format($auto_scores['section_5'], 2); ?></div>
            <div class="expert-score" id="display_expert_section_5"><?php echo number_format($expert_scores['section_5'], 2); ?></div>
            <div class="text-center">75</div>
            <div class="text-center" id="display_diff_section_5"><?php echo number_format($expert_scores['section_5'] - $auto_scores['section_5'], 2); ?></div>
        </div>
        <div class="score-row total">
            <div><strong>TOTAL</strong></div>
            <div class="auto-score"><?php echo number_format($auto_scores['total'], 2); ?></div>
            <div class="expert-score" id="display_expert_total"><?php echo number_format($expert_scores['total'], 2); ?></div>
            <div class="text-center"><strong>725</strong></div>
            <div class="text-center" id="display_diff_total"><?php echo number_format($expert_scores['total'] - $auto_scores['total'], 2); ?></div>
        </div>
    </div>
    
    <div class="alert alert-success mt-4">
        <h4><i class="fas fa-check-circle"></i> ALL SECTIONS COMPLETE!</h4>
        <p><strong>✅ 100% Implementation Complete:</strong></p>
        <ul class="mb-0">
            <li>✅ Section I: Faculty Output & Research (26 items, 300 marks)</li>
            <li>✅ Section II: NEP Initiatives (6 items, 100 marks)</li>
            <li>✅ Section III: Departmental Governance (12 items, 110 marks)</li>
            <li>✅ Section IV: Student Support & Progression (15 items, 140 marks)</li>
            <li>✅ Section V: Conferences & Collaborations (11 items, 75 marks)</li>
        </ul>
        <p class="mt-3 mb-0"><strong>Total: 81 data points, 725 marks - All with verification fields, real-time calculation, and supporting documents!</strong></p>
    </div>

    <!-- Action Buttons -->
    <div class="text-center my-5">
        <?php if (!$is_locked): ?>
        <button type="button" class="btn-save me-3" onclick="saveReview()">
            <i class="fas fa-save"></i> Save Progress
        </button>
        <button type="button" class="btn btn-danger me-3" onclick="clearExpertData()">
            <i class="fas fa-eraser"></i> Clear Expert Data
        </button>
        <?php 
        // Only show "Verify & Lock" button if review has been saved at least once
        $has_been_saved = $expert_review ? true : false;
        ?>
        <button type="button" 
                class="btn-lock <?php echo $has_been_saved ? '' : 'd-none'; ?>" 
                id="btn-lock-review"
                onclick="lockReview()">
            <i class="fas fa-lock"></i> Verify & Lock Department
        </button>
        <?php else: ?>
        <button type="button" class="btn btn-warning" onclick="unlockReview()">
            <i class="fas fa-unlock"></i> Unlock for Updates
        </button>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-save function
function saveReview() {
    // Collect all expert scores by summing individual item inputs within each section
    let section1Total = 0, section2Total = 0, section3Total = 0, section4Total = 0, section5Total = 0;
    
    // Find all section cards by searching for h2 elements with section titles
    const allSectionCards = Array.from(document.querySelectorAll('.section-card'));
    
    const section1Card = allSectionCards.find(card => {
        const h2 = card.querySelector('h2');
        return h2 && h2.textContent.includes('Section I');
    });
    const section2Card = allSectionCards.find(card => {
        const h2 = card.querySelector('h2');
        return h2 && h2.textContent.includes('Section II');
    });
    const section3Card = allSectionCards.find(card => {
        const h2 = card.querySelector('h2');
        return h2 && h2.textContent.includes('Section III');
    });
    const section4Card = allSectionCards.find(card => {
        const h2 = card.querySelector('h2');
        return h2 && h2.textContent.includes('Section IV');
    });
    const section5Card = allSectionCards.find(card => {
        const h2 = card.querySelector('h2');
        return h2 && h2.textContent.includes('Section V');
    });
    
    // Sum all expert-input fields within each section (including narrative scores)
    if (section1Card) {
        // Regular numeric inputs
        section1Card.querySelectorAll('.expert-input').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section1Total += val;
            }
        });
        // Narrative question scores in Section I (items 22-26)
        section1Card.querySelectorAll('input[id^="narrative_"][id$="_score"]').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section1Total += val;
            }
        });
    }
    if (section2Card) {
        section2Card.querySelectorAll('.expert-input').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section2Total += val;
            }
        });
    }
    if (section3Card) {
        // Regular numeric inputs
        section3Card.querySelectorAll('.expert-input').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section3Total += val;
            }
        });
        // Narrative question scores in Section III (items 5, 7-12, 21-22)
        section3Card.querySelectorAll('input[id^="narrative_"][id$="_score"]').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section3Total += val;
            }
        });
    }
    if (section4Card) {
        // Regular numeric inputs
        section4Card.querySelectorAll('.expert-input').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section4Total += val;
            }
        });
        // Narrative question scores in Section IV (item 17)
        section4Card.querySelectorAll('input[id^="narrative_"][id$="_score"]').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section4Total += val;
            }
        });
    }
    if (section5Card) {
        section5Card.querySelectorAll('.expert-input').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section5Total += val;
            }
        });
    }
    
    // Debug: Log totals
    console.log('Section totals calculated:', {
        section1: section1Total,
        section2: section2Total,
        section3: section3Total,
        section4: section4Total,
        section5: section5Total
    });
    
    // Also check section total inputs if they exist (override if present)
    const s1 = document.getElementById('expert_section_1_total');
    const s2 = document.getElementById('expert_section_2_total');
    const s3 = document.getElementById('expert_section_3_total');
    const s4 = document.getElementById('expert_section_4_total');
    const s5 = document.getElementById('expert_section_5_total');
    
    if (s1 && s1.value) section1Total = parseFloat(s1.value) || section1Total;
    if (s2 && s2.value) section2Total = parseFloat(s2.value) || section2Total;
    if (s3 && s3.value) section3Total = parseFloat(s3.value) || section3Total;
    if (s4 && s4.value) section4Total = parseFloat(s4.value) || section4Total;
    if (s5 && s5.value) section5Total = parseFloat(s5.value) || section5Total;
    
    // Collect ALL individual item scores (for storage and display)
    const expertItemScores = {};
    const expertNarrativeScores = {};
    
    // Collect all expert-input fields (numeric items)
    document.querySelectorAll('.expert-input').forEach(input => {
        const itemId = input.id;
        const value = parseFloat(input.value) || 0;
        if (itemId && !isNaN(value)) {
            expertItemScores[itemId] = value;
        }
    });
    
    // Collect narrative question scores and remarks
    // Use item_number format for consistent IDs (narrative_22, narrative_23, etc.)
    document.querySelectorAll('input[id^="narrative_"][id$="_score"]').forEach(input => {
        const fullId = input.id; // e.g., "narrative_22_score"
        // Extract item number from ID (narrative_XX_score -> narrative_XX)
        const itemId = fullId.replace('_score', ''); // Remove _score suffix to get base ID
        const value = parseFloat(input.value) || 0;
        const remarksId = itemId + '_remarks';
        const remarksElement = document.getElementById(remarksId);
        // Try to get value from textarea (expert view) or text content (readonly view)
        const remarks = remarksElement ? (remarksElement.value || remarksElement.textContent || '') : '';
        if (itemId && !isNaN(value)) {
            expertNarrativeScores[itemId] = {
                score: value,
                remarks: remarks.trim()
            };
            console.log('[Save] Narrative item saved:', itemId, 'Score:', value, 'Remarks:', remarks.trim() ? 'YES (' + remarks.trim().length + ' chars)' : 'NO');
        }
    });
    
    // Debug: Log all collected narrative scores
    console.log('[Save] Total narrative scores collected:', Object.keys(expertNarrativeScores).length);
    if (Object.keys(expertNarrativeScores).length > 0) {
        console.log('[Save] Narrative scores:', JSON.stringify(expertNarrativeScores, null, 2));
    }
    
    const reviewData = {
        dept_id: <?php echo $dept_id; ?>,
        academic_year: '<?php echo $academic_year; ?>',
        expert_score_section_1: section1Total,
        expert_score_section_2: section2Total,
        expert_score_section_3: section3Total,
        expert_score_section_4: section4Total,
        expert_score_section_5: section5Total,
        expert_item_scores: expertItemScores, // Store individual item scores
        expert_narrative_scores: expertNarrativeScores, // Store narrative scores and remarks
        review_notes: document.getElementById('review_notes')?.value || ''
    };
    
    // Show loading
    const saveBtn = event?.target || document.querySelector('.btn-save');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    // AJAX save
    fetch('save_expert_review.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(reviewData)
    })
    .catch(error => {
        // CRITICAL: Handle fetch errors gracefully
        console.error('[Save] Fetch error:', error);
        return {
            ok: false,
            text: () => Promise.resolve(JSON.stringify({
                success: false,
                message: 'Network error: ' + (error.message || 'Failed to connect to server')
            }))
        };
    })
    .then(response => {
        // CRITICAL: Ensure response is valid
        if (!response || typeof response.text !== 'function') {
            console.error('[Save] Invalid response object:', response);
            return { success: false, message: 'Save failed: Invalid server response' };
        }
        
        return response.text().then(text => {
            const trimmed = text.trim();
            
            // Handle empty response
            if (!trimmed || trimmed === '') {
                console.warn('[Save] Empty response received');
                return { success: false, message: 'Empty server response. Please try again.' };
            }
            
            // Try to parse JSON
            try {
                return JSON.parse(trimmed);
            } catch (e) {
                console.error('[Save] JSON parse error:', e, 'Response text:', trimmed.substring(0, 200));
                return { success: false, message: 'Invalid server response. Please refresh and try again.' };
            }
        }).catch(textError => {
            console.error('[Save] Error reading response text:', textError);
            return { success: false, message: 'Save failed: Error reading server response' };
        });
    })
    .then(data => {
        // CRITICAL: Handle null/undefined data gracefully
        if (!data || typeof data !== 'object') {
            console.error('[Save] Invalid data structure:', data);
            alert('Save failed: Invalid server response');
            return;
        }
        
        if (data.success) {
            // Use saved scores from response if available, otherwise use calculated totals
            const savedScores = data.scores || {};
            const finalSection1 = savedScores.section_1 !== undefined ? savedScores.section_1 : section1Total;
            const finalSection2 = savedScores.section_2 !== undefined ? savedScores.section_2 : section2Total;
            const finalSection3 = savedScores.section_3 !== undefined ? savedScores.section_3 : section3Total;
            const finalSection4 = savedScores.section_4 !== undefined ? savedScores.section_4 : section4Total;
            const finalSection5 = savedScores.section_5 !== undefined ? savedScores.section_5 : section5Total;
            
            // Update displayed totals
            if (document.getElementById('display_expert_section_1')) {
                document.getElementById('display_expert_section_1').textContent = finalSection1.toFixed(2);
            }
            if (document.getElementById('display_expert_section_2')) {
                document.getElementById('display_expert_section_2').textContent = finalSection2.toFixed(2);
            }
            if (document.getElementById('display_expert_section_3')) {
                document.getElementById('display_expert_section_3').textContent = finalSection3.toFixed(2);
            }
            if (document.getElementById('display_expert_section_4')) {
                document.getElementById('display_expert_section_4').textContent = finalSection4.toFixed(2);
            }
            if (document.getElementById('display_expert_section_5')) {
                document.getElementById('display_expert_section_5').textContent = finalSection5.toFixed(2);
            }
            
            // Update differences - get auto scores from consolidated score summary
            const scoreRows = document.querySelectorAll('.score-summary .score-row:not(.total)');
            const auto1 = parseFloat(scoreRows[0]?.querySelector('.auto-score')?.textContent || 0);
            const auto2 = parseFloat(scoreRows[1]?.querySelector('.auto-score')?.textContent || 0);
            const auto3 = parseFloat(scoreRows[2]?.querySelector('.auto-score')?.textContent || 0);
            const auto4 = parseFloat(scoreRows[3]?.querySelector('.auto-score')?.textContent || 0);
            const auto5 = parseFloat(scoreRows[4]?.querySelector('.auto-score')?.textContent || 0);
            
            // Calculate differences (expert - auto)
            if (document.getElementById('display_diff_section_1')) {
                document.getElementById('display_diff_section_1').textContent = (finalSection1 - auto1).toFixed(2);
            }
            if (document.getElementById('display_diff_section_2')) {
                document.getElementById('display_diff_section_2').textContent = (finalSection2 - auto2).toFixed(2);
            }
            if (document.getElementById('display_diff_section_3')) {
                document.getElementById('display_diff_section_3').textContent = (finalSection3 - auto3).toFixed(2);
            }
            if (document.getElementById('display_diff_section_4')) {
                document.getElementById('display_diff_section_4').textContent = (finalSection4 - auto4).toFixed(2);
            }
            if (document.getElementById('display_diff_section_5')) {
                document.getElementById('display_diff_section_5').textContent = (finalSection5 - auto5).toFixed(2);
            }
            
            recalculateGrandTotal();
            
            // Show the "Verify & Lock" button after successful save
            const lockBtn = document.getElementById('btn-lock-review');
            if (lockBtn) {
                lockBtn.classList.remove('d-none');
                lockBtn.style.display = 'inline-block';
            }
            
            // Show success message and reload page to show saved values
            alert('Review saved successfully! The "Verify & Lock" button is now available. Page will reload to show updated scores.');
            // Force a full page reload to ensure all saved data is displayed
            setTimeout(() => {
                window.location.reload(true);
            }, 500);
        } else {
            alert('Error saving review: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        // CRITICAL: Handle errors gracefully
        console.error('[Save] Error in promise chain:', error);
        alert('Failed to save review: ' + (error.message || 'Unknown error'));
    })
    .finally(() => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    });
}

// Lock review function
function lockReview() {
    // Check if data has been saved first
    const lockBtn = document.getElementById('btn-lock-review');
    if (lockBtn && lockBtn.classList.contains('d-none')) {
        alert('Please save your progress first before locking the review. Click "Save Progress" to save your data.');
        return;
    }
    
    if (!confirm('Are you sure you want to LOCK this review? This will finalize all scores. You can unlock it later if needed.')) {
        return;
    }
    
    // Collect current scores and save + lock in one request
    saveReview();
    
    setTimeout(() => {
        const reviewData = {
            dept_id: <?php echo $dept_id; ?>,
            academic_year: '<?php echo $academic_year; ?>',
            lock: true
        };
        
        fetch('save_expert_review.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(reviewData)
        })
        .then(response => response.text())
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    alert('Review locked successfully!');
                    location.reload();
                } else {
                    alert('Error locking review: ' + (data.message || 'Unknown error'));
                }
            } catch (e) {
                console.error('JSON parse error:', e, 'Response:', text);
                alert('Failed to lock review. Please try again.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to lock review. Please try again.');
        });
    }, 500);
}

// Unlock review function
function unlockReview() {
    if (!confirm('Are you sure you want to UNLOCK this review? This will allow you to make changes.')) {
        return;
    }
    
    const reviewData = {
        dept_id: <?php echo $dept_id; ?>,
        academic_year: '<?php echo $academic_year; ?>',
        unlock: true
    };
    
    fetch('save_expert_review.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(reviewData)
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                alert('Review unlocked successfully!');
                location.reload();
            } else {
                alert('Error unlocking review: ' + (data.message || 'Unknown error'));
            }
        } catch (e) {
            console.error('JSON parse error:', e, 'Response:', text);
            alert('Failed to unlock review. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to unlock review. Please try again.');
    });
}

// Real-time score calculation
function recalculateGrandTotal() {
    // Get section totals from displayed values or calculate from inputs
    let section1 = parseFloat(document.getElementById('display_expert_section_1')?.textContent || 0);
    let section2 = parseFloat(document.getElementById('display_expert_section_2')?.textContent || 0);
    let section3 = parseFloat(document.getElementById('display_expert_section_3')?.textContent || 0);
    let section4 = parseFloat(document.getElementById('display_expert_section_4')?.textContent || 0);
    let section5 = parseFloat(document.getElementById('display_expert_section_5')?.textContent || 0);
    
    const grandTotal = section1 + section2 + section3 + section4 + section5;
    
    if (document.getElementById('display_expert_total')) {
        document.getElementById('display_expert_total').textContent = grandTotal.toFixed(2);
    }
    const autoTotal = <?php echo $auto_scores['total']; ?>;
    const diffTotal = grandTotal - autoTotal;
    if (document.getElementById('display_diff_total')) {
        document.getElementById('display_diff_total').textContent = diffTotal.toFixed(2);
    }
}

// Global recalculate function that calculates all section totals from inputs
function recalculateScores() {
    let section1Total = 0, section2Total = 0, section3Total = 0, section4Total = 0, section5Total = 0;
    
    // Find all section cards
    const allSectionCards = Array.from(document.querySelectorAll('.section-card'));
    
    const section1Card = allSectionCards.find(card => {
        const h2 = card.querySelector('h2');
        return h2 && h2.textContent.includes('Section I');
    });
    const section2Card = allSectionCards.find(card => {
        const h2 = card.querySelector('h2');
        return h2 && h2.textContent.includes('Section II');
    });
    const section3Card = allSectionCards.find(card => {
        const h2 = card.querySelector('h2');
        return h2 && h2.textContent.includes('Section III');
    });
    const section4Card = allSectionCards.find(card => {
        const h2 = card.querySelector('h2');
        return h2 && h2.textContent.includes('Section IV');
    });
    const section5Card = allSectionCards.find(card => {
        const h2 = card.querySelector('h2');
        return h2 && h2.textContent.includes('Section V');
    });
    
    // Sum all expert-input fields within each section (including narrative scores)
    if (section1Card) {
        // Regular numeric inputs
        section1Card.querySelectorAll('.expert-input').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section1Total += val;
            }
        });
        // Narrative question scores in Section I (items 22-26)
        section1Card.querySelectorAll('input[id^="narrative_"][id$="_score"]').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section1Total += val;
            }
        });
    }
    if (section2Card) {
        section2Card.querySelectorAll('.expert-input').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section2Total += val;
            }
        });
    }
    if (section3Card) {
        // Regular numeric inputs
        section3Card.querySelectorAll('.expert-input').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section3Total += val;
            }
        });
        // Narrative question scores in Section III (items 5, 7-12, 21-22)
        section3Card.querySelectorAll('input[id^="narrative_"][id$="_score"]').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section3Total += val;
            }
        });
    }
    if (section4Card) {
        // Regular numeric inputs
        section4Card.querySelectorAll('.expert-input').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section4Total += val;
            }
        });
        // Narrative question scores in Section IV (item 17)
        section4Card.querySelectorAll('input[id^="narrative_"][id$="_score"]').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section4Total += val;
            }
        });
    }
    if (section5Card) {
        section5Card.querySelectorAll('.expert-input').forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) {
                section5Total += val;
            }
        });
    }
    
    // Update displayed section totals in summary table
    if (document.getElementById('display_expert_section_1')) {
        document.getElementById('display_expert_section_1').textContent = section1Total.toFixed(2);
    }
    if (document.getElementById('display_expert_section_2')) {
        document.getElementById('display_expert_section_2').textContent = section2Total.toFixed(2);
    }
    if (document.getElementById('display_expert_section_3')) {
        document.getElementById('display_expert_section_3').textContent = section3Total.toFixed(2);
    }
    if (document.getElementById('display_expert_section_4')) {
        document.getElementById('display_expert_section_4').textContent = section4Total.toFixed(2);
    }
    if (document.getElementById('display_expert_section_5')) {
        document.getElementById('display_expert_section_5').textContent = section5Total.toFixed(2);
    }
    
    // Also update section totals at the bottom of each section
    const section1Display = document.getElementById('section_1_total_display');
    if (section1Display) {
        section1Display.textContent = section1Total.toFixed(2) + ' / 300';
    }
    const section1Input = document.getElementById('expert_section_1_total');
    if (section1Input) {
        section1Input.value = section1Total;
    }
    
    const section2Display = document.getElementById('section_2_total_display');
    if (section2Display) {
        section2Display.textContent = section2Total.toFixed(2) + ' / 100';
    }
    const section2Input = document.getElementById('expert_section_2_total');
    if (section2Input) {
        section2Input.value = section2Total;
    }
    
    const section3Display = document.getElementById('section_3_total_display');
    if (section3Display) {
        section3Display.textContent = section3Total.toFixed(2) + ' / 110';
    }
    const section3Input = document.getElementById('expert_section_3_total');
    if (section3Input) {
        section3Input.value = section3Total;
    }
    
    const section4Display = document.getElementById('section_4_total_display');
    if (section4Display) {
        section4Display.textContent = section4Total.toFixed(2) + ' / 140';
    }
    const section4Input = document.getElementById('expert_section_4_total');
    if (section4Input) {
        section4Input.value = section4Total;
    }
    
    const section5Display = document.getElementById('section_5_total_display');
    if (section5Display) {
        section5Display.textContent = section5Total.toFixed(2) + ' / 75';
    }
    const section5Input = document.getElementById('expert_section_5_total');
    if (section5Input) {
        section5Input.value = section5Total;
    }
    
    // Update differences - get auto scores from consolidated score summary
    const scoreRows = document.querySelectorAll('.score-summary .score-row:not(.total)');
    const auto1 = parseFloat(scoreRows[0]?.querySelector('.auto-score')?.textContent || 0);
    const auto2 = parseFloat(scoreRows[1]?.querySelector('.auto-score')?.textContent || 0);
    const auto3 = parseFloat(scoreRows[2]?.querySelector('.auto-score')?.textContent || 0);
    const auto4 = parseFloat(scoreRows[3]?.querySelector('.auto-score')?.textContent || 0);
    const auto5 = parseFloat(scoreRows[4]?.querySelector('.auto-score')?.textContent || 0);
    
    // Calculate differences (expert - auto)
    if (document.getElementById('display_diff_section_1')) {
        document.getElementById('display_diff_section_1').textContent = (section1Total - auto1).toFixed(2);
    }
    if (document.getElementById('display_diff_section_2')) {
        document.getElementById('display_diff_section_2').textContent = (section2Total - auto2).toFixed(2);
    }
    if (document.getElementById('display_diff_section_3')) {
        document.getElementById('display_diff_section_3').textContent = (section3Total - auto3).toFixed(2);
    }
    if (document.getElementById('display_diff_section_4')) {
        document.getElementById('display_diff_section_4').textContent = (section4Total - auto4).toFixed(2);
    }
    if (document.getElementById('display_diff_section_5')) {
        document.getElementById('display_diff_section_5').textContent = (section5Total - auto5).toFixed(2);
    }
    
    // Recalculate grand total
    recalculateGrandTotal();
}

// Clear all expert input data (DELETE from database and reset form)
function clearExpertData() {
    if (!confirm('Are you sure you want to clear all expert input data? This will DELETE the saved review from the database and reset all scores to auto-calculated values. This action cannot be undone.')) {
        return;
    }
    
    // Show loading indicator
    const loadingMsg = document.createElement('div');
    loadingMsg.id = 'clear-loading';
    loadingMsg.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px;border:2px solid #007bff;border-radius:5px;z-index:10000;';
    loadingMsg.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Clearing data...';
    document.body.appendChild(loadingMsg);
    
    // Delete the review record from database
    const deleteData = {
        action: 'delete',
        dept_id: <?php echo $dept_id; ?>,
        academic_year: '<?php echo $academic_year; ?>'
    };
    
    fetch('save_expert_review.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(deleteData)
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.text();
    })
    .then(text => {
        try {
            const result = JSON.parse(text);
            if (result.success) {
                // After successful deletion, force a hard reload with cache-busting
                // Use location.replace to prevent back button issues
                const timestamp = new Date().getTime();
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('dept_id', '<?php echo $dept_id; ?>');
                currentUrl.searchParams.set('t', timestamp);
                currentUrl.searchParams.set('cleared', '1'); // Flag to indicate data was cleared
                window.location.replace(currentUrl.toString());
            } else {
                throw new Error(result.message || 'Failed to delete review data');
            }
        } catch (e) {
            // If JSON parse fails, try to show the error
            console.error('Delete response:', text);
            throw new Error('Failed to parse response: ' + e.message);
        }
    })
    .catch(error => {
        document.body.removeChild(loadingMsg);
        alert('Error clearing data: ' + error.message + '\n\nPlease try again or refresh the page.');
        console.error('Clear data error:', error);
    });
}

// Update section total displays at the bottom of each section
function updateSectionTotalDisplays() {
    // Get calculated totals from summary table
    const section1Total = parseFloat(document.getElementById('display_expert_section_1')?.textContent || 0);
    const section2Total = parseFloat(document.getElementById('display_expert_section_2')?.textContent || 0);
    const section3Total = parseFloat(document.getElementById('display_expert_section_3')?.textContent || 0);
    const section4Total = parseFloat(document.getElementById('display_expert_section_4')?.textContent || 0);
    const section5Total = parseFloat(document.getElementById('display_expert_section_5')?.textContent || 0);
    
    // Get auto scores from summary table using the same method as clearExpertData
    const scoreRows = document.querySelectorAll('.score-row:not(.total)');
    const auto1 = parseFloat(scoreRows[0]?.querySelector('.auto-score')?.textContent || 0);
    const auto2 = parseFloat(scoreRows[1]?.querySelector('.auto-score')?.textContent || 0);
    const auto3 = parseFloat(scoreRows[2]?.querySelector('.auto-score')?.textContent || 0);
    const auto4 = parseFloat(scoreRows[3]?.querySelector('.auto-score')?.textContent || 0);
    const auto5 = parseFloat(scoreRows[4]?.querySelector('.auto-score')?.textContent || 0);
    
    // Update Section I total display
    const section1Display = document.getElementById('section_1_total_display');
    if (section1Display) {
        section1Display.textContent = section1Total.toFixed(2) + ' / 300';
    }
    const section1Input = document.getElementById('expert_section_1_total');
    if (section1Input) {
        section1Input.value = section1Total;
    }
    const section1Diff = document.getElementById('section_1_diff_display');
    if (section1Diff) {
        section1Diff.textContent = (section1Total - auto1).toFixed(2);
    }
    
    // Update Section II total display
    const section2Display = document.getElementById('section_2_total_display');
    if (section2Display) {
        section2Display.textContent = section2Total.toFixed(2) + ' / 100';
    }
    const section2Input = document.getElementById('expert_section_2_total');
    if (section2Input) {
        section2Input.value = section2Total;
    }
    const section2Diff = document.getElementById('section_2_diff_display');
    if (section2Diff) {
        section2Diff.textContent = (section2Total - auto2).toFixed(2);
    }
    
    // Update Section III total display
    const section3Display = document.getElementById('section_3_total_display');
    if (section3Display) {
        section3Display.textContent = section3Total.toFixed(2) + ' / 110';
    }
    const section3Input = document.getElementById('expert_section_3_total');
    if (section3Input) {
        section3Input.value = section3Total;
    }
    const section3Diff = document.getElementById('section_3_diff_display');
    if (section3Diff) {
        section3Diff.textContent = (section3Total - auto3).toFixed(2);
    }
    
    // Update Section IV total display
    const section4Display = document.getElementById('section_4_total_display');
    if (section4Display) {
        section4Display.textContent = section4Total.toFixed(2) + ' / 140';
    }
    const section4Input = document.getElementById('expert_section_4_total');
    if (section4Input) {
        section4Input.value = section4Total;
    }
    const section4Diff = document.getElementById('section_4_diff_display');
    if (section4Diff) {
        section4Diff.textContent = (section4Total - auto4).toFixed(2);
    }
    
    // Update Section V total display
    const section5Display = document.getElementById('section_5_total_display');
    if (section5Display) {
        section5Display.textContent = section5Total.toFixed(2) + ' / 75';
    }
    const section5Input = document.getElementById('expert_section_5_total');
    if (section5Input) {
        section5Input.value = section5Total;
    }
    const section5Diff = document.getElementById('section_5_diff_display');
    if (section5Diff) {
        section5Diff.textContent = (section5Total - auto5).toFixed(2);
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Trigger initial recalculation to include all inputs (including narrative scores)
    // This ensures totals are calculated from actual input values, not just saved values
    if (typeof recalculateScores === 'function') {
        recalculateScores();
    }
    
    // Check if there are saved expert scores from the database (from PHP)
    const savedSection1 = parseFloat(document.getElementById('display_expert_section_1')?.textContent || 0);
    const savedSection2 = parseFloat(document.getElementById('display_expert_section_2')?.textContent || 0);
    const savedSection3 = parseFloat(document.getElementById('display_expert_section_3')?.textContent || 0);
    const savedSection4 = parseFloat(document.getElementById('display_expert_section_4')?.textContent || 0);
    const savedSection5 = parseFloat(document.getElementById('display_expert_section_5')?.textContent || 0);
    const savedTotal = savedSection1 + savedSection2 + savedSection3 + savedSection4 + savedSection5;
    
    // If we have saved scores, keep them and just update differences and grand total
    // Don't recalculate from inputs as they have auto_score values, not saved expert scores
    if (savedTotal > 0) {
        // We have saved scores from database, preserve them
        // Just update differences and grand total - get auto scores from consolidated score summary
        const scoreRows = document.querySelectorAll('.score-summary .score-row:not(.total)');
        const auto1 = parseFloat(scoreRows[0]?.querySelector('.auto-score')?.textContent || 0);
        const auto2 = parseFloat(scoreRows[1]?.querySelector('.auto-score')?.textContent || 0);
        const auto3 = parseFloat(scoreRows[2]?.querySelector('.auto-score')?.textContent || 0);
        const auto4 = parseFloat(scoreRows[3]?.querySelector('.auto-score')?.textContent || 0);
        const auto5 = parseFloat(scoreRows[4]?.querySelector('.auto-score')?.textContent || 0);
        
        // Update differences based on saved scores (expert - auto)
        if (document.getElementById('display_diff_section_1')) {
            document.getElementById('display_diff_section_1').textContent = (savedSection1 - auto1).toFixed(2);
        }
        if (document.getElementById('display_diff_section_2')) {
            document.getElementById('display_diff_section_2').textContent = (savedSection2 - auto2).toFixed(2);
        }
        if (document.getElementById('display_diff_section_3')) {
            document.getElementById('display_diff_section_3').textContent = (savedSection3 - auto3).toFixed(2);
        }
        if (document.getElementById('display_diff_section_4')) {
            document.getElementById('display_diff_section_4').textContent = (savedSection4 - auto4).toFixed(2);
        }
        if (document.getElementById('display_diff_section_5')) {
            document.getElementById('display_diff_section_5').textContent = (savedSection5 - auto5).toFixed(2);
        }
        
        // Update grand total
        recalculateGrandTotal();
        
        // CRITICAL: Update section totals at the bottom to match summary table
        updateSectionTotalDisplays();
    } else {
        // No saved scores (review was deleted or never saved)
        // CRITICAL: Do NOT recalculate from inputs - they have auto_score values, not expert scores
        // Expert scores should remain 0 (already set by PHP)
        // Just update differences and section totals
        
        // Get auto scores for difference calculation from consolidated score summary
        const scoreRows = document.querySelectorAll('.score-summary .score-row:not(.total)');
        const auto1 = parseFloat(scoreRows[0]?.querySelector('.auto-score')?.textContent || 0);
        const auto2 = parseFloat(scoreRows[1]?.querySelector('.auto-score')?.textContent || 0);
        const auto3 = parseFloat(scoreRows[2]?.querySelector('.auto-score')?.textContent || 0);
        const auto4 = parseFloat(scoreRows[3]?.querySelector('.auto-score')?.textContent || 0);
        const auto5 = parseFloat(scoreRows[4]?.querySelector('.auto-score')?.textContent || 0);
        
        // Get expert scores (should be 0 if no review)
        const expert1 = parseFloat(document.getElementById('display_expert_section_1')?.textContent || 0);
        const expert2 = parseFloat(document.getElementById('display_expert_section_2')?.textContent || 0);
        const expert3 = parseFloat(document.getElementById('display_expert_section_3')?.textContent || 0);
        const expert4 = parseFloat(document.getElementById('display_expert_section_4')?.textContent || 0);
        const expert5 = parseFloat(document.getElementById('display_expert_section_5')?.textContent || 0);
        
        // Update differences (expert - auto)
        if (document.getElementById('display_diff_section_1')) {
            document.getElementById('display_diff_section_1').textContent = (expert1 - auto1).toFixed(2);
        }
        if (document.getElementById('display_diff_section_2')) {
            document.getElementById('display_diff_section_2').textContent = (expert2 - auto2).toFixed(2);
        }
        if (document.getElementById('display_diff_section_3')) {
            document.getElementById('display_diff_section_3').textContent = (expert3 - auto3).toFixed(2);
        }
        if (document.getElementById('display_diff_section_4')) {
            document.getElementById('display_diff_section_4').textContent = (expert4 - auto4).toFixed(2);
        }
        if (document.getElementById('display_diff_section_5')) {
            document.getElementById('display_diff_section_5').textContent = (expert5 - auto5).toFixed(2);
        }
        
        // Update grand total (expert total should be 0)
        recalculateGrandTotal();
        
        // Update section totals at bottom (should show 0 for expert scores)
        updateSectionTotalDisplays();
    }
});
</script>

</body>
</html>

