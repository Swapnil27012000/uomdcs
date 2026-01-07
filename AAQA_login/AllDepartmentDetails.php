<?php
/**
 * AAQA - All Department Details with Progress Tracking
 */

require('session.php');
require('functions.php');

error_reporting(E_ALL);
ini_set('display_errors', 0);

// Load CSRF utilities
if (file_exists(__DIR__ . '/../dept_login/csrf.php')) {
    require_once __DIR__ . '/../dept_login/csrf.php';
    if (function_exists('csrf_token')) {
        $csrf_token = csrf_token();
    }
} elseif (file_exists(__DIR__ . '/../csrf.php')) {
    require_once __DIR__ . '/../csrf.php';
    if (function_exists('csrf_token')) {
        $csrf_token = csrf_token();
    }
}

$academic_year = getAcademicYear();
$departments = getAllDepartmentsWithProgress($academic_year);

// Get selected department if provided
$selected_dept_id = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : null;
$selected_dept = null;
if ($selected_dept_id) {
    foreach ($departments as $dept) {
        if ($dept['DEPT_ID'] == $selected_dept_id) {
            $selected_dept = $dept;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (isset($csrf_token)): ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <title>All Departments - AAQA Dashboard</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --accent-red: #ef4444;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .top-bar {
            background: var(--primary-blue);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .top-bar-content {
            max-width: 1600px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .container-main {
            max-width: 1600px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        .progress-bar-container {
            min-width: 150px;
        }
        
        .progress {
            height: 24px;
            border-radius: 12px;
            background: #e5e7eb;
            overflow: hidden;
            position: relative;
        }
        
        .progress-bar {
            height: 100%;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .progress-bar.bg-success {
            background: var(--accent-green) !important;
        }
        
        .progress-bar.bg-warning {
            background: var(--accent-amber) !important;
        }
        
        .progress-bar.bg-danger {
            background: var(--accent-red) !important;
        }
        
        .progress-text {
            position: absolute;
            width: 100%;
            text-align: center;
            color: #1f2937;
            font-size: 0.75rem;
            font-weight: 600;
            z-index: 1;
        }
        
        .btn-action {
            padding: 0.4rem 1rem;
            border-radius: 6px;
            border: none;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-view {
            background: var(--primary-blue);
            color: white;
        }
        
        .btn-view:hover {
            background: #1e40af;
            color: white;
        }
        
        .btn-send {
            background: var(--accent-green);
            color: white;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        
        .btn-resend {
            background: var(--accent-amber);
            color: white;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        
        table.dataTable {
            border-collapse: separate;
            border-spacing: 0;
        }
        
        table.dataTable thead th {
            background: #f8fafc;
            color: var(--primary-blue);
            font-weight: 600;
            border-bottom: 2px solid #e5e7eb;
            padding: 1rem;
        }
        
        table.dataTable tbody td {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }
        
        table.dataTable tbody tr:hover {
            background: #f8fafc;
        }
        
        .badge-category {
            background: #e0e7ff;
            color: var(--primary-blue);
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .download-btn {
            background: var(--primary-blue);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .download-btn:hover {
            background: #1e40af;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .back-link {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .alert {
            border-radius: 8px;
            border: none;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="top-bar-content">
            <h1><i class="fas fa-list me-2"></i>All Departments</h1>
            <div class="d-flex align-items-center gap-3">
                <a href="AddDepartmentDetails.php" class="btn btn-sm btn-success">
                    <i class="fas fa-plus-circle me-1"></i>Add Department
                </a>
                <span><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'AAQA User'); ?></span>
                <a href="../logout.php" class="btn btn-sm btn-light">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>
    </div>
    
    <div class="container-main">
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i>Back to Dashboard
        </a>
        
        <h2 class="page-title">All Departments</h2>
        <p class="text-muted mb-4">Academic Year: <strong><?php echo htmlspecialchars($academic_year); ?></strong></p>
        
        <div class="table-container">
            <div id="responseMessage" class="alert" style="display: none;"></div>
            
            <button id="downloadExcel" class="download-btn">
                <i class="fas fa-file-excel me-2"></i>Download as Excel
            </button>
            
            <?php if (empty($departments)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No departments found.
                </div>
            <?php else: ?>
                <table id="departmentsTable" class="table table-hover">
                    <thead>
                        <tr>
                            <th>Dept Code</th>
                            <th>Department Name</th>
                            <th>Category</th>
                            <th>Email</th>
                            <th>HOD Email</th>
                            <th>Progress</th>
                            <th>Completed Forms</th>
                            <th>Password</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach ($departments as $dept): 
                            $progress = isset($dept['progress']) ? $dept['progress']['progress_percentage'] : 0;
                            $completed = isset($dept['progress']) ? $dept['progress']['completed_forms'] : 0;
                            $total = isset($dept['progress']) ? $dept['progress']['total_forms'] : 0;
                            
                            // Get password from database
                            $dept_id = $dept['DEPT_ID'];
                            $password_query = "SELECT PASS_WORD, SEND_CRED FROM department_master WHERE DEPT_ID = ?";
                            $pwd_stmt = $conn->prepare($password_query);
                            $dept_password = '-';
                            $send_cred = 0;
                            if ($pwd_stmt) {
                                $pwd_stmt->bind_param("i", $dept_id);
                                $pwd_stmt->execute();
                                $pwd_result = $pwd_stmt->get_result();
                                if ($pwd_row = $pwd_result->fetch_assoc()) {
                                    $dept_password = htmlspecialchars($pwd_row['PASS_WORD'] ?? '-');
                                    $send_cred = (int)($pwd_row['SEND_CRED'] ?? 0);
                                }
                                mysqli_free_result($pwd_result);
                                $pwd_stmt->close();
                            }
                            
                            // Get HOD Email
                            $hod_email_query = "SELECT HOD_EMAIL FROM department_master WHERE DEPT_ID = ?";
                            $hod_stmt = $conn->prepare($hod_email_query);
                            $hod_email = '-';
                            if ($hod_stmt) {
                                $hod_stmt->bind_param("i", $dept_id);
                                $hod_stmt->execute();
                                $hod_result = $hod_stmt->get_result();
                                if ($hod_row = $hod_result->fetch_assoc()) {
                                    $hod_email = htmlspecialchars($hod_row['HOD_EMAIL'] ?? '-');
                                }
                                mysqli_free_result($hod_result);
                                $hod_stmt->close();
                            }
                            
                            // Determine progress bar color
                            $progress_class = 'bg-success';
                            if ($progress < 50) {
                                $progress_class = 'bg-danger';
                            } elseif ($progress < 100) {
                                $progress_class = 'bg-warning';
                            }
                            
                            $btnClass = $send_cred === 1 ? 'btn-resend' : 'btn-send';
                            $btnText = $send_cred === 1 ? 'Re-send' : 'Send';
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($dept['DEPT_CODE'] ?? '-'); ?></strong></td>
                                <td><?php echo htmlspecialchars($dept['DEPT_NAME'] ?? '-'); ?></td>
                                <td><span class="badge-category"><?php echo htmlspecialchars($dept['CATEGORY'] ?? 'Uncategorized'); ?></span></td>
                                <td><?php echo htmlspecialchars($dept['EMAIL'] ?? '-'); ?></td>
                                <td><?php echo $hod_email; ?></td>
                                <td>
                                    <div class="progress-bar-container">
                                        <div class="progress">
                                            <div class="progress-bar <?php echo $progress_class; ?>" style="width: <?php echo $progress; ?>%"></div>
                                            <div class="progress-text"><?php echo $progress; ?>%</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <small><?php echo $completed; ?> / <?php echo $total; ?> forms</small>
                                </td>
                                <td><code><?php echo $dept_password; ?></code></td>
                                <td>
                                    <button type="button" class="btn <?php echo $btnClass; ?> send-mail-btn" data-id="<?php echo $dept_id; ?>">
                                        <i class="fas fa-envelope me-1"></i><?php echo $btnText; ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        $(document).ready(function() {
            var table = $('#departmentsTable').DataTable({
                responsive: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[5, 'desc']], // Sort by progress descending
                columnDefs: [
                    { orderable: false, targets: [8] } // Disable sorting on Actions column
                ]
            });
            
            // Download Excel function
            $('#downloadExcel').on('click', function() {
                var wb = XLSX.utils.book_new();
                var ws = XLSX.utils.table_to_sheet(document.getElementById('departmentsTable'));
                XLSX.utils.book_append_sheet(wb, ws, "Departments");
                XLSX.writeFile(wb, "Department_List_" + new Date().getTime() + ".xlsx");
            });
            
            // Send Mail Button logic
            $('#departmentsTable tbody').on('click', '.send-mail-btn', function() {
                var button = $(this);
                var deptId = button.data('id');
                var responseBox = $('#responseMessage');
                
                if (!deptId) {
                    responseBox.removeClass().addClass("alert alert-danger").text("Invalid Department ID").fadeIn();
                    return;
                }
                
                button.html('<i class="fas fa-spinner fa-spin me-1"></i>Sending...').prop('disabled', true);
                
                // Get CSRF token
                var csrfToken = $('meta[name="csrf-token"]').attr('content') || 
                                $('input[name="csrf_token"]').val() || '';
                
                $.ajax({
                    url: 'sendmail.php',
                    type: 'POST',
                    data: { 
                        deptId: deptId,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    success: function(data) {
                        if (data && data.status === "success") {
                            responseBox.removeClass().addClass("alert alert-success").html('<i class="fas fa-check-circle me-2"></i>' + data.message).fadeIn();
                            button.removeClass('btn-send').addClass('btn-resend').html('<i class="fas fa-envelope me-1"></i>Re-send');
                        } else {
                            var msg = (data && data.message) ? data.message : 'Something went wrong.';
                            responseBox.removeClass().addClass("alert alert-danger").html('<i class="fas fa-exclamation-circle me-2"></i>' + msg).fadeIn();
                            button.html('<i class="fas fa-envelope me-1"></i>Send');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Send Mail Error:", status, error);
                        console.error("Response:", xhr.responseText);
                        var errorMsg = 'Server error. Please try again.';
                        if (xhr.responseText) {
                            try {
                                var errorData = JSON.parse(xhr.responseText);
                                if (errorData.message) {
                                    errorMsg = errorData.message;
                                }
                            } catch (e) {
                                // If response is HTML (redirect), show generic message
                                if (xhr.responseText.includes('top.location') || xhr.responseText.includes('dashboard')) {
                                    errorMsg = 'Session expired. Please refresh the page.';
                                } else if (xhr.responseText.trim().length > 0) {
                                    // Show first 200 chars of response if not JSON
                                    errorMsg = 'Error: ' + xhr.responseText.substring(0, 200);
                                }
                            }
                        }
                        responseBox.removeClass().addClass("alert alert-danger").html('<i class="fas fa-exclamation-circle me-2"></i>' + errorMsg).fadeIn();
                        button.html('<i class="fas fa-envelope me-1"></i>Send');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                        setTimeout(function() { responseBox.fadeOut(); }, 5000);
                    }
                });
            });
        });
    </script>
</body>
</html>

