<?php
// admin/AllDepartmentDetails.php - All Department Details
require('session.php');
error_reporting(0);

$year = date("Y");
$pyear = $year - 1;
$A_YEAR = $pyear . '-' . $year;
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f6f7fb;
        }

        .form-container {
            background-color: #ffffff;
            padding: 50px;
            border-radius: 20px;
            width: 100%;
            margin-top: 30px;
        }

        #collegetable,
        #collegetable th,
        #collegetable td {
            border: 1px solid #dee2e6 !important;
        }

        #collegetable th,
        #collegetable td {
            padding: 12px;
            vertical-align: middle;
        }

        #collegetable {
            border-collapse: collapse;
            margin: 20px 0;
        }

        .btn-send {
            background-color: #198754;
            color: #fff;
        }

        .btn-resend {
            background-color: #ffc107;
            color: #000;
        }

        .alert {
            display: none;
            margin-top: 15px;
        }

        .div {
            padding: 20px;
            background-color: white;
        }

        .download-btn {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            margin-bottom: 10px;
        }

        .download-btn:hover {
            background-color: #0056b3;
        }
    </style>
</head>

<body>
    <div class="d-flex" id="wrapper">
        <?php include('sidebar.php'); ?>
        <div id="page-content-wrapper">
            <nav class="navbar navbar-expand-lg navbar-light bg-transparent py-4 px-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-align-left primary-text fs-4 me-3" id="menu-toggle"></i>
                    <h2 class="fs-2 m-0">Dashboard</h2>
                </div>
                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle second-text fw-bold" href="#" id="navbarDropdown"
                                role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user me-2"></i><?php echo $_SESSION['admin_username'] ?>
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
                                <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="div">
                <div class="container">
                    <div id="responseMessage" class="alert"></div>

                    <!-- ✅ Download as Excel Button -->
                    <button id="downloadExcel" class="download-btn">
                        <i class="fas fa-file-excel"></i> Download as Excel
                    </button>

                    <div>
                        <table class="table table-bordered" id="collegetable" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>Department Code</th>
                                    <th>Department Name</th>
                                    <th>Department Email</th>
                                    <th>HOD Email</th>
                                    <th>Department password</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                ini_set('display_errors', 1);
                                error_reporting(E_ALL);
                                $query = "SELECT a.DEPT_ID, a.DEPT_COLL_NO, b.collno, a.DEPT_NAME, a.EMAIL,a.HOD_EMAIL, a.PASS_WORD, b.collname, IFNULL(a.SEND_CRED,0) AS SEND_CRED
                                          FROM department_master a
                                          JOIN colleges b ON b.collno = a.DEPT_COLL_NO";
                                $result = mysqli_query($conn, $query);
                                if ($result) {
                                    while ($row = mysqli_fetch_assoc($result)) {
                                        $deptId    = (int)$row['DEPT_ID'];
                                        $deptName  = htmlspecialchars($row['collname']);
                                        $deptEmail = htmlspecialchars($row['EMAIL']);
                                        $deptHodEmail = htmlspecialchars($row['HOD_EMAIL']);
                                        $deptPass  = htmlspecialchars($row['PASS_WORD']);
                                        $deptCode  = htmlspecialchars($row['collno']);
                                        $sendCred  = (int)$row['SEND_CRED'];

                                        $btnClass = $sendCred === 1 ? 'btn-resend' : 'btn-send';
                                        $btnText  = $sendCred === 1 ? 'Re-send Credentials' : 'Send Credentials';
                                ?>
                                        <tr>
                                            <td class="text-center"><?php echo $deptCode; ?></td>
                                            <td><?php echo $deptName; ?></td>
                                            <td><?php echo $deptEmail; ?></td>
                                            <td><?php echo $deptHodEmail; ?></td>
                                            <td><?php echo $deptPass; ?></td>
                                            <td>
                                                <button type="button"
                                                    class="btn <?php echo $btnClass; ?> send-mail-btn"
                                                    data-id="<?php echo $deptId; ?>">
                                                    <?php echo $btnText; ?>
                                                </button>
                                            </td>
                                        </tr>
                                <?php
                                    }
                                } else {
                                    echo '<tr><td colspan="5">No departments found or query error.</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <!-- ✅ SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

    <script>
        $(document).ready(function() {
            var table = $('#collegetable').DataTable({
                responsive: true,
                pageLength: 10,
                lengthMenu: [5, 10, 25, 50, 100,500, 1000],
                columnDefs: [{
                    orderable: false,
                    targets: 4
                }]
            });

            // ✅ Download Excel function
            $('#downloadExcel').on('click', function() {
                var wb = XLSX.utils.book_new();
                var ws = XLSX.utils.table_to_sheet(document.getElementById('collegetable'));
                XLSX.utils.book_append_sheet(wb, ws, "Departments");
                XLSX.writeFile(wb, "Department_List.xlsx");
            });

            // ✅ Send Mail Button logic
            $('#collegetable tbody').on('click', '.send-mail-btn', function() {
                var button = $(this);
                var deptId = button.data('id');
                var responseBox = $('#responseMessage');

                if (!deptId) {
                    responseBox.removeClass().addClass("alert alert-danger").text("Invalid Department ID").fadeIn();
                    return;
                }

                button.text('Sending...').prop('disabled', true);

                $.ajax({
                    url: 'sendmail.php',
                    type: 'POST',
                    data: { deptId: deptId },
                    dataType: 'json',
                    success: function(data) {
                        if (data && data.status === "success") {
                            responseBox.removeClass().addClass("alert alert-success").text(data.message).fadeIn();
                            button.removeClass('btn-send').addClass('btn-resend').text('Re-send Credentials');
                        } else {
                            var msg = (data && data.message) ? data.message : 'Something went wrong.';
                            responseBox.removeClass().addClass("alert alert-danger").text(msg).fadeIn();
                            button.text('Send Credentials');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error(xhr.responseText);
                        responseBox.removeClass().addClass("alert alert-danger").text("AJAX error: " + error).fadeIn();
                        button.text('Send Credentials');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                        setTimeout(function() { responseBox.fadeOut(); }, 5000);
                    }
                });
            });
        });

        // Sidebar toggle
        var el = document.getElementById("wrapper");
        var toggleButton = document.getElementById("menu-toggle");
        toggleButton.onclick = function() {
            el.classList.toggle("toggled");
        };
    </script>
<!-- Footer -->
<?php include '../footer_main.php';?>
</body>


</html>
