<?php
/**
 * Consolidated Score Page - Department View
 * Displays auto-calculated scores for the logged-in department
 * Similar to Expert_comty_login but shows only department auto scores
 */
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1); // Still log errors to error_log file

require('session.php');
// Note: config.php is already loaded by session.php -> session_verification.php

// Load common functions first to get getAcademicYear()
if (file_exists(__DIR__ . '/common_functions.php')) {
    require_once(__DIR__ . '/common_functions.php');
}

// Load expert functions to get score calculation functions (these also load config.php, but check if $conn exists)
require_once(__DIR__ . '/../Expert_comty_login/expert_functions.php');
require_once(__DIR__ . '/../Expert_comty_login/data_fetcher.php');

// Get department ID - try multiple sources
$dept_id = (int)($userInfo['DEPT_ID'] ?? $_SESSION['dept_id'] ?? 0);
$dept_code = htmlspecialchars($userInfo['DEPT_COLL_NO'] ?? '', ENT_QUOTES, 'UTF-8');
$dept_name = htmlspecialchars($userInfo['DEPT_NAME'] ?? '', ENT_QUOTES, 'UTF-8');

// If no dept_id, set defaults to allow page to display
if (!$dept_id) {
    $dept_id = 0;
    $dept_code = '';
    $dept_name = 'Unknown Department';
}

// Get academic year - use function from common_functions.php (preferred) or expert_functions.php
if (function_exists('getAcademicYear')) {
    $academic_year = getAcademicYear();
} else {
    // Fallback calculation - matches the corrected logic
    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    $academic_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 2) . '-' . ($current_year - 1);
}

// Fetch department data for recalculation - only if dept_id is valid
$dept_data = [];
$auto_scores = [
    'section_1' => 0,
    'section_2' => 0,
    'section_3' => 0,
    'section_4' => 0,
    'section_5' => 0,
    'total' => 0
];

if ($dept_id > 0) {
    try {
        // CRITICAL: Always fetch fresh data (bypass any potential caching)
        // Clear cache first to ensure we get the latest data
        clearDepartmentScoreCache($dept_id, $academic_year, false);
        
        // Fetch fresh data from database
        $dept_data = fetchAllDepartmentData($dept_id, $academic_year);
    } catch (Exception $e) {
        error_log("Consolidated_Score: Error fetching department data: " . $e->getMessage());
        $dept_data = [];
    }

    // CRITICAL: Use centralized calculation function for consistency
    // This ensures all sections are calculated using the same logic as review_complete.php
    // Force recalculation from fresh data (update_cache = true to store results)
    $auto_scores = recalculateAllSectionsFromData($dept_id, $academic_year, $dept_data, true);
    
    error_log("[Consolidated_Score] FINAL SCORES BREAKDOWN for dept_id=$dept_id:");
    error_log("  Section I (Faculty Output): " . $auto_scores['section_1']);
    error_log("  Section II (NEP Initiatives): " . $auto_scores['section_2']);
    error_log("  Section III (Departmental Governance): " . $auto_scores['section_3']);
    error_log("  Section IV (Student Support): " . $auto_scores['section_4']);
    error_log("  Section V (Conferences & Collaborations): " . $auto_scores['section_5']);
    error_log("  TOTAL: " . $auto_scores['total'] . " / 725");
    
    // Note: Cache is already updated by recalculateAllSectionsFromData(), no need to update again
}

require('unified_header.php');
?>

