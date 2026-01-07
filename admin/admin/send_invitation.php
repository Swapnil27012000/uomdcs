<?php
// admin/send_invitation.php - Send Invitation Handler
require('session.php');

error_reporting(0);
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// $server   = $_ENV['DB_HOST'] ?? 'localhost';
// $user     = $_ENV['DB_USER'] ?? 'root';
// $pass     = $_ENV['DB_PASS'] ?? '';
// $database = $_ENV['DB_NAME'] ?? '';

// Handle only POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['email'])) {
    $email = trim($_POST['email']);

    // Database connection
    $conn = new mysqli($server, $user, $pass, $database);
    if ($conn->connect_error) {
        die('Database connection failed: ' . $conn->connect_error);
    }

    // Check if email already exists
    $stmt = $conn->prepare("SELECT email FROM department_master WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        echo "<script>alert('This email is already registered.'); window.location.href = 'Dashboard.php';</script>";
        exit;
    }
    $stmt->close();

    // Generate secure token + expiry
    $token       = bin2hex(random_bytes(32));
    $expire_time = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Store invitation
    $stmt = $conn->prepare("INSERT INTO user_invitationss (email, token, RESET_TOKEN_EXPIRE) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $email, $token, $expire_time);

    if ($stmt->execute()) {
        $stmt->close();

        // Create account link
        $link = $port_link . "/" . "create_account.php?token=$token";

        // Setup PHPMailer
        $mail = new PHPMailer(true);
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['SMTP_USER'] ?? 'your-email@gmail.com';
            $mail->Password   = $_ENV['SMTP_PASS'] ?? 'your-password';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;

            // Recipients
            $mail->setFrom($_ENV['SMTP_FROM'] ?? 'no-reply@example.com', 'NIRF Portal');
            $mail->addAddress($email);

            // Content
            $mail->isHTML(true);
            $mail->Subject = "NIRF - Create Your Account";
            $mail->Body    = "Hello,<br><br>Please click the link below to create your account:<br>
                              <a href='$link'>$link</a><br><br>This link will expire in 1 hour.";
            $mail->AltBody = "Click the link to create your account: $link";

            $mail->send();
            echo "<script>alert('Invitation email has been sent to $email.'); window.location.href = 'Dashboard.php';</script>";
        } catch (Exception $e) {
            echo "<script>alert('Mailer Error: {$mail->ErrorInfo}'); window.location.href = 'Dashboard.php';</script>";
        }
    } else {
        echo "<script>alert('Failed to store the invitation token.'); window.location.href = 'Dashboard.php';</script>";
    }

    $conn->close();
}
?>
