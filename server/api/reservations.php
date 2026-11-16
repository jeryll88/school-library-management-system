<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$db       = getDB();
$account  = requireApiAccount();
$method   = $_SERVER['REQUEST_METHOD'];
$currUser = $account['user_id'];
$currRole = $account['role'];

// Ensure reservations table has copy_id column for stock deduction
function ensureReservationCopyColumn($db) {
    static $ensured = false;
    if ($ensured) return;
    try {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $db->exec("ALTER TABLE reservations ADD COLUMN IF NOT EXISTS copy_id INTEGER REFERENCES book_copies(copy_id) ON DELETE SET NULL");
        } else {
            $check = $db->query("SHOW COLUMNS FROM reservations LIKE 'copy_id'")->fetch();
            if (!$check) {
                $db->exec("ALTER TABLE reservations ADD COLUMN copy_id INT NULL");
            }
        }
    } catch (Exception $e) {
        // Ignored if column already exists
    }
    $ensured = true;
}
ensureReservationCopyColumn($db);

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
            SELECT r.reservation_id, r.reservation_date, r.status, r.copy_id,
                   bc.barcode AS reserved_barcode,
                   s.name AS student_name, u.full_name,
                   b.title AS book_title, b.book_id
            FROM reservations r
            JOIN users u ON r.user_id = u.user_id
            LEFT JOIN students s ON u.student_id = s.id
            JOIN books b ON r.book_id = b.book_id
            LEFT JOIN book_copies bc ON r.copy_id = bc.copy_id
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

        // Check if student already has a pending or approved reservation for this book
        $dup = $db->prepare("SELECT reservation_id FROM reservations WHERE user_id = :u AND book_id = :b AND status IN ('Pending', 'Approved')");
        $dup->execute([':u' => $targetUser, ':b' => $input['book_id']]);
        if ($dup->fetch()) {
            jsonResponse(['error' => 'You already have an active reservation for this book.'], 400);
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

            // Retrieve reservation to check if a copy was reserved
            $currResStmt = $db->prepare("SELECT reservation_id, copy_id, status FROM reservations WHERE reservation_id = :id AND user_id = :user_id");
            $currResStmt->execute([':id' => $id, ':user_id' => $currUser]);
            $currRes = $currResStmt->fetch();

            if (!$currRes || !in_array($currRes['status'], ['Pending', 'Approved'], true)) {
                jsonResponse(['error' => 'Reservation not found or cannot be cancelled'], 404);
            }

            // If it was Approved, release the reserved copy back to Available (stock increases)
            if ($currRes['status'] === 'Approved' && !empty($currRes['copy_id'])) {
                $db->prepare("UPDATE book_copies SET status = 'Available' WHERE copy_id = :copy_id AND status = 'Reserved'")
                   ->execute([':copy_id' => $currRes['copy_id']]);
            }

            $stmt = $db->prepare("UPDATE reservations SET status = 'Cancelled', copy_id = NULL WHERE reservation_id = :id");
            $stmt->execute([':id' => $id]);
        } else {
            $allowedStatuses = ['Pending', 'Approved', 'Cancelled', 'Fulfilled'];
            $newStatus = $input['status'] ?? '';
            if (!in_array($newStatus, $allowedStatuses, true)) {
                jsonResponse(['error' => 'Valid reservation status is required'], 422);
            }

            // Fetch existing reservation details
            $resStmt = $db->prepare("SELECT reservation_id, book_id, user_id, copy_id, status FROM reservations WHERE reservation_id = :id");
            $resStmt->execute([':id' => $id]);
            $res = $resStmt->fetch();

            if (!$res) {
                jsonResponse(['error' => 'Reservation not found'], 404);
            }

            $oldStatus       = $res['status'];
            $bookId          = (int)$res['book_id'];
            $existingCopyId  = $res['copy_id'];

            if ($newStatus === 'Approved' && $oldStatus !== 'Approved') {
                // When librarian APPROVES the reservation: find an Available copy of the book
                $findCopy = $db->prepare("
                    SELECT copy_id FROM book_copies 
                    WHERE book_id = :book_id AND status = 'Available' 
                    ORDER BY copy_id ASC LIMIT 1
                ");
                $findCopy->execute([':book_id' => $bookId]);
                $copyRow = $findCopy->fetch();

                if (!$copyRow) {
                    jsonResponse(['error' => 'Cannot approve reservation: no available stock/copies left for this book.'], 409);
                }

                $reservedCopyId = (int)$copyRow['copy_id'];

                // Deduct book stock by marking this physical copy as 'Reserved'
                $db->prepare("UPDATE book_copies SET status = 'Reserved' WHERE copy_id = :copy_id")
                   ->execute([':copy_id' => $reservedCopyId]);

                // Link copy to the reservation and set Approved
                $stmt = $db->prepare("UPDATE reservations SET status = 'Approved', copy_id = :copy_id WHERE reservation_id = :id");
                $stmt->execute([':copy_id' => $reservedCopyId, ':id' => $id]);

            } elseif ($oldStatus === 'Approved' && in_array($newStatus, ['Cancelled', 'Pending'], true)) {
                // If previously Approved and now Cancelled or Pending: release copy back to Available (stock increases)
                if (!empty($existingCopyId)) {
                    $db->prepare("UPDATE book_copies SET status = 'Available' WHERE copy_id = :copy_id AND status = 'Reserved'")
                       ->execute([':copy_id' => $existingCopyId]);
                }
                $stmt = $db->prepare("UPDATE reservations SET status = :status, copy_id = NULL WHERE reservation_id = :id");
                $stmt->execute([':status' => $newStatus, ':id' => $id]);

            } else {
                // Other status updates (e.g. Fulfilled)
                $stmt = $db->prepare("UPDATE reservations SET status = :status WHERE reservation_id = :id");
                $stmt->execute([':status' => $newStatus, ':id' => $id]);
            }
        }
        jsonResponse(['success' => true]);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) jsonResponse(['error' => 'Reservation ID required'], 422);

        $resStmt = $db->prepare("SELECT copy_id, status FROM reservations WHERE reservation_id = :id");
        $resStmt->execute([':id' => $id]);
        $res = $resStmt->fetch();

        // If deleting an Approved reservation that has a reserved copy, release copy back to Available
        if ($res && $res['status'] === 'Approved' && !empty($res['copy_id'])) {
            $db->prepare("UPDATE book_copies SET status = 'Available' WHERE copy_id = :copy_id AND status = 'Reserved'")
               ->execute([':copy_id' => $res['copy_id']]);
        }

        if ($currRole === 'User') {
            $stmt = $db->prepare("DELETE FROM reservations WHERE reservation_id = :id AND user_id = :user_id");
            $stmt->execute([':id' => $id, ':user_id' => $currUser]);
            if ($stmt->rowCount() === 0) {
                jsonResponse(['error' => 'Reservation not found'], 404);
            }
        } else {
            $db->prepare("DELETE FROM reservations WHERE reservation_id = :id")->execute([':id' => $id]);
        }
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
