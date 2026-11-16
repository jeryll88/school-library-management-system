<?php
error_reporting(0);
ob_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$account = requireApiAccount(['Admin']);
$db = getDB();
$verifiedAdmin = $db->prepare("SELECT role, is_active FROM users WHERE user_id = :id LIMIT 1");
$verifiedAdmin->execute([':id' => $account['user_id']]);
$adminRow = $verifiedAdmin->fetch();
if (!$adminRow || $adminRow['role'] !== 'Admin' || (int)$adminRow['is_active'] !== 1) {
    jsonResponse(['error' => 'Administrator access is required'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && !validateCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    jsonResponse(['error' => 'Invalid or missing security token'], 403);
}

function logLibrarianActivity(PDO $db, array $admin, int $targetId, string $targetUsername, string $action, array $details = []) {
    $stmt = $db->prepare("
        INSERT INTO activity_logs (admin_user_id, admin_username, target_user_id, target_username, action, details)
        VALUES (:admin_id, :admin_username, :target_id, :target_username, :action, :details)
    ");
    $stmt->execute([
        ':admin_id' => $admin['user_id'],
        ':admin_username' => $admin['username'],
        ':target_id' => $targetId,
        ':target_username' => $targetUsername,
        ':action' => $action,
        ':details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
}

function validateLibrarianCredentials(string $fullName, string $employeeId, string $username, string $email) {
    if ($fullName === '' || strlen($fullName) > 100) {
        jsonResponse(['error' => 'Full name is required and must not exceed 100 characters'], 422);
    }
    if ($employeeId === '' || strlen($employeeId) > 50 || !preg_match('/^[A-Za-z0-9._-]+$/', $employeeId)) {
        jsonResponse(['error' => 'Employee ID is required, must not exceed 50 characters, and may contain only letters, numbers, periods, hyphens, and underscores'], 422);
    }
    if (strlen($username) < 3 || strlen($username) > 50 || !preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
        jsonResponse(['error' => 'Username must be 3-50 characters and contain only letters, numbers, periods, hyphens, and underscores'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        jsonResponse(['error' => 'Please enter a valid email address'], 422);
    }
}

function checkLibrarianDuplicates(PDO $db, string $username, string $email, string $employeeId, int $excludeId = 0) {
    $stmt = $db->prepare("
        SELECT user_id,
               LOWER(username) = LOWER(:username) AS username_match,
               LOWER(email) = LOWER(:email) AS email_match,
               LOWER(employee_id) = LOWER(:employee_id) AS employee_id_match
        FROM users
        WHERE (LOWER(username) = LOWER(:username_where)
            OR LOWER(email) = LOWER(:email_where)
            OR LOWER(employee_id) = LOWER(:employee_id_where))
          AND user_id <> :exclude_id
        LIMIT 1
    ");
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':employee_id' => $employeeId,
        ':username_where' => $username,
        ':email_where' => $email,
        ':employee_id_where' => $employeeId,
        ':exclude_id' => $excludeId,
    ]);
    $duplicate = $stmt->fetch();
    if ($duplicate) {
        if ((int)$duplicate['username_match'] === 1) {
            return 'That username is already registered';
        }
        if ((int)$duplicate['email_match'] === 1) {
            return 'That email address is already registered';
        }
        if ((int)$duplicate['employee_id_match'] === 1) {
            return 'That employee ID is already registered';
        }
    }
    return null;
}

switch ($method) {
    case 'GET':
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;
        $where = "WHERE u.role = 'Librarian'";
        $params = [];

        if (isset($_GET['id'])) {
            $where .= ' AND u.user_id = :id';
            $params[':id'] = (int)$_GET['id'];
        }
        if ($search !== '') {
            $where .= ' AND (u.full_name LIKE :s_name OR u.employee_id LIKE :s_employee OR u.username LIKE :s_user OR u.email LIKE :s_email)';
            $params[':s_name'] = "%$search%";
            $params[':s_employee'] = "%$search%";
            $params[':s_user'] = "%$search%";
            $params[':s_email'] = "%$search%";
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM users u $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT u.user_id, u.employee_id, u.username, u.full_name, u.email, u.role, u.is_active, u.created_at
            FROM users u
            $where
            ORDER BY u.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse([
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
        break;

    case 'POST':
        $input = getInput();
        if (($_GET['action'] ?? '') === 'reset_password') {
            $id = (int)($_GET['id'] ?? 0);
            $password = (string)($input['password'] ?? '');
            $confirmPassword = (string)($input['confirm_password'] ?? '');
            if ($id < 1) {
                jsonResponse(['error' => 'Valid librarian user ID is required'], 422);
            }
            if (strlen($password) < 8 || $password !== $confirmPassword) {
                jsonResponse(['error' => 'Passwords must match and contain at least 8 characters'], 422);
            }

            $target = $db->prepare("SELECT user_id, username FROM users WHERE user_id = :id AND role = 'Librarian'");
            $target->execute([':id' => $id]);
            $librarian = $target->fetch();
            if (!$librarian) {
                jsonResponse(['error' => 'Librarian account not found'], 404);
            }

            $db->beginTransaction();
            try {
                $update = $db->prepare("UPDATE users SET password_hash = :password_hash WHERE user_id = :id AND role = 'Librarian'");
                $update->execute([
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':id' => $id,
                ]);
                logLibrarianActivity($db, $account, $id, $librarian['username'], 'librarian.password_reset');
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            jsonResponse(['success' => true, 'message' => 'Librarian password reset successfully']);
        }

        $fullName = trim($input['full_name'] ?? '');
        $employeeId = trim($input['employee_id'] ?? '');
        $username = trim($input['username'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $confirmPassword = (string)($input['confirm_password'] ?? '');

        validateLibrarianCredentials($fullName, $employeeId, $username, $email);
        if (strlen($password) < 8 || $password !== $confirmPassword) {
            jsonResponse(['error' => 'Passwords must match and contain at least 8 characters'], 422);
        }

        $duplicateError = checkLibrarianDuplicates($db, $username, $email, $employeeId);
        if ($duplicateError !== null) {
            jsonResponse(['error' => $duplicateError], 409);
        }

        $db->beginTransaction();
        try {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $stmt = $db->prepare("
                    INSERT INTO users (username, full_name, employee_id, email, password_hash, role, is_active)
                    VALUES (:username, :full_name, :employee_id, :email, :password_hash, 'Librarian', 1)
                    RETURNING user_id
                ");
                $stmt->execute([
                    ':username' => $username,
                    ':full_name' => $fullName,
                    ':employee_id' => $employeeId,
                    ':email' => $email,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $userId = (int)$stmt->fetchColumn();
            } else {
                $stmt = $db->prepare("
                    INSERT INTO users (username, full_name, employee_id, email, password_hash, role, is_active)
                    VALUES (:username, :full_name, :employee_id, :email, :password_hash, 'Librarian', 1)
                ");
                $stmt->execute([
                    ':username' => $username,
                    ':full_name' => $fullName,
                    ':employee_id' => $employeeId,
                    ':email' => $email,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $userId = (int)$db->lastInsertId();
            }
            logLibrarianActivity($db, $account, $userId, $username, 'librarian.created', [
                'full_name' => $fullName,
                'employee_id' => $employeeId,
            ]);
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23000') {
                jsonResponse(['error' => 'That username, email address, or employee ID is already in use'], 409);
            }
            throw $e;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        jsonResponse([
            'success' => true,
            'message' => 'Librarian account created successfully',
            'user_id' => $userId,
        ], 201);
        break;

    case 'PUT':
        $id = (int)($_GET['id'] ?? 0);
        $input = getInput();
        if ($id < 1) {
            jsonResponse(['error' => 'Valid librarian user ID is required'], 422);
        }

        $fullName = trim($input['full_name'] ?? '');
        $employeeId = trim($input['employee_id'] ?? '');
        $username = trim($input['username'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $active = filter_var($input['is_active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            jsonResponse(['error' => 'A valid account status is required'], 422);
        }
        validateLibrarianCredentials($fullName, $employeeId, $username, $email);

        $db->beginTransaction();
        try {
            $target = $db->prepare("SELECT user_id, employee_id, username, full_name, email, is_active FROM users WHERE user_id = :id AND role = 'Librarian' FOR UPDATE");
            $target->execute([':id' => $id]);
            $old = $target->fetch();
            if (!$old) {
                $db->rollBack();
                jsonResponse(['error' => 'Librarian account not found'], 404);
            }
            $duplicateError = checkLibrarianDuplicates($db, $username, $email, $employeeId, $id);
            if ($duplicateError !== null) {
                $db->rollBack();
                jsonResponse(['error' => $duplicateError], 409);
            }

            $updated = [
                'full_name' => $fullName,
                'employee_id' => $employeeId,
                'username' => $username,
                'email' => $email,
                'is_active' => $active ? 1 : 0,
            ];
            $changedFields = [];
            $changes = [];
            foreach ($updated as $field => $value) {
                if ((string)$old[$field] !== (string)$value) {
                    $changedFields[] = $field;
                    $changes[$field] = ['before' => $old[$field], 'after' => $value];
                }
            }

            $stmt = $db->prepare("
                UPDATE users
                SET full_name = :full_name, employee_id = :employee_id, username = :username,
                    email = :email, is_active = :is_active
                WHERE user_id = :id AND role = 'Librarian'
            ");
            $stmt->execute([
                ':full_name' => $fullName,
                ':employee_id' => $employeeId,
                ':username' => $username,
                ':email' => $email,
                ':is_active' => $active ? 1 : 0,
                ':id' => $id,
            ]);
            if ($changedFields) {
                $action = $old['is_active'] != $updated['is_active']
                    ? ($active ? 'librarian.activated' : 'librarian.deactivated')
                    : 'librarian.updated';
                logLibrarianActivity($db, $account, $id, $old['username'], $action, [
                    'changed_fields' => $changedFields,
                    'changes' => $changes,
                ]);
            }
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23000') {
                jsonResponse(['error' => 'That username, email address, or employee ID is already in use'], 409);
            }
            throw $e;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        jsonResponse(['success' => true, 'message' => 'Librarian account updated successfully']);
        break;

    case 'PATCH':
        $id = (int)($_GET['id'] ?? 0);
        $input = getInput();
        $active = filter_var($input['is_active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($id < 1 || $active === null) {
            jsonResponse(['error' => 'Valid librarian user ID and account status are required'], 422);
        }

        $db->beginTransaction();
        try {
            $target = $db->prepare("SELECT username, is_active FROM users WHERE user_id = :id AND role = 'Librarian' FOR UPDATE");
            $target->execute([':id' => $id]);
            $librarian = $target->fetch();
            if (!$librarian) {
                $db->rollBack();
                jsonResponse(['error' => 'Librarian account not found'], 404);
            }
            $newStatus = $active ? 1 : 0;
            if ((int)$librarian['is_active'] !== $newStatus) {
                $stmt = $db->prepare("UPDATE users SET is_active = :is_active WHERE user_id = :id AND role = 'Librarian'");
                $stmt->execute([':is_active' => $newStatus, ':id' => $id]);
                logLibrarianActivity(
                    $db,
                    $account,
                    $id,
                    $librarian['username'],
                    $active ? 'librarian.activated' : 'librarian.deactivated',
                    ['previous_status' => (int)$librarian['is_active'], 'new_status' => $newStatus]
                );
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        jsonResponse(['success' => true, 'message' => $active ? 'Librarian account activated' : 'Librarian account deactivated']);
        break;

    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) {
            jsonResponse(['error' => 'Valid librarian user ID is required'], 422);
        }

        $db->beginTransaction();
        try {
            $target = $db->prepare("SELECT username FROM users WHERE user_id = :id AND role = 'Librarian' FOR UPDATE");
            $target->execute([':id' => $id]);
            $librarian = $target->fetch();
            if (!$librarian) {
                $db->rollBack();
                jsonResponse(['error' => 'Librarian account not found'], 404);
            }

            $transactions = $db->prepare("
                SELECT
                    (SELECT COUNT(*) FROM borrowing_records WHERE user_id = :borrow_user_id) +
                    (SELECT COUNT(*) FROM borrowing_records WHERE issued_by = :issued_by_user_id) +
                    (SELECT COUNT(*) FROM reservations WHERE user_id = :reservation_user_id)
            ");
            $transactions->execute([
                ':borrow_user_id' => $id,
                ':issued_by_user_id' => $id,
                ':reservation_user_id' => $id,
            ]);
            if ((int)$transactions->fetchColumn() > 0) {
                $db->rollBack();
                jsonResponse([
                    'error' => 'This account is linked to borrowing or reservation history and cannot be deleted. Deactivate it instead to preserve transaction records.',
                ], 409);
            }

            logLibrarianActivity($db, $account, $id, $librarian['username'], 'librarian.deleted');
            $stmt = $db->prepare("DELETE FROM users WHERE user_id = :id AND role = 'Librarian'");
            $stmt->execute([':id' => $id]);
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23000') {
                jsonResponse(['error' => 'This account is linked to other records and cannot be deleted. Deactivate it instead.'], 409);
            }
            throw $e;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        jsonResponse(['success' => true, 'message' => 'Librarian account deleted successfully']);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
