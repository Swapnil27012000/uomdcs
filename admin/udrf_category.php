<?php
/**
 * Admin UDRF Data - Step 2: Departments List
 * Copied from Chairman_login/category.php
 */

require('session.php');
require('udrf_functions.php');

// Load common functions for getAcademicYear()
if (file_exists(__DIR__ . '/../common_functions.php')) {
    require_once(__DIR__ . '/../common_functions.php');
} elseif (file_exists(__DIR__ . '/../common_progress_functions.php')) {
    require_once(__DIR__ . '/../common_progress_functions.php');
}

// CRITICAL: Validate and sanitize input (Security Guide Section 5)
$cat_id = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
$category_name_raw = isset($_GET['name']) ? trim($_GET['name']) : '';
$category_name = !empty($category_name_raw) ? htmlspecialchars(urldecode($category_name_raw), ENT_QUOTES, 'UTF-8') : '';

if (!$cat_id || !$category_name) {
    error_log("Invalid category parameters in udrf_category.php: cat_id=$cat_id, name=" . ($category_name_raw ?? 'empty'));
    header('Location: udrf_dashboard.php');
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
    <title>Departments - <?php echo htmlspecialchars($category_name); ?> - Admin UDRF</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/styles.css" />
    <style>
        .btn-print {
            background: #10b981;
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
        
        /* Ensure table headers are visible with blue background */
        #category-table thead th {
            background-color: #1976d2 !important;
            color: white !important;
        }
        
        #category-table thead {
            background-color: #1976d2 !important;
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
                    <a href="udrf_dashboard.php" class="btn btn-sm btn-primary mb-2">
                        <i class="fas fa-arrow-left"></i> Back to Categories
                    </a>
                </h4>
                <h4 class="mt-2"><?php echo htmlspecialchars($category_name); ?></h4>
                <p class="text-muted">Academic Year: <strong><?php echo htmlspecialchars($academic_year); ?></strong></p>
        
        <div class="card mt-4">
            <div class="card-body">
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
                <thead class="table-dark">
                    <tr>
                        <th style="color: white !important; font-weight: 600; background-color: #1976d2 !important;">Dept Name</th>
                        <th style="color: white !important; font-weight: 600; background-color: #1976d2 !important;">Dept Auto Score / 725</th>
                        <th style="color: white !important; font-weight: 600; background-color: #1976d2 !important;">Expert Score / 725</th>
                        <th style="color: white !important; font-weight: 600; background-color: #1976d2 !important;">Action</th>
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
                                    <a href="udrf_department.php?dept_id=<?php echo (int)$dept_id; ?>&cat_id=<?php echo (int)$cat_id; ?>&name=<?php echo urlencode($category_name); ?>" 
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
    <!-- Footer -->
    <?php include '../footer_main.php'; ?>
</body>
</html>

