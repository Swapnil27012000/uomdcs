<?php
// admin/AllUserCredentials.php - Send Credentials to Experts, AAQA, Chairman, etc.
require('session.php');
error_reporting(0);

// Check if SEND_CRED column exists in boss table
$check_col = mysqli_query($conn, "SHOW COLUMNS FROM boss LIKE 'SEND_CRED'");
$has_send_cred = ($check_col && mysqli_num_rows($check_col) > 0);
if ($check_col) {
    mysqli_free_result($check_col);
}

// Get all users from boss table (experts, AAQA, chairman, etc.)
if ($has_send_cred) {
    $query = "SELECT Id, EMAIL, PASS_WORD, PERMISSION, IFNULL(SEND_CRED, 0) AS SEND_CRED
              FROM boss
              WHERE PERMISSION IN ('Expert_comty_login', 'AAQA_login', 'Chairman_login', 'verification_committee')
              ORDER BY 
                CASE PERMISSION 
                    WHEN 'Expert_comty_login' THEN 1
                    WHEN 'AAQA_login' THEN 2
                    WHEN 'Chairman_login' THEN 3
                    WHEN 'verification_committee' THEN 4
                    ELSE 5
                END,
                EMAIL";
} else {
    $query = "SELECT Id, EMAIL, PASS_WORD, PERMISSION, 0 AS SEND_CRED
              FROM boss
              WHERE PERMISSION IN ('Expert_comty_login', 'AAQA_login', 'Chairman_login', 'verification_committee')
              ORDER BY 
                CASE PERMISSION 
                    WHEN 'Expert_comty_login' THEN 1
                    WHEN 'AAQA_login' THEN 2
                    WHEN 'Chairman_login' THEN 3
                    WHEN 'verification_committee' THEN 4
                    ELSE 5
                END,
                EMAIL";
}
$result = mysqli_query($conn, $query);
// SECURITY: Note - query has no user input, but result should be freed after use
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
    <title>User Credentials Management - Admin</title>
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

        #userstable,
        #userstable th,
        #userstable td {
            border: 1px solid #dee2e6 !important;
        }

        #userstable th,
        #userstable td {
            padding: 12px;
            vertical-align: middle;
        }

        #userstable {
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

        .badge-permission {
            font-size: 0.85rem;
            padding: 0.35rem 0.65rem;
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
                    <h2 class="fs-2 m-0">User Credentials Management</h2>
                </div>
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

            <div class="div">
                <div class="container">
                    <div id="responseMessage" class="alert"></div>

                    <!-- Download as Excel Button -->
                    <button id="downloadExcel" class="download-btn">
                        <i class="fas fa-file-excel"></i> Download as Excel
                    </button>

                    <div>
                        <table class="table table-bordered" id="userstable" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Password</th>
                                    <th>Permission/Role</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if ($result && mysqli_num_rows($result) > 0) {
                                    while ($row = mysqli_fetch_assoc($result)) {
                                        $userId = (int)$row['Id'];
                                        $email = htmlspecialchars($row['EMAIL'], ENT_QUOTES, 'UTF-8');
                                        $password = htmlspecialchars($row['PASS_WORD'], ENT_QUOTES, 'UTF-8');
                                        $permission = htmlspecialchars($row['PERMISSION'], ENT_QUOTES, 'UTF-8');
                                        $sendCred = (int)($row['SEND_CRED'] ?? 0);

                                        // Get permission badge color
                                        $badgeClass = 'bg-secondary';
                                        $permissionLabel = $permission;
                                        switch (strtolower($permission)) {
                                            case 'expert_comty_login':
                                                $badgeClass = 'bg-info';
                                                $permissionLabel = 'Expert Committee';
                                                break;
                                            case 'aaqa_login':
                                                $badgeClass = 'bg-primary';
                                                $permissionLabel = 'AAQA';
                                                break;
                                            case 'chairman_login':
                                                $badgeClass = 'bg-success';
                                                $permissionLabel = 'Chairman';
                                                break;
                                            case 'verification_committee':
                                                $badgeClass = 'bg-warning text-dark';
                                                $permissionLabel = 'Verification Committee';
                                                break;
                                        }

                                        $btnClass = $sendCred === 1 ? 'btn-resend' : 'btn-send';
                                        $btnText = $sendCred === 1 ? 'Re-send Credentials' : 'Send Credentials';
                                ?>
                                        <tr>
                                            <td><?php echo $email; ?></td>
                                            <td><?php echo $password; ?></td>
                                            <td>
                                                <span class="badge <?php echo $badgeClass; ?> badge-permission">
                                                    <?php echo $permissionLabel; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button"
                                                    class="btn <?php echo $btnClass; ?> send-mail-btn"
                                                    data-id="<?php echo $userId; ?>"
                                                    data-email="<?php echo htmlspecialchars($email, ENT_QUOTES); ?>"
                                                    data-permission="<?php echo htmlspecialchars($permission, ENT_QUOTES); ?>">
                                                    <?php echo $btnText; ?>
                                                </button>
                                            </td>
                                        </tr>
                                <?php
                                    }
                                    mysqli_free_result($result);
                                } else {
                                    echo '<tr><td colspan="4">No users found.</td></tr>';
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
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

    <script>
        $(document).ready(function() {
            var table = $('#userstable').DataTable({
                responsive: true,
                pageLength: 10,
                lengthMenu: [5, 10, 25, 50, 100, 500, 1000],
                columnDefs: [{
                    orderable: false,
                    targets: 3
                }]
            });

            // Download Excel function
            $('#downloadExcel').on('click', function() {
                var wb = XLSX.utils.book_new();
                var ws = XLSX.utils.table_to_sheet(document.getElementById('userstable'));
                XLSX.utils.book_append_sheet(wb, ws, "Users");
                XLSX.writeFile(wb, "User_Credentials_List.xlsx");
            });

            // Send Mail Button logic
            $('#userstable tbody').on('click', '.send-mail-btn', function() {
                var button = $(this);
                var userId = button.data('id');
                var email = button.data('email');
                var permission = button.data('permission');
                var responseBox = $('#responseMessage');

                if (!userId) {
                    responseBox.removeClass().addClass("alert alert-danger").text("Invalid User ID").fadeIn();
                    return;
                }

                button.text('Sending...').prop('disabled', true);

                $.ajax({
                    url: 'send_user_credentials.php',
                    type: 'POST',
                    data: { 
                        userId: userId,
                        email: email,
                        permission: permission
                    },
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
