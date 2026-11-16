<?php
/**
 * Database Connection & Global API Helpers
 * Supports both Local MySQL (XAMPP) and Cloud PostgreSQL (Neon / Render)
 */

define('DEFAULT_DB_HOST', '127.0.0.1');
define('DEFAULT_DB_USER', 'root');
define('DEFAULT_DB_PASS', '');
define('DEFAULT_DB_NAME', 'school_db');
define('DEFAULT_DB_PORT', 3306);

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            // Check for Cloud Database URL (Neon / Render / Vercel environment variable)
            $dbUrl = getenv('DATABASE_URL') 
                  ?: ($_ENV['DATABASE_URL'] 
                  ?? ($_SERVER['DATABASE_URL'] 
                  ?? (getenv('NEON_DATABASE_URL') 
                  ?: (getenv('DB_URL') ?: null))));

            if (!empty($dbUrl)) {
                // Parse postgresql://user:password@host:port/dbname?sslmode=require
                $parts = parse_url($dbUrl);
                $scheme = strtolower($parts['scheme'] ?? 'pgsql');
                $host   = $parts['host'] ?? 'localhost';
                $port   = $parts['port'] ?? ($scheme === 'mysql' ? 3306 : 5432);
                $user   = isset($parts['user']) ? urldecode($parts['user']) : '';
                $pass   = isset($parts['pass']) ? urldecode($parts['pass']) : '';
                $name   = isset($parts['path']) ? ltrim($parts['path'], '/') : '';

                if ($scheme === 'mysql') {
                    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                } else {
                    // PostgreSQL / Neon – strip '-pooler' from host for SNI match,
                    // and pass explicit endpoint ID for libpq without SNI support
                    $endpointId = explode('.', $host)[0];
                    $endpointId = str_replace('-pooler', '', $endpointId);
                    $host = str_replace('-pooler', '', $host);
                    $dsn = "pgsql:host={$host};port={$port};dbname={$name};sslmode=require;options='endpoint={$endpointId}'";
                }

                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_PERSISTENT         => true,
                ]);
            } else {
                // Local XAMPP MySQL fallback
                $host = getenv('DB_HOST') ?: DEFAULT_DB_HOST;
                $user = getenv('DB_USER') ?: DEFAULT_DB_USER;
                $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : DEFAULT_DB_PASS;
                $name = getenv('DB_NAME') ?: DEFAULT_DB_NAME;
                $port = getenv('DB_PORT') ?: DEFAULT_DB_PORT;

                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_PERSISTENT         => true,
                ]);
            }
            // Auto-ensure schema & default system users across all environments
            ensureReservationCopyColumn($pdo);
            ensureDefaultUsers($pdo);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'Database connection failed: ' . $e->getMessage(),
                'details' => 'Please check your database configuration.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
    return $pdo;
}

if (!function_exists('ensureReservationCopyColumn')) {
    function ensureReservationCopyColumn($db) {
        static $ensured = false;
        if ($ensured || !$db) return;
        try {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $db->exec("ALTER TABLE reservations ADD COLUMN IF NOT EXISTS copy_id INTEGER REFERENCES book_copies(copy_id) ON DELETE SET NULL");
                // Ensure check constraint allows Rejected status
                $db->exec("ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_status_check");
                $db->exec("ALTER TABLE reservations ADD CONSTRAINT reservations_status_check CHECK (status IN ('Pending', 'Approved', 'Cancelled', 'Fulfilled', 'Rejected'))");
            } else {
                $check = $db->query("SHOW COLUMNS FROM reservations LIKE 'copy_id'")->fetch();
                if (!$check) {
                    $db->exec("ALTER TABLE reservations ADD COLUMN copy_id INT NULL");
                }
            }
        } catch (Throwable $e) {
            // Ignored if column already exists or table doesn't exist
        }
        $ensured = true;
    }
}

if (!function_exists('ensureDefaultUsers')) {
    function ensureDefaultUsers($db) {
        static $usersEnsured = false;
        if ($usersEnsured || !$db) return;
        try {
            $defaultHash = '$2y$10$XPNtktEBi8trLAK/aBo1Oes5yP7NK6isgwVtviNVviAHeeseuQM3G'; // password123

            // 1. Ensure Admin exists
            $adminCheck = $db->prepare("SELECT user_id FROM users WHERE LOWER(username) = 'admin' OR LOWER(email) = 'admin@school.edu' LIMIT 1");
            $adminCheck->execute();
            if (!$adminCheck->fetch()) {
                $ins = $db->prepare("
                    INSERT INTO users (username, full_name, employee_id, email, password_hash, role, is_active)
                    VALUES ('admin', 'System Administrator', 'EMP-001', 'admin@school.edu', :hash, 'Admin', 1)
                ");
                $ins->execute([':hash' => $defaultHash]);
            }

            // 2. Ensure Librarian exists
            $libCheck = $db->prepare("SELECT user_id FROM users WHERE LOWER(username) = 'librarian' OR LOWER(email) = 'librarian@school.edu' LIMIT 1");
            $libCheck->execute();
            if (!$libCheck->fetch()) {
                $ins = $db->prepare("
                    INSERT INTO users (username, full_name, employee_id, email, password_hash, role, is_active)
                    VALUES ('librarian', 'Chief Librarian', 'EMP-002', 'librarian@school.edu', :hash, 'Librarian', 1)
                ");
                $ins->execute([':hash' => $defaultHash]);
            }
        } catch (Throwable $e) {
            // Silently continue if users table doesn't exist yet
        }
        $usersEnsured = true;
    }
}

function getDbDriver() {
    return getDB()->getAttribute(PDO::ATTR_DRIVER_NAME); // 'mysql' or 'pgsql'
}

function jsonResponse($data, $code = 200) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!defined('GLOBAL_EXCEPTION_HANDLER_SET')) {
    define('GLOBAL_EXCEPTION_HANDLER_SET', true);
    set_exception_handler(function (Throwable $e) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => $e->getMessage(),
            'code'  => $e->getCode(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    });
}

function getInput() {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }
        parse_str($raw, $parsed);
        if (is_array($parsed) && !empty($parsed)) {
            return $parsed;
        }
    }
    return !empty($_POST) ? $_POST : [];
}

