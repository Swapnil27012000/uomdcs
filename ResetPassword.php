<?php

include 'config.php'; // Include database configuration

session_start();
error_reporting(0);

if (!isset($_GET["token"])) {
    die("Access denied. No token provided.");
}

require_once 'encryption_util.php';
EncryptionUtil::initialize();

$encrypted_token = $_GET["token"];
$token = EncryptionUtil::decrypt($encrypted_token);

if ($token === false) {
    die("Invalid token format.");
}

$mysql = $conn; // Ensure $conn is initialized in config.php

// Function to check for expired token
function isTokenExpired($expiry_time) {
    if (!$expiry_time) return true;
    $expiry = strtotime($expiry_time);
    $current = time();
    return $current > $expiry;
}

// Function to display error page
function showErrorPage($message) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Link Expired</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container">
            <div class="row justify-content-center mt-5">
                <div class="col-md-6">
                    <div class="card shadow">
                        <div class="card-body text-center">
                            <h3 class="card-title text-danger mb-4">Link Expired</h3>
                            <p class="card-text mb-4">' . htmlspecialchars($message) . '</p>
                            <a href="unified_login.php" class="btn btn-primary">Return to Login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

// First check department_master table
$sql = "SELECT EMAIL, RESET_TOKEN_EXPIRE FROM department_master WHERE RESET_PASSWORD = ?";
$stmt = $mysql->prepare($sql);

if (!$stmt) {
    showErrorPage("Database error. Please try again later.");
}

$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// If not found in department_master, check boss table
if (!$user) {
    $sql = "SELECT EMAIL, RESET_TOKEN_EXPIRE FROM boss WHERE RESET_PASSWORD = ?";
    $stmt = $mysql->prepare($sql);
    
    if (!$stmt) {
        showErrorPage("Database error. Please try again later.");
    }
    
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
}

// Check if token exists and is valid
if (!$user) {
    showErrorPage("This password reset link is invalid or has already been used.");
}

// Check if token has expired
if (isTokenExpired($user['RESET_TOKEN_EXPIRE'])) {
    showErrorPage("This password reset link has expired. Please request a new password reset.");
}

// if (!$user) {
//     die("Invalid or expired password reset token.");
// }

