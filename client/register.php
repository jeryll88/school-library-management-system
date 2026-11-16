<?php
require_once __DIR__ . '/../server/config/db.php';
require_once __DIR__ . '/../server/config/auth.php';

startAppSession();

// If already logged in, redirect to index
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error   = '';
$success = '';

$db = getDB();

// Fetch courses for the student registration dropdown
$courses = [];
try {
    $cStmt = $db->query("SELECT id, course_code, course_name FROM courses ORDER BY course_code ASC");
    $courses = $cStmt->fetchAll();
} catch (Exception $e) {
    $courses = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // SECURITY: Always assign 'User' role regardless of what might be submitted in the request
    $dbRole = 'User';

    // Form inputs
    $fullName        = trim($_POST['full_name'] ?? '');
    $username        = trim($_POST['username'] ?? '');
    $email           = strtolower(trim($_POST['email'] ?? ''));
    $studentNumber   = strtoupper(trim($_POST['student_number'] ?? ''));
    $gender          = $_POST['gender'] ?? 'Male';
    $yearLevel       = (int)($_POST['year_level'] ?? 1);
    $courseId        = (int)($_POST['course_id'] ?? 0);
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validation
    if ($fullName === '' || $username === '' || $email === '' || $studentNumber === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please fill in all required fields, including your Student ID / Number.';
    } elseif (strlen($fullName) > 100) {
        $error = 'Full name must be 100 characters or fewer.';
    } elseif (strlen($studentNumber) < 3 || strlen($studentNumber) > 50) {
        $error = 'Student ID / Number must be between 3 and 50 characters.';
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $error = 'Username must be between 3 and 50 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
        $error = 'Username may only contain letters, numbers, periods, underscores, and hyphens.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        $error = 'Please enter a valid email address (max 100 characters).';
    } elseif ($courseId <= 0) {
        $error = 'Please select your course / degree program.';
    } elseif (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
        $error = 'Please select a valid gender option.';
    } elseif ($yearLevel < 1 || $yearLevel > 4) {
        $error = 'Please select a valid year level (1st to 4th Year).';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match. Please re-enter your password.';
    } else {
        try {
            // 1. Check duplicate username (case-insensitive)
            $checkUser = $db->prepare("SELECT user_id FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
            $checkUser->execute([':u' => $username]);
            if ($checkUser->fetch()) {
                $error = 'That username is already taken. Please choose another username.';
            }

            // 2. Check duplicate email (case-insensitive)
            if (empty($error)) {
                $checkEmail = $db->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(:e) LIMIT 1");
                $checkEmail->execute([':e' => $email]);
                if ($checkEmail->fetch()) {
                    $error = 'That email address is already registered. Please log in or use another email.';
                }
            }

            // 3. Check duplicate student number / ID
            if (empty($error)) {
                $checkStudent = $db->prepare("SELECT id FROM students WHERE LOWER(student_number) = LOWER(:sn) LIMIT 1");
                $checkStudent->execute([':sn' => $studentNumber]);
                if ($checkStudent->fetch()) {
                    $error = 'That Student ID (' . htmlspecialchars($studentNumber) . ') is already registered in the library system.';
                }
            }

            // If all checks passed, insert student record and user account atomically
            if (empty($error)) {
                $db->beginTransaction();

                $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'pgsql') {
                    $sStmt = $db->prepare("
                        INSERT INTO students (name, student_number, gender, year_level, course_id)
                        VALUES (:name, :snum, :gender, :year, :course)
                        RETURNING id
                    ");
                    $sStmt->execute([
                        ':name'   => $fullName,
                        ':snum'   => $studentNumber,
                        ':gender' => $gender,
                        ':year'   => $yearLevel,
                        ':course' => $courseId,
                    ]);
                    $studentPk = (int)$sStmt->fetchColumn();
                } else {
                    $sStmt = $db->prepare("
                        INSERT INTO students (name, student_number, gender, year_level, course_id)
                        VALUES (:name, :snum, :gender, :year, :course)
                    ");
                    $sStmt->execute([
                        ':name'   => $fullName,
                        ':snum'   => $studentNumber,
                        ':gender' => $gender,
                        ':year'   => $yearLevel,
                        ':course' => $courseId,
                    ]);
                    $studentPk = (int)$db->lastInsertId();
                }

                // Hash password securely using standard bcrypt
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);

                // Insert into users table with default role 'User' and active status
                $uStmt = $db->prepare("
                    INSERT INTO users (student_id, username, full_name, email, password_hash, role, is_active)
                    VALUES (:sid, :u, :fn, :e, :h, 'User', 1)
                ");
                $uStmt->execute([
                    ':sid' => $studentPk,
                    ':u'   => $username,
                    ':fn'  => $fullName,
                    ':e'   => $email,
                    ':h'   => $passwordHash,
                ]);

                $db->commit();

                // Redirect to login page with success notification
                header('Location: login.php?registered=1');
                exit;
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23000') {
                $error = 'Registration failed: Username, email, or Student ID is already in use.';
            } else {
                $error = 'Registration error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration — School Library Management System</title>
    <!-- Tailwind CSS (Offline Local) -->
    <script src="assets/vendor/tailwindcss.js"></script>
    <!-- Font Awesome (Offline Local) -->
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <!-- Inter Font (Offline Local) -->
    <link rel="stylesheet" href="assets/vendor/fonts/inter.css">
    <!-- Custom Authentication Stylesheet -->
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="min-h-screen flex flex-col bg-slate-100">

    <!-- Top Navigation -->
    <nav class="top-nav px-8 py-3.5 flex items-center justify-between shrink-0">
        <a href="login.php" class="flex items-center gap-3">
            <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
                <i class="fa-solid fa-book-open text-white text-sm"></i>
            </div>
            <span class="font-bold text-white text-base tracking-tight">Library Management System</span>
        </a>
        <div class="flex items-center gap-4 text-xs text-slate-300 font-medium">
            <span>Already have an account?</span>
            <a href="login.php" class="px-3.5 py-1.5 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors font-semibold">Sign In</a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="flex flex-1 overflow-hidden">

        <!-- Left Branding Panel (Hidden on smaller screens) -->
        <div class="library-bg-gradient hidden lg:flex flex-col items-center justify-center w-5/12 p-12 text-white">
            <div class="w-20 h-20 bg-white/10 backdrop-blur-md rounded-2xl flex items-center justify-center border border-white/20 mb-8 shadow-xl">
                <i class="fa-solid fa-graduation-cap text-4xl text-blue-300"></i>
            </div>

            <h2 class="text-3xl font-black text-center mb-3 leading-tight drop-shadow">
                Student Portal<br>Registration
            </h2>
            <p class="text-blue-200 text-center font-medium mb-8 text-sm max-w-xs">
                Register as a student to search books, request reservations, and manage your borrowed library materials.
            </p>

            <div class="space-y-3 w-72">
                <div class="bg-white/10 backdrop-blur-sm border border-white/20 rounded-xl px-4 py-2.5 text-xs font-semibold text-white flex items-center gap-3">
                    <i class="fa-solid fa-check text-blue-300 text-sm"></i> Instant Student Account
                </div>
                <div class="bg-white/10 backdrop-blur-sm border border-white/20 rounded-xl px-4 py-2.5 text-xs font-semibold text-white flex items-center gap-3">
                    <i class="fa-solid fa-book-open-reader text-blue-300 text-sm"></i> Book Borrowing & Tracking
                </div>
                <div class="bg-white/10 backdrop-blur-sm border border-white/20 rounded-xl px-4 py-2.5 text-xs font-semibold text-white flex items-center gap-3">
                    <i class="fa-solid fa-shield-halved text-blue-300 text-sm"></i> Secure Authentication
                </div>
            </div>

            <div class="mt-8 text-[11px] text-blue-200/80 text-center">
                Need staff access? Librarian accounts are created directly by the System Administrator.
            </div>
        </div>

        <!-- Right Registration Form -->
        <div class="flex-1 overflow-y-auto flex items-center justify-center p-6 lg:p-10 bg-slate-50">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-7 border border-slate-100 my-auto">

                <!-- Header -->
                <div class="text-center mb-5">
                    <div class="inline-flex items-center gap-2 px-3 py-1 bg-blue-50 text-blue-700 rounded-full text-xs font-semibold mb-2">
                        <i class="fa-solid fa-user-graduate"></i> Student Registration
                    </div>
                    <h1 class="text-2xl font-black text-slate-900 mb-1">Create Your Account</h1>
                    <p class="text-slate-500 text-xs">Fill in your information to register for library access.</p>
                </div>

                <!-- Error Alert -->
                <?php if ($error): ?>
                <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl px-4 py-2.5 text-xs font-semibold flex items-center gap-2 shake">
                    <i class="fa-solid fa-circle-xmark text-rose-500 text-sm shrink-0"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="register.php" id="register-form">
                    <!-- Full Name -->
                    <div class="mb-3">
                        <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">Full Name <span class="text-rose-500">*</span></label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-id-card inp-icon"></i>
                            <input type="text" name="full_name" placeholder="e.g. Maria Santos" class="inp"
                                value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" maxlength="100" required>
                        </div>
                    </div>

                    <!-- Student ID / Number -->
                    <div class="mb-3">
                        <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">
                            Student ID / Number <span class="text-rose-500">*</span>
                        </label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-id-badge inp-icon"></i>
                            <input type="text" name="student_number" placeholder="e.g. 2026-00123" class="inp"
                                value="<?= htmlspecialchars($_POST['student_number'] ?? '') ?>" maxlength="50" required>
                        </div>
                    </div>

                    <!-- Course / Degree Program -->
                    <div class="mb-3">
                        <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">Course / Program <span class="text-rose-500">*</span></label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-graduation-cap inp-icon"></i>
                            <select name="course_id" class="inp" required>
                                <option value="">-- Select Your Course --</option>
                                <?php foreach ($courses as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= (int)($_POST['course_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Gender & Year Level Grid -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">Gender <span class="text-rose-500">*</span></label>
                            <div class="inp-wrap">
                                <i class="fa-solid fa-venus-mars inp-icon"></i>
                                <select name="gender" class="inp" required>
                                    <option value="Male" <?= ($_POST['gender'] ?? 'Male') === 'Male' ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= ($_POST['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                    <option value="Other" <?= ($_POST['gender'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">Year Level <span class="text-rose-500">*</span></label>
                            <div class="inp-wrap">
                                <i class="fa-solid fa-calendar-days inp-icon"></i>
                                <select name="year_level" class="inp" required>
                                    <option value="1" <?= (int)($_POST['year_level'] ?? 1) === 1 ? 'selected' : '' ?>>1st Year</option>
                                    <option value="2" <?= (int)($_POST['year_level'] ?? 0) === 2 ? 'selected' : '' ?>>2nd Year</option>
                                    <option value="3" <?= (int)($_POST['year_level'] ?? 0) === 3 ? 'selected' : '' ?>>3rd Year</option>
                                    <option value="4" <?= (int)($_POST['year_level'] ?? 0) === 4 ? 'selected' : '' ?>>4th Year</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Username & Email Grid -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">Username <span class="text-rose-500">*</span></label>
                            <div class="inp-wrap">
                                <i class="fa-solid fa-user inp-icon"></i>
                                <input type="text" name="username" placeholder="Choose username" class="inp"
                                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" required>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">Email Address <span class="text-rose-500">*</span></label>
                            <div class="inp-wrap">
                                <i class="fa-solid fa-envelope inp-icon"></i>
                                <input type="email" name="email" placeholder="student@school.edu" class="inp"
                                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" maxlength="100" required>
                            </div>
                        </div>
                    </div>

                    <!-- Password & Confirm Password Grid -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">Password <span class="text-rose-500">*</span></label>
                            <div class="inp-wrap">
                                <i class="fa-solid fa-lock inp-icon"></i>
                                <input type="password" name="password" id="reg-pass" placeholder="Min. 6 characters" class="inp pr-9" required minlength="6" autocomplete="new-password">
                                <span class="inp-right" onclick="toggleField('reg-pass', 'reg-eye')">
                                    <i class="fa-regular fa-eye" id="reg-eye"></i>
                                </span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">Confirm Password <span class="text-rose-500">*</span></label>
                            <div class="inp-wrap">
                                <i class="fa-solid fa-lock inp-icon"></i>
                                <input type="password" name="confirm_password" id="reg-cpass" placeholder="Re-enter password" class="inp" required minlength="6" autocomplete="new-password">
                            </div>
                        </div>
                    </div>

                    <!-- Register Submit Button -->
                    <button type="submit" class="btn-register mb-4">
                        <i class="fa-solid fa-user-plus mr-1.5"></i> Register Account
                    </button>
                </form>

                <!-- Back to Login -->
                <div class="text-center pt-3 border-t border-slate-100">
                    <span class="text-slate-500 text-xs">Already have an account? </span>
                    <a href="login.php" class="text-blue-600 font-bold text-xs hover:text-blue-800 hover:underline">Log in here</a>
                </div>

            </div>
        </div>
    </div>

    <script>
        function toggleField(inputId, iconId) {
            const inp = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (inp.type === 'password') {
                inp.type = 'text';
                icon.className = 'fa-regular fa-eye-slash';
            } else {
                inp.type = 'password';
                icon.className = 'fa-regular fa-eye';
            }
        }
    </script>
</body>
</html>
