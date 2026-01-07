<?php
require('session.php');
require('unified_header.php');

// Load CSRF utilities
if (file_exists(__DIR__ . '/csrf.php')) {
    require_once __DIR__ . '/csrf.php';
}

// Rate limiting for password changes (simple session-based)
if (!isset($_SESSION['password_change_attempts'])) {
    $_SESSION['password_change_attempts'] = 0;
    $_SESSION['password_change_last_attempt'] = 0;
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!function_exists('validate_csrf') || !validate_csrf($_POST['csrf_token'] ?? '')) {
        $error_message = 'Security token validation failed. Please refresh the page and try again.';
    } else {
        // Rate limiting check (max 5 attempts per 15 minutes)
        $current_time = time();
        $last_attempt = $_SESSION['password_change_last_attempt'] ?? 0;
        
        if ($current_time - $last_attempt < 900) { // 15 minutes
            if ($_SESSION['password_change_attempts'] >= 5) {
                $error_message = 'Too many password change attempts. Please wait 15 minutes before trying again.';
            }
        } else {
            // Reset attempts after 15 minutes
            $_SESSION['password_change_attempts'] = 0;
        }
        
        if (empty($error_message)) {
            // Retrieve and sanitize user input
            $email = $_SESSION['admin_username'] ?? '';
            $currentPassword = trim($_POST['current_password'] ?? '');
            $newPassword = trim($_POST['new_password'] ?? '');
            $confirmPassword = trim($_POST['confirm_password'] ?? '');
            
            // Validate input
            if (empty($email)) {
                $error_message = 'Session expired. Please log in again.';
            } elseif (empty($currentPassword)) {
                $error_message = 'Current password is required.';
            } elseif (empty($newPassword)) {
                $error_message = 'New password is required.';
            } elseif (strlen($newPassword) < 8) {
                $error_message = 'New password must be at least 8 characters long.';
            } elseif ($newPassword !== $confirmPassword) {
                $error_message = 'New password and confirm password do not match.';
            } elseif ($currentPassword === $newPassword) {
                $error_message = 'New password must be different from current password.';
            }
            
            if (empty($error_message)) {
                // Fetch current password using prepared statement
                $stmt = mysqli_prepare($conn, "SELECT PASS_WORD FROM department_master WHERE EMAIL = ? LIMIT 1");
                if (!$stmt) {
                    $error_message = 'Database error. Please try again.';
                    error_log("Changepwd.php prepare error: " . mysqli_error($conn));
                } else {
                    mysqli_stmt_bind_param($stmt, "s", $email);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if ($result && ($row = mysqli_fetch_assoc($result))) {
                        $storedPassword = $row['PASS_WORD'];
                        
                        // Verify current password (support both plaintext and hashed passwords)
                        $passwordMatch = false;
                        if (password_verify($currentPassword, $storedPassword)) {
                            // Password is hashed
                            $passwordMatch = true;
                        } elseif ($currentPassword === $storedPassword) {
                            // Password is plaintext (backward compatibility)
                            $passwordMatch = true;
                        }
                        
                        if ($passwordMatch) {
                            // Hash the new password using the same method as the system (PASSWORD_DEFAULT)
                            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                            
                            // Verify the hash was created successfully
                            if (!$hashedPassword) {
                                $error_message = 'Failed to hash password. Please try again.';
                                error_log("Changepwd.php password_hash failed");
                            } else {
                                // Update password using prepared statement
                                $updateStmt = mysqli_prepare($conn, "UPDATE department_master SET PASS_WORD = ? WHERE EMAIL = ?");
                                if (!$updateStmt) {
                                    $error_message = 'Database error. Please try again.';
                                    error_log("Changepwd.php update prepare error: " . mysqli_error($conn));
                                } else {
                                    mysqli_stmt_bind_param($updateStmt, "ss", $hashedPassword, $email);
                                    if (mysqli_stmt_execute($updateStmt)) {
                                        // Verify the update actually affected a row
                                        $affectedRows = mysqli_stmt_affected_rows($updateStmt);
                                        if ($affectedRows > 0) {
                                            $success_message = 'Password changed successfully!';
                                            // Reset rate limiting on success
                                            $_SESSION['password_change_attempts'] = 0;
                                            $_SESSION['password_change_last_attempt'] = 0;
                                            
                                            // Verify the new password can be verified (double-check)
                                            $verifyStmt = mysqli_prepare($conn, "SELECT PASS_WORD FROM department_master WHERE EMAIL = ? LIMIT 1");
                                            if ($verifyStmt) {
                                                mysqli_stmt_bind_param($verifyStmt, "s", $email);
                                                mysqli_stmt_execute($verifyStmt);
                                                $verifyResult = mysqli_stmt_get_result($verifyStmt);
                                                if ($verifyRow = mysqli_fetch_assoc($verifyResult)) {
                                                    if (!password_verify($newPassword, $verifyRow['PASS_WORD'])) {
                                                        error_log("Changepwd.php WARNING: Password update verification failed - new password doesn't match stored hash!");
                                                    }
                                                }
                                                mysqli_stmt_close($verifyStmt);
                                            }
                                        } else {
                                            $error_message = 'Password update failed. No rows were updated. Please try again.';
                                            error_log("Changepwd.php update affected 0 rows for email: " . $email);
                                        }
                                    } else {
                                        $error_message = 'Failed to update password. Please try again.';
                                        error_log("Changepwd.php update execute error: " . mysqli_stmt_error($updateStmt));
                                    }
                                    mysqli_stmt_close($updateStmt);
                                }
                            }
                        } else {
                            $error_message = 'Current password is incorrect.';
                            // Increment rate limiting counter
                            $_SESSION['password_change_attempts'] = ($_SESSION['password_change_attempts'] ?? 0) + 1;
                            $_SESSION['password_change_last_attempt'] = $current_time;
                        }
                    } else {
                        $error_message = 'User not found.';
                    }
                    mysqli_stmt_close($stmt);
                }
            } else {
                // Increment rate limiting counter on validation failure
                $_SESSION['password_change_attempts'] = ($_SESSION['password_change_attempts'] ?? 0) + 1;
                $_SESSION['password_change_last_attempt'] = $current_time;
            }
        }
    }
}
?>