// echo "Token is valid. You can reset your password.";

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - MU NIRF Portal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="assets/img/mumbai-university-removebg-preview.png" type="image/png">
    <style>
        .login-container {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            transition: transform 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            background: linear-gradient(135deg, #5a6fd6 0%, #6a4494 100%);
        }
    </style>
</head>
<body class="login-container">
    <!-- Header -->
    <header class="flex justify-between items-center px-8 py-4 bg-white bg-opacity-90 backdrop-blur-sm">
        <img src="assets/img/mumbai-university-removebg-preview.png" class="h-16" alt="MU Logo">
        <h1 class="text-3xl font-bold font-serif text-gray-800">University Of Mumbai</h1>
        <img src="assets/img/nirf-full-removebg-preview.png" class="h-14" alt="NIRF Logo">
    </header>

    <!-- Main Content -->
    <main class="grow flex items-center justify-center px-6 py-10">
        <div class="login-card p-8 max-w-md w-full">
            <h2 class="text-2xl font-bold text-center text-gray-800 mb-6">Reset Your Password</h2>
            
            <form action="ProcessResetPassword.php" method="post" class="space-y-6">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="mb-4">
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                    <div class="relative">
                        <input type="password" id="password" name="password" 
                               class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-purple-500 focus:ring-2 focus:ring-purple-200" 
                               placeholder="Enter your new password" required>
                        <button type="button" class="toggle-password absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-600 hover:text-gray-800">
                            <svg class="w-5 h-5 show-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            <svg class="w-5 h-5 hide-icon hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                    <div class="relative">
                        <input type="password" id="confirm_password" name="confirm_password" 
                               class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-purple-500 focus:ring-2 focus:ring-purple-200" 
                               placeholder="Re-enter your new password" required>
                        <button type="button" class="toggle-password absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-600 hover:text-gray-800">
                            <svg class="w-5 h-5 show-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            <svg class="w-5 h-5 hide-icon hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="flex flex-col gap-4">
                    <button type="submit" 
                            class="w-full btn btn-primary py-3 rounded-lg text-white font-semibold hover:bg-purple-600 transition duration-200">
                        Reset Password
                    </button>
                    
                    <a href="unified_login.php" 
                       class="text-center text-purple-600 hover:text-purple-700 font-medium transition duration-200">
                        Back to Login
                    </a>
                </div>
            </form>

            <div class="mt-8 p-4 bg-blue-50 rounded-lg">
                <p class="text-sm text-blue-800">
                    Please choose a strong password that includes at least 8 characters with a mix of letters, numbers, and special characters.
                </p>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 bg-opacity-90 text-white text-center py-3 text-sm">
        © <?php echo date("Y"); ?> University of Mumbai - NIRF Portal
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Password validation
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const form = document.querySelector('form');

        function isStrongPassword(password) {
            // At least 8 characters
            if (password.length < 8) return false;
            
            // Must contain at least one lowercase letter
            if (!/[a-z]/.test(password)) return false;
            
            // Must contain at least one uppercase letter
            if (!/[A-Z]/.test(password)) return false;
            
            // Must contain at least one number
            if (!/[0-9]/.test(password)) return false;
            
            // Must contain at least one special character
            if (!/[!@#$%^&*(),.?":{}|<>]/.test(password)) return false;
            
            return true;
        }

        function validatePassword() {
            const passwordValue = password.value;
            const feedback = document.getElementById('password-feedback');
            
            if (!isStrongPassword(passwordValue)) {
                password.setCustomValidity("Password must be at least 8 characters long and include uppercase, lowercase, numbers, and special characters.");
                if (feedback) {
                    feedback.style.display = 'block';
                }
            } else {
                password.setCustomValidity('');
                if (feedback) {
                    feedback.style.display = 'none';
                }
            }

            // Check if passwords match
            if (passwordValue !== confirmPassword.value) {
                confirmPassword.setCustomValidity("Passwords don't match");
            } else {
                confirmPassword.setCustomValidity('');
            }
        }

        // Add password strength indicator div after password input
        const strengthIndicator = document.createElement('div');
        strengthIndicator.id = 'password-feedback';
        strengthIndicator.className = 'mt-2 text-sm';
        password.parentNode.insertBefore(strengthIndicator, password.nextSibling);

        function updateStrengthIndicator() {
            const passwordValue = password.value;
            const feedback = document.getElementById('password-feedback');
            
            if (!passwordValue) {
                feedback.className = 'mt-2 text-sm';
                feedback.textContent = '';
                return;
            }

            const checks = {
                length: passwordValue.length >= 8,
                lowercase: /[a-z]/.test(passwordValue),
                uppercase: /[A-Z]/.test(passwordValue),
                number: /[0-9]/.test(passwordValue),
                special: /[!@#$%^&*(),.?":{}|<>]/.test(passwordValue)
            };

            let missingRequirements = [];
            if (!checks.length) missingRequirements.push('at least 8 characters');
            if (!checks.lowercase) missingRequirements.push('lowercase letter');
            if (!checks.uppercase) missingRequirements.push('uppercase letter');
            if (!checks.number) missingRequirements.push('number');
            if (!checks.special) missingRequirements.push('special character');

            if (missingRequirements.length > 0) {
                feedback.className = 'mt-2 text-sm text-red-600';
                feedback.textContent = `Missing: ${missingRequirements.join(', ')}`;
            } else {
                feedback.className = 'mt-2 text-sm text-green-600';
                feedback.textContent = 'Password meets all requirements!';
            }
        }

        // Event listeners
        password.addEventListener('input', updateStrengthIndicator);
        password.addEventListener('change', validatePassword);
        confirmPassword.addEventListener('input', validatePassword);

        // Form submission validation
        form.addEventListener('submit', function(e) {
            validatePassword();
            if (!isStrongPassword(password.value)) {
                e.preventDefault();
                alert('Please ensure your password meets all the requirements.');
            }
        });

        // Password visibility toggle
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const input = this.parentNode.querySelector('input');
                const showIcon = this.querySelector('.show-icon');
                const hideIcon = this.querySelector('.hide-icon');
                
                // Toggle password visibility
                if (input.type === 'password') {
                    input.type = 'text';
                    showIcon.classList.add('hidden');
                    hideIcon.classList.remove('hidden');
                } else {
                    input.type = 'password';
                    showIcon.classList.remove('hidden');
                    hideIcon.classList.add('hidden');
                }
                
                // Maintain focus on the input
                input.focus();
            });
        });
    </script>
</body>
</html>

