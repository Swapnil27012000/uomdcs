<?php
// admin/EditDepartmentDetails.php - Edit Department Details
require('session.php');

error_reporting(0);

//Normal year wise logic
$year = date("Y");
$pyear = $year - 1;
$A_YEAR = $pyear . '-' . $year;

$dept = $_SESSION['dept_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" />
    <link rel="stylesheet" href="assets/css/styles.css" />
    <link rel="icon" href="assets/img/mumbai-university-removebg-preview.png" type="image/png">
    <title>UoM Centralized DCS Ranking PORTAL</title>

    <!-- jQuery: include once -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <style>
        /* your styles (kept as before) */
        body { font-family: Arial, sans-serif; background-color: #f6f7fb; margin: 0; padding: 0; }
        .container { max-width: 900px; margin: 40px auto; background: #fff; padding: 25px 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.08);}
        h3 { font-size: 18px; font-weight: bold; margin-bottom: 12px; }
        select, input[type="text"], input[type="email"] { width:100%; padding:10px; border-radius:5px; border:1px solid #ccc; margin-top:8px; font-size:14px;}
        .btn { display:inline-block; padding:10px 20px; border-radius:6px; border:none; cursor:pointer; font-size:14px; font-weight:bold; margin-top:15px; }
        .btn-primary { background-color:#0066ff; color:white; } .btn-primary:hover { background-color:#0052cc; }
        table { width:100%; margin-top:20px; border-collapse:collapse; }
        table th, table td { border:1px solid #e1e1e1; padding:12px; text-align:left; }
        table th { background-color:#f4f4f4; font-weight:bold; }
        .note { margin-top:12px; font-size:14px; color:red; font-weight:500; }
        .select2-container { width:100% !important; }

        .loader { display:none; position:fixed; z-index:9999; top:50%; left:50%; transform:translate(-50%,-50%);
                 border:8px solid #f3f3f3; border-radius:50%; border-top:8px solid #3498db; width:60px; height:60px; animation:spin 1s linear infinite; }
        @keyframes spin { 0%{ transform: rotate(0deg);} 100%{ transform: rotate(360deg);} }
        #updateMsg p { margin:0; padding:8px; border-radius:4px; }
    </style>

    <script>
    // Get department details (HTML) and inject into #department_details
    function GetDetails(val) {
        if (!val) {
            $("#department_details").html('');
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
                $("#department_details").html('<p style="color:red;">Error loading details.</p>');
                console.error(error);
            },
            complete: function() {
                $(".loader").hide();
            }
        });
    }

    // Delegate submit handler (form is injected dynamically)
    $(document).on("submit", "#updateForm", function(e) {
        e.preventDefault();

        // Ensure update_btn is sent (force it)
        var data = $(this).serialize() + "&update_btn=1";

        $(".loader").show();

        $.ajax({
            url: "ajaxGetDetails.php",
            method: "POST",
            data: data,
            dataType: "json",
            success: function(response) {
                if (response.status === "success") {
                    $("#updateMsg").html("<p style='color:green; font-weight:bold;'>" + response.msg + "</p>").show();
                    // If it was a NEW insert, clear only the editable fields and refresh the form to show saved data
                    if (response.action === "insert") {
                        // clear email/HOD fields (keep dept code and name)
                        $("#updateForm").find('input[name="dept_email"], input[name="hod_name"], input[name="hod_address"]').val('');
                        // Refresh the form (now department_master has the record) after a short delay
                        setTimeout(function() {
                            var coll = $('#department').val();
                            if (coll) GetDetails(coll);
                        }, 800);
                    } else {
                        // for update, refresh to display latest saved values
                        setTimeout(function() {
                            var coll = $('#department').val();
                            if (coll) GetDetails(coll);
                        }, 800);
                    }
                    // auto-hide success
                    setTimeout(function() { $("#updateMsg").fadeOut("slow"); }, 5000);
                } else {
                    $("#updateMsg").html("<p style='color:red; font-weight:bold;'>" + response.msg + "</p>").show();
                }
            },
            error: function(xhr, status, error) {
                $("#updateMsg").html("<p style='color:red; font-weight:bold;'>❌ Something went wrong! (" + (xhr.status || '') + ")</p>").show();
                console.error("AJAX error:", status, error, xhr.responseText);
            },
            complete: function() {
                $(".loader").hide();
            }
        });
    });

    // Initialize select2 once DOM ready
    $(document).ready(function() {
        $('#department').select2({ placeholder: "Select Department", allowClear: true });
    });
    </script>
</head>

<body>
    <div class="loader"></div>
    <div class="d-flex" id="wrapper">
        <!-- Sidebar -->
        <?php include('sidebar.php'); ?>
        <!-- /#sidebar-wrapper -->

        <!-- Page Content -->
        <div id="page-content-wrapper">
            <nav class="navbar navbar-expand-lg navbar-light bg-transparent py-4 px-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-align-left primary-text fs-4 me-3" id="menu-toggle"></i>
                    <h2 class="fs-2 m-0">Dashboard</h2>
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
                                <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['admin_username']); ?>
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
                                <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="container">
                <h3>NAME OF THE DEPARTMENT</h3>
                <!-- NOTE: use colleges table for collno / collname to stay consistent -->
                <select id="department" name="department" style="width: 100%;" onchange="GetDetails(this.value);" required>
                    <option value="">Select Department</option>
                    <?php
                    // Use 'colleges' table so collname matches ajaxGetDetails.php lookups
                    $datapoint_query_1 = "SELECT collno, collname FROM colleges ORDER BY collname ASC";
                    $datapoint_result_1 = mysqli_query($conn, $datapoint_query_1);
                    while ($info_1 = mysqli_fetch_array($datapoint_result_1, MYSQLI_ASSOC)) {
                        $collno = $info_1['collno'];
                        $collname = $info_1['collname'];
                        echo '<option value="'.htmlspecialchars($collno).'">'.htmlspecialchars($collno).' : '.htmlspecialchars($collname).'</option>';
                    }
                    ?>
                </select>

                <div id="department_details">
                    <!-- default placeholder -->
                    <table>
                        <tr>
                            <th>Department Code</th>
                            <th>Department Name</th>
                            <th>Department Email</th>
                        </tr>
                        <tr>
                            <td>0000</td>
                            <td><input type="text" value="xyz" disabled></td>
                            <td><input type="email" value="xyz@gmail.com" disabled></td>
                        </tr>
                    </table>
                    <button class="btn btn-primary" disabled>UPDATE DETAILS</button>
                </div>

                <p class="note">After updating the department details, make sure to resend the login credentials.</p>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            var el = document.getElementById("wrapper");
            var toggleButton = document.getElementById("menu-toggle");
            toggleButton.onclick = function() { el.classList.toggle("toggled"); };
        </script>
    </div>

    <!-- Include Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- Footer -->
<?php include '../footer_main.php';?>
</body>

</html>
