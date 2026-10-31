<?php
/**
 * CSRF Protection Helpers
 * Laboratory Management System (LMS)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generates and stores a cryptographically secure CSRF token in the session
 * if one does not already exist, and returns it.
 *
 * @return string
 */
function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Returns an HTML hidden input tag containing the active CSRF token.
 *
 * @return string
 */
function csrf_token_field(): string
{
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
}

/**
 * Validates a submitted CSRF token against the session token using hash_equals.
 *
 * @param string|null $token
 * @return bool
 */
function validate_csrf_token(?string $token): bool
{
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], (string)$token);
}

/**
 * Validates CSRF token for state-changing HTTP requests (POST, PUT, DELETE, PATCH).
 * Immediately terminates execution with HTTP 403 Forbidden on failure.
 *
 * @return void
 */
function check_csrf(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    
    if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        
        if (!validate_csrf_token($token)) {
            http_response_code(403);
            
            $backUrl = $_SERVER['HTTP_REFERER'] ?? (defined('APP_URL') ? APP_URL : '/LMS');
            
            echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>403 Forbidden - Security Token Invalid</title>";
            echo "<style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#ffffff;color:#1e293b;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}";
            echo ".card{background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:2.5rem;text-align:center;max-width:500px;box-shadow:0 10px 25px rgba(0,0,0,0.5);}";
            echo "h1{color:#ef4444;margin-top:0;}p{color:#64748b;line-height:1.6;}a{display:inline-block;margin-top:1.5rem;color:#2563eb;text-decoration:none;font-weight:600;}";
            echo "</style></head><body><div class='card'>";
            echo "<h1>403 Forbidden</h1>";
            echo "<p>Your security session token (CSRF token) has expired or is invalid. Please reload the previous page and try again.</p>";
            echo "<a href='" . htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') . "'>&larr; Back to Previous Page</a>";
            echo "</div></body></html>";
            exit;
        }
    }
}

