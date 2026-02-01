<?php
require_once 'config/config.php';

// Redirect to dashboard if logged in, otherwise to login page
if (isLoggedIn()) {
    redirect(APP_URL . '/modules/dashboard/dashboard.php');
} else {
    redirect(APP_URL . '/auth/login.php');
}
?>
