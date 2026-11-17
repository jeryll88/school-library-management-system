<?php
error_reporting(0);
ob_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $account = requireApiAccount();
    $db = getDB();

    $userId   = $account['user_id'];
    $userRole = $account['role'];

    // Ensure tables, due date column and overdue status check are maintained
    if (function_exists('ensureNotificationsTable')) {
        ensureNotificationsTable($db);
    }
    if (function_exists('ensureReservationDueDateColumn')) {
        ensureReservationDueDateColumn($db);
    }
    if (function_exists('checkAndCreateOverdueNotifications')) {
        checkAndCreateOverdueNotifications($db);
    }

    if ($userRole === 'User') {
        // ═════════════════════════════════════════════════════════════════════
        // STUDENT DASHBOARD DATA (Optimized: Combined Queries)
        // ═════════════════════════════════════════════════════════════════════
        $borrowedCount = 0;
        $overdueCount  = 0;
        $reserveCount  = 0;
        $returnedCount = 0;
        $dueSoonCount  = 0;
        $myBorrowings  = [];
        $myReservations = [];

        if ($userId) {
            // Combined borrowing counts into a single fast query
            $stmt = $db->prepare("
                SELECT 
                    COUNT(CASE WHEN status = 'Borrowed' THEN 1 END) AS borrowed_count,
                    COUNT(CASE WHEN status = 'Overdue' THEN 1 END) AS overdue_count,
                    COUNT(CASE WHEN status = 'Returned' THEN 1 END) AS returned_count
                FROM borrowing_records WHERE user_id = :uid
            ");
            $stmt->execute([':uid' => $userId]);
            $bCounts = $stmt->fetch() ?: [];
            $borrowedCount = (int)($bCounts['borrowed_count'] ?? 0);
            $overdueCount  = (int)($bCounts['overdue_count'] ?? 0);
            $returnedCount = (int)($bCounts['returned_count'] ?? 0);

            $stmt = $db->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = :uid AND status IN ('Pending', 'Approved')");
            $stmt->execute([':uid' => $userId]);
            $reserveCount = (int)$stmt->fetchColumn();

            // My current borrowings
            $today = new DateTime('today');
            $stmt = $db->prepare("
                SELECT br.borrow_id, br.borrow_date, br.due_date, br.return_date, br.status,
                       b.title AS book_title, b.author, bc.barcode
                FROM borrowing_records br
                JOIN book_copies bc ON br.copy_id = bc.copy_id
                JOIN books b ON bc.book_id = b.book_id
                WHERE br.user_id = :uid
                ORDER BY (CASE WHEN br.status = 'Borrowed' OR br.status = 'Overdue' THEN 1 ELSE 0 END) DESC, br.borrow_id DESC
                LIMIT 5
            ");
            $stmt->execute([':uid' => $userId]);
            $myBorrowings = $stmt->fetchAll();
            $totalFine = 0;
            $fineRate = 10.00; // ₱10.00 per day overdue
            foreach ($myBorrowings as &$mb) {
                if (!empty($mb['due_date'])) {
                    $diff = (int)$today->diff(new DateTime($mb['due_date']))->format('%r%a');
                    $mb['days_left'] = $diff;
                    if ($mb['status'] === 'Borrowed' && $diff >= 0 && $diff <= 2) {
                        $dueSoonCount++;
                    }
                    if ($diff < 0 && ($mb['status'] === 'Borrowed' || $mb['status'] === 'Overdue')) {
                        $overdueDays = abs($diff);
                        $mb['overdue_days'] = $overdueDays;
                        $mb['fine_amount'] = $overdueDays * $fineRate;
                        $mb['formatted_fine'] = '₱' . number_format($mb['fine_amount'], 2);
                        $totalFine += $mb['fine_amount'];
                    } else {
                        $mb['overdue_days'] = 0;
                        $mb['fine_amount'] = 0;
                        $mb['formatted_fine'] = '₱0.00';
                    }
                } else {
                    $mb['days_left'] = 0;
                    $mb['overdue_days'] = 0;
                    $mb['fine_amount'] = 0;
                    $mb['formatted_fine'] = '₱0.00';
                }
            }
            unset($mb);

            // My current reservations (safe with copy_id fallback)
            try {
                $stmt = $db->prepare("
                    SELECT r.reservation_id, r.reservation_date, r.due_date, r.status, r.copy_id,
                           bc.barcode AS reserved_barcode,
                           b.book_id, b.title AS book_title, b.author, c.category_name,
                           s.name AS student_name, s.student_number, u.full_name
                    FROM reservations r
                    JOIN users u ON r.user_id = u.user_id
                    LEFT JOIN students s ON u.student_id = s.id
                    JOIN books b ON r.book_id = b.book_id
                    LEFT JOIN categories c ON b.category_id = c.category_id
                    LEFT JOIN book_copies bc ON r.copy_id = bc.copy_id
                    WHERE r.user_id = :uid
                    ORDER BY r.reservation_id DESC
                    LIMIT 5
                ");
                $stmt->execute([':uid' => $userId]);
                $myReservations = $stmt->fetchAll();
            } catch (Throwable $e) {
                $stmt = $db->prepare("
                    SELECT r.reservation_id, r.reservation_date, NULL AS due_date, r.status, NULL AS copy_id,
                           NULL AS reserved_barcode,
                           b.book_id, b.title AS book_title, b.author, c.category_name,
                           u.full_name AS student_name, NULL AS student_number, u.full_name
                    FROM reservations r
                    JOIN users u ON r.user_id = u.user_id
                    JOIN books b ON r.book_id = b.book_id
                    LEFT JOIN categories c ON b.category_id = c.category_id
                    WHERE r.user_id = :uid
                    ORDER BY r.reservation_id DESC
                    LIMIT 5
                ");
                $stmt->execute([':uid' => $userId]);
                $myReservations = $stmt->fetchAll();
            }
        }

        // Fast featured books: limits to latest 6 books first before joining copies
        $featStmt = $db->prepare("
            SELECT b.book_id, b.title, b.author, b.isbn, c.category_name,
                   COUNT(bc.copy_id) AS total_copies,
                   COUNT(CASE WHEN bc.status = 'Available' THEN 1 END) AS available_copies,
                   (SELECT r.status FROM reservations r WHERE r.book_id = b.book_id AND r.user_id = :uid AND r.status IN ('Pending', 'Approved') LIMIT 1) AS my_reservation_status
            FROM (SELECT book_id, category_id, isbn, title, author FROM books ORDER BY book_id DESC LIMIT 6) b
            LEFT JOIN categories c ON b.category_id = c.category_id
            LEFT JOIN book_copies bc ON bc.book_id = b.book_id
            GROUP BY b.book_id, b.title, b.author, b.isbn, c.category_name
            ORDER BY b.book_id DESC
        ");
        $featStmt->execute([':uid' => $userId]);
        $featuredBooks = $featStmt->fetchAll();

        jsonResponse([
            'role' => 'Student',
            'stats' => [
                'my_borrowed'          => $borrowedCount,
                'my_overdue'           => $overdueCount,
                'my_due_soon'          => $dueSoonCount,
                'my_reservations'      => $reserveCount,
                'my_returned'          => $returnedCount,
                'borrow_quota'         => 5,
                'available_quota'      => max(0, 5 - ($borrowedCount + $overdueCount)),
                'total_fine'           => $totalFine,
                'formatted_total_fine' => '₱' . number_format($totalFine, 2),
                'fine_rate'            => 10.00,
                'total_catalog'        => count($featuredBooks),
            ],
            'my_borrowings'   => $myBorrowings,
            'my_reservations' => $myReservations,
            'featured_books'  => $featuredBooks,
        ]);

    } elseif ($userRole === 'Librarian') {
        // ═════════════════════════════════════════════════════════════════════
        // LIBRARIAN DASHBOARD DATA (Optimized: Combined Stats Query)
        // ═════════════════════════════════════════════════════════════════════
        $statsRow = $db->query("
            SELECT 
                (SELECT COUNT(*) FROM books) AS total_books,
                (SELECT COUNT(*) FROM book_copies WHERE status = 'Available') AS available_copies,
                (SELECT COUNT(*) FROM students) AS total_students,
                (SELECT COUNT(*) FROM reservations WHERE status = 'Pending') AS pending_reservations,
                (SELECT COUNT(*) FROM borrowing_records WHERE status = 'Borrowed') AS active_borrowings,
                (SELECT COUNT(*) FROM borrowing_records WHERE status = 'Overdue') AS overdue
        ")->fetch() ?: [];

        $recentActivity = $db->query("
            SELECT br.borrow_id, s.name AS student_name, u.full_name, b.title AS book_title,
                   bc.barcode, br.borrow_date, br.due_date, br.return_date, br.status
            FROM borrowing_records br
            JOIN users u ON br.user_id = u.user_id
            LEFT JOIN students s ON u.student_id = s.id
            JOIN book_copies bc ON br.copy_id = bc.copy_id
            JOIN books b ON bc.book_id = b.book_id
            ORDER BY br.borrow_id DESC
            LIMIT 6
        ")->fetchAll();

        $today = new DateTime('today');
        foreach ($recentActivity as &$ra) {
            $dueDate = $ra['due_date'] ?? null;
            $returnDate = $ra['return_date'] ?? null;
            $overdueDays = 0;
            if ($dueDate) {
                $dueObj = new DateTime($dueDate);
                if ($ra['status'] === 'Returned' && $returnDate) {
                    $retObj = new DateTime($returnDate);
                    if ($retObj > $dueObj) {
                        $overdueDays = max(0, (int)$dueObj->diff($retObj)->format('%a'));
                    }
                } elseif ($today > $dueObj && in_array($ra['status'], ['Borrowed', 'Overdue'])) {
                    $overdueDays = max(0, (int)$dueObj->diff($today)->format('%a'));
                }
            }
            $ra['overdue_days'] = $overdueDays;
            $ra['fine_rate']    = 10;
            $ra['fine_amount']  = $overdueDays * 10;
        }
        unset($ra);

        $pendingReservationsList = $db->query("
            SELECT r.reservation_id, s.name AS student_name, s.student_number, u.full_name,
                   b.title AS book_title, r.reservation_date, r.status, r.copy_id
            FROM reservations r
            JOIN users u ON r.user_id = u.user_id
            LEFT JOIN students s ON u.student_id = s.id
            JOIN books b ON r.book_id = b.book_id
            WHERE r.status = 'Pending'
            ORDER BY r.reservation_id DESC
            LIMIT 5
        ")->fetchAll();

        jsonResponse([
            'role' => 'Librarian',
            'stats' => [
                'total_books'          => (int)($statsRow['total_books'] ?? 0),
                'available_copies'     => (int)($statsRow['available_copies'] ?? 0),
                'total_students'       => (int)($statsRow['total_students'] ?? 0),
                'active_borrowings'    => (int)($statsRow['active_borrowings'] ?? 0),
                'pending_reservations' => (int)($statsRow['pending_reservations'] ?? 0),
                'overdue'              => (int)($statsRow['overdue'] ?? 0),
            ],
            'recent_activity'           => $recentActivity,
            'pending_reservations_list' => $pendingReservationsList,
        ]);

    } else {
        // ═════════════════════════════════════════════════════════════════════
        // ADMINISTRATOR DASHBOARD DATA (Optimized: Combined Stats Query)
        // ═════════════════════════════════════════════════════════════════════
        $statsRow = $db->query("
            SELECT 
                (SELECT COUNT(*) FROM users) AS total_users,
                (SELECT COUNT(*) FROM students) AS total_students,
                (SELECT COUNT(*) FROM books) AS total_books,
                (SELECT COUNT(*) FROM book_copies) AS total_copies,
                (SELECT COUNT(*) FROM borrowing_records WHERE status = 'Borrowed') AS active_borrowings,
                (SELECT COUNT(*) FROM borrowing_records WHERE status = 'Overdue') AS overdue,
                (SELECT COUNT(*) FROM courses) AS total_courses,
                (SELECT COUNT(*) FROM categories) AS total_categories
        ")->fetch() ?: [];

        $userRoles = $db->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);

        $recentUsers = $db->query("
            SELECT u.user_id, u.username, u.full_name, u.email, u.role, u.is_active,
                   s.name AS student_name
            FROM users u
            LEFT JOIN students s ON u.student_id = s.id
            WHERE u.role = 'Librarian'
            ORDER BY u.user_id DESC
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
            ORDER BY br.borrow_id DESC
            LIMIT 5
        ")->fetchAll();

        jsonResponse([
            'role' => 'Admin',
            'stats' => [
                'total_users'       => (int)($statsRow['total_users'] ?? 0),
                'total_students'    => (int)($statsRow['total_students'] ?? 0),
                'total_books'       => (int)($statsRow['total_books'] ?? 0),
                'total_copies'      => (int)($statsRow['total_copies'] ?? 0),
                'active_borrowings' => (int)($statsRow['active_borrowings'] ?? 0),
                'total_courses'     => (int)($statsRow['total_courses'] ?? 0),
                'total_categories'  => (int)($statsRow['total_categories'] ?? 0),
                'overdue'           => (int)($statsRow['overdue'] ?? 0),
                'roles_breakdown'   => $userRoles,
            ],
            'recent_users'    => $recentUsers,
            'recent_students' => $recentStudents,
            'courses'         => $coursesList,
            'categories'      => $categoriesList,
            'recent_activity' => $recentActivity,
        ]);
    }
} catch (Throwable $e) {
    error_log('Dashboard error: ' . $e->getMessage());
    jsonResponse([
        'error' => 'Dashboard error: ' . $e->getMessage(),
        'role' => $userRole ?? 'User',
        'stats' => [],
        'my_borrowings' => [],
        'my_reservations' => [],
        'featured_books' => [],
    ], 500);
}
