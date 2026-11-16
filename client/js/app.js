/**
 * Library Management System — app.js
 * Handles all page navigation, data fetching, and CRUD operations.
 */

'use strict';

// ─── State ────────────────────────────────────────────────────────────────────
const State = {
    currentPage: 'dashboard',
    pages: {},
};

// ─── Utility Helpers ──────────────────────────────────────────────────────────

function fmt(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function badge(status) {
    const map = {
        'Available': 'available', 'Borrowed': 'borrowed', 'Reserved': 'reserved',
        'Lost': 'lost', 'Damaged': 'damaged',
        'Active': 'active', 'Inactive': 'inactive', 'Returned': 'returned', 'Overdue': 'overdue',
        'Pending': 'pending', 'Approved': 'approved', 'Cancelled': 'cancelled',
        'Fulfilled': 'fulfilled', 'Checked Out': 'checkedout',
        'User': 'active', 'Librarian': 'returned', 'Admin': 'reserved',
    };
    const cls = map[status] || 'pending';
    return `<span class="badge badge-${cls}">${status}</span>`;
}

function toast(msg, type = 'success') {
    const container = document.getElementById('toast-container');
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.innerHTML = `<i class="fa-solid fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'circle-xmark' : 'info-circle'} mr-2"></i>${msg}`;
    container.appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

async function api(url, method = 'GET', body = null) {
    const opts = {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.CSRF_TOKEN || '',
        },
    };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(url, opts);
    // Guard against non-JSON responses (PHP errors, HTML pages)
    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch (e) {
        console.error('Non-JSON API response:', text.substring(0, 500));
        throw new Error('Server returned an unexpected response. Check console for details.');
    }
    if (!res.ok) throw new Error(data.error || 'API error');
    return data;
}

function showModal(html) {
    document.getElementById('modal-container').innerHTML = html;
    document.getElementById('modal-container').querySelector('.modal-overlay').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
}

function closeModal() {
    document.getElementById('modal-container').innerHTML = '';
}

function buildPagination(total, page, limit, onPageChange) {
    const totalPages = Math.ceil(total / limit);
    if (totalPages <= 1) return '';
    let html = `<div class="flex items-center gap-1.5">
        <button class="page-btn" onclick="(${onPageChange})(${page - 1})" ${page <= 1 ? 'disabled' : ''}>
            <i class="fa-solid fa-chevron-left text-[10px]"></i>
        </button>`;
    const start = Math.max(1, page - 2);
    const end   = Math.min(totalPages, page + 2);
    for (let p = start; p <= end; p++) {
        html += `<button class="page-btn ${p === page ? 'active' : ''}" onclick="(${onPageChange})(${p})">${p}</button>`;
    }
    html += `<button class="page-btn" onclick="(${onPageChange})(${page + 1})" ${page >= totalPages ? 'disabled' : ''}>
        <i class="fa-solid fa-chevron-right text-[10px]"></i>
    </button></div>`;
    return html;
}

// ─── CSV Export Utility ───────────────────────────────────────────────────────

function downloadCsv(filename, headers, rows) {
    const escapeCsv = (str) => {
        if (str === null || str === undefined) return '""';
        const s = String(str).replace(/"/g, '""');
        return `"${s}"`;
    };
    const csvContent = '\uFEFF' + [
        headers.map(escapeCsv).join(','),
        ...rows.map(row => row.map(escapeCsv).join(','))
    ].join('\r\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

async function exportBooksCsv() {
    toast('Preparing book inventory report...', 'info');
    try {
        const res = await api('api/books.php?limit=5000');
        const books = res.data || [];
        const headers = ['Book ID', 'ISBN', 'Title', 'Author', 'Category', 'Classification', 'Publisher', 'Publication Year', 'Total Copies', 'Available Copies'];
        const rows = books.map(b => [
            `B${String(b.book_id).padStart(3, '0')}`,
            b.isbn || '',
            b.title || '',
            b.author || '',
            b.category_name || 'No Category',
            b.category_type || 'Academic',
            b.publisher || '',
            b.publication_year || '',
            b.total_copies || 0,
            b.available_copies || 0
        ]);
        const dateStr = new Date().toISOString().slice(0, 10);
        downloadCsv(`library_books_inventory_${dateStr}.csv`, headers, rows);
        toast(`Exported ${books.length} books successfully!`, 'success');
    } catch (err) {
        toast('Failed to export books: ' + err.message, 'error');
    }
}

async function exportBorrowingCsv() {
    toast('Preparing borrowing records report...', 'info');
    try {
        const res = await api('api/borrowing.php?limit=5000');
        const records = res.data || [];
        const isStudent = (window.CURRENT_USER?.role || '').toLowerCase() === 'user';
        const headers = isStudent
            ? ['Borrow ID', 'Book / Copy', 'Borrow Date', 'Due Date', 'Return Date', 'Status']
            : ['Borrow ID', 'Student / User', 'Book / Copy', 'Borrow Date', 'Due Date', 'Return Date', 'Status'];
        const rows = records.map(r => {
            const row = [
                `BR${String(r.borrow_id).padStart(3, '0')}`,
                r.copy_label || r.barcode || '',
                r.borrow_date || '',
                r.due_date || '',
                r.return_date || '',
                r.status || ''
            ];
            if (!isStudent) {
                row.splice(1, 0, r.student_name || r.full_name || '—');
            }
            return row;
        });
        const dateStr = new Date().toISOString().slice(0, 10);
        downloadCsv(`borrowing_records_${dateStr}.csv`, headers, rows);
        toast(`Exported ${records.length} borrowing records!`, 'success');
    } catch (err) {
        toast('Failed to export borrowing records: ' + err.message, 'error');
    }
}

function setLoading(el, loading) {
    if (loading) {
        el.innerHTML = `<tr><td colspan="20" class="text-center py-10">
            <div class="flex flex-col items-center gap-2 text-slate-400">
                <div class="spinner"></div><span class="text-sm">Loading...</span>
            </div></td></tr>`;
    }
}

function emptyRow(colspan, msg = 'No records found') {
    return `<tr><td colspan="${colspan}" class="text-center py-10 text-slate-400">
        <i class="fa-regular fa-folder-open text-2xl mb-2 block"></i>${msg}</td></tr>`;
}

// ─── Navigation ───────────────────────────────────────────────────────────────

function navigateTo(page) {
    const role = (window.CURRENT_USER?.role || '').toLowerCase();
    const allowedRoles = {
        book_copies: ['admin', 'librarian'],
        borrowing: ['admin', 'librarian', 'user'],
        categories: ['admin', 'librarian'],
        courses: ['admin'],
        profile: ['admin', 'librarian', 'user'],
        reservations: ['admin', 'librarian', 'user'],
        students: ['admin', 'librarian'],
        users: ['admin'],
    };
    if (allowedRoles[page] && !allowedRoles[page].includes(role)) {
        toast('You are not authorized to view this section.', 'error');
        return;
    }

    State.currentPage = page;
    document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
    const navEl = document.getElementById(`nav-${page}`);
    if (navEl) navEl.classList.add('active');

    const pc = document.getElementById('page-content');
    pc.innerHTML = `<div class="flex items-center justify-center h-64"><div class="spinner"></div></div>`;

    const pages = {
        dashboard: renderDashboard,
        books: renderBooks,
        book_copies: renderBookCopies,
        borrowing: renderBorrowing,
        reservations: renderReservations,
        students: renderStudents,
        users: renderUsers,
        profile: renderProfile,
        courses: renderCourses,
        categories: renderCategories,
    };

    if (pages[page]) pages[page]();
}

window.navigate = navigateTo;
window.navigateTo = navigateTo;
window.addCopy = (...args) => addCopyModal(...args);

// ─── DASHBOARD DISPATCHER ─────────────────────────────────────────────────────

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function renderDashboard() {
    const data = await api('api/dashboard.php');
    const role = (window.CURRENT_USER?.role || data.role || 'User').toLowerCase();

    // Show overdue badge in top header if applicable
    const ob = document.getElementById('overdue-badge');
    const ovCount = (data.stats && (data.stats.my_overdue || data.stats.overdue)) || 0;
    if (ovCount > 0) {
        ob.textContent = ovCount;
        ob.classList.remove('hidden');
    } else {
        ob.classList.add('hidden');
    }

    if (role === 'user' || role === 'student') {
        renderStudentDashboard(data);
    } else if (role === 'librarian') {
        renderLibrarianDashboard(data);
    } else {
        renderAdminDashboard(data);
    }
}

// ─── 1. STUDENT DASHBOARD (Matches Section 5 Student Portal) ─────────────────

function renderStudentDashboard(data) {
    const s = data.stats || {};
    const myBorrowings = data.my_borrowings || [];
    const myReservations = data.my_reservations || [];
    const featuredBooks = data.featured_books || [];

    const borrowingRows = myBorrowings.map((b, i) => {
        const isOverdue = b.days_left < 0 && (b.status === 'Borrowed' || b.status === 'Overdue');
        const dueText = isOverdue 
            ? `<span class="text-rose-600 font-bold">${fmt(b.due_date)} (${Math.abs(b.days_left)}d overdue)</span>`
            : `${fmt(b.due_date)} <span class="text-slate-400 text-xs">(${b.days_left}d left)</span>`;
        return `
        <tr>
            <td class="font-bold text-slate-500">${i + 1}</td>
            <td class="font-semibold text-slate-800">${escapeHtml(b.book_title)}</td>
            <td class="text-slate-500">${fmt(b.borrow_date)}</td>
            <td>${dueText}</td>
            <td class="text-slate-600 font-medium">${b.return_date ? fmt(b.return_date) : '<span class="text-slate-400 text-xs">—</span>'}</td>
            <td>${badge(b.status)}</td>
        </tr>`;
    }).join('') || emptyRow(6, 'You currently have no borrowed books.');

    const reservationRows = myReservations.map((r, i) => `
        <tr>
            <td class="font-bold text-slate-500">${i + 1}</td>
            <td class="font-semibold text-slate-800">
                ${escapeHtml(r.book_title)}
                ${r.reserved_barcode ? `<div class="text-[10px] text-slate-400 font-normal"><i class="fa-solid fa-barcode mr-0.5"></i>Copy: <span class="font-mono text-blue-600 font-semibold">${escapeHtml(r.reserved_barcode)}</span></div>` : ''}
            </td>
            <td class="text-slate-500">${fmt(r.reservation_date)}</td>
            <td>${badge(r.status)}</td>
            <td class="text-center">
                ${r.status === 'Pending' || r.status === 'Approved' ? `
                    <button onclick="cancelMyReservation(${r.reservation_id})" class="text-xs text-rose-600 hover:text-rose-800 font-semibold px-2 py-0.5 rounded border border-rose-200 hover:bg-rose-50 transition-colors">
                        Cancel
                    </button>
                ` : '<span class="text-slate-400 text-xs">—</span>'}
            </td>
        </tr>
    `).join('') || emptyRow(5, 'You have no active reservations.');

    const featuredCards = featuredBooks.map(b => {
        const isAvail = b.available_copies > 0;
        const words = (b.title || '').trim().split(' ');
        const initials = ((words[0]?.[0] || 'B') + (words[1]?.[0] || '')).toUpperCase();
        return `
        <div class="border border-slate-200 rounded-xl p-3.5 bg-white hover:shadow-md transition-shadow flex flex-col justify-between">
            <div>
                <div class="flex items-start justify-between gap-2 mb-2.5">
                    <div class="w-11 h-14 bg-gradient-to-br from-blue-600 to-indigo-700 text-white rounded-md overflow-hidden flex items-center justify-center shrink-0 shadow-xs relative">
                        <img src="https://covers.openlibrary.org/b/isbn/${escapeHtml(b.isbn)}-M.jpg?default=false"
                             alt="${escapeHtml(b.title)}"
                             class="w-full h-full object-cover relative z-10"
                             onerror="this.remove()" />
                        <span class="font-bold text-xs absolute z-0">${initials}</span>
                    </div>
                    <span class="badge ${isAvail ? 'badge-available' : 'badge-borrowed'}">
                        ${isAvail ? `${b.available_copies} Available` : 'All Borrowed'}
                    </span>
                </div>
                <h4 class="font-bold text-slate-900 text-sm leading-snug line-clamp-1" title="${escapeHtml(b.title)}">${escapeHtml(b.title)}</h4>
                <p class="text-slate-500 text-xs mb-1 line-clamp-1">${escapeHtml(b.author)}</p>
                <span class="inline-block bg-slate-100 text-slate-600 text-[10px] px-2 py-0.5 rounded font-medium">
                    ${escapeHtml(b.category_name || 'General')}
                </span>
            </div>
            <div class="mt-3 pt-2.5 border-t border-slate-100 flex items-center justify-between gap-2">
                <button onclick="viewBook(${b.book_id})" class="text-xs text-slate-500 hover:text-slate-800 font-semibold">
                    <i class="fa-regular fa-eye mr-1"></i> Details
                </button>
                <button onclick="studentReserveBook(${b.book_id}, '${escapeHtml(b.title).replace(/'/g, "\\'")}')" 
                    class="text-xs bg-brand-500 hover:bg-brand-600 text-white font-bold px-3 py-1.5 rounded-lg transition-colors flex items-center gap-1 shadow-xs">
                    <i class="fa-regular fa-bookmark"></i> Reserve
                </button>
            </div>
        </div>`;
    }).join('') || '<div class="col-span-3 text-center py-6 text-slate-400">No books currently in catalog.</div>';

    document.getElementById('page-content').innerHTML = `
    <!-- Student Welcome Banner -->
    <div class="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-2xl p-6 text-white mb-6 shadow-md relative overflow-hidden">
        <div class="relative z-10 max-w-xl">
            <span class="bg-white/20 text-blue-100 text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wider inline-block mb-2">
                <i class="fa-solid fa-graduation-cap mr-1"></i> Student Portal
            </span>
            <h1 class="text-2xl font-black mb-1">Welcome back, ${escapeHtml(window.CURRENT_USER?.full_name || 'Student')}!</h1>
            <p class="text-blue-100 text-sm">Search and browse books, track your borrowing records, and reserve books easily.</p>
            <div class="mt-4 flex flex-wrap gap-2.5">
                <button onclick="navigateTo('books')" class="bg-white text-blue-700 hover:bg-blue-50 font-bold text-xs px-4 py-2 rounded-xl transition-colors shadow-sm flex items-center gap-1.5">
                    <i class="fa-solid fa-magnifying-glass"></i> Browse All Books
                </button>
                <button onclick="navigateTo('borrowing')" class="bg-blue-800/60 hover:bg-blue-800 text-white border border-white/20 font-bold text-xs px-4 py-2 rounded-xl transition-colors flex items-center gap-1.5">
                    <i class="fa-solid fa-clock-rotate-left"></i> My Borrowing History
                </button>
            </div>
        </div>
        <div class="absolute -right-6 -bottom-6 text-white/10 text-9xl pointer-events-none">
            <i class="fa-solid fa-book-open-reader"></i>
        </div>
    </div>

    <!-- Student 4 Quick Stats -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6 stats-grid">
        <div class="stat-card cursor-pointer hover:border-blue-300 transition-colors" onclick="navigateTo('borrowing')">
            <div class="stat-icon bg-blue-50 text-blue-600"><i class="fa-solid fa-book-bookmark"></i></div>
            <div>
                <div class="text-xs text-slate-500 font-semibold flex items-center gap-1.5">
                    <span>Active Loans</span>
                    <span class="text-[9px] bg-blue-100 text-blue-700 px-1 py-0.2 rounded font-bold">Quota: ${s.borrow_quota || 3}</span>
                </div>
                <div class="text-2xl font-black text-slate-800">${s.my_borrowed || 0} <span class="text-xs text-slate-400 font-semibold">/ ${s.borrow_quota || 3}</span></div>
            </div>
        </div>
        <div class="stat-card ${s.my_overdue > 0 ? 'border-rose-300 bg-rose-50/30' : ''}">
            <div class="stat-icon bg-rose-50 text-rose-600"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="text-xs text-slate-500 font-semibold">Overdue Books</div>
                <div class="text-2xl font-black ${s.my_overdue > 0 ? 'text-rose-600' : 'text-slate-800'}">${s.my_overdue || 0}</div>
            </div>
        </div>
        <div class="stat-card cursor-pointer hover:border-purple-300 transition-colors" onclick="navigateTo('reservations')">
            <div class="stat-icon bg-purple-50 text-purple-600"><i class="fa-solid fa-bookmark"></i></div>
            <div>
                <div class="text-xs text-slate-500 font-semibold">Active Reservations</div>
                <div class="text-2xl font-black text-slate-800">${s.my_reservations || 0}</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-emerald-50 text-emerald-600"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="text-xs text-slate-500 font-semibold">Books Returned / Read</div>
                <div class="text-2xl font-black text-slate-800">${s.my_returned || 0}</div>
            </div>
        </div>
    </div>

    <!-- Overdue Warning Alert -->
    ${s.my_overdue > 0 ? `
    <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 mb-6 flex items-center justify-between gap-3 shadow-2xs">
        <div class="flex items-center gap-3">
            <i class="fa-solid fa-circle-exclamation text-rose-600 text-xl"></i>
            <div>
                <div class="font-bold text-rose-800 text-sm">Action Required: Overdue Books</div>
                <div class="text-xs text-rose-600">You have ${s.my_overdue} book(s) past their return due date. Please return them to the library counter as soon as possible.</div>
            </div>
        </div>
        <button onclick="navigateTo('borrowing')" class="text-xs bg-rose-600 text-white font-bold px-3 py-1.5 rounded-lg hover:bg-rose-700 transition-colors shrink-0">
            View Details
        </button>
    </div>` : ''}

    <!-- Due Soon Reminder Alert (≤ 48 hours) -->
    ${(s.my_due_soon > 0 && !(s.my_overdue > 0)) ? `
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6 flex items-center justify-between gap-3 shadow-2xs">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-amber-500 text-white flex items-center justify-center shrink-0">
                <i class="fa-solid fa-clock"></i>
            </div>
            <div>
                <div class="font-bold text-amber-900 text-sm">Due Date Reminder: Book(s) Due in ≤ 48 Hours</div>
                <div class="text-xs text-amber-700">You have ${s.my_due_soon} borrowed book(s) due within 2 days. Please return or renew them to keep your library account in good standing.</div>
            </div>
        </div>
        <button onclick="navigateTo('borrowing')" class="text-xs bg-amber-600 hover:bg-amber-700 text-white font-bold px-3 py-1.5 rounded-lg transition-colors shrink-0">
            View Due Dates
        </button>
    </div>` : ''}

    <!-- Featured Books Section (from GUI Section 5) -->
    <div class="section-card p-5 mb-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="font-bold text-slate-900 text-base flex items-center gap-2">
                    <i class="fa-solid fa-star text-amber-500"></i> Featured & Available Books
                </h3>
                <p class="text-xs text-slate-500">Search and reserve copies directly from the library collection.</p>
            </div>
            <button onclick="navigateTo('books')" class="text-blue-600 font-bold text-xs hover:underline flex items-center gap-1">
                Browse Full Catalog <i class="fa-solid fa-arrow-right text-[10px]"></i>
            </button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5">
            ${featuredCards}
        </div>
    </div>

    <!-- Two-column: My Loans & My Reservations -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <!-- My Borrowings -->
        <div class="section-card overflow-hidden">
            <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between">
                <span class="font-bold text-slate-800 text-sm flex items-center gap-2">
                    <i class="fa-solid fa-book-open text-blue-600"></i> My Current Loans
                </span>
                <button onclick="navigateTo('borrowing')" class="text-blue-600 font-semibold text-xs hover:underline">View History →</button>
            </div>
            <div class="overflow-x-auto table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>#</th><th>Book Title</th><th>Borrow Date</th><th>Due Date</th><th>Returned Date</th><th>Status</th>
                    </tr></thead>
                    <tbody>${borrowingRows}</tbody>
                </table>
            </div>
        </div>

        <!-- My Reservations -->
        <div class="section-card overflow-hidden">
            <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between">
                <span class="font-bold text-slate-800 text-sm flex items-center gap-2">
                    <i class="fa-solid fa-bookmark text-purple-600"></i> My Book Reservations
                </span>
                <button onclick="navigateTo('reservations')" class="text-blue-600 font-semibold text-xs hover:underline">Manage →</button>
            </div>
            <div class="overflow-x-auto table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>#</th><th>Book Title</th><th>Reserved Date</th><th>Status</th><th class="text-center">Action</th>
                    </tr></thead>
                    <tbody>${reservationRows}</tbody>
                </table>
            </div>
        </div>
    </div>`;
}

// ─── 2. LIBRARIAN DASHBOARD (Matches Section 2 & 4 of GUI Design) ────────────

function renderLibrarianDashboard(data) {
    const s = data.stats || {};
    const recentActivity = data.recent_activity || [];
    const pendingReservations = data.pending_reservations_list || [];

    const activityRows = recentActivity.map((r, i) => `
        <tr>
            <td class="font-bold text-slate-600">${i + 1}</td>
            <td class="font-semibold text-slate-800">${escapeHtml(r.student_name || r.full_name || '—')}</td>
            <td>${escapeHtml(r.book_title)}</td>
            <td class="text-slate-500">${fmt(r.borrow_date)}</td>
            <td class="text-slate-500">${fmt(r.due_date)}</td>
            <td class="text-slate-600 font-medium">${r.return_date ? fmt(r.return_date) : '<span class="text-slate-400 text-xs">—</span>'}</td>
            <td>${badge(r.status)}</td>
            <td class="text-center">
                ${r.status !== 'Returned' ? `
                    <button onclick="returnBook(${r.borrow_id})" title="Record Return" class="text-emerald-600 hover:text-emerald-800 font-semibold text-xs px-2 py-0.5 rounded border border-emerald-200 hover:bg-emerald-50">
                        <i class="fa-solid fa-arrow-rotate-left mr-1"></i> Return
                    </button>
                ` : '<span class="text-emerald-600 text-xs font-semibold"><i class="fa-solid fa-check mr-0.5"></i> Returned</span>'}
            </td>
        </tr>`).join('') || emptyRow(8, 'No recent borrowing activity');

    const pendingRows = pendingReservations.map((r, i) => `
        <tr>
            <td class="font-bold text-slate-600">${i + 1}</td>
            <td class="font-semibold text-slate-800">${escapeHtml(r.student_name || r.full_name || '—')}</td>
            <td>${escapeHtml(r.book_title)}</td>
            <td class="text-slate-500">${fmt(r.reservation_date)}</td>
            <td><span class="badge badge-pending">Pending</span></td>
            <td class="text-center space-x-1">
                <button onclick="updateReservationStatus(${r.reservation_id}, 'Approved')" title="Approve" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-bold text-[10px] px-2 py-0.5 rounded border border-emerald-200">
                    Approve
                </button>
                <button onclick="updateReservationStatus(${r.reservation_id}, 'Cancelled')" title="Cancel" class="bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-[10px] px-2 py-0.5 rounded border border-rose-200">
                    Cancel
                </button>
            </td>
        </tr>`).join('') || emptyRow(6, 'No pending reservations awaiting approval.');

    document.getElementById('page-content').innerHTML = `
    <!-- Librarian Welcome Banner -->
    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-100 rounded-xl p-4 mb-5 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-xl font-black text-slate-900 leading-tight">Welcome back, Librarian!</h1>
            <p class="text-xs text-slate-500 mt-0.5">Here's what's happening with your library operations today.</p>
        </div>
        <!-- Quick Action Buttons -->
        <div class="flex flex-wrap items-center gap-2">
            <button onclick="issueBook()" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs px-3 py-1.5 rounded-lg flex items-center gap-1.5 shadow-sm transition-colors">
                <i class="fa-solid fa-plus"></i> Issue Book
            </button>
            <button onclick="addBook()" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 font-bold text-xs px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-colors">
                <i class="fa-solid fa-book"></i> Add Book
            </button>
            <button onclick="addCopy()" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 font-bold text-xs px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-colors">
                <i class="fa-solid fa-layer-group"></i> Add Copy
            </button>
            <button onclick="addStudent()" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 font-bold text-xs px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-colors">
                <i class="fa-solid fa-user-plus"></i> Add Student
            </button>
        </div>
    </div>

    <!-- 5 Stat Badges (Matches Section 2 of GUI Design) -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3.5 mb-5">
        <div class="stat-card">
            <div class="stat-icon bg-blue-50 text-blue-600"><i class="fa-solid fa-book"></i></div>
            <div><div class="text-[11px] text-slate-500 font-semibold">Total Books</div>
            <div class="text-2xl font-black text-slate-800">${s.total_books.toLocaleString()}</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-emerald-50 text-emerald-600"><i class="fa-solid fa-book-open"></i></div>
            <div><div class="text-[11px] text-slate-500 font-semibold">Available Copies</div>
            <div class="text-2xl font-black text-slate-800">${s.available_copies.toLocaleString()}</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-purple-50 text-purple-600"><i class="fa-solid fa-user-graduate"></i></div>
            <div><div class="text-[11px] text-slate-500 font-semibold">Registered Students</div>
            <div class="text-2xl font-black text-slate-800">${s.total_students.toLocaleString()}</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber-50 text-amber-600"><i class="fa-solid fa-hand-holding-hand"></i></div>
            <div><div class="text-[11px] text-slate-500 font-semibold">Active Borrowings</div>
            <div class="text-2xl font-black text-slate-800">${s.active_borrowings.toLocaleString()}</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-indigo-50 text-indigo-600"><i class="fa-solid fa-clock"></i></div>
            <div><div class="text-[11px] text-slate-500 font-semibold">Pending Reservations</div>
            <div class="text-2xl font-black text-slate-800">${s.pending_reservations.toLocaleString()}</div></div>
        </div>
    </div>

    ${s.overdue > 0 ? `
    <div class="bg-rose-50 border border-rose-200 rounded-xl p-3.5 mb-5 flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <i class="fa-solid fa-triangle-exclamation text-rose-500 text-lg"></i>
            <div>
                <div class="font-bold text-rose-700 text-sm">Overdue Alert</div>
                <div class="text-xs text-rose-600">${s.overdue} borrowing record(s) are past their due date.</div>
            </div>
        </div>
        <button onclick="navigateTo('borrowing')" class="text-xs bg-rose-500 text-white font-bold px-3 py-1.5 rounded-lg hover:bg-rose-600 transition-colors">
            View Overdue Records
        </button>
    </div>` : ''}

    <!-- Two-column tables for Librarian -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
        <!-- Pending Reservations to Review -->
        <div class="section-card overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 flex justify-between items-center">
                <span class="font-bold text-slate-800 text-sm flex items-center gap-1.5">
                    <i class="fa-solid fa-bookmark text-blue-600"></i> Pending Reservations
                </span>
                <button onclick="navigateTo('reservations')" class="text-blue-600 font-semibold text-xs hover:underline">View All →</button>
            </div>
            <div class="overflow-x-auto table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>#</th><th>Student/User</th><th>Book Title</th><th>Date</th><th>Status</th><th class="text-center">Action</th>
                    </tr></thead>
                    <tbody>${pendingRows}</tbody>
                </table>
            </div>
        </div>

        <!-- Recent Borrowing Activity Table (from GUI Section 2) -->
        <div class="section-card overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 flex justify-between items-center">
                <span class="font-bold text-slate-800 text-sm flex items-center gap-1.5">
                    <i class="fa-solid fa-clock-rotate-left text-blue-600"></i> Recent Borrowing Activity
                </span>
                <button onclick="navigateTo('borrowing')" class="text-blue-600 font-semibold text-xs hover:underline">View All →</button>
            </div>
            <div class="overflow-x-auto table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>#</th><th>Student/User</th><th>Book Title</th><th>Borrow Date</th><th>Due Date</th><th>Returned Date</th><th>Status</th><th class="text-center">Action</th>
                    </tr></thead>
                    <tbody>${activityRows}</tbody>
                </table>
            </div>
        </div>
    </div>`;
}

// ─── 3. ADMINISTRATOR DASHBOARD (Matches Section 5 of GUI Design) ────────────

function renderAdminDashboard(data) {
    const s = data.stats || {};
    const recentUsers = data.recent_users || [];
    const recentStudents = data.recent_students || [];
    const recentActivity = data.recent_activity || [];
    const courses = data.courses || [];
    const categories = data.categories || [];

    const adminActivityRows = recentActivity.map((r, i) => `
        <tr>
            <td class="font-bold text-slate-600">${i + 1}</td>
            <td class="font-semibold text-slate-800">${escapeHtml(r.student_name || r.full_name || '—')}</td>
            <td>${escapeHtml(r.book_title)}</td>
            <td class="text-slate-500">${fmt(r.borrow_date)}</td>
            <td class="text-slate-500">${fmt(r.due_date)}</td>
            <td class="text-slate-600 font-medium">${r.return_date ? fmt(r.return_date) : '<span class="text-slate-400 text-xs">—</span>'}</td>
            <td>${badge(r.status)}</td>
        </tr>`).join('') || emptyRow(7, 'No recent borrowing activity');

    const userRows = recentUsers.map(u => `
        <tr>
            <td class="font-semibold text-slate-700">U${String(u.user_id).padStart(3,'0')}</td>
            <td class="font-semibold text-slate-800">${escapeHtml(u.username)}</td>
            <td><span class="badge ${u.role === 'Admin' ? 'badge-reserved' : u.role === 'Librarian' ? 'badge-returned' : 'badge-active'}">${u.role === 'User' ? 'Student' : u.role}</span></td>
            <td>${badge(Number(u.is_active) === 1 ? 'Active' : 'Inactive')}</td>
        </tr>`).join('') || emptyRow(4, 'No users found');

    const studentRows = recentStudents.map(s => `
        <tr>
            <td class="font-semibold text-slate-700">S${String(s.id).padStart(3,'0')}</td>
            <td class="font-semibold text-slate-800">${escapeHtml(s.name)}</td>
            <td>${escapeHtml(s.course_code || '—')}</td>
            <td>Yr ${s.year_level}</td>
        </tr>`).join('') || emptyRow(4, 'No students found');

    const courseRows = courses.map(c => `
        <tr><td>C${String(c.id).padStart(3,'0')}</td><td class="font-semibold">${escapeHtml(c.course_code)}</td></tr>`).join('') || emptyRow(2);

    const categoryRows = categories.map(c => `
        <tr><td>CAT${String(c.category_id).padStart(3,'0')}</td><td class="font-semibold">${escapeHtml(c.category_name)}</td></tr>`).join('') || emptyRow(2);

    document.getElementById('page-content').innerHTML = `
    <!-- Admin Welcome Banner -->
    <div class="bg-gradient-to-r from-slate-900 via-navy-900 to-blue-900 rounded-xl p-5 text-white mb-5 shadow-sm flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <div class="inline-flex items-center gap-1.5 bg-blue-500/20 text-blue-300 text-xs font-bold px-2.5 py-0.5 rounded uppercase tracking-wider mb-1">
                <i class="fa-solid fa-shield-halved"></i> Administrator Control Center
            </div>
            <h1 class="text-xl font-black">Welcome back, Administrator!</h1>
            <p class="text-slate-300 text-xs">Full system management, user administration, and institutional records.</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="navigateTo('users')" class="bg-white/10 hover:bg-white/20 text-white border border-white/20 font-bold text-xs px-3.5 py-2 rounded-lg transition-colors flex items-center gap-1.5 shadow-sm">
                <i class="fa-solid fa-user-gear"></i> Librarian Management
            </button>
            <button onclick="addStudent()" class="bg-white/10 hover:bg-white/20 text-white border border-white/20 font-bold text-xs px-3.5 py-2 rounded-lg transition-colors flex items-center gap-1.5">
                <i class="fa-solid fa-plus"></i> Add Student
            </button>
        </div>
    </div>

    <!-- Admin Top Stat Cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-5">
        <div class="stat-card">
            <div class="stat-icon bg-violet-50 text-violet-600"><i class="fa-solid fa-users"></i></div>
            <div><div class="text-[10px] text-slate-500 font-semibold uppercase">Total Users</div>
            <div class="text-xl font-black text-slate-800">${s.total_users || 0}</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-blue-50 text-blue-600"><i class="fa-solid fa-user-graduate"></i></div>
            <div><div class="text-[10px] text-slate-500 font-semibold uppercase">Students</div>
            <div class="text-xl font-black text-slate-800">${s.total_students || 0}</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-emerald-50 text-emerald-600"><i class="fa-solid fa-book"></i></div>
            <div><div class="text-[10px] text-slate-500 font-semibold uppercase">Book Titles</div>
            <div class="text-xl font-black text-slate-800">${s.total_books || 0}</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-teal-50 text-teal-600"><i class="fa-solid fa-layer-group"></i></div>
            <div><div class="text-[10px] text-slate-500 font-semibold uppercase">Total Copies</div>
            <div class="text-xl font-black text-slate-800">${s.total_copies || 0}</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber-50 text-amber-600"><i class="fa-solid fa-hand-holding-hand"></i></div>
            <div><div class="text-[10px] text-slate-500 font-semibold uppercase">Active Loans</div>
            <div class="text-xl font-black text-slate-800">${s.active_borrowings || 0}</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-indigo-50 text-indigo-600"><i class="fa-solid fa-graduation-cap"></i></div>
            <div><div class="text-[10px] text-slate-500 font-semibold uppercase">Courses</div>
            <div class="text-xl font-black text-slate-800">${s.total_courses || 0}</div></div>
        </div>
    </div>

    <!-- Section 5: Administrator Portal Cards Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
        <!-- Student Management (from GUI Section 5) -->
        <div class="section-card p-3 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <h4 class="font-bold text-slate-800 text-xs flex items-center gap-1.5">
                    <i class="fa-solid fa-user-graduate text-blue-600"></i> Student Management
                </h4>
                <div class="flex gap-1">
                    <button onclick="addStudent()" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-[9px] px-2 py-0.5 rounded shadow-2xs">
                        + Add Student
                    </button>
                    <button onclick="navigateTo('students')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-[9px] px-2 py-0.5 rounded border border-slate-200">
                        View All
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto table-wrap">
                <table class="ui-table">
                    <thead><tr><th>Student ID</th><th>Full Name</th><th>Course</th><th>Year</th></tr></thead>
                    <tbody>${studentRows}</tbody>
                </table>
            </div>
        </div>

        <!-- User Management (Admin Only) (from GUI Section 5) -->
        <div class="section-card p-3 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <h4 class="font-bold text-slate-800 text-xs flex items-center gap-1.5">
                    <i class="fa-solid fa-user-gear text-purple-600"></i> Librarian Management <span class="text-[9px] text-slate-400 font-normal">(Admin Only)</span>
                </h4>
                <div class="flex gap-1">
                    <button onclick="addUserModal()" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-[9px] px-2 py-0.5 rounded shadow-2xs">
                        + Add Librarian
                    </button>
                    <button onclick="navigateTo('users')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-[9px] px-2 py-0.5 rounded border border-slate-200">
                        Manage Users
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto table-wrap">
                <table class="ui-table">
                    <thead><tr><th>User ID</th><th>Username</th><th>Role</th><th>Status</th></tr></thead>
                    <tbody>${userRows}</tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Borrowing Activity Table (Admin View) -->
    <div class="section-card overflow-hidden mb-4">
        <div class="px-5 py-3 border-b border-slate-100 flex justify-between items-center">
            <span class="font-bold text-slate-800 text-sm flex items-center gap-1.5">
                <i class="fa-solid fa-clock-rotate-left text-blue-600"></i> Recent Borrowing Activity
            </span>
            <button onclick="navigateTo('borrowing')" class="text-blue-600 font-semibold text-xs hover:underline">View All Records →</button>
        </div>
        <div class="overflow-x-auto table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>#</th><th>Student/User</th><th>Book Title</th><th>Borrow Date</th><th>Due Date</th><th>Returned Date</th><th>Status</th>
                </tr></thead>
                <tbody>${adminActivityRows}</tbody>
            </table>
        </div>
    </div>

    <!-- Courses & Categories Overview Card (from GUI Section 5) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="section-card p-3 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <h4 class="font-bold text-slate-800 text-xs flex items-center gap-1.5">
                    <i class="fa-solid fa-folder-tree text-blue-600"></i> Courses & Academic Programs
                </h4>
                <button onclick="navigateTo('courses')" class="text-blue-600 font-bold text-[10px] hover:underline">Manage Courses →</button>
            </div>
            <div class="overflow-x-auto table-wrap">
                <table class="ui-table">
                    <thead><tr><th>Course ID</th><th>Course Code</th></tr></thead>
                    <tbody>${courseRows}</tbody>
                </table>
            </div>
        </div>

        <div class="section-card p-3 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <h4 class="font-bold text-slate-800 text-xs flex items-center gap-1.5">
                    <i class="fa-solid fa-tags text-emerald-600"></i> Book Categories
                </h4>
                <button onclick="navigateTo('categories')" class="text-blue-600 font-bold text-[10px] hover:underline">Manage Categories →</button>
            </div>
            <div class="overflow-x-auto table-wrap">
                <table class="ui-table">
                    <thead><tr><th>Category ID</th><th>Category Name</th></tr></thead>
                    <tbody>${categoryRows}</tbody>
                </table>
            </div>
        </div>
    </div>`;
}

// ─── STUDENT ACTIONS ──────────────────────────────────────────────────────────

async function studentReserveBook(bookId, bookTitle) {
    if (!confirm(`Do you want to reserve "${bookTitle}"?`)) return;
    try {
        await api('api/reservations.php', 'POST', {
            book_id: bookId,
            user_id: window.CURRENT_USER?.id
        });
        toast(`Reservation for "${bookTitle}" submitted successfully! 🎉`, 'success');
        if (State.currentPage === 'dashboard') {
            renderDashboard();
        } else if (State.currentPage === 'reservations') {
            renderReservations();
        }
    } catch(err) {
        toast(err.message, 'error');
    }
}

async function cancelMyReservation(reservationId) {
    if (!confirm('Cancel this reservation?')) return;
    try {
        await api(`api/reservations.php?id=${reservationId}`, 'PUT', { status: 'Cancelled' });
        toast('Reservation cancelled.');
        if (State.currentPage === 'dashboard') renderDashboard();
        else renderReservations();
    } catch(err) {
        toast(err.message, 'error');
    }
}

async function updateReservationStatus(reservationId, newStatus) {
    try {
        await api(`api/reservations.php?id=${reservationId}`, 'PUT', { status: newStatus });
        toast(`Reservation marked as ${newStatus}.`);
        if (State.currentPage === 'dashboard') renderDashboard();
        else renderReservations();
    } catch(err) {
        toast(err.message, 'error');
    }
}

// ─── Category Grouping Helper ─────────────────────────────────────────────────
function buildGroupedCategoryOptions(cats, selectedId = null) {
    const academic = (cats || []).filter(c => (c.category_type || 'Academic') === 'Academic');
    const nonAcademic = (cats || []).filter(c => c.category_type === 'Non-Academic');

    let html = '';
    if (academic.length) {
        html += `<optgroup label="🎓 Academic Categories (${academic.length})">`;
        html += academic.map(c => `<option value="${c.category_id}" ${c.category_id == selectedId ? 'selected' : ''}>${escapeHtml(c.category_name)}</option>`).join('');
        html += `</optgroup>`;
    }
    if (nonAcademic.length) {
        html += `<optgroup label="📖 Non-Academic & General (${nonAcademic.length})">`;
        html += nonAcademic.map(c => `<option value="${c.category_id}" ${c.category_id == selectedId ? 'selected' : ''}>${escapeHtml(c.category_name)}</option>`).join('');
        html += `</optgroup>`;
    }
    return html;
}

// ─── BOOKS ────────────────────────────────────────────────────────────────────

async function renderBooks(page = 1, search = '', categoryId = '', availOnly = false) {
    State.pages.books = { page, search, categoryId, availOnly };
    const isStudent = (window.CURRENT_USER?.role || '').toLowerCase() === 'user';
    const cats = await api('api/categories.php');
    const availParam = availOnly ? '&available_only=1' : '';
    const data = await api(`api/books.php?page=${page}&search=${encodeURIComponent(search)}&category=${categoryId}${availParam}`);

    const catOptions = buildGroupedCategoryOptions(cats.data || [], categoryId);

    const rows = (data.data || []).map(b => {
        const studentAction = `
            <button onclick="viewBook(${b.book_id})" title="View Details" class="text-slate-400 hover:text-blue-600 transition-colors mr-2"><i class="fa-regular fa-eye"></i></button>
            ${b.available_copies > 0 
                ? `<button onclick="studentReserveBook(${b.book_id},'${b.title.replace(/'/g,"\\'")}')" class="text-[11px] bg-brand-500 hover:bg-brand-600 text-white font-bold px-2.5 py-1 rounded-md shadow-2xs transition-colors"><i class="fa-regular fa-bookmark mr-1"></i> Reserve</button>`
                : `<span class="text-[11px] text-slate-400 font-medium">All Borrowed</span>`
            }
        `;
        const adminAction = `
            <button onclick="viewBook(${b.book_id})" title="View" class="text-slate-400 hover:text-blue-600 transition-colors"><i class="fa-regular fa-eye"></i></button>
            <button onclick="editBook(${b.book_id})" title="Edit" class="text-slate-400 hover:text-amber-500 transition-colors"><i class="fa-regular fa-pen-to-square"></i></button>
            <button onclick="deleteBook(${b.book_id},'${b.title.replace(/'/g,"\\'")}') " title="Delete" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fa-regular fa-trash-can"></i></button>
        `;

        const isNonAcad = b.category_type === 'Non-Academic';

        return `
        <tr>
            <td class="font-bold text-blue-600">B${String(b.book_id).padStart(3,'0')}</td>
            <td class="font-semibold text-slate-800">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-11 bg-slate-200 rounded overflow-hidden flex items-center justify-center text-slate-400 shrink-0 text-[10px] relative shadow-2xs">
                        <img src="https://covers.openlibrary.org/b/isbn/${escapeHtml(b.isbn)}-S.jpg?default=false"
                             class="w-full h-full object-cover relative z-10"
                             onerror="this.remove()" />
                        <i class="fa-solid fa-book absolute z-0 text-slate-400"></i>
                    </div>
                    <div class="max-w-[200px] truncate" title="${escapeHtml(b.title)}">${escapeHtml(b.title)}</div>
                </div>
            </td>
            <td class="text-slate-600">${escapeHtml(b.author)}</td>
            <td>
                <div class="font-medium text-slate-800 text-xs">${escapeHtml(b.category_name || 'General')}</div>
                <span class="inline-flex items-center gap-1 text-[9px] px-1.5 py-0.5 rounded font-bold uppercase tracking-wider mt-0.5 ${isNonAcad ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-blue-50 text-blue-700 border border-blue-200'}">
                    <i class="fa-solid ${isNonAcad ? 'fa-book-open' : 'fa-graduation-cap'} text-[8px]"></i>
                    ${b.category_type || 'Academic'}
                </span>
            </td>
            <td class="font-mono text-xs text-slate-500">${escapeHtml(b.isbn)}</td>
            <td>${b.available_copies > 0 ? badge('Available') : badge('Borrowed')}</td>
            <td class="text-slate-500 text-xs">${b.total_copies || 0} / ${b.available_copies || 0}</td>
            <td class="text-center space-x-2">
                ${isStudent ? studentAction : adminAction}
            </td>
        </tr>`;
    }).join('') || emptyRow(8);

    const pagination = buildPagination(data.total, data.page, data.limit,
        `(p) => renderBooks(p, document.getElementById('book-search').value, document.getElementById('book-cat').value, document.getElementById('book-avail')?.checked)`);

    document.getElementById('page-content').innerHTML = `
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-xl font-black text-slate-900">${isStudent ? 'Browse Library Books' : 'Book Management'}</h1>
            <p class="text-sm text-slate-500">Total: <strong>${data.total}</strong> books ${availOnly ? 'currently available' : 'in catalog'}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <select id="book-cat" onchange="renderBooks(1,document.getElementById('book-search').value,this.value,document.getElementById('book-avail')?.checked)"
                class="form-input w-52 text-xs">
                <option value="">All Categories & Types</option>${catOptions}
            </select>
            <label class="flex items-center gap-1.5 text-xs text-slate-700 bg-white border border-slate-200 px-3 py-2 rounded-lg cursor-pointer hover:bg-slate-50 transition-colors select-none shadow-2xs">
                <input type="checkbox" id="book-avail" ${availOnly ? 'checked' : ''} onchange="renderBooks(1,document.getElementById('book-search').value,document.getElementById('book-cat').value,this.checked)" class="rounded text-blue-600 focus:ring-blue-500">
                <span class="font-semibold text-slate-700">Available Only</span>
            </label>
            <button onclick="exportBooksCsv()" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 font-bold text-xs px-3.5 py-2 rounded-lg flex items-center gap-1.5 shadow-2xs transition-colors cursor-pointer" title="Export book catalog to CSV">
                <i class="fa-solid fa-file-csv text-emerald-600 text-sm"></i> Export CSV
            </button>
            ${!isStudent ? `
            <button onclick="addBook()" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs px-3.5 py-2 rounded-lg flex items-center gap-1.5 transition-colors shadow-sm">
                <i class="fa-solid fa-plus"></i> Add Book
            </button>` : ''}
        </div>
    </div>

    <div class="section-card">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between gap-3">
            <div class="relative flex-1 max-w-xs">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input id="book-search" type="text" placeholder="Search title, author, or ISBN..." value="${search}"
                    class="form-input pl-8" oninput="debounce(() => renderBooks(1, this.value, document.getElementById('book-cat').value, document.getElementById('book-avail')?.checked), 400)()">
            </div>
            ${!isStudent ? `
            <button onclick="exportBooksCsv()" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 font-semibold text-xs px-3 py-1.5 rounded-lg flex items-center gap-1.5 shadow-sm transition-colors cursor-pointer shrink-0">
                <i class="fa-solid fa-file-csv text-emerald-600 text-sm"></i>
                <span>Export CSV</span>
            </button>` : ''}
        </div>
        <div class="overflow-x-auto table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>Book ID</th><th>Title</th><th>Author</th><th>Category</th>
                    <th>ISBN</th><th>Availability</th><th>Copies (Total/Avail)</th><th class="text-center">Actions</th>
                </tr></thead>
                <tbody id="books-tbody">${rows}</tbody>
            </table>
        </div>
        <div class="px-5 py-3 border-t border-slate-100 flex items-center justify-between">
            <span class="text-xs text-slate-500">Showing page ${data.page} of ${Math.ceil(data.total / data.limit) || 1}</span>
            ${pagination}
        </div>
    </div>`;
}

async function addBook() {
    const cats = await api('api/categories.php');
    const catOptions = buildGroupedCategoryOptions(cats.data || []);
    showModal(`
    <div class="modal-overlay">
      <div class="modal-box">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base">Add New Book</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form id="add-book-form" class="px-6 py-5 space-y-4" onsubmit="submitAddBook(event)">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="form-label">Title *</label>
                    <input name="title" required class="form-input" placeholder="Book title">
                </div>
                <div>
                    <label class="form-label">Author *</label>
                    <input name="author" required class="form-input" placeholder="Author name">
                </div>
                <div>
                    <label class="form-label">ISBN *</label>
                    <div class="relative">
                        <input name="isbn" id="add-book-isbn" required class="form-input pr-16" placeholder="e.g. 9780132350884">
                        <button type="button" onclick="lookupBookByIsbn(document.getElementById('add-book-isbn').value, 'add-book-form')" class="absolute right-1 top-1 bottom-1 px-2 bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-semibold rounded transition-colors" title="Auto-fill book details from online database">
                            Lookup
                        </button>
                    </div>
                </div>
                <div>
                    <label class="form-label">Publisher</label>
                    <input name="publisher" class="form-input" placeholder="Publisher">
                </div>
                <div>
                    <label class="form-label">Publication Year</label>
                    <input name="publication_year" type="number" min="1800" max="2099" class="form-input" placeholder="${new Date().getFullYear()}">
                </div>
                <div>
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-input"><option value="">-- No Category --</option>${catOptions}</select>
                </div>
                <div>
                    <label class="form-label">Initial Copies</label>
                    <input name="copies" type="number" min="0" value="1" class="form-input" placeholder="Number of copies to add">
                </div>
                <div class="col-span-2">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-input" rows="3" placeholder="Brief description..."></textarea>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-brand-500 text-white rounded-lg hover:bg-brand-600 transition-colors">
                    <i class="fa-solid fa-plus mr-1"></i> Add Book
                </button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitAddBook(e) {
    e.preventDefault();
    const form = e.target;
    const fd   = new FormData(form);
    const body = Object.fromEntries(fd.entries());
    const copies = parseInt(body.copies) || 0;
    delete body.copies;
    try {
        const res = await api('api/books.php', 'POST', body);
        // Add copies
        for (let i = 1; i <= copies; i++) {
            const barcode = `BC${String(res.book_id).padStart(3,'0')}-${String(i).padStart(2,'0')}`;
            await api('api/book_copies.php', 'POST', { book_id: res.book_id, barcode, status: 'Available' });
        }
        closeModal();
        toast('Book added successfully!');
        renderBooks();
    } catch(err) { toast(err.message, 'error'); }
}

async function editBook(bookId) {
    showModal(`
    <div class="modal-overlay">
      <div class="modal-box">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base">Edit Book #${bookId}</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <div class="px-6 py-5" id="edit-book-body">
            <div class="flex justify-center"><div class="spinner"></div></div>
        </div>
      </div>
    </div>`);

    try {
        const [bookRes, catsRes] = await Promise.all([
            api(`api/books.php?id=${bookId}`),
            api('api/categories.php')
        ]);
        const b = bookRes.data || {};
        const catOpts = buildGroupedCategoryOptions(catsRes.data || [], b.category_id);

        document.getElementById('edit-book-body').innerHTML = `
        <form id="edit-book-form" class="space-y-4" onsubmit="submitEditBook(event,${bookId})">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2"><label class="form-label">Title *</label>
                    <input name="title" required class="form-input" value="${escapeHtml(b.title || '')}"></div>
                <div><label class="form-label">Author *</label>
                    <input name="author" required class="form-input" value="${escapeHtml(b.author || '')}"></div>
                <div>
                    <label class="form-label">ISBN *</label>
                    <div class="relative">
                        <input name="isbn" id="edit-book-isbn" required class="form-input pr-16" value="${escapeHtml(b.isbn || '')}">
                        <button type="button" onclick="lookupBookByIsbn(document.getElementById('edit-book-isbn').value, 'edit-book-form')" class="absolute right-1 top-1 bottom-1 px-2 bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-semibold rounded transition-colors" title="Auto-fill book details from online database">
                            Lookup
                        </button>
                    </div>
                </div>
                <div><label class="form-label">Publisher</label>
                    <input name="publisher" class="form-input" value="${escapeHtml(b.publisher || '')}"></div>
                <div><label class="form-label">Publication Year</label>
                    <input name="publication_year" type="number" class="form-input" value="${b.publication_year || ''}"></div>
                <div><label class="form-label">Category</label>
                    <select name="category_id" class="form-input"><option value="">-- No Category --</option>${catOpts}</select></div>
                <div class="col-span-2"><label class="form-label">Description</label>
                    <textarea name="description" class="form-input" rows="3">${escapeHtml(b.description || '')}</textarea></div>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-amber-500 text-white rounded-lg hover:bg-amber-600 transition-colors">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Changes
                </button>
            </div>
        </form>`;
    } catch(err) {
        document.getElementById('edit-book-body').innerHTML = `<p class="text-rose-600 text-sm font-semibold">${escapeHtml(err.message)}</p>`;
    }
}

async function submitEditBook(e, bookId) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = Object.fromEntries(fd.entries());
    try {
        await api(`api/books.php?id=${bookId}`, 'PUT', body);
        closeModal();
        toast('Book updated successfully!');
        const s = State.pages.books || {};
        renderBooks(s.page, s.search, s.categoryId);
    } catch(err) { toast(err.message, 'error'); }
}

async function viewBook(bookId) {
    const isStaff = ['admin', 'librarian'].includes((window.CURRENT_USER?.role || '').toLowerCase());
    try {
        const [bookRes, copiesRes] = await Promise.all([
            api(`api/books.php?id=${bookId}`),
            isStaff ? api(`api/book_copies.php?book_id=${bookId}`) : Promise.resolve({ data: [], total: 0 })
        ]);
        const book = bookRes.data || {};
        const copies = copiesRes.data || [];

        let copiesSection = '';
        if (isStaff) {
            const copyRows = copies.map(c => `
                <tr>
                    <td class="font-mono text-xs">${escapeHtml(c.barcode)}</td>
                    <td>${badge(c.status)}</td>
                    <td class="text-center">
                        <button onclick="deleteCopy(${c.copy_id})" class="text-slate-400 hover:text-rose-500">
                            <i class="fa-regular fa-trash-can text-xs"></i>
                        </button>
                    </td>
                </tr>`).join('') || '<tr><td colspan="3" class="text-center py-4 text-slate-400 text-sm">No copies added yet.</td></tr>';

            copiesSection = `
            <div class="flex items-center justify-between mb-3">
                <h5 class="font-bold text-slate-800 text-sm">Book Copies (${copiesRes.total || 0})</h5>
                <button onclick="addCopyForBook(${bookId})" class="text-xs bg-brand-500 text-white font-bold px-3 py-1.5 rounded-lg">
                    <i class="fa-solid fa-plus mr-1"></i> Add Copy
                </button>
            </div>
            <div class="border border-slate-200 rounded-lg overflow-hidden">
                <table class="ui-table">
                    <thead><tr><th>Barcode</th><th>Status</th><th class="text-center">Del</th></tr></thead>
                    <tbody id="copies-tbody">${copyRows}</tbody>
                </table>
            </div>`;
        } else {
            // Student View: show copy summary
            copiesSection = `
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3.5 flex items-center justify-between">
                <div>
                    <div class="text-xs text-slate-500 font-medium">Availability Status</div>
                    <div class="font-bold text-slate-800 text-sm">
                        ${book.available_copies > 0 ? `${book.available_copies} of ${book.total_copies} copies available` : 'All copies are currently borrowed'}
                    </div>
                </div>
                ${book.available_copies > 0 ? `
                <button onclick="closeModal(); studentReserveBook(${bookId}, '${escapeHtml(book.title).replace(/'/g, "\\'")}')" class="text-xs bg-brand-500 hover:bg-brand-600 text-white font-bold px-3 py-1.5 rounded-lg shadow-2xs transition-colors">
                    <i class="fa-regular fa-bookmark mr-1"></i> Reserve Now
                </button>` : ''}
            </div>`;
        }

        showModal(`
        <div class="modal-overlay">
          <div class="modal-box max-w-xl">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
                <h3 class="font-bold text-slate-800 text-base">Book Details</h3>
                <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <div class="px-6 py-5">
                <div class="flex gap-4 mb-4">
                    <div class="w-16 h-22 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-lg overflow-hidden flex items-center justify-center text-white text-2xl shrink-0 shadow-sm relative">
                        <img src="https://covers.openlibrary.org/b/isbn/${escapeHtml(book?.isbn || '')}-M.jpg?default=false"
                             alt="${escapeHtml(book?.title || '')}"
                             class="w-full h-full object-cover relative z-10"
                             onerror="this.remove()" />
                        <i class="fa-solid fa-book absolute z-0 text-white/70"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-lg text-slate-900">${escapeHtml(book?.title || '—')}</h4>
                        <p class="text-slate-600 text-sm">${escapeHtml(book?.author || '—')}</p>
                        <p class="text-slate-400 text-xs mt-1">${escapeHtml(book?.publisher || '')} ${book?.publication_year ? '(' + book.publication_year + ')' : ''}</p>
                        <div class="mt-2 flex items-center gap-2">
                            <span class="text-xs bg-slate-100 px-2 py-0.5 rounded font-mono">${escapeHtml(book?.isbn || '—')}</span>
                            <span class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded font-semibold">${escapeHtml(book?.category_name || 'No Category')}</span>
                            <span class="text-[11px] px-2 py-0.5 rounded font-bold ${book?.category_type === 'Non-Academic' ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-indigo-50 text-indigo-700 border border-indigo-200'}">
                                <i class="fa-solid ${book?.category_type === 'Non-Academic' ? 'fa-book-open' : 'fa-graduation-cap'} text-[10px] mr-1"></i>${escapeHtml(book?.category_type || 'Academic')}
                            </span>
                        </div>
                    </div>
                </div>
                ${book?.description ? `<p class="text-sm text-slate-600 bg-slate-50 rounded-lg p-3 mb-4">${escapeHtml(book.description)}</p>` : ''}
                ${copiesSection}
            </div>
          </div>
        </div>`);
    } catch(err) { toast(err.message, 'error'); }
}

async function addCopyForBook(bookId) {
    const barcode = prompt('Enter barcode for new copy:', `BC${String(bookId).padStart(3,'0')}-${String(Date.now()).slice(-4)}`);
    if (!barcode) return;
    try {
        await api('api/book_copies.php', 'POST', { book_id: bookId, barcode, status: 'Available' });
        toast('Copy added!');
        viewBook(bookId);
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteCopy(copyId) {
    if (!confirm('Delete this copy?')) return;
    try {
        await api(`api/book_copies.php?id=${copyId}`, 'DELETE');
        toast('Copy deleted.');
        closeModal();
        const s = State.pages.books || {};
        renderBooks(s.page, s.search, s.categoryId);
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteBook(bookId, title) {
    if (!confirm(`Delete book "${title}"? This will also delete all copies and related records.`)) return;
    try {
        await api(`api/books.php?id=${bookId}`, 'DELETE');
        toast('Book deleted.');
        const s = State.pages.books || {};
        renderBooks(s.page, s.search, s.categoryId);
    } catch(err) { toast(err.message, 'error'); }
}

// ─── BOOK COPIES ──────────────────────────────────────────────────────────────

async function renderBookCopies(page = 1, search = '') {
    State.pages.book_copies = { page, search };
    try {
        const data = await api(`api/book_copies.php?page=${page}&search=${encodeURIComponent(search)}`);

        const rows = (data.data || []).map(c => `
            <tr>
                <td class="font-bold text-slate-600">C${String(c.copy_id).padStart(3,'0')}</td>
                <td class="font-semibold text-slate-800">${c.book_title}</td>
                <td class="text-slate-500">${c.author}</td>
                <td class="font-mono text-xs text-slate-500">${c.barcode}</td>
                <td>${badge(c.status)}</td>
                <td class="text-center space-x-2">
                    <button onclick="editCopyStatus(${c.copy_id}, '${c.status}')" title="Change Status" class="text-slate-400 hover:text-amber-500 transition-colors"><i class="fa-regular fa-pen-to-square"></i></button>
                    <button onclick="deleteCopyRow(${c.copy_id})" title="Delete" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fa-regular fa-trash-can"></i></button>
                </td>
            </tr>`).join('') || emptyRow(6);

        const pagination = buildPagination(data.total, data.page, data.limit,
            `(p) => renderBookCopies(p, document.getElementById('copy-search').value)`);

        document.getElementById('page-content').innerHTML = `
        <div class="flex items-center justify-between mb-5">
            <div><h1 class="text-xl font-black text-slate-900">Book Copies</h1>
            <p class="text-sm text-slate-500">Total: <strong>${data.total}</strong> copies</p></div>
            <button onclick="addCopyModal()" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-sm px-4 py-2 rounded-lg flex items-center gap-2 shadow-sm transition-colors">
                <i class="fa-solid fa-plus"></i> Add Copy
            </button>
        </div>
        <div class="section-card">
            <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-3">
                <div class="relative flex-1 max-w-xs">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                    <input id="copy-search" type="text" placeholder="Search by title or barcode..." value="${search}"
                        class="form-input pl-8" oninput="debounce(() => renderBookCopies(1, this.value), 400)()">
                </div>
            </div>
            <div class="overflow-x-auto table-wrap">
                <table class="ui-table">
                    <thead><tr><th>Copy ID</th><th>Book Title</th><th>Author</th><th>Barcode</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
            <div class="px-5 py-3 border-t border-slate-100 flex items-center justify-between">
                <span class="text-xs text-slate-500">Showing page ${data.page} of ${Math.ceil(data.total / data.limit) || 1}</span>
                ${pagination}
            </div>
        </div>`;
    } catch (err) {
        toast(err.message, 'error');
        document.getElementById('page-content').innerHTML = `
            <div class="bg-white rounded-xl p-8 text-center border border-slate-200 shadow-sm max-w-md mx-auto my-12">
                <div class="w-12 h-12 bg-amber-50 text-amber-500 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i class="fa-solid fa-triangle-exclamation text-xl"></i>
                </div>
                <h3 class="font-bold text-slate-800 text-base">Unable to load Book Copies</h3>
                <p class="text-xs text-slate-500 mt-1">${escapeHtml(err.message)}</p>
                <button onclick="navigateTo('dashboard')" class="mt-4 bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs px-4 py-2 rounded-lg transition-colors">Return to Dashboard</button>
            </div>
        `;
    }
}

async function addCopyModal() {
    const books = await api('api/books.php?page=1&search=');
    const bookOptions = (books.data || []).map(b => `<option value="${b.book_id}">${b.title}</option>`).join('');
    showModal(`
    <div class="modal-overlay">
      <div class="modal-box">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base">Add Book Copy</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form class="px-6 py-5 space-y-4" onsubmit="submitAddCopy(event)">
            <div>
                <label class="form-label">Book *</label>
                <select name="book_id" required class="form-input"><option value="">-- Select Book --</option>${bookOptions}</select>
            </div>
            <div>
                <label class="form-label">Barcode *</label>
                <input name="barcode" required class="form-input" placeholder="e.g. BC001-01">
            </div>
            <div>
                <label class="form-label">Status</label>
                <select name="status" class="form-input">
                    <option value="Available">Available</option>
                    <option value="Borrowed">Borrowed</option>
                    <option value="Reserved">Reserved</option>
                    <option value="Lost">Lost</option>
                    <option value="Damaged">Damaged</option>
                </select>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-brand-500 text-white rounded-lg hover:bg-brand-600">Add Copy</button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitAddCopy(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        await api('api/book_copies.php', 'POST', Object.fromEntries(fd.entries()));
        closeModal(); toast('Copy added!');
        renderBookCopies();
    } catch(err) { toast(err.message, 'error'); }
}

async function editCopyStatus(copyId, currentStatus) {
    const statuses = ['Available', 'Borrowed', 'Reserved', 'Lost', 'Damaged'];
    const options = statuses.map(s => `<option value="${s}" ${s === currentStatus ? 'selected' : ''}>${s}</option>`).join('');
    showModal(`
    <div class="modal-overlay">
      <div class="modal-box max-w-sm">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base">Update Copy Status</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form class="px-6 py-5 space-y-4" onsubmit="submitEditCopy(event,${copyId})">
            <div>
                <label class="form-label">Status</label>
                <select name="status" class="form-input">${options}</select>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-amber-500 text-white rounded-lg hover:bg-amber-600">Update</button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitEditCopy(e, copyId) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        await api(`api/book_copies.php?id=${copyId}`, 'PUT', Object.fromEntries(fd.entries()));
        closeModal(); toast('Status updated!');
        renderBookCopies();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteCopyRow(copyId) {
    if (!confirm('Delete this copy?')) return;
    try {
        await api(`api/book_copies.php?id=${copyId}`, 'DELETE');
        toast('Copy deleted.');
        renderBookCopies();
    } catch(err) { toast(err.message, 'error'); }
}

// ─── BORROWING ────────────────────────────────────────────────────────────────

async function renderBorrowing(page = 1, search = '', status = '') {
    State.pages.borrowing = { page, search, status };
    const isStudent = (window.CURRENT_USER?.role || '').toLowerCase() === 'user';
    const data = await api(`api/borrowing.php?page=${page}&search=${encodeURIComponent(search)}&status=${status}`);

    const rows = (data.data || []).map(r => `
        <tr>
            <td class="font-bold text-blue-600">BR${String(r.borrow_id).padStart(3,'0')}</td>
            ${!isStudent ? `<td class="font-semibold text-slate-800">${escapeHtml(r.student_name || r.full_name || '—')}</td>` : ''}
            <td class="text-slate-700 font-medium max-w-[180px] truncate">${escapeHtml(r.copy_label || r.barcode)}</td>
            <td class="text-slate-500">${fmt(r.borrow_date)}</td>
            <td class="text-slate-500">${fmt(r.due_date)}</td>
            <td class="text-slate-400">${r.return_date ? fmt(r.return_date) : '—'}</td>
            <td>${badge(r.status)}</td>
            ${!isStudent ? `
            <td class="text-center space-x-2">
                ${r.status !== 'Returned' ? `<button onclick="returnBook(${r.borrow_id})" title="Record Return" class="text-slate-400 hover:text-emerald-600 transition-colors"><i class="fa-solid fa-arrow-rotate-left"></i></button>` : '<span class="text-slate-400 text-xs">—</span>'}
            </td>` : ''}
        </tr>`).join('') || emptyRow(isStudent ? 6 : 8, isStudent ? 'You have no borrowing records found.' : 'No borrowing records found');

    const statusOptions = ['', 'Borrowed', 'Returned', 'Overdue'].map(s =>
        `<option value="${s}" ${s === status ? 'selected' : ''}>${s || 'All Statuses'}</option>`).join('');

    const pagination = buildPagination(data.total, data.page, data.limit,
        `(p) => renderBorrowing(p, document.getElementById('borrow-search').value, document.getElementById('borrow-status').value)`);

    document.getElementById('page-content').innerHTML = `
    <div class="flex items-center justify-between mb-5">
        <div><h1 class="text-xl font-black text-slate-900">${isStudent ? 'My Borrowing History' : 'Borrowing Records'}</h1>
        <p class="text-sm text-slate-500">Total: <strong>${data.total}</strong> records</p></div>
        ${!isStudent ? `
        <div class="flex items-center gap-2">
            <button onclick="exportBorrowingCsv()" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 font-bold text-sm px-3.5 py-2 rounded-lg flex items-center gap-2 shadow-2xs transition-colors cursor-pointer" title="Export all borrowing records to CSV">
                <i class="fa-solid fa-file-csv text-emerald-600 text-base"></i> Export CSV
            </button>
            <button onclick="issueBook()" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-sm px-4 py-2 rounded-lg flex items-center gap-2 shadow-sm transition-colors">
                <i class="fa-solid fa-plus"></i> Issue Book
            </button>
        </div>` : `
        <div class="flex items-center gap-2">
            <button onclick="exportBorrowingCsv()" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 font-bold text-xs px-3 py-2 rounded-lg flex items-center gap-1.5 shadow-2xs transition-colors cursor-pointer" title="Export your loan history to CSV">
                <i class="fa-solid fa-file-csv text-emerald-600"></i> Export History
            </button>
            <button onclick="navigateTo('books')" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs px-3.5 py-2 rounded-lg flex items-center gap-1.5 shadow-sm transition-colors">
                <i class="fa-solid fa-magnifying-glass"></i> Browse Books to Borrow
            </button>
        </div>`}
    </div>
    <div class="section-card">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-3 flex-wrap">
            <div class="relative flex-1 min-w-[200px] max-w-xs">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input id="borrow-search" type="text" placeholder="Search records..." value="${search}"
                    class="form-input pl-8" oninput="debounce(() => renderBorrowing(1, this.value, document.getElementById('borrow-status').value), 400)()">
            </div>
            <select id="borrow-status" class="form-input w-40 text-sm" onchange="renderBorrowing(1, document.getElementById('borrow-search').value, this.value)">
                ${statusOptions}
            </select>
        </div>
        <div class="overflow-x-auto table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>Borrow ID</th>
                    ${!isStudent ? '<th>Student/User</th>' : ''}
                    <th>Book / Copy</th>
                    <th>Borrow Date</th><th>Due Date</th><th>Return Date</th><th>Status</th>
                    ${!isStudent ? '<th class="text-center">Actions</th>' : ''}
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
        <div class="px-5 py-3 border-t border-slate-100 flex items-center justify-between">
            <span class="text-xs text-slate-500">Showing page ${data.page} of ${Math.ceil(data.total / data.limit) || 1}</span>
            ${pagination}
        </div>
    </div>`;
}

async function issueBook() {
    const students = await api('api/students.php?page=1');
    const copies = await api('api/book_copies.php?page=1&search=');
    const avail  = (copies.data || []).filter(c => c.status === 'Available');

    const userOptions = (students.data || [])
        .filter(student => student.user_id && Number(student.is_active) === 1)
        .map(student => `<option value="${student.user_id}">${escapeHtml(student.name)} (${escapeHtml(student.username)})</option>`).join('');
    const copyOptions = avail.map(c =>
        `<option value="${c.copy_id}">[${c.barcode}] ${c.book_title}</option>`).join('');

    const today   = new Date().toISOString().slice(0,10);
    const dueDate = new Date(Date.now() + 14*86400000).toISOString().slice(0,10);

    showModal(`
    <div class="modal-overlay">
      <div class="modal-box">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base"><i class="fa-solid fa-plus mr-2 text-blue-600"></i>Issue Book</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form class="px-6 py-5 space-y-4" onsubmit="submitIssueBook(event)">
            <div>
                <label class="form-label">Student / User *</label>
                <select name="user_id" required class="form-input">
                    <option value="">-- Select User --</option>${userOptions}
                </select>
            </div>
            <div>
                <label class="form-label">Available Book Copy *</label>
                <select name="copy_id" required class="form-input">
                    <option value="">-- Select Copy --</option>${copyOptions}
                </select>
                ${avail.length === 0 ? '<p class="text-xs text-rose-500 mt-1">No available copies right now.</p>' : ''}
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Borrow Date *</label>
                    <input type="date" name="borrow_date" required class="form-input" value="${today}"></div>
                <div><label class="form-label">Due Date *</label>
                    <input type="date" name="due_date" required class="form-input" value="${dueDate}"></div>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-brand-500 text-white rounded-lg hover:bg-brand-600">
                    <i class="fa-solid fa-check mr-1"></i> Issue Book
                </button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitIssueBook(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        await api('api/borrowing.php', 'POST', Object.fromEntries(fd.entries()));
        closeModal(); toast('Book issued successfully!');
        renderBorrowing();
    } catch(err) { toast(err.message, 'error'); }
}

async function returnBook(borrowId) {
    const today = new Date().toISOString().slice(0,10);
    showModal(`
    <div class="modal-overlay">
      <div class="modal-box max-w-sm">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base"><i class="fa-solid fa-arrow-rotate-left mr-2 text-emerald-600"></i>Record Return</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form class="px-6 py-5 space-y-4" onsubmit="submitReturn(event,${borrowId})">
            <div>
                <label class="form-label">Return Date *</label>
                <input type="date" name="return_date" required class="form-input" value="${today}">
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">
                    <i class="fa-solid fa-check mr-1"></i> Confirm Return
                </button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitReturn(e, borrowId) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        await api(`api/borrowing.php?id=${borrowId}`, 'PUT', { action: 'return', return_date: fd.get('return_date') });
        closeModal(); toast('Return recorded!', 'success');
        renderBorrowing();
    } catch(err) { toast(err.message, 'error'); }
}

// ─── RESERVATIONS ─────────────────────────────────────────────────────────────

async function renderReservations(page = 1, search = '', status = '') {
    State.pages.reservations = { page, search, status };
    const isStudent = (window.CURRENT_USER?.role || '').toLowerCase() === 'user';
    const data = await api(`api/reservations.php?page=${page}&search=${encodeURIComponent(search)}&status=${status}`);

    const rows = (data.data || []).map(r => `
        <tr>
            <td class="font-bold text-blue-600">R${String(r.reservation_id).padStart(3,'0')}</td>
            ${!isStudent ? `<td class="font-semibold text-slate-800">${escapeHtml(r.student_name || r.full_name || '—')}</td>` : ''}
            <td class="text-slate-800 font-medium">
                ${escapeHtml(r.book_title)}
                ${r.reserved_barcode ? `<div class="text-[11px] text-slate-500 font-normal mt-0.5"><i class="fa-solid fa-barcode text-slate-400 mr-1"></i>Reserved Copy: <span class="font-mono font-semibold text-blue-600">${escapeHtml(r.reserved_barcode)}</span></div>` : ''}
            </td>
            <td class="text-slate-500">${fmt(r.reservation_date)}</td>
            <td>${badge(r.status)}</td>
            <td class="text-center space-x-1">
                ${isStudent ? `
                    ${r.status === 'Pending' || r.status === 'Approved' ? `
                        <button onclick="cancelMyReservation(${r.reservation_id})" class="text-xs text-rose-600 hover:text-rose-800 font-semibold px-2 py-0.5 rounded border border-rose-200 hover:bg-rose-50 transition-colors">
                            Cancel
                        </button>
                    ` : '<span class="text-slate-400 text-xs">—</span>'}
                ` : `
                    ${r.status === 'Pending' ? `
                    <button onclick="updateReservation(${r.reservation_id},'Approved')" title="Approve & Reserve Stock" class="text-slate-400 hover:text-emerald-600 transition-colors"><i class="fa-solid fa-check"></i></button>
                    <button onclick="updateReservation(${r.reservation_id},'Cancelled')" title="Cancel" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fa-solid fa-xmark"></i></button>` : ''}
                    ${r.status === 'Approved' ? `
                    <button onclick="updateReservation(${r.reservation_id},'Fulfilled')" title="Fulfilled" class="text-slate-400 hover:text-blue-600 transition-colors"><i class="fa-solid fa-circle-check"></i></button>
                    <button onclick="updateReservation(${r.reservation_id},'Cancelled')" title="Cancel & Return Stock" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fa-solid fa-xmark"></i></button>` : ''}
                    <button onclick="deleteReservation(${r.reservation_id})" title="Delete" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fa-regular fa-trash-can text-xs"></i></button>
                `}
            </td>
        </tr>`).join('') || emptyRow(isStudent ? 5 : 6, isStudent ? 'You have no reservations on file.' : 'No reservations found');

    const statusOptions = ['', 'Pending', 'Approved', 'Cancelled', 'Fulfilled'].map(s =>
        `<option value="${s}" ${s === status ? 'selected' : ''}>${s || 'All Statuses'}</option>`).join('');

    const pagination = buildPagination(data.total, data.page, data.limit,
        `(p) => renderReservations(p, document.getElementById('res-search').value, document.getElementById('res-status').value)`);

    document.getElementById('page-content').innerHTML = `
    <div class="flex items-center justify-between mb-5">
        <div><h1 class="text-xl font-black text-slate-900">${isStudent ? 'My Book Reservations' : 'Reservations Management'}</h1>
        <p class="text-sm text-slate-500">Total: <strong>${data.total}</strong> reservations</p></div>
        ${isStudent ? `
        <button onclick="studentAddReservationModal()" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-sm px-4 py-2 rounded-lg flex items-center gap-2 shadow-sm transition-colors">
            <i class="fa-solid fa-plus"></i> Reserve a Book
        </button>` : `
        <button onclick="addReservation()" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-sm px-4 py-2 rounded-lg flex items-center gap-2 shadow-sm transition-colors">
            <i class="fa-solid fa-plus"></i> Add Reservation
        </button>`}
    </div>
    <div class="section-card">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-3 flex-wrap">
            <div class="relative flex-1 min-w-[200px] max-w-xs">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input id="res-search" type="text" placeholder="Search by book..." value="${search}"
                    class="form-input pl-8" oninput="debounce(() => renderReservations(1, this.value, document.getElementById('res-status').value), 400)()">
            </div>
            <select id="res-status" class="form-input w-40 text-sm" onchange="renderReservations(1, document.getElementById('res-search').value, this.value)">
                ${statusOptions}
            </select>
        </div>
        <div class="overflow-x-auto table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>Res. ID</th>
                    ${!isStudent ? '<th>Student/User</th>' : ''}
                    <th>Book Title</th><th>Reservation Date</th><th>Status</th><th class="text-center">Action</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
        <div class="px-5 py-3 border-t border-slate-100 flex items-center justify-between">
            <span class="text-xs text-slate-500">Showing page ${data.page} of ${Math.ceil(data.total / data.limit) || 1}</span>
            ${pagination}
        </div>
    </div>`;
}

async function studentAddReservationModal() {
    const books = await api('api/books.php?page=1');
    const bookOpts = (books.data || []).map(b => `<option value="${b.book_id}">${escapeHtml(b.title)} (${b.author})</option>`).join('');

    showModal(`
    <div class="modal-overlay">
      <div class="modal-box max-w-md">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base"><i class="fa-regular fa-bookmark mr-2 text-blue-600"></i>Reserve a Book</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form class="px-6 py-5 space-y-4" onsubmit="submitStudentReservation(event)">
            <div>
                <label class="form-label">Select Book</label>
                <select name="book_id" required class="form-input">${bookOpts}</select>
            </div>
            <div class="bg-blue-50 border border-blue-100 text-blue-700 text-xs p-3 rounded-lg">
                <i class="fa-solid fa-circle-info mr-1"></i> Your reservation will be submitted to the library staff for review. You can pick up the book once approved!
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-brand-500 text-white rounded-lg hover:bg-brand-600">Submit Reservation</button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitStudentReservation(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        await api('api/reservations.php', 'POST', {
            book_id: fd.get('book_id'),
            user_id: window.CURRENT_USER?.id
        });
        closeModal();
        toast('Reservation submitted successfully! 🎉');
        renderReservations();
    } catch(err) {
        toast(err.message, 'error');
    }
}

async function addReservation() {
    const [users, books] = await Promise.all([
        api('api/students.php?page=1'),
        api('api/books.php?page=1'),
    ]);
    const userOpts = (users.data || [])
        .filter(student => student.user_id && Number(student.is_active) === 1)
        .map(student => `<option value="${student.user_id}">${escapeHtml(student.name)} (${escapeHtml(student.username)})</option>`).join('');
    const bookOpts = (books.data || []).map(b => `<option value="${b.book_id}">${b.title}</option>`).join('');

    showModal(`
    <div class="modal-overlay">
      <div class="modal-box">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base">Add Reservation</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form class="px-6 py-5 space-y-4" onsubmit="submitAddReservation(event)">
            <div><label class="form-label">Student / User *</label>
                <select name="user_id" required class="form-input"><option value="">-- Select User --</option>${userOpts}</select></div>
            <div><label class="form-label">Book *</label>
                <select name="book_id" required class="form-input"><option value="">-- Select Book --</option>${bookOpts}</select></div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-brand-500 text-white rounded-lg hover:bg-brand-600">Add Reservation</button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitAddReservation(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        await api('api/reservations.php', 'POST', Object.fromEntries(fd.entries()));
        closeModal(); toast('Reservation added!');
        renderReservations();
    } catch(err) { toast(err.message, 'error'); }
}

async function updateReservation(id, status) {
    try {
        await api(`api/reservations.php?id=${id}`, 'PUT', { status });
        toast(`Reservation ${status.toLowerCase()}!`);
        renderReservations();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteReservation(id) {
    if (!confirm('Delete this reservation?')) return;
    try {
        await api(`api/reservations.php?id=${id}`, 'DELETE');
        toast('Reservation deleted.');
        renderReservations();
    } catch(err) { toast(err.message, 'error'); }
}

// ─── STUDENTS ────────────────────────────────────────────────────────────────

async function renderStudents(page = 1, search = '') {
    State.pages.students = { page, search };
    const canManage = ['admin', 'librarian'].includes((window.CURRENT_USER?.role || '').toLowerCase());
    const data = await api(`api/students.php?page=${page}&search=${encodeURIComponent(search)}`);

    const rows = (data.data || []).map(s => `
        <tr>
            <td class="font-bold text-blue-600">${escapeHtml(s.student_number || '—')}</td>
            <td class="font-semibold text-slate-800">${escapeHtml(s.name)}</td>
            <td>${escapeHtml(s.gender)}</td>
            <td class="text-center">${s.year_level}</td>
            <td><span class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded font-semibold">${escapeHtml(s.course_code)}</span></td>
            <td class="text-slate-500 text-xs max-w-[180px] truncate">${escapeHtml(s.course_name)}</td>
            <td class="text-slate-400 text-xs">${escapeHtml(s.username || '—')}</td>
            <td class="text-center space-x-2">
                <button onclick="editStudent(${s.id})" title="Edit Student Profile" class="text-slate-400 hover:text-amber-500 transition-colors"><i class="fa-regular fa-pen-to-square"></i></button>
                <button onclick="deleteStudent(${s.id},'${s.name.replace(/'/g,"\\'")}') " title="Delete Student Record" class="text-slate-400 hover:text-rose-500 transition-colors"><i class="fa-regular fa-trash-can"></i></button>
            </td>
        </tr>`).join('') || emptyRow(8);

    const pagination = buildPagination(data.total, data.page, data.limit,
        `(p) => renderStudents(p, document.getElementById('stu-search').value)`);

    document.getElementById('page-content').innerHTML = `
    <div class="flex items-center justify-between mb-5">
        <div><h1 class="text-xl font-black text-slate-900">Students</h1>
        <p class="text-sm text-slate-500">Total: <strong>${data.total}</strong> students</p></div>
        <button onclick="addStudent()" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-sm px-4 py-2 rounded-lg flex items-center gap-2 shadow-sm transition-colors">
            <i class="fa-solid fa-user-plus"></i> Add Student
        </button>
    </div>
    <div class="section-card">
        <div class="px-5 py-3 border-b border-slate-100">
            <div class="relative max-w-xs">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input id="stu-search" type="text" placeholder="Search students..." value="${search}"
                    class="form-input pl-8" oninput="debounce(() => renderStudents(1, this.value), 400)()">
            </div>
        </div>
        <div class="overflow-x-auto table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>School ID</th><th>Name</th><th>Gender</th><th class="text-center">Year</th>
                    <th>Course</th><th>Course Name</th><th>Username</th><th class="text-center">Actions</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
        <div class="px-5 py-3 border-t border-slate-100 flex items-center justify-between">
            <span class="text-xs text-slate-500">Showing page ${data.page} of ${Math.ceil(data.total / data.limit) || 1}</span>
            ${pagination}
        </div>
    </div>`;
}

async function addStudent() {
    const courses = await api('api/courses.php');
    const courseOpts = (courses.data || []).map(c => `<option value="${c.id}">${c.course_code} — ${c.course_name}</option>`).join('');
    showModal(`
    <div class="modal-overlay">
      <div class="modal-box">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base">Add Student</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form class="px-6 py-5 space-y-4" onsubmit="submitAddStudent(event)">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2"><label class="form-label">Full Name *</label>
                    <input name="name" required class="form-input" placeholder="Juan Dela Cruz"></div>
                <div class="col-span-2"><label class="form-label">School Student ID *</label>
                    <input name="student_number" required maxlength="50" class="form-input" placeholder="Student ID"></div>
                <div><label class="form-label">Gender *</label>
                    <select name="gender" required class="form-input">
                        <option value="Male">Male</option><option value="Female">Female</option><option value="Other">Other</option>
                    </select></div>
                <div><label class="form-label">Year Level *</label>
                    <select name="year_level" required class="form-input">
                        <option value="1">1st Year</option><option value="2">2nd Year</option>
                        <option value="3">3rd Year</option><option value="4">4th Year</option>
                    </select></div>
                <div class="col-span-2"><label class="form-label">Course *</label>
                    <select name="course_id" required class="form-input"><option value="">-- Select Course --</option>${courseOpts}</select></div>
            </div>
            <div class="border-t border-slate-100 pt-4">
                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-3">Optional: Create Login Account</p>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="form-label">Username</label>
                        <input name="username" class="form-input" placeholder="username123"></div>
                    <div><label class="form-label">Email</label>
                        <input name="email" type="email" class="form-input" placeholder="email@school.edu"></div>
                    <div class="col-span-2"><label class="form-label">Password (at least 6 characters)</label>
                        <input name="password" type="password" minlength="6" class="form-input" placeholder="Required when creating an account"></div>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-brand-500 text-white rounded-lg hover:bg-brand-600">Add Student</button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitAddStudent(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        await api('api/students.php', 'POST', Object.fromEntries(fd.entries()));
        closeModal(); toast('Student added!');
        renderStudents();
    } catch(err) { toast(err.message, 'error'); }
}

async function editStudent(id) {
    const courses = await api('api/courses.php');
    const allStudents = await api('api/students.php?page=1&search=');
    let s = allStudents.data?.find(x => x.id == id);
    if (!s) { toast('Student not found', 'error'); return; }
    const courseOpts = (courses.data || []).map(c =>
        `<option value="${c.id}" ${c.id == s.course_id ? 'selected' : ''}>${c.course_code} — ${c.course_name}</option>`).join('');
    showModal(`
    <div class="modal-overlay">
      <div class="modal-box">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base">Edit Student</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form class="px-6 py-5 space-y-4" onsubmit="submitEditStudent(event,${id})">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2"><label class="form-label">Full Name *</label>
                    <input name="name" required class="form-input" value="${escapeHtml(s.name)}"></div>
                <div class="col-span-2"><label class="form-label">School Student ID *</label>
                    <input name="student_number" required maxlength="50" class="form-input" value="${escapeHtml(s.student_number || '')}"></div>
                <div><label class="form-label">Gender *</label>
                    <select name="gender" class="form-input">
                        <option ${s.gender==='Male'?'selected':''}>Male</option>
                        <option ${s.gender==='Female'?'selected':''}>Female</option>
                        <option ${s.gender==='Other'?'selected':''}>Other</option>
                    </select></div>
                <div><label class="form-label">Year Level *</label>
                    <select name="year_level" class="form-input">
                        ${[1,2,3,4].map(y => `<option value="${y}" ${s.year_level==y?'selected':''}>${y}${['st','nd','rd','th'][y-1]} Year</option>`).join('')}
                    </select></div>
                <div class="col-span-2"><label class="form-label">Course *</label>
                    <select name="course_id" class="form-input">${courseOpts}</select></div>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-amber-500 text-white rounded-lg hover:bg-amber-600">Save Changes</button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitEditStudent(e, id) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        await api(`api/students.php?id=${id}`, 'PUT', Object.fromEntries(fd.entries()));
        closeModal(); toast('Student updated!');
        renderStudents();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteStudent(id, name) {
    if (!confirm(`Delete student "${name}"?`)) return;
    try {
        await api(`api/students.php?id=${id}`, 'DELETE');
        toast('Student deleted.');
        renderStudents();
    } catch(err) { toast(err.message, 'error'); }
}

// ─── USERS ───────────────────────────────────────────────────────────────────

async function renderUsers(page = 1, search = '') {
    State.pages.users = { page, search };
    const data = await api(`api/users.php?page=${page}&search=${encodeURIComponent(search)}`);

    const rows = (data.data || []).map(u => `
        <tr>
            <td class="font-bold text-blue-600">U${String(u.user_id).padStart(3,'0')}</td>
            <td class="font-semibold text-slate-800">${escapeHtml(u.full_name)}</td>
            <td class="text-slate-500 font-mono text-xs">${escapeHtml(u.employee_id || '—')}</td>
            <td class="text-slate-500 font-mono text-xs">${escapeHtml(u.username)}</td>
            <td class="text-slate-500 text-xs">${escapeHtml(u.email)}</td>
            <td>${badge(Number(u.is_active) === 1 ? 'Active' : 'Inactive')}</td>
            <td class="text-slate-400 text-xs">${fmt(u.created_at)}</td>
            <td class="text-center space-x-2">
                <button onclick="editLibrarian(${u.user_id})" title="Edit account" class="text-slate-400 hover:text-amber-500"><i class="fa-regular fa-pen-to-square"></i></button>
                <button onclick="setLibrarianActive(${u.user_id},${Number(u.is_active) === 1 ? 'false' : 'true'})" title="${Number(u.is_active) === 1 ? 'Deactivate' : 'Activate'} account" class="${Number(u.is_active) === 1 ? 'text-rose-500' : 'text-emerald-600'}">
                    <i class="fa-solid fa-${Number(u.is_active) === 1 ? 'ban' : 'check'}"></i>
                </button>
                <button onclick="resetLibrarianPassword(${u.user_id})" title="Reset password" class="text-slate-400 hover:text-blue-600"><i class="fa-solid fa-key"></i></button>
                <button onclick="deleteLibrarian(${u.user_id})" title="Delete account" class="text-slate-400 hover:text-rose-600"><i class="fa-regular fa-trash-can"></i></button>
            </td>
        </tr>`).join('') || emptyRow(8);

    const pagination = buildPagination(data.total, data.page, data.limit,
        `(p) => renderUsers(p, document.getElementById('usr-search').value)`);

    document.getElementById('page-content').innerHTML = `
    <div class="flex items-center justify-between mb-5">
        <div><h1 class="text-xl font-black text-slate-900">Librarian Management</h1>
        <p class="text-sm text-slate-500">Manage library staff accounts (${data.total} total)</p></div>
        <button onclick="addUserModal()" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-sm px-4 py-2 rounded-lg flex items-center gap-2">
            <i class="fa-solid fa-user-plus"></i> Add Librarian
        </button>
    </div>
    <div class="section-card">
        <div class="px-5 py-3 border-b border-slate-100">
            <div class="relative max-w-xs">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input id="usr-search" type="text" placeholder="Search librarians..." value="${escapeHtml(search)}"
                    class="form-input pl-8" oninput="debounce(() => renderUsers(1, this.value), 400)()">
            </div>
        </div>
        <div class="overflow-x-auto table-wrap">
            <table class="ui-table">
                <thead><tr><th>User ID</th><th>Full Name</th><th>Employee ID</th><th>Username</th><th>Email</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
        <div class="px-5 py-3 border-t border-slate-100 flex items-center justify-between">
            <span class="text-xs text-slate-500">Showing page ${data.page} of ${Math.ceil(data.total / data.limit) || 1}</span>
            ${pagination}
        </div>
    </div>`;
}

async function addUserModal() {
    showLibrarianForm();
}

async function editLibrarian(id) {
    try {
        const result = await api(`api/users.php?id=${id}`);
        const librarian = result.data?.[0];
        if (!librarian) {
            toast('Librarian account not found.', 'error');
            return;
        }
        showLibrarianForm(librarian);
    } catch (err) {
        toast(err.message, 'error');
    }
}

function showLibrarianForm(librarian = null) {
    const editing = Boolean(librarian);
    showModal(`
    <div class="modal-overlay">
      <div class="modal-box">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base">${editing ? 'Edit Librarian' : 'Create Librarian Account'}</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form class="px-6 py-5 space-y-4" onsubmit="submitLibrarian(event,${editing ? librarian.user_id : 'null'})">
            ${editing ? '' : '<p class="text-xs text-slate-500">The account role is assigned as Librarian automatically.</p>'}
            <div><label class="form-label">Full Name *</label>
                <input name="full_name" required maxlength="100" class="form-input" value="${escapeHtml(librarian?.full_name || '')}"></div>
            <div><label class="form-label">Employee ID *</label>
                <input name="employee_id" required maxlength="50" pattern="[A-Za-z0-9._-]+" class="form-input" value="${escapeHtml(librarian?.employee_id || '')}"></div>
            <div><label class="form-label">Username *</label>
                <input name="username" required minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" class="form-input" value="${escapeHtml(librarian?.username || '')}"></div>
            <div><label class="form-label">Email Address *</label>
                <input name="email" type="email" maxlength="100" required class="form-input" value="${escapeHtml(librarian?.email || '')}"></div>
            ${editing ? `
            <div><label class="form-label">Account Status</label>
                <select name="is_active" class="form-input">
                    <option value="1" ${Number(librarian.is_active) === 1 ? 'selected' : ''}>Active</option>
                    <option value="0" ${Number(librarian.is_active) !== 1 ? 'selected' : ''}>Inactive</option>
                </select></div>` : `
            <div><label class="form-label">Password * (at least 8 characters)</label>
                <input name="password" type="password" required minlength="8" autocomplete="new-password" class="form-input"></div>
            <div><label class="form-label">Confirm Password *</label>
                <input name="confirm_password" type="password" required minlength="8" autocomplete="new-password" class="form-input"></div>`}
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-brand-500 text-white rounded-lg">${editing ? 'Save Changes' : 'Create Account'}</button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitLibrarian(event, id) {
    event.preventDefault();
    const input = Object.fromEntries(new FormData(event.target).entries());
    try {
        await api(id ? `api/users.php?id=${id}` : 'api/users.php', id ? 'PUT' : 'POST', input);
        closeModal();
        toast(id ? 'Librarian account updated.' : 'Librarian account created.');
        renderUsers(State.pages.users?.page || 1, State.pages.users?.search || '');
    } catch (err) {
        toast(err.message, 'error');
    }
}

async function setLibrarianActive(id, active) {
    if (!confirm(`${active ? 'Activate' : 'Deactivate'} this librarian account?`)) return;
    try {
        await api(`api/users.php?id=${id}`, 'PATCH', { is_active: active });
        toast(`Librarian account ${active ? 'activated' : 'deactivated'}.`);
        renderUsers(State.pages.users?.page || 1, State.pages.users?.search || '');
    } catch (err) {
        toast(err.message, 'error');
    }
}

function resetLibrarianPassword(id) {
    showModal(`
    <div class="modal-overlay">
      <div class="modal-box">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base">Reset Librarian Password</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form class="px-6 py-5 space-y-4" onsubmit="submitLibrarianPasswordReset(event,${id})">
            <div><label class="form-label">New Password * (at least 8 characters)</label>
                <input name="password" type="password" required minlength="8" autocomplete="new-password" class="form-input"></div>
            <div><label class="form-label">Confirm New Password *</label>
                <input name="confirm_password" type="password" required minlength="8" autocomplete="new-password" class="form-input"></div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-brand-500 text-white rounded-lg">Reset Password</button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitLibrarianPasswordReset(event, id) {
    event.preventDefault();
    const input = Object.fromEntries(new FormData(event.target).entries());
    if (input.password !== input.confirm_password) {
        toast('Passwords do not match.', 'error');
        return;
    }
    try {
        await api(`api/users.php?action=reset_password&id=${id}`, 'POST', input);
        closeModal();
        toast('Librarian password reset successfully.');
    } catch (err) {
        toast(err.message, 'error');
    }
}

async function deleteLibrarian(id) {
    if (!confirm('Delete this librarian account permanently? Accounts with borrowing or reservation history cannot be deleted; deactivate those accounts instead.')) return;
    try {
        await api(`api/users.php?id=${id}`, 'DELETE');
        toast('Librarian account deleted.');
        renderUsers(State.pages.users?.page || 1, State.pages.users?.search || '');
    } catch (err) {
        toast(err.message, 'error');
    }
}

async function renderProfile() {
    const result = await api('api/profile.php');
    const profile = result.data || {};
    const isStudent = (profile.role || window.CURRENT_USER?.role || '').toLowerCase() === 'user';
    const displayRole = profile.role === 'User' ? 'Student' : (profile.role || 'Staff');

    document.getElementById('page-content').innerHTML = `
    <div class="max-w-2xl">
        <div class="mb-5">
            <h1 class="text-xl font-black text-slate-900">My Profile & Account Settings</h1>
            <p class="text-sm text-slate-500">Manage your profile details, email address, and security password.</p>
        </div>
        <form class="section-card p-6 space-y-4" onsubmit="saveProfile(event)">
            <!-- Account Overview Pill -->
            <div class="flex items-center gap-3 p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl mb-4">
                <div class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-base shrink-0">
                    ${(profile.full_name || profile.username || 'U').charAt(0).toUpperCase()}
                </div>
                <div class="flex-1">
                    <div class="font-bold text-slate-900 text-sm">${escapeHtml(profile.full_name || profile.username)}</div>
                    <div class="text-xs text-slate-500">@${escapeHtml(profile.username)} • <span class="font-semibold text-blue-600">${displayRole}</span></div>
                </div>
                <span class="badge ${profile.role === 'Admin' ? 'badge-reserved' : profile.role === 'Librarian' ? 'badge-returned' : 'badge-active'}">
                    ${displayRole}
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="form-label">Full Name *</label>
                    <input name="full_name" required maxlength="150" class="form-input" value="${escapeHtml(profile.full_name || '')}">
                </div>
                <div>
                    <label class="form-label">Username</label>
                    <input disabled class="form-input bg-slate-100 text-slate-500 font-mono text-xs" value="${escapeHtml(profile.username || '')}">
                    <span class="text-[11px] text-slate-400">Usernames are system-managed.</span>
                </div>
                <div>
                    <label class="form-label">Email Address *</label>
                    <input name="email" type="email" required maxlength="100" class="form-input" value="${escapeHtml(profile.email || '')}">
                </div>
                ${isStudent ? `
                <div class="sm:col-span-2">
                    <label class="form-label">School Student ID</label>
                    <input disabled class="form-input bg-slate-100 text-slate-600 font-semibold" value="${escapeHtml(profile.student_number || '—')}">
                </div>` : `
                <div class="sm:col-span-2">
                    <label class="form-label">Employee / Staff ID</label>
                    <input disabled class="form-input bg-slate-100 text-slate-600 font-mono text-xs" value="${escapeHtml(profile.employee_id || 'System Staff')}">
                </div>`}
            </div>

            <!-- Password Change Section -->
            <div class="border-t border-slate-100 pt-4 mt-2">
                <h4 class="font-bold text-slate-800 text-sm mb-1 flex items-center gap-2">
                    <i class="fa-solid fa-lock text-slate-400"></i> Change Password
                </h4>
                <p class="text-xs text-slate-400 mb-3">Leave blank if you do not wish to change your current password.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">New Password</label>
                        <input name="password" id="profile-new-pass" type="password" minlength="6" placeholder="Min. 6 characters" autocomplete="new-password" class="form-input">
                    </div>
                    <div>
                        <label class="form-label">Confirm New Password</label>
                        <input name="confirm_password" id="profile-conf-pass" type="password" minlength="6" placeholder="Re-enter new password" autocomplete="new-password" class="form-input">
                    </div>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
                <span class="text-xs text-slate-400">Profile updates take effect immediately.</span>
                <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-sm px-5 py-2.5 rounded-lg shadow-sm transition-colors cursor-pointer">
                    <i class="fa-solid fa-floppy-disk mr-1.5"></i> Save Profile
                </button>
            </div>
        </form>
    </div>`;
}

async function saveProfile(event) {
    event.preventDefault();
    const fd = new FormData(event.target);
    const pass = (fd.get('password') || '').trim();
    const conf = (fd.get('confirm_password') || '').trim();
    if (pass && pass !== conf) {
        toast('New passwords do not match. Please re-enter.', 'error');
        return;
    }
    try {
        const result = await api('api/profile.php', 'PUT', Object.fromEntries(fd.entries()));
        const oldName = window.CURRENT_USER.full_name;
        const name = event.target.elements.full_name.value;
        window.CURRENT_USER.full_name = name;
        document.querySelectorAll('header .font-semibold, #sidebar .font-bold').forEach(el => {
            if (el.textContent.trim() === oldName) el.textContent = name;
        });
        toast(result.success ? 'Profile updated.' : 'Profile update failed.', result.success ? 'success' : 'error');
        renderProfile();
    } catch (err) {
        toast(err.message, 'error');
    }
}

// ─── COURSES ──────────────────────────────────────────────────────────────────

async function renderCourses() {
    const data = await api('api/courses.php');
    const rows = (data.data || []).map((c, i) => `
        <tr>
            <td class="font-bold text-slate-600">${i + 1}</td>
            <td><span class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded font-bold">${c.course_code}</span></td>
            <td class="font-semibold text-slate-800">${c.course_name}</td>
        </tr>`).join('') || emptyRow(3);

    document.getElementById('page-content').innerHTML = `
    <div class="mb-5">
        <h1 class="text-xl font-black text-slate-900">Courses</h1>
        <p class="text-sm text-slate-500">All registered academic courses (${data.data?.length || 0} total)</p>
    </div>
    <div class="section-card">
        <div class="overflow-x-auto table-wrap">
            <table class="ui-table">
                <thead><tr><th>#</th><th>Course Code</th><th>Course Name</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
    </div>`;
}

// ─── CATEGORIES ───────────────────────────────────────────────────────────────

async function renderCategories(filterType = 'all') {
    const data = await api('api/categories.php');
    const allCats = data.data || [];
    const filteredCats = filterType === 'all' 
        ? allCats 
        : allCats.filter(c => (c.category_type || 'Academic').toLowerCase() === filterType.toLowerCase());

    const acadCount = allCats.filter(c => (c.category_type || 'Academic') === 'Academic').length;
    const nonAcadCount = allCats.filter(c => c.category_type === 'Non-Academic').length;

    const rows = filteredCats.map(c => {
        const isNonAcad = c.category_type === 'Non-Academic';
        return `
        <tr>
            <td class="font-bold text-slate-600">CAT${String(c.category_id).padStart(3,'0')}</td>
            <td class="font-semibold text-slate-800">${escapeHtml(c.category_name)}</td>
            <td>
                <span class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-full font-bold ${isNonAcad ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-blue-50 text-blue-700 border border-blue-200'}">
                    <i class="fa-solid ${isNonAcad ? 'fa-book-open' : 'fa-graduation-cap'} text-[10px]"></i>
                    ${c.category_type || 'Academic'}
                </span>
            </td>
            <td class="text-slate-500 text-sm max-w-xs truncate" title="${escapeHtml(c.description || '')}">${escapeHtml(c.description || '—')}</td>
            <td class="text-center font-bold text-blue-600">${c.book_count}</td>
            <td class="text-center space-x-2">
                <button onclick="editCategory(${c.category_id},'${escapeHtml(c.category_name).replace(/'/g,"\\'")}','${c.category_type || 'Academic'}','${escapeHtml(c.description || '').replace(/'/g,"\\'")}')" class="text-slate-400 hover:text-amber-500 transition-colors" title="Edit"><i class="fa-regular fa-pen-to-square"></i></button>
                <button onclick="deleteCategory(${c.category_id},'${escapeHtml(c.category_name).replace(/'/g,"\\'")}') " class="text-slate-400 hover:text-rose-500 transition-colors" title="Delete"><i class="fa-regular fa-trash-can"></i></button>
            </td>
        </tr>`;
    }).join('') || emptyRow(6, 'No categories found for this filter.');

    document.getElementById('page-content').innerHTML = `
    <div class="flex items-center justify-between mb-5">
        <div>
            <h1 class="text-xl font-black text-slate-900">Categories & Classifications</h1>
            <p class="text-sm text-slate-500">Organize books into Academic and Non-Academic categories (${allCats.length} total)</p>
        </div>
        <button onclick="addCategory()" class="bg-brand-500 hover:bg-brand-600 text-white font-bold text-sm px-4 py-2 rounded-lg flex items-center gap-2 shadow-sm transition-colors">
            <i class="fa-solid fa-plus"></i> Add Category
        </button>
    </div>

    <!-- Filter Pills -->
    <div class="flex items-center gap-2 mb-4">
        <button onclick="renderCategories('all')" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors ${filterType === 'all' ? 'bg-slate-900 text-white shadow-2xs' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'}">
            All Categories (${allCats.length})
        </button>
        <button onclick="renderCategories('academic')" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors ${filterType === 'academic' ? 'bg-blue-600 text-white shadow-2xs' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'}">
            <i class="fa-solid fa-graduation-cap mr-1 text-[11px]"></i> Academic (${acadCount})
        </button>
        <button onclick="renderCategories('non-academic')" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors ${filterType === 'non-academic' ? 'bg-amber-600 text-white shadow-2xs' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'}">
            <i class="fa-solid fa-book-open mr-1 text-[11px]"></i> Non-Academic (${nonAcadCount})
        </button>
    </div>

    <div class="section-card">
        <div class="overflow-x-auto table-wrap">
            <table class="ui-table">
                <thead><tr><th>Category ID</th><th>Name</th><th>Classification</th><th>Description</th><th class="text-center">Books</th><th class="text-center">Actions</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
    </div>`;
}

function addCategory() {
    showModal(`
    <div class="modal-overlay">
      <div class="modal-box max-w-sm">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base">Add Category</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form class="px-6 py-5 space-y-4" onsubmit="submitAddCategory(event)">
            <div>
                <label class="form-label">Category Name *</label>
                <input name="category_name" required class="form-input" placeholder="e.g. Computer Science">
            </div>
            <div>
                <label class="form-label">Classification *</label>
                <select name="category_type" required class="form-input">
                    <option value="Academic">🎓 Academic (Textbooks, Curricular, Reference)</option>
                    <option value="Non-Academic">📖 Non-Academic (Fiction, Self-Help, Leisure)</option>
                </select>
            </div>
            <div>
                <label class="form-label">Description</label>
                <textarea name="description" class="form-input" rows="2" placeholder="Optional brief summary"></textarea>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-brand-500 text-white rounded-lg hover:bg-brand-600">Add</button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitAddCategory(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        await api('api/categories.php', 'POST', Object.fromEntries(fd.entries()));
        closeModal(); toast('Category added!');
        renderCategories();
    } catch(err) { toast(err.message, 'error'); }
}

function editCategory(id, name, type = 'Academic', desc = '') {
    showModal(`
    <div class="modal-overlay">
      <div class="modal-box max-w-sm">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-base">Edit Category</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>
        <form class="px-6 py-5 space-y-4" onsubmit="submitEditCategory(event,${id})">
            <div>
                <label class="form-label">Category Name *</label>
                <input name="category_name" required class="form-input" value="${name}">
            </div>
            <div>
                <label class="form-label">Classification *</label>
                <select name="category_type" required class="form-input">
                    <option value="Academic" ${type === 'Academic' ? 'selected' : ''}>🎓 Academic (Textbooks, Curricular, Reference)</option>
                    <option value="Non-Academic" ${type === 'Non-Academic' ? 'selected' : ''}>📖 Non-Academic (Fiction, Self-Help, Leisure)</option>
                </select>
            </div>
            <div>
                <label class="form-label">Description</label>
                <textarea name="description" class="form-input" rows="2">${desc}</textarea>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm font-bold bg-amber-500 text-white rounded-lg hover:bg-amber-600">Save</button>
            </div>
        </form>
      </div>
    </div>`);
}

async function submitEditCategory(e, id) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        await api(`api/categories.php?id=${id}`, 'PUT', Object.fromEntries(fd.entries()));
        closeModal(); toast('Category updated!');
        renderCategories();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteCategory(id, name) {
    if (!confirm(`Delete category "${name}"?`)) return;
    try {
        await api(`api/categories.php?id=${id}`, 'DELETE');
        toast('Category deleted.');
        renderCategories();
    } catch(err) { toast(err.message, 'error'); }
}

// ─── Debounce Utility ─────────────────────────────────────────────────────────

const debounceMap = {};
function debounce(fn, delay) {
    const key = fn.toString().slice(0,40);
    return function(...args) {
        clearTimeout(debounceMap[key]);
        debounceMap[key] = setTimeout(() => fn.apply(this, args), delay);
    };
}

// ─── Global Search ────────────────────────────────────────────────────────────

document.getElementById('global-search').addEventListener('input', debounce(function() {
    const val = this.value.trim();
    if (!val) return;
    const page = State.currentPage;
    if (page === 'books') renderBooks(1, val);
    else if (page === 'students') renderStudents(1, val);
    else if (page === 'borrowing') renderBorrowing(1, val);
    else if (page === 'reservations') renderReservations(1, val);
    else if (page === 'book_copies') renderBookCopies(1, val);
}, 400));

// ─── Sidebar Navigation Setup ─────────────────────────────────────────────────

document.querySelectorAll('.nav-item[data-page]').forEach(el => {
    el.addEventListener('click', function() {
        navigateTo(this.dataset.page);
        document.getElementById('global-search').value = '';
    });
});

// ─── Init ─────────────────────────────────────────────────────────────────────

navigateTo('dashboard');

const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('welcome')) {
    setTimeout(() => toast('Welcome to the Library Management System! 🎉', 'success'), 400);
}


// ─── Online Metadata Lookup (Open Library & Google Books) ─────────────────────

async function lookupBookByIsbn(isbn, formId = 'add-book-form') {
    isbn = (isbn || '').replace(/[^0-9X]/gi, '').trim();
    if (!isbn) {
        toast('Please enter or scan a valid ISBN first.', 'error');
        return;
    }

    toast('Looking up book details online...', 'info');

    try {
        let bookData = null;

        // 1. First try Open Library Books API (free, open, no auth)
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 6000);
            const olRes = await fetch(`https://openlibrary.org/api/books?bibkeys=ISBN:${isbn}&format=json&jscmd=data`, {
                signal: controller.signal
            });
            clearTimeout(timeoutId);

            if (olRes.ok) {
                const olJson = await olRes.json();
                const olBook = olJson[`ISBN:${isbn}`];
                if (olBook && olBook.title) {
                    bookData = {
                        title: olBook.title || '',
                        author: (olBook.authors || []).map(a => a.name).join(', ') || '',
                        publisher: (olBook.publishers || []).map(p => p.name).join(', ') || '',
                        year: olBook.publish_date ? parseInt(olBook.publish_date.match(/\d{4}/)?.[0] || '') : '',
                        description: typeof olBook.notes === 'string' ? olBook.notes : (olBook.subtitle || ''),
                        isbn: isbn
                    };
                }
            }
        } catch (e) {
            console.warn('Open Library lookup bypassed:', e.message);
        }

        // 2. Fallback to Google Books API
        if (!bookData) {
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 6000);
                const gbRes = await fetch(`https://www.googleapis.com/books/v1/volumes?q=isbn:${isbn}`, {
                    signal: controller.signal
                });
                clearTimeout(timeoutId);

                if (gbRes.ok) {
                    const gbJson = await gbRes.json();
                    if (gbJson.items && gbJson.items.length > 0) {
                        const info = gbJson.items[0].volumeInfo;
                        bookData = {
                            title: info.title || '',
                            author: (info.authors || []).join(', ') || '',
                            publisher: info.publisher || '',
                            year: info.publishedDate ? parseInt(info.publishedDate.substring(0, 4)) : '',
                            description: info.description || '',
                            isbn: isbn
                        };
                    }
                }
            } catch (e) {
                console.warn('Google Books lookup bypassed:', e.message);
            }
        }

        const form = document.getElementById(formId);
        if (form && form.elements['isbn']) {
            form.elements['isbn'].value = isbn;
        }

        if (!bookData) {
            toast(`ISBN ${isbn} recorded. (No online metadata match found)`, 'info');
            return;
        }

        // Auto-fill form fields
        if (form) {
            if (form.elements['title'] && bookData.title) form.elements['title'].value = bookData.title;
            if (form.elements['author'] && bookData.author) form.elements['author'].value = bookData.author;
            if (form.elements['publisher'] && bookData.publisher) form.elements['publisher'].value = bookData.publisher;
            if (form.elements['publication_year'] && bookData.year) form.elements['publication_year'].value = bookData.year;
            if (form.elements['description'] && bookData.description) form.elements['description'].value = bookData.description.slice(0, 600);
        }

        toast(`Book found: "${bookData.title}"! Details auto-filled. ✨`, 'success');

    } catch (err) {
        console.error('ISBN lookup error:', err);
        toast('Lookup completed. ISBN filled in.', 'info');
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// ─── HARDWARE BARCODE & ISBN SCANNER INTEGRATION ──────────────────────────────
// ═════════════════════════════════════════════════════════════════════════════

// Web Audio API scanner beep feedback
function playScanBeep(success = true) {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        if (success) {
            osc.frequency.setValueAtTime(880, ctx.currentTime); // A5 tone
            gain.gain.setValueAtTime(0.18, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.12);
            osc.start();
            osc.stop(ctx.currentTime + 0.12);
        } else {
            osc.frequency.setValueAtTime(320, ctx.currentTime); // low alert tone
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
            osc.start();
            osc.stop(ctx.currentTime + 0.25);
        }
    } catch (e) {}
}

// Global scanner wedge buffer & timing
let scannerBuffer = '';
let lastKeyTime = 0;
const SCANNER_FAST_THRESHOLD_MS = 65; // Barcode scanners send keys at ~15-40ms intervals

window.addEventListener('keydown', function (e) {
    const now = Date.now();
    const interval = now - lastKeyTime;
    lastKeyTime = now;

    // Handle Enter key (scanner terminating character)
    if (e.key === 'Enter') {
        const scannedText = scannerBuffer.trim();
        // Check if we captured a barcode sequence (at least 4 characters and rapid entry)
        if (scannedText.length >= 4) {
            scannerBuffer = '';
            e.preventDefault();
            handleHardwareScan(scannedText);
            return;
        }
        scannerBuffer = '';
        return;
    }

    // Ignore modifier keys and function keys
    if (e.key.length !== 1) {
        return;
    }

    // If keys are coming in rapidly, append to scanner buffer; otherwise reset buffer
    if (interval > SCANNER_FAST_THRESHOLD_MS && scannerBuffer.length > 0) {
        scannerBuffer = '';
    }
    scannerBuffer += e.key;
});

// Also handle direct scanning or typing into #global-search
const globalSearchInput = document.getElementById('global-search');
if (globalSearchInput) {
    globalSearchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            const val = this.value.trim();
            const cleanVal = val.replace(/[^0-9X]/gi, '');
            // If it looks like an ISBN (10 or 13 digits) or copy barcode (e.g. starts with BC-)
            if (cleanVal.length === 10 || cleanVal.length === 13 || /^BC-/i.test(val)) {
                e.preventDefault();
                this.value = '';
                handleHardwareScan(val);
            }
        }
    });
}

/**
 * Universal Hardware Scanner Handler
 * Context-aware: Auto-fills open forms or performs system-wide lookup
 */
async function handleHardwareScan(code) {
    code = (code || '').trim();
    if (!code) return;

    // 1. Context: Add Book modal is open
    const addBookIsbn = document.getElementById('add-book-isbn');
    if (addBookIsbn && document.getElementById('add-book-form')) {
        addBookIsbn.value = code;
        playScanBeep(true);
        toast(`Scanned ISBN: ${code}`, 'success');
        lookupBookByIsbn(code, 'add-book-form');
        return;
    }

    // 2. Context: Edit Book modal is open
    const editBookIsbn = document.getElementById('edit-book-isbn');
    if (editBookIsbn && document.getElementById('edit-book-form')) {
        editBookIsbn.value = code;
        playScanBeep(true);
        toast(`Scanned ISBN: ${code}`, 'success');
        lookupBookByIsbn(code, 'edit-book-form');
        return;
    }

    // 3. Context: Issue Book modal is open
    const issueForm = document.getElementById('issue-book-form');
    if (issueForm) {
        const copySelect = issueForm.elements['copy_id'];
        if (copySelect && copySelect.options) {
            let found = false;
            for (let i = 0; i < copySelect.options.length; i++) {
                const optText = copySelect.options[i].text.toLowerCase();
                if (optText.includes(code.toLowerCase())) {
                    copySelect.selectedIndex = i;
                    found = true;
                    break;
                }
            }
            if (found) {
                playScanBeep(true);
                toast(`Selected book copy: ${code}`, 'success');
                return;
            }
        }
    }

    // 4. General Context: Lookup in local database
    toast(`Scanning barcode: ${code}...`, 'info');

    // 4a. Try ISBN book lookup
    try {
        const res = await api(`api/books.php?isbn=${encodeURIComponent(code)}`);
        if (res && res.data && res.data.book_id) {
            playScanBeep(true);
            toast(`Found book: "${res.data.title}" ✨`, 'success');
            viewBook(res.data.book_id);
            return;
        }
    } catch (err) {
        // Not matched as ISBN in books table
    }

    // 4b. Try Copy Barcode lookup (e.g. BC-00101)
    try {
        const copyRes = await api(`api/book_copies.php?barcode=${encodeURIComponent(code)}`);
        if (copyRes && copyRes.data && copyRes.data.book_id) {
            playScanBeep(true);
            toast(`Found copy: ${copyRes.data.barcode} (${copyRes.data.status})`, 'success');
            viewBook(copyRes.data.book_id);
            return;
        }
    } catch (err) {
        // Not matched as copy barcode
    }

    // 4c. If on Books catalog page, filter the table
    const bookSearch = document.getElementById('book-search');
    if (bookSearch && State.currentPage === 'books') {
        bookSearch.value = code;
        renderBooks(1, code);
        playScanBeep(true);
        toast(`Catalog filtered for: ${code}`, 'info');
        return;
    }

    // Not found in database
    playScanBeep(false);
    toast(`No book or copy found for barcode: ${code}`, 'error');
}

// Backward-compatible helper for programmatic ISBN opening
function openBookByIsbn(isbn) {
    handleHardwareScan(isbn);
}
