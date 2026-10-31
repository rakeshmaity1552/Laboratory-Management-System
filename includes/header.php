<?php
/**
 * Shared Header Layout
 * Laboratory Management System (LMS)
 *
 * Expected incoming variables:
 * @var string|null $page_title Custom page title (optional)
 */

// Core includes with relative path independence
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

$appTitle = defined('APP_NAME') ? APP_NAME : 'Laboratory Management System';
$documentTitle = !empty($page_title) ? htmlspecialchars($page_title) . " &mdash; " . $appTitle : $appTitle;
$baseUrl = defined('APP_URL') ? APP_URL : '/LMS';
$currentUser = get_authenticated_user();
$isLoggedIn = is_logged_in();
$isAdmin = is_admin();
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= $documentTitle ?></title>
    
    <!-- Application Main Stylesheet -->
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/style.css">
    
    <!-- Inline fallback baseline styles for navigation, badges, and alerts -->
    <style>
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #ffffff;
            --bg-tertiary: #e2e8f0;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --border: #e2e8f0;
            --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.6;
        }

        /* Top Navbar */
        .site-nav {
            background-color: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-container {
            max-width: 1300px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
        }

        .brand-link {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-menu {
            display: flex;
            align-items: center;
            list-style: none;
            gap: 1.25rem;
            margin: 0;
            padding: 0;
        }

        .nav-menu a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.925rem;
            font-weight: 500;
            transition: color 0.15s ease-in-out;
            padding: 0.35rem 0.6rem;
            border-radius: 4px;
        }

        .nav-menu a:hover, .nav-menu a.active {
            color: var(--text-primary);
            background-color: rgba(15, 23, 42, 0.05);
        }

        .nav-user {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-left: auto;
        }

        .user-greeting {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .user-greeting strong {
            color: var(--text-primary);
        }

        .btn-logout {
            color: #f87171 !important;
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 4px;
            padding: 0.3rem 0.75rem !important;
            font-size: 0.85rem !important;
            text-decoration: none;
            transition: all 0.15s ease-in-out;
        }

        .btn-logout:hover {
            background-color: var(--danger) !important;
            color: #ffffff !important;
            border-color: var(--danger);
        }

        /* Role Badge */
        .role-badge {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            padding: 0.15rem 0.5rem;
            border-radius: 9999px;
            letter-spacing: 0.05em;
        }

        .role-badge-admin {
            background-color: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.4);
        }

        .role-badge-user {
            background-color: rgba(56, 189, 248, 0.2);
            color: #7dd3fc;
            border: 1px solid rgba(56, 189, 248, 0.4);
        }

        /* Status & Priority Badges */
        .badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 4px;
            text-transform: capitalize;
        }

        .badge-status-open { background: #0284c7; color: #fff; }
        .badge-status-acknowledged { background: #6366f1; color: #fff; }
        .badge-status-in-progress { background: #d97706; color: #fff; }
        .badge-status-waiting { background: #7c3aed; color: #fff; }
        .badge-status-resolved { background: #059669; color: #fff; }
        .badge-status-closed { background: #4b5563; color: #fff; }
        .badge-status-rejected { background: #dc2626; color: #fff; }
        
        .badge-status-working { background: #15803d; color: #fff; }
        .badge-status-reported-issue { background: #b45309; color: #fff; }
        .badge-status-maintenance { background: #6d28d9; color: #fff; }
        .badge-status-out-of-service { background: #b91c1c; color: #fff; }
        .badge-status-inactive { background: #374151; color: #9ca3af; }

        .badge-priority-critical { background: #dc2626; color: #fff; }
        .badge-priority-high { background: #ea580c; color: #fff; }
        .badge-priority-medium { background: #d97706; color: #fff; }
        .badge-priority-low { background: #4b5563; color: #fff; }

        /* Flash Message Alert Container */
        .flash-wrapper {
            max-width: 1300px;
            margin: 1.25rem auto 0;
            padding: 0 1.5rem;
            width: 100%;
        }

        .flash-alert {
            padding: 0.85rem 1.25rem;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.95rem;
            margin-bottom: 1rem;
        }

        .flash-alert-success {
            background-color: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.4);
            color: #86efac;
        }

        .flash-alert-error {
            background-color: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fca5a5;
        }

        .flash-alert-warning {
            background-color: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.4);
            color: #fde68a;
        }

        .flash-alert-info {
            background-color: rgba(56, 189, 248, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.4);
            color: #bae6fd;
        }

        .flash-close {
            background: transparent;
            border: none;
            color: inherit;
            font-size: 1.25rem;
            cursor: pointer;
            padding: 0 0.5rem;
            opacity: 0.7;
            transition: opacity 0.15s;
        }

        .flash-close:hover {
            opacity: 1;
        }

        /* Main Content Container */
        .app-content {
            flex: 1 0 auto;
            max-width: 1300px;
            width: 100%;
            margin: 1.5rem auto;
            padding: 0 1.5rem;
        }
    </style>
</head>
<body>
    <!-- Site Navigation Bar -->
    <header class="site-nav">
        <div class="nav-container">
            <a href="<?= $baseUrl ?>/index.php" class="brand-link">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="8" y1="21" x2="16" y2="21"></line>
                    <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
                <span><?= htmlspecialchars($appTitle) ?></span>
            </a>

            <nav>
                <ul class="nav-menu">
                    <?php if ($isLoggedIn): ?>
                        <?php if ($isAdmin): ?>
                            <!-- Admin Navigation Menu -->
                            <li><a href="<?= $baseUrl ?>/admin/dashboard.php">Admin Dashboard</a></li>
                            <li><a href="<?= $baseUrl ?>/admin/pcs.php">Manage PCs</a></li>
                            <li><a href="<?= $baseUrl ?>/admin/tickets.php">Manage Tickets</a></li>
                            <li><a href="<?= $baseUrl ?>/admin/users.php">Manage Users</a></li>
                        <?php else: ?>
                            <!-- Regular User Navigation Menu -->
                            <li><a href="<?= $baseUrl ?>/user/dashboard.php">Dashboard</a></li>
                            <li><a href="<?= $baseUrl ?>/user/my-tickets.php">My Tickets</a></li>
                            <li><a href="<?= $baseUrl ?>/user/create-ticket.php">Report Issue</a></li>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- Public / Guest Navigation Menu -->
                        <li><a href="<?= $baseUrl ?>/index.php">Home</a></li>
                        <li><a href="<?= $baseUrl ?>/login.php">Login</a></li>
                    <?php endif; ?>
                </ul>
            </nav>

            <div class="nav-user">
                <?php if ($isLoggedIn): ?>
                    <span class="user-greeting">
                        <?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) ?>
                        <span class="role-badge <?= $isAdmin ? 'role-badge-admin' : 'role-badge-user' ?>">
                            <?= htmlspecialchars(strtoupper($currentUser['role'] ?? 'user')) ?>
                        </span>
                    </span>
                    <form method="post" action="<?= $baseUrl ?>/logout.php" style="display:inline">
                        <?= csrf_token_field() ?>
                        <button type="submit" class="btn-logout" style="background:transparent;cursor:pointer">Logout</button>
                    </form>
                <?php else: ?>
                    <a href="<?= $baseUrl ?>/login.php" class="btn-logout" style="color: var(--primary) !important; border-color: var(--primary);">Sign In</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Global Flash Messages Notification Bar -->
    <?php if ($flash): ?>
        <div class="flash-wrapper">
            <div class="flash-alert flash-alert-<?= htmlspecialchars($flash['type']) ?>">
                <span><?= htmlspecialchars($flash['message']) ?></span>
                <button type="button" class="flash-close" onclick="this.parentElement.remove();" aria-label="Dismiss">&times;</button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main Content Wrapper -->
    <main class="app-content">


