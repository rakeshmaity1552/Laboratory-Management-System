<?php
/**
 * Database Configuration and Connection Handler
 * Laboratory Management System (LMS)
 */

if (!defined('DB_PATH')) {
    define('DB_PATH', getenv('LMS_DB_PATH') ?: dirname(__DIR__) . '/data/lms.db');
}

/**
 * Returns a PDO connection to the SQLite database.
 * Reuses the connection within a single request.
 *
 * @return PDO
 * @throws RuntimeException|PDOException
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dbPath = DB_PATH;
        $dataDir = dirname($dbPath);

        // Ensure the data directory exists
        if (!is_dir($dataDir)) {
            if (!mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
                throw new RuntimeException("Failed to create database directory: {$dataDir}");
            }
        }

        $dsn = 'sqlite:' . $dbPath;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $pdo = new PDO($dsn, null, null, $options);

        // Enforce SQLite foreign key constraints for every connection
        $pdo->exec('PRAGMA foreign_keys = ON;');
    }

    return $pdo;
}
