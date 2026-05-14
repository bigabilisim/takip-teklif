<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class ParasutClient
{
    private array $config;
    private string $tokenPath;
    private string $contactCachePath;

    public function __construct()
    {
        $this->config = \app_config('parasut', []);
        $this->tokenPath = (string) ($this->config['token_path'] ?? ROOT_PATH . '/storage/parasut_token.json');
        $this->contactCachePath = (string) ($this->config['contact_cache_path'] ?? ROOT_PATH . '/storage/parasut_contacts_cache.json');
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['client_id']) && !empty($this->config['client_secret']);
    }

    public function authorizationUrl(): string
    {
        return $this->baseUrl() . '/oauth/authorize?' . http_build_query([
            'client_id' => $this->config['client_id'] ?? '',
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
        ]);
    }

    public function status(): array
    {
        $token = $this->readToken();

        return [
            'configured' => $this->isConfigured(),
            'connected' => !empty($token['refresh_token']) || !empty($token['access_token']),
            'company_id' => (string) ($token['company_id'] ?? ''),
            'expires_at' => isset($token['expires_at']) ? (int) $token['expires_at'] : null,
        ];
    }

    public function exchangeCode(string $code, string $companyId): void
    {
        $code = trim($code);
        $companyId = trim($companyId);

        if (!$this->isConfigured()) {
            throw new RuntimeException('Parasut API anahtarlari tanimli degil.');
        }

        if ($code === '' || $companyId === '') {
            throw new RuntimeException('Firma ID ve onay kodu zorunlu.');
        }

        $token = $this->postToken([
            'grant_type' => 'authorization_code',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);

        $token['company_id'] = $companyId;
        $this->writeToken($this->normalizeToken($token));
        $this->clearContactCache();
    }

    public function disconnect(): void
    {
        if (is_file($this->tokenPath)) {
            unlink($this->tokenPath);
        }
        $this->clearContactCache();
    }

    public function searchContacts(string $query, int $limit = 10, ?string $accountType = null): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $token = $this->readToken();
        $companyId = (string) ($token['company_id'] ?? '');
        if ($companyId === '') {
            throw new RuntimeException('Parasut firma ID tanimli degil.');
        }

        $limit = min(max($limit, 1), 25);
        $accountType = in_array($accountType, ['customer', 'supplier'], true) ? $accountType : null;
        $contacts = $this->filterCachedContacts($this->cachedContacts($companyId), $query, $limit, $accountType);

        foreach ($this->contactSearchFilters($query) as $filter) {
            try {
                $remote = $this->remoteContactSearch($companyId, $filter, $query, $limit, $accountType);
            } catch (RuntimeException $e) {
                if ($contacts !== []) {
                    return array_slice($contacts, 0, $limit);
                }

                throw $e;
            }

            $contacts = $this->mergeContacts($contacts, $remote);

            if (count($contacts) >= $limit) {
                break;
            }
        }

        return array_slice($contacts, 0, $limit);
    }

    public function refreshContactCache(): array
    {
        $token = $this->readToken();
        $companyId = (string) ($token['company_id'] ?? '');
        if ($companyId === '') {
            throw new RuntimeException('Parasut firma ID tanimli degil.');
        }

        $contacts = $this->fetchAllContacts($companyId);
        if ($contacts === []) {
            throw new RuntimeException('Parasut cari listesi alinamadi.');
        }

        $this->writeContactCache($companyId, $contacts);

        return [
            'company_id' => $companyId,
            'count' => count($contacts),
            'generated_at' => time(),
        ];
    }

    public function createCustomerContact(array $customer): array
    {
        return $this->createContactFromLocalRecord($customer, 'customer');
    }

    private function createContactFromLocalRecord(array $record, string $accountType): array
    {
        $companyId = $this->companyId();
        $accountType = $accountType === 'supplier' ? 'supplier' : 'customer';
        $payload = [
            'data' => [
                'type' => 'contacts',
                'attributes' => $this->contactAttributesFromLocalRecord($record, $accountType),
            ],
        ];

        $contactPeople = $this->contactPeopleFromLocalRecord($record);
        if ($contactPeople !== []) {
            $payload['data']['relationships'] = [
                'contact_people' => [
                    'data' => $contactPeople,
                ],
            ];
        }

        $created = $this->request('POST', sprintf('/v4/%s/contacts', rawurlencode($companyId)), $payload);
        $contact = $this->mapContacts([$created['data'] ?? []])[0] ?? [];
        if (trim((string) ($contact['id'] ?? '')) === '') {
            throw new RuntimeException('Paraşüt cari oluşturdu ancak cari ID dönmedi.');
        }

        $this->clearContactCache();

        return $contact;
    }

    private function contactAttributesFromLocalRecord(array $record, string $accountType): array
    {
        $name = trim((string) (($record['company_name'] ?? '') ?: ($record['name'] ?? '') ?: ($record['contact_name'] ?? '')));
        if ($name === '') {
            throw new RuntimeException('Paraşüt carisi oluşturmak için firma adı zorunlu.');
        }

        $taxNumber = preg_replace('/\D+/', '', (string) ($record['tax_number'] ?? '')) ?? '';
        $contactType = strlen($taxNumber) === 11 && !preg_match('/(A\.?Ş|ANONİM|LTD|LİMİTED|LIMITED|ŞİRKET|SANAYİ|TİCARET|AŞ)/iu', $name)
            ? 'person'
            : 'company';
        $email = $this->normalizedLocalEmail($this->firstLocalContactValue($record, 'email'));
        $phone = $this->normalizedLocalPhone($this->firstLocalContactValue($record, 'phone'));
        $shortName = trim((string) ($record['short_name'] ?? ''));
        if ($shortName === '') {
            $shortName = mb_substr($name, 0, 64, 'UTF-8');
        }

        $attributes = [
            'name' => $name,
            'short_name' => $shortName,
            'contact_type' => $contactType,
            'tax_office' => trim((string) ($record['tax_office'] ?? '')),
            'tax_number' => $taxNumber,
            'district' => trim((string) ($record['district'] ?? '')),
            'city' => trim((string) ($record['city'] ?? '')),
            'address' => trim((string) ($record['address'] ?? '')),
            'phone' => $phone,
            'email' => $email,
            'account_type' => $accountType,
        ];

        return array_filter($attributes, static fn (mixed $value): bool => $value !== '' && $value !== null);
    }

    private function contactPeopleFromLocalRecord(array $record): array
    {
        $rows = is_array($record['contacts'] ?? null) ? $record['contacts'] : [];
        if ($rows === [] && (trim((string) ($record['contact_name'] ?? '')) !== '' || trim((string) ($record['email'] ?? '')) !== '')) {
            $rows[] = [
                'full_name' => trim((string) ($record['contact_name'] ?? '')),
                'email' => trim((string) ($record['email'] ?? '')),
                'phone' => trim((string) ($record['phone'] ?? '')),
            ];
        }

        $people = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $attributes = [
                'name' => trim((string) ($row['full_name'] ?? $row['name'] ?? '')),
                'email' => $this->normalizedLocalEmail((string) ($row['email'] ?? '')),
                'phone' => $this->normalizedLocalPhone((string) ($row['phone'] ?? '')),
                'notes' => trim((string) ($row['role_title'] ?? '')),
            ];
            $attributes = array_filter($attributes, static fn (mixed $value): bool => $value !== '' && $value !== null);
            if ($attributes === []) {
                continue;
            }

            $people[] = [
                'type' => 'contact_people',
                'attributes' => $attributes,
            ];
        }

        return array_slice($people, 0, 20);
    }

    private function firstLocalContactValue(array $record, string $field): string
    {
        $value = trim((string) ($record[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }

        foreach ((array) ($record['contacts'] ?? []) as $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $candidate = trim((string) ($contact[$field] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function normalizedLocalPhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        return function_exists('normalize_phone_number') ? \normalize_phone_number($phone) : $phone;
    }

    private function normalizedLocalEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            return '';
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    public function fetchAllProducts(int $maxPages = 200): array
    {
        $companyId = $this->companyId();
        $products = [];
        $totalPages = null;
        $maxPages = max(1, min($maxPages, 500));

        for ($page = 1; $page <= $maxPages; $page++) {
            $params = [
                'sort' => 'name',
                'page[number]' => $page,
                'page[size]' => 25,
            ];
            $response = $this->request('GET', sprintf('/v4/%s/products?%s', rawurlencode($companyId), http_build_query($params)));
            $pageProducts = $this->mapProducts($response['data'] ?? []);
            if ($pageProducts === []) {
                break;
            }

            foreach ($pageProducts as $product) {
                $productId = (string) ($product['id'] ?? '');
                if ($productId === '') {
                    continue;
                }
                $products[$productId] = $product;
            }

            $totalPages = isset($response['meta']['total_pages']) ? (int) $response['meta']['total_pages'] : $totalPages;
            if ($totalPages !== null && $page >= $totalPages) {
                break;
            }

            usleep(350000);
        }

        return array_values($products);
    }

    public function createSalesInvoiceFromOffer(array $offer, array $lines, ?array $payment = null): array
    {
        $companyId = $this->companyId();
        $contactId = trim((string) ($offer['parasut_contact_id'] ?? ''));
        if ($contactId === '') {
            throw new RuntimeException('Müşterinin Paraşüt cari ID bilgisi yok. Önce müşteriyi Paraşüt carisiyle eşleştirin.');
        }

        if ($lines === []) {
            throw new RuntimeException('Fatura oluşturmak için teklif satırı bulunamadı.');
        }

        $currency = $this->parasutCurrency((string) ($offer['currency'] ?? 'TRY'));
        $details = [];
        foreach ($lines as $line) {
            $title = trim((string) ($line['item_title'] ?? ''));
            if ($title === '') {
                $title = 'Ürün / hizmet';
            }

            $quantity = max(0.01, (float) ($line['quantity'] ?? 1));
            $unitPrice = max(0.0, (float) ($line['unit_price'] ?? 0));
            $vatRate = max(0.0, (float) ($line['vat_rate'] ?? 20));
            $product = $this->findOrCreateProduct($title, $vatRate, $unitPrice, $currency);

            $details[] = [
                'type' => 'sales_invoice_details',
                'attributes' => [
                    'quantity' => round($quantity, 2),
                    'unit_price' => round($unitPrice, 2),
                    'vat_rate' => round($vatRate, 2),
                    'description' => $title,
                ],
                'relationships' => [
                    'product' => [
                        'data' => [
                            'id' => (string) $product['id'],
                            'type' => 'products',
                        ],
                    ],
                ],
            ];
        }

        $offerNumber = trim((string) ($offer['offer_number'] ?? ''));
        $description = trim((string) ($offer['subject'] ?? ''));
        if ($description === '') {
            $description = 'Hızlı Takip ve Teklif yenileme teklifi ' . ($offerNumber !== '' ? $offerNumber : '#' . (int) ($offer['id'] ?? 0));
        } elseif ($offerNumber !== '' && !str_contains($description, $offerNumber)) {
            $description = $offerNumber . ' - ' . $description;
        }

        $payload = [
            'data' => [
                'type' => 'sales_invoices',
                'attributes' => [
                    'item_type' => 'invoice',
                    'description' => mb_substr($description, 0, 255),
                    'issue_date' => date('Y-m-d'),
                    'currency' => $currency,
                    'invoice_note' => $this->salesInvoiceNote($offer, $payment),
                ],
                'relationships' => [
                    'contact' => [
                        'data' => [
                            'id' => $contactId,
                            'type' => 'contacts',
                        ],
                    ],
                    'details' => [
                        'data' => $details,
                    ],
                ],
            ],
        ];

        $response = $this->request('POST', sprintf('/v4/%s/sales_invoices?include=details,contact', rawurlencode($companyId)), $payload);
        $data = $response['data'] ?? [];
        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];

        return [
            'id' => (string) ($data['id'] ?? ''),
            'invoice_no' => (string) ($attributes['invoice_no'] ?? ''),
            'net_total' => $attributes['net_total'] ?? null,
            'gross_total' => $attributes['gross_total'] ?? null,
            'raw' => $response,
        ];
    }

    public function updateSalesInvoiceNote(string $invoiceId, string $note): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') {
            throw new RuntimeException('Paraşüt fatura ID boş olamaz.');
        }

        $companyId = $this->companyId();
        $payload = [
            'data' => [
                'id' => $invoiceId,
                'type' => 'sales_invoices',
                'attributes' => [
                    'invoice_note' => mb_substr(trim($note), 0, 5000),
                ],
            ],
        ];

        $response = $this->request('PUT', sprintf('/v4/%s/sales_invoices/%s?include=details,contact', rawurlencode($companyId), rawurlencode($invoiceId)), $payload);
        $data = $response['data'] ?? [];
        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];

        return [
            'id' => (string) ($data['id'] ?? $invoiceId),
            'invoice_no' => (string) ($attributes['invoice_no'] ?? ''),
            'invoice_note' => (string) ($attributes['invoice_note'] ?? ''),
            'raw' => $response,
        ];
    }

    public function salesInvoiceNote(array $offer, ?array $payment = null): string
    {
        $offerNumber = trim((string) ($offer['offer_number'] ?? ''));
        $lines = [
            'Bu fatura Hızlı Takip ve Teklif Platformu üzerinden onaylanan teklif ' . ($offerNumber !== '' ? $offerNumber : '#' . (int) ($offer['id'] ?? 0)) . ' için oluşturuldu.',
        ];

        $method = trim((string) (($offer['renewal_payment_method'] ?? '') ?: ($offer['payment_method'] ?? '')));
        $provider = $payment !== null ? $this->paymentProviderLabel((string) ($payment['provider'] ?? '')) : '';
        if ($method === '' && $provider !== '') {
            $method = 'Kredi kartı / ' . $provider;
        } elseif ($method !== '' && $provider !== '' && !str_contains(mb_strtolower($method, 'UTF-8'), mb_strtolower($provider, 'UTF-8'))) {
            $method .= ' / ' . $provider;
        }

        if ($method !== '') {
            $lines[] = 'Ödeme yöntemi: ' . $method;
        }

        if ($payment !== null && $payment !== []) {
            $paymentId = trim((string) ($payment['payment_id'] ?? ''));
            $conversationId = trim((string) ($payment['conversation_id'] ?? ''));
            $paidAt = trim((string) (($payment['paid_at'] ?? '') ?: ($payment['updated_at'] ?? '') ?: ($payment['created_at'] ?? '')));
            $amount = $this->formatPaymentAmount($payment);

            if ($paymentId !== '') {
                $lines[] = 'Ödeme ID: ' . $paymentId;
            }
            if (!empty($payment['id'])) {
                $lines[] = 'Sistem ödeme kayıt no: #' . (int) $payment['id'];
            }
            if ($conversationId !== '') {
                $lines[] = 'Ödeme numarası / Conversation ID: ' . $conversationId;
            }
            if ($paidAt !== '') {
                $timestamp = strtotime($paidAt);
                $lines[] = 'Ödeme tarihi: ' . ($timestamp !== false ? date('d.m.Y H:i:s', $timestamp) : $paidAt);
            }
            if ($amount !== '') {
                $lines[] = 'Ödeme tutarı: ' . $amount;
            }
        }

        return mb_substr(implode("\n", $lines), 0, 5000);
    }

    private function paymentProviderLabel(string $provider): string
    {
        return match (mb_strtolower(trim($provider), 'UTF-8')) {
            'iyzico' => 'iyzico',
            'paytr' => 'PayTR',
            default => trim($provider),
        };
    }

    private function formatPaymentAmount(array $payment): string
    {
        if (!isset($payment['amount']) || (float) $payment['amount'] <= 0) {
            return '';
        }

        $currency = strtoupper(trim((string) ($payment['currency'] ?? 'TRY'))) ?: 'TRY';

        return number_format((float) $payment['amount'], 2, ',', '.') . ' ' . $currency;
    }

    private function contactSearchFilters(string $query): array
    {
        if (str_contains($query, '@')) {
            return ['filter[email]'];
        }

        $digitCount = preg_match_all('/\d/', $query);
        if ($digitCount !== false && $digitCount >= 4) {
            return ['filter[tax_number]', 'filter[name]'];
        }

        return ['filter[name]'];
    }

    private function mergeContacts(array $current, array $incoming): array
    {
        $merged = [];
        $dataIndex = [];
        foreach (array_merge($current, $incoming) as $contact) {
            $key = $this->contactIdentityKey($contact);
            $dataKey = $this->contactDataKey($contact);
            if ($key === '') {
                continue;
            }

            if ($dataKey !== '' && isset($dataIndex[$dataKey])) {
                $existingKey = $dataIndex[$dataKey];
                $contact = $this->mergeContactData($merged[$existingKey] ?? [], $contact);
                unset($merged[$existingKey]);
                if (str_starts_with($existingKey, 'id:') && !str_starts_with($key, 'id:')) {
                    $key = $existingKey;
                }
            } elseif (isset($merged[$key])) {
                $contact = $this->mergeContactData($merged[$key], $contact);
            }

            $merged[$key] = $contact;
            if ($dataKey !== '') {
                $dataIndex[$dataKey] = $key;
            }
        }

        return array_values($merged);
    }

    private function contactIdentityKey(array $contact): string
    {
        $id = trim((string) ($contact['id'] ?? ''));
        if ($id !== '') {
            return 'id:' . $id;
        }

        $dataKey = $this->contactDataKey($contact);
        return $dataKey === '' ? '' : 'data:' . $dataKey;
    }

    private function contactDataKey(array $contact): string
    {
        $name = $this->compactSearch((string) ($contact['name'] ?? ''));
        if ($name === '') {
            return '';
        }

        $taxNumber = $this->compactSearch((string) ($contact['tax_number'] ?? ''));
        $email = $this->compactSearch((string) ($contact['email'] ?? ''));
        $phone = $this->compactSearch((string) ($contact['phone'] ?? ''));

        return implode('|', array_filter([$name, $taxNumber, $email, $phone], static fn (string $part): bool => $part !== ''));
    }

    private function mergeContactData(array $base, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if ($value !== '' && $value !== null) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function remoteContactSearch(string $companyId, string $filter, string $query, int $limit, ?string $accountType): array
    {
        $params = [
            $filter => $query,
            'sort' => 'name',
            'page[number]' => 1,
            'page[size]' => min(max($limit, 1), 25),
        ];
        if ($accountType !== null) {
            $params['filter[account_type]'] = $accountType;
        }

        $response = $this->request('GET', sprintf('/v4/%s/contacts?%s', rawurlencode($companyId), http_build_query($params)));

        return $this->filterCachedContacts($this->mapContacts($response['data'] ?? []), $query, $limit, $accountType);
    }

    private function cachedContacts(string $companyId): array
    {
        $cached = $this->readContactCache($companyId);
        if ($cached !== null) {
            return $cached;
        }

        $stale = $this->readContactCache($companyId, true);

        try {
            $contacts = $this->fetchAllContacts($companyId);
        } catch (RuntimeException) {
            return $stale ?? [];
        }

        if ($contacts !== []) {
            $this->writeContactCache($companyId, $contacts);

            return $contacts;
        }

        return $stale ?? [];
    }

    private function fetchAllContacts(string $companyId): array
    {
        $contacts = $this->exportContacts($companyId);
        if ($contacts !== []) {
            return $contacts;
        }

        return $this->pagedContacts($companyId);
    }

    private function pagedContacts(string $companyId): array
    {
        $contacts = [];
        $maxPages = max(1, (int) ($this->config['contact_cache_pages'] ?? 50));
        $totalPages = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            $params = [
                'sort' => 'name',
                'page[number]' => $page,
                'page[size]' => 25,
            ];
            $response = $this->request('GET', sprintf('/v4/%s/contacts?%s', rawurlencode($companyId), http_build_query($params)));
            $pageContacts = $this->mapContacts($response['data'] ?? []);
            if ($pageContacts === []) {
                break;
            }

            $contacts = $this->mergeContacts($contacts, $pageContacts);

            $totalPages = isset($response['meta']['total_pages']) ? (int) $response['meta']['total_pages'] : $totalPages;
            if ($totalPages !== null && $page >= $totalPages) {
                break;
            }
        }

        return $contacts;
    }

    private function filterCachedContacts(array $contacts, string $query, int $limit, ?string $accountType): array
    {
        $needle = $this->normalizeSearch($query);
        $matches = [];

        foreach ($contacts as $contact) {
            $contactAccountType = (string) ($contact['account_type'] ?? '');
            if ($accountType !== null && $contactAccountType !== '' && $contactAccountType !== $accountType) {
                continue;
            }

            $score = $this->contactSearchScore($contact, $needle);
            if ($score !== null) {
                $matches[] = [
                    'score' => $score,
                    'name' => $this->normalizeSearch((string) ($contact['name'] ?? '')),
                    'contact' => $contact,
                ];
            }
        }

        usort($matches, static function (array $left, array $right): int {
            return [$left['score'], $left['name']] <=> [$right['score'], $right['name']];
        });

        return array_slice(array_map(static fn (array $match): array => $match['contact'], $matches), 0, $limit);
    }

    private function contactSearchScore(array $contact, string $needle): ?int
    {
        if ($needle === '') {
            return null;
        }

        $compactNeedle = $this->compactSearch($needle);
        $groups = [
            0 => [$contact['name'] ?? '', $contact['short_name'] ?? ''],
            20 => [$contact['email'] ?? '', $contact['tax_number'] ?? '', $contact['phone'] ?? ''],
            35 => [$contact['tax_office'] ?? '', $contact['city'] ?? '', $contact['district'] ?? ''],
        ];

        foreach ($groups as $baseScore => $fields) {
            foreach ($fields as $field) {
                $field = $this->normalizeSearch((string) $field);
                if ($field === '') {
                    continue;
                }

                if ($field === $needle) {
                    return $baseScore;
                }

                if (str_starts_with($field, $needle)) {
                    return $baseScore + 5;
                }

                if (str_contains($field, $needle)) {
                    return $baseScore + 10;
                }

                $compactField = $this->compactSearch($field);
                if ($compactNeedle !== '' && $compactField !== '' && str_contains($compactField, $compactNeedle)) {
                    return $baseScore + 12;
                }
            }
        }

        return null;
    }

    private function mapContacts(array $items): array
    {
        $contacts = [];

        foreach ($items as $contact) {
            $attrs = $contact['attributes'] ?? [];
            $contacts[] = [
                'id' => (string) ($contact['id'] ?? ''),
                'name' => (string) ($attrs['name'] ?? ''),
                'short_name' => (string) ($attrs['short_name'] ?? ''),
                'email' => (string) ($attrs['email'] ?? ''),
                'phone' => (string) ($attrs['phone'] ?? ''),
                'tax_office' => (string) ($attrs['tax_office'] ?? ''),
                'tax_number' => (string) ($attrs['tax_number'] ?? ''),
                'city' => (string) ($attrs['city'] ?? ''),
                'district' => (string) ($attrs['district'] ?? ''),
                'address' => (string) ($attrs['address'] ?? ''),
                'account_type' => (string) ($attrs['account_type'] ?? ''),
                'account_type_label' => $this->accountTypeLabel((string) ($attrs['account_type'] ?? '')),
                'contact_type' => (string) ($attrs['contact_type'] ?? ''),
            ];
        }

        return $contacts;
    }

    private function accountTypeLabel(string $accountType): string
    {
        return match ($accountType) {
            'customer' => 'Musteri',
            'supplier' => 'Tedarikci',
            default => $accountType,
        };
    }

    private function mapProducts(array $items): array
    {
        $products = [];

        foreach ($items as $product) {
            if (!is_array($product)) {
                continue;
            }

            $attrs = is_array($product['attributes'] ?? null) ? $product['attributes'] : [];
            $currency = strtoupper(trim((string) ($attrs['currency'] ?? 'TRY')));
            if ($currency === 'TRL' || $currency === 'TL') {
                $currency = 'TRY';
            }
            if (!in_array($currency, ['TRY', 'USD', 'EUR'], true)) {
                $currency = 'TRY';
            }

            $stockCount = $attrs['stock_count']
                ?? $attrs['inventory_count']
                ?? $attrs['available_stock_count']
                ?? $attrs['remaining_stock_count']
                ?? null;

            $isArchived = !empty($attrs['archived'])
                || !empty($attrs['is_archived'])
                || (array_key_exists('is_active', $attrs) && empty($attrs['is_active']));

            $products[] = [
                'id' => (string) ($product['id'] ?? ''),
                'name' => (string) ($attrs['name'] ?? ''),
                'code' => (string) ($attrs['code'] ?? $attrs['item_code'] ?? ''),
                'barcode' => (string) ($attrs['barcode'] ?? ''),
                'brand' => (string) ($attrs['brand'] ?? ''),
                'unit' => (string) ($attrs['unit'] ?? 'Adet'),
                'currency' => $currency,
                'list_price' => (float) ($attrs['list_price'] ?? $attrs['sales_price'] ?? 0),
                'buying_price' => isset($attrs['buying_price']) ? (float) $attrs['buying_price'] : null,
                'vat_rate' => (float) ($attrs['vat_rate'] ?? 20),
                'inventory_tracking' => !empty($attrs['inventory_tracking']),
                'stock_count' => $stockCount !== null ? (float) $stockCount : null,
                'is_archived' => $isArchived,
                'raw' => $product,
            ];
        }

        return $products;
    }

    private function exportContacts(string $companyId): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return [];
        }

        try {
            $response = $this->request('GET', sprintf('/v4/%s/contacts/export', rawurlencode($companyId)));
        } catch (RuntimeException) {
            return [];
        }

        $resultUrl = (string) ($response['data']['attributes']['url'] ?? '');
        if ($resultUrl === '') {
            return [];
        }

        $downloadUrl = '';
        for ($attempt = 0; $attempt < 6; $attempt++) {
            if ($attempt > 0) {
                sleep(1);
            }

            $resultBody = $this->fetchExternalUrl($resultUrl, 15, false);
            if ($resultBody === null) {
                continue;
            }

            $resultData = json_decode($resultBody, true);
            if (!is_array($resultData)) {
                continue;
            }

            $downloadUrl = (string) ($resultData['url'] ?? $resultData['data']['attributes']['url'] ?? '');
            if ($downloadUrl !== '') {
                break;
            }
        }

        if ($downloadUrl === '') {
            return [];
        }

        $xlsxBody = $this->fetchExternalUrl($downloadUrl, 40, true);
        if ($xlsxBody === null || $xlsxBody === '') {
            return [];
        }

        return $this->parseContactExportXlsx($xlsxBody);
    }

    private function fetchExternalUrl(string $url, int $timeout, bool $throwOnError): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, */*'],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '' || $status >= 400) {
            if ($throwOnError) {
                throw new RuntimeException('Parasut export dosyasi indirilemedi.');
            }

            return null;
        }

        return (string) $body;
    }

    private function parseContactExportXlsx(string $xlsxBody): array
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'parasut-contacts-');
        if ($tempPath === false) {
            return [];
        }

        file_put_contents($tempPath, $xlsxBody, LOCK_EX);

        $zip = new \ZipArchive();
        if ($zip->open($tempPath) !== true) {
            unlink($tempPath);

            return [];
        }

        $sharedStrings = $this->readXlsxSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tempPath);

        if (!is_string($sheetXml) || $sheetXml === '') {
            return [];
        }

        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            return [];
        }

        $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = $sheet->xpath('//m:sheetData/m:row') ?: [];
        $contacts = [];

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex === 0) {
                continue;
            }

            $values = [];
            $row->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach (($row->xpath('m:c') ?: []) as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                $column = $this->xlsxColumnIndex($ref);
                if ($column === null) {
                    continue;
                }

                $values[$column] = $this->xlsxCellValue($cell, $sharedStrings);
            }

            $name = trim((string) ($values[0] ?? ''));
            if ($name === '') {
                continue;
            }

            $contacts = $this->mergeContacts($contacts, [[
                'id' => '',
                'name' => $name,
                'short_name' => $name,
                'email' => trim((string) ($values[1] ?? '')),
                'phone' => trim((string) ($values[10] ?? '')),
                'tax_office' => trim((string) ($values[12] ?? '')),
                'tax_number' => trim((string) ($values[13] ?? '')),
                'city' => trim((string) ($values[8] ?? '')),
                'district' => trim((string) ($values[9] ?? '')),
                'address' => trim((string) ($values[7] ?? '')),
                'account_type' => '',
                'account_type_label' => 'Cari',
                'contact_type' => '',
                'source' => 'export',
            ]]);
        }

        return $contacts;
    }

    private function readXlsxSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($xml) || $xml === '') {
            return [];
        }

        $shared = simplexml_load_string($xml);
        if ($shared === false) {
            return [];
        }

        $shared->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = [];
        foreach ($shared->xpath('//m:si') ?: [] as $item) {
            $parts = [];
            $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($item->xpath('.//m:t') ?: [] as $text) {
                $parts[] = (string) $text;
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    private function xlsxCellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');
        $raw = (string) ($cell->v ?? '');

        if ($type === 's') {
            return (string) ($sharedStrings[(int) $raw] ?? '');
        }

        if ($type === 'inlineStr') {
            $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $parts = [];
            foreach ($cell->xpath('.//m:t') ?: [] as $text) {
                $parts[] = (string) $text;
            }

            return implode('', $parts);
        }

        return $raw;
    }

    private function xlsxColumnIndex(string $reference): ?int
    {
        if (!preg_match('/^([A-Z]+)/', $reference, $matches)) {
            return null;
        }

        $index = 0;
        foreach (str_split($matches[1]) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function readContactCache(string $companyId, bool $allowStale = false): ?array
    {
        if (!is_file($this->contactCachePath)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->contactCachePath), true);
        if (!is_array($data) || ($data['company_id'] ?? '') !== $companyId) {
            return null;
        }

        $ttl = max(21600, (int) ($this->config['contact_cache_ttl'] ?? 21600));
        if (!$allowStale && (int) ($data['generated_at'] ?? 0) < time() - $ttl) {
            return null;
        }

        return is_array($data['contacts'] ?? null) ? $data['contacts'] : null;
    }

    private function writeContactCache(string $companyId, array $contacts): void
    {
        $dir = dirname($this->contactCachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->contactCachePath, json_encode([
            'company_id' => $companyId,
            'generated_at' => time(),
            'contacts' => $contacts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function clearContactCache(): void
    {
        if (is_file($this->contactCachePath)) {
            unlink($this->contactCachePath);
        }
    }

    private function normalizeSearch(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');

        return strtr($value, [
            'ı' => 'i',
            'ğ' => 'g',
            'ü' => 'u',
            'ş' => 's',
            'ö' => 'o',
            'ç' => 'c',
            'İ' => 'i',
            'Ğ' => 'g',
            'Ü' => 'u',
            'Ş' => 's',
            'Ö' => 'o',
            'Ç' => 'c',
        ]);
    }

    private function compactSearch(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/u', '', $this->normalizeSearch($value));
    }

    private function findOrCreateProduct(string $name, float $vatRate, float $unitPrice, string $currency): array
    {
        $companyId = $this->companyId();
        $query = http_build_query([
            'filter[name]' => $name,
            'page[number]' => 1,
            'page[size]' => 10,
        ]);
        $response = $this->request('GET', sprintf('/v4/%s/products?%s', rawurlencode($companyId), $query));
        $normalizedName = $this->normalizeSearch($name);
        foreach (($response['data'] ?? []) as $product) {
            $attrs = is_array($product['attributes'] ?? null) ? $product['attributes'] : [];
            $productId = trim((string) ($product['id'] ?? ''));
            if ($productId !== '' && $this->normalizeSearch((string) ($attrs['name'] ?? '')) === $normalizedName) {
                return [
                    'id' => $productId,
                    'name' => (string) ($attrs['name'] ?? $name),
                ];
            }
        }

        $payload = [
            'data' => [
                'type' => 'products',
                'attributes' => [
                    'name' => $name,
                    'vat_rate' => round($vatRate, 2),
                    'unit' => 'Adet',
                    'list_price' => round($unitPrice, 2),
                    'currency' => $currency,
                    'inventory_tracking' => false,
                ],
            ],
        ];
        $created = $this->request('POST', sprintf('/v4/%s/products', rawurlencode($companyId)), $payload);
        $data = $created['data'] ?? [];
        if (trim((string) ($data['id'] ?? '')) === '') {
            throw new RuntimeException('Paraşüt ürün oluşturdu ancak ürün ID dönmedi.');
        }

        return [
            'id' => (string) ($data['id'] ?? ''),
            'name' => $name,
        ];
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $accessToken = $this->accessToken();

        return $this->performJsonRequest(function () use ($method, $path, $payload, $accessToken) {
            $ch = curl_init($this->baseUrl() . $path);
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $accessToken,
                ],
                CURLOPT_TIMEOUT => 30,
            ];
            if ($payload !== null) {
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            curl_setopt_array($ch, $options);

            return $ch;
        }, 4);
    }

    private function companyId(): string
    {
        $token = $this->readToken();
        $companyId = (string) ($token['company_id'] ?? '');
        if ($companyId === '') {
            throw new RuntimeException('Parasut firma ID tanimli degil.');
        }

        return $companyId;
    }

    private function parasutCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        return match ($currency) {
            'TRY', 'TL', 'TRL' => 'TRL',
            'USD' => 'USD',
            'EUR' => 'EUR',
            'GBP' => 'GBP',
            default => 'TRL',
        };
    }

    private function accessToken(): string
    {
        $token = $this->readToken();

        if (!empty($token['access_token']) && (int) ($token['expires_at'] ?? 0) > time() + 90) {
            return (string) $token['access_token'];
        }

        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Parasut baglantisi yapilmamis.');
        }

        $fresh = $this->postToken([
            'grant_type' => 'refresh_token',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $token['refresh_token'],
        ]);
        $fresh['company_id'] = $token['company_id'] ?? '';
        $fresh = $this->normalizeToken($fresh);
        $this->writeToken($fresh);

        return (string) $fresh['access_token'];
    }

    private function postToken(array $fields): array
    {
        return $this->performJsonRequest(function () use ($fields) {
            $ch = curl_init($this->baseUrl() . '/oauth/token');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $fields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);

            return $ch;
        }, 2);
    }

    private function performJsonRequest(callable $createHandle, int $maxAttempts = 3): array
    {
        $maxAttempts = max(1, $maxAttempts);
        $lastMessage = 'Parasut API hata verdi.';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $headers = [];
            $ch = $createHandle();
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $header) use (&$headers): int {
                $length = strlen($header);
                $header = trim($header);
                if ($header !== '' && str_contains($header, ':')) {
                    [$name, $value] = explode(':', $header, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return $length;
            });

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false || $error !== '') {
                $lastMessage = 'Parasut API baglantisi basarisiz: ' . $error;
                if ($attempt < $maxAttempts) {
                    sleep($this->retryDelaySeconds($headers, $error, $attempt));
                    continue;
                }

                throw new RuntimeException($lastMessage);
            }

            $decoded = json_decode((string) $body, true);
            if (!is_array($decoded)) {
                $detail = trim(strip_tags((string) $body));
                $lastMessage = $status >= 400 && $detail !== ''
                    ? 'Parasut API hata verdi: ' . $detail
                    : 'Parasut API gecersiz yanit dondu.';
                if ($attempt < $maxAttempts && $this->shouldRetryParasutResponse($status, $detail)) {
                    sleep($this->retryDelaySeconds($headers, $detail, $attempt));
                    continue;
                }

                throw new RuntimeException($lastMessage);
            }

            if ($status >= 400) {
                $detail = $decoded['error_description'] ?? $decoded['error'] ?? ($decoded['errors'][0]['detail'] ?? 'Bilinmeyen hata');
                $detail = is_scalar($detail) ? (string) $detail : 'Bilinmeyen hata';
                $lastMessage = 'Parasut API hata verdi: ' . $detail;
                if ($attempt < $maxAttempts && $this->shouldRetryParasutResponse($status, $detail)) {
                    sleep($this->retryDelaySeconds($headers, $detail, $attempt));
                    continue;
                }

                throw new RuntimeException($lastMessage);
            }

            return $decoded;
        }

        throw new RuntimeException($lastMessage);
    }

    private function shouldRetryParasutResponse(int $status, string $detail): bool
    {
        $detail = mb_strtolower($detail, 'UTF-8');

        return in_array($status, [429, 500, 502, 503, 504], true)
            || str_contains($detail, 'try again')
            || str_contains($detail, 'too many')
            || str_contains($detail, 'rate limit')
            || str_contains($detail, 'temporarily');
    }

    private function retryDelaySeconds(array $headers, string $detail, int $attempt): int
    {
        $retryAfter = trim((string) ($headers['retry-after'] ?? ''));
        if (ctype_digit($retryAfter)) {
            return max(1, min(30, (int) $retryAfter));
        }

        if (preg_match('/try again in\s+(\d+)\s+seconds?/i', $detail, $matches)) {
            return max(1, min(30, (int) $matches[1]));
        }

        return max(1, min(10, $attempt * 2));
    }

    private function normalizeToken(array $token): array
    {
        $token['expires_at'] = time() + (int) ($token['expires_in'] ?? 7200);

        return $token;
    }

    private function readToken(): array
    {
        if (!is_file($this->tokenPath)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->tokenPath), true);

        return is_array($data) ? $data : [];
    }

    private function writeToken(array $token): void
    {
        $dir = dirname($this->tokenPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->tokenPath, json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://api.parasut.com'), '/');
    }

    private function redirectUri(): string
    {
        return (string) ($this->config['redirect_uri'] ?? 'urn:ietf:wg:oauth:2.0:oob');
    }
}
