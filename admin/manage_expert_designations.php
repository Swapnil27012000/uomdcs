<?php
/**
 * Admin Interface: Manage Expert Designations (Expert 1, Expert 2, Test Experts)
 * Allows admin to designate which experts should be used for averaging per category
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require('session.php');
    require_once(__DIR__ . '/../config.php');
    require_once(__DIR__ . '/../csrf_shared.php');
} catch (Exception $e) {
    error_log("Error loading dependencies in manage_expert_designations.php: " . $e->getMessage());
    die("System error. Please contact administrator.");
} catch (Error $e) {
    error_log("Fatal error loading dependencies in manage_expert_designations.php: " . $e->getMessage());
    die("System error. Please contact administrator.");
}

if (!isset($conn) || !$conn) {
    error_log("Database connection not available in manage_expert_designations.php");
    die("Database connection error. Please contact administrator.");
}

// Get all categories
$categories = [
    'Sciences and Technology',
    'Management, Institutions,   Sub-campus, Constituent or Conducted/ Model Colleges',
    'Languages',
    'Humanities, and Social Sciences, Commerce',
    'Interdisciplinary',
    'Centre of Studies, Centre of Excellence, Chairs'
];

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF validation (Security Guide Section 2)
    if (!validate_csrf($_POST['csrf_token'] ?? null)) {
        $message = 'Security check failed (invalid CSRF token). Please refresh and try again.';
        $message_type = 'danger';
    } else {
    $action = $_POST['action'];
    $category = trim($_POST['category'] ?? ''); // CRITICAL: Normalize category name
    
    if ($action === 'save_designation') {
        $expert_1_email = trim($_POST['expert_1_email'] ?? '');
        $expert_2_email = trim($_POST['expert_2_email'] ?? '');
        
        // Validate category (normalize all categories for comparison)
        $category_normalized = false;
        foreach ($categories as $cat) {
            if (trim($cat) === $category) {
                $category_normalized = trim($cat); // Use the exact category from the array
                break;
            }
        }
        
        if (!$category_normalized) {
            $message = 'Invalid category selected.';
            $message_type = 'danger';
        } else {
            // Check if table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'expert_designations'");
            if (!$table_check || $table_check->num_rows == 0) {
                $message = 'Error: expert_designations table does not exist. Please run the SQL script first.';
                $message_type = 'danger';
            } else {
                // Insert or update designation - use normalized category
                $stmt = $conn->prepare("INSERT INTO expert_designations (category, expert_1_email, expert_2_email) 
                                        VALUES (?, ?, ?)
                                        ON DUPLICATE KEY UPDATE 
                                        expert_1_email = VALUES(expert_1_email),
                                        expert_2_email = VALUES(expert_2_email),
                                        updated_at = NOW()");
                if ($stmt) {
                    $stmt->bind_param("sss", $category_normalized, $expert_1_email, $expert_2_email);
                    error_log("[Admin] Saving expert designations for category: '" . $category_normalized . "', Expert 1: " . $expert_1_email . ", Expert 2: " . $expert_2_email);
                    if ($stmt->execute()) {
                        $message = 'Expert designations saved successfully!';
                        $message_type = 'success';
                    } else {
                        $message = 'Error saving designations: ' . $stmt->error;
                        $message_type = 'danger';
                    }
                    $stmt->close();
                } else {
                    $message = 'Error preparing statement: ' . $conn->error;
                    $message_type = 'danger';
                }
            }
            if ($table_check) {
                mysqli_free_result($table_check);
            }
        }
    } elseif ($action === 'toggle_test') {
        $expert_email = trim($_POST['expert_email'] ?? '');
        $is_test = (int)($_POST['is_test'] ?? 0);
        if ($is_test !== 0 && $is_test !== 1) {
            $is_test = 0;
        }
        
        // Check if is_test column exists
        $col_check = $conn->query("SHOW COLUMNS FROM expert_categories LIKE 'is_test'");
        if (!$col_check || $col_check->num_rows == 0) {
            $message = 'Error: is_test column does not exist. Please run the SQL script first.';
            $message_type = 'danger';
        } else {
            $stmt = $conn->prepare("UPDATE expert_categories SET is_test = ? WHERE expert_email = ?");
            if ($stmt) {
                $stmt->bind_param("is", $is_test, $expert_email);
                if ($stmt->execute()) {
                    $message = 'Test expert flag updated successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Error updating flag: ' . $stmt->error;
                    $message_type = 'danger';
                }
                $stmt->close();
            }
        }
        if ($col_check) {
            mysqli_free_result($col_check);
        }
    }
    }
}

// Get all designations
$designations = [];
$table_check = $conn->query("SHOW TABLES LIKE 'expert_designations'");
if ($table_check && $table_check->num_rows > 0) {
    $stmt = $conn->prepare("SELECT * FROM expert_designations");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $designations[$row['category']] = $row;
        }
        mysqli_free_result($result);
        $stmt->close();
    }
}
if ($table_check) {
    mysqli_free_result($table_check);
}

// Get all experts with their test status
$all_experts = [];
$col_check = $conn->query("SHOW COLUMNS FROM expert_categories LIKE 'is_test'");
$has_is_test = $col_check && $col_check->num_rows > 0;
if ($col_check) {
    mysqli_free_result($col_check);
}

if ($has_is_test) {
    $stmt = $conn->prepare("SELECT expert_email, category, is_test FROM expert_categories ORDER BY category, expert_email");
} else {
    $stmt = $conn->prepare("SELECT expert_email, category, 0 as is_test FROM expert_categories ORDER BY category, expert_email");
}

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (!isset($all_experts[$row['category']])) {
            $all_experts[$row['category']] = [];
        }
        $all_experts[$row['category']][] = [
            'email' => $row['expert_email'],
            'is_test' => (bool)($row['is_test'] ?? 0)
        ];
    }
    mysqli_free_result($result);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Expert Designations - Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        .category-card {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            background: white;
        }
        .category-card h5 {
            color: #667eea;
            margin-bottom: 1.5rem;
        }
        .expert-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 0.5rem;
            margin-top: 0.5rem;
        }
        .expert-item {
            padding: 0.5rem;
            border-bottom: 1px solid #eee;
        }
        .expert-item:last-child {
            border-bottom: none;
        }
        .test-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
    </style>
</head>
<body>
    <div class="d-flex" id="wrapper">
        <?php include('sidebar.php'); ?>
        <!-- /#sidebar-wrapper -->
        <div id="page-content-wrapper">
            <nav class="navbar navbar-expand-lg navbar-light bg-transparent py-4 px-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-align-left primary-text fs-4 me-3" id="menu-toggle"></i>
                    <h2 class="fs-2 m-0">Manage Expert Designations</h2>
                </div>
                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle second-text fw-bold" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?>
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
                                <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>
            <div class="div">
            <div class="container">
                
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert" id="status-message">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <script>
                        // Auto-dismiss success messages after 2000ms
                        <?php if ($message_type === 'success'): ?>
                        setTimeout(function() {
                            const alert = document.getElementById('status-message');
                            if (alert) {
                                const bsAlert = new bootstrap.Alert(alert);
                                bsAlert.close();
                            }
                        }, 2000);
                        <?php endif; ?>
                    </script>
                <?php endif; ?>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Instructions:</strong> Designate Expert 1 and Expert 2 for each category. These will be used for averaging scores on the Chairman view. 
                    Mark test experts to exclude them from calculations.
                </div>
                
                <?php foreach ($categories as $category): ?>
                    <div class="category-card">
                        <h5><?php echo htmlspecialchars($category); ?></h5>
                        
                        <form method="POST" class="mb-3">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="save_designation">
                            <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label"><strong>Expert 1 Email:</strong></label>
                                    <select name="expert_1_email" class="form-select">
                                        <option value="">-- Select Expert 1 --</option>
                                        <?php if (isset($all_experts[$category])): ?>
                                            <?php foreach ($all_experts[$category] as $expert): ?>
                                                <?php if (!$expert['is_test']): ?>
                                                    <option value="<?php echo htmlspecialchars($expert['email']); ?>"
                                                            <?php echo (isset($designations[$category]['expert_1_email']) && $designations[$category]['expert_1_email'] === $expert['email']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($expert['email']); ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label"><strong>Expert 2 Email:</strong></label>
                                    <select name="expert_2_email" class="form-select">
                                        <option value="">-- Select Expert 2 --</option>
                                        <?php if (isset($all_experts[$category])): ?>
                                            <?php foreach ($all_experts[$category] as $expert): ?>
                                                <?php if (!$expert['is_test']): ?>
                                                    <option value="<?php echo htmlspecialchars($expert['email']); ?>"
                                                            <?php echo (isset($designations[$category]['expert_2_email']) && $designations[$category]['expert_2_email'] === $expert['email']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($expert['email']); ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Designations
                            </button>
                        </form>
                        
                        <div class="mt-3">
                            <strong>All Experts in this Category:</strong>
                            <div class="expert-list">
                                <?php if (isset($all_experts[$category]) && !empty($all_experts[$category])): ?>
                                    <?php foreach ($all_experts[$category] as $expert): ?>
                                        <div class="expert-item d-flex justify-content-between align-items-center">
                                            <span><?php echo htmlspecialchars($expert['email']); ?></span>
                                            <div>
                                                <?php if ($expert['is_test']): ?>
                                                    <span class="badge bg-warning test-badge">Test Expert</span>
                                                <?php endif; ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="toggle_test">
                                                    <input type="hidden" name="expert_email" value="<?php echo htmlspecialchars($expert['email']); ?>">
                                                    <input type="hidden" name="is_test" value="<?php echo $expert['is_test'] ? '0' : '1'; ?>">
                                                    <button type="submit" class="btn btn-sm <?php echo $expert['is_test'] ? 'btn-success' : 'btn-warning'; ?>">
                                                        <?php echo $expert['is_test'] ? 'Remove Test Flag' : 'Mark as Test'; ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted">No experts assigned to this category.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle (unified admin behavior)
        var el = document.getElementById("wrapper");
        var toggleButton = document.getElementById("menu-toggle");
        if (toggleButton) {
            toggleButton.onclick = function() {
                el.classList.toggle("toggled");
            };
        }
    </script>
    <?php include '../footer_main.php'; ?>
</body>
</html>
