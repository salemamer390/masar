-- MASAR Database Finalization Script
-- Target database: masar ONLY
-- Purpose: finalize relational integrity and dynamic-module foundations before UI work.
-- IMPORTANT: does not alter Payroll calculation formulas or existing payroll values.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- Hard safety guard: refuse to run against any database other than MASAR.
SET @masar_db := DATABASE();
SELECT CASE
    WHEN @masar_db = 'masar' THEN 'MASAR database confirmed.'
    ELSE CONCAT('STOP: Current database is ', COALESCE(@masar_db,'NULL'), ', not masar.')
END AS safety_check;

DELIMITER $$

DROP PROCEDURE IF EXISTS masar_add_column_if_missing$$
CREATE PROCEDURE masar_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF DATABASE() <> 'masar' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MASAR SAFETY GUARD: wrong database';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT(
            'ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition
        );
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS masar_add_fk_if_missing$$
CREATE PROCEDURE masar_add_fk_if_missing(
    IN p_constraint VARCHAR(64),
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_ref_table VARCHAR(64),
    IN p_ref_column VARCHAR(64),
    IN p_delete_rule VARCHAR(40),
    IN p_update_rule VARCHAR(40)
)
BEGIN
    IF DATABASE() <> 'masar' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MASAR SAFETY GUARD: wrong database';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = p_constraint
          AND TABLE_NAME = p_table
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) THEN
        SET @sql = CONCAT(
            'ALTER TABLE `', p_table,
            '` ADD CONSTRAINT `', p_constraint,
            '` FOREIGN KEY (`', p_column, '`) REFERENCES `',
            p_ref_table, '` (`', p_ref_column, '`) ',
            p_delete_rule, ' ', p_update_rule
        );
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS masar_add_unique_if_missing$$
CREATE PROCEDURE masar_add_unique_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_columns TEXT
)
BEGIN
    IF DATABASE() <> 'masar' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MASAR SAFETY GUARD: wrong database';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
          AND NON_UNIQUE = 0
    ) THEN
        SET @sql = CONCAT(
            'ALTER TABLE `', p_table,
            '` ADD UNIQUE INDEX `', p_index, '` (', p_columns, ')'
        );
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- 1) HR REQUESTS: normalize request type reference
-- ============================================================

CALL masar_add_column_if_missing(
    'hr_requests',
    'request_type_id',
    'BIGINT(20) UNSIGNED NULL AFTER request_type'
);

