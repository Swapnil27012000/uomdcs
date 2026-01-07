<?php
/**
 * Chairman Review Console - Step 2: Departments List
 */

require('session.php');
require('functions.php');

$cat_id = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
$category_name = isset($_GET['name']) ? urldecode($_GET['name']) : '';

if (!$cat_id || !$category_name) {
    header('Location: dashboard.php');
    exit;
}

$academic_year = getAcademicYear();
$departments = getDepartmentsWithScores($category_name, $academic_year);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments - <?php echo htmlspecialchars($category_name); ?> - Chairman Console</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .top-bar {
            background: white;
            color: #1f2937;
            padding: 1rem 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .top-bar-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .container-main {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
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
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .btn-back {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            color: #374151;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .btn-back:hover {
            background: #e5e7eb;
        }
        
        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: white;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        tbody tr {
            border-bottom: 1px solid #e5e7eb;
            transition: background 0.2s;
        }
        
        tbody tr:hover {
            background: #f9fafb;
        }
        
        tbody tr:nth-child(even) {
            background: #fafbfc;
        }
        
        tbody tr:nth-child(even):hover {
            background: #f3f4f6;
        }
        
        td {
            padding: 1rem;
            color: #1f2937;
        }
        
        .dept-name {
            font-weight: 600;
            color: var(--primary-blue);
        }
        
        .score {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--accent-green);
        }
        
        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 32px;
            background: var(--primary-blue);
            color: white;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.875rem;
            padding: 0 0.75rem;
        }
        
        .rank-badge.top-3 {
            background: var(--accent-green);
        }
        
        .btn-view {
            background: var(--primary-blue);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-view:hover {
            background: #1e40af;
        }
        
        .btn-print {
            background: var(--accent-green);
            color: white;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 1rem;
        }
        
        .btn-print:hover {
            background: #059669;
        }
        
        @media print {
            @page {
                margin: 1cm;
                size: A4;
            }
            
            body {
                background: white;
                font-size: 12pt;
            }
            
            .top-bar, .btn-back, .btn-print, .btn-view, header {
                display: none !important;
            }
            
            .dashboard-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
            }
            
            .print-header {
                text-align: center;
                margin-bottom: 3rem;
                padding-bottom: 1.5rem;
                page-break-after: avoid;
            }
            
            .print-header img {
                height: 60px;
                margin-bottom: 1rem;
            }
            
            .print-header h1 {
                font-size: 18pt;
                font-weight: 700;
                color: #1e3a8a;
                margin: 0.5rem 0;
            }
            
            .print-header p {
                font-size: 11pt;
                color: #1e3a8a;
                margin: 0.25rem 0;
            }
            
            .table-responsive {
                margin-top: 2rem;
                margin-bottom: 2rem;
            }
            
            table {
                page-break-inside: auto;
                width: 100%;
                border-collapse: collapse;
                margin-top: 1.5rem;
            }
            
            thead {
                display: table-header-group;
            }
            
            tbody {
                display: table-row-group;
            }
            
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            th, td {
                padding: 0.5rem;
                border: 1px solid #ddd;
            }
            
            th {
                background: #1e3a8a !important;
                color: white !important;
                font-weight: 700;
            }
            
            .print-signature {
                margin-top: 5rem;
                page-break-inside: avoid;
                width: 100%;
            }
            
            .print-signature-container {
                display: flex;
                justify-content: flex-end;
                margin-top: 4rem;
                padding-top: 3rem;
                padding-right: 2rem;
            }
            
            .print-signature-box {
                width: 320px;
                text-align: center;
                padding: 1.5rem 2rem;
            }
            
            .print-signature-line {
                border-top: 2px solid #000;
                width: 100%;
                margin: 0 auto;
                padding-top: 1rem;
                font-weight: 600;
                font-size: 11pt;
                margin-bottom: 2.5rem;
                min-height: 50px;
            }
            
            .print-signature-line:last-child {
                margin-bottom: 0;
            }
            
            /* Remove date/time and URL from print */
            @page {
                @top-right {
                    content: "";
                }
                @bottom-center {
                    content: "";
                }
                @bottom-right {
                    content: "";
                }
            }
        }
        
        @media (max-width: 768px) {
            .table-container {
                overflow-x: auto;
            }
            
            table {
                min-width: 600px;
            }
        }
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
                        <h1 class="h4 mb-0 text-gray-800">Chairman Review Console</h1>
                        <small class="text-muted">Review All Department Data</small>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="text-muted">Welcome, Chairman</span>
                    <a href="../logout.php" class="btn btn-danger btn-sm">Logout</a>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Main Content -->
    <div class="container-main">
        <div class="page-header">
            <a href="dashboard.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Categories
            </a>
            <h1 style="font-size: 1.75rem; font-weight: 700; color: #1e3a8a; margin-bottom: 0.5rem;">
                <?php echo htmlspecialchars($category_name); ?>
            </h1>
            <p style="color: #1e3a8a; font-weight: 600; font-size: 1rem;">Academic Year: <strong><?php echo htmlspecialchars($academic_year); ?></strong></p>
        </div>
        
        <div class="dashboard-container">
            <!-- Print Header (hidden on screen, shown in print) -->
            <div class="print-header" style="display: none;">
                <img src="../assets/img/mumbai-university-removebg-preview.png" alt="University of Mumbai Logo">
                <h1>University of Mumbai</h1>
                <p><strong>Department Rankings - <?php echo htmlspecialchars($category_name); ?></strong></p>
                <p>Academic Year: <?php echo htmlspecialchars($academic_year); ?></p>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="mb-0">Department Rankings</h3>
                <button class="btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover" id="category-table">
                <thead>
                    <tr>
                        <th>Dept Name</th>
                        <th>Dept Auto Score / 725</th>
                        <th>Expert Score / 725</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($departments)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 3rem; color: #6b7280;">
                                <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 1rem; display: block; opacity: 0.5;"></i>
                                No departments found in this category
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($departments as $dept): ?>
                            <?php 
                            // Safety: Skip invalid entries
                            if (!is_array($dept) || empty($dept['DEPT_ID'])) {
                                continue;
                            }
                            $dept_id = (int)($dept['DEPT_ID'] ?? 0);
                            $dept_name = htmlspecialchars($dept['DEPT_NAME'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
                            // CRITICAL: expert_total_score is the consolidated score (includes all section corrections)
                            // Ranking is based on expert_total_score, not dept_auto_score
                            $dept_score = (float)($dept['dept_auto_score'] ?? $dept['total_score'] ?? 0);
                            $expert_score = isset($dept['expert_total_score']) ? (float)$dept['expert_total_score'] : null;
                            ?>
                            <tr>
                                <td class="dept-name"><?php echo $dept_name; ?></td>
                                <td>
                                    <span class="score" style="color: #6b7280;">
                                        <?php echo number_format($dept_score, 2); ?>
                                    </span>
                                    <span style="color: #9ca3af;">/ 725</span>
                                </td>
                                <td>
                                    <?php if ($expert_score !== null): ?>
                                        <span class="score" style="color: var(--accent-green); font-weight: 600;">
                                            <?php echo number_format($expert_score, 2); ?>
                                        </span>
                                        <span style="color: #9ca3af;">/ 725</span>
                                    <?php else: ?>
                                        <span style="color: #9ca3af; font-style: italic;">Not reviewed</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="department.php?dept_id=<?php echo $dept_id; ?>&cat_id=<?php echo (int)$cat_id; ?>&name=<?php echo urlencode($category_name); ?>" 
                                       class="btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            
            <!-- Print Signature Section (professional right-aligned) -->
            <div class="print-signature" style="display: none;">
                <div class="print-signature-container">
                    <div class="print-signature-box">
                        <div class="print-signature-line">Signature</div>
                        <div class="print-signature-line" style="margin-bottom: 0;">Date</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show header and signature section when printing
        // Safety: Check if elements exist before accessing
        window.addEventListener('beforeprint', function() {
            try {
                const header = document.querySelector('.print-header');
                const signature = document.querySelector('.print-signature');
                if (header) header.style.display = 'block';
                if (signature) signature.style.display = 'block';
            } catch (e) {
                console.error('Error showing print elements:', e);
            }
        });
        window.addEventListener('afterprint', function() {
            try {
                const header = document.querySelector('.print-header');
                const signature = document.querySelector('.print-signature');
                if (header) header.style.display = 'none';
                if (signature) signature.style.display = 'none';
            } catch (e) {
                console.error('Error hiding print elements:', e);
            }
        });
    </script>
</body>
</html>

