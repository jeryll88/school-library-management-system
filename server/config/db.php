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
                    // PostgreSQL / Neon (Include endpoint option for SNI & connection poolers)
                    $endpointId = explode('.', $host)[0];
                    $endpointId = str_replace('-pooler', '', $endpointId);
                    $dsn = "pgsql:host={$host};port={$port};dbname={$name};sslmode=require";
                    if (!empty($endpointId) && strpos($host, 'neon.tech') !== false) {
                        $dsn .= ";options='endpoint={$endpointId}'";
                    }
                }

                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
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
                ]);
            }
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Database connection failed',
                'details' => (getenv('APP_ENV') === 'development' || !empty($_GET['debug'])) ? $e->getMessage() : 'Please check your database configuration.'
            ]);
            exit;
        }
    }
    return $pdo;
}

function getDbDriver() {
    return getDB()->getAttribute(PDO::ATTR_DRIVER_NAME); // 'mysql' or 'pgsql'
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
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

