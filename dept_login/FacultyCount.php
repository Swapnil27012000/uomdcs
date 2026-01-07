<?php
// FacultyCount - Faculty count form
require('session.php');
require('unified_header.php');
// $A_YEAR is already set by unified_header.php using centralized getAcademicYear() function

$dept = $_SESSION['dept_id'];

// Check if data already exists for this department
$existing_data = null;
$check_existing_query = "SELECT * FROM faculty_count WHERE DEPT_ID = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $check_existing_query);
mysqli_stmt_bind_param($stmt, 'i', $dept);
mysqli_stmt_execute($stmt);
$check_existing = mysqli_stmt_get_result($stmt);

if ($check_existing && mysqli_num_rows($check_existing) > 0) {
    $existing_data = mysqli_fetch_assoc($check_existing);
}

// Initialize variables
$form_locked = false;
$success_message = '';
$error_message = '';

// Check if data already exists
if ($existing_data) {
    $form_locked = true;
}    

// Handle clear data action via POST (not GET) with CSRF protection
if (isset($_POST['clear_data']) && isset($_POST['confirm_clear'])) {
    // CSRF validation
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
        if (function_exists('validate_csrf')) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (empty($csrf) || !validate_csrf($csrf)) {
                header('Location: FacultyCount.php?error=csrf_failed');
                exit;
            }
        }
    }
    
    $stmt = mysqli_prepare($conn, "DELETE FROM faculty_count WHERE DEPT_ID = ?");
    mysqli_stmt_bind_param($stmt, 'i', $dept);
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        header('Location: FacultyCount.php?success=cleared');
        exit;
    } else {
        mysqli_stmt_close($stmt);
        header('Location: FacultyCount.php?error=clear_failed');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_faculty']) || isset($_POST['update_faculty']))) {
    // CSRF validation
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once(__DIR__ . '/csrf.php');
        if (function_exists('validate_csrf')) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (empty($csrf) || !validate_csrf($csrf)) {
                header('Location: FacultyCount.php?error=csrf_failed');
                exit;
            }
        }
    }
    
    try {
        // Read numeric inputs safely with validation
	$male = isset($_POST['male_faculty']) ? intval($_POST['male_faculty']) : 0;
	$female = isset($_POST['female_faculty']) ? intval($_POST['female_faculty']) : 0;
	$other = isset($_POST['other']) ? intval($_POST['other']) : 0;
	$num_perm_phd = isset($_POST['num_perm_phd']) ? intval($_POST['num_perm_phd']) : 0;
	$num_adhoc_phd = isset($_POST['num_adhoc_phd']) ? intval($_POST['num_adhoc_phd']) : 0;

        // Basic guard: prevent negatives and validate ranges
        $male = max(0, min(99999, $male));
        $female = max(0, min(99999, $female));
        $other = max(0, min(99999, $other));
        $num_perm_phd = max(0, min(99999, $num_perm_phd));
        $num_adhoc_phd = max(0, min(99999, $num_adhoc_phd));

        // For single entry system, always update if exists, insert if not
        if ($existing_data) {
            // UPDATE existing record using prepared statement (DEPT_ID doesn't need to be updated)
            $update_query = "UPDATE faculty_count SET A_YEAR = ?, NUM_OF_INTERN_MALE_FACULTY = ?, NUM_OF_INTERN_FEMALE_FACULTY = ?, NUM_OF_INTERN_OTHER_FACULTY = ?, NUM_OF_PERM_FACULTY_PHD = ?, NUM_OF_ADHOC_TEACHERS_PHD = ? WHERE DEPT_ID = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            
            if ($update_stmt) {
                mysqli_stmt_bind_param($update_stmt, 'siiiiii', $A_YEAR, $male, $female, $other, $num_perm_phd, $num_adhoc_phd, $dept);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    mysqli_stmt_close($update_stmt);
                    header('Location: FacultyCount.php?success=updated');
                    exit;
                } else {
                    mysqli_stmt_close($update_stmt);
                    throw new Exception("Error updating record: " . mysqli_stmt_error($update_stmt));
                }
            } else {
                throw new Exception("Error preparing update query: " . mysqli_error($conn));
            }
        } else {
            // INSERT new record using prepared statement
            $insert_query = "INSERT INTO faculty_count (A_YEAR, DEPT_ID, NUM_OF_INTERN_MALE_FACULTY, NUM_OF_INTERN_FEMALE_FACULTY, NUM_OF_INTERN_OTHER_FACULTY, NUM_OF_PERM_FACULTY_PHD, NUM_OF_ADHOC_TEACHERS_PHD) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            
            if ($insert_stmt) {
                mysqli_stmt_bind_param($insert_stmt, 'siiiiii', $A_YEAR, $dept, $male, $female, $other, $num_perm_phd, $num_adhoc_phd);
            
                if (mysqli_stmt_execute($insert_stmt)) {
                    mysqli_stmt_close($insert_stmt);
                    header('Location: FacultyCount.php?success=saved');
                    exit;
                } else {
                    mysqli_stmt_close($insert_stmt);
                    throw new Exception("Error adding record: " . mysqli_stmt_error($insert_stmt));
                }
    } else {
                throw new Exception("Error preparing insert query: " . mysqli_error($conn));
            }
        }
    } catch (Exception $e) {
        header('Location: FacultyCount.php?error=save_failed');
        exit;
    }
}
?>
<div class="container-fluid" style="max-width: 100%; overflow-x: hidden;">
    <div class="main-content-area">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-users me-3"></i>Faculty Count
            </h1>
            <p class="page-subtitle">Manage faculty count and distribution data</p>
        </div>

        <div class="card">
            <div class="card-body">
                <form class="modern-form" method="POST" enctype="multipart/form-data" autocomplete="off">
                    <!-- Important Instructions -->
                    <div class="alert alert-warning">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i><b>Important Guidelines:</b></h5>
                        <ul class="mb-0">
                            <li><b>Enter accurate faculty count for each category</b></li>
                            <li><b>Include all faculty members including visiting and adjunct faculty</b></li>
                            <li><b>Ensure total counts match actual faculty strength</b></li>
                            <li><b>Update data regularly to maintain accuracy</b></li>
                        </ul>   
                    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" id="successAlert">
            <?php 
                $success_messages = [
                    'saved' => 'Faculty count data saved successfully!',
                    'updated' => 'Faculty count data updated successfully!',
                    'cleared' => 'Faculty count data cleared successfully!'
                ];
                echo $success_messages[$_GET['success']] ?? 'Operation completed successfully!';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
            setTimeout(function() {
                const successAlert = document.getElementById('successAlert');
                if (successAlert) {
                    successAlert.style.transition = 'opacity 0.5s';
                    successAlert.style.opacity = '0';
                    setTimeout(() => successAlert.remove(), 500);
                }
                disableForm();
            }, 2000);
                </script>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php 
                $error_messages = [
                    'save_failed' => 'Failed to save faculty count data. Please try again.',
                    'csrf_failed' => 'Security validation failed. Please refresh and try again.',
                    'clear_failed' => 'Failed to clear data. Please try again.'
                ];
                echo $error_messages[$_GET['error']] ?? 'An error occurred. Please try again.';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
    <?php endif; ?>

                    <?php if ($form_locked): ?>
                        <div class="alert alert-info">
            <strong>Form Status:</strong> Data has been submitted. Click "Update" to modify existing data.
                        </div>
                    <?php endif; ?>

    <form method="POST" id="facultyForm" onsubmit="return validateForm()">
                    <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                    <!-- Faculty Count Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-chalkboard-teacher me-2"></i>Faculty Count Distribution
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label"><b>Male Faculty *</b></label>
                                    <input type="number" name="male_faculty" class="form-control" placeholder="Enter Count" 
                                           value="<?php echo $existing_data ? intval($existing_data['NUM_OF_INTERN_MALE_FACULTY']) : '0' ?>" 
                                           <?php echo $form_locked ? 'readonly disabled' : ''; ?> min="0" required>
                                    <div class="form-text">If none, leave as 0.</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label"><b>Female Faculty *</b></label>
                                    <input type="number" name="female_faculty" class="form-control" placeholder="Enter Count" 
                                           value="<?php echo $existing_data ? intval($existing_data['NUM_OF_INTERN_FEMALE_FACULTY']) : '0' ?>" 
                                           <?php echo $form_locked ? 'readonly disabled' : ''; ?> min="0" required>
                                    <div class="form-text">If none, leave as 0.</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label"><b>Other Faculty *</b></label>
                                    <input type="number" name="other" class="form-control" placeholder="Enter Count" 
                                           value="<?php echo $existing_data ? intval($existing_data['NUM_OF_INTERN_OTHER_FACULTY']) : '0' ?>" 
                                           <?php echo $form_locked ? 'readonly disabled' : ''; ?> min="0">
                                    <div class="form-text">If none, leave as 0.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PhD Faculty Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-graduation-cap me-2"></i>PhD Faculty Details
                        </h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><b>Permanent Faculty with PhD *</b></label>
                                    <input type="number" name="num_perm_phd" class="form-control" placeholder="Enter Count" 
                                           value="<?php echo $existing_data ? intval($existing_data['NUM_OF_PERM_FACULTY_PHD']) : '0' ?>" 
                                           <?php echo $form_locked ? 'readonly disabled' : ''; ?> min="0">
                                    <div class="form-text">If none, leave as 0.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><b>Adhoc Teachers with PhD *</b></label>
                                    <input type="number" name="num_adhoc_phd" class="form-control" placeholder="Enter Count" 
                                           value="<?php echo $existing_data ? intval($existing_data['NUM_OF_ADHOC_TEACHERS_PHD']) : '0' ?>" 
                                           <?php echo $form_locked ? 'readonly disabled' : ''; ?> min="0">
                                    <div class="form-text">If none, leave as 0.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="text-center mt-4">
                        <div class="d-flex flex-wrap justify-content-center gap-3">
                            <?php if ($form_locked): ?>
                                <!-- Form is locked - show Update and Clear Data buttons -->
                                <button type="button" class="btn btn-warning btn-lg px-5" onclick="enableUpdate()">
                                    <i class="fas fa-edit me-2"></i>Update Data
                                </button>
                                <button type="submit" name="update_faculty" class="btn btn-success btn-lg px-5" id="updateBtn" style="display:none;">
                                    <i class="fas fa-save me-2"></i>Save Changes
                                </button>
                                <form method="POST" style="display:inline;" onsubmit="return confirmClearData();">
                                    <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                                    <input type="hidden" name="clear_data" value="1">
                                    <input type="hidden" name="confirm_clear" value="1">
                                    <button type="submit" class="btn btn-danger btn-lg px-5">
                                    <i class="fas fa-trash me-2"></i>Clear Data
                                    </button>
                                </form>
                            <?php else: ?>
                                <!-- Form is unlocked - show Submit button -->
                                <button type="submit" name="save_faculty" class="btn btn-primary btn-lg px-5">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Data
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    body {
        background: linear-gradient(135deg, #f6f8fa 0%, #e9ecef 100%);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .card {
        background: #fff;
        border: 1px solid #e3e6ea;
    }

    label.form-label {
        font-weight: 500;
        color: #2b3a55;
        margin-bottom: 6px;
    }

    input[type="number"],
    input[type="text"],
    input[type="date"],
    input[type="month"],
    input[type="url"],
    textarea,
    select {
        border-radius: 0.5rem !important;
        border: 1px solid #ced4da !important;
        background: #fff !important;
        font-size: 1rem;
        padding: 0.75rem 1rem !important;
        transition: all 0.3s ease;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    input:focus,
    textarea:focus,
    select:focus {
        border-color: #80bdff !important;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25) !important;
        background: #fff !important;
        outline: none;
    }
    
    input:hover,
    textarea:hover,
    select:hover {
        border-color: #adb5bd !important;
    }

    input[readonly],
    textarea[readonly],
    select[readonly] {
        background-color: #f8f9fa !important;
        color: #6c757d !important;
        cursor: not-allowed !important;
    }

    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .btn {
        font-weight: 500;
        border-radius: 0.375rem;
        transition: all 0.15s ease-in-out;
        border: 1px solid transparent;
        padding: 0.5rem 1rem;
        font-size: 1rem;
        line-height: 1.5;
    }

    .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    .btn:active {
        transform: translateY(0);
    }

    .btn-primary {
        background-color: #0d6efd;
        border-color: #0d6efd;
        color: #fff;
    }

    .btn-primary:hover {
        background-color: #0b5ed7;
        border-color: #0a58ca;
    }

    .btn-success {
        background-color: #198754;
        border-color: #198754;
        color: #fff;
    }

    .btn-success:hover {
        background-color: #157347;
        border-color: #146c43;
    }

    .btn-warning {
        background-color: #ffc107;
        border-color: #ffc107;
        color: #000;
    }

    .btn-warning:hover {
        background-color: #ffca2c;
        border-color: #ffc720;
    }

    .btn-danger {
        background-color: #dc3545;
        border-color: #dc3545;
        color: #fff;
    }

    .btn-danger:hover {
        background-color: #bb2d3b;
        border-color: #b02a37;
    }

    .btn i {
        font-size: 1em;
    }

    .form-text {
        font-size: 0.875em;
        color: #6c757d;
        margin-top: 0.25rem;
    }

    .alert {
        border-radius: 0.5rem;
        border: 1px solid transparent;
    }

    .alert-success {
        background-color: #d1e7dd;
        border-color: #badbcc;
        color: #0f5132;
    }

    .alert-danger {
        background-color: #f8d7da;
        border-color: #f5c2c7;
        color: #842029;
    }

    .alert-info {
        background-color: #d1ecf1;
        border-color: #bee5eb;
        color: #055160;
    }

    @media (max-width: 767px) {
        .card {
            margin: 16px;
            padding: 16px !important;
        }
        
        .btn {
            width: 100%;
            margin-bottom: 0.5rem;
        }
    }
</style>

<script>
// Form validation function
function validateForm() {
    const male = parseInt(document.querySelector('input[name="male_faculty"]').value) || 0;
    const female = parseInt(document.querySelector('input[name="female_faculty"]').value) || 0;
    const other = parseInt(document.querySelector('input[name="other"]').value) || 0;
    const permPhd = parseInt(document.querySelector('input[name="num_perm_phd"]').value) || 0;
    const adhocPhd = parseInt(document.querySelector('input[name="num_adhoc_phd"]').value) || 0;
    
    // Check if any data is provided
    if (male === 0 && female === 0 && other === 0 && permPhd === 0 && adhocPhd === 0) {
        showSmoothMessage('Please enter at least some data in the form before submitting.', 'warning');
        return false;
    }

    // Validate numeric ranges
    if (male < 0 || male > 99999) {
        showSmoothMessage('Male faculty count must be between 0 and 99999.', 'error');
        return false;
    }
    if (female < 0 || female > 99999) {
        showSmoothMessage('Female faculty count must be between 0 and 99999.', 'error');
        return false;
    }
    if (other < 0 || other > 99999) {
        showSmoothMessage('Other faculty count must be between 0 and 99999.', 'error');
        return false;
    }
    if (permPhd < 0 || permPhd > 99999) {
        showSmoothMessage('Permanent PhD faculty count must be between 0 and 99999.', 'error');
        return false;
    }
    if (adhocPhd < 0 || adhocPhd > 99999) {
        showSmoothMessage('Adhoc PhD faculty count must be between 0 and 99999.', 'error');
    return false;
}

        return true;
    }

// Function to disable all form fields (make them read-only)
function disableForm() {
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        input.setAttribute('readonly', 'readonly');
        input.disabled = true;
        input.style.pointerEvents = 'none';
        input.style.cursor = 'not-allowed';
        input.style.backgroundColor = '#f8f9fa';
    });
    
    // Hide Save Changes button, show Update button
    const updateBtn = document.getElementById('updateBtn');
    const updateTriggerBtn = document.querySelector('button[onclick="enableUpdate()"]');
    if (updateBtn) updateBtn.style.display = 'none';
    if (updateTriggerBtn) updateTriggerBtn.style.display = 'inline-block';
}

