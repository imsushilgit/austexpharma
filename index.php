<?php
/**
 * Root entry point.
 * Sends logged-in users to the dashboard and everyone else to the login page.
 * Also keeps old "index.php?access=denied" redirects working.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$target = !empty($_SESSION['user_id']) ? 'dashboard/dashboard.php' : 'auth/login.php';
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . $target . ($qs !== '' ? '?' . $qs : ''));
exit;
