<?php
/**
 * AAQA - Edit Department Details
 */

require('session.php');
require_once(__DIR__ . '/../config.php');

// Load CSRF utilities
if (file_exists(__DIR__ . '/../dept_login/csrf.php')) {
    require_once __DIR__ . '/../dept_login/csrf.php';
    if (function_exists('csrf_token')) {
        csrf_token(); // Generate token if missing
    }
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (function_exists('csrf_token')): ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <title>Edit Department - AAQA Dashboard</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <style>
        :root {
            --primary-blue: #1e3a8a;
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
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .container-main {
            max-width: 1000px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 2.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .form-control {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.75rem 1rem;
        }
        
        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
        
        .select2-container {
            width: 100% !important;
        }
        
        .select2-selection {
            border: 2px solid #e5e7eb !important;
            border-radius: 8px !important;
            min-height: 48px !important;
        }
        
        .loader {
            display: none;
            position: fixed;
            z-index: 9999;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            border: 8px solid #f3f3f3;
            border-radius: 50%;
            border-top: 8px solid var(--primary-blue);
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        #updateMsg {
            margin-top: 1rem;
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
    </style>
</head>
<body>
    <div class="loader"></div>
    
    <div class="top-bar">
        <div class="top-bar-content">
            <h1><i class="fas fa-edit me-2"></i>Edit Department</h1>
            <div class="d-flex align-items-center gap-3">
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
        
        <div class="form-card">
            <h2 class="page-title">Edit Department Details</h2>
            <p class="text-muted mb-4">Select a department to view and update its details</p>
            
            <div class="mb-4">
                <label class="form-label">Select Department</label>
                <select id="department" name="department" class="form-select" style="width: 100%;" onchange="GetDetails(this.value);" required>
                    <option value="">Select Department</option>
                    <?php
                    $datapoint_query_1 = "SELECT collno, collname FROM colleges ORDER BY collname ASC";
                    $datapoint_stmt_1 = $conn->prepare($datapoint_query_1);
                    if ($datapoint_stmt_1) {
                        $datapoint_stmt_1->execute();
                        $datapoint_result_1 = $datapoint_stmt_1->get_result();
                        if ($datapoint_result_1) {
                            while ($info_1 = $datapoint_result_1->fetch_assoc()) {
                                $collno = htmlspecialchars($info_1['collno'], ENT_QUOTES, 'UTF-8');
                                $collname = htmlspecialchars($info_1['collname'], ENT_QUOTES, 'UTF-8');
                                echo '<option value="' . $collno . '">' . $collno . ' : ' . $collname . '</option>';
                            }
                            mysqli_free_result($datapoint_result_1);
                        }
                        $datapoint_stmt_1->close();
                    }
                    ?>
                </select>
            </div>
            
            <div id="department_details">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>Please select a department from the dropdown above to view and edit its details.
                </div>
            </div>
            
            <div id="updateMsg"></div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#department').select2({ placeholder: "Select Department", allowClear: true });
        });
        
        function GetDetails(val) {
            if (!val) {
                $("#department_details").html('<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>Please select a department from the dropdown above.</div>');
                return;
            }
            $.ajax({
                url: "ajaxGetDetails.php",
                method: "POST",
                data: { collno: val },
                dataType: "html",
                beforeSend: function() {
                    $(".loader").show();
                },
                success: function(resp) {
                    $("#department_details").html(resp);
                },
                error: function(xhr, status, error) {
                    $("#department_details").html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>Error loading details. Please try again.</div>');
                    console.error(error);
                },
                complete: function() {
                    $(".loader").hide();
                }
            });
        }
        
        // Get CSRF token for AJAX requests
        function getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.content || 
                   document.querySelector('input[name="csrf_token"]')?.value || '';
        }
        
        $(document).on("submit", "#updateForm", function(e) {
            e.preventDefault();
            
            // Get CSRF token
            var csrfToken = getCsrfToken();
            if (!csrfToken) {
                // Try to get from form
                csrfToken = $(this).find('input[name="csrf_token"]').val() || '';
            }
            
            var data = $(this).serialize();
            if (csrfToken && !data.includes('csrf_token')) {
                data += '&csrf_token=' + encodeURIComponent(csrfToken);
            }
            data += "&update_btn=1";
            
            $(".loader").show();
            
            $.ajax({
                url: "ajaxGetDetails.php",
                method: "POST",
                data: data,
                dataType: "json",
                success: function(response) {
                    if (response.status === "success") {
                        $("#updateMsg").html("<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>" + response.msg + "</div>").show();
                        setTimeout(function() {
                            var coll = $('#department').val();
                            if (coll) GetDetails(coll);
                        }, 800);
                        setTimeout(function() { $("#updateMsg").fadeOut("slow"); }, 5000);
                    } else {
                        $("#updateMsg").html("<div class='alert alert-danger'><i class='fas fa-exclamation-circle me-2'></i>" + response.msg + "</div>").show();
                    }
                },
                error: function(xhr, status, error) {
                    $("#updateMsg").html("<div class='alert alert-danger'><i class='fas fa-exclamation-circle me-2'></i>Something went wrong! Please try again.</div>").show();
                    console.error("AJAX error:", status, error, xhr.responseText);
                },
                complete: function() {
                    $(".loader").hide();
                }
            });
        });
    </script>
</body>
</html>