function enableUpdate() {
    // Enable all form inputs
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        input.removeAttribute('readonly');
        input.disabled = false;
        input.style.pointerEvents = 'auto';
        input.style.cursor = 'text';
        input.style.backgroundColor = '#fff';
    });
    
    // Show Save Changes button, hide Update button
    const updateBtn = document.getElementById('updateBtn');
    const updateTriggerBtn = document.querySelector('button[onclick="enableUpdate()"]');
    if (updateBtn) updateBtn.style.display = 'inline-block';
    if (updateTriggerBtn) updateTriggerBtn.style.display = 'none';
    
    showSmoothMessage('Form is now editable. Make your changes and click "Save Changes" to update.', 'info');
}

function confirmClearData() {
    if (confirm('Are you sure you want to clear all data? This action cannot be undone!')) {
        showSmoothMessage('Clearing faculty count data...', 'info');
        return true;
    }
    return false;
}

// Smooth message function
function showSmoothMessage(message, type = 'success') {
    // Remove existing messages
    const existing = document.querySelectorAll('.smooth-message');
    existing.forEach(msg => msg.remove());

    const messageDiv = document.createElement('div');
    messageDiv.className = `smooth-message smooth-message-${type}`;
    messageDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        padding: 1rem 1.5rem;
        border-radius: 0.75rem;
        color: white;
        font-weight: 600;
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
        transform: translateX(100%);
        transition: transform 0.3s ease;
        max-width: 400px;
    `;

    const colors = {
        success: 'linear-gradient(135deg, #28a745, #20c997)',
        error: 'linear-gradient(135deg, #dc3545, #e74c3c)',
        warning: 'linear-gradient(135deg, #ffc107, #f39c12)',
        info: 'linear-gradient(135deg, #17a2b8, #3498db)'
    };
    messageDiv.style.background = colors[type] || colors.success;

    messageDiv.innerHTML = `
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span>${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: white; font-size: 1.2rem; cursor: pointer; margin-left: auto;">×</button>
        </div>
    `;

    document.body.appendChild(messageDiv);

    setTimeout(() => {
        messageDiv.style.transform = 'translateX(0)';
    }, 100);

    setTimeout(() => {
        if (messageDiv.parentNode) {
            messageDiv.style.transform = 'translateX(100%)';
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    messageDiv.remove();
                }
            }, 300);
        }
    }, 2000);
}

// Initialize form on page load
window.onload = function() {
    // Lock form if it should be locked
    <?php if ($form_locked): ?>
    setTimeout(function() {
        disableForm();
    }, 200);
    <?php endif; ?>
};
</script>
<?php
require "unified_footer.php";
?>





