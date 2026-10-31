<?php
/**
 * Laboratory Management System (LMS)
 * Authentication - Login Page
 *
 * Self-contained login view handling secure credential verification,
 * CSRF protection, active account validation, and session regeneration.
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

// Redirect already authenticated users to their landing router
if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
$username_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    $valid_csrf = false;
    if (function_exists('validate_csrf_token')) {
        $valid_csrf = validate_csrf_token($token);
    } elseif (function_exists('check_csrf')) {
        $valid_csrf = check_csrf();
    } elseif (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        $valid_csrf = true;
    }

    if (!$valid_csrf) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        // 2. Validate input fields are not empty
        $username_val = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username_val === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            // 3. Database lookup and credential verification
            try {
                $db = getDB();
                $stmt = $db->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
                $stmt->execute([':username' => $username_val]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                $password_hash = $user['password_hash'] ?? '';
                $account_status = $user['account_status'] ?? '';

                if ($user && password_verify($password, $password_hash)) {
                    if ($account_status === 'active') {
                        // Success: Regenerate session ID to prevent session fixation attacks
                        session_regenerate_id(true);

                        // Store essential user credentials and profile in session
                        $_SESSION['user_id'] = (int)$user['user_id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['user'] = [
                            'user_id' => (int)$user['user_id'],
                            'username' => $user['username'],
                            'full_name' => $user['full_name'],
                            'role' => $user['role'],
                            'account_status' => $account_status
                        ];

                        // Flash success message
                        $welcome_name = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
                        set_flash('success', 'Welcome back, ' . $welcome_name . '!');

                        // Redirect to index router
                        redirect('index.php');
                    } else {
                        // Account disabled / suspended
                        $error = 'Invalid credentials or account disabled';
                    }
                } else {
                    // Invalid username or password
                    $error = 'Invalid credentials or account disabled';
                }
            } catch (PDOException $e) {
                error_log("Login DB Exception: " . $e->getMessage());
                $error = 'A database connection error occurred. Please contact administrator.';
            }
        }
    }
}

// Retrieve any flash messages set prior to login (e.g. from logout)
$flash_success = function_exists('get_flash') ? get_flash('success') : ($_SESSION['flash']['success'] ?? null);
$flash_error = function_exists('get_flash') ? get_flash('error') : ($_SESSION['flash']['error'] ?? null);
if ($flash_error && empty($error)) {
    $error = $flash_error;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Laboratory Management System') ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-icon">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                        <line x1="8" y1="21" x2="16" y2="21"></line>
                        <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                </div>
                <h1 class="login-title"><?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Lab Management') ?></h1>
                <p class="login-subtitle">College Computer Laboratory Management Portal</p>
            </div>

            <div class="login-body">
                <?php if (!empty($flash_success)): ?>
                    <div class="alert alert-success" role="alert">
                        <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                        <div class="alert-content"><?= htmlspecialchars($flash_success) ?></div>
                        <button type="button" class="alert-close" aria-label="Close">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-error" role="alert">
                        <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                        <div class="alert-content"><?= htmlspecialchars($error) ?></div>
                        <button type="button" class="alert-close" aria-label="Close">&times;</button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php" autocomplete="on" id="loginForm">
                    <?php if (function_exists('csrf_token_field')): ?>
                        <?= csrf_token_field() ?>
                    <?php else: ?>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('generate_csrf_token') ? generate_csrf_token() : ($_SESSION['csrf_token'] ?? '')) ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="username" class="form-label required">Username</label>
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            class="form-control" 
                            placeholder="Enter username" 
                            value="<?= htmlspecialchars($username_val) ?>" 
                            required 
                            autofocus
                            autocomplete="username"
                        >
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label required">Password</label>
                        <div class="password-input-wrapper">
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-control" 
                                placeholder="Enter password" 
                                required
                                autocomplete="current-password"
                            >
                            <button type="button" class="password-toggle-btn" id="togglePasswordBtn" aria-label="Toggle password visibility">
                                <svg class="eye-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-group mt-6">
                        <button type="submit" class="btn btn-primary btn-block btn-lg" id="loginSubmitBtn">
                            <span>Sign In</span>
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                <polyline points="12 5 19 12 12 19"></polyline>
                            </svg>
                        </button>
                    </div>
                </form>
            </div>

            <div class="login-footer">
                <p>&copy; <?= date('Y') ?> <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Laboratory Management System') ?>. All rights reserved.</p>
                <p class="mt-1">For support or password reset, contact the Lab Administrator.</p>
            </div>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
    <script>
        // Password visibility toggle handler
        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('togglePasswordBtn');
            const passwordInput = document.getElementById('password');
            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', function() {
                    const isPassword = passwordInput.getAttribute('type') === 'password';
                    passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                    toggleBtn.innerHTML = isPassword 
                        ? `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>`
                        : `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;
                });
            }
        });
    </script>
</body>
</html>
