<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/db.php';

$password = fgets(STDIN);
$confirmation = fgets(STDIN);
if ($password === false || $confirmation === false) {
    fwrite(STDERR, "Password input is missing.\n");
    exit(1);
}
$password = rtrim($password, "\r\n");
$confirmation = rtrim($confirmation, "\r\n");

if (strlen($password) < 12 || strlen($password) > 72) {
    fwrite(STDERR, "Password must contain 12-72 bytes.\n");
    exit(1);
}
if (!hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $db = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $admins = $db->query("
        SELECT user_id, username
        FROM users
        WHERE role = 'Admin' AND is_active = 1
        ORDER BY user_id
    ")->fetchAll();

    if (count($admins) !== 1) {
        fwrite(STDERR, "Expected exactly one active Admin account; no password was changed.\n");
        exit(1);
    }

    $admin = $admins[0];
    $update = $db->prepare("
        UPDATE users
        SET password_hash = :password_hash
        WHERE user_id = :user_id AND role = 'Admin' AND is_active = 1
    ");
    $update->execute([
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':user_id' => $admin['user_id'],
    ]);

    fwrite(STDOUT, "Admin password reset successfully for account '{$admin['username']}'.\n");
} catch (Throwable $e) {
    error_log('Local Admin password reset failed: ' . $e->getMessage());
    fwrite(STDERR, "Admin password reset failed. Check the PHP error log.\n");
    exit(1);
} finally {
    $password = '';
    $confirmation = '';
}
