<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$db     = getDB();
$account = requireApiAccount();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && !in_array($account['role'], ['Admin', 'Librarian'], true)) {
    jsonResponse(['error' => 'Only library staff can modify categories'], 403);
}

switch ($method) {
    case 'GET':
        $cats = $db->query("
            SELECT c.*, COUNT(b.book_id) AS book_count
            FROM categories c
            LEFT JOIN books b ON b.category_id = c.category_id
            GROUP BY c.category_id
            ORDER BY c.category_name ASC
        ")->fetchAll();
        jsonResponse(['data' => $cats]);
        break;

    case 'POST':
        $input = getInput();
        if (empty($input['category_name'])) jsonResponse(['error' => 'Category name is required'], 422);
        $type = in_array($input['category_type'] ?? '', ['Academic', 'Non-Academic'], true) ? $input['category_type'] : 'Academic';
        $stmt = $db->prepare("INSERT INTO categories (category_name, category_type, description) VALUES (:name, :type, :desc)");
        $stmt->execute([':name' => $input['category_name'], ':type' => $type, ':desc' => $input['description'] ?? null]);
        jsonResponse(['success' => true, 'category_id' => $db->lastInsertId()], 201);
        break;

    case 'PUT':
        $input = getInput();
        $id    = $_GET['id'] ?? $input['category_id'] ?? null;
        if (!$id) jsonResponse(['error' => 'Category ID required'], 422);
        $type = in_array($input['category_type'] ?? '', ['Academic', 'Non-Academic'], true) ? $input['category_type'] : 'Academic';
        $stmt = $db->prepare("UPDATE categories SET category_name=:name, category_type=:type, description=:desc WHERE category_id=:id");
        $stmt->execute([':name' => $input['category_name'], ':type' => $type, ':desc' => $input['description'] ?? null, ':id' => $id]);
        jsonResponse(['success' => true]);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) jsonResponse(['error' => 'Category ID required'], 422);
        $db->prepare("DELETE FROM categories WHERE category_id=:id")->execute([':id' => $id]);
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
