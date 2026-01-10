<?php
/**
 * Admin UDRF Data - Landing Page
 * Step 1: Pick Category
 * Copied from Chairman_login/dashboard.php
 */

require('session.php');
require('udrf_functions.php');

// Load common functions for getAcademicYear()
if (file_exists(__DIR__ . '/../common_functions.php')) {
    require_once(__DIR__ . '/../common_functions.php');
} elseif (file_exists(__DIR__ . '/../common_progress_functions.php')) {
    require_once(__DIR__ . '/../common_progress_functions.php');
}

$academic_year = getAcademicYear();
$categories = getAllCategoriesWithCounts($academic_year);
$overall_ranking = getAllDepartmentsOverallRanking($academic_year);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UDRF Data - University of Mumbai</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/styles.css" />
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --bg-light: #f8fafc;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .category-card {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 2rem;
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .category-card:hover {
            border-color: var(--primary-blue);
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.15);
            transform: translateY(-2px);
        }
        
        .category-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            background: var(--primary-blue);
            color: white;
            border-radius: 10px;
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .category-name {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 1rem;
            line-height: 1.4;
        }
        
        .category-stats {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #6b7280;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }
        
        .category-count {
            font-weight: 600;
            color: var(--primary-blue);
        }
        
        .btn-view {
            width: 100%;
            background: var(--primary-blue);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-view:hover {
            background: #1e40af;
            transform: translateY(-1px);
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
        
        .overall-ranking-table {
            font-size: 0.95rem;
        }
        
        .overall-ranking-table th,
        .overall-ranking-table td {
            font-weight: 600;
            color: #1f2937 !important;
            padding: 0.75rem;
        }
        
        .overall-ranking-table thead th {
            color: white !important;
            font-weight: 700;
            font-size: 0.875rem;
            background-color: #1976d2 !important;
        }
        
        .overall-ranking-table thead {
            background-color: #1976d2 !important;
        }
        
        .overall-ranking-table tbody td {
            color: #1f2937 !important;
            font-weight: 500;
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
            
            .top-bar, .btn-print, header, .categories-grid, .no-print {
                display: none !important;
            }
            
            .container-main {
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
            .categories-grid {
                grid-template-columns: 1fr;
            }
            
            .container-main {
                padding: 0 1rem;
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
                <h4 class="mt-4">UDRF Data</h4>
                <p class="text-muted">Academic Year: <strong><?php echo htmlspecialchars($academic_year); ?></strong></p>
                <p class="text-muted no-print">Select a category to view departments and their review status</p>
        
        <!-- Categories Grid -->
        <div class="card mt-4">
            <div class="card-body">
                <div class="categories-grid">
            <?php foreach ($categories as $category): ?>
                <div class="category-card" onclick="window.location.href='udrf_category.php?cat_id=<?php echo (int)$category['id']; ?>&name=<?php echo urlencode($category['name']); ?>'">
                    <div class="category-number"><?php echo (int)$category['id']; ?></div>
                    <div class="category-name" style="color: #1e3a8a; font-weight: 600;"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="category-stats">
                        <i class="fas fa-building"></i>
                        <span><span class="category-count"><?php echo (int)$category['count']; ?></span> Departments</span>
                    </div>
                    <button class="btn-view" type="button">
                        <i class="fas fa-arrow-right"></i> View Departments
                    </button>
                </div>
            <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Overall Ranking Section - After Categories -->
        <?php if (!empty($overall_ranking)): ?>
        <div class="card mt-4">
            <div class="card-body">
            <!-- Print Header (hidden on screen, shown in print) -->
            <div class="print-header" style="display: none;">
                <img src="../assets/img/mumbai-university-removebg-preview.png" alt="University of Mumbai Logo">
                <h1>University of Mumbai</h1>
                <p><strong>Overall Department Ranking (All Categories)</strong></p>
                <p>Academic Year: <?php echo htmlspecialchars($academic_year); ?></p>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--primary-blue); margin-bottom: 0;">
                    <i class="fas fa-trophy"></i> Overall Department Ranking (All Categories)
                </h2>
                <button class="btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Print All Rankings
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover overall-ranking-table" style="margin-bottom: 0;" id="overall-ranking-table">
                    <thead class="table-dark">
                        <tr>
                            <th style="color: white !important; font-weight: 600; background-color: #1976d2 !important;">Overall Rank</th>
                            <th style="color: white !important; font-weight: 600; background-color: #1976d2 !important;">Dept Code</th>
                            <th style="color: white !important; font-weight: 600; background-color: #1976d2 !important;">Department Name</th>
                            <th style="color: white !important; font-weight: 600; background-color: #1976d2 !important;">Category</th>
                            <th style="color: white !important; font-weight: 600; background-color: #1976d2 !important;">Dept Auto Score / 725</th>
                            <th style="color: white !important; font-weight: 600; background-color: #1976d2 !important;">Expert Score / 725</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Safety: Check if overall_ranking is an array before slicing
                        $display_ranking = is_array($overall_ranking) ? array_slice($overall_ranking, 0, 20) : [];
                        foreach ($display_ranking as $dept): 
                            // Safety: Skip invalid entries
                            if (!is_array($dept)) {
                                continue;
                            }
                            $rank = (int)($dept['overall_rank'] ?? 0);
                            $dept_code = htmlspecialchars($dept['DEPT_CODE'] ?? '-', ENT_QUOTES, 'UTF-8');
                            $dept_name = htmlspecialchars($dept['DEPT_NAME'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
                            $category = htmlspecialchars($dept['CATEGORY'] ?? '-', ENT_QUOTES, 'UTF-8');
                            // CRITICAL: expert_total_score is the consolidated score (includes all section corrections)
                            // Ranking is based on expert_total_score, not dept_auto_score
                            $dept_score = (float)($dept['dept_auto_score'] ?? $dept['total_score'] ?? 0);
                            $expert_score = isset($dept['expert_total_score']) ? (float)$dept['expert_total_score'] : null;
                        ?>
                            <tr>
                                <td>
                                    <span class="rank-badge <?php echo $rank <= 3 ? 'top-3' : ''; ?>" style="display: inline-flex; align-items: center; justify-content: center; min-width: 40px; height: 32px; background: <?php echo $rank <= 3 ? 'var(--accent-green)' : 'var(--primary-blue)'; ?>; color: white; border-radius: 6px; font-weight: 700; font-size: 0.875rem; padding: 0 0.75rem;">
                                        #<?php echo $rank; ?>
                                    </span>
                                    <small class="text-muted d-block" style="font-size: 0.7rem; margin-top: 0.25rem;">
                                        (Expert)
                                    </small>
                                </td>
                                <td><strong><?php echo $dept_code; ?></strong></td>
                                <td style="font-weight: 600; color: var(--primary-blue);"><?php echo $dept_name; ?></td>
                                <td><span style="background: #e5e7eb; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.875rem;"><?php echo $category; ?></span></td>
                                <td>
                                    <span style="font-size: 1rem; font-weight: 600; color: #6b7280;">
                                        <?php echo number_format($dept_score, 2); ?>
                                    </span>
                                    <span style="color: #9ca3af;">/ 725</span>
                                </td>
                                <td>
                                    <?php if ($expert_score !== null): ?>
                                        <span style="font-size: 1.125rem; font-weight: 700; color: var(--accent-green);">
                                            <?php echo number_format($expert_score, 2); ?>
                                        </span>
                                        <span style="color: #9ca3af;">/ 725</span>
                                    <?php else: ?>
                                        <span style="color: #9ca3af; font-style: italic; font-size: 0.875rem;">Not reviewed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($overall_ranking) > 20): ?>
                <p class="text-muted mt-3" style="text-align: center; font-size: 0.875rem;">
                    Showing top 20 departments. Total: <?php echo count($overall_ranking); ?> departments
                </p>
            <?php endif; ?>
            
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
        <?php endif; ?>
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
                const sigSection = document.querySelector('.print-signature');
                if (header) header.style.display = 'block';
                if (sigSection) sigSection.style.display = 'block';
            } catch (e) {
                console.error('Error showing print elements:', e);
            }
        });
        window.addEventListener('afterprint', function() {
            try {
                const header = document.querySelector('.print-header');
                const sigSection = document.querySelector('.print-signature');
                if (header) header.style.display = 'none';
                if (sigSection) sigSection.style.display = 'none';
            } catch (e) {
                console.error('Error hiding print elements:', e);
            }
        });
    </script>
    <!-- Footer -->
    <?php include '../footer_main.php'; ?>
</body>
</html>