CALL masar_add_fk_if_missing(
    'fk_hr_requests_request_type',
    'hr_requests',
    'request_type_id',
    'request_types',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

-- Backfill any existing requests without changing the legacy request_type column.
UPDATE hr_requests r
JOIN request_types rt
  ON rt.code = r.request_type
SET r.request_type_id = rt.id
WHERE r.request_type_id IS NULL;

-- Seed the dynamic request-type catalog used by Dashboard / Requests / Notifications.
INSERT INTO request_types
    (code, display_name, source_key, icon, icon_color, icon_background, metadata, is_active, sort_order, created_at, updated_at)
VALUES
    ('leave', 'إجازة', 'leave', 'bi-calendar-check', '#EF4444', '#FEF2F2', '{"module":"leaves"}', 1, 10, NOW(), NOW()),
    ('advance', 'سلفة', 'advance', 'bi-cash-stack', '#10B981', '#ECFDF5', '{"module":"advances"}', 1, 20, NOW(), NOW()),
    ('permission', 'إذن', 'permission', 'bi-file-signature', '#F59E0B', '#FFFBEB', '{"module":"permissions"}', 1, 30, NOW(), NOW()),
    ('mission', 'مأمورية', 'mission', 'bi-briefcase', '#2563EB', '#EFF6FF', '{"module":"missions"}', 1, 40, NOW(), NOW()),
    ('document_request', 'طلب مستند', 'document_request', 'bi-file-earmark-text', '#7C3AED', '#F5F3FF', '{"module":"documents"}', 1, 50, NOW(), NOW()),
    ('other', 'طلب آخر', 'other', 'bi-clipboard-check', '#64748B', '#F1F5F9', '{"module":"requests"}', 1, 60, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    source_key = VALUES(source_key),
    icon = VALUES(icon),
    icon_color = VALUES(icon_color),
    icon_background = VALUES(icon_background),
    metadata = VALUES(metadata),
    is_active = VALUES(is_active),
    sort_order = VALUES(sort_order),
    updated_at = NOW();

-- ============================================================
-- 2) DOCUMENT GOVERNANCE
-- ============================================================

CALL masar_add_fk_if_missing(
    'fk_documents_type',
    'documents',
    'document_type_id',
    'document_types',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_documents_uploaded_by',
    'documents',
    'uploaded_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_documents_replaced_document',
    'documents',
    'replaced_document_id',
    'documents',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

-- sent_at is a historical event timestamp; updating the row must not rewrite it.
ALTER TABLE document_expiry_notifications
    MODIFY sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

CALL masar_add_fk_if_missing(
    'fk_document_expiry_notification_document',
    'document_expiry_notifications',
    'document_id',
    'documents',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

-- ============================================================
-- 3) EMPLOYEE SUPPORTING DATA
-- ============================================================

CALL masar_add_fk_if_missing(
    'fk_employee_bank_accounts_employee',
    'employee_bank_accounts',
    'employee_id',
    'employees',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_employee_bank_accounts_bank',
    'employee_bank_accounts',
    'bank_id',
    'banks',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

-- employee_profile_details is a 1:1 extension of employees.
CALL masar_add_fk_if_missing(
    'fk_employee_profile_details_employee',
    'employee_profile_details',
    'employee_id',
    'employees',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_employee_emergency_contacts_employee',
    'employee_emergency_contacts',
    'employee_id',
    'employees',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_employees_payment_method',
    'employees',
    'payment_method_id',
    'payment_methods',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

-- ============================================================
-- 4) ATTENDANCE
-- ============================================================

-- Attendance already has a unique employee/date rule in the audited DB.
-- Add/confirm the employee and approval relationships.
CALL masar_add_fk_if_missing(
    'fk_attendance_employee',
    'attendance',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'attendance_approved_by_foreign',
    'attendance',
    'approved_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

-- ============================================================
-- 5) LEAVES
-- ============================================================

CALL masar_add_fk_if_missing(
    'fk_leaves_employee',
    'leaves',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_leaves_type',
    'leaves',
    'leave_type_id',
    'leave_types',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_leaves_approver',
    'leaves',
    'approved_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'leaves_manager_approved_by_foreign',
    'leaves',
    'manager_approved_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

-- ============================================================
-- 6) PENALTIES
-- ============================================================

CALL masar_add_fk_if_missing(
    'fk_penalties_employee',
    'penalties',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_penalties_type',
    'penalties',
    'penalty_type_id',
    'penalty_types',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_penalties_issuer',
    'penalties',
    'issued_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

-- ============================================================
-- 7) MOVEMENTS / HISTORY
-- ============================================================

CALL masar_add_fk_if_missing(
    'fk_movements_employee',
    'movement_records',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_movements_type',
    'movement_records',
    'movement_type_id',
    'movement_types',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_movements_old_department',
    'movement_records',
    'old_department_id',
    'departments',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_movements_new_department',
    'movement_records',
    'new_department_id',
    'departments',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_movements_old_job',
    'movement_records',
    'old_job_id',
    'jobs',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_movements_new_job',
    'movement_records',
    'new_job_id',
    'jobs',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_movements_creator',
    'movement_records',
    'created_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_movements_old_branch',
    'movement_records',
    'old_branch_id',
    'branches',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_movements_new_branch',
    'movement_records',
    'new_branch_id',
    'branches',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_movements_old_manager',
    'movement_records',
    'old_manager_id',
    'employees',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_movements_new_manager',
    'movement_records',
    'new_manager_id',
    'employees',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

-- ============================================================
-- 8) SALARY STRUCTURE / SALARY HISTORY
-- ============================================================

CALL masar_add_fk_if_missing(
    'fk_salaries_employee',
    'salaries',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_salaries_payment_method',
    'salaries',
    'payment_method_id',
    'payment_methods',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_salary_history_employee',
    'salary_history',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_salary_history_salary',
    'salary_history',
    'salary_id',
    'salaries',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_salary_history_created_by',
    'salary_history',
    'created_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_unique_if_missing(
    'salaries',
    'uq_salaries_employee_effective_from',
    '`employee_id`, `effective_from`'
);

CALL masar_add_unique_if_missing(
    'salary_history',
    'uq_salary_history_employee_effective_from',
    '`employee_id`, `effective_from`'
);

-- ============================================================
-- 9) INSURANCE
-- ============================================================

CALL masar_add_fk_if_missing(
    'fk_insurance_employee',
    'insurance_records',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_insurance_job_code',
    'insurance_records',
    'insurance_job_code_id',
    'insurance_job_codes',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

-- Current insurance record points to the salary structure snapshot from which
-- the insured wage was derived. The actual insured wage remains a snapshot.
CALL masar_add_column_if_missing(
    'insurance_records',
    'salary_id',
    'BIGINT(20) UNSIGNED NULL AFTER insurance_job_code_id'
);

CALL masar_add_fk_if_missing(
    'fk_insurance_salary',
    'insurance_records',
    'salary_id',
    'salaries',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_unique_if_missing(
    'insurance_records',
    'uq_insurance_records_employee',
    '`employee_id`'
);

CALL masar_add_fk_if_missing(
    'insurance_record_history_employee',
    'insurance_record_history',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'insurance_record_history_insurance_record',
    'insurance_record_history',
    'insurance_record_id',
    'insurance_records',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'insurance_record_history_changed_by',
    'insurance_record_history',
    'changed_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

-- ============================================================
-- 10) OVERTIME
-- ============================================================

CALL masar_add_fk_if_missing(
    'fk_overtime_employee',
    'overtime_records',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_overtime_payroll_record',
    'overtime_records',
    'payroll_record_id',
    'payroll_records',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_overtime_approved_by',
    'overtime_records',
    'approved_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

-- ============================================================
-- 11) PAYROLL RELATIONAL INTEGRITY
--     No calculation formula/value is changed here.
-- ============================================================

CALL masar_add_fk_if_missing(
    'fk_payroll_employee',
    'payroll_records',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_payroll_salary',
    'payroll_records',
    'salary_id',
    'salaries',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_payroll_run',
    'payroll_records',
    'payroll_run_id',
    'payroll_runs',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_payroll_approved_by',
    'payroll_records',
    'approved_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_payroll_run_created_by',
    'payroll_runs',
    'created_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_payroll_run_approved_by',
    'payroll_runs',
    'approved_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_payroll_run_locked_by',
    'payroll_runs',
    'locked_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_payroll_run_finalized_by',
    'payroll_runs',
    'finalized_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_earnings_payroll_record_id_foreign',
    'payroll_earnings',
    'payroll_record_id',
    'payroll_records',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_earnings_employee_id_foreign',
    'payroll_earnings',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_deductions_payroll_record_id_foreign',
    'payroll_deductions',
    'payroll_record_id',
    'payroll_records',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_items_payroll_run_id_foreign',
    'payroll_items',
    'payroll_run_id',
    'payroll_runs',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_items_employee_id_foreign',
    'payroll_items',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_items_payroll_record_id_foreign',
    'payroll_items',
    'payroll_record_id',
    'payroll_records',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_snapshots_payroll_record_id_foreign',
    'payroll_snapshots',
    'payroll_record_id',
    'payroll_records',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_snapshots_employee_id_foreign',
    'payroll_snapshots',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_snapshots_created_by_foreign',
    'payroll_snapshots',
    'created_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_calculation_snapshots_payroll_record_id_foreign',
    'payroll_calculation_snapshots',
    'payroll_record_id',
    'payroll_records',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_calculation_snapshots_payroll_run_id_foreign',
    'payroll_calculation_snapshots',
    'payroll_run_id',
    'payroll_runs',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_calculation_snapshots_employee_id_foreign',
    'payroll_calculation_snapshots',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'payroll_setting_changes_changed_by_foreign',
    'payroll_setting_changes',
    'changed_by',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

-- ============================================================
-- 12) TAX / YEARLY EMPLOYEE RECORDS
-- ============================================================

CALL masar_add_fk_if_missing(
    'employee_tax_years_employee_id_foreign',
    'employee_tax_years',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'tax_reconciliations_employee_id_foreign',
    'tax_reconciliations',
    'employee_id',
    'employees',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

-- ============================================================
-- 13) SECURITY / AUDIT
-- ============================================================

CALL masar_add_fk_if_missing(
    'fk_users_role',
    'users',
    'role_id',
    'roles',
    'id',
    'ON DELETE RESTRICT',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_users_employee',
    'users',
    'employee_id',
    'employees',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_activity_logs_user',
    'activity_logs',
    'user_id',
    'users',
    'id',
    'ON DELETE SET NULL',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_role_permissions_role',
    'role_permissions',
    'role_id',
    'roles',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_role_permissions_permission',
    'role_permissions',
    'permission_id',
    'permissions',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

CALL masar_add_fk_if_missing(
    'fk_sessions_user',
    'sessions',
    'user_id',
    'users',
    'id',
    'ON DELETE CASCADE',
    'ON UPDATE CASCADE'
);

-- ============================================================
-- 14) FINAL CHECKS
-- ============================================================

SELECT DATABASE() AS active_database;

SELECT COUNT(*) AS total_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE();

SELECT
    'request_types' AS table_name,
    COUNT(*) AS records
FROM request_types
UNION ALL
SELECT 'employees', COUNT(*) FROM employees
UNION ALL
SELECT 'salaries', COUNT(*) FROM salaries
UNION ALL
SELECT 'insurance_records', COUNT(*) FROM insurance_records
UNION ALL
SELECT 'hr_requests', COUNT(*) FROM hr_requests
UNION ALL
SELECT 'users', COUNT(*) FROM users;

SELECT
    TABLE_NAME,
    COUNT(*) AS foreign_keys
FROM information_schema.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
GROUP BY TABLE_NAME
ORDER BY TABLE_NAME;

-- Cleanup helper procedures.
DROP PROCEDURE IF EXISTS masar_add_column_if_missing;
DROP PROCEDURE IF EXISTS masar_add_fk_if_missing;
DROP PROCEDURE IF EXISTS masar_add_unique_if_missing;

SELECT 'MASAR DATABASE FINALIZATION COMPLETED' AS result;
