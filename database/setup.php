<?php
/**
 * Database Setup and Initialization Script
 * Laboratory Management System (LMS)
 *
 * This script initializes the SQLite database, executes the schema,
 * seeds the initial PC inventory, and creates the default admin user.
 * It is fully idempotent and safe to run multiple times.
 */

// Initialization runs from the command line only.
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) { http_response_code(404); exit('Not found. Run database/setup.php from the command line.'); }

function output_message(string $msg, string $type = 'info', bool $isCli = false): void
{
    if ($isCli) {
        $prefix = match ($type) {
            'success' => '[SUCCESS] ',
            'error'   => '[ERROR] ',
            'warning' => '[WARNING] ',
            default   => '[INFO] '
        };
        echo $prefix . $msg . PHP_EOL;
    } else {
        $color = match ($type) {
            'success' => '#16a34a',
            'error'   => '#dc2626',
            'warning' => '#d97706',
            default   => '#2563eb'
        };
        echo "<p style='color: {$color}; margin: 6px 0; font-family: monospace;'><strong>[" . strtoupper($type) . "]</strong> " . htmlspecialchars($msg) . "</p>";
    }
}

if (!$isCli) {
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>LMS Database Setup</title>";
    echo "<style>body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem; max-width: 800px; margin: 0 auto; line-height: 1.5; } h1 { color: #38bdf8; } .box { background: #1e293b; border-radius: 8px; padding: 1.5rem; border: 1px solid #334155; margin-top: 1rem; }</style>";
    echo "</head><body><h1>Laboratory Management System &mdash; Database Setup</h1><div class='box'>";
}

try {
    // 1. Load Database Connection
    require_once __DIR__ . '/../config/database.php';
    output_message('Connecting to database at: ' . DB_PATH, 'info', $isCli);
    $db = getDB();
    output_message('Connected to SQLite database successfully.', 'success', $isCli);

    // 2. Execute Schema (schema.sql)
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("Schema file not found at: {$schemaFile}");
    }
    $schemaSql = file_get_contents($schemaFile);
    output_message('Executing schema definitions...', 'info', $isCli);
    $db->exec($schemaSql);
    output_message('Database schema created/verified successfully.', 'success', $isCli);

    // 3. Execute PC Seeds (seed-pcs.sql)
    $seedFile = __DIR__ . '/seed-pcs.sql';
    if (!file_exists($seedFile)) {
        throw new RuntimeException("Seed file not found at: {$seedFile}");
    }
    $seedSql = file_get_contents($seedFile);
    output_message('Seeding PC workstations...', 'info', $isCli);
    $db->exec($seedSql);

    $pcCountStmt = $db->query("SELECT COUNT(*) AS total FROM pcs");
    $pcTotal = $pcCountStmt->fetchColumn();
    output_message("PC inventory verified. Total PCs in database: {$pcTotal}.", 'success', $isCli);

    // First administrator credentials are supplied by the local operator; no defaults ship with the app.
    $userTotal = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userTotal === 0) {
        $adminUsername = trim((string)(getenv('LMS_ADMIN_USERNAME') ?: ''));
        $adminPassword = (string)(getenv('LMS_ADMIN_PASSWORD') ?: '');
        $adminFullName = trim((string)(getenv('LMS_ADMIN_NAME') ?: 'System Administrator'));
        if ($adminUsername === '' || strlen($adminPassword) < 6) {
            output_message('Schema and PCs initialized. Set LMS_ADMIN_USERNAME and LMS_ADMIN_PASSWORD (6+ characters), then rerun to create the first administrator.', 'warning', $isCli);
        } else {
            $insertUserStmt = $db->prepare("INSERT INTO users (full_name, username, password_hash, role, account_status) VALUES (:name, :username, :hash, 'admin', 'active')");
            $insertUserStmt->execute([':name' => $adminFullName, ':username' => $adminUsername, ':hash' => password_hash($adminPassword, PASSWORD_DEFAULT)]);
            output_message("Created first administrator '{$adminUsername}'.", 'success', $isCli);
        }
    }
    // 5. Verification Summary
    $userCountStmt = $db->query("SELECT COUNT(*) FROM users");
    $userTotal = $userCountStmt->fetchColumn();

    output_message("Database initialization complete! Summary: {$pcTotal} PCs, {$userTotal} users.", 'success', $isCli);
} catch (Throwable $e) {
    output_message("Database setup error: " . $e->getMessage(), 'error', $isCli);
    if ($isCli) {
        exit(1);
    }
}