<div class="container-fluid" style="max-width: 100%; overflow-x: hidden;">
    <div class="main-content-area">
        <?php if ($dept_id <= 0): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Error:</strong> Department ID not found. Please <a href="../unified_login.php">login again</a>.
            </div>
        <?php endif; ?>
        
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-chart-line me-3"></i>Consolidated Score Summary
                    </h1>
                    <p class="page-subtitle">Auto-calculated scores based on your department's submitted data | Academic Year: <?php echo htmlspecialchars($academic_year, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <div style="display: flex; gap: 10px; margin-left: 20px; flex-wrap: wrap;">
                    <a href="department_review.php" class="btn btn-success" style="white-space: nowrap;">
                        <i class="fas fa-eye"></i> View Complete Data Review
                    </a>
                    <a href="export_page_pdf.php?page=Consolidated_Score" target="_blank" class="btn btn-warning" style="white-space: nowrap;">
                        <i class="fas fa-file-pdf"></i> Download Consolidated Score PDF
                    </a>
                    <a href="export_udrf_data_report.php" target="_blank" class="btn btn-primary" style="white-space: nowrap;">
                        <i class="fas fa-file-alt"></i> Download UDRF Data Report
                    </a>
                </div>
            </div>
        </div>

        <!-- Score Summary Card -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h3 class="card-title mb-0">
                    <i class="fas fa-trophy"></i> Department Auto Scores
                </h3>
            </div>
            <div class="card-body">
                <div class="score-summary-table">
                    <table class="table table-bordered table-hover">
                        <thead class="table-primary">
                            <tr>
                                <th style="width: 5%;">Sr. No.</th>
                                <th style="width: 50%;">Section</th>
                                <th style="width: 20%;" class="text-center">Department Auto Score</th>
                                <th style="width: 25%;" class="text-center">Max Marks</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                                <td class="text-center"><strong>I</strong></td>
                                <td><strong>Faculty Output, Research & Professional Activities</strong></td>
                                <td class="text-center score-value"><?php echo number_format($auto_scores['section_1'], 2); ?></td>
                                <td class="text-center">300</td>
                </tr>
                <tr>
                                <td class="text-center"><strong>II</strong></td>
                                <td><strong>NEP Initiatives, Teaching, Learning & Assessment</strong></td>
                                <td class="text-center score-value"><?php echo number_format($auto_scores['section_2'], 2); ?></td>
                                <td class="text-center">100</td>
                </tr>
                <tr>
                                <td class="text-center"><strong>III</strong></td>
                                <td><strong>Departmental Governance & Practices</strong></td>
                                <td class="text-center score-value"><?php echo number_format($auto_scores['section_3'], 2); ?></td>
                                <td class="text-center">110</td>
                </tr>
                <tr>
                                <td class="text-center"><strong>IV</strong></td>
                                <td><strong>Student Support, Achievements & Progression</strong></td>
                                <td class="text-center score-value"><?php echo number_format($auto_scores['section_4'], 2); ?></td>
                                <td class="text-center">140</td>
                </tr>
                <tr>
                                <td class="text-center"><strong>V</strong></td>
                                <td><strong>Conferences, Workshops & Collaborations</strong></td>
                                <td class="text-center score-value"><?php echo number_format($auto_scores['section_5'], 2); ?></td>
                                <td class="text-center">75</td>
                </tr>
                            <tr class="table-success" style="font-weight: bold; font-size: 1.1em;">
                                <td colspan="2" class="text-end"><strong>GRAND TOTAL</strong></td>
                                <td class="text-center score-value" style="font-size: 1.2em;"><?php echo number_format($auto_scores['total'], 2); ?></td>
                                <td class="text-center"><strong>725</strong></td>
                            </tr>
            </tbody>
        </table>
                </div>
            </div> 
        </div>

        <!-- Instructions Box -->
        <div class="alert alert-warning" style="border-left: 5px solid #ffc107;">
            <h5><i class="fas fa-info-circle"></i> Important Instructions</h5>
            <p><strong>Data Review:</strong> This is a review of your submitted data. Please check all sections carefully to ensure data integrity, completeness, and accuracy.</p>
            <p><strong>How to Review:</strong> Click on <strong>"View Complete Data Review"</strong> button above to see all your submitted data across all sections, including supporting documents. This will help you verify that all information is correctly entered and reflected in the scores.</p>
            <p><strong>Making Changes:</strong> If you need to update any data, go to the respective form pages (Faculty Output, NEP Initiatives, etc.) and make the changes. The scores will automatically update and reflect here immediately.</p>
            <p><strong>Support:</strong> If you face any issues, notice discrepancies, or need assistance, please report to <a href="mailto:techsupport.nirf@mu.ac.in" style="font-weight: 600; color: #1976d2;">techsupport.nirf@mu.ac.in</a></p>
            <p class="mb-0"><strong>Note:</strong> These are preliminary auto-calculated scores. Final scores will be determined after expert committee review and verification.</p>
        </div>

        <!-- Information Box -->
        <div class="alert alert-info">
            <h5><i class="fas fa-info-circle"></i> About These Scores</h5>
            <p><strong>Auto-Calculated Scores:</strong> These scores are automatically calculated based on the data you have submitted in various forms. The calculation follows UDRF guidelines and multiplies counts by their respective marks.</p>
            <p class="mb-0"><strong>Last Updated:</strong> Scores are recalculated automatically when you view this page based on your latest submitted data.</p>
        </div>
    </div>
</div>

<style>
    .score-value {
        font-weight: 600;
        font-size: 1.1em;
        color: #667eea;
    }
    
    .score-summary-table {
        overflow-x: auto;
    }
    
    .table th {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        font-weight: 600;
    }
    
    .table tbody tr:hover {
        background-color: #f8f9fa;
    }
</style>

<?php require('unified_footer.php'); ?>
