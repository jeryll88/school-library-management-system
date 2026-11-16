<?php
// Suppress PHP warnings/notices from leaking into JSON responses
error_reporting(0);
ob_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Discard any accidental output before headers
ob_clean();
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$db       = getDB();
$account  = requireApiAccount();
$method   = $_SERVER['REQUEST_METHOD'];
$currUser = $account['user_id'];
$currRole = $account['role'];

// Ensure reservations table has copy_id column for stock deduction (defined in db.php)
if (function_exists('ensureReservationCopyColumn')) {
    ensureReservationCopyColumn($db);
}

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

        try {
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
                SELECT r.reservation_id, r.reservation_date, r.status, r.copy_id, r.user_id,
                       bc.barcode AS reserved_barcode,
                       s.name AS student_name, s.student_number, u.full_name, u.username,
                       b.title AS book_title, b.book_id
                FROM reservations r
                JOIN users u ON r.user_id = u.user_id
                LEFT JOIN students s ON u.student_id = s.id
                JOIN books b ON r.book_id = b.book_id
                LEFT JOIN book_copies bc ON r.copy_id = bc.copy_id
                $whereSQL
                ORDER BY r.reservation_id DESC
                LIMIT :limit OFFSET :offset
            ");
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $resData = $stmt->fetchAll();
        } catch (Throwable $e) {
            // Fallback if copy_id column is missing or table structure varies
            try {
                $countStmt = $db->prepare("
                    SELECT COUNT(*) FROM reservations r
                    JOIN users u ON r.user_id = u.user_id
                    JOIN books b ON r.book_id = b.book_id
                    $whereSQL
                ");
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();

                $stmt = $db->prepare("
                    SELECT r.reservation_id, r.reservation_date, r.status, NULL AS copy_id,
                           NULL AS reserved_barcode,
                           u.full_name AS student_name, u.full_name,
                           b.title AS book_title, b.book_id
                    FROM reservations r
                    JOIN users u ON r.user_id = u.user_id
                    JOIN books b ON r.book_id = b.book_id
                    $whereSQL
                    ORDER BY r.reservation_id DESC
                    LIMIT :limit OFFSET :offset
                ");
                foreach ($params as $k => $v) $stmt->bindValue($k, $v);
                $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $resData = $stmt->fetchAll();
            } catch (Throwable $e2) {
                $resData = [];
                $total = 0;
            }
        }
        jsonResponse(['data' => $resData, 'total' => $total, 'page' => $page, 'limit' => $limit]);
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

        // Check if student already has an active borrowed copy of this book (cannot borrow duplicates/similar copies)
        $dupBorrow = $db->prepare("
            SELECT br.borrow_id, b.title
            FROM borrowing_records br
            JOIN book_copies bc ON br.copy_id = bc.copy_id
            JOIN books b ON bc.book_id = b.book_id
            WHERE br.user_id = :u AND bc.book_id = :b AND br.status IN ('Borrowed', 'Overdue')
            LIMIT 1
        ");
        $dupBorrow->execute([':u' => $targetUser, ':b' => $input['book_id']]);
        if ($dupRow = $dupBorrow->fetch()) {
            jsonResponse(['error' => 'You already have an active borrowed copy of "' . $dupRow['title'] . '". Students can only borrow 1 copy of a book.'], 400);
        }

        // Enforce 5-book borrowing quota
        $maxQuota = 5;
        $activeBorrowCheck = $db->prepare("
            SELECT COUNT(*) FROM borrowing_records 
            WHERE user_id = :u AND status IN ('Borrowed', 'Overdue')
        ");
        $activeBorrowCheck->execute([':u' => $targetUser]);
        $activeBorrowCount = (int)$activeBorrowCheck->fetchColumn();
        if ($activeBorrowCount >= $maxQuota) {
            jsonResponse(['error' => "Borrowing limit reached: You currently have {$activeBorrowCount} active borrowed books (maximum limit is 5). Please return a book to free up your borrowing quota before reserving another."], 400);
        }

        // Check active reservations (total borrowed + reserved cannot exceed 5)
        $activeResCheck = $db->prepare("
            SELECT COUNT(*) FROM reservations 
            WHERE user_id = :u AND status IN ('Pending', 'Approved')
        ");
        $activeResCheck->execute([':u' => $targetUser]);
        $activeResCount = (int)$activeResCheck->fetchColumn();
        if (($activeBorrowCount + $activeResCount) >= $maxQuota) {
            jsonResponse(['error' => "Reservation limit reached: You have {$activeBorrowCount} active borrowed book(s) and {$activeResCount} active hold(s). Maximum total allowed is 5. Please return a book to restore your quota slots."], 400);
        }

        // Verify book exists and has available copies before allowing reservation
        $bookCheck = $db->prepare("
            SELECT b.book_id, b.title,
                   COALESCE(SUM(CASE WHEN bc.status = 'Available' THEN 1 ELSE 0 END), 0) AS available_copies
            FROM books b
            LEFT JOIN book_copies bc ON bc.book_id = b.book_id
            WHERE b.book_id = :book_id
            GROUP BY b.book_id, b.title
        ");
        $bookCheck->execute([':book_id' => $input['book_id']]);
        $bookRow = $bookCheck->fetch();
        if (!$bookRow) {
            jsonResponse(['error' => 'Selected book does not exist in catalog'], 404);
        }
        if ((int)$bookRow['available_copies'] <= 0) {
            jsonResponse(['error' => 'All copies of "' . $bookRow['title'] . '" are currently borrowed or reserved. No copies available to reserve.'], 400);
        }

        // Find the first available copy to assign and hold immediately (deducts stock)
        $findCopy = $db->prepare("
            SELECT copy_id FROM book_copies 
            WHERE book_id = :book_id AND status = 'Available' 
            ORDER BY copy_id ASC LIMIT 1
        ");
        $findCopy->execute([':book_id' => $input['book_id']]);
        $copyRow = $findCopy->fetch();
        if (!$copyRow) {
            jsonResponse(['error' => 'All copies of "' . $bookRow['title'] . '" are currently borrowed or reserved.'], 400);
        }
        $reservedCopyId = (int)$copyRow['copy_id'];

        // Immediately hold copy by marking as Reserved (available stock decreases by 1)
        $db->prepare("UPDATE book_copies SET status = 'Reserved' WHERE copy_id = :copy_id")
           ->execute([':copy_id' => $reservedCopyId]);

        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $stmt = $db->prepare("INSERT INTO reservations (user_id, book_id, copy_id, status) VALUES (:user, :book, :copy, 'Pending') RETURNING reservation_id");
            $stmt->execute([':user' => $targetUser, ':book' => $input['book_id'], ':copy' => $reservedCopyId]);
            $resId = $stmt->fetchColumn();
        } else {
            $stmt = $db->prepare("INSERT INTO reservations (user_id, book_id, copy_id, status) VALUES (:user, :book, :copy, 'Pending')");
            $stmt->execute([':user' => $targetUser, ':book' => $input['book_id'], ':copy' => $reservedCopyId]);
            $resId = $db->lastInsertId();
        }
        jsonResponse(['success' => true, 'reservation_id' => $resId], 201);
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

            // Release the reserved copy back to Available (stock increases)
            if (!empty($currRes['copy_id'])) {
                $db->prepare("UPDATE book_copies SET status = 'Available' WHERE copy_id = :copy_id AND status = 'Reserved'")
                   ->execute([':copy_id' => $currRes['copy_id']]);
            }

            $stmt = $db->prepare("UPDATE reservations SET status = 'Cancelled', copy_id = NULL WHERE reservation_id = :id");
            $stmt->execute([':id' => $id]);
        } else {
            $allowedStatuses = ['Pending', 'Approved', 'Cancelled', 'Fulfilled', 'Rejected'];
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

            if ($newStatus === 'Approved') {
                // Ensure copy is assigned and marked Reserved
                if (empty($existingCopyId)) {
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
                    $existingCopyId = (int)$copyRow['copy_id'];
                    $db->prepare("UPDATE book_copies SET status = 'Reserved' WHERE copy_id = :copy_id")
                       ->execute([':copy_id' => $existingCopyId]);
                } else {
                    $db->prepare("UPDATE book_copies SET status = 'Reserved' WHERE copy_id = :copy_id")
                       ->execute([':copy_id' => $existingCopyId]);
                }
                $stmt = $db->prepare("UPDATE reservations SET status = 'Approved', copy_id = :copy_id WHERE reservation_id = :id");
                $stmt->execute([':copy_id' => $existingCopyId, ':id' => $id]);

            } elseif (in_array($newStatus, ['Cancelled', 'Rejected'], true)) {
                // If Cancelled or Rejected: release copy back to Available (stock increases by 1)
                if (!empty($existingCopyId)) {
                    $db->prepare("UPDATE book_copies SET status = 'Available' WHERE copy_id = :copy_id AND status = 'Reserved'")
                       ->execute([':copy_id' => $existingCopyId]);
                }
                $stmt = $db->prepare("UPDATE reservations SET status = :status, copy_id = NULL WHERE reservation_id = :id");
                $stmt->execute([':status' => $newStatus, ':id' => $id]);

            } elseif ($newStatus === 'Fulfilled') {
                if ($oldStatus === 'Fulfilled') {
                    jsonResponse(['error' => 'This reservation has already been fulfilled.'], 400);
                }

                // When marking as Fulfilled: transition copy to Borrowed and insert into borrowing_records
                if (empty($existingCopyId)) {
                    $findCopy = $db->prepare("
                        SELECT copy_id FROM book_copies 
                        WHERE book_id = :book_id AND status IN ('Available', 'Reserved') 
                        ORDER BY copy_id ASC LIMIT 1
                    ");
                    $findCopy->execute([':book_id' => $bookId]);
                    $cRow = $findCopy->fetch();
                    if ($cRow) {
                        $existingCopyId = (int)$cRow['copy_id'];
                    }
                }

                if (empty($existingCopyId)) {
                    jsonResponse(['error' => 'No copy found to fulfill this reservation.'], 404);
                }

                // Prevent duplicate: check if copy already actively borrowed
                $dupCopyBorrow = $db->prepare("
                    SELECT borrow_id FROM borrowing_records 
                    WHERE copy_id = :cid AND status IN ('Borrowed', 'Overdue')
                    LIMIT 1
                ");
                $dupCopyBorrow->execute([':cid' => $existingCopyId]);
                if ($dupCopyBorrow->fetch()) {
                    jsonResponse(['error' => 'This copy already has an active borrowing record. Cannot fulfill.'], 409);
                }

                // Prevent exceeding quota: check if student already has 5 active borrowed books
                $maxQuota = 5;
                $userBorrowCheck = $db->prepare("
                    SELECT COUNT(*) FROM borrowing_records 
                    WHERE user_id = :uid AND status IN ('Borrowed', 'Overdue')
                ");
                $userBorrowCheck->execute([':uid' => (int)$res['user_id']]);
                $currLoans = (int)$userBorrowCheck->fetchColumn();
                if ($currLoans >= $maxQuota) {
                    jsonResponse(['error' => "Borrowing limit reached: This student already has {$currLoans} active borrowed books (maximum limit is 5). Cannot fulfill reservation until a book is returned."], 409);
                }

                // Prevent duplicate copy of the same book
                $dupBookCheck = $db->prepare("
                    SELECT br.borrow_id, b.title
                    FROM borrowing_records br
                    JOIN book_copies bc ON br.copy_id = bc.copy_id
                    JOIN books b ON bc.book_id = b.book_id
                    WHERE br.user_id = :uid AND bc.book_id = :bid AND br.status IN ('Borrowed', 'Overdue')
                    LIMIT 1
                ");
                $dupBookCheck->execute([':uid' => (int)$res['user_id'], ':bid' => $bookId]);
                if ($dupBookRow = $dupBookCheck->fetch()) {
                    jsonResponse(['error' => "This student already has an active borrowed copy of \"{$dupBookRow['title']}\". Students can only borrow 1 copy per book."], 409);
                }

                // Update copy status to Borrowed
                $db->prepare("UPDATE book_copies SET status = 'Borrowed' WHERE copy_id = :copy_id")
                   ->execute([':copy_id' => $existingCopyId]);

                $targetUid = (int)$res['user_id'];
                $borrowDate = !empty($input['borrow_date']) ? $input['borrow_date'] : date('Y-m-d');
                $dueDate = !empty($input['due_date']) ? $input['due_date'] : date('Y-m-d', strtotime('+14 days'));

                $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'pgsql') {
                    $bStmt = $db->prepare("
                        INSERT INTO borrowing_records (user_id, copy_id, issued_by, borrow_date, due_date, status)
                        VALUES (:user, :copy, :issued, :borrow, :due, 'Borrowed')
                        RETURNING borrow_id
                    ");
                } else {
                    $bStmt = $db->prepare("
                        INSERT INTO borrowing_records (user_id, copy_id, issued_by, borrow_date, due_date, status)
                        VALUES (:user, :copy, :issued, :borrow, :due, 'Borrowed')
                    ");
                }
                $bStmt->execute([
                    ':user'   => $targetUid,
                    ':copy'   => $existingCopyId,
                    ':issued' => $currUser,
                    ':borrow' => $borrowDate,
                    ':due'    => $dueDate,
                ]);

                $stmt = $db->prepare("UPDATE reservations SET status = 'Fulfilled', copy_id = :copy_id WHERE reservation_id = :id");
                $stmt->execute([':copy_id' => $existingCopyId, ':id' => $id]);

            } else {
                // Other status updates
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

        // If deleting a reservation that has a reserved copy, release copy back to Available
        if ($res && !empty($res['copy_id']) && $res['status'] !== 'Fulfilled') {
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
