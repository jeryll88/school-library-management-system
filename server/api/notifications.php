<?php
error_reporting(0);
ob_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$account = requireApiAccount();
$db      = getDB();
$userId  = (int)$account['user_id'];
$method  = $_SERVER['REQUEST_METHOD'];

// Ensure notifications table and overdue check have run
ensureNotificationsTable($db);
checkAndCreateOverdueNotifications($db);

switch ($method) {
    case 'GET':
        // Retrieve notifications for current user
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $stmt = $db->prepare("
            SELECT id, user_id, type, title, message, link, is_read, created_at
            FROM notifications
            WHERE user_id = :uid
            ORDER BY created_at DESC, id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Count unread
        $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
        $countStmt->execute([':uid' => $userId]);
        $unreadCount = (int)$countStmt->fetchColumn();

        jsonResponse([
            'data'         => $notifications,
            'unread_count' => $unreadCount,
        ]);
        break;

    case 'PUT':
    case 'POST':
        $input = getInput();
        $action = $input['action'] ?? ($_GET['action'] ?? '');
        $notifId = (int)($input['id'] ?? ($_GET['id'] ?? 0));

        if ($action === 'mark_all_read') {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid");
            $stmt->execute([':uid' => $userId]);
            jsonResponse(['success' => true, 'message' => 'All notifications marked as read']);
        } elseif ($notifId > 0) {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $notifId, ':uid' => $userId]);
            jsonResponse(['success' => true, 'message' => 'Notification marked as read']);
        } else {
            jsonResponse(['error' => 'Notification ID or action required'], 422);
        }
        break;

    case 'DELETE':
        $notifId = (int)($_GET['id'] ?? 0);
        $action  = $_GET['action'] ?? '';

        if ($action === 'clear_all') {
            $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = :uid");
            $stmt->execute([':uid' => $userId]);
            jsonResponse(['success' => true, 'message' => 'All notifications cleared']);
        } elseif ($notifId > 0) {
            $stmt = $db->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $notifId, ':uid' => $userId]);
            jsonResponse(['success' => true, 'message' => 'Notification deleted']);
        } else {
            jsonResponse(['error' => 'Notification ID required'], 422);
        }
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
