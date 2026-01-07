<?php
// Expert_comty_login/review_department.php - Expert Review Page for Department
require('session.php');
require('expert_functions.php');

// Get department ID from query parameter (accept DEPT_ID or department code)
$dept_id_raw = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
$dept_id = resolveDepartmentId($dept_id_raw);
if (!$dept_id) {
    header('Location: dashboard.php');
    exit;
}

$academic_year = getAcademicYear();
$expert_category = getExpertCategory($email);

// Get department information
global $conn;
$stmt = $conn->prepare("SELECT 
        dm.DEPT_COLL_NO AS DEPT_CODE,
        COALESCE(dn.collname, dm.DEPT_NAME) AS DEPT_NAME,
        COALESCE(dp.category, bd.TYPE) AS CATEGORY
    FROM department_master dm
    LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
    LEFT JOIN department_profiles dp ON dp.A_YEAR = ? 
        AND CAST(dp.dept_id AS UNSIGNED) IN (dm.DEPT_ID, dm.DEPT_COLL_NO)
    LEFT JOIN brief_details_of_the_department bd ON bd.DEPT_ID = dm.DEPT_ID AND bd.A_YEAR = ?
    WHERE dm.DEPT_ID = ?
    LIMIT 1");
$stmt->bind_param("ssi", $academic_year, $academic_year, $dept_id);
$stmt->execute();
$result = $stmt->get_result();
$dept_info = $result->fetch_assoc();
$stmt->close();

if (!$dept_info) {
    header('Location: dashboard.php');
    exit;
}

// Get department scores
$dept_scores = getDepartmentScores($dept_id, $academic_year);

// Get existing expert review
$review = getExpertReview($email, $dept_id, $academic_year);
$review_status = $review ? $review['review_status'] : 'pending';
$is_locked = $review ? (bool)$review['is_locked'] : false;

// Handle form submission (save scores)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_locked) {
    $expert_scores = [
        'section_1' => isset($_POST['expert_score_1']) ? (float)$_POST['expert_score_1'] : null,
        'section_2' => isset($_POST['expert_score_2']) ? (float)$_POST['expert_score_2'] : null,
        'section_3' => isset($_POST['expert_score_3']) ? (float)$_POST['expert_score_3'] : null,
        'section_4' => isset($_POST['expert_score_4']) ? (float)$_POST['expert_score_4'] : null,
        'section_5' => isset($_POST['expert_score_5']) ? (float)$_POST['expert_score_5'] : null,
    ];
    
    $expert_total = array_sum(array_filter($expert_scores, function($v) { return $v !== null; }));
    $review_notes = isset($_POST['review_notes']) ? trim($_POST['review_notes']) : '';
    $action = $_POST['action'] ?? 'save';
    
    // Determine status
    $new_status = 'in_progress';
    if ($action === 'complete') {
        $new_status = 'completed';
    } elseif ($action === 'lock') {
        $new_status = 'completed';
        $is_locked = true;
    }
    
    // Insert or update review
    $stmt = $conn->prepare("INSERT INTO expert_reviews 
        (expert_email, dept_id, academic_year, category,
         dept_score_section_1, dept_score_section_2, dept_score_section_3, dept_score_section_4, dept_score_section_5, dept_total_score,
         expert_score_section_1, expert_score_section_2, expert_score_section_3, expert_score_section_4, expert_score_section_5, expert_total_score,
         review_status, is_locked, review_notes, review_started_at, review_completed_at, review_locked_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                IF(review_started_at IS NULL, NOW(), review_started_at),
                IF(? = 'completed' OR ? = 'lock', NOW(), review_completed_at),
                IF(? = 'lock', NOW(), review_locked_at))
        ON DUPLICATE KEY UPDATE
         dept_score_section_1 = VALUES(dept_score_section_1),
         dept_score_section_2 = VALUES(dept_score_section_2),
         dept_score_section_3 = VALUES(dept_score_section_3),
         dept_score_section_4 = VALUES(dept_score_section_4),
         dept_score_section_5 = VALUES(dept_score_section_5),
         dept_total_score = VALUES(dept_total_score),
         expert_score_section_1 = VALUES(expert_score_section_1),
         expert_score_section_2 = VALUES(expert_score_section_2),
         expert_score_section_3 = VALUES(expert_score_section_3),
         expert_score_section_4 = VALUES(expert_score_section_4),
         expert_score_section_5 = VALUES(expert_score_section_5),
         expert_total_score = VALUES(expert_total_score),
         review_status = VALUES(review_status),
         is_locked = VALUES(is_locked),
         review_notes = VALUES(review_notes),
         review_completed_at = IF(VALUES(review_status) = 'completed' OR VALUES(review_status) = 'lock', NOW(), review_completed_at),
         review_locked_at = IF(VALUES(is_locked) = 1, NOW(), review_locked_at)");
    
    $stmt->bind_param("sissddddddddddddssssss", 
        $email, $dept_id, $academic_year, $expert_category,
        $dept_scores['section_1'], $dept_scores['section_2'], $dept_scores['section_3'], $dept_scores['section_4'], $dept_scores['section_5'], $dept_scores['total'],
        $expert_scores['section_1'], $expert_scores['section_2'], $expert_scores['section_3'], $expert_scores['section_4'], $expert_scores['section_5'], $expert_total,
        $new_status, $is_locked, $review_notes, $action, $action, $action);
    
    if ($stmt->execute()) {
        $_SESSION['review_saved'] = true;
        header('Location: review_department.php?dept_id=' . $dept_id);
        exit;
    }
    $stmt->close();
}

// Refresh review data after save
if (isset($_SESSION['review_saved'])) {
    $review = getExpertReview($email, $dept_id, $academic_year);
    $review_status = $review ? $review['review_status'] : 'pending';
    $is_locked = $review ? (bool)$review['is_locked'] : false;
    unset($_SESSION['review_saved']);
}

// Get department data for display
// Section A: Brief Details
$brief_details = null;
$stmt = $conn->prepare("SELECT * FROM brief_details_of_the_department WHERE DEPT_ID = ? AND A_YEAR = ? LIMIT 1");
$stmt->bind_param("is", $dept_id, $academic_year);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $brief_details = $result->fetch_assoc();
}
$stmt->close();

