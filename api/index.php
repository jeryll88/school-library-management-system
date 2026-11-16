<?php
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
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ]);
}
