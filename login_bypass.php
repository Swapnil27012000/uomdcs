<?php
// login_bypass.php - Direct login without OTP verification
header("X-XSS-Protection: 0");
session_start();
require('config.php');
require('maintenance_mode.php');
error_reporting(E_ALL);

// Redirect by already active sessions (role-based)
if (isset($_SESSION['admin'])) {
    header('Location: admin/Dashboard.php');
    exit;
} elseif (isset($_SESSION['dept_login'])) {
    header('Location: dept_login/dashboard.php');
    exit;
} elseif (isset($_SESSION['AAQA'])) {
    header('Location: AAQA_login/dashboard.php');
    exit;
} elseif (isset($_SESSION['MUIBEAS'])) {
    header('Location: MUIBEAS/dashboard.php');
    exit;
} elseif (isset($_SESSION['Expert_comty_login'])) {
    header('Location: Expert_comty_login/dashboard.php');
    exit;
} elseif (isset($_SESSION['Chairman_login'])) {
    header('Location: Chairman_login/dashboard.php');
    exit;
} elseif (isset($_SESSION['verification_committee'])) {
    header('Location: verification_committee/dashboard.php');
    exit;
}

require __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;

// Load ENV
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();
$siteKey = $_ENV['SITE_KEY'] ?? '';
$secretKey = $_ENV['SECRET_KEY'] ?? '';

// Sanitize input
function sanitizeInput($data, $isPassword = false)
{
    if ($isPassword) {
        return trim($data);
    }
    return htmlspecialchars(stripslashes(trim($data)));
}

