<?php
// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    include 'config.php';
}

if (isset($_GET['token'])) {
    // Sanitize input
    $token = trim($_GET['token']);

    if (empty($token)) {
        echo "<div class='alert alert-danger text-center'>No valid token provided.</div>";
        exit;
    }

    // Database connection
    // $conn = new mysqli('localhost', 'root', '', 'test');
    if ($conn->connect_error) {
        die("<div class='alert alert-danger text-center'>Database connection failed: " . $conn->connect_error . "</div>");
    }

    // Validate token
    $stmt = $conn->prepare("SELECT email, RESET_TOKEN_EXPIRE, used FROM user_invitationss WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($email, $expire_at, $used);
        $stmt->fetch();

        if (strtotime($expire_at) < time()) {
            echo "<div class='alert alert-warning text-center'>The token has expired. Please request a new invitation.</div>";
        } elseif ($used == 1) {
            echo "<div class='alert alert-warning text-center'>This token has already been used. Please request a new invitation.</div>";
        } else {
?>
            <!DOCTYPE html>
            <html lang="en">

            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">
                <link rel="stylesheet" type="text/css" href="./assets/css/style.css">
                <link rel="icon" href="assets/img/mumbai-university-removebg-preview.png" type="image/png">

                <title>Create Account</title>
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
                <script>
                    function validatePasswords() {
                        let password = document.getElementById('password').value;
                        let confirmPassword = document.getElementById('confirm_password').value;
                        let errorMessage = document.getElementById('passwordError');

                        if (password !== confirmPassword) {
                            errorMessage.innerText = "Passwords do not match!";
                            return false;
                        } else {
                            errorMessage.innerText = "";
                            return true;
                        }
                    }
                </script>
                <style>
                    .container {
                        max-width: 500px;
                        margin-top: 50px;
                    }

                    .card {
                        padding: 20px;
                        border-radius: 10px;
                    }

                    .error {
                        color: red;
                        font-size: 14px;
                    }
                </style>
            </head>

            <body>
                <nav class="navbar">
                    <div class="items">
                        <img src="assets\img\mumbai-university-removebg-preview.png" alt="image" height="100px" width="">
                        <h1>University Of Mumbai</h1>
                        <img src="assets\img\nirf-full-removebg-preview.png" alt="image" height="90px" width="">
                    </div>
                </nav>
                <div class="container">
                    <div class="card shadow">
                        <h3 class="text-center">Create Account</h3>
                        <form method="POST" action="process_account_creation.php" onsubmit="return validatePasswords()">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" value="<?= htmlspecialchars($email) ?>" readonly>
                                <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Department Name</label>
                                <input type="text" name="dept_name" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">HOD Name</label>
                                <input type="text" name="hod_name" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Address</label>
                                <textarea name="address" class="form-control" rows="3" required></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="text" name="password" id="password" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Confirm Password</label>
                                <input type="text" name="confirm_password" id="confirm_password" class="form-control" required>
                                <span id="passwordError" class="error"></span>
                            </div>

                            <input type="hidden" name="permission" value="user">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                            <button type="submit" class="btn btn-primary w-100">Create Account</button>
                        </form>
                    </div>
                </div>
            </body>

            </html>
<?php

            // Mark token as used
            // $update_stmt = $conn->prepare("UPDATE user_invitationss SET used = 1 WHERE token = ?");
            // $update_stmt->bind_param("s", $token);
            // $update_stmt->execute();
            // $update_stmt->close();
        }
    } else {
        echo "<div class='alert alert-danger text-center'>Invalid or expired token.</div>";
    }

    $stmt->close();
    $conn->close();
} else {
    echo "<div class='alert alert-danger text-center'>No token provided.</div>";
}
?>




<!-- ########## Make sure the DEPT_ID column is set to AUTO_INCREMENT   #################  -->

<!-- -- 
ALTER TABLE department_master MODIFY COLUMN DEPT_ID INT AUTO_INCREMENT; 
-->



<!-- invitation Database  -->

<!-- 

CREATE TABLE `user_invitationss` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `RESET_TOKEN_EXPIRE` timestamp NOT NULL DEFAULT current_timestamp()
) 
  
-->