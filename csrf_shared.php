<?php
// csrf_shared.php - Shared CSRF token utilities for all portals

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get (and lazily generate) a CSRF token for the current session
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token supplied by the client
 */
function validate_csrf(?string $token): bool {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Render a hidden input to include in HTML forms
 */
function csrf_field(): string {
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}
?>

