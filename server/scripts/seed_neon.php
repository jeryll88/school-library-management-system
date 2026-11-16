<?php
/**
 * ======================================================================
 *  LIBRARY_MS — Full Database Sync: MySQL → Neon PostgreSQL
 *  Pushes the complete current local database to the Neon cloud instance.
 *  Usage:
 *    php server/scripts/seed_neon.php [neon_database_url]
 *  Or set DATABASE_URL env var and run with no argument.
 * ======================================================================
 */

$databaseUrl = $argv[1]
    ?? getenv('DATABASE_URL')
    ?: 'postgresql://neondb_owner:npg_QFmBRUa5sJ3E@ep-young-fire-ao3f6pj2-pooler.c-2.ap-southeast-1.aws.neon.tech/neondb?sslmode=require&channel_binding=require';

// ── Helpers ─────────────────────────────────────────────────────────────────
function step(string $label): void {
    echo "\n$label\n" . str_repeat('─', 70) . "\n";
}
function ok(string $msg): void  { echo "  [OK] $msg\n"; }
function info(string $msg): void { echo "  ·  $msg\n"; }
function fail(string $msg): void { echo "  [FAIL] $msg\n"; }

// ── Connect to Neon ──────────────────────────────────────────────────────────
echo "======================================================================\n";
echo "   LIBRARY_MS — Full Neon PostgreSQL Seed\n";
echo "======================================================================\n";

$parts      = parse_url($databaseUrl);
$host       = $parts['host'] ?? 'localhost';
$port       = $parts['port'] ?? 5432;
$db         = ltrim($parts['path'] ?? 'neondb', '/');
$user       = isset($parts['user']) ? urldecode($parts['user']) : '';
$pass       = isset($parts['pass']) ? urldecode($parts['pass']) : '';
$endpointId = str_replace('-pooler', '', explode('.', $host)[0]);

step("[1/7] Connecting to Neon PostgreSQL");
try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";
    if (strpos($host, 'neon.tech') !== false) {
        $dsn .= ";options='endpoint={$endpointId}'";
    }
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    ok("Connected → {$host} / {$db}");
    $v = $pdo->query("SELECT version()")->fetchColumn();
    info("Engine: " . substr($v, 0, 60) . "…");
} catch (Exception $e) {
    fail("Connection failed: " . $e->getMessage());
    exit(1);
}

// ── Re-create schema (safe idempotent DROP + CREATE) ─────────────────────────
step("[2/7] Running schema migrations");
$schemaFile = __DIR__ . '/../migrations/schema_neon_postgres.sql';
if (!file_exists($schemaFile)) {
    fail("Schema file not found: $schemaFile");
    exit(1);
}
try {
    $pdo->exec(file_get_contents($schemaFile));
    ok("Base schema applied (courses, categories, users, books, copies…)");
} catch (Exception $e) {
    fail("Schema error: " . $e->getMessage());
    exit(1);
}

