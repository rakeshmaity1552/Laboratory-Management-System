<?php
/**
 * Application Configuration
 * Laboratory Management System (LMS)
 */

// Log errors without exposing paths, SQL, or credentials to browser users.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Send HTML responses as UTF-8 before session startup or page output.
if (!headers_sent() && PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=UTF-8');
}

// Set default timezone to UTC for consistent timestamp storage
date_default_timezone_set('UTC');

// Base Application Constants
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Laboratory Management System');
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0');
}
if (!defined('APP_URL')) {
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/LMS/index.php');
    $projectRoot = preg_replace('~/(?:admin|user|database)/[^/]+$~', '', $scriptName);
    if ($projectRoot === $scriptName) {
        $projectRoot = preg_replace('~/[^/]+\.php$~', '', $scriptName);
    }
    define('APP_URL', rtrim($projectRoot, '/'));
}
if (!defined('ITEMS_PER_PAGE')) {
    define('ITEMS_PER_PAGE', 15);
}
if (!defined('MAX_DESCRIPTION_LENGTH')) {
    define('MAX_DESCRIPTION_LENGTH', 2000);
}
if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');
}

// Allowed Categories
if (!defined('ALLOWED_CATEGORIES')) {
    define('ALLOWED_CATEGORIES', [
        'Hardware',
        'Software',
        'Operating System',
        'Network/Internet',
        'Peripheral',
        'Power',
        'Other'
    ]);
}

// Allowed Priorities
if (!defined('ALLOWED_PRIORITIES')) {
    define('ALLOWED_PRIORITIES', [
        'Low',
        'Medium',
        'High',
        'Critical'
    ]);
}

// Allowed Ticket Statuses
if (!defined('ALLOWED_TICKET_STATUSES')) {
    define('ALLOWED_TICKET_STATUSES', [
        'Open',
        'Acknowledged',
        'In Progress',
        'Waiting for Parts',
        'Resolved',
        'Closed',
        'Rejected'
    ]);
}

// Allowed PC Statuses
if (!defined('ALLOWED_PC_STATUSES')) {
    define('ALLOWED_PC_STATUSES', [
        'Working',
        'Reported Issue',
        'Under Maintenance',
        'Out of Service',
        'Inactive'
    ]);
}

// Session configuration and initialization
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? '1' : '0');
    session_start();
}
