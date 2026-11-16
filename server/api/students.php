<?php
error_reporting(0);
ob_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$db      = getDB();
// Only Admin and Librarian can view or manage students
$account = requireApiAccount(['Admin', 'Librarian']);
$method  = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $search = trim($_GET['search'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(5000, max(1, (int)($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];
        if ($search !== '') {
            $where[]  = "(s.name LIKE :search_name OR s.student_number LIKE :search_number OR c.course_name LIKE :search_course OR c.course_code LIKE :search_code)";
            $params[':search_name']   = "%$search%";
            $params[':search_number'] = "%$search%";
            $params[':search_course'] = "%$search%";
            $params[':search_code']   = "%$search%";
        }
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $db->prepare("SELECT COUNT(*) FROM students s JOIN courses c ON s.course_id = c.id $whereSQL");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT s.id, s.name, s.student_number, s.gender, s.year_level,
                   c.id AS course_id, c.course_code, c.course_name,
                   u.user_id, u.username, u.email, u.is_active
            FROM students s
            JOIN courses c ON s.course_id = c.id
            LEFT JOIN users u ON u.student_id = s.id
            $whereSQL
            ORDER BY s.id ASC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse([
            'data'  => $stmt->fetchAll(),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
        break;

    case 'POST':
        $input         = getInput();
        $name          = trim($input['name'] ?? '');
        $studentNumber = strtoupper(trim($input['student_number'] ?? ''));
        $gender        = $input['gender'] ?? '';
        $yearLevel     = (int)($input['year_level'] ?? 0);
        $courseId      = (int)($input['course_id'] ?? 0);
        $username      = trim($input['username'] ?? '');
        $email         = strtolower(trim($input['email'] ?? ''));
        $password      = $input['password'] ?? '';

        if ($name === '' || $studentNumber === '' || $gender === '' || $yearLevel < 1 || $yearLevel > 4 || $courseId < 1) {
            jsonResponse(['error' => 'Name, student ID, gender, year level, and course are required'], 422);
        }
        if (!in_array($gender, ['Female', 'Male', 'Other'], true) || strlen($studentNumber) > 50) {
            jsonResponse(['error' => 'Check the student profile fields and try again'], 422);
        }

        // Duplicate student number check
        $dupStudent = $db->prepare("SELECT id FROM students WHERE LOWER(student_number) = LOWER(:sn) LIMIT 1");
        $dupStudent->execute([':sn' => $studentNumber]);
        if ($dupStudent->fetch()) {
            jsonResponse(['error' => 'That Student ID is already registered'], 409);
        }

        $createAccount = ($username !== '' || $email !== '' || $password !== '');
        if ($createAccount) {
            if ($username === '' || strlen($username) < 3 || strlen($username) > 50 ||
                !preg_match('/^[A-Za-z0-9._-]+$/', $username) || strlen($email) > 100 ||
                $email === '' || strlen($password) < 6 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(['error' => 'Creating a login account requires a valid username, email, and password of at least 6 characters'], 422);
            }
            $dupUser = $db->prepare("SELECT user_id FROM users WHERE LOWER(username) = LOWER(:u) OR LOWER(email) = LOWER(:e) LIMIT 1");
            $dupUser->execute([':u' => $username, ':e' => $email]);
            if ($dupUser->fetch()) {
                jsonResponse(['error' => 'That username or email is already in use'], 409);
            }
        }

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("
                INSERT INTO students (name, student_number, gender, year_level, course_id)
                VALUES (:name, :snum, :gender, :year, :course)
            ");
            $stmt->execute([
                ':name'   => $name,
                ':snum'   => $studentNumber,
                ':gender' => $gender,
                ':year'   => $yearLevel,
                ':course' => $courseId,
            ]);
            $studentId = (int)$db->lastInsertId();

            if ($createAccount) {
                $uStmt = $db->prepare("
                    INSERT INTO users (student_id, username, full_name, email, password_hash, role, is_active)
                    VALUES (:sid, :username, :name, :email, :pass, 'User', 1)
                ");
                $uStmt->execute([
                    ':sid'      => $studentId,
                    ':username' => $username,
                    ':name'     => $name,
                    ':email'    => $email,
                    ':pass'     => password_hash($password, PASSWORD_BCRYPT),
                ]);
            }
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23000') {
                jsonResponse(['error' => 'The student ID, username, or email is already in use'], 409);
            }
            throw $e;
        }

        jsonResponse([
            'success'    => true,
            'student_id' => $studentId,
            'message'    => 'Student registered successfully',
        ], 201);
        break;

    case 'PUT':
        $input         = getInput();
        $id            = (int)($_GET['id'] ?? $input['id'] ?? 0);
        if ($id < 1) {
            jsonResponse(['error' => 'Student record ID is required'], 422);
        }

        $name          = trim($input['name'] ?? '');
        $studentNumber = strtoupper(trim($input['student_number'] ?? ''));
        $gender        = $input['gender'] ?? '';
        $yearLevel     = (int)($input['year_level'] ?? 0);
        $courseId      = (int)($input['course_id'] ?? 0);

        if ($name === '' || $studentNumber === '' || $gender === '' || $yearLevel < 1 || $yearLevel > 4 || $courseId < 1 ||
            !in_array($gender, ['Female', 'Male', 'Other'], true) || strlen($studentNumber) > 50) {
            jsonResponse(['error' => 'Please provide valid student profile details'], 422);
        }

        $duplicate = $db->prepare("SELECT id FROM students WHERE LOWER(student_number) = LOWER(:sn) AND id <> :id LIMIT 1");
        $duplicate->execute([':sn' => $studentNumber, ':id' => $id]);
        if ($duplicate->fetch()) {
            jsonResponse(['error' => 'That Student ID is already assigned to another student'], 409);
        }

        $stmt = $db->prepare("
            UPDATE students
            SET name = :name, student_number = :snum, gender = :gender, year_level = :year, course_id = :course
            WHERE id = :id
        ");
        $stmt->execute([
            ':name'   => $name,
            ':snum'   => $studentNumber,
            ':gender' => $gender,
            ':year'   => $yearLevel,
            ':course' => $courseId,
            ':id'     => $id,
        ]);

        jsonResponse([
            'success' => true,
            'message' => 'Student record updated successfully',
        ]);
        break;

    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) {
            jsonResponse(['error' => 'Student ID is required'], 422);
        }

        $stmt = $db->prepare("DELETE FROM students WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(['error' => 'Student record not found'], 404);
        }

        jsonResponse([
            'success' => true,
            'message' => 'Student record deleted',
        ]);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
