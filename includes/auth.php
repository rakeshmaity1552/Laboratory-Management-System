<?php
/**
 * Authentication and Authorization Helpers
 * Laboratory Management System (LMS)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Checks whether the current visitor is logged in.
 *
 * @return bool
 */
function is_logged_in(): bool
{
    return !empty($_SESSION['user']) && !empty($_SESSION['user']['user_id']);
}

/**
 * Checks whether the current logged-in user has the admin role.
 *
 * @return bool
 */
function is_admin(): bool
{
    return is_logged_in() && (($_SESSION['user']['role'] ?? '') === 'admin');
}

/**
 * Returns the currently authenticated user's session record.
 *
 * @return array|null
 */
function get_authenticated_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Returns the current authenticated user's ID, or null if unauthenticated.
 *
 * @return int|null
 */
function get_current_user_id(): ?int
{
    $user = get_authenticated_user();
    return isset($user['user_id']) ? (int)$user['user_id'] : null;
}

/**
 * Enforces authentication. If not logged in, stores the current URL and redirects to login page.
 *
 * @return void
 */
function require_login(): void
{
    if (!is_logged_in()) {
        if (!empty($_SERVER['REQUEST_URI'])) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        }
        
        $loginUrl = defined('APP_URL') ? APP_URL . '/login.php' : '/LMS/login.php';
        
        if (function_exists('set_flash')) {
            set_flash('error', 'Please log in to access this page.');
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please log in to access this page.'];
        }

        header("Location: {$loginUrl}");
        exit;
    }

    // Do not keep disabled accounts authenticated after an administrator disables them.
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT account_status, role, full_name, username FROM users WHERE user_id = :id');
        $stmt->execute([':id' => get_current_user_id()]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account || $account['account_status'] !== 'active') {
            $_SESSION = [];
            session_regenerate_id(true);
            set_flash('error', 'Your account is unavailable. Please contact an administrator.');
            redirect((defined('APP_URL') ? APP_URL : '') . '/login.php');
        }
        $_SESSION['user'] = [
            'user_id' => (int)get_current_user_id(),
            'username' => $account['username'],
            'full_name' => $account['full_name'],
            'role' => $account['role'],
        ];
    } catch (Throwable $e) {
        error_log('Authentication check failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Authentication service is temporarily unavailable.');
    }
}

/**
 * Enforces admin authorization.
 * If not logged in, redirects to login.
 * If logged in but not admin, terminates with HTTP 403 Forbidden.
 *
 * @return void
 */
function require_admin(): void
{
    require_login();

    if (!is_admin()) {
        http_response_code(403);
        
        $homeUrl = defined('APP_URL') ? APP_URL . '/index.php' : '/LMS/index.php';
        
        echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>403 Forbidden - Access Denied</title>";
        echo "<style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#ffffff;color:#1e293b;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}";
        echo ".card{background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:2.5rem;text-align:center;max-width:480px;box-shadow:0 10px 25px rgba(0,0,0,0.5);}";
        echo "h1{color:#ef4444;margin-top:0;}p{color:#64748b;line-height:1.6;}a{display:inline-block;margin-top:1.5rem;color:#2563eb;text-decoration:none;font-weight:600;}";
        echo "</style></head><body><div class='card'>";
        echo "<h1>403 Forbidden</h1>";
        echo "<p>Access denied. You do not have sufficient administrative privileges to view this page.</p>";
        echo "<a href='{$homeUrl}'>&larr; Return to Dashboard</a>";
        echo "</div></body></html>";
        exit;
    }
}

