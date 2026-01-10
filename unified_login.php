<?php
// unified_login.php - Single login page for all user types
// CRITICAL: Add error handling for production server issues

// Start output buffering to catch any errors
if (ob_get_level() === 0) {
    ob_start();
}

// Set error reporting based on environment
if (isset($_SERVER['SERVER_NAME']) && strpos($_SERVER['SERVER_NAME'], 'localhost') === false) {
    // Production server - log errors but don't display
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
} else {
    // Local development - show errors
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

header("X-XSS-Protection: 0");
session_start();

// CRITICAL: Load config.php with error handling
try {
    if (!file_exists(__DIR__ . '/config.php')) {
        throw new Exception('config.php file not found. Please contact administrator.');
    }
    require(__DIR__ . '/config.php');
} catch (Exception $e) {
    ob_end_clean();
    die("<!DOCTYPE html><html><head><title>Configuration Error</title></head><body style='font-family: Arial; padding: 40px; text-align: center;'><h1 style='color: #d32f2f;'>Configuration Error</h1><p>" . htmlspecialchars($e->getMessage()) . "</p><p>Please contact the administrator.</p></body></html>");
} catch (Error $e) {
    ob_end_clean();
    die("<!DOCTYPE html><html><head><title>Configuration Error</title></head><body style='font-family: Arial; padding: 40px; text-align: center;'><h1 style='color: #d32f2f;'>Configuration Error</h1><p>Failed to load configuration file.</p><p>Please contact the administrator.</p></body></html>");
}

// Redirect by already active sessions (role-based)
if (isset($_SESSION['admin'])) {
    echo '<script>top.location = "admin/Dashboard.php";</script>';
    exit;
} elseif (isset($_SESSION['dept_login'])) {
    echo '<script>top.location = "dept_login/dashboard.php";</script>';
    exit;
} elseif (isset($_SESSION['AAQA'])) {
    echo '<script>top.location = "AAQA_login/dashboard.php";</script>';
    exit;
} elseif (isset($_SESSION['MUIBEAS'])) {
    echo '<script>top.location = "MUIBEAS/dashboard.php";</script>';
    exit;
} elseif (isset($_SESSION['Expert_comty_login'])) {
    echo '<script>top.location = "Expert_comty_login/dashboard.php";</script>';
    exit;
} elseif (isset($_SESSION['Chairman_login'])) {
    echo '<script>top.location = "Chairman_login/dashboard.php";</script>';
    exit;
} elseif (isset($_SESSION['verification_committee'])) {
    echo '<script>top.location = "verification_committee/dashboard.php";</script>';
    exit;
}

// CRITICAL: Load vendor/autoload.php with error handling
$vendor_path = __DIR__ . '/vendor/autoload.php';
if (!file_exists($vendor_path)) {
    ob_end_clean();
    die("<!DOCTYPE html><html><head><title>Missing Dependencies</title></head><body style='font-family: Arial; padding: 40px; text-align: center;'><h1 style='color: #d32f2f;'>Missing Dependencies</h1><p>vendor/autoload.php not found. Please run <code>composer install</code> on the server.</p><p>Please contact the administrator.</p></body></html>");
}

try {
    require $vendor_path;
} catch (Exception $e) {
    ob_end_clean();
    die("<!DOCTYPE html><html><head><title>Dependency Error</title></head><body style='font-family: Arial; padding: 40px; text-align: center;'><h1 style='color: #d32f2f;'>Dependency Error</h1><p>Failed to load dependencies: " . htmlspecialchars($e->getMessage()) . "</p><p>Please contact the administrator.</p></body></html>");
} catch (Error $e) {
    ob_end_clean();
    die("<!DOCTYPE html><html><head><title>Dependency Error</title></head><body style='font-family: Arial; padding: 40px; text-align: center;'><h1 style='color: #d32f2f;'>Dependency Error</h1><p>Failed to load dependencies.</p><p>Please contact the administrator.</p></body></html>");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Dotenv\Dotenv;

// CRITICAL: Load .env file with error handling
try {
    $env_path = __DIR__ . '/.env';
    if (!file_exists($env_path)) {
        // .env file is missing - this is critical
        error_log("CRITICAL: .env file not found at: $env_path");
        // Don't die - allow page to load but log error
        // Some features may not work without .env
    } else {
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();
    }
} catch (Exception $e) {
    error_log("Error loading .env file: " . $e->getMessage());
    // Continue - some features may not work
} catch (Error $e) {
    error_log("Fatal error loading .env file: " . $e->getMessage());
    // Continue - some features may not work
}

$siteKey   = $_ENV['SITE_KEY'] ?? '';
$secretKey = $_ENV['SECRET_KEY'] ?? '';

// Helper: send OTP email
function sendOTP($email, $otp)
{
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'] ?? '';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'] ?? '';
        $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);

        $mail->setFrom($_ENV['SMTP_USER'], 'MU NIRF Portal');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Your OTP for MU NIRF Portal Login';

        // HTML email body
        $mail->Body =
            "<!doctype html>
            <html lang='en'>
            <head>
            <meta charset='utf-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>UOMDCS OTP Email</title>
            <style>
            body { font-family: 'Segoe UI', Roboto, Arial, sans-serif; background-color: #eef2f6; margin: 0; padding: 0; }
            .wrapper { max-width: 650px; margin: 40px auto; background: #ffffff; border-radius: 16px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); overflow: hidden; }
            .header { background: linear-gradient(90deg, #004aad, #0077ff); color: #fff; text-align: center; padding: 24px; }
            .header h1 { font-size: 20px; margin: 0; letter-spacing: 0.5px; }
            .content { padding: 30px; color: #222; line-height: 1.6; }
            .otp-container { text-align: center; margin: 30px 0; }
            .otp { display: inline-block; background: #f1f5ff; border: 2px solid #004aad; color: #004aad; padding: 16px 32px; border-radius: 10px; font-size: 28px; letter-spacing: 6px; font-weight: 700; }
            .info-box { background: #f9fafc; padding: 16px 20px; border-left: 4px solid #0077ff; margin: 20px 0; border-radius: 8px; }
            a { color: #0077ff; text-decoration: none; }
            .footer { background: #f5f7fa; text-align: center; padding: 18px; font-size: 13px; color: #555; }
            .footer strong { color: #222; }
            </style>
            </head>
            <body>
            <div class='wrapper'>
            <div class='header'>
            <h1>University of Mumbai - Department of Information Technology</h1>
            </div>


            <div class='content'>
            <p>Dear <strong>User</strong>,</p>
            <p>Your One Time Password (OTP) for login verification on <strong>UOMDCS Portal</strong> is:</p>


            <div class='otp-container'>
            <div class='otp'>$otp</div>
            </div>


            <p>This OTP is valid for <strong>5 minutes</strong> and can be used only for the current login attempt. Please <strong>do not share</strong> this OTP with anyone.</p>


            <div class='info-box'>
            <p>If you did not request this OTP, please ignore this email or contact our support team immediately.</p>
            </div>


            <p>For any queries or support, you can contact us at:</p>
            <ul>
            <li><strong>Email:</strong> <a href='mailto:techsupport.nirf@mu.ac.in'>techsupport.nirf@mu.ac.in</a></li>
            <li><strong>Website:</strong> <a href='https://uomdcs.univofmumbai.in'>uomdcs.univofmumbai.in</a></li>
            </ul>


            <p>Thank you for your trust in our services.<br>
            <strong>- UOMDCS Support Team</strong></p>
            </div>


            <div class='footer'>
            ******************************* Information *******************************<br>
            Do not share your OTP, password, or personal details with anyone. UOMDCS will never ask for such information.<br><br>
            <strong>Warm Regards,</strong><br>
            Technical Support Team<br>
            Department of Information Technology<br>
            University of Mumbai (UOMDCS)
            </div>
            </div>
            </body>
            </html>";
        // **************
        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log("Mailer Error: " . $e->getMessage());
        return false;
    }
}

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
            // Use LOWER() for case-insensitive email matching
            $stmt = $conn->prepare("SELECT * FROM department_master WHERE LOWER(`EMAIL`) = LOWER(?)");
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $row = $res->fetch_assoc();
                    $storedPassword = trim($row['PASS_WORD'] ?? '');
                    $inputPassword = $password;
                    
                    // Check password - support both hashed and plaintext
                    $passwordMatch = false;
                    if (!empty($storedPassword) && password_verify($inputPassword, $storedPassword)) {
                        $passwordMatch = true;
                    } elseif ($inputPassword === $storedPassword) {
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
                // Use LOWER() for case-insensitive email matching (email addresses are case-insensitive per RFC)
                $stmt = $conn->prepare("SELECT * FROM boss WHERE LOWER(`EMAIL`) = LOWER(?)");
                if ($stmt) {
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $res->num_rows > 0) {
                        $row = $res->fetch_assoc();
                        $storedPassword = trim($row['PASS_WORD'] ?? '');
                        $inputPassword = trim($password);
                        
                        // Debug logging (first 10 chars of stored password for security)
                        $passwordPreview = strlen($storedPassword) > 10 ? substr($storedPassword, 0, 10) . '...' : substr($storedPassword, 0, 10);
                        error_log("Unified Login Debug - Email: $email, Found in boss table, Stored password preview: $passwordPreview, Length: " . strlen($storedPassword));
                        
                        // Check password - support both hashed and plaintext
                        $passwordMatch = false;
                        $passwordCheckMethod = '';
                        
                        // Try password_verify first (for hashed passwords)
                        if (!empty($storedPassword) && password_verify($inputPassword, $storedPassword)) {
                            // Password is hashed and matches
                            $passwordMatch = true;
                            $passwordCheckMethod = 'password_verify (hashed)';
                            error_log("Unified Login - Password verified via password_verify for: $email");
                        } elseif ($inputPassword === $storedPassword) {
                            // Password is plaintext and matches (backward compatibility)
                            $passwordMatch = true;
                            $passwordCheckMethod = 'direct comparison (plaintext)';
                            error_log("Unified Login - Password verified via direct comparison for: $email");
                        } else {
                            error_log("Unified Login - Password verification FAILED for: $email, Method tried: password_verify and direct comparison");
                        }
                        
                        if ($passwordMatch) {
                            $found = true;
                            $table = 'boss';
                            $permission = $row['PERMISSION'] ?? 'boss';
                            error_log("Unified Login - Password match successful for: $email, Permission: $permission, Method: $passwordCheckMethod");
                            $userInfo = [
                                'email' => $row['EMAIL'],
                                'permission' => $permission,
                                'dept_id' => null,
                                'dept_name' => null,
                                'table' => 'boss'
                            ];
                        }
                    } else {
                        error_log("Unified Login - User not found in boss table for email: $email");
                    }
                    if ($res) {
                        mysqli_free_result($res);
                    }
                    $stmt->close();
                } else {
                    error_log("Unified Login - Failed to prepare statement for boss table lookup");
                }
            }

            if ($found && $row && $userInfo) {
                // Generate OTP and expiry
                $otp = str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);
                $otp_expiry = date("Y-m-d H:i:s", time() + 300); // 5 minutes

                // Store OTP in DB for the correct table
                if ($table === 'department_master') {
                    $stmtUp = $conn->prepare("UPDATE department_master SET otp = ?, otp_expiry = ? WHERE EMAIL = ?");
                } else {
                    $stmtUp = $conn->prepare("UPDATE boss SET otp = ?, otp_expiry = ? WHERE EMAIL = ?");
                }

                if ($stmtUp) {
                    $stmtUp->bind_param("sss", $otp, $otp_expiry, $email);
                    $stmtUp->execute();
                    $stmtUp->close();
                }

                // Store temporary session values for OTP verification
                $_SESSION['login_otp'] = $otp;
                $_SESSION['otp_expiry'] = strtotime($otp_expiry);
                $_SESSION['pending_email'] = $userInfo['email'];
                $_SESSION['pending_permission'] = $userInfo['permission'];
                $_SESSION['pending_dept_id'] = $userInfo['dept_id'];
                $_SESSION['pending_table'] = $userInfo['table'];

                // Send OTP email
                if (sendOTP($userInfo['email'], $otp)) {
                    $show_otp_modal = true;
                } else {
                    $login_error = "Failed to send OTP email. Please try again later.";
                }
            } else {
                error_log("Unified Login - Failed login attempt for: $email - User not found in any table or password mismatch.");
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
    <title>MU NIRF Portal - Unified Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <script src="https://cdn.tailwindcss.com"></script>
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
    <main class="flex-grow flex items-center justify-center px-6 py-10">
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
            </div>

            <!-- Login Form -->
            <div class="login-card p-6">
                <form action="" method="POST" class="space-y-5">
                    <?php if (!empty($login_error)) { ?>
                        <div id="loginError" class="alert alert-danger text-center bg-red-100 text-red-800 p-3 rounded-lg">
                            <?php echo $login_error; ?>
                        </div>
                        <script>
                            setTimeout(() => {
                                $("#loginError").fadeOut('slow');
                            }, 5000);
                        </script>
                    <?php } ?>
                    <?php if (!empty($recaptcha_error)): ?>
                        <div id="recaptchaError" class="bg-red-100 text-red-800 p-3 rounded-lg font-bold">
                            <?= $recaptcha_error ?>
                        </div>
                        <script>
                            setTimeout(() => {
                                document.getElementById("recaptchaError").style.display = "none";
                            }, 5000);
                        </script>
                    <?php endif; ?>

                    <h2 class="text-2xl font-bold text-center text-gray-800 mb-6">Unified Login</h2>

                    <div class="space-y-4">
                        <input type="email" name="email" placeholder="Enter your email address" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-400 focus:border-transparent outline-none transition-all">

                        <div class="relative">
                            <input type="password" name="password" placeholder="Enter your password" required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-400 focus:border-transparent outline-none transition-all">
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

                        <div class="g-recaptcha mb-3" data-sitekey="<?php echo htmlspecialchars($siteKey); ?>"></div>

                        <button name="loginBtn" type="submit"
                            class="w-full bg-gradient-to-r from-purple-500 to-purple-600 text-white py-3 rounded-lg font-semibold hover:from-purple-600 hover:to-purple-700 transition-all transform hover:scale-105 shadow-lg">
                            Login to Portal
                        </button>
                    </div>

                    <div class="text-center">
                        <p class="text-sm text-gray-600">
                            Forgot Password?
                            <a href="forgot_password.php" class="text-purple-600 hover:underline font-medium">Reset Here</a>
                        </p>
                    </div>
                </form>
            </div>

            <!-- Instructions -->
            <div class="login-card p-6">
                <h2 class="text-xl font-bold text-purple-700 mb-4">Instructions</h2>
                <div class="space-y-3 text-sm text-gray-700">
                    <p><strong>1.</strong> India Ranking NIRF 2026 & UDRF 2025 data capturing system of University of Mumbai is now open.</p>
                    <p><strong>2.</strong> The last date of submission is <span class="font-semibold text-red-600">10th December 2025</span></p>
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

        <!-- OTP Modal -->
        <div class="modal fade" id="otpModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content p-4">
                    <button type="button" class="btn-close position-absolute top-3 end-3" data-bs-dismiss="modal" aria-label="Close"></button>
                    <div class="text-center mb-4">
                        <h5 class="modal-title text-xl font-bold">Enter OTP</h5>
                        <p class="text-sm text-gray-600 mt-2">We've sent a 6-digit code to your email</p>
                    </div>
                    <div id="otpError" class="alert alert-danger text-center d-none bg-red-100 text-red-800 p-3 rounded-lg"></div>
                    <form id="otpForm">
                        <input type="text" name="otp" maxlength="6" class="form-control text-center mb-3 text-2xl font-bold tracking-widest"
                            placeholder="000000" required>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success py-2">Verify OTP</button>
                        </div>
                    </form>
                    <div class="text-center mt-3">
                        <p id="otpTimer" class="fw-bold text-danger"></p>
                        <button id="resendOtp" class="btn btn-link d-none">Resend OTP</button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 bg-opacity-90 text-white text-center py-3 text-sm">
        © <?php echo date("Y"); ?> University of Mumbai - NIRF Portal
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Auto show OTP modal if PHP flagged it
        <?php if (!empty($show_otp_modal)) { ?>
            var otpModal = new bootstrap.Modal(document.getElementById('otpModal'));
            otpModal.show();

            // Start countdown timer
            let timeLeft = 300; // 300 means 5 minutes 
            const timerElement = document.getElementById('otpTimer');
            const resendButton = document.getElementById('resendOtp');

            const timer = setInterval(() => {
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                timerElement.textContent = `OTP expires in: ${minutes}:${seconds.toString().padStart(2, '0')}`;

                if (timeLeft <= 0) {
                    clearInterval(timer);
                    timerElement.textContent = 'OTP has expired';
                    resendButton.classList.remove('d-none');
                }
                timeLeft--;
            }, 1000);
        <?php } ?>

        // OTP form submit via AJAX
        $("#otpForm").on("submit", function(e) {
            e.preventDefault();
            $.ajax({
                url: "verify_otp.php",
                type: "POST",
                data: $(this).serialize(),
                dataType: "json",
                success: function(response) {
                    console.log("OTP Response:", response); // Debug log
                    if (response.status === "success") {
                        console.log("Redirecting to:", response.redirect); // Debug log
                        if (response.debug) {
                            console.log("Debug Info:", response.debug); // Debug log
                        }
                        window.location.href = response.redirect;
                    } else {
                        $("#otpError").removeClass("d-none").text(response.message || 'Verification failed.');
                    }
                },
                error: function() {
                    $("#otpError").removeClass("d-none").text('Server error. Try again.');
                }
            });
        });

        // Auto-format OTP input
        $('input[name="otp"]').on('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Resend OTP button handler
        $('#resendOtp').on('click', function() {
            const button = $(this);
            button.prop('disabled', true).text('Sending...');
            
            $.ajax({
                url: 'resend_otp.php',
                type: 'POST',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Reset timer
                        timeLeft = 300; // 5 minutes
                        button.addClass('d-none');
                        
                        // Show success message
                        $("#otpError")
                            .removeClass("d-none alert-danger")
                            .addClass("alert-success")
                            .text(response.message);
                            
                        // Restart countdown
                        const timer = setInterval(() => {
                            const minutes = Math.floor(timeLeft / 60);
                            const seconds = timeLeft % 60;
                            timerElement.textContent = `OTP expires in: ${minutes}:${seconds.toString().padStart(2, '0')}`;

                            if (timeLeft <= 0) {
                                clearInterval(timer);
                                timerElement.textContent = 'OTP has expired';
                                button.removeClass('d-none').prop('disabled', false).text('Resend OTP');
                            }
                            timeLeft--;
                        }, 1000);
                    } else {
                        // Show error message
                        $("#otpError")
                            .removeClass("d-none alert-success")
                            .addClass("alert-danger")
                            .text(response.message);
                        button.prop('disabled', false).text('Resend OTP');
                    }
                },
                error: function() {
                    $("#otpError")
                        .removeClass("d-none alert-success")
                        .addClass("alert-danger")
                        .text('Server error. Please try again.');
                    button.prop('disabled', false).text('Resend OTP');
                }
            });
        });

        // Password visibility toggle
        document.querySelector('.toggle-password').addEventListener('click', function(e) {
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
    </script>
</body>

</html>