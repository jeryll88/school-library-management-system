<?php
/**
 * Vercel Serverless Single-Function Router & API Gateway
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_save_path('/tmp');
}

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
$path = '/' . trim($path, '/');

// 1. API Endpoints
if (preg_match('#^/api/([a-zA-Z0-9_-]+)(\.php)?$#', $path, $matches)) {
    $endpoint = $matches[1];
    
    // Health check endpoint
    if ($endpoint === 'index' || $endpoint === '') {
        header('Content-Type: application/json');
        require_once __DIR__ . '/../server/config/db.php';
        try {
            $db = getDB();
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            echo json_encode([
                'status'    => 'ok',
                'message'   => 'School Library Management System API Gateway',
                'database'  => $driver === 'pgsql' ? 'Neon Serverless PostgreSQL' : 'Local MySQL',
                'timestamp' => date('c'),
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    $target = __DIR__ . '/../server/api/' . $endpoint . '.php';
    if (file_exists($target)) {
        require $target;
        exit;
    }

    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'API endpoint not found: ' . $endpoint]);
    exit;
}

// 2. Client Authentication & System Pages
if ($path === '/login' || $path === '/login.php') {
    require __DIR__ . '/../client/login.php';
    exit;
}

if ($path === '/register' || $path === '/register.php') {
    require __DIR__ . '/../client/register.php';
    exit;
}

if ($path === '/forgot_password' || $path === '/forgot_password.php') {
    require __DIR__ . '/../client/forgot_password.php';
    exit;
}

if ($path === '/logout' || $path === '/logout.php') {
    require __DIR__ . '/../client/logout.php';
    exit;
}

// 3. Main Single Page Application Shell (Default)
require __DIR__ . '/../client/index.php';
