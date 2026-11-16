<?php
/**
 * Authentication and Session Management Helper
 * Supports local XAMPP and serverless (Vercel Lambda) stateless sessions via HMAC-signed cookies.
 */

require_once __DIR__ . '/db.php';

if (!defined('APP_AUTH_COOKIE')) {
    define('APP_AUTH_COOKIE', 'LMS_AUTH');
}
if (!defined('APP_AUTH_SECRET')) {
    $secret = getenv('APP_SECRET') ?: 'library_ms_secret_hmac_2026_salt_99';
    define('APP_AUTH_SECRET', $secret);
}

/**
 * Write a tamper-proof HMAC-signed cookie storing minimal session credentials.
 */
function writeAuthCookie(array $data) {
    if (headers_sent()) return;
    $payload = base64_encode(json_encode($data));
    $signature = hash_hmac('sha256', $payload, APP_AUTH_SECRET);
    $cookieValue = $payload . '.' . $signature;
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || !empty($_SERVER['VERCEL']);

    setcookie(APP_AUTH_COOKIE, $cookieValue, [
        'expires'  => time() + 86400 * 7, // 7 days
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isSecure,
    ]);
}

/**
 * Clear the auth cookie on logout or deactivation.
 */
function clearAuthCookie() {
    if (headers_sent()) return;
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || !empty($_SERVER['VERCEL']);
    setcookie(APP_AUTH_COOKIE, '', [
        'expires'  => time() - 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isSecure,
    ]);
    unset($_COOKIE[APP_AUTH_COOKIE]);
}

/**
 * Initialize PHP session and auto-restore session from signed cookie in serverless environments.
 */
function startAppSession() {
    if (session_status() === PHP_SESSION_NONE) {
        if (getenv('VERCEL') || !empty($_ENV['VERCEL']) || !empty($_SERVER['VERCEL'])) {
            @session_save_path('/tmp');
        }
        @session_start();
    }

    // If native PHP session is missing (e.g. fresh Vercel serverless container), restore from signed cookie
    if (empty($_SESSION['logged_in']) && !empty($_COOKIE[APP_AUTH_COOKIE])) {
        $parts = explode('.', $_COOKIE[APP_AUTH_COOKIE], 2);
        if (count($parts) === 2) {
            [$payload, $sig] = $parts;
            $expected = hash_hmac('sha256', $payload, APP_AUTH_SECRET);
            if (hash_equals($expected, $sig)) {
                $decoded = json_decode(base64_decode($payload), true);
                if (is_array($decoded) && !empty($decoded['logged_in']) && ($decoded['exp'] ?? 0) > time()) {
                    $_SESSION['user_id']    = (int)$decoded['user_id'];
                    $_SESSION['username']   = $decoded['username'] ?? '';
                    $_SESSION['full_name']  = $decoded['full_name'] ?? '';
                    $_SESSION['role']       = $decoded['role'] ?? 'User';
                    $_SESSION['student_id'] = $decoded['student_id'] ?? null;
                    $_SESSION['logged_in']  = true;
                    if (!empty($decoded['csrf_token'])) {
                        $_SESSION['csrf_token'] = $decoded['csrf_token'];
                    }
                }
            }
        }
    }
}

/**
 * Set session credentials and issue the signed auth cookie.
 */
function setSessionUser(array $user) {
    startAppSession();
    $_SESSION['user_id']    = (int)$user['user_id'];
    $_SESSION['username']   = $user['username'] ?? '';
    $_SESSION['full_name']  = $user['full_name'] ?? '';
    $_SESSION['role']       = $user['role'] ?? 'User';
    $_SESSION['student_id'] = $user['student_id'] ?? null;
    $_SESSION['logged_in']  = true;

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    writeAuthCookie([
        'user_id'    => (int)$user['user_id'],
        'username'   => $user['username'] ?? '',
        'full_name'  => $user['full_name'] ?? '',
        'role'       => $user['role'] ?? 'User',
        'student_id' => $user['student_id'] ?? null,
        'logged_in'  => true,
        'csrf_token' => $_SESSION['csrf_token'],
        'exp'        => time() + 86400 * 7,
    ]);
}

/**
 * Destroy current session and clear auth cookie.
 */
function clearSessionUser() {
    startAppSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    @session_destroy();
    clearAuthCookie();
}

function isLoggedIn() {
    startAppSession();
    return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);
}

function getCsrfToken() {
    startAppSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    startAppSession();
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'user_id'    => $_SESSION['user_id'] ?? null,
        'id'         => $_SESSION['user_id'] ?? null,
        'username'   => $_SESSION['username'] ?? '',
        'full_name'  => $_SESSION['full_name'] ?? '',
        'role'       => $_SESSION['role'] ?? 'User',
        'student_id' => $_SESSION['student_id'] ?? null,
    ];
}

function requirePageAccount($allowedRoles = []) {
    startAppSession();
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }

    // Verify account is still active in database
    $user = getCurrentUser();
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT user_id, role, is_active FROM users WHERE user_id = :id LIMIT 1");
        $stmt->execute([':id' => $user['user_id']]);
        $row = $stmt->fetch();

        if (!$row || (int)($row['is_active'] ?? 1) !== 1) {
            clearSessionUser();
            header('Location: login.php?error=deactivated');
            exit;
        }

        // Keep session role in sync with database
        $_SESSION['role'] = $row['role'];
        $user['role']     = $row['role'];
    } catch (Exception $e) {
        // Fallback to session data if DB check fails
    }

    if (!empty($allowedRoles)) {
        $allowed = array_map('strtolower', array_map('trim', (array)$allowedRoles));
        $userRole = strtolower(trim((string)($user['role'] ?? '')));
        if (!in_array($userRole, $allowed, true)) {
            header('Location: index.php?error=unauthorized');
            exit;
        }
    }
    return $user;
}

function requireApiAccount($allowedRoles = []) {
    startAppSession();
    if (!isLoggedIn()) {
        if (function_exists('jsonResponse')) {
            jsonResponse(['error' => 'Authentication required'], 401);
        } else {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
    }

    $user = getCurrentUser();

    // Verify account is still active in database (cached for 60s to eliminate redundant roundtrips)
    $now = time();
    $lastChecked = $_SESSION['active_checked_at'] ?? 0;
    if ($now - $lastChecked > 60) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT user_id, role, is_active FROM users WHERE user_id = :id LIMIT 1");
            $stmt->execute([':id' => $user['user_id']]);
            $row = $stmt->fetch();

            if (!$row || (int)($row['is_active'] ?? 1) !== 1) {
                clearSessionUser();
                if (function_exists('jsonResponse')) {
                    jsonResponse(['error' => 'Account is deactivated or suspended'], 403);
                } else {
                    http_response_code(403);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Account is deactivated or suspended']);
                    exit;
                }
            }

            $_SESSION['role'] = $row['role'];
            $_SESSION['active_checked_at'] = $now;
            $user['role']     = $row['role'];
        } catch (Exception $e) {
            // Fallback to session
        }
    }

    if (!empty($allowedRoles)) {
        $allowed = array_map('strtolower', array_map('trim', (array)$allowedRoles));
        $userRole = strtolower(trim((string)($user['role'] ?? '')));
        if (!in_array($userRole, $allowed, true)) {
            if (function_exists('jsonResponse')) {
                jsonResponse(['error' => 'Unauthorized access'], 403);
            } else {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Unauthorized access']);
                exit;
            }
        }
    }
    return $user;
}
