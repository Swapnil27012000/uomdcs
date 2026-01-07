<?php
/**
 * Expert Committee Change Password Page
 * Allows Expert users to change their password
 */

// Start output buffering early to prevent issues
if (!ob_get_level()) {
    ob_start();
}

// Enable error reporting for debugging (remove in production if needed)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require('session.php');
require_once(__DIR__ . '/../config.php');

// Load CSRF utilities
if (file_exists(__DIR__ . '/../csrf_shared.php')) {
    require_once __DIR__ . '/../csrf_shared.php';
} elseif (file_exists(__DIR__ . '/../dept_login/csrf.php')) {
    require_once __DIR__ . '/../dept_login/csrf.php';
}

// Rate limiting for password changes (simple session-based)
if (!isset($_SESSION['password_change_attempts'])) {
    $_SESSION['password_change_attempts'] = 0;
    $_SESSION['password_change_last_attempt'] = 0;
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Ensure database connection is available
        if (!isset($conn) || !$conn) {
            throw new Exception('Database connection not available');
        }
        
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
            $user_email = $email ?? ($_SESSION['admin_username'] ?? '');
            $currentPassword = trim($_POST['current_password'] ?? '');
            $newPassword = trim($_POST['new_password'] ?? '');
            $confirmPassword = trim($_POST['confirm_password'] ?? '');
            
            // Validate input
            if (empty($user_email)) {
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
                // Fetch current password using prepared statement from boss table
                $stmt = $conn->prepare("SELECT PASS_WORD FROM boss WHERE EMAIL = ? AND PERMISSION = 'Expert_comty_login' LIMIT 1");
                if (!$stmt) {
                    $error_message = 'Database error. Please try again.';
                    error_log("Expert Changepwd.php prepare error: " . $conn->error);
                } else {
                    $stmt->bind_param("s", $user_email);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result && ($row = $result->fetch_assoc())) {
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
                                error_log("Expert Changepwd.php password_hash failed");
                            } else {
                                // Update password using prepared statement
                                $updateStmt = $conn->prepare("UPDATE boss SET PASS_WORD = ? WHERE EMAIL = ? AND PERMISSION = 'Expert_comty_login'");
                                if (!$updateStmt) {
                                    $error_message = 'Database error. Please try again.';
                                    error_log("Expert Changepwd.php update prepare error: " . $conn->error);
                                } else {
                                    $updateStmt->bind_param("ss", $hashedPassword, $user_email);
                                    if ($updateStmt->execute()) {
                                        // Verify the update actually affected a row
                                        $affectedRows = $updateStmt->affected_rows;
                                        if ($affectedRows > 0) {
                                            $success_message = 'Password changed successfully!';
                                            // Reset rate limiting on success
                                            $_SESSION['password_change_attempts'] = 0;
                                            $_SESSION['password_change_last_attempt'] = 0;
                                            
                                            // Verify the new password can be verified (double-check)
                                            $verifyStmt = $conn->prepare("SELECT PASS_WORD FROM boss WHERE EMAIL = ? AND PERMISSION = 'Expert_comty_login' LIMIT 1");
                                            if ($verifyStmt) {
                                                $verifyStmt->bind_param("s", $user_email);
                                                $verifyStmt->execute();
                                                $verifyResult = $verifyStmt->get_result();
                                                if ($verifyRow = $verifyResult->fetch_assoc()) {
                                                    if (!password_verify($newPassword, $verifyRow['PASS_WORD'])) {
                                                        error_log("Expert Changepwd.php WARNING: Password update verification failed - new password doesn't match stored hash!");
                                                    }
                                                }
                                                mysqli_free_result($verifyResult);
                                                $verifyStmt->close();
                                            }
                                        } else {
                                            $error_message = 'Password update failed. No rows were updated. Please try again.';
                                            error_log("Expert Changepwd.php update affected 0 rows for email: " . $user_email);
                                        }
                                    } else {
                                        $error_message = 'Failed to update password. Please try again.';
                                        error_log("Expert Changepwd.php update execute error: " . $updateStmt->error);
                                    }
                                    $updateStmt->close();
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
                    mysqli_free_result($result);
                    $stmt->close();
                }
            } else {
                // Increment rate limiting counter on validation failure
                $_SESSION['password_change_attempts'] = ($_SESSION['password_change_attempts'] ?? 0) + 1;
                $_SESSION['password_change_last_attempt'] = $current_time;
            }
        }
    }
    } catch (Exception $e) {
        $error_message = 'An error occurred: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        error_log("Expert Changepwd.php Exception: " . $e->getMessage());
    } catch (Error $e) {
        $error_message = 'A fatal error occurred. Please try again.';
        error_log("Expert Changepwd.php Fatal Error: " . $e->getMessage());
    }
}

// Ensure we have email variable
if (!isset($email) || empty($email)) {
    $email = $_SESSION['admin_username'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Expert Committee</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --accent-green: #10b981;
            --bg-light: #f8fafc;
        }
        
        body {
            background: var(--bg-light);
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
        
        .top-bar h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }
        
        .btn-logout {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 0.5rem 1.5rem;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-logout:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        .container-main {
            max-width: 600px;
            margin: 3rem auto;
            padding: 0 2rem;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 2rem;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: #6b7280;
            margin-bottom: 2rem;
        }
        
        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .btn-primary {
            background: var(--primary-blue);
            border: none;
            padding: 0.75rem 2rem;
        }
        
        .btn-primary:hover {
            background: #1e40af;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="top-bar-content">
            <h1><i class="fas fa-user-check me-2"></i>Expert Committee Dashboard</h1>
            <div>
                <a href="dashboard.php" class="btn-logout me-2"><i class="fas fa-home me-1"></i> Dashboard</a>
                <a href="../logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
            </div>
        </div>
    </div>

    <div class="container-main">
        <div class="card">
            <h1 class="page-title">
                <i class="fas fa-key"></i> Change Password
            </h1>
            <p class="page-subtitle">Update your account password</p>
            
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
            
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" autocomplete="off" id="changePasswordForm">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
</body>
</html>

