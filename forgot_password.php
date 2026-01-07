<?php
session_start();
error_reporting(0);

require __DIR__ . '/vendor/autoload.php';
include 'config.php';

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

if (isset($_POST['submit'])) {
    $email = trim($_POST['email']);

    // Check if email exists in department_master
    $stmt = $conn->prepare("SELECT * FROM department_master WHERE EMAIL = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $table = 'department_master';

    // If not found in department_master, check boss table
    if ($result->num_rows === 0) {
        $stmt = $conn->prepare("SELECT * FROM boss WHERE EMAIL = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $table = 'boss';
    }

    if ($result->num_rows > 0) {

        // Generate secure token and expiry
        $token = bin2hex(random_bytes(32));
        $exp_time = date("Y-m-d H:i:s", strtotime('+1 hour'));

        // Save token to database
        if ($table === 'department_master') {
            $update_stmt = $conn->prepare("UPDATE department_master SET RESET_PASSWORD = ?, RESET_TOKEN_EXPIRE = ? WHERE EMAIL = ?");
        } else {
            $update_stmt = $conn->prepare("UPDATE boss SET RESET_PASSWORD = ?, RESET_TOKEN_EXPIRE = ? WHERE EMAIL = ?");
        }
        $update_stmt->bind_param("sss", $token, $exp_time, $email);
        $update_stmt->execute();

        require_once 'encryption_util.php';
        EncryptionUtil::initialize();
        $encrypted_token = EncryptionUtil::encrypt($token);

        // Reset link with encrypted token
        $reset_link = $port_link . "/" . "ResetPassword.php?token=" . urlencode($encrypted_token);

        try {
            // SMTP Config
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['SMTP_USER'];
            $mail->Password   = $_ENV['SMTP_PASS'];
            $mail->SMTPSecure = 'tls';
            $mail->Port       = $_ENV['SMTP_PORT'];

            // Sender & Recipient
            $mail->setFrom($_ENV['SMTP_FROM'], $_ENV['SMTP_FROM_NAME']);
            $mail->addAddress($email);

            // Content
            $mail->isHTML(true);
            $mail->Subject = "Password Reset Request";
            $mail->Body    = "
                <p>Dear User,</p>
                <p>We received a request to reset your password for the <b>UoM Centralized DCS Ranking Portal</b>.</p>
                <p>Please click the link below to reset your password (valid for 1 hour):</p>
                <p><a href='$reset_link'>$reset_link</a></p>
                <p>If you did not request this, you can safely ignore this email.</p>
                <br>
                <p>Regards,<br>University of Mumbai</p>
            ";

            $mail->send();
            echo "<script>alert('Password reset link has been sent to your email.'); window.location='index.php';</script>";
        } catch (Exception $e) {
            echo "<script>alert('Email could not be sent. Mailer Error: {$mail->ErrorInfo}');</script>";
        }
    } else {
        echo "<script>alert('Email not found.');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - MU NIRF Portal</title>
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
    <main class="flex-grow flex items-center justify-center px-6 py-10">
        <div class="login-card p-8 max-w-md w-full">
            <h2 class="text-2xl font-bold text-center text-gray-800 mb-6">Password Reset</h2>
            
            <form method="POST" action="" class="space-y-6">
                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                    <input type="email" id="email" name="email" 
                           class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-purple-500 focus:ring-2 focus:ring-purple-200" 
                           placeholder="Enter your registered email" required>
                </div>

                <div class="flex flex-col gap-4">
                    <button type="submit" name="submit" 
                            class="w-full btn btn-primary py-3 rounded-lg text-white font-semibold hover:bg-purple-600 transition duration-200">
                        Send Reset Link
                    </button>
                    
                    <a href="unified_login.php" 
                       class="text-center text-purple-600 hover:text-purple-700 font-medium transition duration-200">
                        Back to Login
                    </a>
                </div>
            </form>

            <div class="mt-8 p-4 bg-blue-50 rounded-lg">
                <p class="text-sm text-blue-800">
                    A password reset link will be sent to your registered email address. 
                    The link will expire in 1 hour for security purposes.
                </p>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 bg-opacity-90 text-white text-center py-3 text-sm">
        © <?php echo date("Y"); ?> University of Mumbai - NIRF Portal
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
