<?php
// MUIBEAS/dashboard.php
header("X-XSS-Protection: 0");
session_start();
require('../config.php');

// Only allow if session set
if (!isset($_SESSION['MUIBEAS']) || !isset($_SESSION['admin_username'])) {
    echo '<script>top.location = "../index_3.php";</script>';
    exit;
}

$email = $_SESSION['admin_username'];

// Use prepared statement to fetch user info (by email)
$stmt = $conn->prepare("SELECT * FROM boss WHERE EMAIL = ?");
$info = null;
if ($stmt) {
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $info = $res->fetch_assoc();
    }
    $stmt->close();
}

// If user record not found, logout to be safe
if (!$info) {
    echo '<script>top.location = "../logout.php";</script>';
    exit;
}

$email = $info['EMAIL'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expert Committee Dashboard</title>
</head>
<body>
    Hello, MUIBEAS <?php echo htmlspecialchars($email); ?> <br>
    <p>MUIBEAS Dashboard</p>
    <a href="../logout.php">Logout</a>
</body>
</html>