$show_otp_modal = false;
$login_error = "";
$recaptcha_error = "";

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loginBtn'])) {

    // --- reCAPTCHA check ---
    if (empty($_POST['g-recaptcha-response'])) {
        $recaptcha_error = "Please complete the reCAPTCHA.";
    } else {
        $recaptchaResponse = $_POST['g-recaptcha-response'];

        $url = "https://www.google.com/recaptcha/api/siteverify";
        $data = [
            'secret'   => $secretKey,
            'response' => $recaptchaResponse,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        if ($output === false) {
            $recaptcha_error = "Unable to verify reCAPTCHA (curl error).";
        } else {
            $recaptcha = json_decode($output, true);
            if (!is_array($recaptcha) || empty($recaptcha['success'])) {
                $recaptcha_error = "reCAPTCHA verification failed.";
            }
        }
        curl_close($ch);
    }

    // If recaptcha OK, process login
    if (empty($recaptcha_error)) {
        $email = sanitizeInput($_POST['email'] ?? '');
        $password = sanitizeInput($_POST['password'] ?? '', true);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $login_error = "Invalid email format.";
        } elseif (empty($password)) {
            $login_error = "Password cannot be empty.";
        } else {
            // Unified login logic - check both tables
            $found = false;
            $table = '';
            $row = null;
            $userInfo = null;

            // Check department_master table first
            $stmt = $conn->prepare("SELECT * FROM department_master WHERE LOWER(`EMAIL`) = LOWER(?)");
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $row = $res->fetch_assoc();
                    $storedPassword = trim($row['PASS_WORD'] ?? '');
                    
                    // Check password - support both hashed and plaintext
                    $passwordMatch = false;
                    if (!empty($storedPassword) && password_verify($password, $storedPassword)) {
                        $passwordMatch = true;
                    } elseif ($password === $storedPassword) {
                        $passwordMatch = true;
                    }
                    
                    if ($passwordMatch) {
                        $found = true;
                        $table = 'department_master';
                        $userInfo = [
                            'email' => $row['EMAIL'],
                            'permission' => $row['PERMISSION'] ?? 'department',
                            'dept_id' => $row['DEPT_ID'] ?? null,
                            'dept_name' => $row['DEPT_NAME'] ?? null,
                            'table' => 'department_master'
                        ];
                    }
                }
                $stmt->close();
            }

            // Check boss table if not found in department_master
            if (!$found) {
                // First fetch user by email only (to check both hashed and plaintext passwords)
                $stmt = $conn->prepare("SELECT * FROM boss WHERE BINARY `EMAIL` = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $res->num_rows > 0) {
                        $row = $res->fetch_assoc();
                        $storedPassword = $row['PASS_WORD'];
                        
                        // Check password - support both hashed and plaintext
                        $passwordMatch = false;
                        if (password_verify($password, $storedPassword)) {
                            // Password is hashed and matches
                            $passwordMatch = true;
                        } elseif ($password === $storedPassword) {
                            // Password is plaintext and matches (backward compatibility)
                            $passwordMatch = true;
                        }
                        
                        if ($passwordMatch) {
                            $found = true;
                            $table = 'boss';
                            $userInfo = [
                                'email' => $row['EMAIL'],
                                'permission' => $row['PERMISSION'] ?? 'boss',
                                'dept_id' => null,
                                'dept_name' => null,
                                'table' => 'boss'
                            ];
                        }
                    }
                    mysqli_free_result($res);
                    $stmt->close();
                }
            }

            if ($found && $row && $userInfo) {
                // Direct login without OTP verification
                $permission = $userInfo['permission'];
                $permission_str = (string)$permission;
                $permission_lower = strtolower(trim($permission_str));
                
                // Debug logging
                error_log("Login_bypass Debug - Email: " . $email . ", Table: " . $table . ", Permission: [" . $permission_str . "], Lower: [" . $permission_lower . "]");
                
                // Set appropriate session variables based on user type
                if ($table === 'boss') {
                    // Check AAQA first (before switch) to handle any variation - use case-insensitive check
                    $permission_check = $permission_str . '|' . $permission_lower;
                    if (stripos($permission_check, 'aaqa') !== false || $permission_lower === 'aaqa_login' || $permission_lower === 'aaqa') {
                        error_log("AAQA Match Found in login_bypass.php - Setting AAQA session for: " . $email);
                        $_SESSION['AAQA'] = true;
                        $_SESSION['admin_username'] = $email;
                        header('Location: AAQA_login/dashboard.php');
                        exit;
                    }
                    
                    switch ($permission_lower) {
                        case 'boss':
                        case 'nodal':
                        case 'admin':
                            $_SESSION['admin'] = true;
                            header('Location: admin/Dashboard.php');
                            break;
                        case 'muibeas':
                            $_SESSION['MUIBEAS'] = true;
                            header('Location: MUIBEAS/dashboard.php');
                            break;
                        case 'expert_comty_login':
                        case 'expert':
                        case 'expert_committee':
                            $_SESSION['Expert_comty_login'] = true;
                            header('Location: Expert_comty_login/dashboard.php');
                            break;
                        case 'chairman_login':
                        case 'chairman':
                            $_SESSION['Chairman_login'] = true;
                            header('Location: Chairman_login/dashboard.php');
                            break;
                        case 'verification_committee':
                        case 'verification':
                            $_SESSION['verification_committee'] = true;
                            header('Location: verification_committee/dashboard.php');
                            break;
                        default:
                            // Log unknown permission for debugging
                            error_log("Unknown permission in login_bypass.php: " . $permission . " (lower: " . $permission_lower . ") for user: " . $email);
                            // Set generic user session as fallback
                            $_SESSION['userus'] = $email;
                            $_SESSION['admin_username'] = $email;
                            header('Location: user/dashboard.php');
                    }
                } else {
                    // department_master table users
                    $_SESSION['dept_login'] = true;
                    header('Location: dept_login/dashboard.php');
                }
                
                // Set common session variables
                $_SESSION['admin_username'] = $email;
                if (isset($userInfo['dept_id'])) $_SESSION['dept_id'] = $userInfo['dept_id'];
                if ($permission) $_SESSION['permission'] = $permission;
                
                exit;
            } else {
                error_log("Login_bypass - Failed login attempt for: $email - User not found or password mismatch.");
                $login_error = "Invalid email or password. Please check your credentials.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Direct Login - MU NIRF Portal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        purple: {
                            500: '#8B5CF6',
                            600: '#7C3AED',
                            700: '#6D28D9'
                        }
                    }
                }
            }
        }
    </script>
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

        .user-type-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin: 2px;
        }

        .badge-admin {
            background: #ff6b6b;
            color: white;
        }

        .badge-dept {
            background: #4ecdc4;
            color: white;
        }

        .badge-aaqa {
            background: #45b7d1;
            color: white;
        }

        .badge-muibeas {
            background: #96ceb4;
            color: white;
        }

        .badge-expert {
            background: #feca57;
            color: white;
        }

        .badge-chairman {
            background: #ff9ff3;
            color: white;
        }

        .badge-verification {
            background: #ffa502;
            color: white;
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
        <div class="grid md:grid-cols-3 gap-8 max-w-6xl w-full">

            <!-- User Types Info -->
            <div class="login-card p-6">
                <h2 class="text-xl font-bold text-purple-700 mb-4">Supported User Types</h2>
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span>Administrators</span>
                        <span class="user-type-badge badge-admin">Admin</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>Departments</span>
                        <span class="user-type-badge badge-dept">Dept</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>AAQA</span>
                        <span class="user-type-badge badge-aaqa">AAQA</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>MUIBEAS</span>
                        <span class="user-type-badge badge-muibeas">MUIBEAS</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>Expert Committee</span>
                        <span class="user-type-badge badge-expert">Expert</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>Chairman</span>
                        <span class="user-type-badge badge-chairman">Chairman</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>Verification Committee</span>
                        <span class="user-type-badge badge-verification">Verification</span>
                    </div>
                </div>
                <div class="mt-4 p-3 bg-blue-50 rounded-lg">
                    <p class="text-sm text-blue-800">
                        <strong>Note:</strong> All user types use the same login form.
                        Your dashboard will be determined automatically based on your assigned permissions.
                    </p>
                </div>
            </div>

            <!-- Login Form -->
            <div class="login-card ">
                <form action="" method="POST" class="space-y-5 bg-white p-8 rounded-xl shadow-lg">
                    <div class="text-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-800">Direct Login</h2>
                        <p class="text-gray-600 mt-2">Bypass OTP Verification</p>
                    </div>

                    <?php if (!empty($login_error)) { ?>
                        <div id="loginError" class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
                            <div class="flex">
                                <div class="shrink-0">
                                    <i class="fas fa-exclamation-circle"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="font-medium"><?php echo $login_error; ?></p>
                                </div>
                            </div>
                        </div>
                        <script>
                            setTimeout(() => {
                                $("#loginError").fadeOut('slow');
                            }, 5000);
                        </script>
                    <?php } ?>
                    <?php if (!empty($recaptcha_error)): ?>
                        <div id="recaptchaError" class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded">
                            <div class="flex">
                                <div class="shrink-0">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="font-medium"><?= $recaptcha_error ?></p>
                                </div>
                            </div>
                        </div>
                        <script>
                            setTimeout(() => {
                                document.getElementById("recaptchaError").style.display = "none";
                            }, 5000);
                        </script>
                    <?php endif; ?>

                    <div class="space-y-6">
                        <div class="space-y-2">
                            <label for="email" class="text-sm font-medium text-gray-700">Email Address</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-envelope text-gray-400"></i>
                                </div>
                                <input type="email" id="email" name="email" placeholder="Enter your email address" required
                                    class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent outline-none transition duration-200">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label for="password" class="text-sm font-medium text-gray-700">Password</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-lock text-gray-400"></i>
                                </div>
                                <input type="password" id="password" name="password" placeholder="Enter your password" required
                                    class="w-full pl-10 pr-10 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent outline-none transition duration-200">
                                <button type="button" class="toggle-password absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                    <i class="fas fa-eye show-icon"></i>
                                    <i class="fas fa-eye-slash hide-icon hidden"></i>
                                </button>
                            </div>
                        </div>

                        <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($siteKey); ?>"></div>

                        <button name="loginBtn" type="submit"
                            class="w-full bg-purple-600 hover:bg-purple-700 text-white py-3 px-4 rounded-lg font-semibold transition duration-200 transform hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2">
                            <i class="fas fa-sign-in-alt mr-2"></i> Direct Login
                        </button>
                    </div>

                    <div class="pt-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <a href="unified_login.php" class="text-sm text-purple-600 hover:text-purple-700 flex items-center">
                                <i class="fas fa-arrow-left mr-2"></i> Regular Login
                            </a>
                            <a href="forgot_password.php" class="text-sm text-purple-600 hover:text-purple-700 flex items-center">
                                Forgot Password? <i class="fas fa-key ml-2"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Instructions -->
            <div class="login-card p-6">
                <h2 class="text-xl font-bold text-purple-700 mb-4">Instructions</h2>
                <div class="space-y-3 text-sm text-gray-700">
                    <p><strong>1.</strong> India Ranking NIRF 2026 & UDRF 2025 data capturing system of University of Mumbai is now open.</p>
                    <p><strong>2.</strong> The last date of submission is <span class="font-semibold text-red-600">yet to be announced</span></p>
                    <p><strong>3.</strong> For operational guidance, please refer to this link
                        <?php
                        $file_path = "assets/files/Nirf_Sample_Data.pdf";
                        if (file_exists($file_path)) {
                            echo '<a href="' . $file_path . '" download class="text-purple-600 hover:underline font-medium">Click here</a>';
                        } else {
                            // echo '<span class="text-red-500">File not found.</span>';
                            echo '<a href="generate_operational_guide.php" class="text-purple-600 hover:underline font-medium">Generate Guide</a>';
                        }
                        ?>
                    </p>
                </div>

                <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                    <h3 class="font-bold text-gray-800 mb-2">Helpdesk</h3>
                    <p class="text-sm text-gray-700">
                        For technical queries, contact us at<br>
                        <a href="mailto:techsupport.nirf@mu.ac.in" class="text-purple-600 hover:underline font-medium">
                            techsupport.nirf@mu.ac.in
                        </a>
                    </p>
                </div>
            </div>
        </div>

        <?php 
        // include 'chatbot_widget.php'; 
        ?>

    </main>
    
        

    <!-- Footer -->
    <footer class="bg-gray-800 bg-opacity-90 text-white text-center py-3 text-sm">
        © <?php echo date("Y"); ?> University of Mumbai - NIRF Portal
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Password visibility toggle
        document.querySelector('.toggle-password').addEventListener('click', function(e) {
            e.preventDefault();
            const input = this.parentNode.querySelector('input');
            const showIcon = this.querySelector('.fa-eye');
            const hideIcon = this.querySelector('.fa-eye-slash');

            if (input.type === 'password') {
                input.type = 'text';
                showIcon.classList.add('hidden');
                hideIcon.classList.remove('hidden');
            } else {
                input.type = 'password';
                showIcon.classList.remove('hidden');
                hideIcon.classList.add('hidden');
            }

            input.focus();
        });
    </script>

   
</body>

</html>