<?php

// CRITICAL: Only require config if connection doesn't exist - prevent multiple connections
if (!isset($conn) || !$conn) {
    include 'config.php'; // Include database configuration
}

session_start();
error_reporting(0);

if (!isset($_GET["token"])) {
    die("Access denied. No token provided.");
}

$token = $_GET["token"];
// $token = htmlspecialchars($_GET["token"]);
$mysql = $conn; // Ensure $conn is initialized in config.php

// Check if token exists in the database
$sql = "SELECT * FROM department_master WHERE 
        RESET_PASSWORD = ? AND 
        RESET_TOKEN_EXPIRE > NOW()";
$stmt = $mysql->prepare($sql);

if (!$stmt) {
    die("Database error: " . $mysql->error);
}

$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();


// if ($result->num_rows === 0) {
//     die("Invalid or expired token.");
// }

########## after PHP server i need to check this ############
$user = $result->fetch_assoc();

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
    <title>Reset Password</title>
    <link rel="stylesheet" href="./assets/css/style.css">
</head>
<body>

<nav class="navbar">
<div class="items">
  <img src="assets\img\mumbai-university-removebg-preview.png" alt="image" height="100px" width="">
  <h1>University Of Mumbai</h1>
  <img src="assets\img\nirf-full-removebg-preview.png" alt="image" height="90px" width="">
</div>
</nav>

    <div class="main">
        <div class="container">
            <h1>Reset Password</h1>
            <form action="ProcessResetPassword.php" method="post">
                <!-- <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"> -->
                <input type="hidden" name="token" value="<?php echo $token; ?>">

                <div class="input-group">
                    <label for="password">New Password:</label>
                    <input type="password" name="password" id="password" placeholder="Enter new password" required>
                </div>

                <div class="input-group">
                    <label for="confirm_password">Confirm Password:</label>
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Re-Enter new password" required>
                </div>
                
                <div class="input-group">
                    <button type="submit"  class="btn">Reset Password</button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>

