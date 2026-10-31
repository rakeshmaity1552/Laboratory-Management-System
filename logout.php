<?php
/**
 * Laboratory Management System (LMS)
 * Authentication - Logout Handler
 *
 * Clears and destroys the active session, expires its cookie,
 * and redirects to the app's login page.
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
check_csrf();

if (session_status() === PHP_SESSION_ACTIVE) {
    // Remove authentication and all other state from the current session.
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

$loginUrl = (defined('APP_URL') ? APP_URL : '') . '/login.php';
header('Location: ' . $loginUrl, true, 303);
exit;
