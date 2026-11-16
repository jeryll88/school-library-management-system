<?php
error_reporting(0);
ob_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');

$account = requireApiAccount(['Admin', 'Librarian']);
$db = getDB();
$courses = $db->query("SELECT * FROM courses ORDER BY course_code ASC")->fetchAll();
jsonResponse(['data' => $courses]);
