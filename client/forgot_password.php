<?php
require_once __DIR__ . '/../server/config/db.php';
require_once __DIR__ . '/../server/config/auth.php';

startAppSession();

// If already logged in, redirect to main application
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error   = '';
$success = '';
$step    = 1; // Step 1: Verify identity; Step 2: Set new password
$verifiedUserId = 0;
$verifiedUsername = '';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'verify';

    if ($action === 'verify') {
        $identifier    = trim($_POST['identifier'] ?? '');
        $securityCode  = trim($_POST['security_code'] ?? ''); // Student ID or Employee ID or Email

        if ($identifier === '' || $securityCode === '') {
            $error = 'Please fill in both your username/email and verification credential.';
        } else {
            // Find user by username or email
            $stmt = $db->prepare("
                SELECT u.user_id, u.username, u.full_name, u.email, u.employee_id, u.role, u.is_active,
                       s.student_number
                FROM users u
                LEFT JOIN students s ON u.student_id = s.id
                WHERE LOWER(u.username) = LOWER(:id1) OR LOWER(u.email) = LOWER(:id2)
                LIMIT 1
            ");
            $stmt->execute([':id1' => $identifier, ':id2' => $identifier]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'No account found matching that username or email address.';
            } elseif ((int)$user['is_active'] !== 1) {
                $error = 'This account has been deactivated. Please contact the library administrator.';
            } else {
                $verified = false;

                // 1. Check Student Number (for Students)
                if (!empty($user['student_number']) && strcasecmp(trim($user['student_number']), $securityCode) === 0) {
                    $verified = true;
                }
                // 2. Check Employee ID (for Staff/Admin)
                elseif (!empty($user['employee_id']) && strcasecmp(trim($user['employee_id']), $securityCode) === 0) {
                    $verified = true;
                }
                // 3. Check registered email match
                elseif (!empty($user['email']) && strcasecmp(trim($user['email']), $securityCode) === 0) {
                    $verified = true;
                }

                if ($verified) {
                    $step = 2;
                    $verifiedUserId   = (int)$user['user_id'];
                    $verifiedUsername = $user['username'];
                    // Store in session for security during step 2
                    $_SESSION['pwd_reset_user_id'] = $verifiedUserId;
                    $_SESSION['pwd_reset_user_name'] = $verifiedUsername;
                } else {
                    $error = 'Identity verification failed. The Student ID / Employee ID / Email did not match our records for that account.';
                }
            }
        }
    } elseif ($action === 'reset') {
        $sessionUserId = (int)($_SESSION['pwd_reset_user_id'] ?? 0);
        $newPassword   = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!$sessionUserId) {
            $error = 'Reset session expired. Please start over.';
            $step = 1;
        } elseif (strlen($newPassword) < 6) {
            $step = 2;
            $verifiedUserId = $sessionUserId;
            $verifiedUsername = $_SESSION['pwd_reset_user_name'] ?? '';
            $error = 'Password must be at least 6 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $step = 2;
            $verifiedUserId = $sessionUserId;
            $verifiedUsername = $_SESSION['pwd_reset_user_name'] ?? '';
            $error = 'Passwords do not match. Please re-enter.';
        } else {
            // Update password in database
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $updateStmt = $db->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :id");
            $updateStmt->execute([':hash' => $passwordHash, ':id' => $sessionUserId]);

            unset($_SESSION['pwd_reset_user_id']);
            unset($_SESSION['pwd_reset_user_name']);

            header('Location: login.php?reset=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — Library Management System</title>
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

    <!-- Top Navigation Bar -->
    <nav class="top-nav px-8 py-3.5 flex items-center justify-between shrink-0">
        <a href="login.php" class="flex items-center gap-3">
            <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
                <i class="fa-solid fa-book-open text-white text-sm"></i>
            </div>
            <span class="font-bold text-white text-base tracking-tight">Library Management System</span>
        </a>
        <div class="flex items-center gap-4 text-xs text-slate-300 font-medium">
            <span>Remembered your password?</span>
            <a href="login.php" class="px-3.5 py-1.5 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors font-semibold">Sign In</a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col lg:flex-row overflow-hidden">

        <!-- Left Branding Panel -->
        <div class="hidden lg:flex lg:w-1/2 library-bg flex-col justify-between p-12 text-white">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                    <i class="fa-solid fa-shield-halved text-white text-lg"></i>
                </div>
                <div>
                    <div class="font-bold text-lg leading-tight">Account Recovery</div>
                    <div class="text-xs text-blue-200">Verified Self-Service Password Reset</div>
                </div>
            </div>

            <div class="space-y-4 my-auto py-8">
                <h2 class="text-3xl font-black leading-tight">
                    Recover Access to Your Library Account
                </h2>
                <p class="text-slate-200 text-sm leading-relaxed max-w-md">
                    Verify your identity using your School Student ID, Employee ID, or registered email to securely create a new password.
                </p>
                <div class="space-y-2.5 pt-4 text-xs font-semibold text-slate-200">
                    <div class="flex items-center gap-2.5">
                        <i class="fa-solid fa-circle-check text-blue-400"></i> No email internet server needed
                    </div>
                    <div class="flex items-center gap-2.5">
                        <i class="fa-solid fa-circle-check text-blue-400"></i> Instant offline verification
                    </div>
                    <div class="flex items-center gap-2.5">
                        <i class="fa-solid fa-circle-check text-blue-400"></i> Bcrypt encrypted security
                    </div>
                </div>
            </div>

            <div class="text-xs text-slate-300">
                School Library Management System &copy; <?= date('Y') ?>
            </div>
        </div>

        <!-- Right Form Panel -->
        <div class="flex-1 flex items-center justify-center p-6 lg:p-12 bg-slate-50 overflow-y-auto">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-8 border border-slate-100 my-auto">

                <!-- Header -->
                <div class="text-center mb-6">
                    <div class="inline-flex items-center gap-2 px-3 py-1 bg-blue-50 text-blue-700 rounded-full text-xs font-semibold mb-2">
                        <i class="fa-solid fa-key"></i> <?= $step === 1 ? 'Step 1: Verify Identity' : 'Step 2: Create Password' ?>
                    </div>
                    <h1 class="text-2xl font-black text-slate-900 mb-1">
                        <?= $step === 1 ? 'Reset Your Password' : 'Set New Password' ?>
                    </h1>
                    <p class="text-slate-500 text-xs">
                        <?= $step === 1 
                            ? 'Enter your account details below to verify ownership.' 
                            : 'Identity confirmed for <strong>' . htmlspecialchars($verifiedUsername) . '</strong>. Choose a new password.' ?>
                    </p>
                </div>

                <!-- Error Alert -->
                <?php if ($error): ?>
                <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl px-4 py-2.5 text-xs font-semibold flex items-center gap-2 shake">
                    <i class="fa-solid fa-circle-xmark text-rose-500 text-sm shrink-0"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                <!-- STEP 1: IDENTITY VERIFICATION FORM -->
                <form method="POST" action="forgot_password.php">
                    <input type="hidden" name="action" value="verify">

                    <!-- Username or Email -->
                    <div class="mb-4">
                        <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">
                            Username or Registered Email <span class="text-rose-500">*</span>
                        </label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-user inp-icon"></i>
                            <input type="text" name="identifier" placeholder="e.g. kiki or student@school.edu" class="inp"
                                value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" required autofocus>
                        </div>
                    </div>

                    <!-- Security Verification Credential -->
                    <div class="mb-5">
                        <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">
                            Student ID / Employee ID / Email <span class="text-rose-500">*</span>
                        </label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-id-card inp-icon"></i>
                            <input type="text" name="security_code" placeholder="e.g. 2026-00123 or EMP-101" class="inp"
                                value="<?= htmlspecialchars($_POST['security_code'] ?? '') ?>" required>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1.5 leading-normal">
                            Students: Enter your official School Student ID. Staff: Enter your Employee ID or registered email.
                        </p>
                    </div>

                    <button type="submit" class="btn-register mb-4">
                        <i class="fa-solid fa-arrow-right mr-1.5"></i> Verify & Continue
                    </button>
                </form>

                <?php else: ?>
                <!-- STEP 2: NEW PASSWORD FORM -->
                <form method="POST" action="forgot_password.php">
                    <input type="hidden" name="action" value="reset">

                    <!-- New Password -->
                    <div class="mb-4">
                        <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">
                            New Password <span class="text-rose-500">*</span>
                        </label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-lock inp-icon"></i>
                            <input type="password" name="new_password" id="reset-pass" placeholder="Min. 6 characters" class="inp pr-9" required minlength="6" autofocus>
                            <span class="inp-right" onclick="togglePassField('reset-pass', 'eye-reset-1')">
                                <i class="fa-regular fa-eye" id="eye-reset-1"></i>
                            </span>
                        </div>
                    </div>

                    <!-- Confirm New Password -->
                    <div class="mb-5">
                        <label class="block text-xs font-bold text-slate-600 mb-1 tracking-wide uppercase">
                            Confirm New Password <span class="text-rose-500">*</span>
                        </label>
                        <div class="inp-wrap">
                            <i class="fa-solid fa-lock inp-icon"></i>
                            <input type="password" name="confirm_password" id="reset-cpass" placeholder="Re-enter new password" class="inp" required minlength="6">
                        </div>
                    </div>

                    <button type="submit" class="btn-register mb-4">
                        <i class="fa-solid fa-check mr-1.5"></i> Update Password & Sign In
                    </button>
                </form>
                <?php endif; ?>

                <!-- Back to Login -->
                <div class="text-center pt-3 border-t border-slate-100">
                    <a href="login.php" class="text-slate-500 text-xs hover:text-blue-600 font-semibold inline-flex items-center gap-1.5">
                        <i class="fa-solid fa-arrow-left text-[10px]"></i> Return to Login
                    </a>
                </div>

            </div>
        </div>
    </div>

    <script>
        function togglePassField(inputId, iconId) {
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

