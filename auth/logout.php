<?php
require_once '../config/config.php';

// Log the logout
if (isLoggedIn()) {
    logActivity($pdo, 'User logged out', 'users', $_SESSION['user_id']);
    logAudit($pdo, 'logout', 'users', $_SESSION['user_id'], null, null, null, 'User logout');
}

// Destroy session
session_destroy();

// Remove remember me cookie
if (isset($_COOKIE['remember_user'])) {
    setcookie('remember_user', '', time() - 3600, '/');
}

// Redirect to login
redirect(APP_URL . '/auth/login.php');
?>
