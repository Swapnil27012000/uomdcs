<?php
/**
 * Department Review Page - Read-Only View of All Submitted Data
 * Shows all sections with department data and auto-calculated scores only
 * No expert scores or inputs - purely for department to review their own data
 */

ob_start();
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require('session.php');

// Load common functions first to get getAcademicYear()
if (file_exists(__DIR__ . '/common_functions.php')) {
    require_once(__DIR__ . '/common_functions.php');
}

// Load expert functions to get score calculation functions
require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
require_once(__DIR__ . '/../Expert_comty_login/data_fetcher.php');

// Get department ID - try multiple sources
$dept_id = (int)($userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0);
$dept_code = htmlspecialchars($userInfo['DEPT_COLL_NO'] ?? '', ENT_QUOTES, 'UTF-8');
$dept_name = htmlspecialchars($userInfo['DEPT_NAME'] ?? '', ENT_QUOTES, 'UTF-8');

// If no dept_id, redirect to login
if (!$dept_id) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Location: ../unified_login.php');
    }
    exit;
}

// Get academic year
if (function_exists('getAcademicYear')) {
    $academic_year = getAcademicYear();
} else {
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    $academic_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 2) . '-' . ($current_year - 1);
}

// CRITICAL: Always fetch fresh data (bypass any potential caching)
clearDepartmentScoreCache($dept_id, $academic_year, false);

// Fetch ALL department data (fresh from database)
$dept_data = fetchAllDepartmentData($dept_id, $academic_year);
$documents = fetchAllSupportingDocuments($dept_id, $academic_year);
$grouped_docs = groupDocumentsBySection($documents);

// CRITICAL: Use centralized calculation function for consistency
$auto_scores = recalculateAllSectionsFromData($dept_id, $academic_year, $dept_data, true);

// Set flags for read-only mode (for section includes)
$is_readonly = true;
$is_locked = false;
$is_chairman_view = false;
$is_department_view = true; // Special flag to completely hide expert columns
$readonly_mode = true;

// No expert review data needed (department view only)
$expert_review = null;
$expert_scores = [
    'section_1' => 0,
    'section_2' => 0,
    'section_3' => 0,
    'section_4' => 0,
    'section_5' => 0,
    'total' => 0
];
$expert_item_scores = [];
$expert_narrative_scores = [];

require('unified_header.php');
?>
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
        
        .page-header h1 {
            margin: 0;
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .page-header p {
            margin: 0.5rem 0 0;
            opacity: 0.9;
            font-size: 1.1rem;
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
            grid-template-columns: 2fr 1fr 1fr !important;
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
            grid-template-columns: 2fr 1fr 1fr !important;
            gap: 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 600;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            align-items: center;
        }
        
        /* Hide expert columns completely in department view */
        .data-grid .expert-input,
        .data-grid .expert-score {
            display: none !important;
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
            grid-template-columns: 2fr 1fr 1fr;
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
        
        .readonly-banner {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            color: #1976d2;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            font-weight: 600;
            margin-bottom: 2rem;
            border: 2px solid #90caf9;
        }
        
        .info-box {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .info-box h5 {
            color: #856404;
            margin-bottom: 1rem;
        }
        
        .info-box p {
            color: #856404;
            margin-bottom: 0.5rem;
        }
        
        .info-box a {
            color: #1976d2;
            font-weight: 600;
        }
    </style>

<div class="container-fluid" style="max-width: 100%; overflow-x: hidden;">
    <div class="main-content-area">
        <div class="main-container">
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1><i class="fas fa-clipboard-check"></i> Department Data Review</h1>
                    <p><?php echo htmlspecialchars($dept_name); ?> (<?php echo htmlspecialchars($dept_code); ?>) | Academic Year: <?php echo htmlspecialchars($academic_year); ?></p>
                </div>
                <div>
                    <a href="Consolidated_Score.php" class="btn btn-light btn-lg" style="white-space: nowrap;">
                        <i class="fas fa-arrow-left"></i> Back to Consolidated Score
                    </a>
                </div>
            </div>
        </div>
        
        <div class="readonly-banner">
            <i class="fas fa-info-circle"></i> This is a read-only view of your submitted data. Review all sections to ensure data integrity and completeness.
        </div>
        
        <!-- Consolidated Score Summary -->
        <div class="score-summary">
            <h2 style="margin-bottom: 1.5rem; color: var(--primary-color);">
                <i class="fas fa-chart-line"></i> Consolidated Score Summary
            </h2>
            <div class="score-row header">
                <div><strong>Section</strong></div>
                <div><strong>Department Auto Score</strong></div>
                <div><strong>Max Marks</strong></div>
            </div>
            <div class="score-row">
                <div><strong>Section I:</strong> Faculty Output & Research</div>
                <div class="auto-score"><?php echo number_format($auto_scores['section_1'], 2); ?></div>
                <div>300</div>
            </div>
            <div class="score-row">
                <div><strong>Section II:</strong> NEP Initiatives</div>
                <div class="auto-score"><?php echo number_format($auto_scores['section_2'], 2); ?></div>
                <div>100</div>
            </div>
            <div class="score-row">
                <div><strong>Section III:</strong> Departmental Governance</div>
                <div class="auto-score"><?php echo number_format($auto_scores['section_3'], 2); ?></div>
                <div>110</div>
            </div>
            <div class="score-row">
                <div><strong>Section IV:</strong> Student Support & Progression</div>
                <div class="auto-score"><?php echo number_format($auto_scores['section_4'], 2); ?></div>
                <div>140</div>
            </div>
            <div class="score-row">
                <div><strong>Section V:</strong> Conferences & Collaborations</div>
                <div class="auto-score"><?php echo number_format($auto_scores['section_5'], 2); ?></div>
                <div>75</div>
            </div>
            <div class="score-row total">
                <div><strong>GRAND TOTAL</strong></div>
                <div class="auto-score" style="font-size: 1.5em;"><?php echo number_format($auto_scores['total'], 2); ?></div>
                <div><strong>725</strong></div>
            </div>
        </div>
        
        <?php
        // Include ALL Sections (read-only mode)
        // Set variables needed by section files
        $email = $userInfo['EMAIL'] ?? '';
        
        // Include section files from Expert_comty_login directory
        include(__DIR__ . '/../Expert_comty_login/section1_faculty_output.php');
        include(__DIR__ . '/../Expert_comty_login/section2_nep_initiatives.php');
        include(__DIR__ . '/../Expert_comty_login/section3_governance.php');
        include(__DIR__ . '/../Expert_comty_login/section4_student_support.php');
        include(__DIR__ . '/../Expert_comty_login/section5_conferences.php');
        ?>
        
        </div>
    </div>
</div>

<?php require('unified_footer.php'); ?>

