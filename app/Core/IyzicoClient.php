<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\SettingsRepository;
use RuntimeException;

final class IyzicoClient
{
    private const INITIALIZE_PATH = '/payment/iyzipos/checkoutform/initialize/auth/ecom';
    private const DETAIL_PATH = '/payment/iyzipos/checkoutform/auth/ecom/detail';

    private array $settings;

    public function __construct(?SettingsRepository $settingsRepository = null)
    {
        $this->settings = ($settingsRepository ?? new SettingsRepository())->all();
    }

    public function isEnabled(): bool
    {
        return (string) ($this->settings['iyzico.enabled'] ?? '0') === '1';
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->settings['iyzico.api_key'] ?? '')) !== ''
            && trim((string) ($this->settings['iyzico.secret_key'] ?? '')) !== '';
    }

    public function mode(): string
    {
        return (string) ($this->settings['iyzico.mode'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
    }

    public function initializeCheckout(array $renewal, float $amount, string $currency, string $conversationId): array
    {
        $amountText = $this->money($amount);
        $payload = [
            'locale' => 'tr',
            'conversationId' => $conversationId,
            'price' => $amountText,
            'paidPrice' => $amountText,
            'currency' => $this->currency($currency),
            'basketId' => 'renewal-' . (int) $renewal['id'],
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => \url('/payments/iyzico/callback'),
            'enabledInstallments' => $this->enabledInstallments(),
            'buyer' => $this->buyer($renewal),
            'billingAddress' => $this->billingAddress($renewal),
            'basketItems' => [[
                'id' => 'renewal-' . (int) $renewal['id'],
                'name' => mb_substr(trim((string) ($renewal['title'] ?? 'Yenileme')), 0, 255),
                'category1' => ((string) ($renewal['kind'] ?? 'service')) === 'product' ? 'Urun' : 'Hizmet',
                'category2' => mb_substr(trim((string) ($renewal['brand'] ?? 'Yenileme')) ?: 'Yenileme', 0, 255),
                'itemType' => 'VIRTUAL',
                'price' => $amountText,
            ]],
        ];

        return [
            'request' => $payload,
            'response' => $this->request(self::INITIALIZE_PATH, $payload),
        ];
    }

    public function initializeManualPayment(array $paymentRequest, float $amount, string $currency, string $conversationId): array
    {
        $amountText = $this->money($amount);
        $title = trim((string) ($paymentRequest['title'] ?? 'Manuel ödeme talebi'));
        $payload = [
            'locale' => 'tr',
            'conversationId' => $conversationId,
            'price' => $amountText,
            'paidPrice' => $amountText,
            'currency' => $this->currency($currency),
            'basketId' => 'manual-payment-' . (int) $paymentRequest['id'],
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => \url('/payments/iyzico/callback'),
            'enabledInstallments' => $this->enabledInstallments(),
            'buyer' => $this->buyer($paymentRequest),
            'billingAddress' => $this->billingAddress($paymentRequest),
            'basketItems' => [[
                'id' => 'manual-payment-' . (int) $paymentRequest['id'],
                'name' => mb_substr($title !== '' ? $title : 'Manuel ödeme talebi', 0, 255),
                'category1' => 'Manuel Tahsilat',
                'category2' => 'Ödeme Talebi',
                'itemType' => 'VIRTUAL',
                'price' => $amountText,
            ]],
        ];

        return [
            'request' => $payload,
            'response' => $this->request(self::INITIALIZE_PATH, $payload),
        ];
    }

    public function retrieveCheckout(string $token, string $conversationId): array
    {
        $payload = [
            'locale' => 'tr',
            'conversationId' => $conversationId,
            'token' => $token,
        ];

        return [
            'request' => $payload,
            'response' => $this->request(self::DETAIL_PATH, $payload),
        ];
    }

    private function request(string $path, array $payload): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('iyzico API anahtarlari tanimli degil.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('iyzico istek govdesi hazirlanamadi.');
        }

        $randomKey = (string) ((int) (microtime(true) * 1000)) . random_int(100000, 999999);
        $signature = hash_hmac('sha256', $randomKey . $path . $body, (string) $this->settings['iyzico.secret_key']);
        $authorization = 'IYZWSv2 ' . base64_encode(
            'apiKey:' . (string) $this->settings['iyzico.api_key']
            . '&randomKey:' . $randomKey
            . '&signature:' . $signature
        );

        $ch = curl_init($this->baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authorization,
                'x-iyzi-rnd: ' . $randomKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            throw new RuntimeException('iyzico baglantisi basarisiz: ' . $error);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('iyzico yaniti okunamadi. HTTP ' . $status);
        }

        if ($status < 200 || $status >= 300) {
            $message = $decoded['errorMessage'] ?? $decoded['error'] ?? ('HTTP ' . $status);
            throw new RuntimeException('iyzico hatasi: ' . $message);
        }

        return $decoded;
    }

    private function buyer(array $renewal): array
    {
        $company = trim((string) (($renewal['company_name'] ?? '') ?: ($renewal['customer_name'] ?? 'Musteri')));
        $contact = trim((string) (($renewal['contact_name'] ?? '') ?: ($renewal['customer_name'] ?? '')));
        [$name, $surname] = $this->splitName($contact !== '' ? $contact : $company);
        $email = trim((string) ($renewal['customer_email'] ?? ''));
        $phone = $this->phone((string) ($renewal['customer_phone'] ?? ''));
        $identityNumber = $this->identityNumber((string) ($renewal['customer_tax_number'] ?? ''));

        if ($this->mode() === 'live') {
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Canli iyzico odemesi icin musteri e-postasi gerekli.');
            }

            if ($identityNumber === '') {
                throw new RuntimeException('Canli iyzico odemesi icin musteri vergi no / TC kimlik no gerekli.');
            }
        }

        return [
            'id' => !empty($renewal['customer_id']) ? 'customer-' . (int) $renewal['customer_id'] : 'manual-' . (int) ($renewal['id'] ?? 0),
            'name' => $name,
            'surname' => $surname,
            'gsmNumber' => $phone !== '' ? $phone : '+905350000000',
            'email' => $email !== '' ? $email : 'musteri@example.com',
            'identityNumber' => $identityNumber !== '' ? $identityNumber : '11111111111',
            'registrationAddress' => $this->address($renewal),
            'ip' => $this->clientIp(),
            'city' => trim((string) ($renewal['customer_city'] ?? '')) ?: 'Istanbul',
            'country' => 'Turkey',
            'zipCode' => '00000',
        ];
    }

    private function billingAddress(array $renewal): array
    {
        $company = trim((string) (($renewal['company_name'] ?? '') ?: ($renewal['customer_name'] ?? 'Musteri')));

        return [
            'contactName' => $company !== '' ? $company : 'Musteri',
            'city' => trim((string) ($renewal['customer_city'] ?? '')) ?: 'Istanbul',
            'country' => 'Turkey',
            'address' => $this->address($renewal),
            'zipCode' => '00000',
        ];
    }

    private function address(array $renewal): string
    {
        $address = trim((string) ($renewal['customer_address'] ?? ''));
        if ($address !== '') {
            return $address;
        }

        return trim((string) (($renewal['company_name'] ?? '') ?: ($renewal['customer_name'] ?? 'Adres girilmedi'))) ?: 'Adres girilmedi';
    }

    private function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return ['Musteri', 'Yetkili'];
        }

        $parts = explode(' ', $name, 2);

        return [
            mb_substr($parts[0], 0, 120),
            mb_substr($parts[1] ?? 'Yetkili', 0, 120),
        ];
    }

    private function identityNumber(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return in_array(strlen($digits), [10, 11], true) ? $digits : '';
    }

    private function phone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '90')) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '+9' . $digits;
        }

        return '+90' . $digits;
    }

    private function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        return in_array($currency, ['TRY', 'USD', 'EUR', 'GBP'], true) ? $currency : 'TRY';
    }

    private function money(float $amount): string
    {
        return number_format(max(0.01, $amount), 2, '.', '');
    }

    private function enabledInstallments(): array
    {
        $raw = (string) ($this->settings['iyzico.installments'] ?? '1');
        $values = [];
        foreach (preg_split('/[,\s]+/', $raw) ?: [] as $part) {
            $installment = (int) $part;
            if (in_array($installment, [1, 2, 3, 6, 9, 12], true)) {
                $values[$installment] = $installment;
            }
        }

        return array_values($values ?: [1]);
    }

    private function clientIp(): string
    {
        $candidates = [
            (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
            (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim(explode(',', $candidate)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return '127.0.0.1';
    }

    private function baseUrl(): string
    {
        return $this->mode() === 'live'
            ? 'https://api.iyzipay.com'
            : 'https://sandbox-api.iyzipay.com';
    }
}