<div class="page-header">
    <h1 class="page-title">
        <i class="fas fa-key"></i>Change Password
    </h1>
    <p class="page-subtitle">Update your account password</p>
</div>

<div class="card mb-4">
    <div class="card-body">
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form action="" method="post" autocomplete="off" id="changePasswordForm">
            <?php if (function_exists('csrf_field')): ?>
                <?php echo csrf_field(); ?>
            <?php endif; ?>
            
            <div class="mb-3">
                <label class="form-label">
                    <i class="fas fa-lock me-2"></i>Current Password
                </label>
                <input type="password" 
                       name="current_password" 
                       class="form-control" 
                       placeholder="Enter Current Password" 
                       required 
                       autocomplete="current-password"
                       minlength="1">
            </div>
            
            <div class="mb-3">
                <label class="form-label">
                    <i class="fas fa-key me-2"></i>New Password
                </label>
                <input type="password" 
                       name="new_password" 
                       class="form-control" 
                       placeholder="Enter New Password (minimum 8 characters)" 
                       required 
                       autocomplete="new-password"
                       minlength="8"
                       id="new_password">
                <div class="form-text">Password must be at least 8 characters long</div>
            </div>
           
            <div class="mb-3">
                <label class="form-label">
                    <i class="fas fa-check-double me-2"></i>Confirm New Password
                </label>
                <input type="password" 
                       name="confirm_password" 
                       class="form-control" 
                       placeholder="Confirm New Password" 
                       required 
                       autocomplete="new-password"
                       minlength="8"
                       id="confirm_password">
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-2"></i>Change Password
            </button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('changePasswordForm');
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    
    // Real-time password match validation
    function validatePasswordMatch() {
        if (confirmPassword.value && newPassword.value) {
            if (newPassword.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match');
            } else {
                confirmPassword.setCustomValidity('');
            }
        }
    }
    
    newPassword.addEventListener('input', validatePasswordMatch);
    confirmPassword.addEventListener('input', validatePasswordMatch);
});
</script>

<?php
require "unified_footer.php";
?>