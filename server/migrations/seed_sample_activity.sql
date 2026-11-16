-- ====================================================================
-- School Library Management System (LIBRARY_MS)
-- Neon PostgreSQL Seeder: Borrowings, Reservations, & Schema Alignment
-- Paste and execute this in your Neon Console SQL Editor
-- ====================================================================

-- 1. Ensure copy_id column exists on reservations
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS copy_id INTEGER REFERENCES book_copies(copy_id) ON DELETE SET NULL;

-- 2. Ensure status constraint supports 'Rejected'
ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_status_check;
ALTER TABLE reservations ADD CONSTRAINT reservations_status_check CHECK (status IN ('Pending', 'Approved', 'Cancelled', 'Fulfilled', 'Rejected'));

-- 3. Ensure Admin and Librarian users exist (Password: password123 or admin123 / librarian123)
INSERT INTO users (username, full_name, employee_id, email, password_hash, role, is_active)
VALUES 
    ('admin', 'System Administrator', 'EMP-001', 'admin@school.edu', '$2y$10$XPNtktEBi8trLAK/aBo1Oes5yP7NK6isgwVtviNVviAHeeseuQM3G', 'Admin', 1)
ON CONFLICT (username) DO UPDATE 
SET role = 'Admin', is_active = 1, password_hash = '$2y$10$XPNtktEBi8trLAK/aBo1Oes5yP7NK6isgwVtviNVviAHeeseuQM3G';

INSERT INTO users (username, full_name, employee_id, email, password_hash, role, is_active)
VALUES 
    ('librarian', 'Chief Librarian', 'EMP-002', 'librarian@school.edu', '$2y$10$XPNtktEBi8trLAK/aBo1Oes5yP7NK6isgwVtviNVviAHeeseuQM3G', 'Librarian', 1)
ON CONFLICT (username) DO UPDATE 
SET role = 'Librarian', is_active = 1, password_hash = '$2y$10$XPNtktEBi8trLAK/aBo1Oes5yP7NK6isgwVtviNVviAHeeseuQM3G';

-- 4. Link sample data to user 'wewe' or first student user
DO $$
DECLARE
    target_user_id INTEGER;
    admin_id INTEGER;
    book1_id INTEGER;
    book2_id INTEGER;
    book3_id INTEGER;
    copy1_id INTEGER;
    copy2_id INTEGER;
    copy3_id INTEGER;
BEGIN
    -- Find student user (wewe or any user with role 'User')
    SELECT user_id INTO target_user_id FROM users WHERE username = 'wewe' LIMIT 1;
    IF target_user_id IS NULL THEN
        SELECT user_id INTO target_user_id FROM users WHERE role = 'User' LIMIT 1;
    END IF;

    -- Find an admin or librarian
    SELECT user_id INTO admin_id FROM users WHERE role IN ('Admin', 'Librarian') LIMIT 1;
    IF admin_id IS NULL THEN
        admin_id := 1;
    END IF;

    IF target_user_id IS NOT NULL THEN
        -- Get books and copies
        SELECT book_id INTO book1_id FROM books ORDER BY book_id ASC LIMIT 1;
        SELECT book_id INTO book2_id FROM books ORDER BY book_id ASC OFFSET 1 LIMIT 1;
        SELECT book_id INTO book3_id FROM books ORDER BY book_id ASC OFFSET 2 LIMIT 1;

        SELECT copy_id INTO copy1_id FROM book_copies WHERE book_id = book1_id LIMIT 1;
        SELECT copy_id INTO copy2_id FROM book_copies WHERE book_id = book2_id LIMIT 1;
        SELECT copy_id INTO copy3_id FROM book_copies WHERE book_id = book3_id LIMIT 1;

        -- 3a. Insert sample Borrowing Records if student has none
        IF NOT EXISTS (SELECT 1 FROM borrowing_records WHERE user_id = target_user_id) THEN
            -- Active borrowed book (due in 7 days)
            IF copy1_id IS NOT NULL THEN
                INSERT INTO borrowing_records (user_id, copy_id, issued_by, borrow_date, due_date, status)
                VALUES (target_user_id, copy1_id, admin_id, CURRENT_DATE - INTERVAL '3 days', CURRENT_DATE + INTERVAL '7 days', 'Borrowed');
                UPDATE book_copies SET status = 'Borrowed' WHERE copy_id = copy1_id;
            END IF;

            -- Past returned book
            IF copy2_id IS NOT NULL THEN
                INSERT INTO borrowing_records (user_id, copy_id, issued_by, borrow_date, due_date, return_date, status)
                VALUES (target_user_id, copy2_id, admin_id, CURRENT_DATE - INTERVAL '20 days', CURRENT_DATE - INTERVAL '6 days', CURRENT_DATE - INTERVAL '7 days', 'Returned');
            END IF;
        END IF;

        -- 3b. Insert sample Reservations if student has none
        IF NOT EXISTS (SELECT 1 FROM reservations WHERE user_id = target_user_id) THEN
            -- Pending reservation (waiting for librarian approval)
            IF book2_id IS NOT NULL THEN
                INSERT INTO reservations (user_id, book_id, reservation_date, status)
                VALUES (target_user_id, book2_id, CURRENT_TIMESTAMP - INTERVAL '1 day', 'Pending');
            END IF;

            -- Approved reservation (copy reserved, stock deducted by 1)
            IF book3_id IS NOT NULL AND copy3_id IS NOT NULL THEN
                INSERT INTO reservations (user_id, book_id, copy_id, reservation_date, status)
                VALUES (target_user_id, book3_id, copy3_id, CURRENT_TIMESTAMP - INTERVAL '2 days', 'Approved');
                UPDATE book_copies SET status = 'Reserved' WHERE copy_id = copy3_id;
            END IF;
        END IF;
    END IF;
END $$;

-- Verify results
SELECT 'Reservations Count' AS check_type, COUNT(*) AS count FROM reservations
UNION ALL
SELECT 'Borrowing Records Count', COUNT(*) FROM borrowing_records;

