<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class BudgetRepository
{
    private PDO $db;
    private static bool $schemaEnsured = false;

    private const PLAN_STATUSES = [
        'draft' => 'Taslak',
        'ready' => 'Bütçeye hazır',
        'shared' => 'Firmaya verildi',
        'approved' => 'Onaylandı',
    ];

    private const ITEM_STATUSES = [
        'planned' => 'Planlandı',
        'approval_written' => 'Onayı yazıldı',
        'approved' => 'Onaylandı',
        'postponed' => 'Ertelendi',
        'cancelled' => 'İptal',
    ];

    public function __construct()
    {
        $this->db = Database::connection();
        $this->ensureSchema();
    }

    public static function planStatuses(): array
    {
        return self::PLAN_STATUSES;
    }

    public static function itemStatuses(): array
    {
        return self::ITEM_STATUSES;
    }

    public static function months(): array
    {
        return [
            1 => 'Ocak',
            2 => 'Şubat',
            3 => 'Mart',
            4 => 'Nisan',
            5 => 'Mayıs',
            6 => 'Haziran',
            7 => 'Temmuz',
            8 => 'Ağustos',
            9 => 'Eylül',
            10 => 'Ekim',
            11 => 'Kasım',
            12 => 'Aralık',
        ];
    }

    public function years(): array
    {
        $years = array_map('intval', $this->db->query(
            'SELECT DISTINCT budget_year FROM budget_plans WHERE deleted_at IS NULL ORDER BY budget_year DESC'
        )->fetchAll(PDO::FETCH_COLUMN));
        $nextYear = (int) date('Y') + 1;
        if (!in_array($nextYear, $years, true)) {
            array_unshift($years, $nextYear);
        }

        return array_values(array_unique($years));
    }

    public function summary(int $year = 0): array
    {
        $where = ['deleted_at IS NULL'];
        $params = [];
        if ($year > 0) {
            $where[] = 'budget_year = :year';
            $params['year'] = $year;
        }

        $stmt = $this->db->prepare(
            'SELECT currency, COUNT(*) AS plan_count, SUM(total) AS total
             FROM budget_plans
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY currency
             ORDER BY currency ASC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function list(int $year = 0, string $query = ''): array
    {
        $where = ['bp.deleted_at IS NULL'];
        $params = [];
        if ($year > 0) {
            $where[] = 'bp.budget_year = :year';
            $params['year'] = $year;
        }

        $query = trim($query);
        if ($query !== '') {
            $where[] = '(bp.customer_name LIKE :query OR bp.title LIKE :query OR bp.budget_number LIKE :query)';
            $params['query'] = '%' . $query . '%';
        }

        $stmt = $this->db->prepare(
            'SELECT bp.*,
                    COUNT(bpi.id) AS item_count,
                    MIN(bpi.planned_month) AS first_month,
                    MAX(bpi.planned_month) AS last_month
             FROM budget_plans bp
             LEFT JOIN budget_plan_items bpi ON bpi.budget_id = bp.id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY bp.id
             ORDER BY bp.budget_year DESC, bp.updated_at DESC, bp.id DESC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM budget_plans
             WHERE id = :id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $plan = $stmt->fetch();
        if (!$plan) {
            return null;
        }

        $plan['items'] = $this->items($id);

        return $plan;
    }

    public function create(array $data): int
    {
        $items = $this->submittedItems($data);
        $plan = $this->planPayload($data, $items);
        $userId = empty($data['user_id']) ? null : (int) $data['user_id'];

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO budget_plans
                    (budget_number, customer_id, customer_name, budget_year, title, currency, status, subtotal, vat_total, total, notes, created_by, updated_by)
                 VALUES
                    (:budget_number, :customer_id, :customer_name, :budget_year, :title, :currency, :status, :subtotal, :vat_total, :total, :notes, :created_by, :updated_by)'
            );
            $stmt->execute([
                'budget_number' => null,
                'customer_id' => $plan['customer_id'],
                'customer_name' => $plan['customer_name'],
                'budget_year' => $plan['budget_year'],
                'title' => $plan['title'],
                'currency' => $plan['currency'],
                'status' => $plan['status'],
                'subtotal' => $plan['subtotal'],
                'vat_total' => $plan['vat_total'],
                'total' => $plan['total'],
                'notes' => $plan['notes'],
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->assignBudgetNumber($id, (int) $plan['budget_year']);
            $this->replaceItems($id, $items);
            $this->db->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): void
    {
        if ($this->find($id) === null) {
            throw new \RuntimeException('Bütçe kaydı bulunamadı.');
        }

        $items = $this->submittedItems($data);
        $plan = $this->planPayload($data, $items);
        $userId = empty($data['user_id']) ? null : (int) $data['user_id'];

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE budget_plans
                 SET customer_id = :customer_id,
                     customer_name = :customer_name,
                     budget_year = :budget_year,
                     title = :title,
                     currency = :currency,
                     status = :status,
                     subtotal = :subtotal,
                     vat_total = :vat_total,
                     total = :total,
                     notes = :notes,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                'id' => $id,
                'customer_id' => $plan['customer_id'],
                'customer_name' => $plan['customer_name'],
                'budget_year' => $plan['budget_year'],
                'title' => $plan['title'],
                'currency' => $plan['currency'],
                'status' => $plan['status'],
                'subtotal' => $plan['subtotal'],
                'vat_total' => $plan['vat_total'],
                'total' => $plan['total'],
                'notes' => $plan['notes'],
                'updated_by' => $userId,
            ]);
            $this->replaceItems($id, $items);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function delete(int $id, ?int $userId = null): void
    {
        $this->db->prepare(
            'UPDATE budget_plans
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

    private function items(int $budgetId): array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM budget_plan_items
             WHERE budget_id = :budget_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['budget_id' => $budgetId]);

        return $stmt->fetchAll();
    }

    private function planPayload(array $data, array $items): array
    {
        $customerId = empty($data['customer_id']) ? null : (int) $data['customer_id'];
        $customerName = $this->customerName($customerId);
        if ($customerName === '') {
            $customerName = trim((string) ($data['customer_name'] ?? ''));
        }
        if ($customerName === '') {
            throw new \RuntimeException('Bütçe için firma seçin.');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = ((int) ($data['budget_year'] ?? ((int) date('Y') + 1))) . ' yılı bütçe planı';
        }

        $totals = $this->totals($items);

        return [
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'budget_year' => max(2020, min(2100, (int) ($data['budget_year'] ?? ((int) date('Y') + 1)))),
            'title' => $title,
            'currency' => self::normalizeCurrency((string) ($data['currency'] ?? 'TRY')),
            'status' => array_key_exists((string) ($data['status'] ?? ''), self::PLAN_STATUSES) ? (string) $data['status'] : 'draft',
            'subtotal' => $totals['subtotal'],
            'vat_total' => $totals['vat_total'],
            'total' => $totals['total'],
            'notes' => $this->nullableString($data['notes'] ?? ''),
        ];
    }

    private function submittedItems(array $data): array
    {
        $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $itemName = trim((string) ($row['item_name'] ?? ''));
            $hasAnyValue = $itemName !== ''
                || trim((string) ($row['description'] ?? '')) !== ''
                || trim((string) ($row['approval_note'] ?? '')) !== ''
                || trim((string) ($row['unit_price'] ?? '')) !== '';
            if (!$hasAnyValue) {
                continue;
            }
            if ($itemName === '') {
                throw new \RuntimeException('Bütçe kalemlerinde ürün / hizmet adı zorunlu.');
            }

            $quantity = max(0.01, $this->number($row['quantity'] ?? 1));
            $unitPrice = max(0.0, $this->number($row['unit_price'] ?? 0));
            $vatRate = max(0.0, min(100.0, $this->number($row['vat_rate'] ?? 20)));
            $subtotal = round($quantity * $unitPrice, 2);
            $vatTotal = round($subtotal * $vatRate / 100, 2);
            $status = (string) ($row['status'] ?? 'planned');
            $plannedMonth = (int) ($row['planned_month'] ?? 0);

            $items[] = [
                'item_name' => $itemName,
                'category' => $this->nullableString($row['category'] ?? ''),
                'description' => $this->nullableString($row['description'] ?? ''),
                'quantity' => $quantity,
                'unit' => trim((string) (($row['unit'] ?? '') ?: 'Adet')),
                'unit_price' => $unitPrice,
                'vat_rate' => $vatRate,
                'subtotal' => $subtotal,
                'vat_total' => $vatTotal,
                'total' => round($subtotal + $vatTotal, 2),
                'planned_month' => $plannedMonth >= 1 && $plannedMonth <= 12 ? $plannedMonth : null,
                'approval_note' => $this->nullableString($row['approval_note'] ?? ''),
                'status' => array_key_exists($status, self::ITEM_STATUSES) ? $status : 'planned',
            ];
        }

        if ($items === []) {
            throw new \RuntimeException('Bütçe için en az bir ürün / hizmet kalemi girin.');
        }

        return $items;
    }

    private function replaceItems(int $budgetId, array $items): void
    {
        $this->db->prepare('DELETE FROM budget_plan_items WHERE budget_id = :budget_id')->execute(['budget_id' => $budgetId]);
        $stmt = $this->db->prepare(
            'INSERT INTO budget_plan_items
                (budget_id, item_name, category, description, quantity, unit, unit_price, vat_rate, subtotal, vat_total, total, planned_month, approval_note, status, sort_order)
             VALUES
                (:budget_id, :item_name, :category, :description, :quantity, :unit, :unit_price, :vat_rate, :subtotal, :vat_total, :total, :planned_month, :approval_note, :status, :sort_order)'
        );
        foreach ($items as $index => $item) {
            $stmt->execute($item + [
                'budget_id' => $budgetId,
                'sort_order' => $index,
            ]);
        }
    }

    private function totals(array $items): array
    {
        $totals = ['subtotal' => 0.0, 'vat_total' => 0.0, 'total' => 0.0];
        foreach ($items as $item) {
            $totals['subtotal'] += (float) $item['subtotal'];
            $totals['vat_total'] += (float) $item['vat_total'];
            $totals['total'] += (float) $item['total'];
        }

        foreach ($totals as $key => $value) {
            $totals[$key] = round((float) $value, 2);
        }

        return $totals;
    }

    private function assignBudgetNumber(int $id, int $year): void
    {
        $this->db->prepare('UPDATE budget_plans SET budget_number = :budget_number WHERE id = :id')
            ->execute([
                'id' => $id,
                'budget_number' => 'BT-' . $year . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
            ]);
    }

    private function customerName(?int $customerId): string
    {
        if ($customerId === null || $customerId < 1) {
            return '';
        }

        $stmt = $this->db->prepare('SELECT company_name FROM customers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $customerId]);

        return trim((string) ($stmt->fetchColumn() ?: ''));
    }

    private static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        return in_array($currency, ['TRY', 'USD', 'EUR'], true) ? $currency : 'TRY';
    }

    private function number(mixed $value): float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0.0;
        }

        $value = str_replace(' ', '', $value);
        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS budget_plans (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS budget_plan_items (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$schemaEnsured = true;
    }
}
