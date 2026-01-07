<?php
// session_start();

// if (!isset($_SESSION['my_permission']) || $_SESSION['my_permission'] !== $requiredPermission) {
//     error_log('Permission Denied: ' . ($_SESSION['my_permission'] ?? 'Not Set'));
//     header("Location: DeniedError.php");
//     exit();
// }
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f9;
            color: #333;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .container {
            text-align: center;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 400px;
        }

        h1 {
            font-size: 2.5rem;
            color: #e63946;
            margin-bottom: 20px;
        }

        p {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: #555;
        }

        a {
            display: inline-block;
            text-decoration: none;
            color: #fff;
            background-color: #007bff;
            padding: 10px 20px;
            border-radius: 4px;
            font-size: 1rem;
            transition: background-color 0.3s ease;
        }

        a:hover {
            background-color: #0056b3;
        }

        .icon {
            font-size: 5rem;
            color: #e63946;
            margin-bottom: 20px;
        }

        footer {
            margin-top: 20px;
            font-size: 0.9rem;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">&#x26A0;</div> <!-- Warning icon -->
        <h1>Access Denied</h1>
        <p>You do not have the required permissions to access this page.</p>
        <a href="../unified_login.php">Return to Home</a>
    </div>
    <footer>
        &copy; 2024 University of Mumbai. All Rights Reserved.
    </footer>
<!-- Footer -->
<?php include '../footer_main.php';?>
</body>

</html>
