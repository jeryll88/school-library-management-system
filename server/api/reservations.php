<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$db       = getDB();
$account = requireApiAccount();
$method   = $_SERVER['REQUEST_METHOD'];
$currUser = $account['user_id'];
$currRole = $account['role'];

switch ($method) {
    case 'GET':
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';
        $userId = $currRole === 'User' ? $currUser : ($_GET['user_id'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 10;
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];

        if ($userId !== '' && $userId !== null) {
            $where[]  = "r.user_id = :user_id";
            $params[':user_id'] = (int)$userId;
        }
        if ($search !== '') {
            $where[]  = "(s.name LIKE :search_student OR b.title LIKE :search_book)";
            $params[':search_student'] = "%$search%";
            $params[':search_book'] = "%$search%";
        }
        if ($status !== '') {
            $where[]  = "r.status = :status";
            $params[':status'] = $status;
        }
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $db->prepare("
            SELECT COUNT(*) FROM reservations r
            JOIN users u ON r.user_id = u.user_id
            LEFT JOIN students s ON u.student_id = s.id
            JOIN books b ON r.book_id = b.book_id
            $whereSQL
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT r.reservation_id, r.reservation_date, r.status,
                   s.name AS student_name, u.full_name,
                   b.title AS book_title, b.book_id
            FROM reservations r
            JOIN users u ON r.user_id = u.user_id
            LEFT JOIN students s ON u.student_id = s.id
            JOIN books b ON r.book_id = b.book_id
            $whereSQL
            ORDER BY r.reservation_date DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        jsonResponse(['data' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'limit' => $limit]);
        break;

    case 'POST':
        $input = getInput();
        $targetUser = $currRole === 'User' ? $currUser : (int)($input['user_id'] ?? 0);
        if ($targetUser < 1 || empty($input['book_id'])) {
            jsonResponse(['error' => 'A valid user and book are required'], 422);
        }
        $borrower = $db->prepare("SELECT user_id FROM users WHERE user_id = :user_id AND role = 'User' AND is_active = 1");
        $borrower->execute([':user_id' => $targetUser]);
        if (!$borrower->fetch()) {
            jsonResponse(['error' => 'Reservations must belong to an active student account'], 422);
        }

        // Check if student already has a pending reservation for this book
        $dup = $db->prepare("SELECT reservation_id FROM reservations WHERE user_id = :u AND book_id = :b AND status = 'Pending'");
        $dup->execute([':u' => $targetUser, ':b' => $input['book_id']]);
        if ($dup->fetch()) {
            jsonResponse(['error' => 'You already have an active pending reservation for this book.'], 400);
        }

        $stmt = $db->prepare("INSERT INTO reservations (user_id, book_id, status) VALUES (:user, :book, 'Pending')");
        $stmt->execute([':user' => $targetUser, ':book' => $input['book_id']]);
        jsonResponse(['success' => true, 'reservation_id' => $db->lastInsertId()], 201);
        break;

    case 'PUT':
        $input = getInput();
        $id    = $_GET['id'] ?? $input['reservation_id'] ?? null;
        if (!$id) jsonResponse(['error' => 'Reservation ID required'], 422);
        if ($currRole === 'User') {
            if (($input['status'] ?? '') !== 'Cancelled') {
                jsonResponse(['error' => 'Students may only cancel their own reservations'], 403);
            }
            $stmt = $db->prepare("
                UPDATE reservations SET status = 'Cancelled'
                WHERE reservation_id = :id AND user_id = :user_id AND status IN ('Pending', 'Approved')
            ");
            $stmt->execute([':id' => $id, ':user_id' => $currUser]);
            if ($stmt->rowCount() === 0) {
                jsonResponse(['error' => 'Reservation not found or cannot be cancelled'], 404);
            }
        } else {
            $allowedStatuses = ['Pending', 'Approved', 'Cancelled', 'Fulfilled'];
            if (!in_array($input['status'] ?? '', $allowedStatuses, true)) {
                jsonResponse(['error' => 'Reservation status is required'], 422);
            }
            $stmt = $db->prepare("UPDATE reservations SET status=:status WHERE reservation_id=:id");
            $stmt->execute([':status' => $input['status'], ':id' => $id]);
        }
        jsonResponse(['success' => true]);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) jsonResponse(['error' => 'Reservation ID required'], 422);
        if ($currRole === 'User') {
            $stmt = $db->prepare("DELETE FROM reservations WHERE reservation_id = :id AND user_id = :user_id");
            $stmt->execute([':id' => $id, ':user_id' => $currUser]);
            if ($stmt->rowCount() === 0) {
                jsonResponse(['error' => 'Reservation not found'], 404);
            }
        } else {
            $db->prepare("DELETE FROM reservations WHERE reservation_id=:id")->execute([':id' => $id]);
        }
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