// Get supporting documents for various sections
$docs_section_a = getSupportingDocuments($dept_id, 'details_of_department', $academic_year);
$docs_section_1 = getSupportingDocuments($dept_id, 'faculty_output', $academic_year);
$docs_section_2 = getSupportingDocuments($dept_id, 'nep_initiatives', $academic_year);
$docs_section_3 = getSupportingDocuments($dept_id, 'departmental_governance', $academic_year);
$docs_section_4 = getSupportingDocuments($dept_id, 'student_support', $academic_year);
$docs_section_5a = getSupportingDocuments($dept_id, 'conferences_workshops', $academic_year);
$docs_section_5b = getSupportingDocuments($dept_id, 'collaborations', $academic_year);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Department - <?php echo htmlspecialchars($dept_info['DEPT_NAME']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f5f5f5;
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .review-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin: 2rem auto;
            padding: 2rem;
            max-width: 1400px;
        }
        .section-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin: 2rem 0 1rem 0;
        }
        .section-content {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .data-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .data-row:last-child {
            border-bottom: none;
        }
        .data-label {
            font-weight: 600;
            color: #495057;
        }
        .data-value {
            color: #212529;
        }
        .score-input-group {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
            margin-bottom: 1rem;
        }
        .score-input-group.locked {
            background: #f8f9fa;
            border-color: #6c757d;
        }
        .doc-link {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: #667eea;
            color: white;
            border-radius: 5px;
            text-decoration: none;
            margin: 0.25rem;
            font-size: 0.9rem;
        }
        .doc-link:hover {
            background: #764ba2;
            color: white;
        }
        .alert-locked {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <div class="navbar-text text-white">
                <strong><?php echo htmlspecialchars($dept_info['DEPT_CODE']); ?> - <?php echo htmlspecialchars($dept_info['DEPT_NAME']); ?></strong>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <?php if ($is_locked): ?>
            <div class="alert-locked">
                <i class="fas fa-lock"></i> <strong>Review Locked:</strong> This review has been finalized and locked. You can view the data but cannot make changes.
            </div>
        <?php endif; ?>

        <form method="POST" id="reviewForm">
            <input type="hidden" name="action" id="actionField" value="save">

            <!-- Section A: Brief Details of Department -->
            <div class="review-container">
                <div class="section-header">
                    <h3 class="mb-0"><i class="fas fa-building"></i> Section A: Brief Details of the Department</h3>
                </div>
                
                <div class="section-content">
                    <?php if ($brief_details): ?>
                        <div class="data-row">
                            <span class="data-label">Name of the Department:</span>
                            <span class="data-value"><?php echo htmlspecialchars($brief_details['DEPARTMENT_NAME'] ?? '-'); ?></span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">Year of Establishment:</span>
                            <span class="data-value"><?php echo htmlspecialchars($brief_details['YEAR_OF_ESTABLISHMENT'] ?? '-'); ?></span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">Name of the current HoD/Director:</span>
                            <span class="data-value"><?php echo htmlspecialchars($brief_details['HOD_NAME'] ?? '-'); ?></span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">Email of the HoD/Director:</span>
                            <span class="data-value"><?php echo htmlspecialchars($brief_details['HOD_EMAIL'] ?? '-'); ?></span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">Mobile Number of the HoD/Director:</span>
                            <span class="data-value"><?php echo htmlspecialchars($brief_details['HOD_MOBILE'] ?? '-'); ?></span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">Name of the IQAC Coordinator:</span>
                            <span class="data-value"><?php echo htmlspecialchars($brief_details['IQAC_COORDINATOR_NAME'] ?? '-'); ?></span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">Email of the IQAC Coordinator:</span>
                            <span class="data-value"><?php echo htmlspecialchars($brief_details['IQAC_COORDINATOR_EMAIL'] ?? '-'); ?></span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">Mobile Number of the IQAC Coordinator:</span>
                            <span class="data-value"><?php echo htmlspecialchars($brief_details['IQAC_COORDINATOR_MOBILE'] ?? '-'); ?></span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">Sanctioned Teaching Faculty:</span>
                            <span class="data-value"><?php echo htmlspecialchars($brief_details['SANCTIONED_TEACHING_FACULTY'] ?? '-'); ?></span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">Number of Permanent Faculties:</span>
                            <span class="data-value">
                                <?php 
                                $perm_prof = $brief_details['PERMANENT_PROFESSORS'] ?? 0;
                                $perm_assoc = $brief_details['PERMANENT_ASSOCIATE_PROFESSORS'] ?? 0;
                                $perm_asst = $brief_details['PERMANENT_ASSISTANT_PROFESSORS'] ?? 0;
                                echo ($perm_prof + $perm_assoc + $perm_asst) . ' (Prof: ' . $perm_prof . ', Assoc: ' . $perm_assoc . ', Asst: ' . $perm_asst . ')';
                                ?>
                            </span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">No of Ad hoc/ Contract Teachers:</span>
                            <span class="data-value"><?php echo htmlspecialchars($brief_details['CONTRACT_TEACHERS'] ?? '-'); ?></span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">Areas of Research:</span>
                            <span class="data-value"><?php echo htmlspecialchars($brief_details['AREAS_OF_RESEARCH'] ?? '-'); ?></span>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No data available for this section.</p>
                    <?php endif; ?>
                    
                    <?php if (!empty($docs_section_a)): ?>
                        <div class="mt-3">
                            <strong>Supporting Documents:</strong><br>
                            <?php foreach ($docs_section_a as $doc): 
                                // Build proper file path - handle both relative and absolute paths
                                $doc_path = $doc['file_path'];
                                if (strpos($doc_path, '../') === 0) {
                                    // Already has ../ prefix
                                    $web_path = $doc_path;
                                } elseif (strpos($doc_path, 'uploads/') === 0) {
                                    // Path starts with uploads/
                                    $web_path = '../' . $doc_path;
                                } else {
                                    // Assume it's relative to root
                                    $web_path = '../' . ltrim($doc_path, '/');
                                }
                                
                                // Check if file exists before displaying link
                                $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
                                $file_exists = file_exists($physical_path);
                            ?>
                                <a href="<?php echo htmlspecialchars($web_path); ?>" 
                                   target="_blank" 
                                   class="doc-link <?php echo !$file_exists ? 'text-warning' : ''; ?>"
                                   title="<?php echo $file_exists ? 'Click to view document' : 'File not found on server'; ?>">
                                    <i class="fas fa-file-pdf"></i> <?php echo htmlspecialchars($doc['document_title']); ?>
                                    <?php if (!$file_exists): ?>
                                        <small class="text-warning">(File missing)</small>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section B: Category -->
            <div class="review-container">
                <div class="section-header">
                    <h3 class="mb-0"><i class="fas fa-tags"></i> Section B: Category</h3>
                </div>
                <div class="section-content">
                    <div class="data-row">
                        <span class="data-label">UDRF Category of Department:</span>
                        <span class="data-value"><?php echo htmlspecialchars($dept_info['CATEGORY'] ?? '-'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Section I: Faculty Output, Research and Professional Activities -->
            <div class="review-container">
                <div class="section-header">
                    <h3 class="mb-0"><i class="fas fa-microscope"></i> Section I: Faculty Output, Research and Professional Activities Details</h3>
                </div>
                
                <div class="section-content">
                    <p class="text-muted">This section contains 26 items covering faculty output, research publications, patents, awards, etc.</p>
                    <p><strong>Note:</strong> Detailed data for all 26 items should be reviewed in the department's Faculty Output section.</p>
                    
                    <?php if (!empty($docs_section_1)): ?>
                        <div class="mt-3">
                            <strong>Supporting Documents:</strong><br>
                            <?php foreach ($docs_section_1 as $doc): 
                                $doc_path = $doc['file_path'];
                                $web_path = (strpos($doc_path, '../') === 0) ? $doc_path : ((strpos($doc_path, 'uploads/') === 0) ? '../' . $doc_path : '../' . ltrim($doc_path, '/'));
                                $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
                                $file_exists = file_exists($physical_path);
                            ?>
                                <a href="<?php echo htmlspecialchars($web_path); ?>" 
                                   target="_blank" 
                                   class="doc-link <?php echo !$file_exists ? 'text-warning' : ''; ?>"
                                   title="<?php echo $file_exists ? 'Click to view document' : 'File not found on server'; ?>">
                                    <i class="fas fa-file-pdf"></i> <?php echo htmlspecialchars($doc['document_title']); ?>
                                    <?php if (!$file_exists): ?>
                                        <small class="text-warning">(File missing)</small>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="score-input-group <?php echo $is_locked ? 'locked' : ''; ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label"><strong>Department Auto-Calculated Score:</strong></label>
                            <input type="text" class="form-control" value="<?php echo number_format($dept_scores['section_1'], 2); ?> / 300" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><strong>Expert Review Score:</strong></label>
                            <input type="number" name="expert_score_1" class="form-control" 
                                   min="0" max="300" step="0.01"
                                   value="<?php echo $review ? number_format($review['expert_score_section_1'] ?? '', 2) : ''; ?>"
                                   <?php echo $is_locked ? 'readonly' : ''; ?> required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section II: NEP Initiatives -->
            <div class="review-container">
                <div class="section-header">
                    <h3 class="mb-0"><i class="fas fa-lightbulb"></i> Section II: NEP Initiatives, Teaching, Learning, and Assessment Process</h3>
                </div>
                
                <div class="section-content">
                    <p class="text-muted">This section contains 6 items covering NEP initiatives, teaching-learning approaches, assessments, etc.</p>
                    
                    <?php if (!empty($docs_section_2)): ?>
                        <div class="mt-3">
                            <strong>Supporting Documents:</strong><br>
                            <?php foreach ($docs_section_2 as $doc): 
                                $doc_path = $doc['file_path'];
                                $web_path = (strpos($doc_path, '../') === 0) ? $doc_path : ((strpos($doc_path, 'uploads/') === 0) ? '../' . $doc_path : '../' . ltrim($doc_path, '/'));
                                $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
                                $file_exists = file_exists($physical_path);
                            ?>
                                <a href="<?php echo htmlspecialchars($web_path); ?>" 
                                   target="_blank" 
                                   class="doc-link <?php echo !$file_exists ? 'text-warning' : ''; ?>"
                                   title="<?php echo $file_exists ? 'Click to view document' : 'File not found on server'; ?>">
                                    <i class="fas fa-file-pdf"></i> <?php echo htmlspecialchars($doc['document_title']); ?>
                                    <?php if (!$file_exists): ?>
                                        <small class="text-warning">(File missing)</small>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="score-input-group <?php echo $is_locked ? 'locked' : ''; ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label"><strong>Department Auto-Calculated Score:</strong></label>
                            <input type="text" class="form-control" value="<?php echo number_format($dept_scores['section_2'], 2); ?> / 100" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><strong>Expert Review Score:</strong></label>
                            <input type="number" name="expert_score_2" class="form-control" 
                                   min="0" max="100" step="0.01"
                                   value="<?php echo $review ? number_format($review['expert_score_section_2'] ?? '', 2) : ''; ?>"
                                   <?php echo $is_locked ? 'readonly' : ''; ?> required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section III: Departmental Governance -->
            <div class="review-container">
                <div class="section-header">
                    <h3 class="mb-0"><i class="fas fa-cogs"></i> Section III: Departmental Governance and Practices</h3>
                </div>
                
                <div class="section-content">
                    <p class="text-muted">This section contains 21 items covering inclusive practices, green practices, governance, etc.</p>
                    
                    <?php if (!empty($docs_section_3)): ?>
                        <div class="mt-3">
                            <strong>Supporting Documents:</strong><br>
                            <?php foreach ($docs_section_3 as $doc): 
                                $doc_path = $doc['file_path'];
                                $web_path = (strpos($doc_path, '../') === 0) ? $doc_path : ((strpos($doc_path, 'uploads/') === 0) ? '../' . $doc_path : '../' . ltrim($doc_path, '/'));
                                $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
                                $file_exists = file_exists($physical_path);
                            ?>
                                <a href="<?php echo htmlspecialchars($web_path); ?>" 
                                   target="_blank" 
                                   class="doc-link <?php echo !$file_exists ? 'text-warning' : ''; ?>"
                                   title="<?php echo $file_exists ? 'Click to view document' : 'File not found on server'; ?>">
                                    <i class="fas fa-file-pdf"></i> <?php echo htmlspecialchars($doc['document_title']); ?>
                                    <?php if (!$file_exists): ?>
                                        <small class="text-warning">(File missing)</small>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="score-input-group <?php echo $is_locked ? 'locked' : ''; ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label"><strong>Department Auto-Calculated Score:</strong></label>
                            <input type="text" class="form-control" value="<?php echo number_format($dept_scores['section_3'], 2); ?> / 110" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><strong>Expert Review Score:</strong></label>
                            <input type="number" name="expert_score_3" class="form-control" 
                                   min="0" max="110" step="0.01"
                                   value="<?php echo $review ? number_format($review['expert_score_section_3'] ?? '', 2) : ''; ?>"
                                   <?php echo $is_locked ? 'readonly' : ''; ?> required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section IV: Student Support -->
            <div class="review-container">
                <div class="section-header">
                    <h3 class="mb-0"><i class="fas fa-hands-helping"></i> Section IV: Student Support, Achievements and Progression</h3>
                </div>
                
                <div class="section-content">
                    <p class="text-muted">This section contains 17 items covering student intake, support, achievements, etc.</p>
                    
                    <?php if (!empty($docs_section_4)): ?>
                        <div class="mt-3">
                            <strong>Supporting Documents:</strong><br>
                            <?php foreach ($docs_section_4 as $doc): 
                                $doc_path = $doc['file_path'];
                                $web_path = (strpos($doc_path, '../') === 0) ? $doc_path : ((strpos($doc_path, 'uploads/') === 0) ? '../' . $doc_path : '../' . ltrim($doc_path, '/'));
                                $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
                                $file_exists = file_exists($physical_path);
                            ?>
                                <a href="<?php echo htmlspecialchars($web_path); ?>" 
                                   target="_blank" 
                                   class="doc-link <?php echo !$file_exists ? 'text-warning' : ''; ?>"
                                   title="<?php echo $file_exists ? 'Click to view document' : 'File not found on server'; ?>">
                                    <i class="fas fa-file-pdf"></i> <?php echo htmlspecialchars($doc['document_title']); ?>
                                    <?php if (!$file_exists): ?>
                                        <small class="text-warning">(File missing)</small>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="score-input-group <?php echo $is_locked ? 'locked' : ''; ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label"><strong>Department Auto-Calculated Score:</strong></label>
                            <input type="text" class="form-control" value="<?php echo number_format($dept_scores['section_4'], 2); ?> / 140" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><strong>Expert Review Score:</strong></label>
                            <input type="number" name="expert_score_4" class="form-control" 
                                   min="0" max="140" step="0.01"
                                   value="<?php echo $review ? number_format($review['expert_score_section_4'] ?? '', 2) : ''; ?>"
                                   <?php echo $is_locked ? 'readonly' : ''; ?> required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section V: Conferences & Collaborations -->
            <div class="review-container">
                <div class="section-header">
                    <h3 class="mb-0"><i class="fas fa-handshake"></i> Section V: Conferences, Workshops, and Collaborations</h3>
                </div>
                
                <div class="section-content">
                    <p class="text-muted">This section contains 11 items covering conferences, workshops, and collaborations.</p>
                    
                    <?php if (!empty($docs_section_5a) || !empty($docs_section_5b)): ?>
                        <div class="mt-3">
                            <strong>Supporting Documents:</strong><br>
                            <?php foreach (array_merge($docs_section_5a, $docs_section_5b) as $doc): 
                                $doc_path = $doc['file_path'];
                                $web_path = (strpos($doc_path, '../') === 0) ? $doc_path : ((strpos($doc_path, 'uploads/') === 0) ? '../' . $doc_path : '../' . ltrim($doc_path, '/'));
                                $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
                                $file_exists = file_exists($physical_path);
                            ?>
                                <a href="<?php echo htmlspecialchars($web_path); ?>" 
                                   target="_blank" 
                                   class="doc-link <?php echo !$file_exists ? 'text-warning' : ''; ?>"
                                   title="<?php echo $file_exists ? 'Click to view document' : 'File not found on server'; ?>">
                                    <i class="fas fa-file-pdf"></i> <?php echo htmlspecialchars($doc['document_title']); ?>
                                    <?php if (!$file_exists): ?>
                                        <small class="text-warning">(File missing)</small>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="score-input-group <?php echo $is_locked ? 'locked' : ''; ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label"><strong>Department Auto-Calculated Score:</strong></label>
                            <input type="text" class="form-control" value="<?php echo number_format($dept_scores['section_5'], 2); ?> / 75" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><strong>Expert Review Score:</strong></label>
                            <input type="number" name="expert_score_5" class="form-control" 
                                   min="0" max="75" step="0.01"
                                   value="<?php echo $review ? number_format($review['expert_score_section_5'] ?? '', 2) : ''; ?>"
                                   <?php echo $is_locked ? 'readonly' : ''; ?> required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Review Notes and Actions -->
            <div class="review-container">
                <div class="section-header">
                    <h3 class="mb-0"><i class="fas fa-comments"></i> Review Notes and Actions</h3>
                </div>
                
                <div class="section-content">
                    <div class="mb-3">
                        <label class="form-label"><strong>Review Notes:</strong></label>
                        <textarea name="review_notes" class="form-control" rows="4" 
                                  <?php echo $is_locked ? 'readonly' : ''; ?>><?php echo htmlspecialchars($review['review_notes'] ?? ''); ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-body text-center">
                                    <h5>Department Total</h5>
                                    <h3 class="text-primary"><?php echo number_format($dept_scores['total'], 2); ?> / 725</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-body text-center">
                                    <h5>Expert Total</h5>
                                    <h3 class="text-success" id="expertTotalDisplay">
                                        <?php echo $review && $review['expert_total_score'] !== null ? number_format($review['expert_total_score'], 2) : '0.00'; ?> / 725
                                    </h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-body text-center">
                                    <h5>Difference</h5>
                                    <h3 class="text-info" id="differenceDisplay">
                                        <?php 
                                        if ($review && $review['expert_total_score'] !== null) {
                                            $diff = $review['expert_total_score'] - $dept_scores['total'];
                                            echo ($diff >= 0 ? '+' : '') . number_format($diff, 2);
                                        } else {
                                            echo '0.00';
                                        }
                                        ?>
                                    </h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!$is_locked): ?>
                    <div class="mt-4 d-flex gap-2 justify-content-end">
                        <button type="submit" class="btn btn-primary" onclick="document.getElementById('actionField').value='save';">
                            <i class="fas fa-save"></i> Save Review
                        </button>
                        <button type="submit" class="btn btn-success" onclick="document.getElementById('actionField').value='complete';">
                            <i class="fas fa-check"></i> Mark as Completed
                        </button>
                        <button type="submit" class="btn btn-warning" onclick="if(confirm('Are you sure you want to lock this review? You will not be able to make further changes.')) { document.getElementById('actionField').value='lock'; } else { return false; }">
                            <i class="fas fa-lock"></i> Lock Review
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-calculate expert total
        function updateTotals() {
            const scores = [1, 2, 3, 4, 5];
            let total = 0;
            scores.forEach(i => {
                const input = document.querySelector(`input[name="expert_score_${i}"]`);
                if (input && input.value) {
                    total += parseFloat(input.value) || 0;
                }
            });
            
            document.getElementById('expertTotalDisplay').textContent = total.toFixed(2) + ' / 725';
            
            const deptTotal = <?php echo $dept_scores['total']; ?>;
            const diff = total - deptTotal;
            document.getElementById('differenceDisplay').textContent = (diff >= 0 ? '+' : '') + diff.toFixed(2);
        }
        
        // Add event listeners
        document.querySelectorAll('input[type="number"][name^="expert_score"]').forEach(input => {
            input.addEventListener('input', updateTotals);
        });
    </script>
</body>
</html>

