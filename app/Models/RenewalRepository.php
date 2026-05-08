<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class RenewalRepository
{
    private const STANDARD_REMINDER_DAYS = [30, 20, 15, 7];

    private PDO $db;
    private static bool $invoiceSchemaEnsured = false;
    private static bool $paymentSchemaEnsured = false;
    private static bool $renewalSchemaEnsured = false;
    private static bool $definitionSchemaEnsured = false;
    private static bool $paymentMethodSchemaEnsured = false;
    private static bool $itemSchemaEnsured = false;
    private static bool $decisionSchemaEnsured = false;
    private static bool $notificationDeliverySchemaEnsured = false;
    private static bool $supplierQuoteSchemaEnsured = false;
    private static bool $customerOfferSchemaEnsured = false;
    private static bool $phoneNormalizationEnsured = false;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->ensureInvoiceSchema();
        $this->ensurePaymentSchema();
        $this->ensureRenewalSchema();
        $this->ensureDefinitionSchema();
        $this->ensurePaymentMethodSchema();
        $this->ensureItemSchema();
        $this->ensureDecisionSchema();
        $this->ensureNotificationDeliverySchema();
        $this->ensureSupplierQuoteSchema();
        $this->ensureCustomerOfferSchema();
        $this->ensurePhoneNormalization();
    }

    public function stats(): array
    {
        $row = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                SUM(r.status = 'active') AS active,
                SUM(r.status = 'active' AND r.renewal_date < CURDATE()) AS overdue,
                SUM(r.status = 'active' AND r.renewal_date >= CURDATE() AND DATEDIFF(r.renewal_date, CURDATE()) <= 60) AS due_soon
            FROM renewals r
            INNER JOIN customers c ON c.id = r.customer_id AND c.deleted_at IS NULL"
        )->fetch() ?: [];

        $customerCount = $this->db->query('SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL')->fetchColumn();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
            'due_soon' => (int) ($row['due_soon'] ?? 0),
            'customers' => (int) $customerCount,
        ];
    }

    public function flowOverview(): array
    {
        $stats = $this->stats();
        $collectionRows = $this->collectionRows('all');

        $collection = [
            'awaiting' => count($collectionRows),
            'choice' => 0,
            'bank' => 0,
        ];

        foreach ($collectionRows as $row) {
            $method = mb_strtolower(trim((string) ($row['payment_method'] ?? '')));
            if (!empty($row['payment_customer_choice']) || trim((string) ($row['payment_method'] ?? '')) === '') {
                $collection['choice']++;
            }
            if (str_contains($method, 'havale') || str_contains($method, 'eft')) {
                $collection['bank']++;
            }
        }

        $supplierRequestStatus = $this->groupCounts(
            'SELECT status, COUNT(*) AS total FROM supplier_quote_requests GROUP BY status'
        );
        $customerOfferStatus = $this->groupCounts(
            'SELECT status, COUNT(*) AS total FROM customer_offer_requests GROUP BY status'
        );

        return [
            'renewal' => [
                'total' => (int) $stats['total'],
                'active' => (int) $stats['active'],
                'due_soon' => (int) $stats['due_soon'],
                'overdue' => (int) $stats['overdue'],
                'reminder_configured' => $this->countValue(
                    "SELECT COUNT(*)
                     FROM renewals r
                     INNER JOIN customers c ON c.id = r.customer_id AND c.deleted_at IS NULL
                     WHERE r.status = 'active'
                       AND (
                           r.reminder_days > 0
                           OR EXISTS (
                               SELECT 1
                               FROM renewal_reminder_rules rrr
                               WHERE rrr.renewal_id = r.id
                           )
                       )"
                ),
                'notifications_sent' => $this->countValue(
                    "SELECT COUNT(DISTINCT renewal_id)
                     FROM renewal_notification_deliveries
                     WHERE status <> 'failed'
                       AND sent_at IS NOT NULL"
                ),
                'notifications_read' => $this->countValue(
                    "SELECT COUNT(DISTINCT renewal_id)
                     FROM renewal_notification_deliveries
                     WHERE status <> 'failed'
                       AND read_at IS NOT NULL"
                ),
                'invoice_periods' => $this->countValue('SELECT COUNT(*) FROM renewal_invoice_periods'),
            ],
            'offer' => [
                'supplier_requests' => array_sum($supplierRequestStatus),
                'supplier_pending' => (int) ($supplierRequestStatus['pending'] ?? 0),
                'supplier_opened' => (int) ($supplierRequestStatus['opened'] ?? 0),
                'supplier_submitted' => (int) ($supplierRequestStatus['submitted'] ?? 0),
                'supplier_expired' => (int) ($supplierRequestStatus['expired'] ?? 0),
                'supplier_price_lines' => $this->countValue(
                    "SELECT COUNT(*)
                     FROM supplier_quote_lines
                     WHERE price_cash IS NOT NULL
                        OR price_30 IS NOT NULL
                        OR price_60 IS NOT NULL
                        OR price_check IS NOT NULL
                        OR price_custom IS NOT NULL"
                ),
                'supplier_selections' => $this->countValue('SELECT COUNT(*) FROM supplier_quote_selections'),
                'supplier_selection_sent' => $this->countValue(
                    "SELECT COUNT(*)
                     FROM supplier_quote_selection_deliveries
                     WHERE status IN ('sent', 'read')
                        OR sent_at IS NOT NULL"
                ),
                'supplier_selection_read' => $this->countValue(
                    "SELECT COUNT(*)
                     FROM supplier_quote_selection_deliveries
                     WHERE read_at IS NOT NULL"
                ),
                'renewals_waiting_selection' => $this->countValue(
                    "SELECT COUNT(DISTINCT sqr.renewal_id)
                     FROM supplier_quote_requests sqr
                     WHERE sqr.status = 'submitted'
                       AND NOT EXISTS (
                           SELECT 1
                           FROM supplier_quote_selections sqs
                           WHERE sqs.renewal_id = sqr.renewal_id
                       )"
                ),
                'renewals_waiting_customer_offer' => $this->countValue(
                    "SELECT COUNT(DISTINCT sqs.renewal_id)
                     FROM supplier_quote_selections sqs
                     WHERE NOT EXISTS (
                         SELECT 1
                         FROM customer_offer_requests cor
                         WHERE cor.renewal_id = sqs.renewal_id
                     )"
                ),
                'customer_offers' => array_sum($customerOfferStatus),
                'customer_sent' => (int) ($customerOfferStatus['sent'] ?? 0),
                'customer_opened' => (int) ($customerOfferStatus['opened'] ?? 0),
                'customer_approved' => (int) ($customerOfferStatus['approved'] ?? 0),
                'customer_revision' => (int) ($customerOfferStatus['revision_requested'] ?? 0),
                'customer_rejected' => (int) ($customerOfferStatus['rejected'] ?? 0),
            ],
            'collection' => [
                'awaiting' => $collection['awaiting'],
                'choice' => $collection['choice'],
                'bank' => $collection['bank'],
                'receipts' => $this->countValue('SELECT COUNT(*) FROM renewal_payment_receipts'),
                'paid_card' => $this->countValue("SELECT COUNT(*) FROM renewal_payments WHERE status = 'paid' AND COALESCE(payment_id, '') <> ''"),
                'card_pending' => $this->countValue("SELECT COUNT(*) FROM renewal_payments WHERE status = 'pending'"),
            ],
        ];
    }

    public function upcoming(int $limit = 8): array
    {
        $stmt = $this->db->prepare($this->baseSelect() . "
            WHERE r.status = 'active'
            ORDER BY r.renewal_date ASC
            LIMIT :limit
        ");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function dashboardOffers(int $limit = 8): array
    {
        $stmt = $this->db->prepare($this->baseSelect() . "
            WHERE r.status = 'active'
              AND (
                  r.last_notified_at IS NOT NULL
                  OR r.payment_customer_choice = 1
                  OR EXISTS (
                      SELECT 1
                      FROM renewal_decisions rd_offer
                      WHERE rd_offer.renewal_id = r.id
                  )
                  OR DATEDIFF(r.renewal_date, CURDATE()) <= 60
              )
            ORDER BY COALESCE(
                (
                    SELECT MAX(rd_order.created_at)
                    FROM renewal_decisions rd_order
                    WHERE rd_order.renewal_id = r.id
                ),
                r.last_notified_at,
                r.renewal_date
            ) DESC,
            r.renewal_date ASC
            LIMIT :limit
        ");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function collectionRows(string $filter = 'all'): array
    {
        $method = "LOWER(COALESCE(r.payment_method, ''))";
        $where = [
            "r.status = 'active'",
            "COALESCE(ri_stats.item_total, r.amount, 0) > 0",
            "NOT EXISTS (
                SELECT 1
                FROM renewal_payments rp_paid_filter
                WHERE rp_paid_filter.renewal_id = r.id
                  AND rp_paid_filter.status = 'paid'
                  AND COALESCE(rp_paid_filter.payment_id, '') <> ''
            )",
            "NOT EXISTS (
                SELECT 1
                FROM renewal_decisions rd_legacy_filter
                WHERE rd_legacy_filter.renewal_id = r.id
                  AND rd_legacy_filter.decision = 'approved'
                  AND rd_legacy_filter.note = 'Eski tarihli giriş olarak kaydedildi.'
            )",
            "(
                r.payment_customer_choice = 1
                OR COALESCE(r.payment_method, '') <> ''
                OR EXISTS (
                    SELECT 1
                    FROM customer_offer_requests cor_collection
                    WHERE cor_collection.renewal_id = r.id
                      AND cor_collection.status = 'approved'
                )
            )",
        ];

        if ($filter === 'bank') {
            $where[] = "({$method} LIKE '%havale%' OR {$method} LIKE '%eft%')";
        } elseif ($filter === 'choice') {
            $where[] = "(r.payment_customer_choice = 1 OR COALESCE(r.payment_method, '') = '')";
        }

        $stmt = $this->db->prepare($this->baseSelect() . '
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY
                CASE
                    WHEN r.payment_customer_choice = 1 OR COALESCE(r.payment_method, \'\') = \'\' THEN 0
                    WHEN ' . $method . ' LIKE \'%havale%\' OR ' . $method . ' LIKE \'%eft%\' THEN 1
                    ELSE 2
                END ASC,
                r.payment_selected_at ASC,
                r.renewal_date ASC,
                r.id DESC
        ');
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function dueForReminder(): array
    {
        $stmt = $this->db->query($this->baseSelect() . "
            WHERE r.status = 'active'
              AND (
                  DATEDIFF(r.renewal_date, CURDATE()) <= 7
                  OR EXISTS (
                      SELECT 1
                      FROM renewal_reminder_rules rrr
                      WHERE rrr.renewal_id = r.id
                        AND rrr.days_before > 7
                        AND DATEDIFF(r.renewal_date, CURDATE()) = rrr.days_before
                  )
                  OR (
                      NOT EXISTS (
                          SELECT 1
                          FROM renewal_reminder_rules rrf
                          WHERE rrf.renewal_id = r.id
                      )
                      AND DATEDIFF(r.renewal_date, CURDATE()) = r.reminder_days
                  )
              )
              AND (r.last_notified_at IS NULL OR DATE(r.last_notified_at) < CURDATE())
              AND NOT EXISTS (
                  SELECT 1
                  FROM renewal_notification_reads rnr
                  WHERE rnr.renewal_id = r.id
                    AND rnr.read_on = CURDATE()
              )
            ORDER BY r.renewal_date ASC
        ");

        return $stmt->fetchAll();
    }

    public function dueForSupplierPriceRequests(): array
    {
        $stmt = $this->db->query($this->baseSelect() . "
            WHERE r.status = 'active'
              AND (r.kind = 'product' OR EXISTS (SELECT 1 FROM renewal_items ri_price WHERE ri_price.renewal_id = r.id AND ri_price.kind = 'product'))
              AND r.supplier_price_request_enabled = 1
              AND r.supplier_price_request_days IS NOT NULL
              AND DATEDIFF(r.renewal_date, CURDATE()) <= r.supplier_price_request_days
              AND r.supplier_price_requested_at IS NULL
              AND (r.supplier_id IS NOT NULL OR r.supplier_group_id IS NOT NULL)
            ORDER BY r.renewal_date ASC
        ");

        return $stmt->fetchAll();
    }

    public function all(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (($filters['state'] ?? '') === 'overdue') {
            $where[] = "r.status = 'active' AND r.renewal_date < CURDATE()";
        } elseif (($filters['state'] ?? '') === 'due_soon') {
            $where[] = "r.status = 'active' AND r.renewal_date >= CURDATE() AND DATEDIFF(r.renewal_date, CURDATE()) <= 60";
        } elseif (($filters['state'] ?? '') !== '') {
            $where[] = 'r.status = :status';
            $params['status'] = $filters['state'];
        }

        if (($filters['q'] ?? '') !== '') {
            $where[] = "(r.title LIKE :q OR c.company_name LIKE :q OR c.contact_name LIKE :q OR c.email LIKE :q OR r.supplier LIKE :q OR s.company_name LIKE :q OR sg.name LIKE :q OR EXISTS (
                SELECT 1
                FROM renewal_items ri_search
                WHERE ri_search.renewal_id = r.id
                  AND (ri_search.title LIKE :q OR ri_search.brand LIKE :q OR ri_search.license_key LIKE :q)
            ))";
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if (($filters['kind'] ?? '') !== '') {
            $where[] = '(r.kind = :kind OR EXISTS (SELECT 1 FROM renewal_items ri_kind WHERE ri_kind.renewal_id = r.id AND ri_kind.kind = :kind))';
            $params['kind'] = $filters['kind'];
        }

        $sql = $this->baseSelect();
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY r.renewal_date ASC, r.id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare($this->baseSelect() . ' WHERE r.id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(array $data): int
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO renewals
                    (customer_id, supplier_id, supplier_group_id, definition_id, renewal_period_id, title, brand, kind, supplier, license_key, payment_method, payment_customer_choice, start_date, renewal_date, reminder_days, supplier_price_request_enabled, supplier_price_request_days, supplier_share_customer_info, amount, currency, status, notes)
                 VALUES
                    (:customer_id, :supplier_id, :supplier_group_id, :definition_id, :renewal_period_id, :title, :brand, :kind, :supplier, :license_key, :payment_method, :payment_customer_choice, :start_date, :renewal_date, :reminder_days, :supplier_price_request_enabled, :supplier_price_request_days, :supplier_share_customer_info, :amount, :currency, :status, :notes)'
            );
            $stmt->execute($this->normalize($data));
            $id = (int) $this->db->lastInsertId();
            $this->replaceItems($id, $data);
            $this->replaceReminderRules($id, $data);
            $this->saveCurrentInvoicePeriodIfProvided($id, $data);
            $this->db->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): void
    {
        $this->db->beginTransaction();

        try {
            $params = $this->normalize($data);
            $params['id'] = $id;

            $stmt = $this->db->prepare(
                'UPDATE renewals SET
                    customer_id = :customer_id,
                    supplier_id = :supplier_id,
                    supplier_group_id = :supplier_group_id,
                    definition_id = :definition_id,
                    renewal_period_id = :renewal_period_id,
                    title = :title,
                    brand = :brand,
                    kind = :kind,
                    supplier = :supplier,
                    license_key = :license_key,
                    payment_method = :payment_method,
                    payment_customer_choice = :payment_customer_choice,
                    start_date = :start_date,
                    renewal_date = :renewal_date,
                    reminder_days = :reminder_days,
                    supplier_price_request_enabled = :supplier_price_request_enabled,
                    supplier_price_request_days = :supplier_price_request_days,
                    supplier_share_customer_info = :supplier_share_customer_info,
                    amount = :amount,
                    currency = :currency,
                    status = :status,
                    notes = :notes,
                    updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute($params);
            $this->replaceItems($id, $data);
            $this->replaceReminderRules($id, $data);
            $this->saveCurrentInvoicePeriodIfProvided($id, $data);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function markRenewed(int $id, string $invoiceNumber, ?string $nextDate = null): void
    {
        $invoiceNumber = trim($invoiceNumber);
        if ($invoiceNumber === '') {
            throw new \RuntimeException('Yeni dönem fatura numarası zorunlu.');
        }

        [$periodStartDate, $periodEndDate] = $this->nextRenewalPeriodFromCurrent($id, $nextDate);

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                "UPDATE renewals
                 SET status = 'active',
                     start_date = :period_start_date,
                     renewal_date = :period_end_date,
                     payment_method = NULL,
                     payment_customer_choice = 1,
                     payment_selected_email = NULL,
                     payment_selected_at = NULL,
                     renewed_at = NOW(),
                     last_notified_at = NULL,
                     supplier_price_requested_at = NULL,
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([
                'id' => $id,
                'period_start_date' => $periodStartDate,
                'period_end_date' => $periodEndDate,
            ]);
            $this->db->prepare('DELETE FROM renewal_notification_reads WHERE renewal_id = :id')
                ->execute(['id' => $id]);
            $this->saveInvoicePeriod($id, $periodStartDate, $periodEndDate, $invoiceNumber);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM renewals WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function customers(): array
    {
        return $this->db->query('SELECT * FROM customers WHERE deleted_at IS NULL ORDER BY company_name ASC')->fetchAll();
    }

    public function deletedCustomersWithContacts(): array
    {
        return $this->customersWithContacts(true);
    }

    public function customersWithContacts(bool $deleted = false): array
    {
        $customers = $deleted
            ? $this->db->query('SELECT * FROM customers WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC, company_name ASC')->fetchAll()
            : $this->customers();

        if ($customers === []) {
            return [];
        }

        return $this->attachContacts($customers);
    }

    public function findCustomer(int $id, bool $includeDeleted = false): ?array
    {
        $sql = 'SELECT * FROM customers WHERE id = :id';
        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $customer = $stmt->fetch();

        if (!$customer) {
            return null;
        }

        return $this->attachContacts([$customer])[0];
    }

    public function updateCustomer(int $id, array $data): void
    {
        $this->db->beginTransaction();

        try {
            $this->saveCustomer($data, $id);
            $this->replaceCustomerContacts($id, $data);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteCustomer(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE customers SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);
    }

    public function restoreCustomer(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE customers SET deleted_at = NULL, updated_at = NOW() WHERE id = :id AND deleted_at IS NOT NULL');
        $stmt->execute(['id' => $id]);
    }

    public function customerRenewalCount(int $customerId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM renewals WHERE customer_id = :customer_id');
        $stmt->execute(['customer_id' => $customerId]);

        return (int) $stmt->fetchColumn();
    }

    public function suppliers(): array
    {
        return $this->db->query($this->supplierSelect() . ' WHERE s.deleted_at IS NULL ORDER BY sg.name ASC, s.company_name ASC')->fetchAll();
    }

    public function supplierGroups(bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM supplier_groups';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';

        return $this->db->query($sql)->fetchAll();
    }

    public function renewalDefinitions(bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM renewal_definitions';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY kind ASC, name ASC';

        return $this->db->query($sql)->fetchAll();
    }

    public function renewalPeriods(bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM renewal_periods';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= " ORDER BY FIELD(interval_unit, 'day', 'month', 'year'), interval_count ASC";

        return $this->db->query($sql)->fetchAll();
    }

    public function paymentMethods(bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM payment_methods';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';

        return array_values(array_filter(
            $this->db->query($sql)->fetchAll(),
            static fn (array $method): bool => !self::isRemovedPaymentMethodName((string) ($method['name'] ?? ''))
        ));
    }

    public function findPaymentMethodByName(string $name): ?array
    {
        if (self::isRemovedPaymentMethodName($name)) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM payment_methods WHERE name = :name AND is_active = 1 LIMIT 1');
        $stmt->execute(['name' => trim($name)]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function invoicePeriods(int $renewalId): array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM renewal_invoice_periods
             WHERE renewal_id = :renewal_id
             ORDER BY period_start_date DESC, id DESC'
        );
        $stmt->execute(['renewal_id' => $renewalId]);

        return $stmt->fetchAll();
    }

    public function renewalItems(int $renewalId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ri.*, rd.name AS definition_name, rd.notification_info AS definition_notification_info
             FROM renewal_items ri
             LEFT JOIN renewal_definitions rd ON rd.id = ri.definition_id
             WHERE ri.renewal_id = :renewal_id
             ORDER BY ri.sort_order ASC, ri.id ASC'
        );
        $stmt->execute(['renewal_id' => $renewalId]);

        return $stmt->fetchAll();
    }

    public function createSupplierQuoteRequest(int $renewalId, array $recipient, string $subject, string $message, string $source = 'mail'): array
    {
        $token = bin2hex(random_bytes(32));
        $source = in_array($source, ['mail', 'whatsapp', 'manual'], true) ? $source : 'manual';

        $stmt = $this->db->prepare(
            'INSERT INTO supplier_quote_requests
                (renewal_id, supplier_id, supplier_contact_id, supplier_name, contact_name, recipient_email, recipient_phone, token_hash, source, status, message_subject, message_body, expires_at)
             VALUES
                (:renewal_id, :supplier_id, :supplier_contact_id, :supplier_name, :contact_name, :recipient_email, :recipient_phone, :token_hash, :source, :status, :message_subject, :message_body, DATE_ADD(NOW(), INTERVAL 14 DAY))'
        );
        $stmt->execute([
            'renewal_id' => $renewalId,
            'supplier_id' => empty($recipient['supplier_id']) ? null : (int) $recipient['supplier_id'],
            'supplier_contact_id' => empty($recipient['contact_id']) ? null : (int) $recipient['contact_id'],
            'supplier_name' => $this->nullableString($recipient['supplier_name'] ?? ''),
            'contact_name' => $this->nullableString($recipient['name'] ?? ''),
            'recipient_email' => $this->nullableString($recipient['email'] ?? ''),
            'recipient_phone' => $this->nullableString($recipient['phone'] ?? ''),
            'token_hash' => hash('sha256', $token),
            'source' => $source,
            'status' => 'pending',
            'message_subject' => mb_substr(trim($subject), 0, 240),
            'message_body' => trim($message),
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'token' => $token,
            'url' => \url('/tedarikci-teklif/' . $token),
            'unsubscribe_url' => \url('/tedarikci-listeden-cik/' . $token),
        ];
    }

    public function findSupplierQuoteRequestByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT sqr.*,
                    r.customer_id,
                    r.title,
                    r.brand,
                    r.kind,
                    r.license_key,
                    r.currency,
                    r.renewal_date,
                    r.start_date,
                    r.renewal_period_id,
                    r.supplier_share_customer_info,
                    COALESCE(s.supplier_group_id, r.supplier_group_id) AS supplier_group_id,
                    rp.name AS renewal_period_name,
                    c.company_name,
                    sg.name AS supplier_group_name,
                    COALESCE(sqr.supplier_name, s.company_name) AS supplier_display
             FROM supplier_quote_requests sqr
             INNER JOIN renewals r ON r.id = sqr.renewal_id
             INNER JOIN customers c ON c.id = r.customer_id
             LEFT JOIN renewal_periods rp ON rp.id = r.renewal_period_id
             LEFT JOIN suppliers s ON s.id = sqr.supplier_id
             LEFT JOIN supplier_groups sg ON sg.id = COALESCE(s.supplier_group_id, r.supplier_group_id)
             WHERE sqr.token_hash = :token_hash
             LIMIT 1"
        );
        $stmt->execute(['token_hash' => hash('sha256', strtolower($token))]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function supplierUnsubscribeContext(string $token): ?array
    {
        return $this->findSupplierQuoteRequestByToken($token);
    }

    public function recordSupplierUnsubscribe(string $token, string $scope, string $ipAddress = '', string $userAgent = ''): array
    {
        $request = $this->findSupplierQuoteRequestByToken($token);
        if (!$request) {
            throw new \RuntimeException('Liste çıkış bağlantısı geçersiz.');
        }

        $scope = $scope === 'all' ? 'all' : 'group';
        $email = trim(mb_strtolower((string) ($request['recipient_email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Bu talepte geçerli tedarikçi e-posta adresi bulunamadı.');
        }

        $supplierGroupId = empty($request['supplier_group_id']) ? null : (int) $request['supplier_group_id'];
        if ($scope === 'group' && $supplierGroupId === null) {
            throw new \RuntimeException('Bu talep için tedarikçi kategorisi bulunamadı.');
        }

        $select = $this->db->prepare(
            'SELECT id
             FROM supplier_unsubscriptions
             WHERE recipient_email = :recipient_email
               AND scope = :scope
               AND (supplier_group_id <=> :supplier_group_id)
             LIMIT 1'
        );
        $select->execute([
            'recipient_email' => $email,
            'scope' => $scope,
            'supplier_group_id' => $scope === 'all' ? null : $supplierGroupId,
        ]);
        $existingId = (int) $select->fetchColumn();

        $payload = [
            'supplier_id' => empty($request['supplier_id']) ? null : (int) $request['supplier_id'],
            'supplier_contact_id' => empty($request['supplier_contact_id']) ? null : (int) $request['supplier_contact_id'],
            'supplier_group_id' => $scope === 'all' ? null : $supplierGroupId,
            'recipient_email' => $email,
            'scope' => $scope,
            'source_request_id' => (int) $request['id'],
            'ip_address' => $this->nullableString($ipAddress),
            'user_agent' => $this->nullableString(mb_substr($userAgent, 0, 255)),
        ];

        if ($existingId > 0) {
            $stmt = $this->db->prepare(
                'UPDATE supplier_unsubscriptions
                 SET supplier_id = :supplier_id,
                     supplier_contact_id = :supplier_contact_id,
                     source_request_id = :source_request_id,
                     ip_address = :ip_address,
                     user_agent = :user_agent,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $payload = [
                'supplier_id' => $payload['supplier_id'],
                'supplier_contact_id' => $payload['supplier_contact_id'],
                'source_request_id' => $payload['source_request_id'],
                'ip_address' => $payload['ip_address'],
                'user_agent' => $payload['user_agent'],
                'id' => $existingId,
            ];
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO supplier_unsubscriptions
                    (supplier_id, supplier_contact_id, supplier_group_id, recipient_email, scope, source_request_id, ip_address, user_agent)
                 VALUES
                    (:supplier_id, :supplier_contact_id, :supplier_group_id, :recipient_email, :scope, :source_request_id, :ip_address, :user_agent)'
            );
        }

        $stmt->execute($payload);

        return array_merge($request, [
            'unsubscribed_scope' => $scope,
            'unsubscribed_email' => $email,
        ]);
    }

    public function isSupplierEmailUnsubscribed(string $email, ?int $supplierGroupId = null): bool
    {
        $email = trim(mb_strtolower($email));
        if ($email === '') {
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM supplier_unsubscriptions
             WHERE recipient_email = :recipient_email
               AND (
                    scope = 'all'
                    OR (scope = 'group' AND supplier_group_id <=> :supplier_group_id)
               )"
        );
        $stmt->execute([
            'recipient_email' => $email,
            'supplier_group_id' => $supplierGroupId,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function markSupplierQuoteRequestOpened(int $requestId, string $ipAddress = '', string $userAgent = ''): void
    {
        $stmt = $this->db->prepare(
            "UPDATE supplier_quote_requests
             SET status = CASE WHEN status = 'pending' THEN 'opened' ELSE status END,
                 opened_at = COALESCE(opened_at, NOW()),
                 submitted_ip = COALESCE(submitted_ip, :submitted_ip),
                 submitted_user_agent = COALESCE(submitted_user_agent, :submitted_user_agent),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $requestId,
            'submitted_ip' => $this->nullableString(substr($ipAddress, 0, 45)),
            'submitted_user_agent' => $this->nullableString(substr($userAgent, 0, 255)),
        ]);
    }

    public function submitSupplierQuote(int $requestId, array $data, ?array $attachment = null, string $ipAddress = '', string $userAgent = ''): void
    {
        $this->db->beginTransaction();

        try {
            $this->db->prepare('DELETE FROM supplier_quote_lines WHERE request_id = :request_id')
                ->execute(['request_id' => $requestId]);

            $insert = $this->db->prepare(
                'INSERT INTO supplier_quote_lines
                    (request_id, renewal_item_id, item_title, currency, price_cash, price_30, price_60, price_check, price_custom, custom_term, vat_included, delivery_note, note)
                 VALUES
                    (:request_id, :renewal_item_id, :item_title, :currency, :price_cash, :price_30, :price_60, :price_check, :price_custom, :custom_term, :vat_included, :delivery_note, :note)'
            );

            $lines = $data['lines'] ?? [];
            if (is_array($lines)) {
                foreach ($lines as $itemId => $line) {
                    if (!is_array($line)) {
                        continue;
                    }

                    $itemTitle = trim((string) ($line['item_title'] ?? ''));
                    $prices = [
                        'price_cash' => $this->nullableDecimalValue($line['price_cash'] ?? null),
                        'price_30' => $this->nullableDecimalValue($line['price_30'] ?? null),
                        'price_60' => $this->nullableDecimalValue($line['price_60'] ?? null),
                        'price_check' => $this->nullableDecimalValue($line['price_check'] ?? null),
                        'price_custom' => $this->nullableDecimalValue($line['price_custom'] ?? null),
                    ];

                    if ($itemTitle === '' && count(array_filter($prices, static fn ($price): bool => $price !== null)) < 1) {
                        continue;
                    }

                    $insert->execute([
                        'request_id' => $requestId,
                        'renewal_item_id' => (int) $itemId > 0 ? (int) $itemId : null,
                        'item_title' => $itemTitle !== '' ? $itemTitle : 'Ürün / hizmet',
                        'currency' => self::normalizeCurrency($line['currency'] ?? ($data['currency'] ?? 'TRY')),
                        'price_cash' => $prices['price_cash'],
                        'price_30' => $prices['price_30'],
                        'price_60' => $prices['price_60'],
                        'price_check' => $prices['price_check'],
                        'price_custom' => $prices['price_custom'],
                        'custom_term' => $this->nullableString($line['custom_term'] ?? ''),
                        'vat_included' => !empty($line['vat_included']) ? 1 : 0,
                        'delivery_note' => $this->nullableString($line['delivery_note'] ?? ''),
                        'note' => $this->nullableString($line['note'] ?? ''),
                    ]);
                }
            }

            if ($attachment !== null) {
                $stmt = $this->db->prepare(
                    'INSERT INTO supplier_quote_attachments
                        (request_id, original_name, stored_path, mime_type, file_size)
                     VALUES
                        (:request_id, :original_name, :stored_path, :mime_type, :file_size)'
                );
                $stmt->execute([
                    'request_id' => $requestId,
                    'original_name' => trim((string) ($attachment['original_name'] ?? '')),
                    'stored_path' => trim((string) ($attachment['stored_path'] ?? '')),
                    'mime_type' => $this->nullableString($attachment['mime_type'] ?? ''),
                    'file_size' => (int) ($attachment['file_size'] ?? 0),
                ]);
            }

            $stmt = $this->db->prepare(
                "UPDATE supplier_quote_requests
                 SET status = 'submitted',
                     submitted_at = NOW(),
                     submitted_ip = :submitted_ip,
                     submitted_user_agent = :submitted_user_agent,
                     quote_note = :quote_note,
                     terms_acknowledged = 1,
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([
                'id' => $requestId,
                'submitted_ip' => $this->nullableString(substr($ipAddress, 0, 45)),
                'submitted_user_agent' => $this->nullableString(substr($userAgent, 0, 255)),
                'quote_note' => $this->nullableString($data['quote_note'] ?? ''),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteSupplierQuoteRequest(int $requestId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT sqr.*, COALESCE(sqr.supplier_name, s.company_name) AS supplier_display
             FROM supplier_quote_requests sqr
             LEFT JOIN suppliers s ON s.id = sqr.supplier_id
             WHERE sqr.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $requestId]);
        $request = $stmt->fetch();
        if (!$request) {
            return null;
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'DELETE sqs
                 FROM supplier_quote_selections sqs
                 INNER JOIN supplier_quote_lines sqln ON sqln.id = sqs.quote_line_id
                 WHERE sqln.request_id = :request_id'
            )->execute(['request_id' => $requestId]);

            $this->db->prepare('DELETE FROM supplier_quote_requests WHERE id = :id')
                ->execute(['id' => $requestId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $request;
    }

    public function closeOpenSupplierQuoteRequestsIfThresholdReached(int $renewalId, int $threshold = 3): array
    {
        $threshold = max(1, $threshold);
        $countStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM supplier_quote_requests
             WHERE renewal_id = :renewal_id
               AND status = 'submitted'"
        );
        $countStmt->execute(['renewal_id' => $renewalId]);
        $submittedCount = (int) $countStmt->fetchColumn();
        if ($submittedCount < $threshold) {
            return ['submitted_count' => $submittedCount, 'closed' => []];
        }

        $stmt = $this->db->prepare(
            "SELECT sqr.*,
                    COALESCE(sqr.supplier_name, s.company_name, CASE WHEN sqs.quote_line_id IS NULL THEN \'Manuel fiyat\' ELSE NULL END) AS supplier_display,
                    r.title,
                    r.renewal_date,
                    r.supplier_share_customer_info,
                    c.company_name
             FROM supplier_quote_requests sqr
             INNER JOIN renewals r ON r.id = sqr.renewal_id
             INNER JOIN customers c ON c.id = r.customer_id
             LEFT JOIN suppliers s ON s.id = sqr.supplier_id
             WHERE sqr.renewal_id = :renewal_id
               AND sqr.status IN ('pending', 'opened')
             ORDER BY sqr.created_at ASC, sqr.id ASC"
        );
        $stmt->execute(['renewal_id' => $renewalId]);
        $openRequests = $stmt->fetchAll();
        if ($openRequests === []) {
            return ['submitted_count' => $submittedCount, 'closed' => []];
        }

        $this->db->prepare(
            "UPDATE supplier_quote_requests
             SET status = 'expired',
                 expires_at = NOW(),
                 updated_at = NOW()
             WHERE renewal_id = :renewal_id
               AND status IN ('pending', 'opened')"
        )->execute(['renewal_id' => $renewalId]);

        return ['submitted_count' => $submittedCount, 'closed' => $openRequests];
    }

    public function supplierQuotesForRenewal(int $renewalId): array
    {
        $requestStmt = $this->db->prepare(
            'SELECT sqr.*,
                    COALESCE(sqr.supplier_name, s.company_name) AS supplier_display
             FROM supplier_quote_requests sqr
             LEFT JOIN suppliers s ON s.id = sqr.supplier_id
             WHERE sqr.renewal_id = :renewal_id
             ORDER BY sqr.created_at DESC, sqr.id DESC'
        );
        $requestStmt->execute(['renewal_id' => $renewalId]);
        $requests = $requestStmt->fetchAll();

        $lineStmt = $this->db->prepare(
            'SELECT sqln.*,
                    sqr.supplier_id,
                    COALESCE(sqr.supplier_name, s.company_name) AS supplier_display,
                    sqr.contact_name,
                    sqr.recipient_email,
                    sqr.status AS request_status,
                    sqr.submitted_at
             FROM supplier_quote_lines sqln
             INNER JOIN supplier_quote_requests sqr ON sqr.id = sqln.request_id
             LEFT JOIN suppliers s ON s.id = sqr.supplier_id
             WHERE sqr.renewal_id = :renewal_id
             ORDER BY sqln.renewal_item_id ASC, sqln.id ASC'
        );
        $lineStmt->execute(['renewal_id' => $renewalId]);
        $lines = $lineStmt->fetchAll();

        $attachmentStmt = $this->db->prepare(
            'SELECT sqa.*, sqr.supplier_id, COALESCE(sqr.supplier_name, s.company_name) AS supplier_display
             FROM supplier_quote_attachments sqa
             INNER JOIN supplier_quote_requests sqr ON sqr.id = sqa.request_id
             LEFT JOIN suppliers s ON s.id = sqr.supplier_id
             WHERE sqr.renewal_id = :renewal_id
             ORDER BY sqa.created_at DESC, sqa.id DESC'
        );
        $attachmentStmt->execute(['renewal_id' => $renewalId]);
        $attachments = $attachmentStmt->fetchAll();

        $selectionStmt = $this->db->prepare(
            'SELECT sqs.*,
                    sqln.item_title,
                    COALESCE(sqr.supplier_name, s.company_name) AS supplier_display,
                    u.name AS selected_by_name,
                    (
                        SELECT COUNT(*)
                        FROM supplier_quote_selection_deliveries sqsd_count
                        WHERE sqsd_count.selection_id = sqs.id
                          AND sqsd_count.status IN (\'sent\', \'read\')
                    ) AS selection_delivery_count,
                    (
                        SELECT COUNT(*)
                        FROM supplier_quote_selection_deliveries sqsd_read_count
                        WHERE sqsd_read_count.selection_id = sqs.id
                          AND sqsd_read_count.read_at IS NOT NULL
                    ) AS selection_read_count,
                    (
                        SELECT MAX(sqsd_sent.sent_at)
                        FROM supplier_quote_selection_deliveries sqsd_sent
                        WHERE sqsd_sent.selection_id = sqs.id
                          AND sqsd_sent.status IN (\'sent\', \'read\')
                    ) AS selection_sent_at,
                    (
                        SELECT MAX(sqsd_read.read_at)
                        FROM supplier_quote_selection_deliveries sqsd_read
                        WHERE sqsd_read.selection_id = sqs.id
                          AND sqsd_read.read_at IS NOT NULL
                    ) AS selection_read_at,
                    (
                        SELECT COALESCE(NULLIF(sqsd_reader.recipient_name, \'\'), sqsd_reader.recipient_email)
                        FROM supplier_quote_selection_deliveries sqsd_reader
                        WHERE sqsd_reader.selection_id = sqs.id
                          AND sqsd_reader.read_at IS NOT NULL
                        ORDER BY sqsd_reader.read_at DESC, sqsd_reader.id DESC
                        LIMIT 1
                    ) AS selection_reader
             FROM supplier_quote_selections sqs
             LEFT JOIN supplier_quote_lines sqln ON sqln.id = sqs.quote_line_id
             LEFT JOIN supplier_quote_requests sqr ON sqr.id = sqln.request_id
             LEFT JOIN suppliers s ON s.id = sqr.supplier_id
             LEFT JOIN users u ON u.id = sqs.selected_by
             WHERE sqs.renewal_id = :renewal_id
             ORDER BY sqs.selected_at DESC, sqs.id DESC'
        );
        $selectionStmt->execute(['renewal_id' => $renewalId]);
        $selections = $selectionStmt->fetchAll();

        return [
            'requests' => $requests,
            'lines' => $lines,
            'attachments' => $attachments,
            'selections' => $selections,
        ];
    }

    public function selectSupplierQuoteLine(int $lineId, string $term, int $userId): array
    {
        $term = in_array($term, ['cash', '30', '60', 'check', 'custom'], true) ? $term : '';
        if ($term === '') {
            throw new \RuntimeException('Geçersiz teklif vadesi.');
        }

        $stmt = $this->db->prepare(
            'SELECT sqln.*,
                    sqr.renewal_id,
                    sqr.recipient_email,
                    sqr.contact_name,
                    COALESCE(sqr.supplier_name, s.company_name) AS supplier_display
             FROM supplier_quote_lines sqln
             INNER JOIN supplier_quote_requests sqr ON sqr.id = sqln.request_id
             LEFT JOIN suppliers s ON s.id = sqr.supplier_id
             WHERE sqln.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $lineId]);
        $line = $stmt->fetch();
        if (!$line) {
            throw new \RuntimeException('Teklif satırı bulunamadı.');
        }

        $price = $this->supplierQuoteTermPrice($line, $term);
        if ($price === null) {
            throw new \RuntimeException('Seçilen vadede fiyat yok.');
        }

        $renewalItemId = (int) ($line['renewal_item_id'] ?? 0);
        if ($renewalItemId < 1) {
            throw new \RuntimeException('Teklif satırı bir ürün kalemine bağlı değil.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO supplier_quote_selections
                (renewal_id, renewal_item_id, quote_line_id, selected_term, selected_price, currency, selected_by, selected_at)
             VALUES
                (:renewal_id, :renewal_item_id, :quote_line_id, :selected_term, :selected_price, :currency, :selected_by, NOW())
             ON DUPLICATE KEY UPDATE
                quote_line_id = VALUES(quote_line_id),
                selected_term = VALUES(selected_term),
                selected_price = VALUES(selected_price),
                currency = VALUES(currency),
                selected_by = VALUES(selected_by),
                selected_at = NOW()'
        );
        $stmt->execute([
            'renewal_id' => (int) $line['renewal_id'],
            'renewal_item_id' => $renewalItemId,
            'quote_line_id' => $lineId,
            'selected_term' => $term,
            'selected_price' => $price,
            'currency' => (string) ($line['currency'] ?? 'TRY'),
            'selected_by' => $userId > 0 ? $userId : null,
        ]);
        $selectionId = $this->findSupplierQuoteSelectionId((int) $line['renewal_id'], $renewalItemId, $lineId);

        return [
            'selection_id' => $selectionId,
            'renewal_id' => (int) $line['renewal_id'],
            'renewal_item_id' => $renewalItemId,
            'quote_line_id' => $lineId,
            'item_title' => (string) ($line['item_title'] ?? ''),
            'price' => $price,
            'currency' => (string) ($line['currency'] ?? 'TRY'),
            'term' => $term,
            'custom_term' => (string) ($line['custom_term'] ?? ''),
            'vat_included' => (int) ($line['vat_included'] ?? 0),
            'delivery_note' => (string) ($line['delivery_note'] ?? ''),
            'note' => (string) ($line['note'] ?? ''),
            'supplier_display' => (string) ($line['supplier_display'] ?? ''),
            'contact_name' => (string) ($line['contact_name'] ?? ''),
            'recipient_email' => (string) ($line['recipient_email'] ?? ''),
        ];
    }

    public function selectManualCustomerPrices(int $renewalId, mixed $rows, int $userId, string $currency = 'TRY'): array
    {
        if (!is_array($rows)) {
            throw new \RuntimeException('Manuel fiyat satırı bulunamadı.');
        }

        $items = [];
        foreach ($this->renewalItems($renewalId) as $item) {
            $items[(int) $item['id']] = $item;
        }
        if ($items === []) {
            throw new \RuntimeException('Fiyat girilecek ürün / hizmet kalemi bulunamadı.');
        }

        $currency = self::normalizeCurrency($currency);
        $stmt = $this->db->prepare(
            'INSERT INTO supplier_quote_selections
                (renewal_id, renewal_item_id, quote_line_id, selected_term, selected_price, currency, selected_by, selected_at)
             VALUES
                (:renewal_id, :renewal_item_id, NULL, \'manual\', :selected_price, :currency, :selected_by, NOW())
             ON DUPLICATE KEY UPDATE
                quote_line_id = NULL,
                selected_term = VALUES(selected_term),
                selected_price = VALUES(selected_price),
                currency = VALUES(currency),
                selected_by = VALUES(selected_by),
                selected_at = NOW()'
        );

        $saved = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $itemId = (int) ($row['renewal_item_id'] ?? 0);
            if ($itemId < 1 || !isset($items[$itemId])) {
                continue;
            }

            $price = $this->nullableDecimalValue($row['unit_price'] ?? null);
            if ($price === null || $price <= 0) {
                continue;
            }

            $stmt->execute([
                'renewal_id' => $renewalId,
                'renewal_item_id' => $itemId,
                'selected_price' => round($price, 2),
                'currency' => $currency,
                'selected_by' => $userId > 0 ? $userId : null,
            ]);
            $saved++;
        }

        if ($saved < 1) {
            throw new \RuntimeException('En az bir kalem için geçerli manuel fiyat yazın.');
        }

        return ['count' => $saved, 'currency' => $currency];
    }

    public function selectedSupplierQuotesForRenewal(int $renewalId): array
    {
        $stmt = $this->db->prepare(
            'SELECT sqs.id AS selection_id,
                    sqs.renewal_id,
                    sqs.renewal_item_id,
                    sqs.quote_line_id,
                    sqs.selected_term,
                    sqs.selected_price,
                    sqs.currency AS selected_currency,
                    sqs.selected_at,
                    ri.title AS item_title,
                    ri.quantity,
                    ri.vat_rate,
                    sqln.custom_term,
                    COALESCE(sqln.vat_included, 1) AS vat_included,
                    sqln.delivery_note,
                    sqln.note,
                    COALESCE(sqr.supplier_name, s.company_name, CASE WHEN sqs.quote_line_id IS NULL THEN \'Manuel fiyat\' ELSE NULL END) AS supplier_display,
                    sqr.recipient_email,
                    sqr.contact_name
             FROM supplier_quote_selections sqs
             INNER JOIN renewal_items ri ON ri.id = sqs.renewal_item_id
             LEFT JOIN supplier_quote_lines sqln ON sqln.id = sqs.quote_line_id
             LEFT JOIN supplier_quote_requests sqr ON sqr.id = sqln.request_id
             LEFT JOIN suppliers s ON s.id = sqr.supplier_id
             WHERE sqs.renewal_id = :renewal_id
             ORDER BY ri.sort_order ASC, ri.id ASC'
        );
        $stmt->execute(['renewal_id' => $renewalId]);

        return $stmt->fetchAll();
    }

    public function createSupplierQuoteSelectionDelivery(array $selected): array
    {
        $token = bin2hex(random_bytes(32));
        $renewalId = (int) ($selected['renewal_id'] ?? 0);
        $quoteLineId = (int) ($selected['quote_line_id'] ?? $selected['supplier_quote_line_id'] ?? 0);
        $renewalItemId = (int) ($selected['renewal_item_id'] ?? 0);

        if ($renewalItemId < 1 && $quoteLineId > 0) {
            $stmt = $this->db->prepare('SELECT renewal_item_id FROM supplier_quote_lines WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $quoteLineId]);
            $renewalItemId = (int) $stmt->fetchColumn();
        }

        $selectionId = (int) ($selected['selection_id'] ?? 0);
        if ($selectionId < 1) {
            $selectionId = $this->findSupplierQuoteSelectionId($renewalId, $renewalItemId, $quoteLineId);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO supplier_quote_selection_deliveries
                (renewal_id, renewal_item_id, quote_line_id, selection_id, supplier_name, item_title, recipient_email, recipient_name, token_hash, selected_term, selected_price, currency, status, created_at, updated_at)
             VALUES
                (:renewal_id, :renewal_item_id, :quote_line_id, :selection_id, :supplier_name, :item_title, :recipient_email, :recipient_name, :token_hash, :selected_term, :selected_price, :currency, \'pending\', NOW(), NOW())'
        );
        $stmt->execute([
            'renewal_id' => $renewalId,
            'renewal_item_id' => $renewalItemId > 0 ? $renewalItemId : null,
            'quote_line_id' => $quoteLineId > 0 ? $quoteLineId : null,
            'selection_id' => $selectionId > 0 ? $selectionId : null,
            'supplier_name' => $this->nullableString($selected['supplier_display'] ?? $selected['supplier_name'] ?? ''),
            'item_title' => $this->nullableString($selected['item_title'] ?? ''),
            'recipient_email' => trim((string) ($selected['recipient_email'] ?? '')),
            'recipient_name' => $this->nullableString($selected['contact_name'] ?? ''),
            'token_hash' => hash('sha256', $token),
            'selected_term' => $this->nullableString($selected['term'] ?? $selected['supplier_term'] ?? ''),
            'selected_price' => $this->nullableDecimalValue($selected['price'] ?? $selected['supplier_price'] ?? null),
            'currency' => self::normalizeCurrency($selected['currency'] ?? $selected['supplier_currency'] ?? 'TRY'),
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'token' => $token,
            'read_url' => \url('/tedarikci-secim-okudum/' . rawurlencode($token)),
        ];
    }

    public function updateSupplierQuoteSelectionDeliveryStatus(int $deliveryId, ?int $mailLogId, string $status, ?string $error = null): void
    {
        $status = in_array($status, ['pending', 'sent', 'failed', 'read'], true) ? $status : 'pending';
        $stmt = $this->db->prepare(
            'UPDATE supplier_quote_selection_deliveries
             SET mail_log_id = :mail_log_id,
                 status = :status,
                 error_message = :error_message,
                 sent_at = CASE WHEN :status_sent = \'sent\' THEN COALESCE(sent_at, NOW()) ELSE sent_at END,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $deliveryId,
            'mail_log_id' => $mailLogId ?: null,
            'status' => $status,
            'status_sent' => $status,
            'error_message' => $this->nullableString($error),
        ]);
    }

    public function markSupplierQuoteSelectionRead(string $token, string $ipAddress = '', string $userAgent = ''): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        $hash = hash('sha256', strtolower($token));
        $stmt = $this->db->prepare(
            "SELECT sqsd.*,
                    sqs.selected_by,
                    r.title,
                    r.supplier_share_customer_info,
                    COALESCE(ri.title, sqsd.item_title, r.title) AS display_item_title,
                    c.company_name
             FROM supplier_quote_selection_deliveries sqsd
             INNER JOIN renewals r ON r.id = sqsd.renewal_id
             INNER JOIN customers c ON c.id = r.customer_id
             LEFT JOIN renewal_items ri ON ri.id = sqsd.renewal_item_id
             LEFT JOIN supplier_quote_selections sqs ON sqs.id = sqsd.selection_id
             WHERE sqsd.token_hash = :token_hash
             LIMIT 1"
        );
        $stmt->execute(['token_hash' => $hash]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $alreadyRead = !empty($row['read_at']);
        $this->db->prepare(
            'UPDATE supplier_quote_selection_deliveries
             SET status = \'read\',
                 read_at = COALESCE(read_at, NOW()),
                 read_ip = :read_ip,
                 read_user_agent = :read_user_agent,
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'id' => (int) $row['id'],
            'read_ip' => $this->nullableString(substr($ipAddress, 0, 45)),
            'read_user_agent' => $this->nullableString(substr($userAgent, 0, 255)),
        ]);

        $row['already_read'] = $alreadyRead ? '1' : '0';
        $row['read_at'] = $row['read_at'] ?: date('Y-m-d H:i:s');

        return $row;
    }

    private function findSupplierQuoteSelectionId(int $renewalId, int $renewalItemId, int $quoteLineId): int
    {
        if ($renewalId < 1) {
            return 0;
        }

        $where = ['renewal_id = :renewal_id'];
        $params = ['renewal_id' => $renewalId];
        if ($quoteLineId > 0) {
            $where[] = 'quote_line_id = :quote_line_id';
            $params['quote_line_id'] = $quoteLineId;
        } elseif ($renewalItemId > 0) {
            $where[] = 'renewal_item_id = :renewal_item_id';
            $params['renewal_item_id'] = $renewalItemId;
        } else {
            return 0;
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM supplier_quote_selections
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY selected_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function createCustomerOffer(int $renewalId, array $recipient, array $data, int $createdBy = 0): array
    {
        $token = bin2hex(random_bytes(32));
        $currency = self::normalizeCurrency($data['currency'] ?? 'TRY');
        $subject = mb_substr(trim((string) ($data['subject'] ?? '')), 0, 240);
        $message = trim((string) ($data['message'] ?? ''));
        $lines = $this->normalizeCustomerOfferLines($data['lines'] ?? [], $currency);
        if ($lines === []) {
            throw new \RuntimeException('Müşteri teklifi için en az bir fiyat satırı gerekli.');
        }

        $subtotal = 0.0;
        $vatTotal = 0.0;
        $total = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) $line['line_subtotal'];
            $vatTotal += (float) $line['line_vat'];
            $total += (float) $line['line_total'];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO customer_offer_requests
                    (renewal_id, recipient_email, recipient_name, token_hash, status, subject, message_body, currency, subtotal, vat_total, total, expires_at, created_by)
                 VALUES
                    (:renewal_id, :recipient_email, :recipient_name, :token_hash, :status, :subject, :message_body, :currency, :subtotal, :vat_total, :total, DATE_ADD(NOW(), INTERVAL 14 DAY), :created_by)'
            );
            $stmt->execute([
                'renewal_id' => $renewalId,
                'recipient_email' => strtolower(trim((string) ($recipient['email'] ?? ''))),
                'recipient_name' => $this->nullableString($recipient['name'] ?? ''),
                'token_hash' => hash('sha256', $token),
                'status' => 'sent',
                'subject' => $subject,
                'message_body' => $message,
                'currency' => $currency,
                'subtotal' => round($subtotal, 2),
                'vat_total' => round($vatTotal, 2),
                'total' => round($total, 2),
                'created_by' => $createdBy > 0 ? $createdBy : null,
            ]);
            $offerId = (int) $this->db->lastInsertId();

            $insertLine = $this->db->prepare(
                'INSERT INTO customer_offer_lines
                    (offer_id, renewal_item_id, supplier_quote_line_id, item_title, quantity, unit_price, vat_rate, line_subtotal, line_vat, line_total, currency, supplier_name, supplier_recipient_email, supplier_contact_name, supplier_term, supplier_custom_term, supplier_price, supplier_currency, supplier_vat_included, supplier_delivery_note, supplier_note, sort_order)
                 VALUES
                    (:offer_id, :renewal_item_id, :supplier_quote_line_id, :item_title, :quantity, :unit_price, :vat_rate, :line_subtotal, :line_vat, :line_total, :currency, :supplier_name, :supplier_recipient_email, :supplier_contact_name, :supplier_term, :supplier_custom_term, :supplier_price, :supplier_currency, :supplier_vat_included, :supplier_delivery_note, :supplier_note, :sort_order)'
            );
            foreach ($lines as $index => $line) {
                $line['offer_id'] = $offerId;
                $line['sort_order'] = $index * 10;
                $insertLine->execute($line);
            }

            $this->db->commit();

            return [
                'id' => $offerId,
                'token' => $token,
                'url' => \url('/musteri-teklif/' . $token),
                'renewal_id' => $renewalId,
                'recipient_email' => strtolower(trim((string) ($recipient['email'] ?? ''))),
                'recipient_name' => $this->nullableString($recipient['name'] ?? ''),
                'subject' => $subject,
                'message_body' => $message,
                'subtotal' => round($subtotal, 2),
                'vat_total' => round($vatTotal, 2),
                'total' => round($total, 2),
                'currency' => $currency,
                'lines' => $lines,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function findCustomerOfferByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT cor.*,
                    r.customer_id,
                    r.title,
                    r.brand,
                    r.kind,
                    r.license_key,
                    r.renewal_date,
                    r.start_date,
                    c.company_name,
                    c.contact_name,
                    c.email AS customer_email,
                    c.phone AS customer_phone,
                    c.tax_office AS customer_tax_office,
                    c.tax_number AS customer_tax_number,
                    c.city AS customer_city,
                    c.district AS customer_district,
                    c.address AS customer_address,
                    c.parasut_contact_id
             FROM customer_offer_requests cor
             INNER JOIN renewals r ON r.id = cor.renewal_id
             INNER JOIN customers c ON c.id = r.customer_id
             WHERE cor.token_hash = :token_hash
             LIMIT 1"
        );
        $stmt->execute(['token_hash' => hash('sha256', strtolower($token))]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findCustomerOfferById(int $offerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT cor.*,
                    r.customer_id,
                    r.title,
                    r.brand,
                    r.kind,
                    r.license_key,
                    r.renewal_date,
                    r.start_date,
                    c.company_name,
                    c.contact_name,
                    c.email AS customer_email,
                    c.phone AS customer_phone,
                    c.tax_office AS customer_tax_office,
                    c.tax_number AS customer_tax_number,
                    c.city AS customer_city,
                    c.district AS customer_district,
                    c.address AS customer_address,
                    c.parasut_contact_id
             FROM customer_offer_requests cor
             INNER JOIN renewals r ON r.id = cor.renewal_id
             INNER JOIN customers c ON c.id = r.customer_id
             WHERE cor.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $offerId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function customerOfferLines(int $offerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM customer_offer_lines
             WHERE offer_id = :offer_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['offer_id' => $offerId]);

        return $stmt->fetchAll();
    }

    public function customerOffersForRenewal(int $renewalId): array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM customer_offer_requests
             WHERE renewal_id = :renewal_id
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['renewal_id' => $renewalId]);
        $offers = $stmt->fetchAll();
        if ($offers === []) {
            return [];
        }

        $ids = array_map(static fn (array $offer): int => (int) $offer['id'], $offers);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $lineStmt = $this->db->prepare(
            'SELECT *
             FROM customer_offer_lines
             WHERE offer_id IN (' . $placeholders . ')
             ORDER BY offer_id ASC, sort_order ASC, id ASC'
        );
        $lineStmt->execute($ids);

        $linesByOffer = [];
        foreach ($lineStmt->fetchAll() as $line) {
            $linesByOffer[(int) $line['offer_id']][] = $line;
        }

        foreach ($offers as &$offer) {
            $offer['lines'] = $linesByOffer[(int) $offer['id']] ?? [];
        }
        unset($offer);

        return $offers;
    }

    public function latestApprovedCustomerOfferWaitingParasut(int $renewalId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id,
                    renewal_id,
                    recipient_name,
                    recipient_email,
                    total,
                    currency,
                    parasut_invoice_status,
                    parasut_invoice_error,
                    responded_at,
                    created_at
             FROM customer_offer_requests
             WHERE renewal_id = :renewal_id
               AND status = 'approved'
               AND COALESCE(parasut_invoice_id, '') = ''
             ORDER BY responded_at DESC, created_at DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute(['renewal_id' => $renewalId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function deleteCustomerOffer(int $offerId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, renewal_id, recipient_email, recipient_name, total, currency, created_at
             FROM customer_offer_requests
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $offerId]);
        $offer = $stmt->fetch();
        if (!$offer) {
            return null;
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM customer_offer_lines WHERE offer_id = :id')->execute(['id' => $offerId]);
            $this->db->prepare('DELETE FROM customer_offer_requests WHERE id = :id')->execute(['id' => $offerId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $offer;
    }

    public function markCustomerOfferOpened(int $offerId): int
    {
        $this->db->prepare(
            "UPDATE customer_offer_requests
             SET status = CASE WHEN status = 'sent' THEN 'opened' ELSE status END,
                 opened_at = COALESCE(opened_at, NOW()),
                 view_count = view_count + 1,
                 updated_at = NOW()
             WHERE id = :id"
        )->execute(['id' => $offerId]);

        $stmt = $this->db->prepare('SELECT view_count FROM customer_offer_requests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $offerId]);

        return (int) $stmt->fetchColumn();
    }

    public function respondCustomerOffer(int $offerId, string $decision, string $note = '', string $ipAddress = '', string $userAgent = ''): void
    {
        $status = match ($decision) {
            'approved' => 'approved',
            'revision_requested' => 'revision_requested',
            'rejected' => 'rejected',
            default => '',
        };
        if ($status === '') {
            throw new \RuntimeException('Geçersiz teklif yanıtı.');
        }

        $stmt = $this->db->prepare(
            'UPDATE customer_offer_requests
             SET status = :status,
                 responded_at = NOW(),
                 response_note = :response_note,
                 response_ip = :response_ip,
                 response_user_agent = :response_user_agent,
                 updated_at = NOW()
             WHERE id = :id
               AND status IN (\'sent\', \'opened\')'
        );
        $stmt->execute([
            'id' => $offerId,
            'status' => $status,
            'response_note' => $this->nullableString($note),
            'response_ip' => $this->nullableString(substr($ipAddress, 0, 45)),
            'response_user_agent' => $this->nullableString(substr($userAgent, 0, 255)),
        ]);
        if ($stmt->rowCount() < 1) {
            throw new \RuntimeException('Bu teklif daha once yanitlanmis veya artik yanitlanamaz.');
        }
    }

    public function markCustomerOfferParasutInvoice(int $offerId, array $invoice): void
    {
        $stmt = $this->db->prepare(
            "UPDATE customer_offer_requests
             SET parasut_invoice_id = :invoice_id,
                 parasut_invoice_no = :invoice_no,
                 parasut_invoice_status = 'created',
                 parasut_invoice_error = NULL,
                 parasut_invoice_created_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $offerId,
            'invoice_id' => trim((string) ($invoice['id'] ?? '')),
            'invoice_no' => $this->nullableString($invoice['invoice_no'] ?? ''),
        ]);
    }

    public function markCustomerOfferParasutInvoiceError(int $offerId, string $error): void
    {
        $stmt = $this->db->prepare(
            "UPDATE customer_offer_requests
             SET parasut_invoice_status = 'failed',
                 parasut_invoice_error = :error,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $offerId,
            'error' => mb_substr(trim($error), 0, 2000),
        ]);
    }

    public function applyCustomerOfferToRenewal(int $renewalId, int $offerId): void
    {
        $lines = $this->customerOfferLines($offerId);
        if ($lines === []) {
            throw new \RuntimeException('Onaylanan teklif satırları bulunamadı.');
        }

        $currency = self::normalizeCurrency($lines[0]['currency'] ?? 'TRY');
        $total = 0.0;
        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare(
                'UPDATE renewal_items
                 SET unit_price = :unit_price,
                     vat_rate = :vat_rate,
                     quantity = :quantity,
                     updated_at = NOW()
                 WHERE id = :id AND renewal_id = :renewal_id'
            );
            foreach ($lines as $line) {
                $itemId = (int) ($line['renewal_item_id'] ?? 0);
                if ($itemId > 0) {
                    $update->execute([
                        'id' => $itemId,
                        'renewal_id' => $renewalId,
                        'unit_price' => (float) ($line['unit_price'] ?? 0),
                        'vat_rate' => (float) ($line['vat_rate'] ?? 20),
                        'quantity' => (float) ($line['quantity'] ?? 1),
                    ]);
                }
                $total += (float) ($line['line_total'] ?? 0);
            }

            $this->db->prepare(
                'UPDATE renewals
                 SET amount = :amount,
                     currency = :currency,
                     payment_customer_choice = 1,
                     payment_method = NULL,
                     updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                'id' => $renewalId,
                'amount' => round($total, 2),
                'currency' => $currency,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function iyzicoPayments(int $renewalId): array
    {
        return $this->providerPayments($renewalId, 'iyzico');
    }

    public function cardPayments(int $renewalId): array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM renewal_payments
             WHERE renewal_id = :renewal_id
               AND provider = \'iyzico\'
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['renewal_id' => $renewalId]);

        return $stmt->fetchAll();
    }

    public function providerPayments(int $renewalId, string $provider): array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM renewal_payments
             WHERE renewal_id = :renewal_id
               AND provider = :provider
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([
            'renewal_id' => $renewalId,
            'provider' => $provider,
        ]);

        return $stmt->fetchAll();
    }

    public function createIyzicoPayment(array $data): int
    {
        $data['provider'] = 'iyzico';

        return $this->createCardPayment($data);
    }

    private function createCardPayment(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO renewal_payments
                (renewal_id, provider, conversation_id, token, amount, currency, status, payment_status, payment_id, payment_page_url, error_message, raw_request, raw_response, created_by)
             VALUES
                (:renewal_id, :provider, :conversation_id, :token, :amount, :currency, :status, :payment_status, :payment_id, :payment_page_url, :error_message, :raw_request, :raw_response, :created_by)'
        );
        $stmt->execute([
            'renewal_id' => (int) $data['renewal_id'],
            'provider' => (string) ($data['provider'] ?? 'iyzico'),
            'conversation_id' => (string) $data['conversation_id'],
            'token' => $this->nullableString($data['token'] ?? ''),
            'amount' => (float) $data['amount'],
            'currency' => self::normalizeCurrency($data['currency'] ?? 'TRY'),
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
            'SELECT rp.*, r.title, r.brand, c.company_name
             FROM renewal_payments rp
             INNER JOIN renewals r ON r.id = rp.renewal_id
             INNER JOIN customers c ON c.id = r.customer_id
             WHERE rp.provider = :provider
               AND rp.token = :token
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
        $stmt = $this->db->prepare(
            'UPDATE renewal_payments SET
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
    }

    public function createPaymentReceipt(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO renewal_payment_receipts
                (renewal_id, payment_method, payer_email, note, original_name, stored_path, mime_type, file_size, notification_recipients, notification_status, notification_error)
             VALUES
                (:renewal_id, :payment_method, :payer_email, :note, :original_name, :stored_path, :mime_type, :file_size, :notification_recipients, :notification_status, :notification_error)'
        );
        $stmt->execute([
            'renewal_id' => (int) $data['renewal_id'],
            'payment_method' => trim((string) ($data['payment_method'] ?? '')),
            'payer_email' => $this->nullableString($data['payer_email'] ?? ''),
            'note' => $this->nullableString($data['note'] ?? ''),
            'original_name' => trim((string) ($data['original_name'] ?? '')),
            'stored_path' => trim((string) ($data['stored_path'] ?? '')),
            'mime_type' => $this->nullableString($data['mime_type'] ?? ''),
            'file_size' => (int) ($data['file_size'] ?? 0),
            'notification_recipients' => $this->nullableString($data['notification_recipients'] ?? ''),
            'notification_status' => trim((string) ($data['notification_status'] ?? 'pending')),
            'notification_error' => $this->nullableString($data['notification_error'] ?? ''),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updatePaymentReceiptNotification(int $receiptId, string $status, string $error = '', string $recipients = ''): void
    {
        $stmt = $this->db->prepare(
            'UPDATE renewal_payment_receipts
             SET notification_status = :notification_status,
                 notification_error = :notification_error,
                 notification_recipients = COALESCE(NULLIF(:notification_recipients, \'\'), notification_recipients),
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $receiptId,
            'notification_status' => trim($status),
            'notification_error' => $this->nullableString($error),
            'notification_recipients' => trim($recipients),
        ]);
    }

    public function recordRenewalDecision(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO renewal_decisions
                (renewal_id, decision, payment_terms, reason, postponed_date, note, mail_status, mail_error, created_by)
             VALUES
                (:renewal_id, :decision, :payment_terms, :reason, :postponed_date, :note, :mail_status, :mail_error, :created_by)'
        );
        $stmt->execute([
            'renewal_id' => (int) $data['renewal_id'],
            'decision' => trim((string) ($data['decision'] ?? '')),
            'payment_terms' => $this->nullableString($data['payment_terms'] ?? ''),
            'reason' => $this->nullableString($data['reason'] ?? ''),
            'postponed_date' => $this->nullableString($data['postponed_date'] ?? ''),
            'note' => $this->nullableString($data['note'] ?? ''),
            'mail_status' => trim((string) ($data['mail_status'] ?? 'none')),
            'mail_error' => $this->nullableString($data['mail_error'] ?? ''),
            'created_by' => empty($data['created_by']) ? null : (int) $data['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function latestRenewalDecision(int $renewalId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT rd.*, u.name AS created_by_name
             FROM renewal_decisions rd
             LEFT JOIN users u ON u.id = rd.created_by
             WHERE rd.renewal_id = :renewal_id
             ORDER BY rd.created_at DESC, rd.id DESC
             LIMIT 1'
        );
        $stmt->execute(['renewal_id' => $renewalId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function markDecisionApproved(int $renewalId, string $paymentTerms): void
    {
        $paymentMethod = mb_substr(trim($paymentTerms), 0, 120);
        $this->db->prepare(
            "UPDATE renewals
             SET status = 'active',
                 payment_method = :payment_terms,
                 payment_customer_choice = 0,
                 updated_at = NOW()
             WHERE id = :id"
        )->execute([
            'id' => $renewalId,
            'payment_terms' => $paymentMethod,
        ]);
    }

    public function markDecisionRejected(int $renewalId): void
    {
        $this->db->prepare(
            "UPDATE renewals
             SET status = 'cancelled',
                 updated_at = NOW()
             WHERE id = :id"
        )->execute(['id' => $renewalId]);
    }

    public function postponeRenewal(int $renewalId, string $newDate): void
    {
        $this->db->prepare(
            "UPDATE renewals
             SET status = 'active',
                 renewal_date = :renewal_date,
                 last_notified_at = NULL,
                 updated_at = NOW()
             WHERE id = :id"
        )->execute([
            'id' => $renewalId,
            'renewal_date' => $newDate,
        ]);
    }

    public function setRenewalPaymentMethod(int $renewalId, string $paymentMethod, ?string $selectedEmail = null): void
    {
        $selectedEmail = trim((string) $selectedEmail);
        if ($selectedEmail !== '' && !filter_var($selectedEmail, FILTER_VALIDATE_EMAIL)) {
            $selectedEmail = '';
        }

        $stmt = $this->db->prepare(
            'UPDATE renewals
             SET payment_method = :payment_method,
                 payment_customer_choice = 0,
                 payment_selected_email = :payment_selected_email,
                 payment_selected_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $renewalId,
            'payment_method' => trim($paymentMethod),
            'payment_selected_email' => $selectedEmail !== '' ? $selectedEmail : null,
        ]);
    }

    public function createRenewalDefinition(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $kind = in_array(($data['kind'] ?? 'service'), ['product', 'service'], true) ? $data['kind'] : 'service';
        $notificationInfo = trim((string) ($data['notification_info'] ?? ''));

        if ($name === '') {
            throw new \RuntimeException('Urun/hizmet adi zorunlu.');
        }

        if ($notificationInfo === '') {
            throw new \RuntimeException('Bilgilendirme metni zorunlu.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO renewal_definitions (name, kind, notification_info, is_active)
             VALUES (:name, :kind, :notification_info, 1)
             ON DUPLICATE KEY UPDATE notification_info = VALUES(notification_info), is_active = 1, updated_at = NOW()'
        );
        $stmt->execute([
            'name' => $name,
            'kind' => $kind,
            'notification_info' => $notificationInfo,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function deleteRenewalDefinition(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE renewal_definitions SET is_active = 0, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function updateRenewalDefinition(int $id, array $data): void
    {
        $name = trim((string) ($data['name'] ?? ''));
        $kind = in_array(($data['kind'] ?? 'service'), ['product', 'service'], true) ? $data['kind'] : 'service';
        $notificationInfo = trim((string) ($data['notification_info'] ?? ''));

        if ($name === '') {
            throw new \RuntimeException('Urun/hizmet adi zorunlu.');
        }

        if ($notificationInfo === '') {
            throw new \RuntimeException('Bilgilendirme metni zorunlu.');
        }

        $stmt = $this->db->prepare(
            'UPDATE renewal_definitions
             SET name = :name, kind = :kind, notification_info = :notification_info, is_active = 1, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'kind' => $kind,
            'notification_info' => $notificationInfo,
        ]);
    }

    public function createRenewalPeriod(array $data): int
    {
        $count = max(1, (int) ($data['interval_count'] ?? 1));
        $unit = in_array(($data['interval_unit'] ?? 'year'), ['day', 'month', 'year'], true) ? $data['interval_unit'] : 'year';
        $name = trim((string) ($data['period_name'] ?? ''));
        if ($name === '') {
            $name = $this->periodName($count, $unit);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO renewal_periods (name, interval_count, interval_unit, is_active)
             VALUES (:name, :interval_count, :interval_unit, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1, updated_at = NOW()'
        );
        $stmt->execute([
            'name' => $name,
            'interval_count' => $count,
            'interval_unit' => $unit,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function deleteRenewalPeriod(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE renewal_periods SET is_active = 0, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function updateRenewalPeriod(int $id, array $data): void
    {
        $count = max(1, (int) ($data['interval_count'] ?? 1));
        $unit = in_array(($data['interval_unit'] ?? 'year'), ['day', 'month', 'year'], true) ? $data['interval_unit'] : 'year';
        $name = trim((string) ($data['period_name'] ?? ''));
        if ($name === '') {
            $name = $this->periodName($count, $unit);
        }

        $stmt = $this->db->prepare(
            'UPDATE renewal_periods
             SET name = :name, interval_count = :interval_count, interval_unit = :interval_unit, is_active = 1, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'interval_count' => $count,
            'interval_unit' => $unit,
        ]);
    }

    public function createPaymentMethod(array $data): int
    {
        $name = trim((string) ($data['payment_name'] ?? $data['name'] ?? ''));
        $description = trim((string) ($data['payment_description'] ?? $data['description'] ?? ''));
        $sortOrder = max(0, (int) ($data['sort_order'] ?? 0));

        if ($name === '') {
            throw new \RuntimeException('Odeme yontemi adi zorunlu.');
        }
        if (self::isRemovedPaymentMethodName($name)) {
            throw new \RuntimeException('Bu ödeme yöntemi kaldırıldı. Özel ödeme şartı için müşteri seçiminde Diğer alanını kullanın.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO payment_methods (name, description, sort_order, is_active)
             VALUES (:name, :description, :sort_order, 1)
             ON DUPLICATE KEY UPDATE description = VALUES(description), sort_order = VALUES(sort_order), is_active = 1, updated_at = NOW()'
        );
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'sort_order' => $sortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updatePaymentMethod(int $id, array $data): void
    {
        $name = trim((string) ($data['payment_name'] ?? $data['name'] ?? ''));
        $description = trim((string) ($data['payment_description'] ?? $data['description'] ?? ''));
        $sortOrder = max(0, (int) ($data['sort_order'] ?? 0));

        if ($name === '') {
            throw new \RuntimeException('Odeme yontemi adi zorunlu.');
        }
        if (self::isRemovedPaymentMethodName($name)) {
            throw new \RuntimeException('Bu ödeme yöntemi kaldırıldı. Özel ödeme şartı için müşteri seçiminde Diğer alanını kullanın.');
        }

        $stmt = $this->db->prepare(
            'UPDATE payment_methods
             SET name = :name, description = :description, sort_order = :sort_order, is_active = 1, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'sort_order' => $sortOrder,
        ]);
    }

    public function deletePaymentMethod(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE payment_methods SET is_active = 0, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function createSupplierGroup(array $data): int
    {
        $name = trim((string) ($data['group_name'] ?? $data['name'] ?? ''));

        $stmt = $this->db->prepare(
            'INSERT INTO supplier_groups (name, is_active)
             VALUES (:name, 1)
             ON DUPLICATE KEY UPDATE is_active = 1, updated_at = NOW()'
        );
        $stmt->execute(['name' => $name]);

        return (int) $this->db->lastInsertId();
    }

    public function deleteSupplierGroup(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE supplier_groups SET is_active = 0, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function updateSupplierGroup(int $id, array $data): void
    {
        $name = trim((string) ($data['group_name'] ?? $data['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Tedarikci grup adi zorunlu.');
        }

        $stmt = $this->db->prepare(
            'UPDATE supplier_groups
             SET name = :name, is_active = 1, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
        ]);
    }

    public function suppliersWithContacts(bool $deleted = false): array
    {
        $suppliers = $deleted
            ? $this->db->query($this->supplierSelect() . ' WHERE s.deleted_at IS NOT NULL ORDER BY s.deleted_at DESC, s.company_name ASC')->fetchAll()
            : $this->suppliers();

        if ($suppliers === []) {
            return [];
        }

        return $this->attachSupplierContacts($suppliers);
    }

    public function findSupplier(int $id, bool $includeDeleted = false): ?array
    {
        $sql = $this->supplierSelect() . ' WHERE s.id = :id';
        if (!$includeDeleted) {
            $sql .= ' AND s.deleted_at IS NULL';
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $supplier = $stmt->fetch();

        if (!$supplier) {
            return null;
        }

        return $this->attachSupplierContacts([$supplier])[0];
    }

    public function createSupplier(array $data): int
    {
        $this->db->beginTransaction();

        try {
            $supplierId = $this->saveSupplier($data);
            $this->createSupplierContacts($supplierId, $data);
            $this->db->commit();

            return $supplierId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updateSupplier(int $id, array $data): void
    {
        $this->db->beginTransaction();

        try {
            $this->saveSupplier($data, $id);
            $this->replaceSupplierContacts($id, $data);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteSupplier(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE suppliers SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);
    }

    public function restoreSupplier(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE suppliers SET deleted_at = NULL, updated_at = NOW() WHERE id = :id AND deleted_at IS NOT NULL');
        $stmt->execute(['id' => $id]);
    }

    private function attachContacts(array $customers): array
    {
        $ids = array_map(static fn (array $customer): int => (int) $customer['id'], $customers);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT *
             FROM customer_contacts
             WHERE customer_id IN ({$placeholders})
             ORDER BY notify_enabled DESC, full_name ASC"
        );
        $stmt->execute($ids);

        $contacts = [];
        foreach ($stmt->fetchAll() as $contact) {
            $contacts[(int) $contact['customer_id']][] = $contact;
        }

        foreach ($customers as &$customer) {
            $customer['contacts'] = $contacts[(int) $customer['id']] ?? [];
        }
        unset($customer);

        return $customers;
    }

    private function ensureInvoiceSchema(): void
    {
        if (self::$invoiceSchemaEnsured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS renewal_invoice_periods (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$invoiceSchemaEnsured = true;
    }

    private function ensurePaymentSchema(): void
    {
        if (self::$paymentSchemaEnsured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS renewal_payments (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS renewal_payment_receipts (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$paymentSchemaEnsured = true;
    }

    private function ensureRenewalSchema(): void
    {
        if (self::$renewalSchemaEnsured) {
            return;
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM renewals LIKE 'payment_method'");
        if (!$stmt || !$stmt->fetch()) {
            $this->db->exec('ALTER TABLE renewals ADD payment_method VARCHAR(120) NULL AFTER license_key');
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM renewals LIKE 'payment_customer_choice'");
        if (!$stmt || !$stmt->fetch()) {
            $this->db->exec('ALTER TABLE renewals ADD payment_customer_choice TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_method');
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM renewals LIKE 'payment_selected_email'");
        if (!$stmt || !$stmt->fetch()) {
            $this->db->exec('ALTER TABLE renewals ADD payment_selected_email VARCHAR(190) NULL AFTER payment_customer_choice');
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM renewals LIKE 'payment_selected_at'");
        if (!$stmt || !$stmt->fetch()) {
            $this->db->exec('ALTER TABLE renewals ADD payment_selected_at DATETIME NULL AFTER payment_selected_email');
        }

        $this->ensureColumn('renewals', 'supplier_share_customer_info', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER supplier_price_request_days');

        self::$renewalSchemaEnsured = true;
    }

    private function ensureDefinitionSchema(): void
    {
        if (self::$definitionSchemaEnsured) {
            return;
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM renewal_definitions LIKE 'notification_info'");
        if (!$stmt || !$stmt->fetch()) {
            $this->db->exec('ALTER TABLE renewal_definitions ADD notification_info TEXT NULL AFTER kind');
        }

        $this->seedDomainHostingNotificationInfo();
        self::$definitionSchemaEnsured = true;
    }

    private function seedDomainHostingNotificationInfo(): void
    {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS app_settings (
                    setting_key VARCHAR(120) PRIMARY KEY,
                    setting_value MEDIUMTEXT NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $key = 'maintenance.definition_domain_hosting_info_v1';
            $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1');
            $stmt->execute(['setting_key' => $key]);
            if ((string) $stmt->fetchColumn() === '1') {
                return;
            }

            $names = self::domainHostingDefinitionNames();
            $placeholders = implode(', ', array_fill(0, count($names), '?'));
            $existsStmt = $this->db->prepare("SELECT COUNT(*) FROM renewal_definitions WHERE kind = 'product' AND name IN ({$placeholders})");
            $existsStmt->execute($names);
            if ((int) $existsStmt->fetchColumn() < 1) {
                $insertStmt = $this->db->prepare(
                    'INSERT INTO renewal_definitions (name, kind, notification_info, is_active)
                     VALUES (:name, "product", :notification_info, 1)
                     ON DUPLICATE KEY UPDATE notification_info = VALUES(notification_info), is_active = 1, updated_at = NOW()'
                );
                $insertStmt->execute([
                    'name' => 'Alan Adı ve Hosting Yenileme',
                    'notification_info' => self::domainHostingNotificationInfo(),
                ]);
            }

            $updateStmt = $this->db->prepare(
                "UPDATE renewal_definitions
                 SET notification_info = ?,
                     is_active = 1,
                     updated_at = NOW()
                 WHERE kind = 'product'
                   AND name IN ({$placeholders})"
            );
            $updateStmt->execute(array_merge([self::domainHostingNotificationInfo()], $names));

            $doneStmt = $this->db->prepare(
                'INSERT INTO app_settings (setting_key, setting_value, updated_at)
                 VALUES (:setting_key, "1", NOW())
                 ON DUPLICATE KEY UPDATE setting_value = "1", updated_at = NOW()'
            );
            $doneStmt->execute(['setting_key' => $key]);
        } catch (\Throwable) {
            // Default content must not block the panel if a host limits maintenance writes.
        }
    }

    private static function domainHostingDefinitionNames(): array
    {
        return [
            'Alan Adı ve Hosting Yenileme',
            'Alan Adi ve Hosting Yenileme',
            'Alan Adı Hosting Yenileme',
            'Alan Adi Hosting Yenileme',
            'Alan Adı ve Hosting Yenilemesi',
            'Alan Adi ve Hosting Yenilemesi',
            'Alan Adı Yenileme',
            'Alan Adi Yenileme',
            'Web Hosting',
            'E-posta Hosting',
        ];
    }

    private static function domainHostingNotificationInfo(): string
    {
        return 'Alan adı ve hosting yenilemeleri genellikle sessiz ilerleyen, ancak süresi kaçırıldığında etkisi hızlı hissedilen süreçlerdir. Süre dolduğunda web sitesi, e-posta hesapları, DNS yönlendirmeleri ve bağlı servislerde erişim kesintileri yaşanabilir. Alan adı tarafında ilk günlerde yenileme çoğu zaman yapılabilse de, bekleme veya kurtarma dönemine girildiğinde ek ücret, kesinti süresi ve alan adının kaybedilmesi riski oluşabilir. Hosting tarafında ise dosya, yedek ve e-posta erişimi etkilenebileceği için yenileme tercihinin süre dolmadan netleşmesi önerilir.';
    }

    private function ensurePaymentMethodSchema(): void
    {
        if (self::$paymentMethodSchemaEnsured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS payment_methods (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                description TEXT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_payment_methods_name (name),
                INDEX idx_payment_methods_active (is_active, sort_order, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->seedDefaultPaymentMethods();
        self::$paymentMethodSchemaEnsured = true;
    }

    private function ensureDecisionSchema(): void
    {
        if (self::$decisionSchemaEnsured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS renewal_decisions (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$decisionSchemaEnsured = true;
    }

    private function ensureNotificationDeliverySchema(): void
    {
        if (self::$notificationDeliverySchemaEnsured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS renewal_notification_reads (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                renewal_id INT UNSIGNED NOT NULL,
                read_on DATE NOT NULL,
                read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_renewal_notification_reads_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
                UNIQUE KEY uq_renewal_notification_read_day (renewal_id, read_on),
                INDEX idx_renewal_notification_reads_day (read_on)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS renewal_notification_deliveries (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->ensureColumn('renewal_notification_deliveries', 'mail_log_id', 'INT UNSIGNED NULL AFTER renewal_id');
        $this->ensureColumn('renewal_notification_deliveries', 'recipient_name', 'VARCHAR(190) NULL AFTER recipient_email');
        $this->ensureColumn('renewal_notification_deliveries', 'notification_date', 'DATE NULL AFTER token_hash');
        $this->ensureColumn('renewal_notification_deliveries', 'sent_at', 'DATETIME NULL AFTER error_message');
        $this->ensureColumn('renewal_notification_deliveries', 'read_at', 'DATETIME NULL AFTER sent_at');
        $this->ensureColumn('renewal_notification_deliveries', 'read_ip', 'VARCHAR(45) NULL AFTER read_at');
        $this->ensureColumn('renewal_notification_deliveries', 'read_user_agent', 'VARCHAR(255) NULL AFTER read_ip');
        $this->ensureColumn('renewal_notification_deliveries', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER read_user_agent');
        $this->ensureColumn('renewal_notification_deliveries', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
        $this->ensureIndex('renewal_notification_deliveries', 'idx_renewal_notification_delivery_mail_log', 'INDEX idx_renewal_notification_delivery_mail_log (mail_log_id)');
        $this->ensureIndex('renewal_notification_deliveries', 'idx_renewal_notification_delivery_read', 'INDEX idx_renewal_notification_delivery_read (read_at)');
        $this->ensureIndex('renewal_notification_deliveries', 'idx_renewal_notification_delivery_recipient', 'INDEX idx_renewal_notification_delivery_recipient (recipient_email)');
        try {
            $this->db->exec(
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
        } catch (\Throwable) {
            // Older installs may create mail logs later; the read screen also has a fallback matcher.
        }

        self::$notificationDeliverySchemaEnsured = true;
    }

    private function ensureSupplierQuoteSchema(): void
    {
        if (self::$supplierQuoteSchemaEnsured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS supplier_quote_requests (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS supplier_quote_lines (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS supplier_quote_selections (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS supplier_quote_selection_deliveries (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS supplier_quote_attachments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_id INT UNSIGNED NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                stored_path VARCHAR(255) NOT NULL,
                mime_type VARCHAR(120) NULL,
                file_size INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_supplier_quote_attachments_request FOREIGN KEY (request_id) REFERENCES supplier_quote_requests(id) ON DELETE CASCADE,
                INDEX idx_supplier_quote_attachments_request (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS supplier_unsubscriptions (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$supplierQuoteSchemaEnsured = true;
    }

    private function ensureCustomerOfferSchema(): void
    {
        if (self::$customerOfferSchemaEnsured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS customer_offer_requests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                renewal_id INT UNSIGNED NOT NULL,
                recipient_email VARCHAR(190) NOT NULL,
                recipient_name VARCHAR(190) NULL,
                token_hash CHAR(64) NOT NULL,
                status ENUM('sent', 'opened', 'approved', 'revision_requested', 'rejected', 'expired') NOT NULL DEFAULT 'sent',
                subject VARCHAR(255) NULL,
                message_body TEXT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'TRY',
                subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
                vat_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                total DECIMAL(12,2) NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                opened_at DATETIME NULL,
                view_count INT UNSIGNED NOT NULL DEFAULT 0,
                responded_at DATETIME NULL,
                response_note TEXT NULL,
                response_ip VARCHAR(45) NULL,
                response_user_agent VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_customer_offer_requests_renewal FOREIGN KEY (renewal_id) REFERENCES renewals(id) ON DELETE CASCADE,
                CONSTRAINT fk_customer_offer_requests_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE KEY uq_customer_offer_requests_token (token_hash),
                INDEX idx_customer_offer_requests_renewal (renewal_id, status, created_at),
                INDEX idx_customer_offer_requests_email (recipient_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->ensureColumn('customer_offer_requests', 'view_count', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER opened_at');
        $this->ensureColumn('customer_offer_requests', 'parasut_invoice_id', 'VARCHAR(64) NULL AFTER total');
        $this->ensureColumn('customer_offer_requests', 'parasut_invoice_no', 'VARCHAR(120) NULL AFTER parasut_invoice_id');
        $this->ensureColumn('customer_offer_requests', 'parasut_invoice_status', "VARCHAR(30) NULL AFTER parasut_invoice_no");
        $this->ensureColumn('customer_offer_requests', 'parasut_invoice_error', 'TEXT NULL AFTER parasut_invoice_status');
        $this->ensureColumn('customer_offer_requests', 'parasut_invoice_created_at', 'DATETIME NULL AFTER parasut_invoice_error');
        $this->ensureIndex('customer_offer_requests', 'idx_customer_offer_parasut_invoice', 'INDEX idx_customer_offer_parasut_invoice (parasut_invoice_id)');

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS customer_offer_lines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                offer_id INT UNSIGNED NOT NULL,
                renewal_item_id INT UNSIGNED NULL,
                supplier_quote_line_id INT UNSIGNED NULL,
                item_title VARCHAR(190) NOT NULL,
                quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
                unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                vat_rate DECIMAL(5,2) NOT NULL DEFAULT 20,
                line_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
                line_vat DECIMAL(12,2) NOT NULL DEFAULT 0,
                line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                currency CHAR(3) NOT NULL DEFAULT 'TRY',
                supplier_name VARCHAR(190) NULL,
                supplier_recipient_email VARCHAR(190) NULL,
                supplier_contact_name VARCHAR(190) NULL,
                supplier_term VARCHAR(30) NULL,
                supplier_custom_term VARCHAR(120) NULL,
                supplier_price DECIMAL(12,2) NULL,
                supplier_currency CHAR(3) NULL,
                supplier_vat_included TINYINT(1) NOT NULL DEFAULT 1,
                supplier_delivery_note TEXT NULL,
                supplier_note TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_customer_offer_lines_offer FOREIGN KEY (offer_id) REFERENCES customer_offer_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_customer_offer_lines_item FOREIGN KEY (renewal_item_id) REFERENCES renewal_items(id) ON DELETE SET NULL,
                CONSTRAINT fk_customer_offer_lines_supplier_quote FOREIGN KEY (supplier_quote_line_id) REFERENCES supplier_quote_lines(id) ON DELETE SET NULL,
                INDEX idx_customer_offer_lines_offer (offer_id, sort_order),
                INDEX idx_customer_offer_lines_item (renewal_item_id),
                INDEX idx_customer_offer_lines_supplier_quote (supplier_quote_line_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$customerOfferSchemaEnsured = true;
    }

    private function ensurePhoneNormalization(): void
    {
        if (self::$phoneNormalizationEnsured) {
            return;
        }
        self::$phoneNormalizationEnsured = true;

        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS app_settings (
                    setting_key VARCHAR(120) PRIMARY KEY,
                    setting_value MEDIUMTEXT NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $key = 'maintenance.phone_normalized_v1';
            $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1');
            $stmt->execute(['setting_key' => $key]);
            if ((string) $stmt->fetchColumn() === '1') {
                return;
            }

            $this->normalizeExistingPhoneValues();

            $stmt = $this->db->prepare(
                'INSERT INTO app_settings (setting_key, setting_value, updated_at)
                 VALUES (:setting_key, "1", NOW())
                 ON DUPLICATE KEY UPDATE setting_value = "1", updated_at = NOW()'
            );
            $stmt->execute(['setting_key' => $key]);
        } catch (\Throwable) {
            // Normalization should never block the application if a host limits schema checks.
        }
    }

    private function normalizeExistingPhoneValues(): void
    {
        foreach (['customers', 'customer_contacts', 'suppliers', 'supplier_contacts'] as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $rows = $this->db->query("SELECT id, phone FROM `{$table}` WHERE phone IS NOT NULL AND phone <> ''")
                ->fetchAll();
            $update = $this->db->prepare("UPDATE `{$table}` SET phone = :phone WHERE id = :id");

            foreach ($rows as $row) {
                $normalized = \normalize_phone_number($row['phone'] ?? '');
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

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
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

    private function ensureItemSchema(): void
    {
        if (self::$itemSchemaEnsured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS renewal_items (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->seedExistingRenewalItems();
        $this->standardizeReminderRulesForExistingRenewals();
        self::$itemSchemaEnsured = true;
    }

    private function standardizeReminderRulesForExistingRenewals(): void
    {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS app_settings (
                    setting_key VARCHAR(120) PRIMARY KEY,
                    setting_value MEDIUMTEXT NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $key = 'maintenance.standard_reminder_rules_v1';
            $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1');
            $stmt->execute(['setting_key' => $key]);
            if ((string) $stmt->fetchColumn() === '1') {
                return;
            }

            $this->db->exec('UPDATE renewals SET reminder_days = 30 WHERE reminder_days <> 30');
            $this->db->exec('DELETE FROM renewal_reminder_rules');
            $this->db->exec(
                'INSERT IGNORE INTO renewal_reminder_rules (renewal_id, days_before)
                 SELECT r.id, reminder_days.days_before
                 FROM renewals r
                 JOIN (
                     SELECT 30 AS days_before
                     UNION ALL SELECT 20
                     UNION ALL SELECT 15
                     UNION ALL SELECT 7
                 ) reminder_days'
            );

            $doneStmt = $this->db->prepare(
                'INSERT INTO app_settings (setting_key, setting_value, updated_at)
                 VALUES (:setting_key, "1", NOW())
                 ON DUPLICATE KEY UPDATE setting_value = "1", updated_at = NOW()'
            );
            $doneStmt->execute(['setting_key' => $key]);
        } catch (\Throwable) {
            // Reminder maintenance should not block the application if the host limits writes.
        }
    }

    private function seedExistingRenewalItems(): void
    {
        $stmt = $this->db->query('SELECT COUNT(*) FROM renewal_items');
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $this->db->exec(
            "INSERT INTO renewal_items
                (renewal_id, definition_id, title, brand, kind, license_key, quantity, unit_price, vat_rate, sort_order)
             SELECT
                r.id,
                r.definition_id,
                r.title,
                r.brand,
                r.kind,
                r.license_key,
                1.00,
                r.amount,
                0.00,
                0
             FROM renewals r
             WHERE r.title IS NOT NULL
               AND r.title <> ''"
        );
    }

    private function seedDefaultPaymentMethods(): void
    {
        $defaults = self::defaultPaymentMethods();
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO payment_methods (name, description, sort_order, is_active)
             VALUES (:name, :description, :sort_order, 1)'
        );

        foreach ($defaults as $index => $method) {
            $stmt->execute([
                'name' => $method['name'],
                'description' => $method['description'],
                'sort_order' => ($index + 1) * 10,
            ]);
        }
    }

    public static function defaultPaymentMethods(): array
    {
        return [
            ['name' => 'Kredi kartı', 'description' => 'Güvenli kredi kartı ödeme sayfasına yönlendirir.'],
            ['name' => 'Havale / EFT', 'description' => 'Banka transferi ile ödeme alınır; dekont sonrası işlem tamamlanır.'],
            ['name' => 'Cari hesap', 'description' => 'Ödeme cari hesap mutabakatına göre takip edilir.'],
            ['name' => 'Online ödeme', 'description' => 'Online ödeme kanalı üzerinden tahsilat yapılır.'],
            ['name' => 'Otomatik ödeme', 'description' => 'Tanımlı otomatik ödeme talimatı ile tahsil edilir.'],
            ['name' => 'Nakit', 'description' => 'Nakit ödeme olarak tahsil edilir.'],
        ];
    }

    private static function isRemovedPaymentMethodName(string $name): bool
    {
        $normalized = strtr(mb_strtolower(trim($name)), [
            'ı' => 'i',
            'ğ' => 'g',
            'ü' => 'u',
            'ş' => 's',
            'ö' => 'o',
            'ç' => 'c',
        ]);

        return str_contains($normalized, '30') && str_contains($normalized, 'cari');
    }

    public function notificationRecipients(int $customerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, full_name, email, phone
             FROM customer_contacts
             WHERE customer_id = :customer_id
               AND notify_enabled = 1
               AND email IS NOT NULL
               AND email <> ''
             ORDER BY full_name ASC"
        );
        $stmt->execute(['customer_id' => $customerId]);

        $recipients = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = mb_strtolower((string) $row['email']);
            $recipients[$key] = [
                'contact_id' => (int) $row['id'],
                'name' => (string) $row['full_name'],
                'email' => (string) $row['email'],
                'phone' => (string) ($row['phone'] ?? ''),
            ];
        }

        return array_values($recipients);
    }

    public function supplierNotificationRecipients(?int $supplierId, ?int $supplierGroupId): array
    {
        if (($supplierId ?? 0) < 1 && ($supplierGroupId ?? 0) < 1) {
            return [];
        }

        $where = 's.deleted_at IS NULL
                  AND sc.notify_enabled = 1
                  AND sc.email IS NOT NULL
                  AND sc.email <> \'\'
                  AND NOT EXISTS (
                    SELECT 1
                    FROM supplier_unsubscriptions su
                    WHERE su.recipient_email = LOWER(sc.email)
                      AND (
                        su.scope = \'all\'
                        OR (su.scope = \'group\' AND su.supplier_group_id <=> s.supplier_group_id)
                      )
                  )';
        $params = [];

        if (($supplierId ?? 0) > 0) {
            $where .= ' AND s.id = :supplier_id';
            $params['supplier_id'] = $supplierId;
        } else {
            $where .= ' AND s.supplier_group_id = :supplier_group_id';
            $params['supplier_group_id'] = $supplierGroupId;
        }

        $stmt = $this->db->prepare(
            "SELECT s.id AS supplier_id,
                    s.supplier_group_id,
                    sg.name AS supplier_group_name,
                    sc.id AS contact_id,
                    s.company_name AS supplier_name,
                    sc.full_name,
                    sc.email,
                    sc.phone
             FROM suppliers s
             INNER JOIN supplier_contacts sc ON sc.supplier_id = s.id
             LEFT JOIN supplier_groups sg ON sg.id = s.supplier_group_id
             WHERE {$where}
             ORDER BY s.company_name ASC, sc.full_name ASC"
        );
        $stmt->execute($params);

        $recipients = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = mb_strtolower((string) $row['email']);
            $recipients[$key] = [
                'supplier_id' => (int) ($row['supplier_id'] ?? 0),
                'supplier_group_id' => empty($row['supplier_group_id']) ? null : (int) $row['supplier_group_id'],
                'supplier_group_name' => (string) ($row['supplier_group_name'] ?? ''),
                'contact_id' => (int) ($row['contact_id'] ?? 0),
                'supplier_name' => (string) $row['supplier_name'],
                'name' => (string) $row['full_name'],
                'email' => (string) $row['email'],
                'phone' => (string) ($row['phone'] ?? ''),
            ];
        }

        return array_values($recipients);
    }

    public function supplierCommunicationRecipients(?int $supplierId, ?int $supplierGroupId): array
    {
        if (($supplierId ?? 0) < 1 && ($supplierGroupId ?? 0) < 1) {
            return [];
        }

        $where = 's.deleted_at IS NULL
                  AND sc.notify_enabled = 1
                  AND (
                    (sc.email IS NOT NULL AND sc.email <> \'\')
                    OR (sc.phone IS NOT NULL AND sc.phone <> \'\')
                  )
                  AND (
                    sc.email IS NULL
                    OR sc.email = \'\'
                    OR NOT EXISTS (
                        SELECT 1
                        FROM supplier_unsubscriptions su
                        WHERE su.recipient_email = LOWER(sc.email)
                          AND (
                            su.scope = \'all\'
                            OR (su.scope = \'group\' AND su.supplier_group_id <=> s.supplier_group_id)
                          )
                    )
                  )';
        $params = [];

        if (($supplierId ?? 0) > 0) {
            $where .= ' AND s.id = :supplier_id';
            $params['supplier_id'] = $supplierId;
        } else {
            $where .= ' AND s.supplier_group_id = :supplier_group_id';
            $params['supplier_group_id'] = $supplierGroupId;
        }

        $stmt = $this->db->prepare(
            "SELECT s.id AS supplier_id,
                    s.supplier_group_id,
                    sg.name AS supplier_group_name,
                    s.company_name AS supplier_name,
                    sc.id AS contact_id,
                    sc.full_name,
                    sc.email,
                    sc.phone
             FROM suppliers s
             INNER JOIN supplier_contacts sc ON sc.supplier_id = s.id
             LEFT JOIN supplier_groups sg ON sg.id = s.supplier_group_id
             WHERE {$where}
             ORDER BY s.company_name ASC, sc.full_name ASC"
        );
        $stmt->execute($params);

        $recipients = [];
        foreach ($stmt->fetchAll() as $row) {
            $email = trim(mb_strtolower((string) ($row['email'] ?? '')));
            $phoneDigits = preg_replace('/\D+/', '', (string) ($row['phone'] ?? '')) ?: '';
            $key = $email !== ''
                ? 'email:' . $email
                : 'phone:' . (string) $row['supplier_id'] . ':' . $phoneDigits;
            if ($key === 'phone:' . (string) $row['supplier_id'] . ':' || isset($recipients[$key])) {
                continue;
            }

            $recipients[$key] = [
                'supplier_id' => (int) $row['supplier_id'],
                'supplier_group_id' => empty($row['supplier_group_id']) ? null : (int) $row['supplier_group_id'],
                'supplier_group_name' => (string) ($row['supplier_group_name'] ?? ''),
                'supplier_name' => (string) $row['supplier_name'],
                'contact_id' => (int) $row['contact_id'],
                'name' => (string) $row['full_name'],
                'email' => $email,
                'phone' => (string) ($row['phone'] ?? ''),
            ];
        }

        return array_values($recipients);
    }

    public function createCustomer(array $data): int
    {
        $this->db->beginTransaction();

        try {
            $customerId = $this->saveCustomer($data);
            $this->createCustomerContacts($customerId, $data);
            $this->db->commit();

            return $customerId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function logMail(int $renewalId, string $to, string $subject, string $body, string $status, ?string $error = null, bool $touchReminder = true): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mail_logs (renewal_id, recipient_email, subject, body, status, error_message, sent_at)
             VALUES (:renewal_id, :recipient_email, :subject, :body, :status, :error_message, NOW())'
        );
        $stmt->execute([
            'renewal_id' => $renewalId,
            'recipient_email' => $to,
            'subject' => $subject,
            'body' => $body,
            'status' => $status,
            'error_message' => $error,
        ]);

        if ($status === 'sent' && $touchReminder) {
            $this->db->prepare('UPDATE renewals SET last_notified_at = NOW() WHERE id = :id')
                ->execute(['id' => $renewalId]);
        }

        return (int) $this->db->lastInsertId();
    }

    public function createNotificationDelivery(int $renewalId, array $recipient): array
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO renewal_notification_deliveries
                (renewal_id, recipient_email, recipient_name, token_hash, notification_date, status, created_at, updated_at)
             VALUES
                (:renewal_id, :recipient_email, :recipient_name, :token_hash, CURDATE(), :status, NOW(), NOW())'
        );
        $stmt->execute([
            'renewal_id' => $renewalId,
            'recipient_email' => trim((string) ($recipient['email'] ?? '')),
            'recipient_name' => $this->nullableString($recipient['name'] ?? ''),
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'token' => $token,
            'read_url' => \url('/renewals/' . $renewalId . '/read?token=' . rawurlencode($token)),
        ];
    }

    public function updateNotificationDeliveryStatus(int $deliveryId, ?int $mailLogId, string $status, ?string $error = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE renewal_notification_deliveries
             SET mail_log_id = :mail_log_id,
                 status = :status,
                 error_message = :error_message,
                 sent_at = CASE WHEN :status_sent = \'sent\' THEN COALESCE(sent_at, NOW()) ELSE sent_at END,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'mail_log_id' => $mailLogId ?: null,
            'status' => in_array($status, ['pending', 'sent', 'failed'], true) ? $status : 'pending',
            'status_sent' => $status,
            'error_message' => $this->nullableString($error),
            'id' => $deliveryId,
        ]);
    }

    public function markNotificationDeliveryRead(int $renewalId, string $token, string $ipAddress = '', string $userAgent = ''): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        $hash = hash('sha256', strtolower($token));
        $stmt = $this->db->prepare(
            'UPDATE renewal_notification_deliveries
             SET read_at = COALESCE(read_at, NOW()),
                 read_ip = :read_ip,
                 read_user_agent = :read_user_agent,
                 updated_at = NOW()
             WHERE renewal_id = :renewal_id
               AND token_hash = :token_hash'
        );
        $stmt->execute([
            'read_ip' => $this->nullableString(substr($ipAddress, 0, 45)),
            'read_user_agent' => $this->nullableString(substr($userAgent, 0, 255)),
            'renewal_id' => $renewalId,
            'token_hash' => $hash,
        ]);

        $select = $this->db->prepare(
            "SELECT rnd.*,
                    r.title,
                    COALESCE(ri_stats.items_summary, r.title) AS item_summary,
                    c.company_name
             FROM renewal_notification_deliveries rnd
             INNER JOIN renewals r ON r.id = rnd.renewal_id
             INNER JOIN customers c ON c.id = r.customer_id
             LEFT JOIN (
                SELECT renewal_id,
                       GROUP_CONCAT(title ORDER BY sort_order ASC, id ASC SEPARATOR ', ') AS items_summary
                FROM renewal_items
                GROUP BY renewal_id
             ) ri_stats ON ri_stats.renewal_id = r.id
             WHERE rnd.renewal_id = :renewal_id
               AND rnd.token_hash = :token_hash
             LIMIT 1"
        );
        $select->execute([
            'renewal_id' => $renewalId,
            'token_hash' => $hash,
        ]);
        $row = $select->fetch();
        if ($row) {
            $this->db->prepare(
                'INSERT INTO renewal_notification_reads (renewal_id, read_on, read_at)
                 VALUES (:renewal_id, CURDATE(), NOW())
                 ON DUPLICATE KEY UPDATE read_at = NOW()'
            )->execute(['renewal_id' => $renewalId]);
        }

        return $row ?: null;
    }

    public function markSupplierPriceRequested(int $renewalId): void
    {
        $this->db->prepare('UPDATE renewals SET supplier_price_requested_at = NOW(), updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $renewalId]);
    }

    public function acknowledgeReminder(int $renewalId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO renewal_notification_reads (renewal_id, read_on, read_at)
             VALUES (:renewal_id, CURDATE(), NOW())
             ON DUPLICATE KEY UPDATE read_at = NOW()'
        );
        $stmt->execute(['renewal_id' => $renewalId]);
    }

    private function normalize(array $data): array
    {
        $reminderDays = $this->submittedReminderDays($data);
        $items = $this->submittedItems($data);
        $firstItem = $items[0] ?? [];
        $definition = !empty($firstItem['definition_id'])
            ? $this->definitionById((int) $firstItem['definition_id'])
            : $this->definitionFromData($data);
        $period = $this->periodFromData($data);
        $startDate = ($data['start_date'] ?? '') ?: date('Y-m-d');
        $renewalDate = $period
            ? $this->calculateRenewalDate($startDate, $period)
            : (($data['renewal_date'] ?? '') ?: $startDate);
        $kind = (string) ($firstItem['kind'] ?? ($definition['kind'] ?? 'service'));
        if (!in_array($kind, ['product', 'service'], true)) {
            $kind = 'service';
        }
        $hasProduct = false;
        foreach ($items as $item) {
            if (($item['kind'] ?? '') === 'product') {
                $hasProduct = true;
                break;
            }
        }
        $supplierPriceEnabled = $hasProduct && !empty($data['supplier_price_request_enabled']);
        $paymentMethod = trim((string) ($data['payment_method'] ?? ''));
        if (self::isRemovedPaymentMethodName($paymentMethod)) {
            $paymentMethod = '';
        }
        $total = $this->itemsTotal($items);
        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, ['active', 'renewed', 'cancelled'], true)) {
            $status = 'active';
        }

        return [
            'customer_id' => (int) $data['customer_id'],
            'supplier_id' => empty($data['supplier_id']) ? null : (int) $data['supplier_id'],
            'supplier_group_id' => empty($data['supplier_group_id']) ? null : (int) $data['supplier_group_id'],
            'definition_id' => !empty($firstItem['definition_id']) ? (int) $firstItem['definition_id'] : ($definition ? (int) $definition['id'] : null),
            'renewal_period_id' => $period ? (int) $period['id'] : null,
            'title' => (string) ($firstItem['title'] ?? ($definition ? (string) $definition['name'] : trim((string) ($data['title'] ?? '')))),
            'brand' => trim((string) ($firstItem['brand'] ?? ($data['brand'] ?? ''))),
            'kind' => $kind,
            'supplier' => trim((string) ($data['supplier'] ?? '')),
            'license_key' => trim((string) ($firstItem['license_key'] ?? ($data['license_key'] ?? ''))),
            'payment_method' => $paymentMethod,
            'payment_customer_choice' => $paymentMethod !== '' ? 0 : (!empty($data['payment_customer_choice']) ? 1 : 0),
            'start_date' => $startDate,
            'renewal_date' => $renewalDate,
            'reminder_days' => max($reminderDays),
            'supplier_price_request_enabled' => $supplierPriceEnabled ? 1 : 0,
            'supplier_price_request_days' => $supplierPriceEnabled ? max(1, (int) ($data['supplier_price_request_days'] ?? 30)) : null,
            'supplier_share_customer_info' => !empty($data['supplier_share_customer_info']) ? 1 : 0,
            'amount' => $total > 0 ? $total : null,
            'currency' => self::normalizeCurrency($data['currency'] ?? 'TRY'),
            'status' => $status,
            'notes' => trim((string) ($data['notes'] ?? '')),
        ];
    }

    private function definitionFromData(array $data): ?array
    {
        $id = (int) ($data['definition_id'] ?? 0);
        if ($id < 1) {
            return null;
        }

        return $this->definitionById($id);
    }

    private function definitionById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM renewal_definitions WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => $id]);
        $definition = $stmt->fetch();

        return $definition ?: null;
    }

    public function submittedItems(array $data): array
    {
        $rows = $data['items'] ?? null;
        if (!is_array($rows)) {
            $rows = [[
                'definition_id' => $data['definition_id'] ?? '',
                'title' => $data['title'] ?? '',
                'kind' => $data['kind'] ?? 'service',
                'brand' => $data['brand'] ?? '',
                'license_key' => $data['license_key'] ?? '',
                'quantity' => $data['quantity'] ?? 1,
                'unit_price' => $data['amount'] ?? '',
                'vat_rate' => $data['vat_rate'] ?? 20,
            ]];
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $definition = $this->definitionById((int) ($row['definition_id'] ?? 0));
            $title = $definition ? (string) $definition['name'] : trim((string) ($row['title'] ?? ''));
            $kind = $definition
                ? (string) $definition['kind']
                : (in_array(($row['kind'] ?? 'product'), ['product', 'service'], true) ? (string) $row['kind'] : 'product');
            $brand = trim((string) ($row['brand'] ?? ''));
            $licenseKey = trim((string) ($row['license_key'] ?? ''));
            $quantity = max(0.01, $this->decimalValue($row['quantity'] ?? 1, 1.0));
            $unitPrice = $this->nullableDecimalValue($row['unit_price'] ?? null);
            $vatRate = max(0.0, min(100.0, $this->decimalValue($row['vat_rate'] ?? 20, 20.0)));

            if ($title === '' && $brand === '' && $licenseKey === '' && ($unitPrice ?? 0.0) <= 0.0) {
                continue;
            }

            $items[] = [
                'definition_id' => $definition ? (int) $definition['id'] : null,
                'title' => $title !== '' ? $title : 'Ürün / hizmet',
                'brand' => $brand,
                'kind' => $kind,
                'license_key' => $licenseKey,
                'quantity' => round($quantity, 2),
                'unit_price' => $unitPrice,
                'vat_rate' => round($vatRate, 2),
            ];
        }

        if ($items === []) {
            $items[] = [
                'definition_id' => null,
                'title' => trim((string) ($data['title'] ?? 'Ürün / hizmet')) ?: 'Ürün / hizmet',
                'brand' => trim((string) ($data['brand'] ?? '')),
                'kind' => in_array(($data['kind'] ?? 'service'), ['product', 'service'], true) ? (string) $data['kind'] : 'service',
                'license_key' => trim((string) ($data['license_key'] ?? '')),
                'quantity' => 1.0,
                'unit_price' => null,
                'vat_rate' => 20.0,
            ];
        }

        return array_values($items);
    }

    private function periodFromData(array $data): ?array
    {
        $id = (int) ($data['renewal_period_id'] ?? 0);
        if ($id < 1) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM renewal_periods WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => $id]);
        $period = $stmt->fetch();

        return $period ?: null;
    }

    private function nextRenewalDateFromPeriod(int $renewalId): string
    {
        return $this->nextRenewalPeriodFromCurrent($renewalId)[1];
    }

    private function nextRenewalPeriodFromCurrent(int $renewalId, ?string $overrideEndDate = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.renewal_date AS current_renewal_date, rp.*
             FROM renewals r
             INNER JOIN renewal_periods rp ON rp.id = r.renewal_period_id
             WHERE r.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $renewalId]);
        $period = $stmt->fetch();

        if (!$period) {
            throw new \RuntimeException('Bu kayit icin yenileme periyodu tanimli degil.');
        }

        $periodStartDate = $this->normalizeDate((string) ($period['current_renewal_date'] ?? '')) ?? date('Y-m-d');
        $periodEndDate = null;

        if ($overrideEndDate !== null && trim($overrideEndDate) !== '') {
            $periodEndDate = $this->normalizeDate($overrideEndDate);
            if ($periodEndDate === null) {
                throw new \RuntimeException('Yeni dönem tarihi geçersiz.');
            }
        }

        if ($periodEndDate === null || $periodEndDate <= $periodStartDate) {
            $periodEndDate = $this->calculateRenewalDate($periodStartDate, $period);
        }

        return [$periodStartDate, $periodEndDate];
    }

    public function previewNextRenewalDate(int $renewalId, ?string $startDate = null): string
    {
        if ($startDate === null || trim($startDate) === '') {
            return $this->nextRenewalPeriodFromCurrent($renewalId)[1];
        }

        $stmt = $this->db->prepare(
            'SELECT rp.*
             FROM renewals r
             INNER JOIN renewal_periods rp ON rp.id = r.renewal_period_id
             WHERE r.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $renewalId]);
        $period = $stmt->fetch();

        if (!$period) {
            throw new \RuntimeException('Bu kayıt için yenileme periyodu tanımlı değil.');
        }

        $normalizedStartDate = $this->normalizeDate($startDate) ?? date('Y-m-d');

        return $this->calculateRenewalDate($normalizedStartDate, $period);
    }

    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($date))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function calculateRenewalDate(string $startDate, array $period): string
    {
        $count = max(1, (int) $period['interval_count']);
        $unit = in_array(($period['interval_unit'] ?? 'year'), ['day', 'month', 'year'], true) ? $period['interval_unit'] : 'year';

        return (new \DateTimeImmutable($startDate))
            ->modify('+' . $count . ' ' . $unit)
            ->format('Y-m-d');
    }

    private function supplierQuoteTermPrice(array $line, string $term): ?float
    {
        $column = match ($term) {
            'cash' => 'price_cash',
            '30' => 'price_30',
            '60' => 'price_60',
            'check' => 'price_check',
            'custom' => 'price_custom',
            default => '',
        };

        if ($column === '' || $line[$column] === null || $line[$column] === '') {
            return null;
        }

        return round((float) $line[$column], 2);
    }

    private function saveCurrentInvoicePeriod(int $renewalId, array $data): void
    {
        $params = $this->normalize($data);
        $this->saveInvoicePeriod(
            $renewalId,
            (string) $params['start_date'],
            (string) $params['renewal_date'],
            trim((string) ($data['invoice_number'] ?? ''))
        );
    }

    private function saveCurrentInvoicePeriodIfProvided(int $renewalId, array $data): void
    {
        if (trim((string) ($data['invoice_number'] ?? '')) === '') {
            return;
        }

        $this->saveCurrentInvoicePeriod($renewalId, $data);
    }

    private function saveInvoicePeriod(int $renewalId, string $periodStartDate, string $periodEndDate, string $invoiceNumber): void
    {
        $invoiceNumber = trim($invoiceNumber);
        if ($invoiceNumber === '') {
            throw new \RuntimeException('Fatura numarası zorunlu.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO renewal_invoice_periods
                (renewal_id, period_start_date, period_end_date, invoice_number, updated_at)
             VALUES
                (:renewal_id, :period_start_date, :period_end_date, :invoice_number, NOW())
             ON DUPLICATE KEY UPDATE invoice_number = VALUES(invoice_number), updated_at = NOW()'
        );
        $stmt->execute([
            'renewal_id' => $renewalId,
            'period_start_date' => $periodStartDate,
            'period_end_date' => $periodEndDate,
            'invoice_number' => $invoiceNumber,
        ]);
    }

    private function periodName(int $count, string $unit): string
    {
        return match ($unit) {
            'day' => $count . ' Gun',
            'month' => $count . ' Ay',
            'year' => $count . ' Yil',
            default => $count . ' Periyot',
        };
    }

    public function submittedReminderDays(array $data): array
    {
        $rows = $data['reminder_rules'] ?? null;
        if (!is_array($rows)) {
            $rows = self::STANDARD_REMINDER_DAYS;
        }

        $days = [];
        foreach ($rows as $row) {
            $day = max(0, (int) $row);
            if ($day > 0) {
                $days[$day] = $day;
            }
        }

        foreach (self::STANDARD_REMINDER_DAYS as $standardDay) {
            $days[$standardDay] = $standardDay;
        }

        rsort($days, SORT_NUMERIC);

        return $days ?: [7];
    }

    private function replaceReminderRules(int $renewalId, array $data): void
    {
        $this->db->prepare('DELETE FROM renewal_reminder_rules WHERE renewal_id = :renewal_id')
            ->execute(['renewal_id' => $renewalId]);

        $stmt = $this->db->prepare(
            'INSERT INTO renewal_reminder_rules (renewal_id, days_before)
             VALUES (:renewal_id, :days_before)'
        );

        foreach ($this->submittedReminderDays($data) as $day) {
            $stmt->execute([
                'renewal_id' => $renewalId,
                'days_before' => $day,
            ]);
        }
    }

    private function replaceItems(int $renewalId, array $data): void
    {
        $this->db->prepare('DELETE FROM renewal_items WHERE renewal_id = :renewal_id')
            ->execute(['renewal_id' => $renewalId]);

        $stmt = $this->db->prepare(
            'INSERT INTO renewal_items
                (renewal_id, definition_id, title, brand, kind, license_key, quantity, unit_price, vat_rate, sort_order)
             VALUES
                (:renewal_id, :definition_id, :title, :brand, :kind, :license_key, :quantity, :unit_price, :vat_rate, :sort_order)'
        );

        foreach ($this->submittedItems($data) as $index => $item) {
            $stmt->execute([
                'renewal_id' => $renewalId,
                'definition_id' => $item['definition_id'],
                'title' => $item['title'],
                'brand' => $this->nullableString($item['brand'] ?? ''),
                'kind' => $item['kind'],
                'license_key' => $this->nullableString($item['license_key'] ?? ''),
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'vat_rate' => $item['vat_rate'],
                'sort_order' => $index * 10,
            ]);
        }
    }

    private function itemsTotal(array $items): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            $unitPrice = $item['unit_price'];
            if ($unitPrice === null) {
                continue;
            }
            $net = (float) $item['quantity'] * (float) $unitPrice;
            $total += $net + ($net * ((float) $item['vat_rate'] / 100));
        }

        return round($total, 2);
    }

    private function normalizeCustomerOfferLines(mixed $rows, string $currency): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $lines = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['item_title'] ?? ''));
            $quantity = max(0.01, $this->decimalValue($row['quantity'] ?? 1, 1.0));
            $unitPrice = max(0.0, $this->decimalValue($row['unit_price'] ?? 0, 0.0));
            $vatRate = max(0.0, min(100.0, $this->decimalValue($row['vat_rate'] ?? 20, 20.0)));
            if ($title === '' || $unitPrice <= 0) {
                continue;
            }

            $subtotal = round($quantity * $unitPrice, 2);
            $vat = round($subtotal * ($vatRate / 100), 2);
            $total = round($subtotal + $vat, 2);
            $supplierPrice = $this->nullableDecimalValue($row['supplier_price'] ?? null);

            $lines[] = [
                'renewal_item_id' => empty($row['renewal_item_id']) ? null : (int) $row['renewal_item_id'],
                'supplier_quote_line_id' => empty($row['supplier_quote_line_id']) ? null : (int) $row['supplier_quote_line_id'],
                'item_title' => mb_substr($title, 0, 190),
                'quantity' => round($quantity, 2),
                'unit_price' => round($unitPrice, 2),
                'vat_rate' => round($vatRate, 2),
                'line_subtotal' => $subtotal,
                'line_vat' => $vat,
                'line_total' => $total,
                'currency' => $currency,
                'supplier_name' => $this->nullableString($row['supplier_name'] ?? ''),
                'supplier_recipient_email' => $this->nullableString($row['supplier_recipient_email'] ?? ''),
                'supplier_contact_name' => $this->nullableString($row['supplier_contact_name'] ?? ''),
                'supplier_term' => $this->nullableString($row['supplier_term'] ?? ''),
                'supplier_custom_term' => $this->nullableString($row['supplier_custom_term'] ?? ''),
                'supplier_price' => $supplierPrice,
                'supplier_currency' => $this->nullableString(self::normalizeCurrency($row['supplier_currency'] ?? $currency)),
                'supplier_vat_included' => !empty($row['supplier_vat_included']) ? 1 : 0,
                'supplier_delivery_note' => $this->nullableString($row['supplier_delivery_note'] ?? ''),
                'supplier_note' => $this->nullableString($row['supplier_note'] ?? ''),
            ];
        }

        return $lines;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function normalizeCurrency(mixed $currency): string
    {
        $currency = strtoupper(trim((string) $currency));

        return in_array($currency, ['TRY', 'USD', 'EUR'], true) ? $currency : 'TRY';
    }

    private function nullableDecimalValue(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return max(0.0, $this->decimalValue($value, 0.0));
    }

    private function decimalValue(mixed $value, float $default): float
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if ($normalized === '' || !is_numeric($normalized)) {
            return $default;
        }

        return (float) $normalized;
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

    private function createCustomerContacts(int $customerId, array $data): void
    {
        $contacts = $this->submittedContacts($data);
        if ($contacts === []) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO customer_contacts (customer_id, full_name, email, phone, notify_enabled)
             VALUES (:customer_id, :full_name, :email, :phone, :notify_enabled)'
        );

        foreach ($contacts as $contact) {
            $stmt->execute([
                'customer_id' => $customerId,
                'full_name' => $contact['full_name'],
                'email' => $this->nullableString($contact['email']),
                'phone' => $this->nullableString(\normalize_phone_number($contact['phone'])),
                'notify_enabled' => $contact['notify_enabled'],
            ]);
        }
    }

    private function replaceCustomerContacts(int $customerId, array $data): void
    {
        $stmt = $this->db->prepare('DELETE FROM customer_contacts WHERE customer_id = :customer_id');
        $stmt->execute(['customer_id' => $customerId]);
        $this->createCustomerContacts($customerId, $data);
    }

    private function saveCustomer(array $data, ?int $id = null): int
    {
        $params = [
            'parasut_contact_id' => $this->nullableString($data['parasut_contact_id'] ?? ''),
            'company_name' => trim((string) $data['company_name']),
            'contact_name' => trim((string) ($data['contact_name'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'phone' => \normalize_phone_number($data['phone'] ?? ''),
            'tax_office' => trim((string) ($data['tax_office'] ?? '')),
            'tax_number' => trim((string) ($data['tax_number'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'district' => trim((string) ($data['district'] ?? '')),
            'address' => trim((string) ($data['address'] ?? '')),
            'notes' => trim((string) ($data['notes'] ?? '')),
        ];

        if ($id === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO customers
                    (parasut_contact_id, company_name, contact_name, email, phone, tax_office, tax_number, city, district, address, notes)
                 VALUES
                    (:parasut_contact_id, :company_name, :contact_name, :email, :phone, :tax_office, :tax_number, :city, :district, :address, :notes)'
            );
            $stmt->execute($params);

            return (int) $this->db->lastInsertId();
        }

        $params['id'] = $id;
        $stmt = $this->db->prepare(
            'UPDATE customers SET
                parasut_contact_id = :parasut_contact_id,
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
        );
        $stmt->execute($params);

        return $id;
    }

    private function attachSupplierContacts(array $suppliers): array
    {
        $ids = array_map(static fn (array $supplier): int => (int) $supplier['id'], $suppliers);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT *
             FROM supplier_contacts
             WHERE supplier_id IN ({$placeholders})
             ORDER BY notify_enabled DESC, full_name ASC"
        );
        $stmt->execute($ids);

        $contacts = [];
        foreach ($stmt->fetchAll() as $contact) {
            $contacts[(int) $contact['supplier_id']][] = $contact;
        }

        foreach ($suppliers as &$supplier) {
            $supplier['contacts'] = $contacts[(int) $supplier['id']] ?? [];
        }
        unset($supplier);

        return $suppliers;
    }

    private function createSupplierContacts(int $supplierId, array $data): void
    {
        $contacts = $this->submittedContacts($data);
        if ($contacts === []) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO supplier_contacts (supplier_id, full_name, email, phone, notify_enabled)
             VALUES (:supplier_id, :full_name, :email, :phone, :notify_enabled)'
        );

        foreach ($contacts as $contact) {
            $stmt->execute([
                'supplier_id' => $supplierId,
                'full_name' => $contact['full_name'],
                'email' => $this->nullableString($contact['email']),
                'phone' => $this->nullableString(\normalize_phone_number($contact['phone'])),
                'notify_enabled' => $contact['notify_enabled'],
            ]);
        }
    }

    private function replaceSupplierContacts(int $supplierId, array $data): void
    {
        $stmt = $this->db->prepare('DELETE FROM supplier_contacts WHERE supplier_id = :supplier_id');
        $stmt->execute(['supplier_id' => $supplierId]);
        $this->createSupplierContacts($supplierId, $data);
    }

    private function saveSupplier(array $data, ?int $id = null): int
    {
        $params = [
            'parasut_contact_id' => $this->nullableString($data['parasut_contact_id'] ?? ''),
            'supplier_group_id' => empty($data['supplier_group_id']) ? null : (int) $data['supplier_group_id'],
            'company_name' => trim((string) $data['company_name']),
            'contact_name' => trim((string) ($data['contact_name'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'phone' => \normalize_phone_number($data['phone'] ?? ''),
            'tax_office' => trim((string) ($data['tax_office'] ?? '')),
            'tax_number' => trim((string) ($data['tax_number'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'district' => trim((string) ($data['district'] ?? '')),
            'address' => trim((string) ($data['address'] ?? '')),
            'notes' => trim((string) ($data['notes'] ?? '')),
        ];

        if ($id === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO suppliers
                    (parasut_contact_id, supplier_group_id, company_name, contact_name, email, phone, tax_office, tax_number, city, district, address, notes)
                 VALUES
                    (:parasut_contact_id, :supplier_group_id, :company_name, :contact_name, :email, :phone, :tax_office, :tax_number, :city, :district, :address, :notes)'
            );
            $stmt->execute($params);

            return (int) $this->db->lastInsertId();
        }

        $params['id'] = $id;
        $stmt = $this->db->prepare(
            'UPDATE suppliers SET
                parasut_contact_id = :parasut_contact_id,
                supplier_group_id = :supplier_group_id,
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
        );
        $stmt->execute($params);

        return $id;
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

    private function baseSelect(): string
    {
        return "SELECT
                r.*,
                rd.name AS definition_name,
                rd.notification_info AS definition_notification_info,
                rp.name AS renewal_period_name,
                rp.interval_count AS renewal_period_count,
                rp.interval_unit AS renewal_period_unit,
                r.supplier_share_customer_info,
                COALESCE(ri_stats.item_count, 0) AS item_count,
                COALESCE(ri_stats.items_summary, r.title) AS item_summary,
                COALESCE(ri_stats.first_title, r.title) AS first_item_title,
                COALESCE(ri_stats.item_subtotal, r.amount) AS item_subtotal,
                COALESCE(ri_stats.item_vat_total, 0) AS item_vat_total,
                COALESCE(ri_stats.item_total, r.amount) AS item_total,
                rip.invoice_number AS current_invoice_number,
                COALESCE(
                    rip.invoice_number,
                    (
                        SELECT rip_last.invoice_number
                        FROM renewal_invoice_periods rip_last
                        WHERE rip_last.renewal_id = r.id
                        ORDER BY rip_last.period_end_date DESC, rip_last.id DESC
                        LIMIT 1
                    )
                ) AS previous_invoice_number,
                sg.name AS supplier_group_name,
                c.company_name,
                c.contact_name,
                c.email AS customer_email,
                c.phone AS customer_phone,
                c.tax_number AS customer_tax_number,
                c.address AS customer_address,
                c.city AS customer_city,
                c.district AS customer_district,
                s.company_name AS supplier_company_name,
                COALESCE(s.company_name, sg.name, r.supplier) AS supplier_display,
                EXISTS (
                    SELECT 1
                    FROM renewal_payments rp_paid
                    WHERE rp_paid.renewal_id = r.id
                      AND rp_paid.status = 'paid'
                      AND COALESCE(rp_paid.payment_id, '') <> ''
                ) AS has_paid_card_payment,
                (
                    SELECT rp_latest.status
                    FROM renewal_payments rp_latest
                    WHERE rp_latest.renewal_id = r.id
                    ORDER BY rp_latest.created_at DESC, rp_latest.id DESC
                    LIMIT 1
                ) AS latest_card_payment_status,
                (
                    SELECT rp_latest.created_at
                    FROM renewal_payments rp_latest
                    WHERE rp_latest.renewal_id = r.id
                    ORDER BY rp_latest.created_at DESC, rp_latest.id DESC
                    LIMIT 1
                ) AS latest_card_payment_at,
                EXISTS (
                    SELECT 1
                    FROM renewal_notification_reads rnr
                    WHERE rnr.renewal_id = r.id
                      AND rnr.read_on = CURDATE()
                ) AS reminder_acknowledged_today,
                (
                    SELECT COUNT(DISTINCT LOWER(cc_notify.email))
                    FROM customer_contacts cc_notify
                    WHERE cc_notify.customer_id = r.customer_id
                      AND cc_notify.notify_enabled = 1
                      AND cc_notify.email IS NOT NULL
                      AND cc_notify.email <> ''
                ) AS notification_recipient_count,
                (
                    SELECT COUNT(DISTINCT LOWER(rnd_sent.recipient_email))
                    FROM renewal_notification_deliveries rnd_sent
                    WHERE rnd_sent.renewal_id = r.id
                      AND rnd_sent.status <> 'failed'
                      AND rnd_sent.notification_date = (
                          SELECT MAX(rnd_latest.notification_date)
                          FROM renewal_notification_deliveries rnd_latest
                          WHERE rnd_latest.renewal_id = r.id
                            AND rnd_latest.status <> 'failed'
                      )
                ) AS notification_sent_count,
                (
                    SELECT COUNT(DISTINCT LOWER(rnd_read.recipient_email))
                    FROM renewal_notification_deliveries rnd_read
                    WHERE rnd_read.renewal_id = r.id
                      AND rnd_read.status <> 'failed'
                      AND rnd_read.read_at IS NOT NULL
                      AND rnd_read.notification_date = (
                          SELECT MAX(rnd_latest.notification_date)
                          FROM renewal_notification_deliveries rnd_latest
                          WHERE rnd_latest.renewal_id = r.id
                            AND rnd_latest.status <> 'failed'
                      )
                ) AS notification_read_count,
                (
                    SELECT GROUP_CONCAT(
                        CONCAT(COALESCE(NULLIF(rnd_reader.recipient_name, ''), rnd_reader.recipient_email), ' - ', DATE_FORMAT(rnd_reader.read_at, '%d.%m.%Y %H:%i'))
                        ORDER BY rnd_reader.read_at DESC
                        SEPARATOR ', '
                    )
                    FROM renewal_notification_deliveries rnd_reader
                    WHERE rnd_reader.renewal_id = r.id
                      AND rnd_reader.status <> 'failed'
                      AND rnd_reader.read_at IS NOT NULL
                      AND rnd_reader.notification_date = (
                          SELECT MAX(rnd_latest.notification_date)
                          FROM renewal_notification_deliveries rnd_latest
                          WHERE rnd_latest.renewal_id = r.id
                            AND rnd_latest.status <> 'failed'
                      )
                ) AS notification_readers,
                (
                    SELECT GROUP_CONCAT(rrr.days_before ORDER BY rrr.days_before DESC SEPARATOR ',')
                    FROM renewal_reminder_rules rrr
                    WHERE rrr.renewal_id = r.id
                ) AS reminder_rule_days,
                CASE
                    WHEN r.status = 'active' AND r.renewal_date < CURDATE() THEN 'overdue'
                    WHEN r.status = 'active' AND r.renewal_date >= CURDATE() AND DATEDIFF(r.renewal_date, CURDATE()) <= 60 THEN 'due_soon'
                    ELSE r.status
                END AS computed_state
            FROM renewals r
            LEFT JOIN (
                SELECT
                    renewal_id,
                    COUNT(*) AS item_count,
                    SUBSTRING_INDEX(GROUP_CONCAT(title ORDER BY sort_order ASC, id ASC SEPARATOR ', '), ', ', 1) AS first_title,
                    CASE
                        WHEN COUNT(*) > 1 THEN CONCAT(SUBSTRING_INDEX(GROUP_CONCAT(title ORDER BY sort_order ASC, id ASC SEPARATOR ', '), ', ', 1), ' + ', COUNT(*) - 1, ' ürün')
                        ELSE SUBSTRING_INDEX(GROUP_CONCAT(title ORDER BY sort_order ASC, id ASC SEPARATOR ', '), ', ', 1)
                    END AS items_summary,
                    ROUND(SUM(quantity * COALESCE(unit_price, 0)), 2) AS item_subtotal,
                    ROUND(SUM(quantity * COALESCE(unit_price, 0) * (vat_rate / 100)), 2) AS item_vat_total,
                    ROUND(SUM(quantity * COALESCE(unit_price, 0) * (1 + vat_rate / 100)), 2) AS item_total
                FROM renewal_items
                GROUP BY renewal_id
            ) ri_stats ON ri_stats.renewal_id = r.id
            LEFT JOIN renewal_definitions rd ON rd.id = r.definition_id
            LEFT JOIN renewal_periods rp ON rp.id = r.renewal_period_id
            LEFT JOIN renewal_invoice_periods rip
                ON rip.renewal_id = r.id
               AND rip.period_start_date = r.start_date
               AND rip.period_end_date = r.renewal_date
            INNER JOIN customers c ON c.id = r.customer_id AND c.deleted_at IS NULL
            LEFT JOIN suppliers s ON s.id = r.supplier_id AND s.deleted_at IS NULL
            LEFT JOIN supplier_groups sg ON sg.id = r.supplier_group_id";
    }

    private function countValue(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function groupCounts(string $sql): array
    {
        $counts = [];
        foreach ($this->db->query($sql)->fetchAll() as $row) {
            $counts[(string) ($row['status'] ?? '')] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    private function supplierSelect(): string
    {
        return 'SELECT s.*, sg.name AS supplier_group_name
                FROM suppliers s
                LEFT JOIN supplier_groups sg ON sg.id = s.supplier_group_id';
    }
}
