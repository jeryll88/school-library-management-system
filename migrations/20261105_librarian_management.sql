-- Inspect the existing school_db schema and transaction relationships first:
-- SHOW CREATE TABLE school_db.users;
-- SHOW CREATE TABLE school_db.borrowing_records;
-- SHOW CREATE TABLE school_db.reservations;
--
-- This migration reuses users; it does not create another account table.
-- Existing employee IDs remain nullable so existing users/accounts are preserved.

USE school_db;

SET @employee_id_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'school_db'
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'employee_id'
);
SET @ddl = IF(
    @employee_id_exists = 0,
    'ALTER TABLE users ADD COLUMN employee_id VARCHAR(50) NULL AFTER full_name',
    'SELECT ''users.employee_id already exists'' AS migration_note'
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Add unique indexes only when existing data supports them.
-- Duplicate legacy accounts are retained and reported instead of being modified.
SET @employee_id_index_exists = (
    SELECT COUNT(*)
    FROM (
        SELECT INDEX_NAME
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = 'school_db'
          AND TABLE_NAME = 'users'
          AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = 'employee_id'
    ) AS unique_indexes
);
SET @duplicate_employee_ids = (
    SELECT COUNT(*)
    FROM (
        SELECT LOWER(employee_id)
        FROM users
        WHERE employee_id IS NOT NULL AND employee_id <> ''
        GROUP BY LOWER(employee_id)
        HAVING COUNT(*) > 1
    ) AS duplicates
);
SET @ddl = IF(
    @employee_id_index_exists > 0,
    'SELECT ''users.employee_id already has a unique index'' AS migration_note',
    IF(
        @duplicate_employee_ids = 0,
        'ALTER TABLE users ADD UNIQUE KEY uq_users_employee_id (employee_id)',
        'SELECT ''Resolve duplicate employee IDs before adding uq_users_employee_id'' AS migration_warning'
    )
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @username_index_exists = (
    SELECT COUNT(*)
    FROM (
        SELECT INDEX_NAME
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = 'school_db'
          AND TABLE_NAME = 'users'
          AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = 'username'
    ) AS unique_indexes
);
SET @duplicate_usernames = (
    SELECT COUNT(*)
    FROM (
        SELECT LOWER(username)
        FROM users
        WHERE username IS NOT NULL
        GROUP BY LOWER(username)
        HAVING COUNT(*) > 1
    ) AS duplicates
);
SET @ddl = IF(
    @username_index_exists > 0,
    'SELECT ''users.username already has a unique index'' AS migration_note',
    IF(
        @duplicate_usernames = 0,
        'ALTER TABLE users ADD UNIQUE KEY uq_users_username (username)',
        'SELECT ''Resolve duplicate usernames before adding uq_users_username'' AS migration_warning'
    )
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @email_index_exists = (
    SELECT COUNT(*)
    FROM (
        SELECT INDEX_NAME
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = 'school_db'
          AND TABLE_NAME = 'users'
          AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = 'email'
    ) AS unique_indexes
);
SET @duplicate_emails = (
    SELECT COUNT(*)
    FROM (
        SELECT LOWER(email)
        FROM users
        WHERE email IS NOT NULL
        GROUP BY LOWER(email)
        HAVING COUNT(*) > 1
    ) AS duplicates
);
SET @ddl = IF(
    @email_index_exists > 0,
    'SELECT ''users.email already has a unique index'' AS migration_note',
    IF(
        @duplicate_emails = 0,
        'ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)',
        'SELECT ''Resolve duplicate email addresses before adding uq_users_email'' AS migration_warning'
    )
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

CREATE TABLE IF NOT EXISTS activity_logs (
    activity_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id INT NOT NULL,
    admin_username VARCHAR(100) NOT NULL,
    target_user_id INT NOT NULL,
    target_username VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (activity_id),
    KEY idx_activity_logs_target (target_user_id, created_at),
    KEY idx_activity_logs_admin (admin_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
