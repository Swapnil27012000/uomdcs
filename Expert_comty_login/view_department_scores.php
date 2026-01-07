<?php
/**
 * View Department Scores - Display Calculated Marks for All Departments
 * Shows auto-calculated scores based on existing data
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require('session.php');
require('expert_functions.php');

$academic_year = getAcademicYear();

// Get all departments with their scores
$query = "SELECT 
    ds.dept_id,
    COALESCE(dn.collname, dm.DEPT_NAME, CONCAT('Department ', ds.dept_id)) AS dept_name,
    dm.DEPT_COLL_NO AS dept_code,
    dp.category,
    ds.academic_year,
    ds.section_1_score,
    ds.section_2_score,
    ds.section_3_score,
    ds.section_4_score,
    ds.section_5_score,
    ds.total_score,
    ds.last_calculated_at
FROM department_scores ds
LEFT JOIN department_master dm ON dm.DEPT_ID = ds.dept_id
LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
LEFT JOIN department_profiles dp ON dp.A_YEAR = ds.academic_year 
    AND CAST(dp.dept_id AS UNSIGNED) IN (dm.DEPT_ID, dm.DEPT_COLL_NO)
WHERE ds.academic_year = ?
ORDER BY ds.total_score DESC";

$stmt = $conn->prepare($query);
$departments = [];
if ($stmt) {
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
    $stmt->close();
}

// Get statistics
$total_depts = count($departments);
$avg_score = $total_depts > 0 ? array_sum(array_column($departments, 'total_score')) / $total_depts : 0;
$max_score = $total_depts > 0 ? max(array_column($departments, 'total_score')) : 0;
$min_score = $total_depts > 0 ? min(array_column($departments, 'total_score')) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Scores - UDRF Ranking</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        
        .main-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 1rem;
        }
        
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .scores-table {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .table thead th {
            border: none;
            padding: 1rem;
            font-weight: 600;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .rank-badge {
            display: inline-block;
            width: 40px;
            height: 40px;
            line-height: 40px;
            border-radius: 50%;
            text-align: center;
            font-weight: 700;
            color: white;
        }
        
        .rank-1 { background: linear-gradient(135deg, #FFD700, #FFA500); }
        .rank-2 { background: linear-gradient(135deg, #C0C0C0, #A9A9A9); }
        .rank-3 { background: linear-gradient(135deg, #CD7F32, #B8860B); }
        .rank-other { background: linear-gradient(135deg, #667eea, #764ba2); }
        
        .score-cell {
            font-weight: 600;
            text-align: center;
        }
        
        .total-score {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .progress {
            height: 8px;
            margin-top: 0.5rem;
        }
        
        .category-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .btn-action {
            padding: 0.5rem 1rem;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-recalculate {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }
        
        .btn-recalculate:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }
    </style>
</head>
<body>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="mb-2"><i class="fas fa-trophy"></i> Department UDRF Scores</h1>
                <p class="text-muted mb-0">Auto-calculated marks based on existing data | Academic Year: <?php echo $academic_year; ?></p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-primary me-2">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <button class="btn-action btn-recalculate" onclick="recalculateScores()">
                    <i class="fas fa-sync-alt"></i> Recalculate All
                </button>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $total_depts; ?></div>
            <div class="stat-label">Total Departments</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($avg_score, 2); ?></div>
            <div class="stat-label">Average Score</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($max_score, 2); ?></div>
            <div class="stat-label">Highest Score</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($min_score, 2); ?></div>
            <div class="stat-label">Lowest Score</div>
        </div>
    </div>

    <!-- Scores Table -->
    <div class="scores-table">
        <h3 class="mb-4"><i class="fas fa-list-ol"></i> Department Rankings</h3>
        
        <?php if (empty($departments)): ?>
            <div class="alert alert-warning">
                <h4><i class="fas fa-exclamation-triangle"></i> No Scores Found</h4>
                <p>Department scores have not been calculated yet. Please run the calculation query first:</p>
                <code>SQL/calculate_department_scores.sql</code>
                <p class="mt-2 mb-0">Or click the "Recalculate All" button above.</p>
            </div>
        <?php else: ?>
        
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Department</th>
                        <th>Category</th>
                        <th class="text-center">Section I<br><small>(300)</small></th>
                        <th class="text-center">Section II<br><small>(100)</small></th>
                        <th class="text-center">Section III<br><small>(110)</small></th>
                        <th class="text-center">Section IV<br><small>(140)</small></th>
                        <th class="text-center">Section V<br><small>(75)</small></th>
                        <th class="text-center">Total<br><small>(725)</small></th>
                        <th class="text-center">Performance</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    foreach ($departments as $dept): 
                        $rank_class = '';
                        if ($rank == 1) $rank_class = 'rank-1';
                        else if ($rank == 2) $rank_class = 'rank-2';
                        else if ($rank == 3) $rank_class = 'rank-3';
                        else $rank_class = 'rank-other';
                        
                        $percentage = ($dept['total_score'] / 725) * 100;
                    ?>
                    <tr>
                        <td>
                            <span class="rank-badge <?php echo $rank_class; ?>">
                                <?php echo $rank; ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($dept['dept_name']); ?></strong>
                            <br><small class="text-muted">Code: <?php echo $dept['dept_code']; ?></small>
                        </td>
                        <td>
                            <?php if ($dept['category']): ?>
                                <span class="category-badge"><?php echo htmlspecialchars($dept['category']); ?></span>
                            <?php else: ?>
                                <span class="text-muted">Not Set</span>
                            <?php endif; ?>
                        </td>
                        <td class="score-cell"><?php echo number_format($dept['section_1_score'], 2); ?></td>
                        <td class="score-cell"><?php echo number_format($dept['section_2_score'], 2); ?></td>
                        <td class="score-cell"><?php echo number_format($dept['section_3_score'], 2); ?></td>
                        <td class="score-cell"><?php echo number_format($dept['section_4_score'], 2); ?></td>
                        <td class="score-cell"><?php echo number_format($dept['section_5_score'], 2); ?></td>
                        <td class="score-cell total-score"><?php echo number_format($dept['total_score'], 2); ?></td>
                        <td>
                            <div class="progress">
                                <div class="progress-bar bg-success" role="progressbar" 
                                     style="width: <?php echo $percentage; ?>%" 
                                     aria-valuenow="<?php echo $percentage; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                </div>
                            </div>
                            <small class="text-muted"><?php echo number_format($percentage, 1); ?>%</small>
                        </td>
                        <td class="text-center">
                            <a href="review_complete.php?dept_id=<?php echo $dept['dept_id']; ?>" 
                               class="btn btn-sm btn-primary">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </td>
                    </tr>
                    <?php 
                    $rank++;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>
        
        <?php endif; ?>
    </div>

    <!-- Information Box -->
    <div class="alert alert-info mt-4">
        <h5><i class="fas fa-info-circle"></i> About These Scores</h5>
        <p><strong>Auto-Calculated Scores:</strong> These scores are automatically calculated based on existing department data in the database. The calculation multiplies counts by their respective marks as per UDRF guidelines.</p>
        <p><strong>Sections Breakdown:</strong></p>
        <ul class="mb-0">
            <li><strong>Section I (300 marks):</strong> Faculty Output, Research & Professional Activities</li>
            <li><strong>Section II (100 marks):</strong> NEP Initiatives, Teaching, Learning & Assessment</li>
            <li><strong>Section III (110 marks):</strong> Departmental Governance & Practices</li>
            <li><strong>Section IV (140 marks):</strong> Student Support, Achievements & Progression</li>
            <li><strong>Section V (75 marks):</strong> Conferences, Workshops & Collaborations</li>
        </ul>
        <p class="mt-2 mb-0"><strong>Note:</strong> Some scores are expert-evaluated and not auto-calculated. The actual final scores will be determined after expert verification.</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function recalculateScores() {
    if (confirm('This will recalculate scores for all departments based on current data. Continue?')) {
        alert('Recalculation feature will trigger the SQL query. For now, please run SQL/calculate_department_scores.sql manually.');
        // In production, this would trigger an AJAX call to run the calculation
    }
}
</script>

</body>
</html>

