<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$db       = getDB();
$account = requireApiAccount();
$method   = $_SERVER['REQUEST_METHOD'];
$currUser = $account['user_id'];
$currRole = $account['role'];

if ($method !== 'GET' && !in_array($currRole, ['Admin', 'Librarian'], true)) {
    jsonResponse(['error' => 'Only library staff can process borrowing and returns'], 403);
}

switch ($method) {
    case 'GET':
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';
        $userId = $currRole === 'User' ? $currUser : ($_GET['user_id'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(5000, max(1, (int)($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];

        if ($userId !== '' && $userId !== null) {
            $where[]  = "br.user_id = :user_id";
            $params[':user_id'] = (int)$userId;
        }
        if ($search !== '') {
            $where[]  = "(s.name LIKE :search_student OR b.title LIKE :search_book OR bc.barcode LIKE :search_barcode)";
            $params[':search_student'] = "%$search%";
            $params[':search_book'] = "%$search%";
            $params[':search_barcode'] = "%$search%";
        }
        if ($status !== '') {
            $where[]  = "br.status = :status";
            $params[':status'] = $status;
        }

        if ($currRole !== 'User') {
            $db->query("UPDATE borrowing_records SET status='Overdue' WHERE status='Borrowed' AND due_date < CURRENT_DATE");
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM borrowing_records br
            JOIN users u ON br.user_id = u.user_id
            LEFT JOIN students s ON u.student_id = s.id
            JOIN book_copies bc ON br.copy_id = bc.copy_id
            JOIN books b ON bc.book_id = b.book_id
            $whereSQL
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT br.borrow_id, br.borrow_date, br.due_date, br.return_date, br.status,
                   s.name AS student_name, u.full_name,
                   b.title AS book_title, bc.barcode, bc.copy_id,
                   CONCAT('C', LPAD(bc.copy_id,3,'0'), ' - ', b.title) AS copy_label
            FROM borrowing_records br
            JOIN users u ON br.user_id = u.user_id
            LEFT JOIN students s ON u.student_id = s.id
            JOIN book_copies bc ON br.copy_id = bc.copy_id
            JOIN books b ON bc.book_id = b.book_id
            $whereSQL
            ORDER BY br.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        jsonResponse(['data' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'limit' => $limit]);
        break;

    case 'POST':
        // Issue a book
        $input = getInput();
        $required = ['user_id', 'copy_id', 'borrow_date', 'due_date'];
        foreach ($required as $f) {
            if (empty($input[$f])) jsonResponse(['error' => "Field '$f' is required"], 422);
        }

        $borrowerStmt = $db->prepare("SELECT user_id FROM users WHERE user_id = :id AND role = 'User' AND is_active = 1");
        $borrowerStmt->execute([':id' => $input['user_id']]);
        if (!$borrowerStmt->fetch()) {
            jsonResponse(['error' => 'An active student account is required to issue a book'], 422);
        }

        // Enforce Borrowing Quota and Overdue Rules
        $targetStudentId = (int)$input['user_id'];
        $quotaCheck = $db->prepare("
            SELECT 
                COUNT(*) AS active_count,
                COALESCE(SUM(CASE WHEN status = 'Overdue' OR (status = 'Borrowed' AND due_date < CURRENT_DATE) THEN 1 ELSE 0 END), 0) AS overdue_count
            FROM borrowing_records 
            WHERE user_id = :uid AND status IN ('Borrowed', 'Overdue')
        ");
        $quotaCheck->execute([':uid' => $targetStudentId]);
        $quotaStats = $quotaCheck->fetch();

        $overdueCount = (int)($quotaStats['overdue_count'] ?? 0);
        $activeCount  = (int)($quotaStats['active_count'] ?? 0);

        if ($overdueCount > 0) {
            jsonResponse([
                'error' => "Cannot issue book: this student currently has {$overdueCount} overdue book(s) that must be returned first."
            ], 400);
        }

        $maxQuota = 3;
        if ($activeCount >= $maxQuota) {
            jsonResponse([
                'error' => "Borrowing quota reached: this student already has {$activeCount} active borrowed book(s). The maximum allowed quota is {$maxQuota} books at a time."
            ], 400);
        }

        // Check copy is available
        $copyStmt = $db->prepare("SELECT status FROM book_copies WHERE copy_id = :id");
        $copyStmt->execute([':id' => $input['copy_id']]);
        $copy = $copyStmt->fetch();
        if (!$copy) jsonResponse(['error' => 'Book copy not found'], 404);
        if ($copy['status'] !== 'Available') jsonResponse(['error' => 'Book copy is not available'], 409);

        // Create borrowing record
        $stmt = $db->prepare("
            INSERT INTO borrowing_records (user_id, copy_id, issued_by, borrow_date, due_date, status)
            VALUES (:user, :copy, :issued, :borrow, :due, 'Borrowed')
        ");
        $stmt->execute([
            ':user'   => $input['user_id'],
            ':copy'   => $input['copy_id'],
            ':issued' => $currUser,
            ':borrow' => $input['borrow_date'],
            ':due'    => $input['due_date'],
        ]);
        $borrowId = $db->lastInsertId();

        // Update copy status
        $db->prepare("UPDATE book_copies SET status='Borrowed' WHERE copy_id=:id")
           ->execute([':id' => $input['copy_id']]);

        jsonResponse(['success' => true, 'borrow_id' => $borrowId], 201);
        break;

    case 'PUT':
        // Record return OR update status
        $input = getInput();
        $id    = $_GET['id'] ?? $input['borrow_id'] ?? null;
        if (!$id) jsonResponse(['error' => 'Borrow ID required'], 422);

        $action = $input['action'] ?? 'return';

        if ($action === 'return') {
            // Get copy_id
            $row = $db->prepare("SELECT copy_id FROM borrowing_records WHERE borrow_id=:id");
            $row->execute([':id' => $id]);
            $record = $row->fetch();
            if (!$record) jsonResponse(['error' => 'Record not found'], 404);

            $returnDate = $input['return_date'] ?? date('Y-m-d');
            $db->prepare("UPDATE borrowing_records SET return_date=:ret, status='Returned' WHERE borrow_id=:id")
               ->execute([':ret' => $returnDate, ':id' => $id]);

            // Free the copy
            $db->prepare("UPDATE book_copies SET status='Available' WHERE copy_id=:id")
               ->execute([':id' => $record['copy_id']]);
        } else {
            $db->prepare("UPDATE borrowing_records SET status=:status WHERE borrow_id=:id")
               ->execute([':status' => $input['status'], ':id' => $id]);
        }
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
