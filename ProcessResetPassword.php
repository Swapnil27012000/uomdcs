<?php

include 'config.php'; // Include database configuration

session_start();
error_reporting(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method.");
}

if (!isset($_POST["token"], $_POST["password"], $_POST["confirm_password"])) {
    die("Missing required fields.");
}

$token = $_POST["token"];
$new_password = $_POST["password"];
$confirm_password = $_POST["confirm_password"];

// Validate password and confirm password match
if ($new_password !== $confirm_password) {
    die("Passwords do not match.");
}

$mysql = $conn; // Ensure $conn is initialized in config.php

// Function to find user and update password
function findAndUpdateUser($conn, $token, $new_password) {
    // First check department_master table
    $stmt = $conn->prepare("SELECT EMAIL, RESET_TOKEN_EXPIRE FROM department_master WHERE RESET_PASSWORD = ? AND RESET_TOKEN_EXPIRE > NOW()");
    if (!$stmt) {
        die("Database error: " . $conn->error);
    }

    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user) {
        // Update password in department_master
        $update_stmt = $conn->prepare("UPDATE department_master SET 
            PASS_WORD = ?, 
            RESET_PASSWORD = NULL, 
            RESET_TOKEN_EXPIRE = NULL 
            WHERE EMAIL = ?");

        if (!$update_stmt) {
            die("Database error: " . $conn->error);
        }

        $update_stmt->bind_param("ss", $new_password, $user['EMAIL']);
        $success = $update_stmt->execute();
        $update_stmt->close();
        
        return $success;
    }

    // If not found in department_master, check boss table
    $stmt = $conn->prepare("SELECT EMAIL, RESET_TOKEN_EXPIRE FROM boss WHERE RESET_PASSWORD = ? AND RESET_TOKEN_EXPIRE > NOW()");
    if (!$stmt) {
        die("Database error: " . $conn->error);
    }

    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user) {
        // Update password in boss table
        $update_stmt = $conn->prepare("UPDATE boss SET 
            PASS_WORD = ?, 
            RESET_PASSWORD = NULL, 
            RESET_TOKEN_EXPIRE = NULL 
            WHERE EMAIL = ?");

        if (!$update_stmt) {
            die("Database error: " . $conn->error);
        }

        $update_stmt->bind_param("ss", $new_password, $user['EMAIL']);
        $success = $update_stmt->execute();
        $update_stmt->close();

        return $success;
    }

    return false;
}

// Try to find and update user in either table
$success = findAndUpdateUser($mysql, $token, $new_password);



// Check if the update was successful
if (!$success) {
    die("Invalid or expired password reset token.");
}

// Show success message in a modal
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Successful</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .modal-success {
            color: #0f5132;
            background: #d1e7dd;
            border-color: #badbcc;
        }
        .success-icon {
            font-size: 3rem;
            color: #198754;
        }
        .modal-backdrop {
            background-color: rgba(0, 0, 0, 0.5);
        }
    </style>
</head>
<body>
    <!-- Success Modal -->
    <div class="modal fade" id="successModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="redirectToLogin()"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="success-icon mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="currentColor" class="bi bi-check-circle-fill" viewBox="0 0 16 16">
                            <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                        </svg>
                    </div>
                    <h4 class="mb-3">Password Reset Successful!</h4>
                    <p class="mb-4">Your password has been successfully updated. You can now log in with your new password.</p>
                    <button type="button" class="btn btn-success px-4 py-2" onclick="redirectToLogin()">
                        Proceed to Login
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show modal on page load
        document.addEventListener('DOMContentLoaded', function() {
            var successModal = new bootstrap.Modal(document.getElementById('successModal'));
            successModal.show();
        });

        // Redirect function
        function redirectToLogin() {
            window.location.href = 'unified_login.php';
        }

        // Prevent going back to reset page
        history.pushState(null, null, document.URL);
        window.addEventListener('popstate', function () {
            history.pushState(null, null, document.URL);
        });
    </script>
</body>
</html>
<?php
exit;




?>