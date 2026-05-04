CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'admin',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_deleted_at (deleted_at),
    INDEX idx_users_active_deleted (is_active, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_permissions (
    user_id INT UNSIGNED NOT NULL,
    permission_key VARCHAR(120) NOT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_key),
    CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_permissions_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_ip_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    last_email VARCHAR(190) NULL,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    first_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_login_ip_attempts_ip (ip_address),
    INDEX idx_login_ip_attempts_locked_until (locked_until),
    INDEX idx_login_ip_attempts_last_failed_at (last_failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parasut_contact_id VARCHAR(64) NULL,
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
    UNIQUE KEY uq_customers_parasut_contact (parasut_contact_id),
    INDEX idx_customers_deleted_at (deleted_at),
    INDEX idx_customers_company (company_name),
    INDEX idx_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    full_name VARCHAR(190) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(60) NULL,
    notify_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_contacts_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_customer_contacts_customer (customer_id),
    INDEX idx_customer_contacts_notify (notify_enabled),
    INDEX idx_customer_contacts_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supplier_groups_name (name),
    INDEX idx_supplier_groups_active (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    full_name VARCHAR(190) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(60) NULL,
    notify_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_supplier_contacts_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    INDEX idx_supplier_contacts_supplier (supplier_id),
    INDEX idx_supplier_contacts_notify (notify_enabled),
    INDEX idx_supplier_contacts_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE renewals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NULL,
    supplier_group_id INT UNSIGNED NULL,
    definition_id INT UNSIGNED NULL,
    renewal_period_id INT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    brand VARCHAR(120) NULL,
    kind ENUM('product', 'service') NOT NULL DEFAULT 'service',
    supplier VARCHAR(190) NULL,
    license_key VARCHAR(190) NULL,
    payment_method VARCHAR(120) NULL,
    payment_customer_choice TINYINT(1) NOT NULL DEFAULT 0,
    payment_selected_email VARCHAR(190) NULL,
    payment_selected_at DATETIME NULL,
    start_date DATE NULL,
    renewal_date DATE NOT NULL,
    reminder_days INT UNSIGNED NOT NULL DEFAULT 30,
    supplier_price_request_enabled TINYINT(1) NOT NULL DEFAULT 0,
    supplier_price_request_days INT UNSIGNED NULL,
    amount DECIMAL(12, 2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'TRY',
    status ENUM('active', 'renewed', 'cancelled') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    last_notified_at DATETIME NULL,
    supplier_price_requested_at DATETIME NULL,
    renewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_renewals_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_renewals_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    CONSTRAINT fk_renewals_supplier_group FOREIGN KEY (supplier_group_id) REFERENCES supplier_groups(id) ON DELETE SET NULL,
    INDEX idx_renewals_date_status (renewal_date, status),
    INDEX idx_renewals_customer (customer_id),
    INDEX idx_renewals_supplier (supplier_id),
    INDEX idx_renewals_supplier_group (supplier_group_id),
    INDEX idx_renewals_definition (definition_id),
    INDEX idx_renewals_period (renewal_period_id),
    INDEX idx_renewals_supplier_price_request (supplier_price_request_enabled, supplier_price_requested_at, renewal_date),
    INDEX idx_renewals_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE renewal_reminder_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    renewal_id INT UNSIGNED NOT NULL,
    days_before INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_renewal_reminder_rules_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
    UNIQUE KEY uq_renewal_reminder_day (renewal_id, days_before),
    INDEX idx_renewal_reminder_days (days_before)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_renewal_payments_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
    UNIQUE KEY uq_renewal_payments_conversation (conversation_id),
    INDEX idx_renewal_payments_renewal (renewal_id, created_at),
    INDEX idx_renewal_payments_token (token),
    INDEX idx_renewal_payments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE renewal_notification_reads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    renewal_id INT UNSIGNED NOT NULL,
    read_on DATE NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_renewal_notification_reads_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
    UNIQUE KEY uq_renewal_notification_read_day (renewal_id, read_on),
    INDEX idx_renewal_notification_reads_day (read_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE renewal_notification_deliveries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    renewal_id INT UNSIGNED NOT NULL,
    mail_log_id INT UNSIGNED NULL,
    recipient_email VARCHAR(190) NOT NULL,
    recipient_name VARCHAR(190) NULL,
    token_hash CHAR(64) NOT NULL,
    notification_date DATE NOT NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    read_at DATETIME NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mail_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    renewal_id INT UNSIGNED NULL,
    recipient_email VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    status ENUM('sent', 'failed') NOT NULL,
    error_message TEXT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_logs_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE SET NULL,
    INDEX idx_mail_logs_sent_at (sent_at),
    INDEX idx_mail_logs_renewal (renewal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value MEDIUMTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_info_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NULL,
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
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_info_requests_token (token_hash),
    INDEX idx_customer_info_requests_status (status, expires_at),
    INDEX idx_customer_info_requests_customer (customer_id),
    INDEX idx_customer_info_requests_submitted_customer (submitted_customer_id),
    INDEX idx_customer_info_requests_email (recipient_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
