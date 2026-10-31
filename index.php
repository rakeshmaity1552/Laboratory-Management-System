<?php
/**
 * Laboratory Management System (LMS)
 * Landing Page / Route Dispatcher
 *
 * Routes authenticated users to their corresponding dashboard (admin vs user)
 * and unauthenticated visitors to the login page.
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Check authentication status and dispatch
if (is_logged_in()) {
    if (is_admin()) {
        redirect('admin/dashboard.php');
    } else {
        redirect('user/dashboard.php');
    }
} else {
    redirect('login.php');
}
