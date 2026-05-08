<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class PaymentRequestRepository
{
    private PDO $db;
    private static bool $schemaEnsured = false;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->ensureSchema();
    }

    public function create(array $data): array
    {
        $token = bin2hex(random_bytes(24));
        $stmt = $this->db->prepare(
            'INSERT INTO manual_payment_requests
                (public_token, customer_id, title, description, customer_name, customer_email, customer_phone, customer_tax_number, recipients_json, amount, currency, status, created_by)
             VALUES
                (:public_token, :customer_id, :title, :description, :customer_name, :customer_email, :customer_phone, :customer_tax_number, :recipients_json, :amount, :currency, :status, :created_by)'
        );
        $stmt->execute([
            'public_token' => $token,
            'customer_id' => empty($data['customer_id']) ? null : (int) $data['customer_id'],
            'title' => trim((string) ($data['title'] ?? 'Manuel ödeme talebi')),
            'description' => $this->nullableString($data['description'] ?? ''),
            'customer_name' => $this->nullableString($data['customer_name'] ?? ''),
            'customer_email' => $this->nullableString($data['customer_email'] ?? ''),
            'customer_phone' => $this->nullableString(\normalize_phone_number($data['customer_phone'] ?? '')),
            'customer_tax_number' => $this->nullableString($data['customer_tax_number'] ?? ''),
            'recipients_json' => $this->jsonOrNull($data['recipients'] ?? []),
            'amount' => max(0.01, (float) ($data['amount'] ?? 0)),
            'currency' => self::normalizeCurrency((string) ($data['currency'] ?? 'TRY')),
            'status' => 'pending',
            'created_by' => empty($data['created_by']) ? null : (int) $data['created_by'],
        ]);

        return $this->find((int) $this->db->lastInsertId()) ?? [];
    }

    public function all(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT mpr.*, u.name AS created_by_name
             FROM manual_payment_requests mpr
             LEFT JOIN users u ON u.id = mpr.created_by
             ORDER BY mpr.created_at DESC, mpr.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT mpr.*, u.name AS created_by_name
             FROM manual_payment_requests mpr
             LEFT JOIN users u ON u.id = mpr.created_by
             WHERE mpr.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT mpr.*, u.name AS created_by_name
             FROM manual_payment_requests mpr
             LEFT JOIN users u ON u.id = mpr.created_by
             WHERE mpr.public_token = :token
             LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function update(int $id, array $data): ?array
    {
        $current = $this->find($id);
        if (!$current) {
            return null;
        }

        $isPaid = (string) ($current['status'] ?? '') === 'paid';
        $amount = $isPaid
            ? (float) ($current['amount'] ?? 0)
            : max(0.01, (float) ($data['amount'] ?? $current['amount'] ?? 0));
        $currency = $isPaid
            ? (string) ($current['currency'] ?? 'TRY')
            : self::normalizeCurrency((string) ($data['currency'] ?? $current['currency'] ?? 'TRY'));

        $stmt = $this->db->prepare(
            'UPDATE manual_payment_requests
             SET title = :title,
                 description = :description,
                 customer_name = :customer_name,
                 customer_email = :customer_email,
                 customer_phone = :customer_phone,
                 customer_tax_number = :customer_tax_number,
                 amount = :amount,
                 currency = :currency,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'title' => trim((string) ($data['title'] ?? $current['title'] ?? 'Manuel ödeme talebi')),
            'description' => $this->nullableString($data['description'] ?? ''),
            'customer_name' => $this->nullableString($data['customer_name'] ?? ''),
            'customer_email' => $this->nullableString($data['customer_email'] ?? ''),
            'customer_phone' => $this->nullableString(\normalize_phone_number($data['customer_phone'] ?? '')),
            'customer_tax_number' => $this->nullableString($data['customer_tax_number'] ?? ''),
            'amount' => $amount,
            'currency' => $currency,
        ]);

        $this->syncCustomerEmailIfEmpty((int) ($current['customer_id'] ?? 0), (string) ($data['customer_email'] ?? ''));

        return $this->find($id);
    }

    public function updatePublicEmail(int $id, string $email): ?array
    {
        $email = trim(mb_strtolower($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Geçerli bir e-posta adresi yazın.');
        }

        $current = $this->find($id);
        if (!$current) {
            return null;
        }

        $this->db->prepare(
            'UPDATE manual_payment_requests
             SET customer_email = :email,
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'id' => $id,
            'email' => $email,
        ]);

        $this->syncCustomerEmailIfEmpty((int) ($current['customer_id'] ?? 0), $email);

        return $this->find($id);
    }

    public function createIyzicoPayment(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO manual_payment_transactions
                (request_id, provider, conversation_id, token, amount, currency, status, payment_status, payment_id, payment_page_url, error_message, raw_request, raw_response, created_by)
             VALUES
                (:request_id, :provider, :conversation_id, :token, :amount, :currency, :status, :payment_status, :payment_id, :payment_page_url, :error_message, :raw_request, :raw_response, :created_by)'
        );
        $stmt->execute([
            'request_id' => (int) $data['request_id'],
            'provider' => 'iyzico',
            'conversation_id' => (string) $data['conversation_id'],
            'token' => $this->nullableString($data['token'] ?? ''),
            'amount' => max(0.01, (float) ($data['amount'] ?? 0)),
            'currency' => self::normalizeCurrency((string) ($data['currency'] ?? 'TRY')),
            'status' => (string) ($data['status'] ?? 'pending'),
            'payment_status' => $this->nullableString($data['payment_status'] ?? ''),
            'payment_id' => $this->nullableString($data['payment_id'] ?? ''),
            'payment_page_url' => $this->nullableString($data['payment_page_url'] ?? ''),
            'error_message' => $this->nullableString($data['error_message'] ?? ''),
            'raw_request' => $this->jsonOrNull($data['raw_request'] ?? null),
            'raw_response' => $this->jsonOrNull($data['raw_response'] ?? null),
            'created_by' => empty($data['created_by']) ? null : (int) $data['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findIyzicoPaymentByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT mpt.*, mpr.public_token, mpr.title, mpr.description, mpr.customer_name, mpr.customer_email, mpr.customer_phone, mpr.customer_tax_number
             FROM manual_payment_transactions mpt
             INNER JOIN manual_payment_requests mpr ON mpr.id = mpt.request_id
             WHERE mpt.provider = :provider
               AND mpt.token = :token
             LIMIT 1'
        );
        $stmt->execute([
            'provider' => 'iyzico',
            'token' => $token,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateIyzicoPaymentResult(int $paymentId, array $request, array $response, string $status): void
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'UPDATE manual_payment_transactions SET
                    status = :status,
                    payment_status = :payment_status,
                    payment_id = :provider_payment_id,
                    error_message = :error_message,
                    raw_request = :raw_request,
                    raw_response = :raw_response,
                    paid_at = CASE WHEN :paid_status = \'paid\' AND paid_at IS NULL THEN NOW() ELSE paid_at END,
                    updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $paymentId,
                'status' => $status,
                'paid_status' => $status,
                'payment_status' => $this->nullableString($response['paymentStatus'] ?? ''),
                'provider_payment_id' => $this->nullableString($response['paymentId'] ?? ''),
                'error_message' => $this->nullableString($response['errorMessage'] ?? ''),
                'raw_request' => $this->jsonOrNull($request),
                'raw_response' => $this->jsonOrNull($response),
            ]);

            if ($status === 'paid') {
                $requestId = (int) $this->db->query('SELECT request_id FROM manual_payment_transactions WHERE id = ' . (int) $paymentId)->fetchColumn();
                if ($requestId > 0) {
                    $this->db->prepare(
                        'UPDATE manual_payment_requests
                         SET status = \'paid\', paid_at = COALESCE(paid_at, NOW()), updated_at = NOW()
                         WHERE id = :id'
                    )->execute(['id' => $requestId]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function logDelivery(int $requestId, string $channel, string $recipient, string $subject, string $body, string $status, ?string $error = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO manual_payment_request_logs
                (request_id, channel, recipient, subject, body, status, error_message)
             VALUES
                (:request_id, :channel, :recipient, :subject, :body, :status, :error_message)'
        );
        $stmt->execute([
            'request_id' => $requestId,
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'status' => $status,
            'error_message' => $error,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        return in_array($currency, ['TRY', 'USD', 'EUR'], true) ? $currency : 'TRY';
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS manual_payment_requests (
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
                status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
                paid_at DATETIME NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_manual_payment_requests_token (public_token),
                INDEX idx_manual_payment_requests_customer (customer_id),
                INDEX idx_manual_payment_requests_status (status, created_at),
                INDEX idx_manual_payment_requests_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->ensureColumn('manual_payment_requests', 'customer_id', 'INT UNSIGNED NULL AFTER public_token');
        $this->ensureColumn('manual_payment_requests', 'recipients_json', 'MEDIUMTEXT NULL AFTER customer_tax_number');
        $this->ensureIndex('manual_payment_requests', 'idx_manual_payment_requests_customer', 'INDEX idx_manual_payment_requests_customer (customer_id)');

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS manual_payment_transactions (
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
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_manual_payment_transactions_request FOREIGN KEY (request_id) REFERENCES manual_payment_requests(id) ON DELETE CASCADE,
                UNIQUE KEY uq_manual_payment_transactions_conversation (conversation_id),
                INDEX idx_manual_payment_transactions_token (token),
                INDEX idx_manual_payment_transactions_status (status),
                INDEX idx_manual_payment_transactions_request (request_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS manual_payment_request_logs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaEnsured = true;
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);

        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` %s',
                str_replace('`', '``', $table),
                str_replace('`', '``', $column),
                $definition
            ));
        }
    }

    private function ensureIndex(string $table, string $index, string $definition): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND INDEX_NAME = :index_name'
        );
        $stmt->execute(['table' => $table, 'index_name' => $index]);

        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec(sprintf('ALTER TABLE `%s` ADD %s', str_replace('`', '``', $table), $definition));
        }
    }

    private function syncCustomerEmailIfEmpty(int $customerId, string $email): void
    {
        $email = trim(mb_strtolower($email));
        if ($customerId < 1 || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $this->db->prepare(
            "UPDATE customers
             SET email = :email,
                 updated_at = NOW()
             WHERE id = :id
               AND deleted_at IS NULL
               AND COALESCE(email, '') = ''"
        )->execute([
            'id' => $customerId,
            'email' => $email,
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function jsonOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
