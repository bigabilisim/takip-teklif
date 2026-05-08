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

CREATE TABLE supplier_quote_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    renewal_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NULL,
    supplier_contact_id INT UNSIGNED NULL,
    supplier_name VARCHAR(190) NULL,
    contact_name VARCHAR(190) NULL,
    recipient_email VARCHAR(190) NULL,
    recipient_phone VARCHAR(60) NULL,
    token_hash CHAR(64) NOT NULL,
    source ENUM('mail', 'whatsapp', 'manual') NOT NULL DEFAULT 'mail',
    status ENUM('pending', 'opened', 'submitted', 'expired') NOT NULL DEFAULT 'pending',
    message_subject VARCHAR(255) NULL,
    message_body TEXT NULL,
    quote_note TEXT NULL,
    terms_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    opened_at DATETIME NULL,
    submitted_at DATETIME NULL,
    submitted_ip VARCHAR(45) NULL,
    submitted_user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_supplier_quote_requests_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
    CONSTRAINT fk_supplier_quote_requests_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    CONSTRAINT fk_supplier_quote_requests_contact FOREIGN KEY (supplier_contact_id) REFERENCES supplier_contacts(id) ON DELETE SET NULL,
    UNIQUE KEY uq_supplier_quote_requests_token (token_hash),
    INDEX idx_supplier_quote_requests_renewal (renewal_id, status, created_at),
    INDEX idx_supplier_quote_requests_supplier (supplier_id, created_at),
    INDEX idx_supplier_quote_requests_email (recipient_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_quote_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    renewal_item_id INT UNSIGNED NULL,
    item_title VARCHAR(190) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'TRY',
    price_cash DECIMAL(12,2) NULL,
    price_30 DECIMAL(12,2) NULL,
    price_60 DECIMAL(12,2) NULL,
    price_check DECIMAL(12,2) NULL,
    price_custom DECIMAL(12,2) NULL,
    custom_term VARCHAR(120) NULL,
    vat_included TINYINT(1) NOT NULL DEFAULT 1,
    delivery_note TEXT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_supplier_quote_lines_request FOREIGN KEY (request_id) REFERENCES supplier_quote_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_supplier_quote_lines_item FOREIGN KEY (renewal_item_id) REFERENCES renewal_items(id) ON DELETE SET NULL,
    INDEX idx_supplier_quote_lines_request (request_id),
    INDEX idx_supplier_quote_lines_item (renewal_item_id),
    INDEX idx_supplier_quote_lines_cash (price_cash),
    INDEX idx_supplier_quote_lines_30 (price_30),
    INDEX idx_supplier_quote_lines_60 (price_60)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_quote_selections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    renewal_id INT UNSIGNED NOT NULL,
    renewal_item_id INT UNSIGNED NOT NULL,
    quote_line_id INT UNSIGNED NULL,
    selected_term VARCHAR(30) NOT NULL,
    selected_price DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'TRY',
    selected_by INT UNSIGNED NULL,
    selected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_supplier_quote_selections_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
    CONSTRAINT fk_supplier_quote_selections_item FOREIGN KEY (renewal_item_id) REFERENCES renewal_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_supplier_quote_selections_line FOREIGN KEY (quote_line_id) REFERENCES supplier_quote_lines(id) ON DELETE SET NULL,
    CONSTRAINT fk_supplier_quote_selections_user FOREIGN KEY (selected_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_supplier_quote_selection_item (renewal_item_id),
    INDEX idx_supplier_quote_selections_renewal (renewal_id),
    INDEX idx_supplier_quote_selections_line (quote_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_quote_selection_deliveries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    renewal_id INT UNSIGNED NOT NULL,
    renewal_item_id INT UNSIGNED NULL,
    quote_line_id INT UNSIGNED NULL,
    selection_id INT UNSIGNED NULL,
    supplier_name VARCHAR(190) NULL,
    item_title VARCHAR(190) NULL,
    recipient_email VARCHAR(190) NOT NULL,
    recipient_name VARCHAR(190) NULL,
    token_hash CHAR(64) NOT NULL,
    selected_term VARCHAR(30) NULL,
    selected_price DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'TRY',
    status ENUM('pending', 'sent', 'failed', 'read') NOT NULL DEFAULT 'pending',
    mail_log_id INT UNSIGNED NULL,
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    read_at DATETIME NULL,
    read_ip VARCHAR(45) NULL,
    read_user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_supplier_quote_selection_deliveries_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
    CONSTRAINT fk_supplier_quote_selection_deliveries_item FOREIGN KEY (renewal_item_id) REFERENCES renewal_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_supplier_quote_selection_deliveries_line FOREIGN KEY (quote_line_id) REFERENCES supplier_quote_lines(id) ON DELETE SET NULL,
    CONSTRAINT fk_supplier_quote_selection_deliveries_selection FOREIGN KEY (selection_id) REFERENCES supplier_quote_selections(id) ON DELETE SET NULL,
    UNIQUE KEY uq_supplier_quote_selection_delivery_token (token_hash),
    INDEX idx_supplier_quote_selection_delivery_selection (selection_id, status, read_at),
    INDEX idx_supplier_quote_selection_delivery_renewal (renewal_id, sent_at),
    INDEX idx_supplier_quote_selection_delivery_recipient (recipient_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_quote_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_supplier_quote_attachments_request FOREIGN KEY (request_id) REFERENCES supplier_quote_requests(id) ON DELETE CASCADE,
    INDEX idx_supplier_quote_attachments_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_unsubscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NULL,
    supplier_contact_id INT UNSIGNED NULL,
    supplier_group_id INT UNSIGNED NULL,
    recipient_email VARCHAR(190) NOT NULL,
    scope ENUM('group', 'all') NOT NULL DEFAULT 'group',
    source_request_id INT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_supplier_unsubscriptions_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    CONSTRAINT fk_supplier_unsubscriptions_contact FOREIGN KEY (supplier_contact_id) REFERENCES supplier_contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_supplier_unsubscriptions_group FOREIGN KEY (supplier_group_id) REFERENCES supplier_groups(id) ON DELETE SET NULL,
    CONSTRAINT fk_supplier_unsubscriptions_request FOREIGN KEY (source_request_id) REFERENCES supplier_quote_requests(id) ON DELETE SET NULL,
    INDEX idx_supplier_unsubscriptions_email (recipient_email),
    INDEX idx_supplier_unsubscriptions_scope (scope, supplier_group_id),
    INDEX idx_supplier_unsubscriptions_supplier (supplier_id, supplier_contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales_offers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
    INDEX idx_sales_offers_status (status, updated_at),
    INDEX idx_sales_offers_template (template_id),
    INDEX idx_sales_offers_customer (customer_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
