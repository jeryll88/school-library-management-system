<?php
error_reporting(0);
ob_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$account = requireApiAccount();
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->prepare("
        SELECT u.username, u.full_name, u.email, u.employee_id, u.role, s.student_number
        FROM users u
        LEFT JOIN students s ON s.id = u.student_id
        WHERE u.user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([':user_id' => $account['user_id']]);
    jsonResponse(['data' => $stmt->fetch()]);
}

if ($method === 'PUT') {
    $input = getInput();
    $fullName = trim($input['full_name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if ($fullName === '' || strlen($fullName) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Enter a valid full name and email address'], 422);
    }
    if ($password !== '' && strlen($password) < 6) {
        jsonResponse(['error' => 'Password must be at least 6 characters long'], 422);
    }

    $duplicate = $db->prepare("
        SELECT user_id FROM users
        WHERE LOWER(email) = LOWER(:email) AND user_id <> :user_id
        LIMIT 1
    ");
    $duplicate->execute([':email' => $email, ':user_id' => $account['user_id']]);
    if ($duplicate->fetch()) {
        jsonResponse(['error' => 'That email address is already in use'], 409);
    }

    if ($password !== '') {
        $stmt = $db->prepare("
            UPDATE users
            SET full_name = :full_name, email = :email, password_hash = :password_hash
            WHERE user_id = :user_id
        ");
        $stmt->execute([
            ':full_name' => $fullName,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':user_id' => $account['user_id'],
        ]);
    } else {
        $stmt = $db->prepare("UPDATE users SET full_name = :full_name, email = :email WHERE user_id = :user_id");
        $stmt->execute([
            ':full_name' => $fullName,
            ':email' => $email,
            ':user_id' => $account['user_id'],
        ]);
    }

    // Keep student table name in sync if account is a linked student
    if (!empty($account['student_id'])) {
        $syncStudent = $db->prepare("UPDATE students SET name = :name WHERE id = :student_id");
        $syncStudent->execute([':name' => $fullName, ':student_id' => $account['student_id']]);
    }

    // Update current session full_name
    $_SESSION['full_name'] = $fullName;

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
