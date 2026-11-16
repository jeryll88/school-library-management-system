<?php
require_once __DIR__ . '/../server/config/db.php';
$db = getDB();
$stmt = $db->prepare('SELECT user_id, username FROM users WHERE role = ?');
$stmt->execute(['User']);
$rows = $stmt->fetchAll();
foreach($rows as $row){ echo $row['user_id'].":".$row['username']."\n"; }
?>
