<?php
error_reporting(0);
ob_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$db  = getDB();
$account = requireApiAccount();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && !in_array($account['role'], ['Admin', 'Librarian'], true)) {
    jsonResponse(['error' => 'Only library staff can modify books'], 403);
}

switch ($method) {
    case 'GET':
        // 1. Single Book by ID
        if (isset($_GET['id']) && $_GET['id'] !== '') {
            $bookId = (int)$_GET['id'];
            $stmt = $db->prepare("
                SELECT b.*, c.category_name, c.category_type,
                       COUNT(bc.copy_id) AS total_copies,
                       COALESCE(SUM(CASE WHEN bc.status = 'Available' THEN 1 ELSE 0 END), 0) AS available_copies
                FROM books b
                LEFT JOIN categories c ON b.category_id = c.category_id
                LEFT JOIN book_copies bc ON bc.book_id = b.book_id
                WHERE b.book_id = :id
                GROUP BY b.book_id, c.category_name, c.category_type
                LIMIT 1
            ");
            $stmt->execute([':id' => $bookId]);
            $book = $stmt->fetch();
            if (!$book) {
                jsonResponse(['error' => 'Book not found'], 404);
            }
            jsonResponse(['data' => $book]);
            break;
        }

        // 1b. Single Book by ISBN (Direct Hardware / Barcode Scanner Lookup)
        if (isset($_GET['isbn']) && trim($_GET['isbn']) !== '') {
            $rawIsbn   = trim($_GET['isbn']);
            $cleanIsbn = preg_replace('/[^0-9X]/i', '', $rawIsbn);
            $stmt = $db->prepare("
                SELECT b.*, c.category_name, c.category_type,
                       COUNT(bc.copy_id) AS total_copies,
                       COALESCE(SUM(CASE WHEN bc.status = 'Available' THEN 1 ELSE 0 END), 0) AS available_copies
                FROM books b
                LEFT JOIN categories c ON b.category_id = c.category_id
                LEFT JOIN book_copies bc ON bc.book_id = b.book_id
                WHERE b.isbn = :raw 
                   OR REPLACE(REPLACE(b.isbn, '-', ''), ' ', '') = :clean
                GROUP BY b.book_id, c.category_name, c.category_type
                LIMIT 1
            ");
            $stmt->execute([':raw' => $rawIsbn, ':clean' => $cleanIsbn]);
            $book = $stmt->fetch();
            if (!$book) {
                jsonResponse(['error' => 'No book found matching ISBN ' . $rawIsbn], 404);
            }
            jsonResponse(['data' => $book]);
            break;
        }

        // 2. Paginated list
        $search   = trim($_GET['search']   ?? '');
        $category = trim($_GET['category'] ?? '');
        $type     = trim($_GET['type']     ?? '');
        $availOnly = !empty($_GET['available_only']);
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $limit    = min(5000, max(1, (int)($_GET['limit'] ?? 10)));
        $offset   = ($page - 1) * $limit;

        $where  = [];
        $params = [];

        if ($search !== '') {
            $where[]  = "(b.title LIKE :search_title OR b.author LIKE :search_author OR b.isbn LIKE :search_isbn)";
            $params[':search_title']  = "%$search%";
            $params[':search_author'] = "%$search%";
            $params[':search_isbn']   = "%$search%";
        }
        if ($category !== '') {
            $where[]  = "b.category_id = :cat";
            $params[':cat'] = $category;
        }
        if ($type !== '' && in_array($type, ['Academic', 'Non-Academic'], true)) {
            $where[]  = "c.category_type = :type";
            $params[':type'] = $type;
        }
        if ($availOnly) {
            $where[]  = "(SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = b.book_id AND bc.status = 'Available') > 0";
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count
        $countStmt = $db->prepare("SELECT COUNT(*) FROM books b LEFT JOIN categories c ON b.category_id = c.category_id $whereSQL");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Data
        $stmt = $db->prepare("
            SELECT b.*, c.category_name, c.category_type,
                   COUNT(bc.copy_id) AS total_copies,
                   COALESCE(SUM(CASE WHEN bc.status = 'Available' THEN 1 ELSE 0 END), 0) AS available_copies
            FROM books b
            LEFT JOIN categories c ON b.category_id = c.category_id
            LEFT JOIN book_copies bc ON bc.book_id = b.book_id
            $whereSQL
            GROUP BY b.book_id, c.category_name, c.category_type
            ORDER BY b.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $books = $stmt->fetchAll();

        jsonResponse(['data' => $books, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        break;

    case 'POST':
        $input = getInput();
        $required = ['isbn', 'title', 'author'];
        foreach ($required as $f) {
            if (empty(trim($input[$f] ?? ''))) {
                jsonResponse(['error' => "Field '$f' is required"], 422);
            }
        }

        $isbn   = trim($input['isbn']);
        $title  = trim($input['title']);
        $author = trim($input['author']);
        $catId  = !empty($input['category_id']) ? (int)$input['category_id'] : null;
        $pub    = !empty(trim($input['publisher'] ?? '')) ? trim($input['publisher']) : null;
        $year   = !empty($input['publication_year']) ? (int)$input['publication_year'] : null;
        $desc   = !empty(trim($input['description'] ?? '')) ? trim($input['description']) : null;

        // Check duplicate ISBN
        $dup = $db->prepare("SELECT book_id, title FROM books WHERE isbn = :isbn LIMIT 1");
        $dup->execute([':isbn' => $isbn]);
        if ($existing = $dup->fetch()) {
            jsonResponse(['error' => "A book with ISBN '{$isbn}' already exists: {$existing['title']}"], 409);
        }

        $stmt = $db->prepare("
            INSERT INTO books (category_id, isbn, title, author, publisher, publication_year, description)
            VALUES (:cat, :isbn, :title, :author, :pub, :year, :desc)
        ");
        $stmt->execute([
            ':cat'    => $catId,
            ':isbn'   => $isbn,
            ':title'  => $title,
            ':author' => $author,
            ':pub'    => $pub,
            ':year'   => $year,
            ':desc'   => $desc,
        ]);
        jsonResponse(['success' => true, 'book_id' => (int)$db->lastInsertId()], 201);
        break;

    case 'PUT':
        $input = getInput();
        $id    = (int)($_GET['id'] ?? $input['book_id'] ?? 0);
        if ($id < 1) jsonResponse(['error' => 'Valid Book ID required'], 422);

        $isbn   = trim($input['isbn'] ?? '');
        $title  = trim($input['title'] ?? '');
        $author = trim($input['author'] ?? '');
        $catId  = !empty($input['category_id']) ? (int)$input['category_id'] : null;
        $pub    = !empty(trim($input['publisher'] ?? '')) ? trim($input['publisher']) : null;
        $year   = !empty($input['publication_year']) ? (int)$input['publication_year'] : null;
        $desc   = !empty(trim($input['description'] ?? '')) ? trim($input['description']) : null;

        if ($isbn === '' || $title === '' || $author === '') {
            jsonResponse(['error' => 'ISBN, Title, and Author are required'], 422);
        }

        // Check duplicate ISBN on other books
        $dup = $db->prepare("SELECT book_id FROM books WHERE isbn = :isbn AND book_id <> :id LIMIT 1");
        $dup->execute([':isbn' => $isbn, ':id' => $id]);
        if ($dup->fetch()) {
            jsonResponse(['error' => "Another book is already using ISBN '{$isbn}'"], 409);
        }

        $stmt = $db->prepare("
            UPDATE books
            SET category_id = :cat, isbn = :isbn, title = :title, author = :author,
                publisher = :pub, publication_year = :year, description = :desc
            WHERE book_id = :id
        ");
        $stmt->execute([
            ':cat'    => $catId,
            ':isbn'   => $isbn,
            ':title'  => $title,
            ':author' => $author,
            ':pub'    => $pub,
            ':year'   => $year,
            ':desc'   => $desc,
            ':id'     => $id,
        ]);
        jsonResponse(['success' => true]);
        break;

    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) jsonResponse(['error' => 'Valid Book ID required'], 422);

        // Check if any copies are currently borrowed or overdue
        $checkBorrowed = $db->prepare("
            SELECT COUNT(*) FROM book_copies bc
            JOIN borrowing_records br ON br.copy_id = bc.copy_id
            WHERE bc.book_id = :id AND br.status IN ('Borrowed', 'Overdue')
        ");
        $checkBorrowed->execute([':id' => $id]);
        if ((int)$checkBorrowed->fetchColumn() > 0) {
            jsonResponse(['error' => 'Cannot delete this book because one or more copies are currently borrowed or overdue.'], 409);
        }

        // Clean deletion in transaction
        $db->beginTransaction();
        try {
            // Delete reservations
            $db->prepare("DELETE FROM reservations WHERE book_id = :id")->execute([':id' => $id]);
            // Delete copies borrowing history or free copies
            $db->prepare("DELETE FROM borrowing_records WHERE copy_id IN (SELECT copy_id FROM book_copies WHERE book_id = :id)")->execute([':id' => $id]);
            // Delete book copies
            $db->prepare("DELETE FROM book_copies WHERE book_id = :id")->execute([':id' => $id]);
            // Delete book
            $db->prepare("DELETE FROM books WHERE book_id = :id")->execute([':id' => $id]);
            $db->commit();
            jsonResponse(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => 'Failed to delete book: ' . $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
