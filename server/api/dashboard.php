<?php
error_reporting(0);
ob_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json');

$account = requireApiAccount();
$db = getDB();

$userId   = $account['user_id'];
$userRole = $account['role'];

if (in_array($userRole, ['Admin', 'Librarian'], true)) {
    $db->query("UPDATE borrowing_records SET status='Overdue' WHERE status='Borrowed' AND due_date < CURRENT_DATE");
}

if ($userRole === 'User') {
    // ═════════════════════════════════════════════════════════════════════════
    // STUDENT DASHBOARD DATA
    // ═════════════════════════════════════════════════════════════════════════
    $borrowedCount = 0;
    $overdueCount  = 0;
    $reserveCount  = 0;
    $returnedCount = 0;
    $myBorrowings  = [];
    $myReservations = [];

    if ($userId) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM borrowing_records WHERE user_id = :uid AND status = 'Borrowed'");
        $stmt->execute([':uid' => $userId]);
        $borrowedCount = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM borrowing_records WHERE user_id = :uid AND status = 'Overdue'");
        $stmt->execute([':uid' => $userId]);
        $overdueCount = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = :uid AND status IN ('Pending', 'Approved')");
        $stmt->execute([':uid' => $userId]);
        $reserveCount = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM borrowing_records WHERE user_id = :uid AND status = 'Returned'");
        $stmt->execute([':uid' => $userId]);
        $returnedCount = (int)$stmt->fetchColumn();

        // Calculate due soon in PHP for cross-database compatibility (MySQL & PostgreSQL)
        $dueStmt = $db->prepare("SELECT due_date FROM borrowing_records WHERE user_id = :uid AND status = 'Borrowed'");
        $dueStmt->execute([':uid' => $userId]);
        $dueRows = $dueStmt->fetchAll();
        $dueSoonCount = 0;
        $today = new DateTime('today');
        foreach ($dueRows as $dr) {
            $diff = (int)$today->diff(new DateTime($dr['due_date']))->format('%r%a');
            if ($diff >= 0 && $diff <= 2) {
                $dueSoonCount++;
            }
        }

        // My current borrowings
        $stmt = $db->prepare("
            SELECT br.borrow_id, br.borrow_date, br.due_date, br.return_date, br.status,
                   b.title AS book_title, b.author, bc.barcode
            FROM borrowing_records br
            JOIN book_copies bc ON br.copy_id = bc.copy_id
            JOIN books b ON bc.book_id = b.book_id
            WHERE br.user_id = :uid
            ORDER BY (br.status = 'Borrowed' OR br.status = 'Overdue') DESC, br.borrow_date DESC
            LIMIT 5
        ");
        $stmt->execute([':uid' => $userId]);
        $myBorrowings = $stmt->fetchAll();
        foreach ($myBorrowings as &$mb) {
            $mb['days_left'] = (int)$today->diff(new DateTime($mb['due_date']))->format('%r%a');
        }
        unset($mb);

        // My current reservations
        $stmt = $db->prepare("
            SELECT r.reservation_id, r.reservation_date, r.status, r.copy_id,
                   bc.barcode AS reserved_barcode,
                   b.book_id, b.title AS book_title, b.author, c.category_name
            FROM reservations r
            JOIN books b ON r.book_id = b.book_id
            LEFT JOIN categories c ON b.category_id = c.category_id
            LEFT JOIN book_copies bc ON r.copy_id = bc.copy_id
            WHERE r.user_id = :uid
            ORDER BY r.reservation_date DESC
            LIMIT 5
        ");
        $stmt->execute([':uid' => $userId]);
        $myReservations = $stmt->fetchAll();
    }

    // Featured books that students can browse and reserve
    $featuredBooks = $db->query("
        SELECT b.book_id, b.title, b.author, b.isbn, c.category_name,
               (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = b.book_id AND bc.status = 'Available') AS available_copies,
               (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = b.book_id) AS total_copies
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.category_id
        ORDER BY b.book_id DESC
        LIMIT 6
    ")->fetchAll();

    jsonResponse([
        'role' => 'Student',
        'stats' => [
            'my_borrowed'     => $borrowedCount,
            'my_overdue'      => $overdueCount,
            'my_due_soon'     => $dueSoonCount,
            'my_reservations' => $reserveCount,
            'my_returned'     => $returnedCount,
            'borrow_quota'    => 3,
            'total_catalog'   => (int)$db->query("SELECT COUNT(*) FROM books")->fetchColumn(),
        ],
        'my_borrowings'   => $myBorrowings,
        'my_reservations' => $myReservations,
        'featured_books'  => $featuredBooks,
    ]);

} elseif ($userRole === 'Librarian') {
    // ═════════════════════════════════════════════════════════════════════════
    // LIBRARIAN DASHBOARD DATA (Matches Section 2 & 4 of GUI Design)
    // ═════════════════════════════════════════════════════════════════════════
    $totalBooks          = (int)$db->query("SELECT COUNT(*) FROM books")->fetchColumn();
    $availableCopies     = (int)$db->query("SELECT COUNT(*) FROM book_copies WHERE status = 'Available'")->fetchColumn();
    $totalStudents       = (int)$db->query("SELECT COUNT(*) FROM students")->fetchColumn();
    $activeBorrowings    = (int)$db->query("SELECT COUNT(*) FROM borrowing_records WHERE status = 'Borrowed'")->fetchColumn();
    $pendingReservations = (int)$db->query("SELECT COUNT(*) FROM reservations WHERE status = 'Pending'")->fetchColumn();
    $overdue             = (int)$db->query("SELECT COUNT(*) FROM borrowing_records WHERE status = 'Overdue'")->fetchColumn();

    $recentActivity = $db->query("
        SELECT br.borrow_id, s.name AS student_name, u.full_name, b.title AS book_title,
               bc.barcode, br.borrow_date, br.due_date, br.return_date, br.status
        FROM borrowing_records br
        JOIN users u ON br.user_id = u.user_id
        LEFT JOIN students s ON u.student_id = s.id
        JOIN book_copies bc ON br.copy_id = bc.copy_id
        JOIN books b ON bc.book_id = b.book_id
        ORDER BY br.created_at DESC
        LIMIT 6
    ")->fetchAll();

    $pendingReservationsList = $db->query("
        SELECT r.reservation_id, s.name AS student_name, u.full_name,
               b.title AS book_title, r.reservation_date, r.status
        FROM reservations r
        JOIN users u ON r.user_id = u.user_id
        LEFT JOIN students s ON u.student_id = s.id
        JOIN books b ON r.book_id = b.book_id
        WHERE r.status = 'Pending'
        ORDER BY r.reservation_date DESC
        LIMIT 5
    ")->fetchAll();

    jsonResponse([
        'role' => 'Librarian',
        'stats' => [
            'total_books'          => $totalBooks,
            'available_copies'     => $availableCopies,
            'total_students'       => $totalStudents,
            'active_borrowings'    => $activeBorrowings,
            'pending_reservations' => $pendingReservations,
            'overdue'              => $overdue,
        ],
        'recent_activity'           => $recentActivity,
        'pending_reservations_list' => $pendingReservationsList,
    ]);

} else {
    // ═════════════════════════════════════════════════════════════════════════
    // ADMINISTRATOR DASHBOARD DATA (Matches Section 5 of GUI Design)
    // ═════════════════════════════════════════════════════════════════════════
    $totalUsers          = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalStudents       = (int)$db->query("SELECT COUNT(*) FROM students")->fetchColumn();
    $totalBooks          = (int)$db->query("SELECT COUNT(*) FROM books")->fetchColumn();
    $totalCopies         = (int)$db->query("SELECT COUNT(*) FROM book_copies")->fetchColumn();
    $activeBorrowings    = (int)$db->query("SELECT COUNT(*) FROM borrowing_records WHERE status = 'Borrowed'")->fetchColumn();
    $totalCourses        = (int)$db->query("SELECT COUNT(*) FROM courses")->fetchColumn();
    $totalCategories     = (int)$db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $overdue             = (int)$db->query("SELECT COUNT(*) FROM borrowing_records WHERE status = 'Overdue'")->fetchColumn();

    $userRoles = $db->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);

    $recentUsers = $db->query("
        SELECT u.user_id, u.username, u.full_name, u.email, u.role, u.is_active, u.created_at,
               s.name AS student_name
        FROM users u
        LEFT JOIN students s ON u.student_id = s.id
        WHERE u.role = 'Librarian'
        ORDER BY u.created_at DESC
        LIMIT 5
    ")->fetchAll();

    $recentStudents = $db->query("
        SELECT s.id, s.name, s.gender, s.year_level, c.course_code
        FROM students s
        LEFT JOIN courses c ON s.course_id = c.id
        ORDER BY s.id DESC
        LIMIT 5
    ")->fetchAll();

    $coursesList = $db->query("SELECT id, course_code, course_name FROM courses LIMIT 4")->fetchAll();
    $categoriesList = $db->query("SELECT category_id, category_name FROM categories LIMIT 4")->fetchAll();

    $recentActivity = $db->query("
        SELECT br.borrow_id, s.name AS student_name, u.full_name, b.title AS book_title,
               bc.barcode, br.borrow_date, br.due_date, br.return_date, br.status
        FROM borrowing_records br
        JOIN users u ON br.user_id = u.user_id
        LEFT JOIN students s ON u.student_id = s.id
        JOIN book_copies bc ON br.copy_id = bc.copy_id
        JOIN books b ON bc.book_id = b.book_id
        ORDER BY br.created_at DESC
        LIMIT 5
    ")->fetchAll();

    jsonResponse([
        'role' => 'Admin',
        'stats' => [
            'total_users'       => $totalUsers,
            'total_students'    => $totalStudents,
            'total_books'       => $totalBooks,
            'total_copies'      => $totalCopies,
            'active_borrowings' => $activeBorrowings,
            'total_courses'     => $totalCourses,
            'total_categories'  => $totalCategories,
            'overdue'           => $overdue,
            'roles_breakdown'   => $userRoles,
        ],
        'recent_users'    => $recentUsers,
        'recent_students' => $recentStudents,
        'courses'         => $coursesList,
        'categories'      => $categoriesList,
        'recent_activity' => $recentActivity,
    ]);
}
