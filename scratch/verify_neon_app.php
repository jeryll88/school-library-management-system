<?php
putenv("DATABASE_URL=postgresql://neondb_owner:npg_QFmBRUa5sJ3E@ep-young-fire-ao3f6pj2-pooler.c-2.ap-southeast-1.aws.neon.tech/neondb?sslmode=require&channel_binding=require");

require_once __DIR__ . '/../server/config/db.php';

echo "Testing getDB() with Neon DATABASE_URL...\n";
$db = getDB();

$courses = $db->query("SELECT COUNT(*) FROM courses")->fetchColumn();
echo "Courses count from Neon: $courses\n";

$categories = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
echo "Categories count from Neon: $categories\n";

$books = $db->query("SELECT COUNT(*) FROM books")->fetchColumn();
echo "Books count from Neon: $books\n";

$admin = $db->query("SELECT username, role FROM users WHERE username = 'admin'")->fetch();
echo "Admin in Neon: " . json_encode($admin) . "\n";

echo "ALL NEON QUERIES SUCCEEDED!\n";
