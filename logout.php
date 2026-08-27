<?php
/**
 * Global Logout
 * PHP 7.1+
 */

define('APP_INIT', true);

require_once __DIR__ . '/app/core/auth.php';

// If your auth system has a logout helper
if (function_exists('auth_logout')) {
    auth_logout();
} else {
    // Fallback (safe)
    session_start();
    $_SESSION = [];
    session_destroy();
}

// Prevent caching after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Redirect to login (or homepage)
header("Location: /login.php");
exit;
