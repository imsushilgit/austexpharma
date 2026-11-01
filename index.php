<?php
/**
 * Root entry point.
 * Sends logged-in users to the dashboard and everyone else to the login page.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fixed: Redirecting directly to dashboard.php in the root folder
$target = !empty($_SESSION['user_id']) ? 'dashboard.php' : 'auth/login.php';
$qs =$_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . $target . ($qs !== '' ? '?' .$qs : ''));
exit;