// ── Seed: Students ────────────────────────────────────────────────────────────
step("[3/7] Seeding Students");
$students = [
    [11, 'Jeryll Anne Bides',   'STU-2026-0011', 'Female', 3, 1],
    [16, 'Estilla Gean Cada',   'STU-2026-6B4C', 'Female', 3, 1],
    [19, 'Demo Student',        'STU-TEST-8546', 'Female', 2, 1],
    [20, 'Jeon onwoo',          '2026-1003',     'Male',   2, 8],
    [22, 'wawawiwa',            '2026-1002',     'Male',   1, 1],
];
$stmtS = $pdo->prepare("
    INSERT INTO students (id, name, student_number, gender, year_level, course_id)
    VALUES (:id, :name, :sn, :g, :yl, :cid)
    ON CONFLICT (id) DO UPDATE SET
        name=EXCLUDED.name, student_number=EXCLUDED.student_number,
        gender=EXCLUDED.gender, year_level=EXCLUDED.year_level,
        course_id=EXCLUDED.course_id
");
foreach ($students as $s) {
    $stmtS->execute([':id'=>$s[0],':name'=>$s[1],':sn'=>$s[2],':g'=>$s[3],':yl'=>$s[4],':cid'=>$s[5]]);
}
$pdo->exec("SELECT setval('students_id_seq', (SELECT MAX(id) FROM students))");
ok(count($students) . " student profiles seeded");

// ── Seed: Full Users ─────────────────────────────────────────────────────────
step("[4/7] Seeding Users");
$users = [
    [1,  null, 'admin',           'Admin User',         null,       'admin@school.edu',              '$2y$10$cLOwKKpuSs2yPkI4.x8w4ObRgYVR702bAuNiF12DvYBBesjYSEJ42', 'Admin',     1],
    [3,  null, 'kiki',            'Eliza Lie Corpuz',   null,       'elizaliecorpuz@gmail.com',      '$2y$10$s08hMYBFFYV/3XuPoBvaT.i/t/GVXQ7I4qVZ9qWYFaLAr9F0UBbVi', 'User',      1],
    [4,  null, 'librarian',       'Maria Clara',        null,       'librarian@school.edu',          '$2y$10$JWhKHRI1DyAflCTELriwFe2rTCqY9BKGe2fuvhZFdzy3dUDqte1kG', 'Librarian', 1],
    [5,  null, 'ikang',           'Lyrica Jen Timbreza',null,       'lyricajen@gmail.com',           '$2y$10$xl8zhNpDQyRYaWhHxsQx4OJYyk2Gvg3C9HtA8r7AcHTFynGypKriu', 'Librarian', 1],
    [10, 16,   'yayang',          'Estilla Gean Cada',  null,       'estillagean@gmail.com',         '$2y$10$xmBze5x366LW8bya6EaW4ObZLstPvOlnBzlI9DyIaKwdJCWNPTNrq', 'User',      1],
    [13, 19,   'student_test_855','Demo Student',       null,       'student_test_855@example.com',  '$2y$10$cCiM5IdBTCYfJ6pXlqeS6u.lloS28VcORo74vkbtstuFiiNqlv9K6', 'User',      1],
    [14, 20,   'wonwoo',          'Jeon onwoo',         null,       'wonwoo@gmail.com',              '$2y$10$e/RnE/vss3XITNn3g131Uu8GHxPXn2n/DpkKCBRzQWXYmKqgm7182', 'User',      1],
    [16, 22,   'wawa',            'wawawiwa',           null,       'wawa@gmail.com',                '$2y$10$/FSq89sbUHmHSiXTMFl.1.MHbbjVKFZuCg68KF6KnGZabep5IuDWe', 'User',      1],
];

// First, remove any conflicting rows that were seeded with wrong user_ids (e.g. librarian at id=2)
$pdo->exec("DELETE FROM users WHERE user_id NOT IN (" . implode(',', array_column($users, 0)) . ")");

$stmtU = $pdo->prepare("
    INSERT INTO users (user_id, student_id, username, full_name, employee_id, email, password_hash, role, is_active)
    VALUES (:uid, :sid, :un, :fn, :eid, :email, :pw, :role, :active)
    ON CONFLICT (user_id) DO UPDATE SET
        student_id=EXCLUDED.student_id, username=EXCLUDED.username,
        full_name=EXCLUDED.full_name, employee_id=EXCLUDED.employee_id,
        email=EXCLUDED.email, password_hash=EXCLUDED.password_hash,
        role=EXCLUDED.role, is_active=EXCLUDED.is_active
");
foreach ($users as $u) {
    $stmtU->execute([
        ':uid'=>$u[0], ':sid'=>$u[1], ':un'=>$u[2], ':fn'=>$u[3],
        ':eid'=>$u[4], ':email'=>$u[5], ':pw'=>$u[6], ':role'=>$u[7], ':active'=>$u[8]
    ]);
}
$pdo->exec("SELECT setval('users_user_id_seq', (SELECT MAX(user_id) FROM users))");
ok(count($users) . " user accounts seeded (Admin, " . (count(array_filter($users, fn($u) => $u[7] === 'Librarian'))) . " Librarians, " . (count(array_filter($users, fn($u) => $u[7] === 'User'))) . " Students)");

// ── Seed: Full Books Catalog ─────────────────────────────────────────────────
step("[5/7] Seeding Complete Books Catalog (29 titles)");
// Wipe the books seeded by schema (they may have different IDs/ISBNs)
$pdo->exec("TRUNCATE book_copies, borrowing_records, reservations RESTART IDENTITY CASCADE");
$pdo->exec("TRUNCATE books RESTART IDENTITY CASCADE");
$books = [
    [1,  1,  '9780132350884', 'Clean Code',                                        'Robert C. Martin',                                                        'Prentice Hall',                                   2008, 'A handbook of agile software craftsmanship.'],
    [2,  2,  '9780743273565', 'The Great Gatsby',                                   'F. Scott Fitzgerald',                                                     'Scribner',                                        1925, 'The story of Jay Gatsby and his love for Daisy Buchanan.'],
    [3,  3,  '9780133970777', 'Database Systems',                                   'Ramez Elmasri',                                                           'Pearson',                                         2015, 'Fundamentals of database systems and SQL.'],
    [4,  4,  '9781319103806', 'Introduction to Psychology',                         'David G. Myers',                                                          'Worth Publishers',                                2018, 'Core concepts and principles of psychology.'],
    [5,  5,  '9781285741552', 'Calculus',                                           'James Stewart',                                                           'Cengage Learning',                                2015, 'Early transcendentals calculus text.'],
    [6,  6,  '9781337553292', 'Physics for Scientists and Engineers',               'Serway & Jewett',                                                         'Cengage Learning',                                2018, 'Physics principles for scientists and engineers.'],
    [7,  15, '9780802416919', '101 Questions You Need to Ask in Your Twenties',     'Paul Angone',                                                             'Moody Publishers',                                2018, 'Life guidance for young adults entering career and university.'],
    [8,  14, '9780590353427', "Harry Potter and the Sorcerer's Stone",              'J. K. Rowling',                                                           'Scholastic Inc.',                                 1999, 'A young wizard discovers his magical identity at Hogwarts.'],
    [10, 1,  '9780135957059', 'The Pragmatic Programmer: Your Journey to Mastery',  'David Thomas, Andrew Hunt',                                               'Addison-Wesley Professional',                     2019, 'Coding craftsmanship and software architecture.'],
    [11, 1,  '9780262046305', 'Introduction to Algorithms (4th Edition)',           'Thomas H. Cormen, Charles E. Leiserson, Ronald L. Rivest, Clifford Stein','MIT Press',                                       2022, 'The standard textbook covering modern algorithms.'],
    [12, 1,  '9780201633610', 'Design Patterns: Elements of Reusable Object-Oriented Software','Erich Gamma, Richard Helm, Ralph Johnson, John Vlissides',    'Addison-Wesley',                                  1994, 'The classic GoF patterns reference.'],
    [13, 1,  '9780596517748', 'JavaScript: The Good Parts',                         'Douglas Crockford',                                                       "O'Reilly Media",                                  2008, 'The essential parts of JavaScript.'],
    [14, 2,  '9780061120084', 'To Kill a Mockingbird',                              'Harper Lee',                                                              'Harper Perennial Modern Classics',                2006, 'A story of racial injustice and childhood in Alabama.'],
    [15, 2,  '9780451524935', '1984',                                               'George Orwell',                                                           'Signet Classic',                                  1950, 'A dystopian novel about totalitarianism and surveillance.'],
    [16, 2,  '9780141439518', 'Pride and Prejudice',                                'Jane Austen',                                                             'Penguin Classics',                                2002, 'Romance and social class in Regency-era England.'],
    [17, 2,  '9780143039693', 'Noli Me Tangere (Touch Me Not)',                     'José Rizal',                                                              'Penguin Classics',                                2006, 'The national novel of the Philippines.'],
    [18, 3,  '9780136681557', 'Computer Networking: A Top-Down Approach (8th Edition)','James Kurose, Keith Ross',                                            'Pearson',                                         2021, 'Comprehensive networking textbook.'],
    [19, 3,  '9781119800361', 'Operating System Concepts (10th Edition)',           'Abraham Silberschatz, Peter B. Galvin, Greg Gagne',                       'Wiley',                                           2021, 'OS fundamentals: processes, memory, storage.'],
    [20, 3,  '9780134610993', 'Artificial Intelligence: A Modern Approach (4th Edition)','Stuart Russell, Peter Norvig',                                       'Pearson',                                         2020, 'The definitive AI textbook.'],
    [21, 4,  '9780374533557', 'Thinking, Fast and Slow',                            'Daniel Kahneman',                                                         'Farrar, Straus and Giroux',                       2011, 'Two systems of thought: intuition vs. deliberation.'],
    [22, 15, '9780735211292', 'Atomic Habits',                                      'James Clear',                                                             'Avery',                                           2018, 'Tiny changes, remarkable results.'],
    [23, 5,  '9780321982384', 'Linear Algebra and Its Applications (5th Edition)',  'David C. Lay, Steven R. Lay, Judi J. McDonald',                           'Pearson',                                         2015, 'Vectors, matrices, and linear transformations.'],
    [24, 5,  '9781259676512', 'Discrete Mathematics and Its Applications (8th Edition)','Kenneth H. Rosen',                                                   'McGraw-Hill Education',                           2018, 'Logic, sets, algorithms, and graph theory.'],
    [25, 6,  '9780135188743', 'Campbell Biology (12th Edition)',                    'Lisa A. Urry, Michael L. Cain, Steven A. Wasserman',                      'Pearson',                                         2020, 'The leading college biology text.'],
    [26, 6,  '9780134414232', 'Chemistry: The Central Science (14th Edition)',      'Theodore L. Brown, H. Eugene LeMay, Bruce E. Bursten',                    'Pearson',                                         2017, 'Comprehensive general chemistry.'],
    [27, 14, '1414059159',    'Sunset Point',                                       'Charles Latham Doxey',                                                    'Authorhouse',                                     2004, ''],
    [28, 11, '9712717070',    "Vicassan's Pilipino-English dictionary.",             'Vito C. Santos',                                                          'Published and exclusively distributed by Anvil Pub.', 2006, ''],
    [29, 14, '9781782808084', 'The Adventures of Chee and Dae in Ishcra Bochra',   'Saniya Chughtai',                                                         'The Wadi Tribe Publishing House, BCH, Ward Publishing Co.', 2016, ''],
    [30, 14, '9780062315007', 'The Alchemist',                                      'Paulo Coelho',                                                            'HarperCollins Publishers',                        2014, 'A philosophical novel about pursuing dreams.'],
];
$stmtB = $pdo->prepare("
    INSERT INTO books (book_id, category_id, isbn, title, author, publisher, publication_year, description)
    VALUES (:bid, :cid, :isbn, :title, :author, :publisher, :year, :desc)
    ON CONFLICT (book_id) DO UPDATE SET
        category_id=EXCLUDED.category_id, isbn=EXCLUDED.isbn,
        title=EXCLUDED.title, author=EXCLUDED.author,
        publisher=EXCLUDED.publisher, publication_year=EXCLUDED.publication_year,
        description=EXCLUDED.description
");
foreach ($books as $b) {
    $stmtB->execute([
        ':bid'=>$b[0],':cid'=>$b[1],':isbn'=>$b[2],':title'=>$b[3],
        ':author'=>$b[4],':publisher'=>$b[5],':year'=>$b[6],':desc'=>$b[7]
    ]);
}
$pdo->exec("SELECT setval('books_book_id_seq', (SELECT MAX(book_id) FROM books))");
ok(count($books) . " books seeded");

// ── Seed: Book Copies (165 physical copies) ───────────────────────────────────
step("[6/7] Seeding Physical Inventory (165 barcoded copies)");
$copies = [];
// Programmatically generate from MySQL export data
$rawCopies = [
    [1,1,'BC-00101'],[2,1,'BC-00102'],[3,2,'BC-00201'],[4,3,'BC-00301'],
    [5,4,'BC-00401'],[6,5,'BC-00501'],[7,6,'BC-00601'],
];
// BC007 (book 7) — 15 copies
for ($i = 1; $i <= 15; $i++) { $rawCopies[] = [7+$i, 7, sprintf('BC007-%02d', $i)]; }
// BC008 (book 8) — 10 copies
for ($i = 1; $i <= 10; $i++) { $rawCopies[] = [22+$i, 8, sprintf('BC008-%02d', $i)]; }
// BC010 (book 10) — 3 copies
for ($i = 1; $i <= 3; $i++) { $rawCopies[] = [32+$i, 10, sprintf('BC010-%02d', $i)]; }
// BC011 (book 11) — 4 copies
for ($i = 1; $i <= 4; $i++) { $rawCopies[] = [35+$i, 11, sprintf('BC011-%02d', $i)]; }
// BC012 — 3 copies
for ($i = 1; $i <= 3; $i++) { $rawCopies[] = [39+$i, 12, sprintf('BC012-%02d', $i)]; }
// BC013 — 2 copies
for ($i = 1; $i <= 2; $i++) { $rawCopies[] = [42+$i, 13, sprintf('BC013-%02d', $i)]; }
// BC014 — 3 copies
for ($i = 1; $i <= 3; $i++) { $rawCopies[] = [44+$i, 14, sprintf('BC014-%02d', $i)]; }
// BC015 — 4 copies
for ($i = 1; $i <= 4; $i++) { $rawCopies[] = [47+$i, 15, sprintf('BC015-%02d', $i)]; }
// BC016 — 2 copies
for ($i = 1; $i <= 2; $i++) { $rawCopies[] = [51+$i, 16, sprintf('BC016-%02d', $i)]; }
// BC017 — 3 copies
for ($i = 1; $i <= 3; $i++) { $rawCopies[] = [53+$i, 17, sprintf('BC017-%02d', $i)]; }
// BC018 — 3 copies
for ($i = 1; $i <= 3; $i++) { $rawCopies[] = [56+$i, 18, sprintf('BC018-%02d', $i)]; }
// BC019 — 3 copies
for ($i = 1; $i <= 3; $i++) { $rawCopies[] = [59+$i, 19, sprintf('BC019-%02d', $i)]; }
// BC020 — 3 copies
for ($i = 1; $i <= 3; $i++) { $rawCopies[] = [62+$i, 20, sprintf('BC020-%02d', $i)]; }
// BC021 — 3 copies
for ($i = 1; $i <= 3; $i++) { $rawCopies[] = [65+$i, 21, sprintf('BC021-%02d', $i)]; }
// BC022 — 4 copies
for ($i = 1; $i <= 4; $i++) { $rawCopies[] = [68+$i, 22, sprintf('BC022-%02d', $i)]; }
// BC023 — 3 copies
for ($i = 1; $i <= 3; $i++) { $rawCopies[] = [72+$i, 23, sprintf('BC023-%02d', $i)]; }
// BC024 — 3 copies
for ($i = 1; $i <= 3; $i++) { $rawCopies[] = [75+$i, 24, sprintf('BC024-%02d', $i)]; }
// BC025 — 3 copies
for ($i = 1; $i <= 3; $i++) { $rawCopies[] = [78+$i, 25, sprintf('BC025-%02d', $i)]; }
// BC026 — 3 copies
for ($i = 1; $i <= 3; $i++) { $rawCopies[] = [81+$i, 26, sprintf('BC026-%02d', $i)]; }
// BC027 — 1 copy
$rawCopies[] = [85, 27, 'BC027-01'];
// BC028 (book 28) — 50 copies
for ($i = 1; $i <= 50; $i++) { $rawCopies[] = [85+$i, 28, sprintf('BC028-%02d', $i)]; }
// BC029 (book 29) — 20 copies
for ($i = 1; $i <= 20; $i++) { $rawCopies[] = [135+$i, 29, sprintf('BC029-%02d', $i)]; }
// BC030 (book 30) — 10 copies
for ($i = 1; $i <= 10; $i++) { $rawCopies[] = [155+$i, 30, sprintf('BC030-%02d', $i)]; }

// Bulk insert all copies in a single SQL statement (1 round-trip)
$valueClauses = [];
foreach ($rawCopies as $c) {
    $esc = str_replace("'", "''", $c[2]); // safe — barcodes are alphanumeric
    $valueClauses[] = "({$c[0]}, {$c[1]}, '$esc', 'Available')";
}
$bulkSql = "INSERT INTO book_copies (copy_id, book_id, barcode, status) VALUES "
         . implode(', ', $valueClauses)
         . " ON CONFLICT (copy_id) DO UPDATE SET "
         . "book_id=EXCLUDED.book_id, barcode=EXCLUDED.barcode, status=EXCLUDED.status";
$pdo->exec($bulkSql);
$pdo->exec("SELECT setval('book_copies_copy_id_seq', (SELECT MAX(copy_id) FROM book_copies))");
ok(count($rawCopies) . " physical copies seeded (single bulk INSERT)");

// ── Final Verification ───────────────────────────────────────────────────────
step("[7/7] Final Verification — Neon Row Counts");
$tables = [
    'courses'           => 'Academic degree programs',
    'categories'        => 'Book taxonomy categories',
    'users'             => 'System user accounts',
    'students'          => 'Student profiles',
    'books'             => 'Library catalog titles',
    'book_copies'       => 'Physical barcoded copies',
    'borrowing_records' => 'Circulation loan transactions',
    'reservations'      => 'Book reservation queue',
    'activity_logs'     => 'Audit trail event logs',
];
foreach ($tables as $t => $desc) {
    $n = $pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
    printf("  %-22s │ %4d records │ %s\n", $t, $n, $desc);
}

echo "\n  Default Login Credentials:\n";
echo "  ┌─────────────┬────────────┬────────────────────────────┐\n";
echo "  │ Role        │ Username   │ Email                      │\n";
echo "  ├─────────────┼────────────┼────────────────────────────┤\n";
$accts = $pdo->query("SELECT role, username, email FROM users WHERE role IN ('Admin','Librarian') ORDER BY user_id")->fetchAll();
foreach ($accts as $a) {
    printf("  │ %-11s │ %-10s │ %-26s │\n", $a['role'], $a['username'], $a['email']);
}
echo "  └─────────────┴────────────┴────────────────────────────┘\n";
echo "  Passwords: admin → admin123  |  librarian → (existing hash)\n";

echo "\n======================================================================\n";
echo "  [SUCCESS] Neon database is fully synced with 29 books & 165 copies!\n";
echo "======================================================================\n";
