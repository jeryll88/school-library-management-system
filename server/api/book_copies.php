<?php
error_reporting(0);
ob_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$account = requireApiAccount();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && !in_array(strtolower($account['role'] ?? ''), ['admin', 'librarian'], true)) {
    jsonResponse(['error' => 'Only library staff can modify book copies'], 403);
}

switch ($method) {
    case 'GET':
        // Single copy lookup by barcode (for physical scanner)
        if (isset($_GET['barcode']) && trim($_GET['barcode']) !== '') {
            $barcode = trim($_GET['barcode']);
            $stmt = $db->prepare("
                SELECT bc.*, b.title AS book_title, b.author, b.isbn
                FROM book_copies bc
                JOIN books b ON bc.book_id = b.book_id
                WHERE LOWER(bc.barcode) = LOWER(:barcode)
                LIMIT 1
            ");
            $stmt->execute([':barcode' => $barcode]);
            $copy = $stmt->fetch();
            if (!$copy) {
                jsonResponse(['error' => 'No copy found with barcode ' . $barcode], 404);
            }
            jsonResponse(['data' => $copy]);
            break;
        }

        $bookId = $_GET['book_id'] ?? '';
        $search = $_GET['search']  ?? '';
        $status = $_GET['status']  ?? '';
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(5000, max(1, (int)($_GET['limit'] ?? 15)));
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];

        if ($bookId !== '') {
            $where[]  = "bc.book_id = :book_id";
            $params[':book_id'] = $bookId;
        }
        if ($status !== '') {
            $where[]  = "bc.status = :status";
            $params[':status'] = $status;
        }
        if ($search !== '') {
            $where[]  = "(b.title LIKE :search_title OR bc.barcode LIKE :search_barcode)";
            $params[':search_title'] = "%$search%";
            $params[':search_barcode'] = "%$search%";
        }
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $db->prepare("SELECT COUNT(*) FROM book_copies bc JOIN books b ON bc.book_id=b.book_id $whereSQL");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT bc.*, b.title AS book_title, b.author,
                   r.reservation_id, r.status AS reservation_status,
                   u.full_name AS reserved_by_name, s.name AS reserved_student_name,
                   s.student_number AS reserved_by_student_id, u.username AS reserved_by_username
            FROM book_copies bc
            JOIN books b ON bc.book_id = b.book_id
            LEFT JOIN reservations r ON bc.copy_id = r.copy_id AND r.status IN ('Pending', 'Approved')
            LEFT JOIN users u ON r.user_id = u.user_id
            LEFT JOIN students s ON u.student_id = s.id
            $whereSQL
            ORDER BY bc.copy_id ASC
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
        if (empty($input['book_id']) || empty($input['barcode'])) {
            jsonResponse(['error' => 'book_id and barcode are required'], 422);
        }

        $dup = $db->prepare("SELECT copy_id FROM book_copies WHERE LOWER(barcode) = LOWER(:b) LIMIT 1");
        $dup->execute([':b' => $input['barcode']]);
        if ($dup->fetch()) {
            jsonResponse(['error' => "Barcode '{$input['barcode']}' is already assigned to another copy"], 409);
        }

        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $stmt = $db->prepare("INSERT INTO book_copies (book_id, barcode, status) VALUES (:book_id, :barcode, :status) RETURNING copy_id");
            $stmt->execute([
                ':book_id' => $input['book_id'],
                ':barcode' => $input['barcode'],
                ':status'  => $input['status'] ?? 'Available',
            ]);
            $copyId = (int)$stmt->fetchColumn();
        } else {
            $stmt = $db->prepare("INSERT INTO book_copies (book_id, barcode, status) VALUES (:book_id, :barcode, :status)");
            $stmt->execute([
                ':book_id' => $input['book_id'],
                ':barcode' => $input['barcode'],
                ':status'  => $input['status'] ?? 'Available',
            ]);
            $copyId = (int)$db->lastInsertId();
        }
        jsonResponse(['success' => true, 'copy_id' => $copyId], 201);
        break;

    case 'PUT':
        $input = getInput();
        $id    = $_GET['id'] ?? $input['copy_id'] ?? null;
        if (!$id) jsonResponse(['error' => 'Copy ID required'], 422);
        $stmt = $db->prepare("UPDATE book_copies SET barcode=:barcode, status=:status WHERE copy_id=:id");
        $stmt->execute([
            ':barcode' => $input['barcode'] ?? '',
            ':status'  => $input['status']  ?? 'Available',
            ':id'      => $id,
        ]);
        jsonResponse(['success' => true]);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) jsonResponse(['error' => 'Copy ID required'], 422);
        $db->prepare("DELETE FROM book_copies WHERE copy_id = :id")->execute([':id' => $id]);
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
