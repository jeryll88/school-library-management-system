<?php
/**
 * Authentication and Session Management Helper
 */

require_once __DIR__ . '/db.php';

function startAppSession() {
    if (session_status() === PHP_SESSION_NONE) {
        // In serverless environments (Vercel Lambda), explicitly use writable /tmp for session files
        if (getenv('VERCEL') || !empty($_ENV['VERCEL']) || !empty($_SERVER['VERCEL'])) {
            @session_save_path('/tmp');
        }
        session_start();
    }
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
        header('Location: /login');
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
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            header('Location: /login?error=deactivated');
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
            header('Location: /?error=unauthorized');
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

    // Verify account is still active in database
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT user_id, role, is_active FROM users WHERE user_id = :id LIMIT 1");
        $stmt->execute([':id' => $user['user_id']]);
        $row = $stmt->fetch();

        if (!$row || (int)($row['is_active'] ?? 1) !== 1) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
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
        $user['role']     = $row['role'];
    } catch (Exception $e) {
        // Fallback to session
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
