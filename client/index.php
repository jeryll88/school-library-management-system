<?php
require_once __DIR__ . '/../server/config/auth.php';
$account = requirePageAccount();
$currentUser = [
    'id'        => $account['user_id'],
    'username'  => $account['username'],
    'full_name' => $account['full_name'],
    'role'      => $account['role'],
];
$avatarLetter = strtoupper(substr($currentUser['full_name'] ?: $currentUser['username'], 0, 1));
$displayRole  = ($currentUser['role'] === 'User') ? 'Student' : $currentUser['role'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Management System</title>
    <meta name="description" content="School Library Management System — manage books, borrowing, students, reservations and more.">
    <!-- Tailwind CSS (Offline Local) -->
    <script src="assets/vendor/tailwindcss.js"></script>
    <!-- Font Awesome (Offline Local) -->
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <!-- Inter Font (Offline Local) -->
    <link rel="stylesheet" href="assets/vendor/fonts/inter.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        navy: { 800: '#0f2c59', 900: '#0d2347', 950: '#081730' },
                        brand: { 500: '#1877f2', 600: '#1464d8' }
                    }
                }
            }
        }
    </script>
    <!-- Custom Main Stylesheet -->
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body class="flex h-screen overflow-hidden">

    <!-- ===== SIDEBAR OVERLAY (mobile) ===== -->
    <div id="sidebar-overlay" onclick="closeSidebar()"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside id="sidebar" class="bg-navy-900 text-white flex flex-col overflow-y-auto shrink-0">
        <!-- Brand -->
        <div class="px-4 py-4 border-b border-slate-700/60">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-graduation-cap text-white text-sm"></i>
                </div>
                <div>
                    <div class="font-bold text-sm text-white leading-tight">Library System</div>
                    <div class="text-[10px] text-slate-400 leading-tight">School DB</div>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 px-3 py-4 space-y-0.5">
            <?php if ($currentUser['role'] === 'User'): ?>
                <!-- Student / User Navigation -->
                <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest px-2 mb-2">Student Portal</div>
                <div class="nav-item active" data-page="dashboard" id="nav-dashboard">
                    <i class="fa-solid fa-house"></i> Student Portal
                </div>
                <div class="nav-item" data-page="books" id="nav-books">
                    <i class="fa-solid fa-book-open"></i> Browse Books
                </div>
                <div class="nav-item" data-page="borrowing" id="nav-borrowing">
                    <i class="fa-solid fa-clock-rotate-left"></i> My Borrowings
                </div>
                <div class="nav-item" data-page="reservations" id="nav-reservations">
                    <i class="fa-solid fa-bookmark"></i> My Reservations
                </div>
                <div class="nav-item" data-page="profile" id="nav-profile">
                    <i class="fa-solid fa-user"></i> My Profile
                </div>

            <?php elseif ($currentUser['role'] === 'Librarian'): ?>
                <!-- Librarian Navigation -->
                <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest px-2 mb-2">Operations</div>
                <div class="nav-item active" data-page="dashboard" id="nav-dashboard">
                    <i class="fa-solid fa-house"></i> Dashboard
                </div>
                <div class="nav-item" data-page="books" id="nav-books">
                    <i class="fa-solid fa-book"></i> Books
                </div>
                <div class="nav-item" data-page="book_copies" id="nav-book_copies">
                    <i class="fa-solid fa-layer-group"></i> Book Copies
                </div>
                <div class="nav-item" data-page="borrowing" id="nav-borrowing">
                    <i class="fa-solid fa-clock-rotate-left"></i> Borrowing Records
                </div>
                <div class="nav-item" data-page="reservations" id="nav-reservations">
                    <i class="fa-solid fa-bookmark"></i> Reservations
                </div>

                <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest px-2 mt-4 mb-2">Directory</div>
                <div class="nav-item" data-page="students" id="nav-students">
                    <i class="fa-solid fa-user-graduate"></i> Students
                </div>
                <div class="nav-item" data-page="categories" id="nav-categories">
                    <i class="fa-solid fa-tags"></i> Categories
                </div>

                <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest px-2 mt-4 mb-2">Account</div>
                <div class="nav-item" data-page="profile" id="nav-profile">
                    <i class="fa-solid fa-user-gear"></i> My Profile
                </div>

            <?php else: ?>
                <!-- Administrator Navigation -->
                <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest px-2 mb-2">Main Menu</div>
                <div class="nav-item active" data-page="dashboard" id="nav-dashboard">
                    <i class="fa-solid fa-house"></i> Dashboard
                </div>
                <div class="nav-item" data-page="books" id="nav-books">
                    <i class="fa-solid fa-book"></i> Books
                </div>
                <div class="nav-item" data-page="book_copies" id="nav-book_copies">
                    <i class="fa-solid fa-layer-group"></i> Book Copies
                </div>
                <div class="nav-item" data-page="borrowing" id="nav-borrowing">
                    <i class="fa-solid fa-clock-rotate-left"></i> Borrowing Records
                </div>
                <div class="nav-item" data-page="reservations" id="nav-reservations">
                    <i class="fa-solid fa-bookmark"></i> Reservations
                </div>

                <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest px-2 mt-4 mb-2">People</div>
                <div class="nav-item" data-page="students" id="nav-students">
                    <i class="fa-solid fa-user-graduate"></i> Students
                </div>
                <div class="nav-item" data-page="users" id="nav-users">
                    <i class="fa-solid fa-users"></i> Librarian Management
                </div>

                <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest px-2 mt-4 mb-2">Settings</div>
                <div class="nav-item" data-page="courses" id="nav-courses">
                    <i class="fa-solid fa-graduation-cap"></i> Courses
                </div>
                <div class="nav-item" data-page="categories" id="nav-categories">
                    <i class="fa-solid fa-tags"></i> Categories
                </div>

                <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest px-2 mt-4 mb-2">Account</div>
                <div class="nav-item" data-page="profile" id="nav-profile">
                    <i class="fa-solid fa-user-gear"></i> My Profile
                </div>

            <?php endif; ?>
        </nav>

        <!-- Footer Avatar -->
        <div class="px-4 py-3 border-t border-slate-700/60 flex items-center justify-between">
            <div class="flex items-center gap-2.5 overflow-hidden">
                <div class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-sm shrink-0">
                    <?= $avatarLetter ?>
                </div>
                <div class="overflow-hidden">
                    <div class="font-bold text-[11px] text-white truncate max-w-[110px]" title="<?= htmlspecialchars($currentUser['full_name']) ?>">
                        <?= htmlspecialchars($currentUser['full_name']) ?>
                    </div>
                    <div class="text-[10px] text-emerald-400 font-semibold flex items-center gap-1 mt-0.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                        <?= htmlspecialchars($displayRole) ?>
                    </div>
                </div>
            </div>
            <a href="logout.php" title="Sign out" class="text-slate-400 hover:text-rose-400 p-1.5 rounded transition-colors">
                <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i>
            </a>
        </div>
    </aside>

    <!-- ===== MAIN AREA ===== -->
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top Header Bar -->
        <header class="bg-white border-b border-slate-200 px-3 sm:px-5 py-2.5 flex items-center justify-between shrink-0 shadow-sm">
            <div class="flex items-center gap-2 sm:gap-3">
                <!-- Hamburger (mobile only) -->
                <button id="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                    <input id="global-search" type="text" placeholder="Search anything..."
                        class="pl-8 pr-4 py-1.5 text-sm border border-slate-200 rounded-lg bg-slate-50 focus:outline-none focus:border-blue-400 w-40 sm:w-56 transition-colors">
                </div>
            </div>
            <div class="flex items-center gap-4">
                <div class="relative cursor-pointer" onclick="navigateTo('borrowing')">
                    <i class="fa-regular fa-bell text-slate-500 text-base"></i>
                    <span id="overdue-badge" class="absolute -top-1 -right-1 w-4 h-4 bg-rose-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center hidden">0</span>
                </div>
                
                <!-- User Profile & Logout -->
                <div class="flex items-center gap-3 border-l border-slate-200 pl-4">
                    <div class="flex items-center gap-2 cursor-pointer hover:opacity-85 transition-opacity" onclick="navigateTo('profile')" title="View My Profile & Settings">
                        <div class="w-7 h-7 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center">
                            <?= $avatarLetter ?>
                        </div>
                        <div class="hidden sm:block text-left">
                            <div class="font-semibold text-sm text-slate-800 leading-none"><?= htmlspecialchars($currentUser['full_name']) ?></div>
                            <div class="text-[10px] text-slate-400 mt-0.5"><?= htmlspecialchars($displayRole) ?> (@<?= htmlspecialchars($currentUser['username']) ?>)</div>
                        </div>
                    </div>
                    <a href="logout.php" class="inline-flex items-center gap-1.5 text-xs text-rose-600 hover:text-white hover:bg-rose-600 border border-rose-200 hover:border-rose-600 font-semibold px-2.5 py-1 rounded-lg transition-colors ml-1">
                        <i class="fa-solid fa-arrow-right-from-bracket text-[10px]"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <main class="flex-1 overflow-y-auto bg-slate-50 p-3 sm:p-5" id="main-content">
            <div id="page-content">
                <!-- Loaded dynamically by app.js -->
                <div class="flex items-center justify-center h-64">
                    <div class="spinner"></div>
                </div>
            </div>
        </main>
    </div>

    <!-- ===== MODAL CONTAINER ===== -->
    <div id="modal-container"></div>

    <!-- ===== TOAST CONTAINER ===== -->
    <div id="toast-container"></div>

    <!-- ===== APP JS ===== -->
    <script>
        window.CURRENT_USER = <?= json_encode($currentUser, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        window.CSRF_TOKEN = <?= json_encode(getCsrfToken()) ?>;

        // ── Mobile Sidebar Toggle ──────────────────────────────────────────
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }
        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebar-overlay').classList.remove('active');
        }
        // Close sidebar when a nav item is clicked on mobile
        document.querySelectorAll('.nav-item').forEach(el => {
            el.addEventListener('click', () => {
                if (window.innerWidth <= 1024) closeSidebar();
            });
        });
    </script>
    <script src="assets/app.js"></script>
</body>
</html>
