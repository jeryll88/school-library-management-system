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
            ensureReservationDueDateColumn($pdo);
            ensureNotificationsTable($pdo);
            ensureDefaultUsers($pdo);
            checkAndCreateOverdueNotifications($pdo);
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

if (!function_exists('ensureReservationDueDateColumn')) {
    function ensureReservationDueDateColumn($db) {
        static $ensured = false;
        if ($ensured || !$db) return;
        try {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $db->exec("ALTER TABLE reservations ADD COLUMN IF NOT EXISTS due_date DATE NULL");
            } else {
                $check = $db->query("SHOW COLUMNS FROM reservations LIKE 'due_date'")->fetch();
                if (!$check) {
                    $db->exec("ALTER TABLE reservations ADD COLUMN due_date DATE NULL AFTER reservation_date");
                }
            }
        } catch (Throwable $e) {
        }
        $ensured = true;
    }
}

if (!function_exists('ensureNotificationsTable')) {
    function ensureNotificationsTable($db) {
        static $ensured = false;
        if ($ensured || !$db) return;
        try {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS notifications (
                        id SERIAL PRIMARY KEY,
                        user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
                        type VARCHAR(50) NOT NULL DEFAULT 'general',
                        title VARCHAR(255) NOT NULL,
                        message TEXT NOT NULL,
                        link VARCHAR(255) NULL,
                        is_read SMALLINT NOT NULL DEFAULT 0,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read);
                ");
            } else {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS notifications (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        type VARCHAR(50) NOT NULL DEFAULT 'general',
                        title VARCHAR(255) NOT NULL,
                        message TEXT NOT NULL,
                        link VARCHAR(255) NULL,
                        is_read TINYINT(1) NOT NULL DEFAULT 0,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_notifications_user (user_id, is_read)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            }
        } catch (Throwable $e) {
        }
        $ensured = true;
    }
}

if (!function_exists('checkAndCreateOverdueNotifications')) {
    function checkAndCreateOverdueNotifications($db) {
        static $checked = false;
        if ($checked || !$db) return;
        try {
            // 1. Update status of any overdue borrowed records
            $db->exec("UPDATE borrowing_records SET status = 'Overdue' WHERE status = 'Borrowed' AND due_date < CURRENT_DATE");

            // 2. Find active overdue borrowings and ensure student has an overdue notification
            $stmt = $db->query("
                SELECT br.borrow_id, br.user_id, br.due_date, b.title AS book_title
                FROM borrowing_records br
                JOIN book_copies bc ON br.copy_id = bc.copy_id
                JOIN books b ON bc.book_id = b.book_id
                WHERE br.status = 'Overdue'
            ");
            $overdues = $stmt->fetchAll();
            $todayStr = date('Y-m-d');
            $todayTime = strtotime($todayStr);

            foreach ($overdues as $ov) {
                $dueTime = strtotime($ov['due_date']);
                $daysOverdue = max(1, (int)floor(($todayTime - $dueTime) / 86400));
                $fineAmount = $daysOverdue * 10.00;
                $formattedDueDate = date('M d, Y', $dueTime);

                // Check if notification already sent today for this book
                $chk = $db->prepare("
                    SELECT id FROM notifications 
                    WHERE user_id = :uid 
                      AND type = 'overdue' 
                      AND title LIKE :title
                      AND DATE(created_at) = CURRENT_DATE
                    LIMIT 1
                ");
                $chk->execute([
                    ':uid' => $ov['user_id'],
                    ':title' => '%' . $ov['book_title'] . '%'
                ]);

                if (!$chk->fetch()) {
                    $ins = $db->prepare("
                        INSERT INTO notifications (user_id, type, title, message, link, is_read)
                        VALUES (:uid, 'overdue', :title, :msg, 'borrowing', 0)
                    ");
                    $ins->execute([
                        ':uid'   => $ov['user_id'],
                        ':title' => "⚠️ Overdue Book: {$ov['book_title']}",
                        ':msg'   => "Your borrowed book \"{$ov['book_title']}\" was due on {$formattedDueDate}. It is currently {$daysOverdue} day(s) overdue. A late penalty of ₱" . number_format($fineAmount, 2) . " has accumulated (₱10.00/day). Please return the book to the library counter immediately to avoid additional fees.",
                    ]);
                }
            }
        } catch (Throwable $e) {
        }
        $checked = true;
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

