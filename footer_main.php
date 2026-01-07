<footer class="bg-gray-800 bg-opacity-90 text-white text-center py-3 text-sm">
    © <?php echo date("Y"); ?> University of Mumbai - NIRF Portal
</footer>

<?php 
// CRITICAL: DO NOT close persistent connections - they are reused across requests
// Persistent connections (with 'p:' prefix in config.php) are managed by PHP/MySQL
// Closing them defeats the purpose of connection pooling and can cause connection exhaustion
// The connection will be automatically closed when the PHP process ends
// Only unset the variable to prevent accidental use after page load
if (isset($conn)) {
    unset($conn);
}
?>