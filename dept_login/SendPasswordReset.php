<?php
session_start();
error_reporting(0);

require __DIR__ . '/vendor/autoload.php';
// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    include 'config.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST["email"])) {
    $email = trim($_POST["email"]);

    // Generate secure random token
    $token = bin2hex(random_bytes(32)); // 256-bit token
    $token_hash = hash("sha256", $token); // Store only hash in DB
    $expiry = date("Y-m-d H:i:s", time() + (60 * 60)); // valid 1 hour

    // Check if email exists
    $sql = "SELECT DEPT_ID, DEPT_NAME FROM department_master WHERE EMAIL = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Update DB with token hash + expiry
        $update_sql = "UPDATE department_master 
                       SET RESET_PASSWORD = ?, RESET_TOKEN_EXPIRE = ? 
                       WHERE EMAIL = ?";
        $update_stmt = $conn->prepare($update_sql);
        if (!$update_stmt) {
            die("Prepare failed: " . $conn->error);
        }
        $update_stmt->bind_param("sss", $token_hash, $expiry, $email);

        if ($update_stmt->execute()) {
            // Build reset link
            $reset_link = "https://nirf.univofmumbai.in/reset_password.php?token=" . urlencode($token);

            try {
                // Setup PHPMailer
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $_ENV['SMTP_HOST'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $_ENV['SMTP_USER'];
                $mail->Password   = $_ENV['SMTP_PASS'];
                $mail->SMTPSecure = 'tls';
                $mail->Port       = $_ENV['SMTP_PORT'];

                // Sender & recipient
                $mail->setFrom($_ENV['SMTP_FROM'], $_ENV['SMTP_FROM_NAME']);
                $mail->addAddress($email);

                // Email content
                $mail->isHTML(true);
                $mail->Subject = "Password Reset Request";
                $mail->Body    = "
                    <p>Hello,</p>
                    <p>We received a request to reset your password for the <b>UoM Centralized DCS Ranking Portal</b>.</p>
                    <p>Click the link below to reset your password (valid for 1 hour):</p>
                    <p><a href='$reset_link'>$reset_link</a></p>
                    <br>
                    <p>If you didn’t request this, please ignore this email.</p>
                ";

                $mail->send();
                echo "<script>alert('Password reset link sent to your email.'); window.location='index.php';</script>";
            } catch (Exception $e) {
                echo "<script>alert('Email could not be sent. Error: {$mail->ErrorInfo}');</script>";
            }
        } else {
            die("Database update failed: " . $update_stmt->error);
        }
    } else {
        echo "<script>alert('Email address not registered.');</script>";
    }
} else {
    echo "<script>alert('Invalid request.');</script>";
}
