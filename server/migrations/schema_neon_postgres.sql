-- ====================================================================
-- Automated School Library Management System (LIBRARY_MS)
-- Target Database: Neon Serverless PostgreSQL / Render PostgreSQL
-- File: schema_neon_postgres.sql
-- ====================================================================

-- 1. Drop existing tables if needed (cascade to clean foreign keys)
DROP TABLE IF EXISTS activity_logs CASCADE;
DROP TABLE IF EXISTS reservations CASCADE;
DROP TABLE IF EXISTS borrowing_records CASCADE;
DROP TABLE IF EXISTS book_copies CASCADE;
DROP TABLE IF EXISTS books CASCADE;
DROP TABLE IF EXISTS categories CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS students CASCADE;
DROP TABLE IF EXISTS courses CASCADE;

-- 2. Create Courses Table
CREATE TABLE courses (
    id SERIAL PRIMARY KEY,
    course_code VARCHAR(20) NOT NULL UNIQUE,
    course_name VARCHAR(100) NOT NULL
);

-- 3. Create Students Table
CREATE TABLE students (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    student_number VARCHAR(50) UNIQUE,
    gender VARCHAR(20) NOT NULL,
    year_level INTEGER NOT NULL,
    course_id INTEGER NOT NULL REFERENCES courses(id) ON UPDATE CASCADE
);

CREATE INDEX idx_students_course ON students(course_id);

