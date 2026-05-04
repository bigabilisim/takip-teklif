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
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_renewal_payments_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
        UNIQUE KEY uq_renewal_payments_conversation (conversation_id),
        INDEX idx_renewal_payments_renewal (renewal_id, created_at),
        INDEX idx_renewal_payments_token (token),
        INDEX idx_renewal_payments_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'renewal_notification_deliveries', 'mail_log_id', 'INT UNSIGNED NULL AFTER renewal_id');
ensure_column($pdo, 'renewal_notification_deliveries', 'recipient_name', 'VARCHAR(190) NULL AFTER recipient_email');
ensure_column($pdo, 'renewal_notification_deliveries', 'notification_date', 'DATE NULL AFTER token_hash');
ensure_column($pdo, 'renewal_notification_deliveries', 'sent_at', 'DATETIME NULL AFTER error_message');
ensure_column($pdo, 'renewal_notification_deliveries', 'read_at', 'DATETIME NULL AFTER sent_at');
ensure_column($pdo, 'renewal_notification_deliveries', 'read_ip', 'VARCHAR(45) NULL AFTER read_at');
ensure_column($pdo, 'renewal_notification_deliveries', 'read_user_agent', 'VARCHAR(255) NULL AFTER read_ip');
ensure_column($pdo, 'renewal_notification_deliveries', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER read_user_agent');
ensure_column($pdo, 'renewal_notification_deliveries', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
ensure_index($pdo, 'renewal_notification_deliveries', 'idx_renewal_notification_delivery_mail_log', 'INDEX idx_renewal_notification_delivery_mail_log (mail_log_id)');
ensure_index($pdo, 'renewal_notification_deliveries', 'idx_renewal_notification_delivery_read', 'INDEX idx_renewal_notification_delivery_read (read_at)');
ensure_index($pdo, 'renewal_notification_deliveries', 'idx_renewal_notification_delivery_recipient', 'INDEX idx_renewal_notification_delivery_recipient (recipient_email)');
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
ensure_column($pdo, 'customer_info_requests', 'recipient_name', 'VARCHAR(190) NULL AFTER recipient_email');
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
    if ($name === 'Alan Adi Yenileme') {
        return 'Alan adının süresi bittikten sonraki 20 gün içinde alan adı normal ücretle yenilenebilir. Bu süre içinde, alan adına bağlı web sitesi, e-mailler ve benzer bütün servisler duracaktır. 20 günü aştığı taktirde ise alan adı kurtarma periyoduna girer ve normal ücretle yenilenemez, böyle bir durumda yenilemek isterseniz destek bildirimi açarak güncel kurtarma ücretini sorabilirsiniz. Süre bitiminden 20 gün geçtikten sonra alan adınızı kurtarabileceğiniz ve sahipliğini sağlayabileceğiniz konusunda garanti verememekteyiz. Firmalar arası farklılıklar göstermektedir.';
    }

    return null;
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
