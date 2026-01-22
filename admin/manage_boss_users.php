<?php
/**
 * Admin Interface: Manage Boss Table Users (CRUD)
 * Allows admin to add, edit, and delete users from the boss table
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require('session.php');
    require_once(__DIR__ . '/../config.php');
    require_once(__DIR__ . '/../csrf_shared.php');
} catch (Exception $e) {
    error_log("Error loading dependencies in manage_boss_users.php: " . $e->getMessage());
    die("System error. Please contact administrator.");
} catch (Error $e) {
    error_log("Fatal error loading dependencies in manage_boss_users.php: " . $e->getMessage());
    die("System error. Please contact administrator.");
}

if (!isset($conn) || !$conn) {
    error_log("Database connection not available in manage_boss_users.php");
    die("Database connection error. Please contact administrator.");
}

// Valid permission types
$valid_permissions = [
    'admin',
    'Expert_comty_login',
    'AAQA_login',
    'Chairman_login',
    'verification_committee',
    'dept_login',
    'MUIBEAS'
];

// Valid expert categories
$expert_categories = [
    'Sciences and Technology',
    'Management, Institutions,   Sub-campus, Constituent or Conducted/ Model Colleges',
    'Languages',
    'Humanities, and Social Sciences, Commerce',
    'Interdisciplinary',
    'Centre of Studies, Centre of Excellence, Chairs'
];

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CRITICAL: Validate CSRF token
    if (!validate_csrf($_POST['csrf_token'] ?? null)) {
        $message = 'Security check failed (invalid CSRF token). Please refresh and try again.';
        $message_type = 'danger';
    } else {
        $action = $_POST['action'];
        
        if ($action === 'add') {
            // ADD new user
            $email = trim($_POST['email'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $permission = trim($_POST['permission'] ?? 'admin');
            $expert_categories_selected = $_POST['expert_categories'] ?? [];
            if (!is_array($expert_categories_selected)) {
                $expert_categories_selected = [];
            }
            $expert_categories_selected = array_values(array_filter(array_map(static function ($v) {
                return trim((string)$v);
            }, $expert_categories_selected), static function ($v) {
                return $v !== '';
            }));
            
            // CRITICAL: Validate input
            if (empty($email)) {
                $message = 'Email is required.';
                $message_type = 'danger';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = 'Invalid email format.';
                $message_type = 'danger';
            } elseif (empty($password)) {
                $message = 'Password is required.';
                $message_type = 'danger';
            } elseif (!in_array($permission, $valid_permissions, true)) {
                $message = 'Invalid permission type.';
                $message_type = 'danger';
            } elseif ($permission === 'Expert_comty_login' && empty($expert_categories_selected)) {
                $message = 'At least one expert category is required for Expert users.';
                $message_type = 'danger';
            } elseif ($permission === 'Expert_comty_login') {
                foreach ($expert_categories_selected as $cat) {
                    if (!in_array($cat, $expert_categories, true)) {
                $message = 'Invalid expert category.';
                $message_type = 'danger';
                        break;
                    }
                }
            }

            if (empty($message)) {
                        // CRITICAL: Insert new user with prepared statement
                // Allow same email with different permissions (no duplicate check)
                        $insert_stmt = $conn->prepare("INSERT INTO boss (EMAIL, PASS_WORD, PERMISSION) VALUES (?, ?, ?)");
                        if ($insert_stmt) {
                            $insert_stmt->bind_param("sss", $email, $password, $permission);
                            if ($insert_stmt->execute()) {
                                // If Expert user, add to expert_categories table
                        if ($permission === 'Expert_comty_login' && !empty($expert_categories_selected)) {
                                    $expert_cat_check = $conn->query("SHOW TABLES LIKE 'expert_categories'");
                                    if ($expert_cat_check && $expert_cat_check->num_rows > 0) {
                                        mysqli_free_result($expert_cat_check);
                                $expert_cat_stmt = $conn->prepare("INSERT INTO expert_categories (expert_email, category) VALUES (?, ?) ON DUPLICATE KEY UPDATE category = category");
                                        if ($expert_cat_stmt) {
                                    foreach ($expert_categories_selected as $cat) {
                                        $expert_cat_stmt->bind_param("ss", $email, $cat);
                                            $expert_cat_stmt->execute();
                                    }
                                            $expert_cat_stmt->close();
                                        }
                            } elseif ($expert_cat_check) {
                                            mysqli_free_result($expert_cat_check);
                                    }
                                }
                                
                                // If verification_committee user, add to verification_committee_users table if it exists
                                if ($permission === 'verification_committee') {
                                    $vc_table_check = $conn->query("SHOW TABLES LIKE 'verification_committee_users'");
                                    if ($vc_table_check && $vc_table_check->num_rows > 0) {
                                        mysqli_free_result($vc_table_check);
                                        $vc_insert_stmt = $conn->prepare("INSERT INTO verification_committee_users (EMAIL, PASS_WORD, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE PASS_WORD = ?, is_active = 1");
                                        if ($vc_insert_stmt) {
                                            $vc_insert_stmt->bind_param("sss", $email, $password, $password);
                                            $vc_insert_stmt->execute();
                                            $vc_insert_stmt->close();
                                        }
                            } elseif ($vc_table_check) {
                                            mysqli_free_result($vc_table_check);
                                    }
                                }
                                
                                // CRITICAL: Redirect after successful POST to prevent duplicate submissions (POST-redirect-GET pattern)
                                $insert_stmt->close();
                                $_SESSION['admin_message'] = 'User added successfully!';
                                $_SESSION['admin_message_type'] = 'success';
                                header('Location: manage_boss_users.php');
                                exit;
                            } else {
                                $message = 'Error adding user: ' . $insert_stmt->error;
                                $message_type = 'danger';
                                $insert_stmt->close();
                            }
                        } else {
                            $message = 'Error preparing insert statement: ' . $conn->error;
                            $message_type = 'danger';
                        }
                    }
        } elseif ($action === 'edit') {
            // EDIT existing user
            $user_id = (int)($_POST['user_id'] ?? 0);
            $email = trim($_POST['email'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $permission = trim($_POST['permission'] ?? 'admin');
            $expert_categories_selected = $_POST['expert_categories'] ?? [];
            if (!is_array($expert_categories_selected)) {
                $expert_categories_selected = [];
            }
            $expert_categories_selected = array_values(array_filter(array_map(static function ($v) {
                return trim((string)$v);
            }, $expert_categories_selected), static function ($v) {
                return $v !== '';
            }));
            $old_email = '';
            $old_permission = '';
            $old_password = '';
            $update_password = false; // Will be set based on comparison
            
            // CRITICAL: Get old permission, email, and password before updating
            $get_old_stmt = $conn->prepare("SELECT EMAIL, PASS_WORD, PERMISSION FROM boss WHERE Id = ? LIMIT 1");
            if ($get_old_stmt) {
                $get_old_stmt->bind_param("i", $user_id);
                $get_old_stmt->execute();
                $get_old_result = $get_old_stmt->get_result();
                if ($old_row = $get_old_result->fetch_assoc()) {
                    $old_email = $old_row['EMAIL'];
                    $old_permission = $old_row['PERMISSION'];
                    $old_password = $old_row['PASS_WORD'] ?? '';
                }
                if ($get_old_result) {
                    mysqli_free_result($get_old_result);
                }
                $get_old_stmt->close();
            }
            
            // Only update password if it's provided AND different from the original
            if (!empty($password) && $password !== $old_password) {
                $update_password = true;
            }
            
            // CRITICAL: Validate input
            if ($user_id <= 0) {
                $message = 'Invalid user ID.';
                $message_type = 'danger';
            } elseif (empty($email)) {
                $message = 'Email is required.';
                $message_type = 'danger';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = 'Invalid email format.';
                $message_type = 'danger';
            } elseif (!in_array($permission, $valid_permissions)) {
                $message = 'Invalid permission type.';
                $message_type = 'danger';
            } elseif ($permission === 'Expert_comty_login' && empty($expert_categories_selected)) {
                $message = 'At least one expert category is required for Expert users.';
                $message_type = 'danger';
            } else {
                if ($permission === 'Expert_comty_login') {
                    foreach ($expert_categories_selected as $cat) {
                        if (!in_array($cat, $expert_categories, true)) {
                $message = 'Invalid expert category.';
                $message_type = 'danger';
                            break;
                        }
                    }
                }
            }

            if (empty($message)) {
                        // CRITICAL: Update user with prepared statement
                // Allow same email with different permissions (no duplicate check)
                $update_stmt = null;
                        if ($update_password) {
                            $update_stmt = $conn->prepare("UPDATE boss SET EMAIL = ?, PASS_WORD = ?, PERMISSION = ? WHERE Id = ?");
                            if ($update_stmt) {
                                $update_stmt->bind_param("sssi", $email, $password, $permission, $user_id);
                            }
                        } else {
                            $update_stmt = $conn->prepare("UPDATE boss SET EMAIL = ?, PERMISSION = ? WHERE Id = ?");
                            if ($update_stmt) {
                                $update_stmt->bind_param("ssi", $email, $permission, $user_id);
                            }
                        }
                        
                        if ($update_stmt) {
                            if ($update_stmt->execute()) {
                                // Handle permission changes and related tables
                                
                                // If changing FROM Expert_comty_login, remove from expert_categories
                                if ($old_permission === 'Expert_comty_login' && $permission !== 'Expert_comty_login') {
                                    $expert_cat_check = $conn->query("SHOW TABLES LIKE 'expert_categories'");
                                    if ($expert_cat_check && $expert_cat_check->num_rows > 0) {
                                        mysqli_free_result($expert_cat_check);
                                        $expert_del_stmt = $conn->prepare("DELETE FROM expert_categories WHERE expert_email = ?");
                                        if ($expert_del_stmt) {
                                            $expert_del_stmt->bind_param("s", $old_email);
                                            $expert_del_stmt->execute();
                                            $expert_del_stmt->close();
                                        }
                                    } else {
                                        if ($expert_cat_check) {
                                            mysqli_free_result($expert_cat_check);
                                        }
                                    }
                                }
                                
                                // If changing TO Expert_comty_login, add to expert_categories
                                // CRITICAL: For same email with different passwords (different categories),
                                // we merge/add categories instead of replacing all categories
                                if ($permission === 'Expert_comty_login' && !empty($expert_categories_selected)) {
                                    $expert_cat_check = $conn->query("SHOW TABLES LIKE 'expert_categories'");
                                    if ($expert_cat_check && $expert_cat_check->num_rows > 0) {
                                        mysqli_free_result($expert_cat_check);
                                        
                                        // Check if there are other boss entries with the same email
                                        $check_other_boss_stmt = $conn->prepare("SELECT COUNT(*) as count FROM boss WHERE EMAIL = ? AND Id != ?");
                                        $has_other_entries = false;
                                        if ($check_other_boss_stmt) {
                                            $check_other_boss_stmt->bind_param("si", $email, $user_id);
                                            $check_other_boss_stmt->execute();
                                            $check_result = $check_other_boss_stmt->get_result();
                                            if ($check_row = $check_result->fetch_assoc()) {
                                                $has_other_entries = ((int)$check_row['count'] > 0);
                                            }
                                            if ($check_result) {
                                                mysqli_free_result($check_result);
                                            }
                                            $check_other_boss_stmt->close();
                                        }
                                        
                                        // If this is the ONLY entry with this email, replace all categories
                                        // Otherwise, just add/merge the new categories (don't delete existing ones)
                                        if (!$has_other_entries) {
                                            // Get current categories for the old email
                                            $get_current_cats_stmt = $conn->prepare("SELECT category FROM expert_categories WHERE expert_email = ?");
                                            $current_categories = [];
                                            if ($get_current_cats_stmt) {
                                                $get_current_cats_stmt->bind_param("s", $old_email);
                                                $get_current_cats_stmt->execute();
                                                $get_cats_result = $get_current_cats_stmt->get_result();
                                                while ($cat_row = $get_cats_result->fetch_assoc()) {
                                                    $current_categories[] = $cat_row['category'];
                                                }
                                                if ($get_cats_result) {
                                                    mysqli_free_result($get_cats_result);
                                                }
                                                $get_current_cats_stmt->close();
                                            }
                                            
                                            // Delete categories that are NOT in the new selection
                                            $categories_to_delete = array_diff($current_categories, $expert_categories_selected);
                                            if (!empty($categories_to_delete)) {
                                                $placeholders = implode(',', array_fill(0, count($categories_to_delete), '?'));
                                                $expert_del_stmt = $conn->prepare("DELETE FROM expert_categories WHERE expert_email = ? AND category IN ($placeholders)");
                                                if ($expert_del_stmt) {
                                                    $types = 's' . str_repeat('s', count($categories_to_delete));
                                                    $params = array_merge([$old_email], $categories_to_delete);
                                                    $expert_del_stmt->bind_param($types, ...$params);
                                                    $expert_del_stmt->execute();
                                                    $expert_del_stmt->close();
                                                }
                                            }
                                            
                                            // Update email in expert_categories if email changed
                                            if ($old_email !== $email) {
                                                $categories_to_update = array_intersect($current_categories, $expert_categories_selected);
                                                if (!empty($categories_to_update)) {
                                                    $placeholders = implode(',', array_fill(0, count($categories_to_update), '?'));
                                                    $update_email_stmt = $conn->prepare("UPDATE expert_categories SET expert_email = ? WHERE expert_email = ? AND category IN ($placeholders)");
                                                    if ($update_email_stmt) {
                                                        $types = 's' . 's' . str_repeat('s', count($categories_to_update));
                                                        $params = array_merge([$email, $old_email], $categories_to_update);
                                                        $update_email_stmt->bind_param($types, ...$params);
                                                        $update_email_stmt->execute();
                                                        $update_email_stmt->close();
                                                    }
                                                }
                                            }
                                        }
                                        
                                        // Add new categories (ON DUPLICATE KEY will skip if already exists)
                                        // This allows merging categories when same email has multiple entries
                                        $expert_cat_stmt = $conn->prepare("INSERT INTO expert_categories (expert_email, category) VALUES (?, ?) ON DUPLICATE KEY UPDATE category = category");
                                        if ($expert_cat_stmt) {
                                            foreach ($expert_categories_selected as $cat) {
                                                $expert_cat_stmt->bind_param("ss", $email, $cat);
                                                $expert_cat_stmt->execute();
                                            }
                                            $expert_cat_stmt->close();
                                        }
                                    } else {
                                        if ($expert_cat_check) {
                                            mysqli_free_result($expert_cat_check);
                                        }
                                    }
                                }
                                
                                // If changing FROM verification_committee, remove from verification_committee_users
                                if ($old_permission === 'verification_committee' && $permission !== 'verification_committee') {
                                    $vc_table_check = $conn->query("SHOW TABLES LIKE 'verification_committee_users'");
                                    if ($vc_table_check && $vc_table_check->num_rows > 0) {
                                        mysqli_free_result($vc_table_check);
                                        $vc_del_stmt = $conn->prepare("DELETE FROM verification_committee_users WHERE EMAIL = ?");
                                        if ($vc_del_stmt) {
                                            $vc_del_stmt->bind_param("s", $old_email);
                                            $vc_del_stmt->execute();
                                            $vc_del_stmt->close();
                                        }
                                    } else {
                                        if ($vc_table_check) {
                                            mysqli_free_result($vc_table_check);
                                        }
                                    }
                                }
                                
                                // If changing TO verification_committee, add to verification_committee_users
                                if ($permission === 'verification_committee') {
                                    $vc_table_check = $conn->query("SHOW TABLES LIKE 'verification_committee_users'");
                                    if ($vc_table_check && $vc_table_check->num_rows > 0) {
                                        mysqli_free_result($vc_table_check);
                                        $vc_insert_stmt = $conn->prepare("INSERT INTO verification_committee_users (EMAIL, PASS_WORD, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE PASS_WORD = COALESCE(?, PASS_WORD), is_active = 1");
                                        if ($vc_insert_stmt) {
                                            $vc_password = $update_password ? $password : '';
                                            if ($update_password) {
                                                $vc_insert_stmt->bind_param("sss", $email, $password, $password);
                                            } else {
                                                // Get password from boss table
                                                $get_pass_stmt = $conn->prepare("SELECT PASS_WORD FROM boss WHERE Id = ? LIMIT 1");
                                                if ($get_pass_stmt) {
                                                    $get_pass_stmt->bind_param("i", $user_id);
                                                    $get_pass_stmt->execute();
                                                    $get_pass_result = $get_pass_stmt->get_result();
                                                    if ($pass_row = $get_pass_result->fetch_assoc()) {
                                                        $vc_password = $pass_row['PASS_WORD'];
                                                        $vc_insert_stmt->bind_param("sss", $email, $vc_password, $vc_password);
                                                    }
                                                    if ($get_pass_result) {
                                                        mysqli_free_result($get_pass_result);
                                                    }
                                                    $get_pass_stmt->close();
                                                }
                                            }
                                            $vc_insert_stmt->execute();
                                            $vc_insert_stmt->close();
                                        }
                                    } else {
                                        if ($vc_table_check) {
                                            mysqli_free_result($vc_table_check);
                                        }
                                    }
                                }
                                
                                // If email changed, update related tables
                                if ($old_email !== $email) {
                                    // Update expert_categories if user is an expert
                                    if ($permission === 'Expert_comty_login') {
                                        $expert_cat_check = $conn->query("SHOW TABLES LIKE 'expert_categories'");
                                        if ($expert_cat_check && $expert_cat_check->num_rows > 0) {
                                            mysqli_free_result($expert_cat_check);
                                            $expert_update_stmt = $conn->prepare("UPDATE expert_categories SET expert_email = ? WHERE expert_email = ?");
                                            if ($expert_update_stmt) {
                                                $expert_update_stmt->bind_param("ss", $email, $old_email);
                                                $expert_update_stmt->execute();
                                                $expert_update_stmt->close();
                                            }
                                        } else {
                                            if ($expert_cat_check) {
                                                mysqli_free_result($expert_cat_check);
                                            }
                                        }
                                    }
                                    
                                    // Update verification_committee_users if user is verification committee
                                    if ($permission === 'verification_committee') {
                                        $vc_table_check = $conn->query("SHOW TABLES LIKE 'verification_committee_users'");
                                        if ($vc_table_check && $vc_table_check->num_rows > 0) {
                                            mysqli_free_result($vc_table_check);
                                            $vc_update_stmt = $conn->prepare("UPDATE verification_committee_users SET EMAIL = ? WHERE EMAIL = ?");
                                            if ($vc_update_stmt) {
                                                $vc_update_stmt->bind_param("ss", $email, $old_email);
                                                $vc_update_stmt->execute();
                                                $vc_update_stmt->close();
                                            }
                                        } else {
                                            if ($vc_table_check) {
                                                mysqli_free_result($vc_table_check);
                                            }
                                        }
                                    }
                                }
                                // CRITICAL: Redirect after successful POST to prevent duplicate submissions (POST-redirect-GET pattern)
                                $update_stmt->close();
                                $_SESSION['admin_message'] = 'User updated successfully!';
                                $_SESSION['admin_message_type'] = 'success';
                                header('Location: manage_boss_users.php');
                                exit;
                            } else {
                                $message = 'Error updating user: ' . $update_stmt->error;
                                $message_type = 'danger';
                                $update_stmt->close();
                            }
                        } else {
                            $message = 'Error preparing update statement: ' . $conn->error;
                    $message_type = 'danger';
                }
            }
            
        } elseif ($action === 'delete') {
            // DELETE user
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            // CRITICAL: Validate input
            if ($user_id <= 0) {
                $message = 'Invalid user ID.';
                $message_type = 'danger';
            } else {
                // CRITICAL: Prevent deleting the current admin user and get permission/email for cleanup
                $current_email = $email ?? $_SESSION['admin_username'] ?? '';
                $check_stmt = $conn->prepare("SELECT EMAIL, PERMISSION FROM boss WHERE Id = ? LIMIT 1");
                if ($check_stmt) {
                    $check_stmt->bind_param("i", $user_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    if ($check_row = $check_result->fetch_assoc()) {
                        $delete_email = $check_row['EMAIL'];
                        $delete_permission = $check_row['PERMISSION'];
                        
                        if ($delete_email === $current_email) {
                            $message = 'You cannot delete your own account.';
                            $message_type = 'danger';
                        } else {
                            
                            // CRITICAL: Delete user with prepared statement
                            $delete_stmt = $conn->prepare("DELETE FROM boss WHERE Id = ?");
                            if ($delete_stmt) {
                                $delete_stmt->bind_param("i", $user_id);
                                if ($delete_stmt->execute()) {
                                    // Clean up related tables
                                    if ($delete_permission === 'Expert_comty_login') {
                                        // Delete from expert_categories
                                        $expert_cat_check = $conn->query("SHOW TABLES LIKE 'expert_categories'");
                                        if ($expert_cat_check && $expert_cat_check->num_rows > 0) {
                                            mysqli_free_result($expert_cat_check);
                                            $expert_del_stmt = $conn->prepare("DELETE FROM expert_categories WHERE expert_email = ?");
                                            if ($expert_del_stmt) {
                                                $expert_del_stmt->bind_param("s", $delete_email);
                                                $expert_del_stmt->execute();
                                                $expert_del_stmt->close();
                                            }
                                        } else {
                                            if ($expert_cat_check) {
                                                mysqli_free_result($expert_cat_check);
                                            }
                                        }
                                        
                                        // Delete from expert_reviews
                                        $expert_reviews_check = $conn->query("SHOW TABLES LIKE 'expert_reviews'");
                                        if ($expert_reviews_check && $expert_reviews_check->num_rows > 0) {
                                            mysqli_free_result($expert_reviews_check);
                                            $expert_reviews_del_stmt = $conn->prepare("DELETE FROM expert_reviews WHERE expert_email = ?");
                                            if ($expert_reviews_del_stmt) {
                                                $expert_reviews_del_stmt->bind_param("s", $delete_email);
                                                $expert_reviews_del_stmt->execute();
                                                $expert_reviews_del_stmt->close();
                                            }
                                        } else {
                                            if ($expert_reviews_check) {
                                                mysqli_free_result($expert_reviews_check);
                                            }
                                        }
                                        
                                        // Clear from expert_designations (set to NULL if they are Expert 1 or Expert 2)
                                        $expert_designations_check = $conn->query("SHOW TABLES LIKE 'expert_designations'");
                                        if ($expert_designations_check && $expert_designations_check->num_rows > 0) {
                                            mysqli_free_result($expert_designations_check);
                                            // Clear expert_1_email if it matches
                                            $expert_des_1_stmt = $conn->prepare("UPDATE expert_designations SET expert_1_email = NULL WHERE expert_1_email = ?");
                                            if ($expert_des_1_stmt) {
                                                $expert_des_1_stmt->bind_param("s", $delete_email);
                                                $expert_des_1_stmt->execute();
                                                $expert_des_1_stmt->close();
                                            }
                                            // Clear expert_2_email if it matches
                                            $expert_des_2_stmt = $conn->prepare("UPDATE expert_designations SET expert_2_email = NULL WHERE expert_2_email = ?");
                                            if ($expert_des_2_stmt) {
                                                $expert_des_2_stmt->bind_param("s", $delete_email);
                                                $expert_des_2_stmt->execute();
                                                $expert_des_2_stmt->close();
                                            }
                                        } else {
                                            if ($expert_designations_check) {
                                                mysqli_free_result($expert_designations_check);
                                            }
                                        }
                                    }
                                    
                                    if ($delete_permission === 'verification_committee') {
                                        $vc_table_check = $conn->query("SHOW TABLES LIKE 'verification_committee_users'");
                                        if ($vc_table_check && $vc_table_check->num_rows > 0) {
                                            mysqli_free_result($vc_table_check);
                                            $vc_del_stmt = $conn->prepare("DELETE FROM verification_committee_users WHERE EMAIL = ?");
                                            if ($vc_del_stmt) {
                                                $vc_del_stmt->bind_param("s", $delete_email);
                                                $vc_del_stmt->execute();
                                                $vc_del_stmt->close();
                                            }
                                        } else {
                                            if ($vc_table_check) {
                                                mysqli_free_result($vc_table_check);
                                            }
                                        }
                                        
                                        // CRITICAL: Delete assigned items from verification_committee_assignments table
                                        $vca_table_check = $conn->query("SHOW TABLES LIKE 'verification_committee_assignments'");
                                        if ($vca_table_check && $vca_table_check->num_rows > 0) {
                                            mysqli_free_result($vca_table_check);
                                            // Get verification_user_id first
                                            $get_vc_user_id_stmt = $conn->prepare("SELECT id FROM verification_committee_users WHERE EMAIL = ? LIMIT 1");
                                            if ($get_vc_user_id_stmt) {
                                                $get_vc_user_id_stmt->bind_param("s", $delete_email);
                                                $get_vc_user_id_stmt->execute();
                                                $get_vc_user_id_result = $get_vc_user_id_stmt->get_result();
                                                if ($vc_user_row = $get_vc_user_id_result->fetch_assoc()) {
                                                    $verification_user_id = (int)($vc_user_row['id'] ?? 0);
                                                    if ($verification_user_id > 0) {
                                                        $vca_del_stmt = $conn->prepare("DELETE FROM verification_committee_assignments WHERE verification_user_id = ?");
                                                        if ($vca_del_stmt) {
                                                            $vca_del_stmt->bind_param("i", $verification_user_id);
                                                            $vca_del_stmt->execute();
                                                            $vca_del_stmt->close();
                                                        }
                                                    }
                                                }
                                                if ($get_vc_user_id_result) {
                                                    mysqli_free_result($get_vc_user_id_result);
                                                }
                                                $get_vc_user_id_stmt->close();
                                            }
                                        } else {
                                            if ($vca_table_check) {
                                                mysqli_free_result($vca_table_check);
                                            }
                                        }
                                    }
                                    
                                    // CRITICAL: Redirect after successful POST to prevent duplicate submissions (POST-redirect-GET pattern)
                                    $delete_stmt->close();
                                    $_SESSION['admin_message'] = 'User deleted successfully!';
                                    $_SESSION['admin_message_type'] = 'success';
                                    header('Location: manage_boss_users.php');
                                    exit;
                                } else {
                                    $message = 'Error deleting user: ' . $delete_stmt->error;
                                    $message_type = 'danger';
                                    $delete_stmt->close();
                                }
                            } else {
                                $message = 'Error preparing delete statement: ' . $conn->error;
                                $message_type = 'danger';
                            }
                        }
                    } else {
                        $message = 'User not found.';
                        $message_type = 'danger';
                    }
                    if ($check_result) {
                        mysqli_free_result($check_result);
                    }
                    $check_stmt->close();
                } else {
                    $message = 'Error preparing check statement: ' . $conn->error;
                    $message_type = 'danger';
                }
            }
        }
    }
}

// CRITICAL: Get message from session (set by redirect after POST) and clear it
if (isset($_SESSION['admin_message'])) {
    $message = $_SESSION['admin_message'];
    $message_type = $_SESSION['admin_message_type'] ?? 'success';
    unset($_SESSION['admin_message']);
    unset($_SESSION['admin_message_type']);
}

// Get all users from boss table
$users = [];
$query = "SELECT Id, EMAIL, PASS_WORD, PERMISSION FROM boss ORDER BY PERMISSION, EMAIL";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    if ($result) {
        mysqli_free_result($result);
    }
    $stmt->close();
}

// Get user for editing (if edit_id is provided)
$edit_user = null;
$edit_user_categories = [];
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
if ($edit_id > 0) {
    $edit_stmt = $conn->prepare("SELECT Id, EMAIL, PASS_WORD, PERMISSION FROM boss WHERE Id = ? LIMIT 1");
    if ($edit_stmt) {
        $edit_stmt->bind_param("i", $edit_id);
        $edit_stmt->execute();
        $edit_result = $edit_stmt->get_result();
        if ($edit_row = $edit_result->fetch_assoc()) {
            $edit_user = $edit_row;
            // Get expert category if user is an expert
            if ($edit_user['PERMISSION'] === 'Expert_comty_login') {
                $expert_cat_check = $conn->query("SHOW TABLES LIKE 'expert_categories'");
                if ($expert_cat_check && $expert_cat_check->num_rows > 0) {
                    mysqli_free_result($expert_cat_check);
                    $cat_stmt = $conn->prepare("SELECT category FROM expert_categories WHERE expert_email = ? ORDER BY category");
                    if ($cat_stmt) {
                        $cat_stmt->bind_param("s", $edit_user['EMAIL']);
                        $cat_stmt->execute();
                        $cat_result = $cat_stmt->get_result();
                        while ($cat_row = $cat_result->fetch_assoc()) {
                            if (!empty($cat_row['category'])) {
                                $edit_user_categories[] = $cat_row['category'];
                            }
                        }
                        if ($cat_result) {
                            mysqli_free_result($cat_result);
                        }
                        $cat_stmt->close();
                    }
                } else {
                    if ($expert_cat_check) {
                        mysqli_free_result($expert_cat_check);
                    }
                }
            }
        }
        if ($edit_result) {
            mysqli_free_result($edit_result);
        }
        $edit_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admins Management - Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        body {
            background: #f8f9fa;
        }
        /* Keep content stable within viewport (prevents horizontal overflow) */
        #page-content-wrapper {
            width: 100%;
            overflow-x: hidden;
        }
        .page-container {
            max-width: 1250px;
            margin-left: auto;
            margin-right: auto;
        }
        .card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            overflow: hidden;
            max-width: 100%;
        }
        .card-header {
            background: #1976d2;
            color: white;
            border: none;
            padding: 1.25rem 1.5rem;
            font-weight: 600;
        }
        .card-header.bg-info {
            background: #2196f3;
        }
        .card-body {
            padding: 1.5rem;
            background: #ffffff;
        }
        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #e0e0e0;
            padding: 0.65rem 1rem;
            transition: all 0.2s ease;
            background: #ffffff;
        }
        .form-control:focus, .form-select:focus {
            border-color: #1976d2;
            box-shadow: 0 0 0 0.2rem rgba(25, 118, 210, 0.1);
            outline: none;
        }
        .btn {
            border-radius: 6px;
            padding: 0.6rem 1.5rem;
            font-weight: 500;
            transition: all 0.2s ease;
            border: none;
        }
        .btn-primary {
            background: #1976d2;
            color: white;
        }
        .btn-primary:hover {
            background: #1565c0;
            box-shadow: 0 2px 8px rgba(25, 118, 210, 0.3);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .btn-warning {
            background: #ed6c02;
            color: white;
        }
        .btn-warning:hover {
            background: #d84315;
        }
        .btn-danger {
            background: #d32f2f;
            color: white;
        }
        .btn-danger:hover {
            background: #c62828;
        }
        .permission-badge {
            font-size: 0.75rem;
            padding: 0.4rem 0.8rem;
            border-radius: 4px;
            font-weight: 500;
        }
        .table {
            margin-bottom: 0;
            width: 100%;
        }
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table thead th {
            background: #1976d2;
            color: white;
            border: none;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            padding: 1rem;
        }
        .table tbody tr {
            transition: background-color 0.2s ease;
        }
        .table tbody tr:hover {
            background-color: #f5f5f5;
        }
        .table tbody td {
            vertical-align: middle;
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
        }
        .dataTables_wrapper {
            padding: 1rem 0;
        }
        .dataTables_filter {
            margin-bottom: 1rem;
        }
        .dataTables_filter input {
            border-radius: 6px;
            border: 1px solid #e0e0e0;
            padding: 0.5rem 1rem;
            margin-left: 0.5rem;
            transition: all 0.2s ease;
            background: #ffffff;
        }
        .dataTables_filter input:focus {
            border-color: #1976d2;
            box-shadow: 0 0 0 0.2rem rgba(25, 118, 210, 0.1);
            outline: none;
        }
        .dataTables_length select {
            border-radius: 6px;
            border: 1px solid #e0e0e0;
            padding: 0.4rem 0.8rem;
            transition: all 0.2s ease;
            background: #ffffff;
        }
        .dataTables_length select:focus {
            border-color: #1976d2;
            outline: none;
        }
        .dataTables_info {
            color: #6c757d;
            font-weight: 500;
            padding-top: 0.85rem;
        }
        .dataTables_paginate {
            padding-top: 0.85rem;
        }
        .dataTables_paginate .paginate_button {
            border-radius: 6px;
            margin: 0 2px;
            padding: 0.5rem 0.75rem;
            border: 1px solid #dee2e6;
            transition: all 0.2s ease;
            background: #ffffff;
        }
        .dataTables_paginate .paginate_button.current {
            background: #1976d2;
            border: 1px solid #1976d2;
            color: white;
        }
        /* Expert category dropdown styling */
        #expertCategorySelect {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
        }
        #expertCategorySelect option {
            padding: 0.5rem;
            cursor: pointer;
        }
        #expertCategorySelect option:hover {
            background-color: #f0f0f0;
        }
        #expertCategorySelect option:checked {
            background-color: #1976d2;
            color: white;
        }
        kbd {
            background-color: #f4f4f4;
            border: 1px solid #ccc;
            border-radius: 3px;
            padding: 0.2rem 0.4rem;
            font-size: 0.85em;
            font-family: monospace;
        }
        /* Password generate button */
        #generatePasswordBtn {
            border-left: none;
        }
        #generatePasswordBtn:hover {
            background-color: #1976d2;
            color: white;
            border-color: #1976d2;
        }
        .dataTables_paginate .paginate_button:hover {
            background: #2196f3;
            border: 1px solid #2196f3;
            color: white;
        }
        .alert {
            border-radius: 6px;
            border: 1px solid transparent;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        code {
            background: #f8f9fa;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.85rem;
            color: #495057;
            border: 1px solid #e0e0e0;
        }
        .btn-group .btn {
            margin: 0 2px;
        }
        h2 {
            color: #333333;
            font-weight: 600;
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
                    <h2 class="fs-2 m-0">Admins Management</h2>
                </div>
                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle second-text fw-bold" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($email ?? $_SESSION['admin_username'] ?? 'Admin'); ?>
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
                                <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>
            <div class="container-fluid px-4 page-container">
                
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert" id="status-message">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <script>
                        <?php if ($message_type === 'success'): ?>
                        setTimeout(function() {
                            const alert = document.getElementById('status-message');
                            if (alert) {
                                const bsAlert = new bootstrap.Alert(alert);
                                bsAlert.close();
                            }
                        }, 3000);
                        <?php endif; ?>
                    </script>
                <?php endif; ?>
                
                <!-- Add/Edit Form -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-<?php echo $edit_user ? 'edit' : 'plus-circle'; ?> me-2"></i>
                            <?php echo $edit_user ? 'Edit User' : 'Add New User'; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="userForm">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="<?php echo $edit_user ? 'edit' : 'add'; ?>">
                            <?php if ($edit_user): ?>
                                <input type="hidden" name="user_id" value="<?php echo (int)$edit_user['Id']; ?>">
                            <?php endif; ?>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label"><strong>Email:</strong> <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" 
                                           value="<?php echo htmlspecialchars($edit_user['EMAIL'] ?? ''); ?>" 
                                           required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label"><strong>Password:</strong> 
                                        <?php if ($edit_user): ?>
                                            <span class="text-muted">(Enter new password to change, or leave blank to keep current)</span>
                                        <?php else: ?>
                                            <span class="text-danger">*</span>
                                        <?php endif; ?>
                                    </label>
                                    <div class="input-group">
                                        <input type="text" name="password" id="passwordInput" class="form-control" 
                                           value="<?php echo $edit_user ? htmlspecialchars($edit_user['PASS_WORD'] ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>"
                                           placeholder="<?php echo $edit_user ? 'Enter new password or leave blank to keep current' : 'Enter password'; ?>"
                                           <?php echo $edit_user ? '' : 'required'; ?>>
                                        <button type="button" class="btn btn-outline-secondary" id="generatePasswordBtn" title="Generate Random Password">
                                            <i class="fas fa-key"></i> Generate
                                        </button>
                                    </div>
                                    <?php if ($edit_user): ?>
                                        <div class="form-text text-muted">
                                            <i class="fas fa-info-circle"></i> Current password is displayed above. Leave it unchanged or enter a new password.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label"><strong>Permission:</strong> <span class="text-danger">*</span></label>
                                    <select name="permission" id="permissionSelect" class="form-select" required>
                                        <?php foreach ($valid_permissions as $perm): ?>
                                            <option value="<?php echo htmlspecialchars($perm); ?>"
                                                    <?php echo (isset($edit_user['PERMISSION']) && $edit_user['PERMISSION'] === $perm) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($perm); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6" id="expertCategoryField" style="display: <?php echo (isset($edit_user['PERMISSION']) && $edit_user['PERMISSION'] === 'Expert_comty_login') ? 'block' : 'none'; ?>;">
                                    <label class="form-label"><strong>Expert Categories:</strong> <span class="text-danger">*</span></label>
                                    <select name="expert_categories[]" id="expertCategorySelect" class="form-select" multiple size="6" style="min-height: 150px;" <?php echo (isset($edit_user['PERMISSION']) && $edit_user['PERMISSION'] === 'Expert_comty_login') ? 'required' : ''; ?>>
                                        <?php foreach ($expert_categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>"
                                                    <?php echo in_array($cat, $edit_user_categories, true) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text text-muted">
                                        <i class="fas fa-info-circle"></i> Hold <kbd>Ctrl</kbd> (Windows) / <kbd>Cmd</kbd> (Mac) to select multiple categories.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> <?php echo $edit_user ? 'Update User' : 'Add User'; ?>
                                </button>
                                <?php if ($edit_user): ?>
                                    <a href="manage_boss_users.php" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Users List -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-users me-2"></i>
                            All Users (<?php echo count($users); ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="usersTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Email</th>
                                        <th>Password</th>
                                        <th>Permission</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?php echo (int)$user['Id']; ?></td>
                                            <td><?php echo htmlspecialchars($user['EMAIL']); ?></td>
                                            <td>
                                                <code><?php echo htmlspecialchars($user['PASS_WORD'] ?? '', ENT_QUOTES, 'UTF-8'); ?></code>
                                            </td>
                                            <td>
                                                <?php
                                                $perm = htmlspecialchars($user['PERMISSION']);
                                                $badge_class = 'bg-secondary';
                                                switch (strtolower($perm)) {
                                                    case 'admin':
                                                        $badge_class = 'bg-danger';
                                                        break;
                                                    case 'expert_comty_login':
                                                        $badge_class = 'bg-primary';
                                                        break;
                                                    case 'aaqa_login':
                                                        $badge_class = 'bg-info';
                                                        break;
                                                    case 'chairman_login':
                                                        $badge_class = 'bg-warning text-dark';
                                                        break;
                                                    case 'verification_committee':
                                                        $badge_class = 'bg-success';
                                                        break;
                                                    case 'dept_login':
                                                        $badge_class = 'bg-dark';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?> permission-badge">
                                                    <?php echo $perm; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <a href="manage_boss_users.php?edit_id=<?php echo (int)$user['Id']; ?>" 
                                                       class="btn btn-sm btn-warning" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <form method="POST" style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to delete user <?php echo htmlspecialchars($user['EMAIL']); ?>? This action cannot be undone.');">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="user_id" value="<?php echo (int)$user['Id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script>
        // Initialize DataTable with enhanced features
        $(document).ready(function() {
            $('#usersTable').DataTable({
                order: [[0, 'asc']],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                language: {
                    search: "<i class='fas fa-search me-2'></i>",
                    searchPlaceholder: "Search users by email, permission, or ID...",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "No entries found",
                    infoFiltered: "(filtered from _MAX_ total entries)",
                    zeroRecords: "No matching records found",
                    paginate: {
                        first: "<i class='fas fa-angle-double-left'></i>",
                        last: "<i class='fas fa-angle-double-right'></i>",
                        next: "<i class='fas fa-angle-right'></i>",
                        previous: "<i class='fas fa-angle-left'></i>"
                    }
                },
                responsive: true,
                columnDefs: [
                    { orderable: true, targets: [0, 1, 2, 3] },
                    { orderable: false, targets: [4] }
                ],
                initComplete: function() {
                    // Add custom styling after initialization
                    $('.dataTables_filter input').attr('placeholder', 'Search users...');
                }
            });
        });
        
        // Sidebar toggle
        var el = document.getElementById("wrapper");
        var toggleButton = document.getElementById("menu-toggle");
        if (toggleButton) {
            toggleButton.onclick = function() {
                el.classList.toggle("toggled");
            };
        }
        
        // Show/hide expert category field based on permission
        function toggleExpertCategory() {
            const permissionSelect = document.getElementById('permissionSelect');
            const expertCategoryField = document.getElementById('expertCategoryField');
            const expertCategorySelect = document.getElementById('expertCategorySelect');
            if (permissionSelect && expertCategoryField && expertCategorySelect) {
                if (permissionSelect.value === 'Expert_comty_login') {
                    expertCategoryField.style.display = 'block';
                    expertCategorySelect.required = true;
                } else {
                    expertCategoryField.style.display = 'none';
                    expertCategorySelect.required = false;
                    // clear all selections when not Expert
                    for (let i = 0; i < expertCategorySelect.options.length; i++) {
                        expertCategorySelect.options[i].selected = false;
                    }
                }
            }
        }
        
        // Initialize on page load
        $(document).ready(function() {
            toggleExpertCategory();
            $('#permissionSelect').on('change', toggleExpertCategory);
        });
        
        // Auto-generate password function
        function generatePassword() {
            const length = 12;
            const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
            let password = "";
            // Ensure at least one of each type
            password += "ABCDEFGHIJKLMNOPQRSTUVWXYZ"[Math.floor(Math.random() * 26)]; // uppercase
            password += "abcdefghijklmnopqrstuvwxyz"[Math.floor(Math.random() * 26)]; // lowercase
            password += "0123456789"[Math.floor(Math.random() * 10)]; // number
            password += "!@#$%^&*"[Math.floor(Math.random() * 8)]; // special char
            
            // Fill the rest randomly
            for (let i = password.length; i < length; i++) {
                password += charset[Math.floor(Math.random() * charset.length)];
            }
            
            // Shuffle the password
            password = password.split('').sort(() => Math.random() - 0.5).join('');
            
            // Set the password field value
            const passwordInput = document.getElementById('passwordInput');
            if (passwordInput) {
                passwordInput.value = password;
                passwordInput.type = 'text'; // Show the generated password
            }
        }
        
        // Attach generate password button
        $(document).ready(function() {
            const generateBtn = document.getElementById('generatePasswordBtn');
            if (generateBtn) {
                generateBtn.addEventListener('click', generatePassword);
            }
        });
        
        // Scroll to form if editing
        <?php if ($edit_user): ?>
        window.onload = function() {
            document.getElementById('userForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
        };
        <?php endif; ?>
    </script>
    <?php include '../footer_main.php'; ?>
</body>
</html>