-- 4. Create Users Table
CREATE TABLE users (
    user_id SERIAL PRIMARY KEY,
    student_id INTEGER REFERENCES students(id) ON DELETE SET NULL ON UPDATE CASCADE,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    employee_id VARCHAR(50) UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'User' CHECK (role IN ('Admin', 'Librarian', 'User')),
    is_active SMALLINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_users_student ON users(student_id);

-- 5. Create Categories Table
CREATE TABLE categories (
    category_id SERIAL PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    category_type VARCHAR(20) NOT NULL DEFAULT 'Academic' CHECK (category_type IN ('Academic', 'Non-Academic')),
    description TEXT
);

-- 6. Create Books Table
CREATE TABLE books (
    book_id SERIAL PRIMARY KEY,
    category_id INTEGER REFERENCES categories(category_id) ON DELETE SET NULL ON UPDATE CASCADE,
    isbn VARCHAR(20) NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    author VARCHAR(150) NOT NULL,
    publisher VARCHAR(150),
    publication_year INTEGER,
    description TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_books_category ON books(category_id);
CREATE INDEX idx_books_isbn ON books(isbn);

-- 7. Create Book Copies Table
CREATE TABLE book_copies (
    copy_id SERIAL PRIMARY KEY,
    book_id INTEGER NOT NULL REFERENCES books(book_id) ON UPDATE CASCADE ON DELETE CASCADE,
    barcode VARCHAR(100) NOT NULL UNIQUE,
    status VARCHAR(20) NOT NULL DEFAULT 'Available' CHECK (status IN ('Available', 'Borrowed', 'Reserved', 'Lost', 'Damaged'))
);

CREATE INDEX idx_copies_book ON book_copies(book_id);
CREATE INDEX idx_copies_barcode ON book_copies(barcode);

-- 8. Create Borrowing Records Table
CREATE TABLE borrowing_records (
    borrow_id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON UPDATE CASCADE,
    copy_id INTEGER NOT NULL REFERENCES book_copies(copy_id) ON UPDATE CASCADE,
    issued_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    borrow_date DATE NOT NULL,
    due_date DATE NOT NULL,
    return_date DATE,
    status VARCHAR(20) NOT NULL DEFAULT 'Borrowed' CHECK (status IN ('Borrowed', 'Returned', 'Overdue')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_borrow_user ON borrowing_records(user_id);
CREATE INDEX idx_borrow_copy ON borrowing_records(copy_id);
CREATE INDEX idx_borrow_issued ON borrowing_records(issued_by);

-- 9. Create Reservations Table
CREATE TABLE reservations (
    reservation_id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON UPDATE CASCADE,
    book_id INTEGER NOT NULL REFERENCES books(book_id) ON UPDATE CASCADE,
    copy_id INTEGER REFERENCES book_copies(copy_id) ON DELETE SET NULL,
    reservation_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending', 'Approved', 'Cancelled', 'Fulfilled', 'Rejected'))
);

CREATE INDEX idx_reservation_user ON reservations(user_id);
CREATE INDEX idx_reservation_book ON reservations(book_id);
CREATE INDEX idx_reservation_copy ON reservations(copy_id);

-- 10. Create Activity Logs Table
CREATE TABLE activity_logs (
    activity_id BIGSERIAL PRIMARY KEY,
    admin_user_id INTEGER NOT NULL,
    admin_username VARCHAR(100) NOT NULL,
    target_user_id INTEGER NOT NULL,
    target_username VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_activity_target ON activity_logs(target_user_id, created_at);
CREATE INDEX idx_activity_admin ON activity_logs(admin_user_id, created_at);


-- ====================================================================
-- SEED DATA FOR PRODUCTION / CLOUD INITIALIZATION
-- ====================================================================

-- Courses (17 Academic Programs)
INSERT INTO courses (id, course_code, course_name) VALUES
(1, 'BSIT', 'Bachelor of Science in Information Technology'),
(2, 'BSM', 'Bachelor of Science in Mathematics'),
(3, 'BPED', 'Bachelor of Physical Education'),
(4, 'BECED', 'Bachelor of Early Childhood Education'),
(5, 'BSA', 'Bachelor of Science in Accountancy'),
(6, 'BSED', 'Bachelor of Secondary Education'),
(7, 'BSBIO', 'Bachelor of Science in Biology'),
(8, 'BSF', 'Bachelor of Science in Forestry'),
(9, 'BTLED', 'Bachelor of Technology and Livelihood Education'),
(10, 'BAT', 'Bachelor of Agricultural Technology'),
(11, 'BSHT', 'Bachelor of Science in Home Technology'),
(12, 'BSAM', 'Bachelor of Science in Agricultural Management'),
(13, 'BEED', 'Bachelor of Elementary Education'),
(14, 'BCM', 'Bachelor of Communication Management'),
(15, 'BSAB', 'Bachelor of Science in Agricultural Business'),
(16, 'BSB', 'Bachelor of Science in Business'),
(17, 'BSES', 'Bachelor of Science in Environmental Science')
ON CONFLICT (id) DO NOTHING;

SELECT setval('courses_id_seq', (SELECT MAX(id) FROM courses));

-- Categories (20 Academic & Non-Academic Categories)
INSERT INTO categories (category_id, category_name, category_type, description) VALUES
(1, 'Programming', 'Academic', 'Software engineering and coding books'),
(2, 'Classic Literature', 'Academic', 'Classic literary works, poetry, and foundational world literature'),
(3, 'Technology', 'Academic', 'Computer science, databases and IT'),
(4, 'Psychology', 'Academic', 'Behavioral and cognitive sciences'),
(5, 'Mathematics', 'Academic', 'Calculus, algebra, and applied math'),
(6, 'Science', 'Academic', 'Physics, chemistry, and biology'),
(7, 'Business & Accountancy', 'Academic', 'Finance, accounting, marketing, management, and economics'),
(8, 'Education & Teaching', 'Academic', 'Pedagogy, curriculum, educational leadership, and instructional methods'),
(9, 'Health & Medical Sciences', 'Academic', 'Nursing, medicine, public health, pharmacology, and anatomy'),
(10, 'Social Sciences & History', 'Academic', 'World history, sociology, political science, anthropology, and culture'),
(11, 'Reference & Dictionaries', 'Academic', 'Encyclopedias, language dictionaries, almanacs, and citation guides'),
(12, 'Engineering & Architecture', 'Academic', 'Civil, electrical, mechanical engineering, CAD, and architectural design'),
(13, 'Law & Legal Studies', 'Academic', 'Constitutional law, civil procedure, business law, and jurisprudence'),
(14, 'Fiction & Fantasy', 'Non-Academic', 'Popular novels, fantasy worlds, mystery, thriller, and science fiction'),
(15, 'Self-Help & Personal Growth', 'Non-Academic', 'Habit building, productivity, personal wellness, and motivational works'),
(16, 'Biography & Memoir', 'Non-Academic', 'Life stories, autobiographies, inspirational figures, and historical memoirs'),
(17, 'Graphic Novels & Comics', 'Non-Academic', 'Illustrated novels, comic strips, manga, and visual storytelling'),
(18, 'Arts, Music & Design', 'Non-Academic', 'Visual arts, music theory, graphic design, photography, and film studies'),
(19, 'Philosophy & Religion', 'Non-Academic', 'Spiritual texts, ethical philosophies, world religions, and existential thought'),
(20, 'General Knowledge & Hobbies', 'Non-Academic', 'Trivia, cooking, sports, travel guides, and general interest')
ON CONFLICT (category_id) DO NOTHING;

SELECT setval('categories_category_id_seq', (SELECT MAX(category_id) FROM categories));

-- Default System Accounts (Password for all default accounts is: password123 or admin123)
-- Admin: username 'admin', email 'admin@school.edu'
-- Librarian: username 'librarian', email 'librarian@school.edu'
INSERT INTO users (user_id, student_id, username, full_name, employee_id, email, password_hash, role, is_active) VALUES
(1, NULL, 'admin', 'System Administrator', 'EMP-001', 'admin@school.edu', '$2y$10$cLOwKKpuSs2yPkI4.x8w4ObRgYVR702bAuNiF12DvYBBesjYSEJ42', 'Admin', 1),
(2, NULL, 'librarian', 'Chief Librarian', 'EMP-002', 'librarian@school.edu', '$2y$10$JWhKHRI1DyAflCTELriwFe2rTCqY9BKGe2fuvhZFdzy3dUDqte1kG', 'Librarian', 1)
ON CONFLICT (user_id) DO NOTHING;

SELECT setval('users_user_id_seq', (SELECT MAX(user_id) FROM users));

-- Initial Books Catalog
INSERT INTO books (book_id, category_id, isbn, title, author, publisher, publication_year, description) VALUES
(1, 1, '9780132350884', 'Clean Code', 'Robert C. Martin', 'Prentice Hall', 2008, 'A handbook of agile software craftsmanship.'),
(2, 2, '9780743273565', 'The Great Gatsby', 'F. Scott Fitzgerald', 'Scribner', 1925, 'The story of Jay Gatsby and his love for Daisy Buchanan.'),
(3, 3, '9780133970777', 'Database Systems', 'Ramez Elmasri', 'Pearson', 2015, 'Fundamentals of database systems and SQL.'),
(4, 4, '9781319103806', 'Introduction to Psychology', 'David G. Myers', 'Worth Publishers', 2018, 'Core concepts and principles of psychology.'),
(5, 5, '9781285741552', 'Calculus', 'James Stewart', 'Cengage Learning', 2015, 'Early transcendentals calculus text.'),
(6, 6, '9781337553292', 'Physics for Scientists and Engineers', 'Serway & Jewett', 'Cengage Learning', 2018, 'Physics principles for scientists and engineers.'),
(7, 15, '9780802416919', '101 Questions You Need to Ask in Your Twenties', 'Paul Angone', 'Moody Publishers', 2018, 'Life guidance for young adults entering career and university.'),
(8, 14, '9780590353427', 'Harry Potter and the Sorcerer''s Stone', 'J. K. Rowling', 'Scholastic Inc.', 1999, 'A young wizard discovers his magical identity at Hogwarts.'),
(9, 1, '9780135957059', 'The Pragmatic Programmer: Your Journey to Mastery', 'David Thomas, Andrew Hunt', 'Addison-Wesley', 2019, 'Coding craftsmanship and software architecture.'),
(10, 1, '9780262046305', 'Introduction to Algorithms (4th Edition)', 'Thomas H. Cormen, et al.', 'MIT Press', 2022, 'The standard textbook covering modern algorithms.')
ON CONFLICT (book_id) DO NOTHING;

SELECT setval('books_book_id_seq', (SELECT MAX(book_id) FROM books));

-- Initial Book Copies
INSERT INTO book_copies (copy_id, book_id, barcode, status) VALUES
(1, 1, 'BC-00101', 'Available'),
(2, 1, 'BC-00102', 'Available'),
(3, 2, 'BC-00201', 'Available'),
(4, 3, 'BC-00301', 'Available'),
(5, 4, 'BC-00401', 'Available'),
(6, 5, 'BC-00501', 'Available'),
(7, 6, 'BC-00601', 'Available'),
(8, 7, 'BC007-01', 'Available'),
(9, 8, 'BC008-01', 'Available'),
(10, 9, 'BC009-01', 'Available'),
(11, 10, 'BC010-01', 'Available')
ON CONFLICT (copy_id) DO NOTHING;

SELECT setval('book_copies_copy_id_seq', (SELECT MAX(copy_id) FROM book_copies));

