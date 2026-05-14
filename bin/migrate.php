<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = App\Core\Database::connection();

ensure_table($pdo, 'user_permissions', "
    CREATE TABLE user_permissions (
        user_id INT UNSIGNED NOT NULL,
        permission_key VARCHAR(120) NOT NULL,
        granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, permission_key),
        CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_permissions_key (permission_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_table($pdo, 'user_sessions', "
    CREATE TABLE user_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        session_id VARCHAR(128) NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        expires_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_sessions_token (token_hash),
        INDEX idx_user_sessions_user_active (user_id, revoked_at, expires_at),
        INDEX idx_user_sessions_expires_at (expires_at),
        CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'customers', 'parasut_contact_id', 'VARCHAR(64) NULL AFTER id');
ensure_column($pdo, 'customers', 'tax_office', 'VARCHAR(120) NULL AFTER phone');
ensure_column($pdo, 'customers', 'tax_number', 'VARCHAR(60) NULL AFTER tax_office');
ensure_column($pdo, 'customers', 'city', 'VARCHAR(120) NULL AFTER tax_number');
ensure_column($pdo, 'customers', 'district', 'VARCHAR(120) NULL AFTER city');
ensure_column($pdo, 'customers', 'address', 'TEXT NULL AFTER district');
ensure_column($pdo, 'customers', 'deleted_at', 'DATETIME NULL AFTER notes');
ensure_index($pdo, 'customers', 'uq_customers_parasut_contact', 'UNIQUE KEY uq_customers_parasut_contact (parasut_contact_id)');
ensure_index($pdo, 'customers', 'idx_customers_deleted_at', 'INDEX idx_customers_deleted_at (deleted_at)');
ensure_table($pdo, 'customer_contacts', "
    CREATE TABLE customer_contacts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        full_name VARCHAR(190) NOT NULL,
        role_title VARCHAR(120) NULL,
        email VARCHAR(190) NULL,
        phone VARCHAR(60) NULL,
        notify_enabled TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_customer_contacts_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        INDEX idx_customer_contacts_customer (customer_id),
        INDEX idx_customer_contacts_notify (notify_enabled),
        INDEX idx_customer_contacts_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'customer_contacts', 'role_title', 'VARCHAR(120) NULL AFTER full_name');
ensure_table($pdo, 'contact_role_definitions', "
    CREATE TABLE contact_role_definitions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_contact_role_definitions_name (name),
        INDEX idx_contact_role_definitions_active (is_active, sort_order, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
seed_default_contact_roles($pdo);
seed_existing_customer_contacts($pdo);
ensure_table($pdo, 'supplier_groups', "
    CREATE TABLE supplier_groups (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_supplier_groups_name (name),
        INDEX idx_supplier_groups_active (is_active, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
seed_default_supplier_groups($pdo);
ensure_table($pdo, 'suppliers', "
    CREATE TABLE suppliers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        parasut_contact_id VARCHAR(64) NULL,
        supplier_group_id INT UNSIGNED NULL,
        company_name VARCHAR(190) NOT NULL,
        contact_name VARCHAR(190) NULL,
        email VARCHAR(190) NULL,
        phone VARCHAR(60) NULL,
        tax_office VARCHAR(120) NULL,
        tax_number VARCHAR(60) NULL,
        city VARCHAR(120) NULL,
        district VARCHAR(120) NULL,
        address TEXT NULL,
        notes TEXT NULL,
        deleted_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_suppliers_group FOREIGN KEY (supplier_group_id) REFERENCES supplier_groups(id) ON DELETE SET NULL,
        UNIQUE KEY uq_suppliers_parasut_contact (parasut_contact_id),
        INDEX idx_suppliers_group (supplier_group_id),
        INDEX idx_suppliers_deleted_at (deleted_at),
        INDEX idx_suppliers_company (company_name),
        INDEX idx_suppliers_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'suppliers', 'supplier_group_id', 'INT UNSIGNED NULL AFTER parasut_contact_id');
ensure_index($pdo, 'suppliers', 'idx_suppliers_group', 'INDEX idx_suppliers_group (supplier_group_id)');
ensure_table($pdo, 'supplier_contacts', "
    CREATE TABLE supplier_contacts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT UNSIGNED NOT NULL,
        full_name VARCHAR(190) NOT NULL,
        role_title VARCHAR(120) NULL,
        email VARCHAR(190) NULL,
        phone VARCHAR(60) NULL,
        notify_enabled TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_supplier_contacts_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
        INDEX idx_supplier_contacts_supplier (supplier_id),
        INDEX idx_supplier_contacts_notify (notify_enabled),
        INDEX idx_supplier_contacts_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'supplier_contacts', 'role_title', 'VARCHAR(120) NULL AFTER full_name');
ensure_column($pdo, 'renewals', 'supplier_id', 'INT UNSIGNED NULL AFTER customer_id');
ensure_column($pdo, 'renewals', 'supplier_group_id', 'INT UNSIGNED NULL AFTER supplier_id');
ensure_column($pdo, 'renewals', 'definition_id', 'INT UNSIGNED NULL AFTER supplier_group_id');
ensure_column($pdo, 'renewals', 'renewal_period_id', 'INT UNSIGNED NULL AFTER definition_id');
ensure_column($pdo, 'renewals', 'brand', 'VARCHAR(120) NULL AFTER title');
ensure_column($pdo, 'renewals', 'payment_method', 'VARCHAR(120) NULL AFTER license_key');
ensure_column($pdo, 'renewals', 'payment_customer_choice', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_method');
ensure_column($pdo, 'renewals', 'payment_selected_email', 'VARCHAR(190) NULL AFTER payment_customer_choice');
ensure_column($pdo, 'renewals', 'payment_selected_at', 'DATETIME NULL AFTER payment_selected_email');
ensure_column($pdo, 'renewals', 'supplier_price_request_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_days');
ensure_column($pdo, 'renewals', 'supplier_price_request_days', 'INT UNSIGNED NULL AFTER supplier_price_request_enabled');
ensure_column($pdo, 'renewals', 'supplier_price_requested_at', 'DATETIME NULL AFTER last_notified_at');
ensure_index($pdo, 'renewals', 'idx_renewals_supplier', 'INDEX idx_renewals_supplier (supplier_id)');
ensure_index($pdo, 'renewals', 'idx_renewals_supplier_group', 'INDEX idx_renewals_supplier_group (supplier_group_id)');
ensure_index($pdo, 'renewals', 'idx_renewals_definition', 'INDEX idx_renewals_definition (definition_id)');
ensure_index($pdo, 'renewals', 'idx_renewals_period', 'INDEX idx_renewals_period (renewal_period_id)');
ensure_index($pdo, 'renewals', 'idx_renewals_supplier_price_request', 'INDEX idx_renewals_supplier_price_request (supplier_price_request_enabled, supplier_price_requested_at, renewal_date)');
seed_existing_suppliers($pdo);
sync_renewal_supplier_groups($pdo);
seed_existing_supplier_contacts($pdo);
normalize_existing_phone_numbers($pdo);
ensure_table($pdo, 'renewal_definitions', "
    CREATE TABLE renewal_definitions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        kind ENUM('product', 'service') NOT NULL DEFAULT 'service',
        notification_info TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_renewal_definitions_kind_name (kind, name),
        INDEX idx_renewal_definitions_active (is_active, kind, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'renewal_definitions', 'notification_info', 'TEXT NULL AFTER kind');
seed_default_renewal_definitions($pdo);
seed_existing_renewal_definitions($pdo);
ensure_table($pdo, 'renewal_items', "
    CREATE TABLE renewal_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        renewal_id INT UNSIGNED NOT NULL,
        definition_id INT UNSIGNED NULL,
        title VARCHAR(190) NOT NULL,
        brand VARCHAR(120) NULL,
        kind ENUM('product', 'service') NOT NULL DEFAULT 'product',
        license_key VARCHAR(190) NULL,
        quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        unit_price DECIMAL(12,2) NULL,
        vat_rate DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_renewal_items_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
        CONSTRAINT fk_renewal_items_definition FOREIGN KEY (definition_id) REFERENCES renewal_definitions(id) ON DELETE SET NULL,
        INDEX idx_renewal_items_renewal (renewal_id, sort_order),
        INDEX idx_renewal_items_definition (definition_id),
        INDEX idx_renewal_items_kind (kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
seed_existing_renewal_items($pdo);
ensure_table($pdo, 'renewal_periods', "
    CREATE TABLE renewal_periods (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        interval_count INT UNSIGNED NOT NULL,
        interval_unit ENUM('day', 'month', 'year') NOT NULL DEFAULT 'year',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_renewal_period_interval (interval_count, interval_unit),
        INDEX idx_renewal_periods_active (is_active, interval_unit, interval_count)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
seed_default_renewal_periods($pdo);
seed_existing_renewal_periods($pdo);
ensure_table($pdo, 'payment_methods', "
    CREATE TABLE payment_methods (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        description TEXT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_payment_methods_name (name),
        INDEX idx_payment_methods_active (is_active, sort_order, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
seed_default_payment_methods($pdo);
ensure_table($pdo, 'renewal_reminder_rules', "
    CREATE TABLE renewal_reminder_rules (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        renewal_id INT UNSIGNED NOT NULL,
        days_before INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_renewal_reminder_rules_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
        UNIQUE KEY uq_renewal_reminder_day (renewal_id, days_before),
        INDEX idx_renewal_reminder_days (days_before)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_table($pdo, 'renewal_invoice_periods', "
    CREATE TABLE renewal_invoice_periods (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        renewal_id INT UNSIGNED NOT NULL,
        period_start_date DATE NOT NULL,
        period_end_date DATE NOT NULL,
        invoice_number VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_renewal_invoice_periods_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
        UNIQUE KEY uq_renewal_invoice_period (renewal_id, period_start_date, period_end_date),
        INDEX idx_renewal_invoice_lookup (renewal_id, period_end_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_table($pdo, 'renewal_payments', "
    CREATE TABLE renewal_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        renewal_id INT UNSIGNED NOT NULL,
        provider VARCHAR(30) NOT NULL DEFAULT 'iyzico',
        conversation_id VARCHAR(80) NOT NULL,
        token VARCHAR(120) NULL,
        amount DECIMAL(12,2) NOT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'TRY',
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        payment_status VARCHAR(40) NULL,
        payment_id VARCHAR(80) NULL,
        payment_page_url TEXT NULL,
        error_message TEXT NULL,
        raw_request MEDIUMTEXT NULL,
        raw_response MEDIUMTEXT NULL,
        paid_at DATETIME NULL,
        internal_push_sent_at DATETIME NULL,
        internal_mail_sent_at DATETIME NULL,
        internal_notification_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        internal_notification_error TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_renewal_payments_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
        UNIQUE KEY uq_renewal_payments_conversation (conversation_id),
        INDEX idx_renewal_payments_renewal (renewal_id, created_at),
        INDEX idx_renewal_payments_token (token),
        INDEX idx_renewal_payments_status (status),
        INDEX idx_renewal_payments_internal_notice (status, internal_push_sent_at, internal_mail_sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'renewal_payments', 'internal_push_sent_at', 'DATETIME NULL AFTER paid_at');
ensure_column($pdo, 'renewal_payments', 'internal_mail_sent_at', 'DATETIME NULL AFTER internal_push_sent_at');
ensure_column($pdo, 'renewal_payments', 'internal_notification_attempts', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER internal_mail_sent_at');
ensure_column($pdo, 'renewal_payments', 'internal_notification_error', 'TEXT NULL AFTER internal_notification_attempts');
ensure_index($pdo, 'renewal_payments', 'idx_renewal_payments_internal_notice', 'INDEX idx_renewal_payments_internal_notice (status, internal_push_sent_at, internal_mail_sent_at)');
ensure_table($pdo, 'renewal_payment_receipts', "
    CREATE TABLE renewal_payment_receipts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        renewal_id INT UNSIGNED NOT NULL,
        payment_method VARCHAR(120) NOT NULL,
        payer_email VARCHAR(190) NULL,
        note TEXT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_path VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NULL,
        file_size INT UNSIGNED NOT NULL DEFAULT 0,
        notification_recipients TEXT NULL,
        notification_status VARCHAR(30) NOT NULL DEFAULT 'pending',
        notification_error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_renewal_payment_receipts_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
        INDEX idx_renewal_payment_receipts_renewal (renewal_id, created_at),
        INDEX idx_renewal_payment_receipts_status (notification_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_table($pdo, 'manual_payment_requests', "
    CREATE TABLE manual_payment_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_token VARCHAR(96) NOT NULL,
        customer_id INT UNSIGNED NULL,
        title VARCHAR(190) NOT NULL,
        description TEXT NULL,
        customer_name VARCHAR(190) NULL,
        customer_email VARCHAR(190) NULL,
        customer_phone VARCHAR(60) NULL,
        customer_tax_number VARCHAR(60) NULL,
        recipients_json MEDIUMTEXT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'TRY',
        payment_due_date DATE NULL,
        reminder_time TIME NULL,
        reminder_start_days_before INT UNSIGNED NOT NULL DEFAULT 3,
        reminder_repeat_daily TINYINT(1) NOT NULL DEFAULT 0,
        reminder_until_paid TINYINT(1) NOT NULL DEFAULT 0,
        last_reminder_sent_at DATETIME NULL,
        status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
        paid_at DATETIME NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_manual_payment_requests_token (public_token),
        INDEX idx_manual_payment_requests_customer (customer_id),
        INDEX idx_manual_payment_requests_reminders (status, reminder_time, last_reminder_sent_at),
        INDEX idx_manual_payment_requests_due_reminders (status, payment_due_date, reminder_time, last_reminder_sent_at),
        INDEX idx_manual_payment_requests_status (status, created_at),
        INDEX idx_manual_payment_requests_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'manual_payment_requests', 'customer_id', 'INT UNSIGNED NULL AFTER public_token');
ensure_column($pdo, 'manual_payment_requests', 'recipients_json', 'MEDIUMTEXT NULL AFTER customer_tax_number');
ensure_column($pdo, 'manual_payment_requests', 'payment_due_date', 'DATE NULL AFTER currency');
ensure_column($pdo, 'manual_payment_requests', 'reminder_time', 'TIME NULL AFTER payment_due_date');
ensure_column($pdo, 'manual_payment_requests', 'reminder_start_days_before', 'INT UNSIGNED NOT NULL DEFAULT 3 AFTER reminder_time');
ensure_column($pdo, 'manual_payment_requests', 'reminder_repeat_daily', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_start_days_before');
ensure_column($pdo, 'manual_payment_requests', 'reminder_until_paid', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_repeat_daily');
ensure_column($pdo, 'manual_payment_requests', 'last_reminder_sent_at', 'DATETIME NULL AFTER reminder_until_paid');
ensure_index($pdo, 'manual_payment_requests', 'idx_manual_payment_requests_customer', 'INDEX idx_manual_payment_requests_customer (customer_id)');
ensure_index($pdo, 'manual_payment_requests', 'idx_manual_payment_requests_reminders', 'INDEX idx_manual_payment_requests_reminders (status, reminder_time, last_reminder_sent_at)');
ensure_index($pdo, 'manual_payment_requests', 'idx_manual_payment_requests_due_reminders', 'INDEX idx_manual_payment_requests_due_reminders (status, payment_due_date, reminder_time, last_reminder_sent_at)');
ensure_table($pdo, 'manual_payment_transactions', "
    CREATE TABLE manual_payment_transactions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        provider VARCHAR(30) NOT NULL DEFAULT 'iyzico',
        conversation_id VARCHAR(190) NOT NULL,
        token VARCHAR(190) NULL,
        amount DECIMAL(12,2) NOT NULL,
        currency VARCHAR(3) NOT NULL DEFAULT 'TRY',
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        payment_status VARCHAR(50) NULL,
        payment_id VARCHAR(120) NULL,
        payment_page_url TEXT NULL,
        error_message TEXT NULL,
        raw_request MEDIUMTEXT NULL,
        raw_response MEDIUMTEXT NULL,
        paid_at DATETIME NULL,
        internal_push_sent_at DATETIME NULL,
        internal_mail_sent_at DATETIME NULL,
        internal_notification_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        internal_notification_error TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_manual_payment_transactions_request FOREIGN KEY (request_id) REFERENCES manual_payment_requests(id) ON DELETE CASCADE,
        UNIQUE KEY uq_manual_payment_transactions_conversation (conversation_id),
        INDEX idx_manual_payment_transactions_token (token),
        INDEX idx_manual_payment_transactions_status (status),
        INDEX idx_manual_payment_transactions_request (request_id, created_at),
        INDEX idx_manual_payment_transactions_internal_notice (status, internal_push_sent_at, internal_mail_sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'manual_payment_transactions', 'internal_push_sent_at', 'DATETIME NULL AFTER paid_at');
ensure_column($pdo, 'manual_payment_transactions', 'internal_mail_sent_at', 'DATETIME NULL AFTER internal_push_sent_at');
ensure_column($pdo, 'manual_payment_transactions', 'internal_notification_attempts', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER internal_mail_sent_at');
ensure_column($pdo, 'manual_payment_transactions', 'internal_notification_error', 'TEXT NULL AFTER internal_notification_attempts');
ensure_index($pdo, 'manual_payment_transactions', 'idx_manual_payment_transactions_internal_notice', 'INDEX idx_manual_payment_transactions_internal_notice (status, internal_push_sent_at, internal_mail_sent_at)');
ensure_table($pdo, 'manual_payment_request_logs', "
    CREATE TABLE manual_payment_request_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        channel VARCHAR(30) NOT NULL,
        recipient VARCHAR(190) NOT NULL,
        subject VARCHAR(240) NULL,
        body MEDIUMTEXT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'sent',
        error_message TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_manual_payment_request_logs_request FOREIGN KEY (request_id) REFERENCES manual_payment_requests(id) ON DELETE CASCADE,
        INDEX idx_manual_payment_request_logs_request (request_id, created_at),
        INDEX idx_manual_payment_request_logs_channel (channel, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_table($pdo, 'offer_templates', "
    CREATE TABLE offer_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        description TEXT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'TRY',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_offer_templates_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY uq_offer_templates_name (name),
        INDEX idx_offer_templates_active (is_active, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_table($pdo, 'stock_items', "
    CREATE TABLE stock_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        parasut_product_id VARCHAR(64) NULL,
        name VARCHAR(190) NOT NULL,
        code VARCHAR(120) NULL,
        barcode VARCHAR(120) NULL,
        brand VARCHAR(120) NULL,
        unit VARCHAR(40) NULL,
        currency CHAR(3) NOT NULL DEFAULT 'TRY',
        list_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        buying_price DECIMAL(12,2) NULL,
        vat_rate DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        inventory_tracking TINYINT(1) NOT NULL DEFAULT 0,
        stock_count DECIMAL(12,2) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_archived TINYINT(1) NOT NULL DEFAULT 0,
        source VARCHAR(30) NOT NULL DEFAULT 'parasut',
        raw_payload MEDIUMTEXT NULL,
        last_synced_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_stock_items_parasut_product (parasut_product_id),
        INDEX idx_stock_items_search (is_active, name),
        INDEX idx_stock_items_code (code),
        INDEX idx_stock_items_source (source),
        INDEX idx_stock_items_synced (last_synced_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_table($pdo, 'offer_template_items', "
    CREATE TABLE offer_template_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_id INT UNSIGNED NOT NULL,
        stock_item_id INT UNSIGNED NULL,
        title VARCHAR(190) NOT NULL,
        brand VARCHAR(120) NULL,
        description TEXT NULL,
        quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        vat_rate DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_offer_template_items_template FOREIGN KEY (template_id) REFERENCES offer_templates(id) ON DELETE CASCADE,
        INDEX idx_offer_template_items_template (template_id, sort_order),
        INDEX idx_offer_template_items_stock (stock_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'offer_template_items', 'stock_item_id', 'INT UNSIGNED NULL AFTER template_id');
ensure_index($pdo, 'offer_template_items', 'idx_offer_template_items_stock', 'INDEX idx_offer_template_items_stock (stock_item_id)');
ensure_table($pdo, 'sales_offers', "
    CREATE TABLE sales_offers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        offer_number VARCHAR(30) NULL,
        template_id INT UNSIGNED NULL,
        title VARCHAR(190) NOT NULL,
        customer_name VARCHAR(190) NOT NULL,
        customer_email VARCHAR(190) NULL,
        customer_phone VARCHAR(60) NULL,
        currency CHAR(3) NOT NULL DEFAULT 'TRY',
        status ENUM('draft', 'sent', 'approved', 'revision_requested', 'rejected', 'expired') NOT NULL DEFAULT 'draft',
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        vat_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        notes TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_sales_offers_template FOREIGN KEY (template_id) REFERENCES offer_templates(id) ON DELETE SET NULL,
        CONSTRAINT fk_sales_offers_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY uq_sales_offers_number (offer_number),
        INDEX idx_sales_offers_status (status, updated_at),
        INDEX idx_sales_offers_template (template_id),
        INDEX idx_sales_offers_customer (customer_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'sales_offers', 'offer_number', 'VARCHAR(30) NULL AFTER id');
ensure_column($pdo, 'sales_offers', 'approved_at', 'DATETIME NULL AFTER updated_at');
ensure_column($pdo, 'sales_offers', 'approved_name', 'VARCHAR(190) NULL AFTER approved_at');
ensure_column($pdo, 'sales_offers', 'approved_email', 'VARCHAR(190) NULL AFTER approved_name');
ensure_column($pdo, 'sales_offers', 'approved_phone', 'VARCHAR(60) NULL AFTER approved_email');
ensure_column($pdo, 'sales_offers', 'approved_delivery_id', 'BIGINT UNSIGNED NULL AFTER approved_phone');
ensure_column($pdo, 'sales_offers', 'approval_ip', 'VARCHAR(45) NULL AFTER approved_delivery_id');
ensure_column($pdo, 'sales_offers', 'approval_user_agent', 'VARCHAR(255) NULL AFTER approval_ip');
ensure_index($pdo, 'sales_offers', 'uq_sales_offers_number', 'UNIQUE KEY uq_sales_offers_number (offer_number)');
ensure_index($pdo, 'sales_offers', 'idx_sales_offers_approved_at', 'INDEX idx_sales_offers_approved_at (approved_at)');
ensure_table($pdo, 'sales_offer_items', "
    CREATE TABLE sales_offer_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        offer_id INT UNSIGNED NOT NULL,
        stock_item_id INT UNSIGNED NULL,
        title VARCHAR(190) NOT NULL,
        brand VARCHAR(120) NULL,
        description TEXT NULL,
        quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        vat_rate DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        line_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        line_vat DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        currency CHAR(3) NOT NULL DEFAULT 'TRY',
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_sales_offer_items_offer FOREIGN KEY (offer_id) REFERENCES sales_offers(id) ON DELETE CASCADE,
        INDEX idx_sales_offer_items_offer (offer_id, sort_order),
        INDEX idx_sales_offer_items_stock (stock_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'sales_offer_items', 'stock_item_id', 'INT UNSIGNED NULL AFTER offer_id');
ensure_index($pdo, 'sales_offer_items', 'idx_sales_offer_items_stock', 'INDEX idx_sales_offer_items_stock (stock_item_id)');
ensure_table($pdo, 'sales_offer_deliveries', "
    CREATE TABLE sales_offer_deliveries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        offer_id INT UNSIGNED NOT NULL,
        recipient_name VARCHAR(190) NULL,
        recipient_email VARCHAR(190) NULL,
        recipient_phone VARCHAR(60) NULL,
        channel VARCHAR(30) NOT NULL DEFAULT 'mail',
        mode VARCHAR(10) NOT NULL DEFAULT 'view',
        token_hash CHAR(64) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'queued',
        error_message TEXT NULL,
        sent_at DATETIME NULL,
        first_viewed_at DATETIME NULL,
        last_viewed_at DATETIME NULL,
        view_count INT UNSIGNED NOT NULL DEFAULT 0,
        approved_at DATETIME NULL,
        approval_name VARCHAR(190) NULL,
        approval_email VARCHAR(190) NULL,
        approval_phone VARCHAR(60) NULL,
        approval_ip VARCHAR(45) NULL,
        approval_user_agent VARCHAR(255) NULL,
        last_ip VARCHAR(45) NULL,
        last_user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_sales_offer_deliveries_offer FOREIGN KEY (offer_id) REFERENCES sales_offers(id) ON DELETE CASCADE,
        UNIQUE KEY uq_sales_offer_deliveries_token (token_hash),
        INDEX idx_sales_offer_deliveries_offer (offer_id, created_at),
        INDEX idx_sales_offer_deliveries_status (offer_id, status),
        INDEX idx_sales_offer_deliveries_viewed (offer_id, first_viewed_at),
        INDEX idx_sales_offer_deliveries_approved (offer_id, approved_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'sales_offer_deliveries', 'recipient_phone', 'VARCHAR(60) NULL AFTER recipient_email');
ensure_column($pdo, 'sales_offer_deliveries', 'mode', "VARCHAR(10) NOT NULL DEFAULT 'view' AFTER channel");
ensure_column($pdo, 'sales_offer_deliveries', 'error_message', 'TEXT NULL AFTER status');
ensure_column($pdo, 'sales_offer_deliveries', 'approved_at', 'DATETIME NULL AFTER view_count');
ensure_column($pdo, 'sales_offer_deliveries', 'approval_name', 'VARCHAR(190) NULL AFTER approved_at');
ensure_column($pdo, 'sales_offer_deliveries', 'approval_email', 'VARCHAR(190) NULL AFTER approval_name');
ensure_column($pdo, 'sales_offer_deliveries', 'approval_phone', 'VARCHAR(60) NULL AFTER approval_email');
ensure_column($pdo, 'sales_offer_deliveries', 'approval_ip', 'VARCHAR(45) NULL AFTER approval_phone');
ensure_column($pdo, 'sales_offer_deliveries', 'approval_user_agent', 'VARCHAR(255) NULL AFTER approval_ip');
ensure_column($pdo, 'sales_offer_deliveries', 'last_ip', 'VARCHAR(45) NULL AFTER view_count');
ensure_column($pdo, 'sales_offer_deliveries', 'last_user_agent', 'VARCHAR(255) NULL AFTER last_ip');
ensure_index($pdo, 'sales_offer_deliveries', 'uq_sales_offer_deliveries_token', 'UNIQUE KEY uq_sales_offer_deliveries_token (token_hash)');
ensure_index($pdo, 'sales_offer_deliveries', 'idx_sales_offer_deliveries_offer', 'INDEX idx_sales_offer_deliveries_offer (offer_id, created_at)');
ensure_index($pdo, 'sales_offer_deliveries', 'idx_sales_offer_deliveries_status', 'INDEX idx_sales_offer_deliveries_status (offer_id, status)');
ensure_index($pdo, 'sales_offer_deliveries', 'idx_sales_offer_deliveries_viewed', 'INDEX idx_sales_offer_deliveries_viewed (offer_id, first_viewed_at)');
ensure_index($pdo, 'sales_offer_deliveries', 'idx_sales_offer_deliveries_approved', 'INDEX idx_sales_offer_deliveries_approved (offer_id, approved_at)');
ensure_table($pdo, 'renewal_decisions', "
    CREATE TABLE renewal_decisions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        renewal_id INT UNSIGNED NOT NULL,
        decision VARCHAR(40) NOT NULL,
        payment_terms VARCHAR(190) NULL,
        reason TEXT NULL,
        postponed_date DATE NULL,
        note TEXT NULL,
        mail_status VARCHAR(30) NOT NULL DEFAULT 'none',
        mail_error TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_renewal_decisions_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
        CONSTRAINT fk_renewal_decisions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_renewal_decisions_renewal (renewal_id, created_at),
        INDEX idx_renewal_decisions_decision (decision)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_table($pdo, 'renewal_notification_reads', "
    CREATE TABLE renewal_notification_reads (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        renewal_id INT UNSIGNED NOT NULL,
        read_on DATE NOT NULL,
        read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_renewal_notification_reads_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
        UNIQUE KEY uq_renewal_notification_read_day (renewal_id, read_on),
        INDEX idx_renewal_notification_reads_day (read_on)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_table($pdo, 'renewal_notification_deliveries', "
    CREATE TABLE renewal_notification_deliveries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        renewal_id INT UNSIGNED NOT NULL,
        mail_log_id INT UNSIGNED NULL,
        recipient_email VARCHAR(190) NOT NULL,
        recipient_name VARCHAR(190) NULL,
        recipient_phone VARCHAR(60) NULL,
        token_hash CHAR(64) NOT NULL,
        notification_date DATE NOT NULL,
        status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
        error_message TEXT NULL,
        sent_at DATETIME NULL,
        first_read_at DATETIME NULL,
        read_at DATETIME NULL,
        last_read_at DATETIME NULL,
        read_count INT UNSIGNED NOT NULL DEFAULT 0,
        read_ip VARCHAR(45) NULL,
        read_user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_renewal_notification_deliveries_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
        UNIQUE KEY uq_renewal_notification_delivery_token (token_hash),
        INDEX idx_renewal_notification_delivery_renewal (renewal_id, notification_date),
        INDEX idx_renewal_notification_delivery_mail_log (mail_log_id),
        INDEX idx_renewal_notification_delivery_read (read_at),
        INDEX idx_renewal_notification_delivery_recipient (recipient_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'renewal_notification_deliveries', 'mail_log_id', 'INT UNSIGNED NULL AFTER renewal_id');
ensure_column($pdo, 'renewal_notification_deliveries', 'recipient_name', 'VARCHAR(190) NULL AFTER recipient_email');
ensure_column($pdo, 'renewal_notification_deliveries', 'recipient_phone', 'VARCHAR(60) NULL AFTER recipient_name');
ensure_column($pdo, 'renewal_notification_deliveries', 'notification_date', 'DATE NULL AFTER token_hash');
ensure_column($pdo, 'renewal_notification_deliveries', 'sent_at', 'DATETIME NULL AFTER error_message');
ensure_column($pdo, 'renewal_notification_deliveries', 'first_read_at', 'DATETIME NULL AFTER sent_at');
ensure_column($pdo, 'renewal_notification_deliveries', 'read_at', 'DATETIME NULL AFTER sent_at');
ensure_column($pdo, 'renewal_notification_deliveries', 'last_read_at', 'DATETIME NULL AFTER read_at');
ensure_column($pdo, 'renewal_notification_deliveries', 'read_count', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_read_at');
ensure_column($pdo, 'renewal_notification_deliveries', 'read_ip', 'VARCHAR(45) NULL AFTER read_at');
ensure_column($pdo, 'renewal_notification_deliveries', 'read_user_agent', 'VARCHAR(255) NULL AFTER read_ip');
ensure_column($pdo, 'renewal_notification_deliveries', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER read_user_agent');
ensure_column($pdo, 'renewal_notification_deliveries', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
ensure_index($pdo, 'renewal_notification_deliveries', 'idx_renewal_notification_delivery_mail_log', 'INDEX idx_renewal_notification_delivery_mail_log (mail_log_id)');
ensure_index($pdo, 'renewal_notification_deliveries', 'idx_renewal_notification_delivery_read', 'INDEX idx_renewal_notification_delivery_read (read_at)');
ensure_index($pdo, 'renewal_notification_deliveries', 'idx_renewal_notification_delivery_recipient', 'INDEX idx_renewal_notification_delivery_recipient (recipient_email)');
try {
    $pdo->exec(
        'UPDATE renewal_notification_deliveries
         SET first_read_at = COALESCE(first_read_at, read_at),
             last_read_at = COALESCE(last_read_at, read_at),
             read_count = CASE WHEN read_at IS NOT NULL AND read_count = 0 THEN 1 ELSE read_count END
         WHERE read_at IS NOT NULL'
    );
} catch (Throwable) {
    // Eski kurulumlar alanlari olusturduktan hemen sonra bu bilgileri doldurur.
}
try {
    $pdo->exec(
        'UPDATE renewal_notification_deliveries rnd
         SET rnd.mail_log_id = (
             SELECT ml.id
             FROM mail_logs ml
             WHERE ml.renewal_id = rnd.renewal_id
               AND LOWER(ml.recipient_email) = LOWER(rnd.recipient_email)
               AND ABS(TIMESTAMPDIFF(MINUTE, rnd.created_at, ml.sent_at)) <= 15
             ORDER BY ABS(TIMESTAMPDIFF(SECOND, rnd.created_at, ml.sent_at)) ASC, ml.id DESC
             LIMIT 1
         )
         WHERE rnd.mail_log_id IS NULL'
    );
} catch (Throwable) {
    // Mail log tablosu eski kurulumlarda daha sonra olusabilir.
}
seed_existing_reminder_rules($pdo);
ensure_table($pdo, 'app_settings', "
    CREATE TABLE app_settings (
        setting_key VARCHAR(120) PRIMARY KEY,
        setting_value MEDIUMTEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column_type($pdo, 'app_settings', 'setting_value', 'mediumtext', 'MEDIUMTEXT NULL');
seed_settings($pdo);
ensure_table($pdo, 'customer_info_requests', "
    CREATE TABLE customer_info_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NULL,
        public_token CHAR(64) NULL,
        token_hash CHAR(64) NOT NULL,
        recipient_email VARCHAR(190) NOT NULL,
        recipient_name VARCHAR(190) NULL,
        status ENUM('pending', 'submitted', 'expired') NOT NULL DEFAULT 'pending',
        submitted_payload MEDIUMTEXT NULL,
        extracted_payload MEDIUMTEXT NULL,
        upload_path VARCHAR(255) NULL,
        submitted_customer_id INT UNSIGNED NULL,
        expires_at DATETIME NOT NULL,
        submitted_at DATETIME NULL,
        last_reminder_sent_at DATETIME NULL,
        reminder_count INT UNSIGNED NOT NULL DEFAULT 0,
        reminder_opted_out_at DATETIME NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_customer_info_requests_public_token (public_token),
        UNIQUE KEY uq_customer_info_requests_token (token_hash),
        INDEX idx_customer_info_requests_status (status, expires_at),
        INDEX idx_customer_info_requests_reminders (status, reminder_opted_out_at, last_reminder_sent_at, created_at),
        INDEX idx_customer_info_requests_customer (customer_id),
        INDEX idx_customer_info_requests_submitted_customer (submitted_customer_id),
        INDEX idx_customer_info_requests_email (recipient_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'customer_info_requests', 'public_token', 'CHAR(64) NULL AFTER customer_id');
ensure_column($pdo, 'customer_info_requests', 'recipient_name', 'VARCHAR(190) NULL AFTER recipient_email');
ensure_column($pdo, 'customer_info_requests', 'last_reminder_sent_at', 'DATETIME NULL AFTER submitted_at');
ensure_column($pdo, 'customer_info_requests', 'reminder_count', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_reminder_sent_at');
ensure_column($pdo, 'customer_info_requests', 'reminder_opted_out_at', 'DATETIME NULL AFTER reminder_count');
ensure_index($pdo, 'customer_info_requests', 'uq_customer_info_requests_public_token', 'UNIQUE KEY uq_customer_info_requests_public_token (public_token)');
ensure_index($pdo, 'customer_info_requests', 'idx_customer_info_requests_reminders', 'INDEX idx_customer_info_requests_reminders (status, reminder_opted_out_at, last_reminder_sent_at, created_at)');
backfill_customer_info_public_tokens($pdo);
ensure_table($pdo, 'interaction_notes', "
    CREATE TABLE interaction_notes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NULL,
        contact_name VARCHAR(190) NULL,
        channel VARCHAR(40) NOT NULL DEFAULT 'meeting',
        title VARCHAR(190) NOT NULL,
        note TEXT NOT NULL,
        quoted_amount DECIMAL(12,2) NULL,
        currency VARCHAR(3) NOT NULL DEFAULT 'TRY',
        status VARCHAR(30) NOT NULL DEFAULT 'open',
        follow_up_at DATETIME NULL,
        completed_at DATETIME NULL,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        deleted_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_interaction_notes_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
        CONSTRAINT fk_interaction_notes_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_interaction_notes_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_interaction_notes_status_follow (status, follow_up_at),
        INDEX idx_interaction_notes_customer (customer_id, created_at),
        INDEX idx_interaction_notes_channel (channel, created_at),
        INDEX idx_interaction_notes_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_table($pdo, 'budget_plans', "
    CREATE TABLE budget_plans (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        budget_number VARCHAR(30) NULL,
        customer_id INT UNSIGNED NULL,
        customer_name VARCHAR(190) NOT NULL,
        budget_year SMALLINT UNSIGNED NOT NULL,
        title VARCHAR(190) NOT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'TRY',
        status VARCHAR(30) NOT NULL DEFAULT 'draft',
        subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
        vat_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        total DECIMAL(14,2) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        deleted_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_budget_plans_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
        CONSTRAINT fk_budget_plans_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_budget_plans_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY uq_budget_plans_number (budget_number),
        INDEX idx_budget_plans_customer (customer_id),
        INDEX idx_budget_plans_year_status (budget_year, status),
        INDEX idx_budget_plans_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_table($pdo, 'budget_plan_items', "
    CREATE TABLE budget_plan_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        budget_id INT UNSIGNED NOT NULL,
        item_name VARCHAR(190) NOT NULL,
        category VARCHAR(120) NULL,
        description TEXT NULL,
        quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
        unit VARCHAR(30) NOT NULL DEFAULT 'Adet',
        unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
        vat_rate DECIMAL(5,2) NOT NULL DEFAULT 20,
        subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
        vat_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        total DECIMAL(14,2) NOT NULL DEFAULT 0,
        planned_month TINYINT UNSIGNED NULL,
        approval_note TEXT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'planned',
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_budget_items_budget FOREIGN KEY (budget_id) REFERENCES budget_plans(id) ON DELETE CASCADE,
        INDEX idx_budget_items_budget (budget_id, sort_order),
        INDEX idx_budget_items_status (status),
        INDEX idx_budget_items_month (planned_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_table($pdo, 'push_subscriptions', "
    CREATE TABLE push_subscriptions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        endpoint TEXT NOT NULL,
        endpoint_hash CHAR(64) NOT NULL,
        public_key VARCHAR(255) NOT NULL,
        auth_token VARCHAR(255) NOT NULL,
        content_encoding VARCHAR(30) NOT NULL DEFAULT 'aes128gcm',
        user_agent VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        failure_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_success_at DATETIME NULL,
        last_failure_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY uq_push_subscriptions_endpoint (endpoint_hash),
        INDEX idx_push_subscriptions_user_active (user_id, is_active),
        INDEX idx_push_subscriptions_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

new App\Models\RenewalRepository();

echo "Migration tamamlandi.\n";

function ensure_table(PDO $pdo, string $table, string $sql): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table'
    );
    $stmt->execute(['table' => $table]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec($sql);
    }
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    }
}

function ensure_column_type(PDO $pdo, string $table, string $column, string $expectedType, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT DATA_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column
         LIMIT 1'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    $type = strtolower((string) $stmt->fetchColumn());

    if ($type !== strtolower($expectedType)) {
        $pdo->exec(sprintf('ALTER TABLE `%s` MODIFY COLUMN `%s` %s', $table, $column, $definition));
    }
}

function backfill_customer_info_public_tokens(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT id
         FROM customer_info_requests
         WHERE public_token IS NULL
            OR public_token = ''
         LIMIT 500"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($rows === []) {
        return;
    }

    $update = $pdo->prepare('UPDATE customer_info_requests SET public_token = :public_token WHERE id = :id');
    foreach ($rows as $row) {
        $update->execute([
            'id' => (int) $row['id'],
            'public_token' => bin2hex(random_bytes(32)),
        ]);
    }
}

function ensure_index(PDO $pdo, string $table, string $index, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND INDEX_NAME = :index_name'
    );
    $stmt->execute(['table' => $table, 'index_name' => $index]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD %s', $table, $definition));
    }
}

function seed_existing_customer_contacts(PDO $pdo): void
{
    $sql = "INSERT INTO customer_contacts (customer_id, full_name, email, phone, notify_enabled)
            SELECT c.id,
                   COALESCE(NULLIF(c.contact_name, ''), c.company_name),
                   c.email,
                   c.phone,
                   1
            FROM customers c
            WHERE c.email IS NOT NULL
              AND c.email <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM customer_contacts cc WHERE cc.customer_id = c.id
              )";
    $pdo->exec($sql);
}

function seed_existing_suppliers(PDO $pdo): void
{
    $sql = "INSERT INTO suppliers (company_name, notes)
            SELECT DISTINCT r.supplier, 'Yenileme kayitlarindan olusturuldu.'
            FROM renewals r
            WHERE r.supplier IS NOT NULL
              AND r.supplier <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM suppliers s WHERE s.company_name = r.supplier
              )";
    $pdo->exec($sql);

    $sql = "UPDATE renewals r
            INNER JOIN suppliers s ON s.company_name = r.supplier
            SET r.supplier_id = s.id
            WHERE r.supplier_id IS NULL";
    $pdo->exec($sql);
}

function seed_default_supplier_groups(PDO $pdo): void
{
    $rows = [
        'Yazilim',
        'Donanim',
        'Bulut',
        'Guvenlik',
        'Hosting',
        'Destek Hizmeti',
        'Telekom',
        'Sarf Malzeme',
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO supplier_groups (name, is_active)
         VALUES (:name, 1)
         ON DUPLICATE KEY UPDATE is_active = 1, updated_at = NOW()'
    );

    foreach ($rows as $name) {
        $stmt->execute(['name' => $name]);
    }
}

function seed_default_contact_roles(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO contact_role_definitions (name, sort_order, is_active)
         VALUES (:name, :sort_order, 1)
         ON DUPLICATE KEY UPDATE is_active = 1, updated_at = NOW()'
    );

    foreach (App\Models\RenewalRepository::defaultContactRoles() as $index => $name) {
        $stmt->execute([
            'name' => $name,
            'sort_order' => ($index + 1) * 10,
        ]);
    }
}

function sync_renewal_supplier_groups(PDO $pdo): void
{
    $sql = "UPDATE renewals r
            INNER JOIN suppliers s ON s.id = r.supplier_id
            SET r.supplier_group_id = s.supplier_group_id
            WHERE r.supplier_group_id IS NULL
              AND s.supplier_group_id IS NOT NULL";
    $pdo->exec($sql);
}

function seed_existing_supplier_contacts(PDO $pdo): void
{
    $sql = "INSERT INTO supplier_contacts (supplier_id, full_name, email, phone, notify_enabled)
            SELECT s.id,
                   COALESCE(NULLIF(s.contact_name, ''), s.company_name),
                   s.email,
                   s.phone,
                   CASE WHEN s.email IS NOT NULL AND s.email <> '' THEN 1 ELSE 0 END
            FROM suppliers s
            WHERE NOT EXISTS (
                SELECT 1 FROM supplier_contacts sc WHERE sc.supplier_id = s.id
            )";
    $pdo->exec($sql);
}

function normalize_existing_phone_numbers(PDO $pdo): void
{
    foreach (['customers', 'customer_contacts', 'suppliers', 'supplier_contacts'] as $table) {
        $stmt = $pdo->query("SELECT id, phone FROM `{$table}` WHERE phone IS NOT NULL AND phone <> ''");
        $update = $pdo->prepare("UPDATE `{$table}` SET phone = :phone WHERE id = :id");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $normalized = normalize_phone_number($row['phone'] ?? '');
            if ($normalized === '' || $normalized === (string) ($row['phone'] ?? '')) {
                continue;
            }

            $update->execute([
                'phone' => $normalized,
                'id' => (int) $row['id'],
            ]);
        }
    }
}

function seed_existing_renewal_definitions(PDO $pdo): void
{
    $sql = "INSERT IGNORE INTO renewal_definitions (name, kind, notification_info, is_active)
            SELECT DISTINCT r.title, r.kind, NULL, 1
            FROM renewals r
            WHERE r.title IS NOT NULL
              AND r.title <> ''";
    $pdo->exec($sql);

    $sql = "UPDATE renewals r
            INNER JOIN renewal_definitions rd ON rd.name = r.title AND rd.kind = r.kind
            SET r.definition_id = rd.id
            WHERE r.definition_id IS NULL";
    $pdo->exec($sql);
}

function seed_default_renewal_definitions(PDO $pdo): void
{
    $rows = [
        ['Microsoft 365 Lisansi', 'product'],
        ['Google Workspace Lisansi', 'product'],
        ['E-posta Hosting', 'product'],
        ['Alan Adi Yenileme', 'product'],
        ['Web Hosting', 'product'],
        ['SSL Sertifikasi', 'product'],
        ['Antivirus / EDR Lisansi', 'product'],
        ['Firewall UTM Lisansi', 'product'],
        ['VPN Lisansi', 'product'],
        ['Yedekleme Yazilimi Lisansi', 'product'],
        ['Sunucu Lisansi', 'product'],
        ['Windows Server CAL', 'product'],
        ['SQL Server Lisansi', 'product'],
        ['ERP Lisansi', 'product'],
        ['CRM Lisansi', 'product'],
        ['Bulut Sunucu', 'product'],
        ['IP Santral Lisansi', 'product'],
        ['Kamera Kayit Yazilimi Lisansi', 'product'],
        ['Bakim ve Destek Sozlesmesi', 'service'],
        ['Helpdesk Destek Hizmeti', 'service'],
        ['Sunucu Bakim Hizmeti', 'service'],
        ['Network Bakim Hizmeti', 'service'],
        ['Web Site Bakim Hizmeti', 'service'],
        ['Yedekleme Hizmeti', 'service'],
        ['Felaket Kurtarma Hizmeti', 'service'],
        ['Siber Guvenlik Izleme Hizmeti', 'service'],
        ['E-posta Guvenligi Hizmeti', 'service'],
        ['Penetrasyon Testi', 'service'],
        ['KVKK Danismanlik Hizmeti', 'service'],
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO renewal_definitions (name, kind, notification_info, is_active)
         VALUES (:name, :kind, :notification_info, 1)
         ON DUPLICATE KEY UPDATE
            notification_info = CASE
                WHEN notification_info IS NULL OR notification_info = '' THEN VALUES(notification_info)
                ELSE notification_info
            END,
            is_active = 1,
            updated_at = NOW()"
    );

    foreach ($rows as [$name, $kind]) {
        $stmt->execute([
            'name' => $name,
            'kind' => $kind,
            'notification_info' => default_renewal_definition_info($name),
        ]);
    }
}

function default_renewal_definition_info(string $name): ?string
{
    $normalized = normalized_definition_name($name);

    if (str_contains($normalized, 'alan adi') || str_contains($normalized, 'hosting')) {
        return 'Alan adı ve hosting yenilemeleri genellikle sessiz ilerleyen, ancak süresi kaçırıldığında etkisi hızlı hissedilen süreçlerdir. Süre dolduğunda web sitesi, e-posta hesapları, DNS yönlendirmeleri ve bağlı servislerde erişim kesintileri yaşanabilir. Alan adı tarafında ilk günlerde yenileme çoğu zaman yapılabilse de, bekleme veya kurtarma dönemine girildiğinde ek ücret, kesinti süresi ve alan adının kaybedilmesi riski oluşabilir. Hosting tarafında ise dosya, yedek ve e-posta erişimi etkilenebileceği için yenileme tercihinin süre dolmadan netleşmesi önerilir.';
    }

    if (str_contains($normalized, 'ssl')) {
        return 'SSL sertifikası yenilenmediğinde web sitesi teknik olarak yayında olsa bile tarayıcılar ziyaretçilere güvenlik uyarısı gösterebilir. Bu uyarılar kullanıcı güvenini düşürür, formlar ve ödeme adımları daha az tercih edilir hale gelir ve bazı entegrasyonlar güvenli bağlantı kabul etmediği için çalışmayabilir. Sertifika süresi dolmadan yenileme yapılması, kesintisiz ve güven veren bir erişim için önemlidir.';
    }

    if (str_contains($normalized, 'microsoft 365') || str_contains($normalized, 'google workspace')) {
        return 'Bulut çalışma lisanslarında yenileme gecikirse e-posta, takvim, dosya paylaşımı ve kullanıcı oturumları etkilenebilir. İlk aşamada uyarılar görünse bile süre uzadığında hesap erişimleri, kota ve yönetim işlemleri kısıtlanabilir. İş akışlarının ve ekip içi iletişimin kesintiye uğramaması için lisans durumunun süre dolmadan netleştirilmesi önerilir.';
    }

    if (str_contains($normalized, 'antivirus') || str_contains($normalized, 'edr')) {
        return 'Antivirüs ve EDR lisansları yalnızca kurulu yazılımı değil; güncel tehdit imzalarını, merkezi yönetimi, olay kayıtlarını ve müdahale kabiliyetini de kapsar. Süre dolduğunda cihazlar çalışmaya devam ediyor gibi görünse bile yeni tehditlere karşı görünürlük ve koruma seviyesi düşebilir. Güvenlik zincirinde boşluk oluşmaması için yenileme kararının gecikmeden verilmesi önemlidir.';
    }

    if (str_contains($normalized, 'firewall') || str_contains($normalized, 'utm') || str_contains($normalized, 'vpn')) {
        return 'Firewall, UTM ve VPN lisanslarında süre dolumu internet erişimini her zaman anında kesmeyebilir; ancak web filtreleme, saldırı önleme, VPN erişimi, güvenlik güncellemeleri ve raporlama gibi kritik katmanlar etkilenebilir. Bu durum dış tehditlere karşı savunmayı zayıflatır ve uzaktan erişim sürekliliğini riske atabilir. Yenilemenin süre bitmeden planlanması önerilir.';
    }

    if (str_contains($normalized, 'yedekleme') || str_contains($normalized, 'felaket kurtarma')) {
        return 'Yedekleme ve felaket kurtarma çözümleri sorun yaşanmadan önce sessiz çalışan ama ihtiyaç anında kritik hale gelen sistemlerdir. Lisans veya hizmet süresi dolduğunda yeni yedeklerin alınması, saklama politikaları, izleme uyarıları veya geri dönüş desteği etkilenebilir. Veri kaybı riskini büyütmemek için yenileme ve test süreçlerinin süre dolmadan tamamlanması önemlidir.';
    }

    if (str_contains($normalized, 'bulut')) {
        return 'Bulut sunucu hizmetlerinde süre veya ödeme takibi gecikirse kaynaklar, yedekler, IP erişimi ve bağlı servisler etkilenebilir. Bazı sağlayıcılar kısa süreli uyarı dönemi sunsa da gecikme uzadığında servis durdurma veya veri erişiminde kısıtlama riski oluşabilir. Canlı sistemlerin etkilenmemesi için yenileme planı önceden yapılmalıdır.';
    }

    if (str_contains($normalized, 'bakim') || str_contains($normalized, 'destek') || str_contains($normalized, 'helpdesk') || str_contains($normalized, 'network') || str_contains($normalized, 'web site')) {
        return 'Bakım ve destek hizmetleri sorun çıkmadığı dönemlerde arka planda kalır; ancak ihtiyaç anında müdahale süresi ve kapsamı belirleyen ana güvencedir. Hizmet süresi yenilenmezse planlı kontroller, öncelikli destek, güncelleme takibi ve arıza müdahalesi kapsam dışı kalabilir. Operasyonun aksamaması için hizmet devamlılığının süre dolmadan netleşmesi önerilir.';
    }

    if (str_contains($normalized, 'sunucu') || str_contains($normalized, 'server cal') || str_contains($normalized, 'sql server')) {
        return 'Sunucu ve veritabanı lisansları erişim, yasal kullanım, güncelleme ve destek sürekliliği açısından önemlidir. Yenileme veya lisans takibi geciktiğinde kullanıcı erişimleri, denetim süreçleri, üretici desteği ve güvenlik güncellemeleri riskli hale gelebilir. İş kritik sistemlerde sürpriz kesinti yaşamamak için lisans durumunun önceden planlanması önerilir.';
    }

    if (str_contains($normalized, 'erp') || str_contains($normalized, 'crm')) {
        return 'ERP ve CRM lisansları satış, muhasebe, stok, müşteri takibi ve entegrasyon süreçlerinin merkezinde yer alır. Süre dolumu veya bakım yenilemesinin gecikmesi kullanıcı erişimlerini, güncelleme hakkını, destek taleplerini ve bağlı entegrasyonları etkileyebilir. Operasyonun aksamaması için yenileme kararının süre dolmadan netleşmesi faydalıdır.';
    }

    if (str_contains($normalized, 'santral')) {
        return 'IP santral lisansı veya hizmet süresi dolduğunda dahili görüşmeler, dış hat kullanımı, çağrı yönlendirme, kayıt ve raporlama gibi telefon süreçleri etkilenebilir. Çağrı trafiği müşteriye doğrudan temas ettiği için küçük bir kesinti bile operasyonel görünürlüğü azaltabilir. Yenilemenin süre dolmadan tamamlanması önerilir.';
    }

    if (str_contains($normalized, 'kamera') || str_contains($normalized, 'kayit')) {
        return 'Kamera kayıt yazılımı ve izleme lisansları güvenlik olaylarında geriye dönük inceleme yapabilmek için kritik öneme sahiptir. Süre dolduğunda canlı izleme çalışıyor gibi görünse bile kayıt, arşivleme, uzaktan erişim veya alarm entegrasyonları etkilenebilir. Kayıt bütünlüğünün bozulmaması için yenileme zamanında yapılmalıdır.';
    }

    if (str_contains($normalized, 'siber guvenlik') || str_contains($normalized, 'e-posta guvenligi') || str_contains($normalized, 'penetrasyon') || str_contains($normalized, 'kvkk')) {
        return 'Güvenlik ve uyumluluk hizmetleri düzenli takip edilmediğinde riskler görünmez hale gelebilir. İzleme, test, raporlama veya danışmanlık süresinin bitmesi; zafiyetlerin geç fark edilmesine, e-posta tehditlerinin artmasına ve uyum süreçlerinde eksik kayıt oluşmasına neden olabilir. Risklerin büyümeden yönetilebilmesi için hizmet takviminin kesintisiz sürmesi önerilir.';
    }

    return 'Bu ürün veya hizmetin yenilemesi zamanında planlanmadığında lisans, destek, güncelleme veya erişim sürekliliği etkilenebilir. İlk anda sistem çalışıyor gibi görünse bile süre uzadıkça servis kısıtları, güvenlik açıkları, ek maliyetler veya kullanım kesintileri oluşabilir. Yenileme kararının süre dolmadan netleşmesi önerilir.';
}

function normalized_definition_name(string $name): string
{
    $name = mb_strtolower($name);

    return str_replace(['ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'İ'], ['i', 'g', 'u', 's', 'o', 'c', 'i'], $name);
}

function seed_default_renewal_periods(PDO $pdo): void
{
    $rows = [
        ['18 Gun', 18, 'day'],
        ['75 Gun', 75, 'day'],
        ['1 Ay', 1, 'month'],
        ['3 Ay', 3, 'month'],
        ['6 Ay', 6, 'month'],
        ['1 Yil', 1, 'year'],
    ];

    foreach ($rows as [$name, $count, $unit]) {
        renewal_period_id($pdo, $name, $count, $unit);
    }
}

function seed_existing_renewal_periods(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT id, start_date, renewal_date
         FROM renewals
         WHERE renewal_period_id IS NULL
           AND start_date IS NOT NULL
           AND renewal_date IS NOT NULL'
    );

    $update = $pdo->prepare('UPDATE renewals SET renewal_period_id = :period_id WHERE id = :id');
    foreach ($stmt->fetchAll() as $row) {
        $period = detect_period((string) $row['start_date'], (string) $row['renewal_date']);
        $periodId = renewal_period_id($pdo, $period['name'], $period['count'], $period['unit']);
        $update->execute([
            'period_id' => $periodId,
            'id' => $row['id'],
        ]);
    }

    $fallbackId = renewal_period_id($pdo, '1 Yil', 1, 'year');
    $pdo->prepare('UPDATE renewals SET renewal_period_id = :period_id WHERE renewal_period_id IS NULL')
        ->execute(['period_id' => $fallbackId]);
}

function detect_period(string $startDate, string $renewalDate): array
{
    $start = new DateTimeImmutable($startDate);
    $renewal = new DateTimeImmutable($renewalDate);

    foreach ([1, 3, 6, 12] as $months) {
        if ($start->modify('+' . $months . ' month')->format('Y-m-d') === $renewal->format('Y-m-d')) {
            return [
                'name' => $months === 12 ? '1 Yil' : $months . ' Ay',
                'count' => $months === 12 ? 1 : $months,
                'unit' => $months === 12 ? 'year' : 'month',
            ];
        }
    }

    $days = max(1, (int) $start->diff($renewal)->format('%r%a'));

    return [
        'name' => $days . ' Gun',
        'count' => $days,
        'unit' => 'day',
    ];
}

function renewal_period_id(PDO $pdo, string $name, int $count, string $unit): int
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO renewal_periods (name, interval_count, interval_unit, is_active)
         VALUES (:name, :interval_count, :interval_unit, 1)'
    );
    $stmt->execute([
        'name' => $name,
        'interval_count' => $count,
        'interval_unit' => $unit,
    ]);

    $stmt = $pdo->prepare(
        'SELECT id
         FROM renewal_periods
         WHERE interval_count = :interval_count
           AND interval_unit = :interval_unit
         LIMIT 1'
    );
    $stmt->execute([
        'interval_count' => $count,
        'interval_unit' => $unit,
    ]);

    return (int) $stmt->fetchColumn();
}

function seed_existing_renewal_items(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM renewal_items')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $pdo->exec(
        "INSERT INTO renewal_items
            (renewal_id, definition_id, title, brand, kind, license_key, quantity, unit_price, vat_rate, sort_order)
         SELECT
            id,
            definition_id,
            title,
            brand,
            kind,
            license_key,
            1.00,
            amount,
            0.00,
            0
         FROM renewals
         WHERE title IS NOT NULL
           AND title <> ''"
    );
}

function seed_default_payment_methods(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO payment_methods (name, description, sort_order, is_active)
         VALUES (:name, :description, :sort_order, 1)'
    );

    foreach (App\Models\RenewalRepository::defaultPaymentMethods() as $index => $method) {
        $stmt->execute([
            'name' => $method['name'],
            'description' => $method['description'],
            'sort_order' => ($index + 1) * 10,
        ]);
    }
}

function seed_existing_reminder_rules(PDO $pdo): void
{
    $pdo->exec('UPDATE renewals SET reminder_days = 7 WHERE reminder_days < 7');

    $sql = "INSERT IGNORE INTO renewal_reminder_rules (renewal_id, days_before)
            SELECT id, reminder_days
            FROM renewals
            WHERE reminder_days IS NOT NULL
              AND reminder_days > 0";
    $pdo->exec($sql);

    $sql = "INSERT IGNORE INTO renewal_reminder_rules (renewal_id, days_before)
            SELECT id, 7
            FROM renewals";
    $pdo->exec($sql);
}

function seed_settings(PDO $pdo): void
{
    $defaults = App\Models\SettingsRepository::defaults();
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO app_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, NOW())'
    );

    foreach ($defaults as $key => $value) {
        $stmt->execute([
            'setting_key' => $key,
            'setting_value' => $value,
        ]);
    }
}
