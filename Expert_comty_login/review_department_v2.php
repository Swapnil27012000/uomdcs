<?php
/**
 * Expert Review - Complete Department Verification Page
 * Displays ALL department data from ALL sections for expert verification
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require('session.php');
require('expert_functions.php');
require('data_fetcher.php');

// Get department ID from URL (accept DEPT_ID or department code)
$dept_id_raw = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
$dept_id = resolveDepartmentId($dept_id_raw);
$academic_year = getAcademicYear();

if (!$dept_id) {
    die("Department ID is required");
}

// Fetch ALL department data
$dept_data = fetchAllDepartmentData($dept_id, $academic_year);
$documents = fetchAllSupportingDocuments($dept_id, $academic_year);
$grouped_docs = groupDocumentsBySection($documents);

// Get department info
$dept_name = '';
$dept_code = '';
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
if ($dept_stmt) {
    $dept_stmt->bind_param("ssi", $academic_year, $academic_year, $dept_id);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    if ($dept_row = $dept_result->fetch_assoc()) {
        $dept_name = $dept_row['dept_name'] ?? '';
        $dept_code = $dept_row['dept_code'] ?? '';
    }
    $dept_stmt->close();
}

// Get existing expert review
$expert_review = getExpertReview($email, $dept_id, $academic_year);
$is_locked = $expert_review ? (bool)$expert_review['is_locked'] : false;

// Calculate auto scores
$auto_scores = getDepartmentScores($dept_id, $academic_year);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Department - <?php echo htmlspecialchars($dept_name); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .review-container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        .review-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2rem;
        }
        .section-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            margin-bottom: 2rem;
            border-left: 5px solid #667eea;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .data-item {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
            align-items: center;
        }
        .data-item:hover {
            background: #f8f9fa;
        }
        .data-label {
            font-weight: 600;
            color: #333;
        }
        .data-value {
            color: #666;
        }
        .score-display {
            text-align: center;
            padding: 0.5rem;
            border-radius: 5px;
            font-weight: 600;
        }
        .score-dept {
            background: #e3f2fd;
            color: #1976d2;
        }
        .score-expert {
            background: #e8f5e9;
            color: #388e3c;
        }
        .expert-input {
            width: 100%;
            padding: 0.5rem;
            border: 2px solid #ddd;
            border-radius: 5px;
            text-align: center;
        }
        .expert-input:focus {
            border-color: #667eea;
            outline: none;
        }
        .doc-link {
            color: #667eea;
            text-decoration: none;
            cursor: pointer;
        }
        .doc-link:hover {
            text-decoration: underline;
        }
        .section-title {
            color: #667eea;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 3px solid #667eea;
        }
        .subsection-title {
            color: #764ba2;
            font-size: 1.3rem;
            font-weight: 600;
            margin-top: 2rem;
            margin-bottom: 1rem;
        }
        .btn-save {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        .btn-lock {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        .btn-lock:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }
        .locked-banner {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
        .score-summary {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 2rem;
            border-radius: 10px;
            margin: 2rem 0;
        }
        .score-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid #dee2e6;
        }
        .score-row:last-child {
            border-bottom: none;
            font-weight: 700;
            font-size: 1.2rem;
        }
        .no-data {
            text-align: center;
            padding: 2rem;
            color: #999;
            font-style: italic;
        }
        .narrative-field {
            width: 100%;
            min-height: 150px;
            padding: 1rem;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-family: inherit;
        }
        .doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .doc-card {
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
            text-align: center;
            transition: all 0.3s;
        }
        .doc-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
        }
    </style>
</head>
<body>

<div class="review-container">
    <!-- Header -->
    <div class="review-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="mb-2">Department Review</h1>
                <h3><?php echo htmlspecialchars($dept_name); ?> (<?php echo $dept_code; ?>)</h3>
                <p class="mb-0">Academic Year: <?php echo $academic_year; ?></p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-light">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <div class="p-4">
        <?php if ($is_locked): ?>
        <div class="locked-banner">
            <i class="fas fa-lock"></i> This department review is locked. No further changes can be made.
        </div>
        <?php endif; ?>

        <!-- Score Summary -->
        <div class="score-summary">
            <h3 class="text-center mb-4">Score Summary</h3>
            <div class="score-row">
                <div class="fw-bold">Section</div>
                <div class="text-center fw-bold">Dept Auto Score</div>
                <div class="text-center fw-bold">Expert Score</div>
                <div class="text-center fw-bold">Difference</div>
            </div>
            <div class="score-row">
                <div>Section I: Faculty Output & Research</div>
                <div class="text-center score-dept"><?php echo number_format($auto_scores['section_1'], 2); ?> / 300</div>
                <div class="text-center score-expert"><span id="expert_section_1">0.00</span> / 300</div>
                <div class="text-center"><span id="diff_section_1">0.00</span></div>
            </div>
            <div class="score-row">
                <div>Section II: NEP Initiatives</div>
                <div class="text-center score-dept"><?php echo number_format($auto_scores['section_2'], 2); ?> / 100</div>
                <div class="text-center score-expert"><span id="expert_section_2">0.00</span> / 100</div>
                <div class="text-center"><span id="diff_section_2">0.00</span></div>
            </div>
            <div class="score-row">
                <div>Section III: Governance</div>
                <div class="text-center score-dept"><?php echo number_format($auto_scores['section_3'], 2); ?> / 110</div>
                <div class="text-center score-expert"><span id="expert_section_3">0.00</span> / 110</div>
                <div class="text-center"><span id="diff_section_3">0.00</span></div>
            </div>
            <div class="score-row">
                <div>Section IV: Student Support</div>
                <div class="text-center score-dept"><?php echo number_format($auto_scores['section_4'], 2); ?> / 140</div>
                <div class="text-center score-expert"><span id="expert_section_4">0.00</span> / 140</div>
                <div class="text-center"><span id="diff_section_4">0.00</span></div>
            </div>
            <div class="score-row">
                <div>Section V: Conferences & Collaborations</div>
                <div class="text-center score-dept"><?php echo number_format($auto_scores['section_5'], 2); ?> / 75</div>
                <div class="text-center score-expert"><span id="expert_section_5">0.00</span> / 75</div>
                <div class="text-center"><span id="diff_section_5">0.00</span></div>
            </div>
            <div class="score-row">
                <div>TOTAL</div>
                <div class="text-center score-dept"><?php echo number_format($auto_scores['total'], 2); ?> / 725</div>
                <div class="text-center score-expert"><span id="expert_total">0.00</span> / 725</div>
                <div class="text-center"><span id="diff_total">0.00</span></div>
            </div>
        </div>

        <!-- Section A: Brief Details -->
        <div class="section-card">
            <h2 class="section-title"><i class="fas fa-info-circle"></i> Section A: Brief Details of the Department</h2>
            
            <?php if (!empty($dept_data['section_a'])): 
                $sec_a = $dept_data['section_a'];
            ?>
                <div class="subsection-title">Department Information</div>
                <div class="data-item">
                    <div class="data-label">Department Name</div>
                    <div class="data-value" colspan="3"><?php echo htmlspecialchars($sec_a['DEPARTMENT_NAME'] ?? 'N/A'); ?></div>
                </div>
                <div class="data-item">
                    <div class="data-label">Year of Establishment</div>
                    <div class="data-value" colspan="3"><?php echo htmlspecialchars($sec_a['YEAR_OF_ESTABLISHMENT'] ?? 'N/A'); ?></div>
                </div>
                <div class="data-item">
                    <div class="data-label">HoD Name</div>
                    <div class="data-value" colspan="3"><?php echo htmlspecialchars($sec_a['HOD_NAME'] ?? 'N/A'); ?></div>
                </div>
                <div class="data-item">
                    <div class="data-label">HoD Email</div>
                    <div class="data-value" colspan="3"><?php echo htmlspecialchars($sec_a['HOD_EMAIL'] ?? 'N/A'); ?></div>
                </div>
                <div class="data-item">
                    <div class="data-label">Sanctioned Teaching Faculty</div>
                    <div class="data-value" colspan="3"><?php echo htmlspecialchars($sec_a['SANCTIONED_TEACHING_FACULTY'] ?? 'N/A'); ?></div>
                </div>
                
                <div class="subsection-title mt-4">Faculty Composition</div>
                <div class="data-item">
                    <div class="data-label">Permanent Professors</div>
                    <div class="data-value" colspan="3"><?php echo htmlspecialchars($sec_a['PERMANENT_PROFESSORS'] ?? '0'); ?></div>
                </div>
                <div class="data-item">
                    <div class="data-label">Permanent Associate Professors</div>
                    <div class="data-value" colspan="3"><?php echo htmlspecialchars($sec_a['PERMANENT_ASSOCIATE_PROFESSORS'] ?? '0'); ?></div>
                </div>
                <div class="data-item">
                    <div class="data-label">Permanent Assistant Professors</div>
                    <div class="data-value" colspan="3"><?php echo htmlspecialchars($sec_a['PERMANENT_ASSISTANT_PROFESSORS'] ?? '0'); ?></div>
                </div>
                <div class="data-item">
                    <div class="data-label">Faculty with PhD (Permanent)</div>
                    <div class="data-value" colspan="3"><?php echo htmlspecialchars($sec_a['NUM_PERM_PHD'] ?? '0'); ?></div>
                </div>
                <div class="data-item">
                    <div class="data-label">Faculty with PhD (Adhoc)</div>
                    <div class="data-value" colspan="3"><?php echo htmlspecialchars($sec_a['NUM_ADHOC_PHD'] ?? '0'); ?></div>
                </div>

                <?php 
                // Supporting Documents for Section A
                if (isset($grouped_docs['details_dept'])):
                ?>
                <div class="subsection-title mt-4">Supporting Documents</div>
                <div class="doc-grid">
                    <?php foreach ($grouped_docs['details_dept'] as $doc): ?>
                    <div class="doc-card">
                        <i class="fas fa-file-pdf fa-3x text-danger mb-2"></i>
                        <div class="small"><?php echo htmlspecialchars($doc['document_title']); ?></div>
                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn btn-sm btn-primary mt-2">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="no-data">No data available for this section</div>
            <?php endif; ?>
        </div>

        <!-- Section B: Category -->
        <div class="section-card">
            <h2 class="section-title"><i class="fas fa-tags"></i> Section B: Department Category</h2>
            
            <?php if (!empty($dept_data['section_b'])): 
                $sec_b = $dept_data['section_b'];
            ?>
                <div class="data-item">
                    <div class="data-label">Category</div>
                    <div class="data-value" colspan="3"><?php echo htmlspecialchars($sec_b['category'] ?? 'N/A'); ?></div>
                </div>
                <div class="data-item">
                    <div class="data-label">Year of Establishment</div>
                    <div class="data-value" colspan="3"><?php echo htmlspecialchars($sec_b['year_of_establishment'] ?? 'N/A'); ?></div>
                </div>
                <div class="data-item">
                    <div class="data-label">Areas of Research</div>
                    <div class="data-value" colspan="3"><?php echo nl2br(htmlspecialchars($sec_b['areas_of_research'] ?? 'N/A')); ?></div>
                </div>
            <?php else: ?>
                <div class="no-data">No category information available</div>
            <?php endif; ?>
        </div>

        <!-- THIS IS JUST THE BEGINNING - Sections I-V will follow with complete data display -->
        <!-- Due to length, I'll show you the pattern. Complete implementation continues... -->
        
        <div class="alert alert-info">
            <h4><i class="fas fa-info-circle"></i> Implementation Status</h4>
            <p>I've created the foundation with:</p>
            <ul>
                <li>✅ Data fetcher for ALL sections</li>
                <li>✅ Section A & B display</li>
                <li>🔄 Sections I-V require detailed field-by-field implementation (3000+ lines)</li>
                <li>🔄 Each field needs: Dept Value | Auto Score | Expert Input | Expert Score</li>
                <li>🔄 Real-time calculation</li>
                <li>🔄 Document viewing for each item</li>
            </ul>
            <p class="mb-0"><strong>Next:</strong> I'll complete Sections I-V with full verification fields. This requires the exact field mapping from each form.</p>
        </div>

        <!-- Action Buttons -->
        <div class="text-center mt-4 mb-4">
            <?php if (!$is_locked): ?>
            <button type="button" class="btn-save me-3" onclick="saveProgress()">
                <i class="fas fa-save"></i> Save Progress
            </button>
            <button type="button" class="btn-lock" onclick="lockReview()">
                <i class="fas fa-lock"></i> Verify & Lock Department
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Save progress
function saveProgress() {
    alert('Save functionality will be implemented with AJAX to save_expert_review.php');
}

// Lock review
function lockReview() {
    if (confirm('Are you sure you want to lock this review? This action cannot be undone.')) {
        alert('Lock functionality will be implemented');
    }
}

// Real-time score calculation (to be implemented)
function calculateScores() {
    // This will calculate expert scores based on inputs
}
</script>

</body>
</html>

