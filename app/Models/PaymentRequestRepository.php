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
        $customerId = empty($data['customer_id']) ? null : (int) $data['customer_id'];
        $title = trim((string) ($data['title'] ?? 'Manuel ödeme talebi'));
        $description = (string) ($data['description'] ?? '');
        $customerName = (string) ($data['customer_name'] ?? '');
        $customerTaxNumber = $this->taxNumberForPayload($data, $customerId, [$customerName, $title, $description]);
        $stmt = $this->db->prepare(
            'INSERT INTO manual_payment_requests
                (public_token, customer_id, title, description, customer_name, customer_email, customer_phone, customer_tax_number, recipients_json, amount, currency, payment_due_date, reminder_time, reminder_start_days_before, reminder_repeat_daily, reminder_until_paid, status, created_by)
             VALUES
                (:public_token, :customer_id, :title, :description, :customer_name, :customer_email, :customer_phone, :customer_tax_number, :recipients_json, :amount, :currency, :payment_due_date, :reminder_time, :reminder_start_days_before, :reminder_repeat_daily, :reminder_until_paid, :status, :created_by)'
        );
        $reminderUntilPaid = !empty($data['reminder_until_paid']) ? 1 : 0;
        $reminderRepeatDaily = (!empty($data['reminder_repeat_daily']) || $reminderUntilPaid) ? 1 : 0;
        $stmt->execute([
            'public_token' => $token,
            'customer_id' => $customerId,
            'title' => $title,
            'description' => $this->nullableString($description),
            'customer_name' => $this->nullableString($customerName),
            'customer_email' => $this->nullableString($data['customer_email'] ?? ''),
            'customer_phone' => $this->nullableString(\normalize_phone_number($data['customer_phone'] ?? '')),
            'customer_tax_number' => $this->nullableString($customerTaxNumber),
            'recipients_json' => $this->jsonOrNull($data['recipients'] ?? []),
            'amount' => max(0.01, (float) ($data['amount'] ?? 0)),
            'currency' => self::normalizeCurrency((string) ($data['currency'] ?? 'TRY')),
            'payment_due_date' => $this->normalizeDate($data['payment_due_date'] ?? ''),
            'reminder_time' => $this->normalizeReminderTime($data['reminder_time'] ?? '', $reminderRepeatDaily === 1),
            'reminder_start_days_before' => $this->normalizeReminderStartDays($data['reminder_start_days_before'] ?? 3),
            'reminder_repeat_daily' => $reminderRepeatDaily,
            'reminder_until_paid' => $reminderUntilPaid,
            'status' => 'pending',
            'created_by' => empty($data['created_by']) ? null : (int) $data['created_by'],
        ]);

        $id = (int) $this->db->lastInsertId();
        $this->syncCustomerTaxNumberIfEmpty((int) ($customerId ?? 0), $customerTaxNumber);

        return $this->ensurePublicToken($id) ?? [];
    }

    public function all(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT mpr.*, u.name AS created_by_name
             FROM manual_payment_requests mpr
             LEFT JOIN users u ON u.id = mpr.created_by
             WHERE mpr.status <> \'cancelled\'
             ORDER BY mpr.created_at DESC, mpr.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function cancel(int $id): bool
    {
        $current = $this->find($id);
        if (!$current) {
            return false;
        }

        if (in_array((string) ($current['status'] ?? ''), ['paid', 'refunded'], true)) {
            throw new \RuntimeException('Tahsilat geçmişi olan ödeme talebi silinemez; gerekiyorsa iade edildi olarak işaretleyin.');
        }

        $stmt = $this->db->prepare(
            'UPDATE manual_payment_requests
             SET status = \'cancelled\',
                 reminder_repeat_daily = 0,
                 reminder_until_paid = 0,
                 updated_at = NOW()
             WHERE id = :id
               AND status NOT IN (\'paid\', \'refunded\')'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function refund(int $id, string $note = ''): bool
    {
        $current = $this->find($id);
        if (!$current) {
            return false;
        }

        $status = (string) ($current['status'] ?? 'pending');
        if ($status === 'refunded') {
            return true;
        }

        if ($status !== 'paid') {
            throw new \RuntimeException('Sadece ödenmiş ödeme talepleri iade edildi olarak işaretlenebilir.');
        }

        $note = trim($note);
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'UPDATE manual_payment_requests
                 SET status = \'refunded\',
                     refunded_at = NOW(),
                     refund_note = :refund_note,
                     reminder_repeat_daily = 0,
                     reminder_until_paid = 0,
                     updated_at = NOW()
                 WHERE id = :id
                   AND status = \'paid\''
            );
            $stmt->execute([
                'id' => $id,
                'refund_note' => $this->nullableString($note),
            ]);

            $this->db->prepare(
                'UPDATE manual_payment_transactions
                 SET status = \'refunded\',
                     payment_status = COALESCE(NULLIF(payment_status, \'\'), \'REFUNDED\'),
                     updated_at = NOW()
                 WHERE request_id = :request_id
                   AND status = \'paid\''
            )->execute(['request_id' => $id]);

            $this->db->prepare(
                'INSERT INTO manual_payment_request_logs
                    (request_id, channel, recipient, subject, body, status, error_message)
                 VALUES
                    (:request_id, \'system\', \'internal\', \'Ödeme iade edildi\', :body, \'refunded\', NULL)'
            )->execute([
                'request_id' => $id,
                'body' => $note !== '' ? $note : 'Ödeme iade edildi olarak işaretlendi.',
            ]);

            $this->db->commit();

            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function paidCardPaymentRows(int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                'manual' AS source_type,
                mpt.id AS payment_row_id,
                NULL AS renewal_id,
                mpr.id AS request_id,
                mpt.provider,
                mpt.amount,
                mpt.currency,
                mpt.status,
                mpt.payment_status,
                mpt.payment_id,
                mpt.error_message,
                mpt.paid_at,
                mpt.created_at,
                mpt.updated_at,
                mpr.title,
                mpr.description,
                mpr.customer_name AS company_name,
                mpr.customer_email,
                mpr.customer_phone
             FROM manual_payment_transactions mpt
             INNER JOIN manual_payment_requests mpr ON mpr.id = mpt.request_id
             WHERE mpt.provider = 'iyzico'
               AND mpt.status = 'paid'
               AND COALESCE(mpt.payment_id, '') <> ''
             ORDER BY COALESCE(mpt.paid_at, mpt.updated_at, mpt.created_at) DESC, mpt.id DESC
             LIMIT :limit"
        );
        $stmt->bindValue('limit', max(1, min(300, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function paidCardPaymentsPendingInternalNotifications(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT mpt.*, mpr.public_token, mpr.customer_id, mpr.title, mpr.description, mpr.customer_name, mpr.customer_email, mpr.customer_phone, mpr.customer_tax_number, mpr.recipients_json
             FROM manual_payment_transactions mpt
             INNER JOIN manual_payment_requests mpr ON mpr.id = mpt.request_id
             WHERE mpt.provider = 'iyzico'
               AND mpt.status = 'paid'
               AND COALESCE(mpt.payment_id, '') <> ''
               AND (mpt.internal_push_sent_at IS NULL OR mpt.internal_mail_sent_at IS NULL)
             ORDER BY COALESCE(mpt.paid_at, mpt.updated_at, mpt.created_at) ASC, mpt.id ASC
             LIMIT :limit"
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

    public function ensurePublicToken(int $id): ?array
    {
        $current = $this->find($id);
        if (!$current) {
            return null;
        }

        if (trim((string) ($current['public_token'] ?? '')) !== '') {
            return $current;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = bin2hex(random_bytes(24));

            try {
                $stmt = $this->db->prepare(
                    'UPDATE manual_payment_requests
                     SET public_token = :public_token,
                         updated_at = NOW()
                     WHERE id = :id
                       AND (public_token IS NULL OR public_token = \'\')'
                );
                $stmt->execute([
                    'id' => $id,
                    'public_token' => $token,
                ]);

                return $this->find($id);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        return $this->find($id);
    }

    public function update(int $id, array $data): ?array
    {
        $current = $this->find($id);
        if (!$current) {
            return null;
        }

        $isFinalized = in_array((string) ($current['status'] ?? ''), ['paid', 'refunded'], true);
        $amount = $isFinalized
            ? (float) ($current['amount'] ?? 0)
            : max(0.01, (float) ($data['amount'] ?? $current['amount'] ?? 0));
        $currency = $isFinalized
            ? (string) ($current['currency'] ?? 'TRY')
            : self::normalizeCurrency((string) ($data['currency'] ?? $current['currency'] ?? 'TRY'));
        $customerId = (int) ($current['customer_id'] ?? 0);
        $title = trim((string) ($data['title'] ?? $current['title'] ?? 'Manuel ödeme talebi'));
        $description = (string) ($data['description'] ?? '');
        $customerName = (string) ($data['customer_name'] ?? '');
        $customerTaxNumber = $this->taxNumberForPayload($data, $customerId > 0 ? $customerId : null, [$customerName, $title, $description]);

        $stmt = $this->db->prepare(
            'UPDATE manual_payment_requests
             SET title = :title,
                 description = :description,
                 customer_name = :customer_name,
                 customer_email = :customer_email,
                 customer_phone = :customer_phone,
                 customer_tax_number = :customer_tax_number,
                 recipients_json = :recipients_json,
                 amount = :amount,
                 currency = :currency,
                 payment_due_date = :payment_due_date,
                 reminder_time = :reminder_time,
                 reminder_start_days_before = :reminder_start_days_before,
                 reminder_repeat_daily = :reminder_repeat_daily,
                 reminder_until_paid = :reminder_until_paid,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $reminderUntilPaid = !empty($data['reminder_until_paid']) ? 1 : 0;
        $reminderRepeatDaily = (!empty($data['reminder_repeat_daily']) || $reminderUntilPaid) ? 1 : 0;
        $stmt->execute([
            'id' => $id,
            'title' => $title,
            'description' => $this->nullableString($description),
            'customer_name' => $this->nullableString($customerName),
            'customer_email' => $this->nullableString($data['customer_email'] ?? ''),
            'customer_phone' => $this->nullableString(\normalize_phone_number($data['customer_phone'] ?? '')),
            'customer_tax_number' => $this->nullableString($customerTaxNumber),
            'recipients_json' => array_key_exists('recipients', $data) ? $this->jsonOrNull($data['recipients']) : ($current['recipients_json'] ?? null),
            'amount' => $amount,
            'currency' => $currency,
            'payment_due_date' => $this->normalizeDate($data['payment_due_date'] ?? $current['payment_due_date'] ?? ''),
            'reminder_time' => $this->normalizeReminderTime($data['reminder_time'] ?? $current['reminder_time'] ?? '', $reminderRepeatDaily === 1),
            'reminder_start_days_before' => $this->normalizeReminderStartDays($data['reminder_start_days_before'] ?? $current['reminder_start_days_before'] ?? 3),
            'reminder_repeat_daily' => $reminderRepeatDaily,
            'reminder_until_paid' => $reminderUntilPaid,
        ]);

        $this->syncCustomerEmailIfEmpty((int) ($current['customer_id'] ?? 0), (string) ($data['customer_email'] ?? ''));
        $this->syncCustomerTaxNumberIfEmpty((int) ($current['customer_id'] ?? 0), $customerTaxNumber);

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

    public function updatePublicTaxNumber(int $id, string $taxNumber): ?array
    {
        $taxNumber = self::normalizeTaxNumber($taxNumber);
        if ($taxNumber === '') {
            throw new \RuntimeException('Vergi no / TC kimlik no 10 veya 11 hane olmalı.');
        }

        $current = $this->find($id);
        if (!$current) {
            return null;
        }

        $this->db->prepare(
            'UPDATE manual_payment_requests
             SET customer_tax_number = :tax_number,
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'id' => $id,
            'tax_number' => $taxNumber,
        ]);

        $this->syncCustomerTaxNumberIfEmpty((int) ($current['customer_id'] ?? 0), $taxNumber);

        return $this->find($id);
    }

    public function resolveTaxNumberForRequest(array $request): string
    {
        return $this->taxNumberForPayload($request, empty($request['customer_id']) ? null : (int) $request['customer_id'], [
            (string) ($request['customer_name'] ?? ''),
            (string) ($request['company_name'] ?? ''),
            (string) ($request['title'] ?? ''),
            (string) ($request['description'] ?? ''),
        ]);
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
            'SELECT mpt.*, mpr.public_token, mpr.customer_id, mpr.title, mpr.description, mpr.customer_name, mpr.customer_email, mpr.customer_phone, mpr.customer_tax_number, mpr.recipients_json
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

    public function latestPaidTransactionForRequests(array $requestIds): ?array
    {
        $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds), static fn (int $id): bool => $id > 0)));
        if ($requestIds === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = $this->db->prepare(
            'SELECT mpt.*, mpr.public_token, mpr.customer_id, mpr.title, mpr.description, mpr.customer_name, mpr.customer_email, mpr.customer_phone, mpr.customer_tax_number, mpr.recipients_json
             FROM manual_payment_transactions mpt
             INNER JOIN manual_payment_requests mpr ON mpr.id = mpt.request_id
             WHERE mpt.request_id IN (' . $placeholders . ')
               AND mpt.status = \'paid\'
             ORDER BY COALESCE(mpt.paid_at, mpt.updated_at, mpt.created_at) DESC, mpt.id DESC
             LIMIT 1'
        );
        $stmt->execute($requestIds);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateIyzicoPaymentResult(int $paymentId, array $request, array $response, string $status): void
    {
        $currentStatus = (string) $this->db->query('SELECT status FROM manual_payment_transactions WHERE id = ' . (int) $paymentId)->fetchColumn();
        if ($currentStatus === 'refunded') {
            return;
        }
        if ($currentStatus === 'paid' && $status !== 'paid') {
            return;
        }

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

    public function markPaymentInternalNotification(int $paymentId, bool $pushSent, bool $mailSent, string $error = ''): void
    {
        if ($paymentId < 1) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE manual_payment_transactions
             SET internal_push_sent_at = CASE WHEN :push_sent = 1 THEN COALESCE(internal_push_sent_at, NOW()) ELSE internal_push_sent_at END,
                 internal_mail_sent_at = CASE WHEN :mail_sent = 1 THEN COALESCE(internal_mail_sent_at, NOW()) ELSE internal_mail_sent_at END,
                 internal_notification_attempts = internal_notification_attempts + 1,
                 internal_notification_error = :error_message,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $paymentId,
            'push_sent' => $pushSent ? 1 : 0,
            'mail_sent' => $mailSent ? 1 : 0,
            'error_message' => trim($error) !== '' ? mb_substr($error, 0, 1000) : null,
        ]);
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

    public function dueForDailyReminders(bool $force = false, int $limit = 100): array
    {
        $conditions = [
            "status = 'pending'",
            "(COALESCE(customer_email, '') <> '' OR COALESCE(recipients_json, '') <> '')",
            '(reminder_repeat_daily = 1 OR reminder_until_paid = 1)',
        ];
        $params = [];

        if (!$force) {
            $conditions[] = 'reminder_time IS NOT NULL';
            $conditions[] = 'reminder_time <= :current_time';
            $conditions[] = '(last_reminder_sent_at IS NULL OR DATE(last_reminder_sent_at) < :today)';
            $conditions[] = '(payment_due_date IS NULL OR DATE_SUB(payment_due_date, INTERVAL reminder_start_days_before DAY) <= :today)';
            $params['current_time'] = date('H:i:s');
            $params['today'] = date('Y-m-d');
        }

        $stmt = $this->db->prepare(
            'SELECT *
             FROM manual_payment_requests
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY reminder_time ASC, created_at ASC, id ASC
             LIMIT :limit'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue('limit', max(1, min(300, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function markReminderAttempted(int $id): void
    {
        $this->db->prepare(
            'UPDATE manual_payment_requests
             SET last_reminder_sent_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id'
        )->execute(['id' => $id]);
    }

    public static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        return in_array($currency, ['TRY', 'USD', 'EUR'], true) ? $currency : 'TRY';
    }

    public static function normalizeTaxNumber(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return in_array(strlen($digits), [10, 11], true) ? $digits : '';
    }

    public static function extractTaxNumberFromText(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        preg_match_all('/(?<!\d)(\d[\d\s.\-\/]{8,22}\d)(?!\d)/u', $value, $matches);
        foreach ($matches[1] ?? [] as $candidate) {
            $taxNumber = self::normalizeTaxNumber((string) $candidate);
            if ($taxNumber !== '') {
                return $taxNumber;
            }
        }

        return '';
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
                payment_due_date DATE NULL,
                reminder_time TIME NULL,
                reminder_start_days_before INT UNSIGNED NOT NULL DEFAULT 3,
                reminder_repeat_daily TINYINT(1) NOT NULL DEFAULT 0,
                reminder_until_paid TINYINT(1) NOT NULL DEFAULT 0,
                last_reminder_sent_at DATETIME NULL,
                status ENUM('pending','paid','refunded','cancelled') NOT NULL DEFAULT 'pending',
                paid_at DATETIME NULL,
                refunded_at DATETIME NULL,
                refund_note TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_manual_payment_requests_token (public_token),
                INDEX idx_manual_payment_requests_customer (customer_id),
                INDEX idx_manual_payment_requests_due_reminders (status, payment_due_date, reminder_time, last_reminder_sent_at),
                INDEX idx_manual_payment_requests_status (status, created_at),
                INDEX idx_manual_payment_requests_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->ensureColumn('manual_payment_requests', 'public_token', 'VARCHAR(96) NULL AFTER id');
        $this->ensureColumn('manual_payment_requests', 'customer_id', 'INT UNSIGNED NULL AFTER public_token');
        $this->ensureColumn('manual_payment_requests', 'recipients_json', 'MEDIUMTEXT NULL AFTER customer_tax_number');
        $this->ensureColumn('manual_payment_requests', 'payment_due_date', 'DATE NULL AFTER currency');
        $this->ensureColumn('manual_payment_requests', 'reminder_time', 'TIME NULL AFTER payment_due_date');
        $this->ensureColumn('manual_payment_requests', 'reminder_start_days_before', 'INT UNSIGNED NOT NULL DEFAULT 3 AFTER reminder_time');
        $this->ensureColumn('manual_payment_requests', 'reminder_repeat_daily', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_start_days_before');
        $this->ensureColumn('manual_payment_requests', 'reminder_until_paid', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_repeat_daily');
        $this->ensureColumn('manual_payment_requests', 'last_reminder_sent_at', 'DATETIME NULL AFTER reminder_until_paid');
        $this->ensureColumn('manual_payment_requests', 'refunded_at', 'DATETIME NULL AFTER paid_at');
        $this->ensureColumn('manual_payment_requests', 'refund_note', 'TEXT NULL AFTER refunded_at');
        $this->ensureManualPaymentStatusEnum();
        $this->backfillMissingPublicTokens();
        $this->ensureIndex('manual_payment_requests', 'uq_manual_payment_requests_token', 'UNIQUE KEY uq_manual_payment_requests_token (public_token)');
        $this->ensureIndex('manual_payment_requests', 'idx_manual_payment_requests_customer', 'INDEX idx_manual_payment_requests_customer (customer_id)');
        $this->ensureIndex('manual_payment_requests', 'idx_manual_payment_requests_due_reminders', 'INDEX idx_manual_payment_requests_due_reminders (status, payment_due_date, reminder_time, last_reminder_sent_at)');

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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->ensureColumn('manual_payment_transactions', 'internal_push_sent_at', 'DATETIME NULL AFTER paid_at');
        $this->ensureColumn('manual_payment_transactions', 'internal_mail_sent_at', 'DATETIME NULL AFTER internal_push_sent_at');
        $this->ensureColumn('manual_payment_transactions', 'internal_notification_attempts', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER internal_mail_sent_at');
        $this->ensureColumn('manual_payment_transactions', 'internal_notification_error', 'TEXT NULL AFTER internal_notification_attempts');
        $this->ensureIndex('manual_payment_transactions', 'idx_manual_payment_transactions_internal_notice', 'INDEX idx_manual_payment_transactions_internal_notice (status, internal_push_sent_at, internal_mail_sent_at)');

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

    private function backfillMissingPublicTokens(): void
    {
        $stmt = $this->db->query(
            'SELECT id
             FROM manual_payment_requests
             WHERE public_token IS NULL OR public_token = \'\'
             ORDER BY id ASC
             LIMIT 250'
        );

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->ensurePublicToken((int) $id);
        }
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

    private function ensureManualPaymentStatusEnum(): void
    {
        $stmt = $this->db->prepare(
            'SELECT COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1'
        );
        $stmt->execute([
            'table' => 'manual_payment_requests',
            'column' => 'status',
        ]);
        $columnType = (string) $stmt->fetchColumn();

        if ($columnType !== '' && str_contains($columnType, "'refunded'")) {
            return;
        }

        $this->db->exec(
            "ALTER TABLE manual_payment_requests
             MODIFY status ENUM('pending','paid','refunded','cancelled') NOT NULL DEFAULT 'pending'"
        );
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

    private function syncCustomerTaxNumberIfEmpty(int $customerId, string $taxNumber): void
    {
        $taxNumber = self::normalizeTaxNumber($taxNumber);
        if ($customerId < 1 || $taxNumber === '') {
            return;
        }

        $this->db->prepare(
            "UPDATE customers
             SET tax_number = :tax_number,
                 updated_at = NOW()
             WHERE id = :id
               AND deleted_at IS NULL
               AND COALESCE(tax_number, '') = ''"
        )->execute([
            'id' => $customerId,
            'tax_number' => $taxNumber,
        ]);
    }

    private function taxNumberForPayload(array $data, ?int $customerId, array $textCandidates = []): string
    {
        $taxNumber = self::normalizeTaxNumber((string) ($data['customer_tax_number'] ?? ''));
        if ($taxNumber !== '') {
            return $taxNumber;
        }

        if ($customerId !== null && $customerId > 0) {
            $taxNumber = $this->customerTaxNumber($customerId);
            if ($taxNumber !== '') {
                return $taxNumber;
            }
        }

        $customerName = trim((string) (($data['customer_name'] ?? '') ?: ($data['company_name'] ?? '')));
        if ($customerName !== '') {
            $taxNumber = $this->customerTaxNumberByName($customerName);
            if ($taxNumber !== '') {
                return $taxNumber;
            }
        }

        return self::extractTaxNumberFromText(implode(' ', array_map(static fn (mixed $value): string => (string) $value, $textCandidates)));
    }

    private function customerTaxNumber(int $customerId): string
    {
        if ($customerId < 1) {
            return '';
        }

        $stmt = $this->db->prepare(
            "SELECT tax_number, company_name
             FROM customers
             WHERE id = :id
               AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute(['id' => $customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            return '';
        }

        $taxNumber = self::normalizeTaxNumber((string) ($customer['tax_number'] ?? ''));
        if ($taxNumber !== '') {
            return $taxNumber;
        }

        return self::extractTaxNumberFromText((string) ($customer['company_name'] ?? ''));
    }

    private function customerTaxNumberByName(string $customerName): string
    {
        $customerName = trim(preg_replace('/\s+/', ' ', $customerName) ?? '');
        if ($customerName === '') {
            return '';
        }

        $stmt = $this->db->prepare(
            "SELECT tax_number, company_name
             FROM customers
             WHERE deleted_at IS NULL
               AND company_name = :company_name
             LIMIT 1"
        );
        $stmt->execute(['company_name' => $customerName]);
        $customer = $stmt->fetch();
        if ($customer) {
            $taxNumber = self::normalizeTaxNumber((string) ($customer['tax_number'] ?? ''));
            if ($taxNumber !== '') {
                return $taxNumber;
            }

            $taxNumber = self::extractTaxNumberFromText((string) ($customer['company_name'] ?? ''));
            if ($taxNumber !== '') {
                return $taxNumber;
            }
        }

        $stmt = $this->db->prepare(
            "SELECT tax_number, company_name
             FROM customers
             WHERE deleted_at IS NULL
               AND company_name LIKE :company_name
             ORDER BY company_name ASC
             LIMIT 5"
        );
        $stmt->execute(['company_name' => '%' . $customerName . '%']);
        foreach ($stmt->fetchAll() as $candidate) {
            $taxNumber = self::normalizeTaxNumber((string) ($candidate['tax_number'] ?? ''));
            if ($taxNumber !== '') {
                return $taxNumber;
            }

            $taxNumber = self::extractTaxNumberFromText((string) ($candidate['company_name'] ?? ''));
            if ($taxNumber !== '') {
                return $taxNumber;
            }
        }

        return '';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeReminderTime(mixed $value, bool $needsDefault = false): ?string
    {
        $time = trim((string) $value);
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $time, $matches) === 1) {
            return $matches[1] . ':' . $matches[2] . ':00';
        }

        return $needsDefault ? '09:00:00' : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $date = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return $date;
    }

    private function normalizeReminderStartDays(mixed $value): int
    {
        return max(0, min(365, (int) $value));
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
