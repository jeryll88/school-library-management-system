-- ====================================================================
-- School Library Management System - Database Migration
-- Database: school_db
-- ====================================================================

USE school_db;

-- 1. Ensure students table has student_number column for school-issued student IDs
--    and add UNIQUE constraint to prevent duplicate student IDs.
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'school_db' 
      AND TABLE_NAME = 'students' 
      AND COLUMN_NAME = 'student_number'
);

-- If student_number does not exist, run:
-- ALTER TABLE students ADD COLUMN student_number VARCHAR(50) NULL AFTER name;
-- UPDATE students SET student_number = CONCAT('STU-2026-', LPAD(id, 4, '0')) WHERE student_number IS NULL;
-- ALTER TABLE students ADD UNIQUE KEY uq_students_student_number (student_number);

-- 2. Ensure users table has is_active column for activating/deactivating accounts
--    (Default is 1 = Active, 0 = Inactive/Deactivated)
-- ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;

-- 3. Verify uniqueness on users.username and users.email
-- ALTER TABLE users ADD UNIQUE KEY uq_users_username (username);
-- ALTER TABLE users ADD UNIQUE KEY uq_users_email (email);
