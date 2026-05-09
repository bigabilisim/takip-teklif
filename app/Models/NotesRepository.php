<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class NotesRepository
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
        $stmt = $this->db->prepare(
            'INSERT INTO interaction_notes
                (customer_id, contact_name, channel, title, note, quoted_amount, currency, status, follow_up_at, created_by, updated_by)
             VALUES
                (:customer_id, :contact_name, :channel, :title, :note, :quoted_amount, :currency, :status, :follow_up_at, :created_by, :updated_by)'
        );
        $userId = empty($data['user_id']) ? null : (int) $data['user_id'];
        $stmt->execute([
            'customer_id' => empty($data['customer_id']) ? null : (int) $data['customer_id'],
            'contact_name' => $this->nullableString($data['contact_name'] ?? ''),
            'channel' => self::normalizeChannel((string) ($data['channel'] ?? 'meeting')),
            'title' => trim((string) ($data['title'] ?? 'Görüşme notu')),
            'note' => trim((string) ($data['note'] ?? '')),
            'quoted_amount' => $this->nullableAmount($data['quoted_amount'] ?? null),
            'currency' => self::normalizeCurrency((string) ($data['currency'] ?? 'TRY')),
            'status' => self::normalizeStatus((string) ($data['status'] ?? 'open')),
            'follow_up_at' => $this->normalizeFollowUp($data['follow_up_date'] ?? '', $data['follow_up_time'] ?? ''),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return $this->find((int) $this->db->lastInsertId()) ?? [];
    }

    public function update(int $id, array $data): ?array
    {
        $current = $this->find($id);
        if (!$current) {
            return null;
        }

        $status = self::normalizeStatus((string) ($data['status'] ?? $current['status'] ?? 'open'));
        $completedAt = $status === 'done'
            ? ((string) ($current['completed_at'] ?? '') !== '' ? $current['completed_at'] : date('Y-m-d H:i:s'))
            : null;

        $stmt = $this->db->prepare(
            'UPDATE interaction_notes
             SET customer_id = :customer_id,
                 contact_name = :contact_name,
                 channel = :channel,
                 title = :title,
                 note = :note,
                 quoted_amount = :quoted_amount,
                 currency = :currency,
                 status = :status,
                 follow_up_at = :follow_up_at,
                 completed_at = :completed_at,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id
               AND deleted_at IS NULL'
        );
        $stmt->execute([
            'id' => $id,
            'customer_id' => empty($data['customer_id']) ? null : (int) $data['customer_id'],
            'contact_name' => $this->nullableString($data['contact_name'] ?? ''),
            'channel' => self::normalizeChannel((string) ($data['channel'] ?? 'meeting')),
            'title' => trim((string) ($data['title'] ?? 'Görüşme notu')),
            'note' => trim((string) ($data['note'] ?? '')),
            'quoted_amount' => $this->nullableAmount($data['quoted_amount'] ?? null),
            'currency' => self::normalizeCurrency((string) ($data['currency'] ?? 'TRY')),
            'status' => $status,
            'follow_up_at' => $this->normalizeFollowUp($data['follow_up_date'] ?? '', $data['follow_up_time'] ?? ''),
            'completed_at' => $completedAt,
            'updated_by' => empty($data['user_id']) ? null : (int) $data['user_id'],
        ]);

        return $this->find($id);
    }

    public function updateStatus(int $id, string $status, ?int $userId = null): ?array
    {
        $status = self::normalizeStatus($status);
        $this->db->prepare(
            'UPDATE interaction_notes
             SET status = :status,
                 completed_at = CASE WHEN :status_done = 1 THEN COALESCE(completed_at, NOW()) ELSE NULL END,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id
               AND deleted_at IS NULL'
        )->execute([
            'id' => $id,
            'status' => $status,
            'status_done' => $status === 'done' ? 1 : 0,
            'updated_by' => $userId,
        ]);

        return $this->find($id);
    }

    public function delete(int $id, ?int $userId = null): void
    {
        $this->db->prepare(
            'UPDATE interaction_notes
             SET deleted_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id
               AND deleted_at IS NULL'
        )->execute([
            'id' => $id,
            'updated_by' => $userId,
        ]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT n.*,
                    c.company_name,
                    c.email AS customer_email,
                    c.phone AS customer_phone,
                    u.name AS created_by_name,
                    uu.name AS updated_by_name
             FROM interaction_notes n
             LEFT JOIN customers c ON c.id = n.customer_id
             LEFT JOIN users u ON u.id = n.created_by
             LEFT JOIN users uu ON uu.id = n.updated_by
             WHERE n.id = :id
               AND n.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function all(array $filters = [], int $limit = 120): array
    {
        $where = ['n.deleted_at IS NULL'];
        $params = [];
        $query = trim((string) ($filters['q'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $channel = trim((string) ($filters['channel'] ?? ''));

        if ($query !== '') {
            $where[] = '(n.title LIKE :q OR n.note LIKE :q OR n.contact_name LIKE :q OR c.company_name LIKE :q OR c.email LIKE :q OR c.phone LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }

        if ($status !== '' && $status !== 'all') {
            $where[] = 'n.status = :status';
            $params['status'] = self::normalizeStatus($status);
        }

        if ($channel !== '' && $channel !== 'all') {
            $where[] = 'n.channel = :channel';
            $params['channel'] = self::normalizeChannel($channel);
        }

        $sql = 'SELECT n.*,
                       c.company_name,
                       c.email AS customer_email,
                       c.phone AS customer_phone,
                       u.name AS created_by_name
                FROM interaction_notes n
                LEFT JOIN customers c ON c.id = n.customer_id
                LEFT JOIN users u ON u.id = n.created_by
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY
                    CASE
                        WHEN n.status <> \'done\' AND n.follow_up_at IS NOT NULL THEN 0
                        WHEN n.status = \'open\' THEN 1
                        WHEN n.status = \'waiting\' THEN 2
                        ELSE 3
                    END ASC,
                    COALESCE(n.follow_up_at, n.created_at) DESC,
                    n.id DESC
                LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', max(1, min(300, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function stats(): array
    {
        $rows = $this->db->query(
            'SELECT status, COUNT(*) AS total
             FROM interaction_notes
             WHERE deleted_at IS NULL
             GROUP BY status'
        )->fetchAll();
        $stats = ['open' => 0, 'waiting' => 0, 'done' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $stats[(string) $row['status']] = (int) $row['total'];
        }

        return $stats;
    }

    public static function channelOptions(): array
    {
        return [
            'meeting' => 'Yüz yüze görüşme',
            'phone' => 'Telefon',
            'whatsapp' => 'WhatsApp',
            'email' => 'E-posta',
            'verbal_quote' => 'Sözlü fiyat',
            'price_quote' => 'Fiyat verildi',
            'follow_up' => 'Takip',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            'open' => 'Takip edilecek',
            'waiting' => 'Cevap bekleniyor',
            'done' => 'Tamamlandı',
            'cancelled' => 'Vazgeçildi',
        ];
    }

    public static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        return in_array($currency, ['TRY', 'USD', 'EUR'], true) ? $currency : 'TRY';
    }

    public static function normalizeStatus(string $status): string
    {
        return array_key_exists($status, self::statusOptions()) ? $status : 'open';
    }

    public static function normalizeChannel(string $channel): string
    {
        return array_key_exists($channel, self::channelOptions()) ? $channel : 'meeting';
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS interaction_notes (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaEnsured = true;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableAmount(mixed $value): ?float
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return max(0, (float) $normalized);
    }

    private function normalizeFollowUp(mixed $date, mixed $time): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }

        try {
            $parsedDate = new \DateTimeImmutable($date);
            $time = trim((string) $time);
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $time, $matches) !== 1) {
                $time = '09:00';
            } else {
                $time = $matches[1] . ':' . $matches[2];
            }

            return $parsedDate->format('Y-m-d') . ' ' . $time . ':00';
        } catch (\Throwable) {
            return null;
        }
    }
}
