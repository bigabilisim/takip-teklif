<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class CustomerInfoRequestRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->ensureSchema();
    }

    public function create(string $email, ?int $customerId = null, ?int $createdBy = null, string $recipientName = ''): array
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO customer_info_requests
                (customer_id, token_hash, recipient_email, recipient_name, status, expires_at, created_by)
             VALUES
                (:customer_id, :token_hash, :recipient_email, :recipient_name, "pending", DATE_ADD(NOW(), INTERVAL 14 DAY), :created_by)'
        );
        $stmt->execute([
            'customer_id' => $customerId ?: null,
            'token_hash' => hash('sha256', $token),
            'recipient_email' => $email,
            'recipient_name' => trim($recipientName),
            'created_by' => $createdBy ?: null,
        ]);

        return $this->find((int) $this->db->lastInsertId()) + ['token' => $token];
    }

    public function find(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM customer_info_requests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: [];
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM customer_info_requests WHERE token_hash = :token_hash LIMIT 1');
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        $request = $stmt->fetch();
        if (!$request) {
            return null;
        }

        if (($request['status'] ?? '') === 'pending' && strtotime((string) $request['expires_at']) < time()) {
            $this->db->prepare('UPDATE customer_info_requests SET status = "expired", updated_at = NOW() WHERE id = :id')
                ->execute(['id' => (int) $request['id']]);
            $request['status'] = 'expired';
        }

        return $request;
    }

    public function recent(int $limit = 6): array
    {
        $stmt = $this->db->prepare(
            'SELECT cir.*, c.company_name AS customer_name, sc.company_name AS submitted_customer_name
             FROM customer_info_requests cir
             LEFT JOIN customers c ON c.id = cir.customer_id
             LEFT JOIN customers sc ON sc.id = cir.submitted_customer_id
             ORDER BY cir.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', max(1, min($limit, 20)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function complete(array $request, array $data, ?string $uploadPath, array $extracted): int
    {
        $data = $this->normalizedSubmissionData($data);
        $extracted = $this->normalizedSubmissionData($extracted);
        $customerId = $this->upsertCustomer((int) ($request['customer_id'] ?? 0), $data);

        $stmt = $this->db->prepare(
            'UPDATE customer_info_requests
             SET status = "submitted",
                 submitted_payload = :submitted_payload,
                 extracted_payload = :extracted_payload,
                 upload_path = COALESCE(:upload_path, upload_path),
                 submitted_customer_id = :submitted_customer_id,
                 submitted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => (int) $request['id'],
            'submitted_payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'extracted_payload' => json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'upload_path' => $uploadPath,
            'submitted_customer_id' => $customerId,
        ]);

        return $customerId;
    }

    private function normalizedSubmissionData(array $data): array
    {
        if (array_key_exists('phone', $data)) {
            $data['phone'] = \normalize_phone_number($data['phone']);
        }

        if (!empty($data['contacts']) && is_array($data['contacts'])) {
            foreach ($data['contacts'] as $index => $contact) {
                if (!is_array($contact) || !array_key_exists('phone', $contact)) {
                    continue;
                }

                $data['contacts'][$index]['phone'] = \normalize_phone_number($contact['phone']);
            }
        }

        return $data;
    }

    private function upsertCustomer(int $preferredCustomerId, array $data): int
    {
        $customerId = $preferredCustomerId > 0 ? $preferredCustomerId : $this->findExistingCustomerId($data);
        $contacts = $this->submittedContacts($data);
        if ($contacts === []) {
            $contacts = $this->legacyContactRows($data);
        }
        $primaryContact = $contacts[0] ?? [];
        $params = [
            'company_name' => trim((string) ($data['company_name'] ?? '')),
            'contact_name' => trim((string) ($data['contact_name'] ?? ($primaryContact['full_name'] ?? ''))),
            'email' => trim((string) ($data['email'] ?? ($primaryContact['email'] ?? ''))),
            'phone' => \normalize_phone_number($data['phone'] ?? ($primaryContact['phone'] ?? '')),
            'tax_office' => trim((string) ($data['tax_office'] ?? '')),
            'tax_number' => trim((string) ($data['tax_number'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'district' => trim((string) ($data['district'] ?? '')),
            'address' => trim((string) ($data['address'] ?? '')),
            'notes' => trim((string) ($data['notes'] ?? '')),
        ];

        if ($customerId > 0) {
            $current = $this->customer($customerId);
            foreach ($params as $key => $value) {
                if ($value === '') {
                    $params[$key] = (string) ($current[$key] ?? '');
                }
            }
            $params['id'] = $customerId;
            $this->db->prepare(
                'UPDATE customers SET
                    company_name = :company_name,
                    contact_name = :contact_name,
                    email = :email,
                    phone = :phone,
                    tax_office = :tax_office,
                    tax_number = :tax_number,
                    city = :city,
                    district = :district,
                    address = :address,
                    notes = :notes,
                    updated_at = NOW()
                 WHERE id = :id'
            )->execute($params);
        } else {
            $this->db->prepare(
                'INSERT INTO customers
                    (company_name, contact_name, email, phone, tax_office, tax_number, city, district, address, notes)
                 VALUES
                    (:company_name, :contact_name, :email, :phone, :tax_office, :tax_number, :city, :district, :address, :notes)'
            )->execute($params);
            $customerId = (int) $this->db->lastInsertId();
        }

        $this->upsertContacts($customerId, $contacts);

        return $customerId;
    }

    private function findExistingCustomerId(array $data): int
    {
        $taxNumber = trim((string) ($data['tax_number'] ?? ''));
        if ($taxNumber !== '') {
            $stmt = $this->db->prepare('SELECT id FROM customers WHERE tax_number = :tax_number AND deleted_at IS NULL LIMIT 1');
            $stmt->execute(['tax_number' => $taxNumber]);
            $id = (int) $stmt->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '') {
            $stmt = $this->db->prepare('SELECT id FROM customers WHERE email = :email AND deleted_at IS NULL LIMIT 1');
            $stmt->execute(['email' => $email]);

            return (int) $stmt->fetchColumn();
        }

        return 0;
    }

    private function customer(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: [];
    }

    private function upsertContacts(int $customerId, array $contacts): void
    {
        foreach ($contacts as $contact) {
            $this->upsertContact($customerId, $contact);
        }
    }

    private function upsertContact(int $customerId, array $data): void
    {
        $name = trim((string) ($data['full_name'] ?? $data['contact_name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $phone = \normalize_phone_number($data['phone'] ?? '');
        $notifyEnabled = !array_key_exists('notify_enabled', $data) || !empty($data['notify_enabled']) ? 1 : 0;
        if ($name === '' && $email === '' && $phone === '') {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM customer_contacts
             WHERE customer_id = :customer_id
               AND ((email <> "" AND email = :email) OR (email IS NULL AND full_name = :full_name))
             LIMIT 1'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'email' => $email,
            'full_name' => $name,
        ]);
        $existingId = (int) $stmt->fetchColumn();
        if ($existingId > 0) {
            $this->db->prepare(
                'UPDATE customer_contacts
                 SET full_name = :full_name,
                     email = :email,
                     phone = :phone,
                     notify_enabled = :notify_enabled,
                     updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                'id' => $existingId,
                'full_name' => $name !== '' ? $name : ($email !== '' ? $email : 'Cari yetkilisi'),
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'notify_enabled' => $notifyEnabled,
            ]);
            return;
        }

        $this->db->prepare(
            'INSERT INTO customer_contacts (customer_id, full_name, email, phone, notify_enabled)
             VALUES (:customer_id, :full_name, :email, :phone, :notify_enabled)'
        )->execute([
            'customer_id' => $customerId,
            'full_name' => $name !== '' ? $name : ($email !== '' ? $email : 'Cari yetkilisi'),
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'notify_enabled' => $notifyEnabled,
        ]);
    }

    private function submittedContacts(array $data): array
    {
        $rows = $data['contacts'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $contacts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['full_name'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            $phone = \normalize_phone_number($row['phone'] ?? '');
            if ($name === '' && $email === '' && $phone === '') {
                continue;
            }

            $contacts[] = [
                'full_name' => $name !== '' ? $name : ($email !== '' ? $email : $phone),
                'email' => $email,
                'phone' => $phone,
                'notify_enabled' => !empty($row['notify_enabled']) ? 1 : 0,
            ];
        }

        return $contacts;
    }

    private function legacyContactRows(array $data): array
    {
        $name = trim((string) ($data['contact_name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $phone = \normalize_phone_number($data['phone'] ?? '');
        if ($name === '' && $email === '' && $phone === '') {
            return [];
        }

        return [[
            'full_name' => $name !== '' ? $name : ($email !== '' ? $email : $phone),
            'email' => $email,
            'phone' => $phone,
            'notify_enabled' => 1,
        ]];
    }

    private function ensureSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS customer_info_requests (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        if (!$this->columnExists('customer_info_requests', 'recipient_name')) {
            $this->db->exec('ALTER TABLE customer_info_requests ADD COLUMN recipient_name VARCHAR(190) NULL AFTER recipient_email');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
