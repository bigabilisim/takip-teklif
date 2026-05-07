<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\SettingsRepository;
use RuntimeException;

final class PaytrClient
{
    private const TOKEN_URL = 'https://www.paytr.com/odeme/api/get-token';
    private const IFRAME_URL = 'https://www.paytr.com/odeme/guvenli/';

    private array $settings;

    public function __construct(?SettingsRepository $settingsRepository = null)
    {
        $this->settings = ($settingsRepository ?? new SettingsRepository())->all();
    }

    public function isEnabled(): bool
    {
        return (string) ($this->settings['paytr.enabled'] ?? '0') === '1';
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->settings['paytr.merchant_id'] ?? '')) !== ''
            && trim((string) ($this->settings['paytr.merchant_key'] ?? '')) !== ''
            && trim((string) ($this->settings['paytr.merchant_salt'] ?? '')) !== '';
    }

    public function mode(): string
    {
        return (string) ($this->settings['paytr.mode'] ?? 'test') === 'live' ? 'live' : 'test';
    }

    public function initializeIframe(array $renewal, float $amount, string $currency, string $merchantOid): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('PayTR API bilgileri tanimli degil.');
        }

        $email = $this->email($renewal);
        $paymentAmount = (int) round($amount * 100);
        $basket = base64_encode(json_encode([
            [
                mb_substr((string) (($renewal['item_summary'] ?? '') ?: ($renewal['title'] ?? 'Yenileme')), 0, 120),
                $this->money($amount),
                1,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');

        $payload = [
            'merchant_id' => trim((string) $this->settings['paytr.merchant_id']),
            'user_ip' => $this->clientIp(),
            'merchant_oid' => $merchantOid,
            'email' => $email,
            'payment_amount' => $paymentAmount,
            'user_basket' => $basket,
            'no_installment' => $this->noInstallment(),
            'max_installment' => $this->maxInstallment(),
            'currency' => $this->currency($currency),
            'test_mode' => $this->mode() === 'test' ? '1' : '0',
            'user_name' => $this->customerName($renewal),
            'user_address' => $this->address($renewal),
            'user_phone' => $this->phone((string) ($renewal['customer_phone'] ?? '')),
            'merchant_ok_url' => \url('/payments/paytr/result/' . rawurlencode($merchantOid) . '?result=success'),
            'merchant_fail_url' => \url('/payments/paytr/result/' . rawurlencode($merchantOid) . '?result=failed'),
            'timeout_limit' => max(1, (int) ($this->settings['paytr.timeout_limit'] ?? 30)),
            'debug_on' => (string) ($this->settings['paytr.debug_on'] ?? '1') === '1' ? '1' : '0',
            'lang' => 'tr',
        ];

        $payload['paytr_token'] = $this->requestTokenHash($payload);

        return [
            'request' => $payload,
            'response' => $this->requestToken($payload),
        ];
    }

    public function iframeUrl(string $token): string
    {
        return self::IFRAME_URL . rawurlencode($token);
    }

    public function verifyCallback(array $post): bool
    {
        $merchantOid = (string) ($post['merchant_oid'] ?? '');
        $status = (string) ($post['status'] ?? '');
        $totalAmount = (string) ($post['total_amount'] ?? '');
        $hash = (string) ($post['hash'] ?? '');

        if ($merchantOid === '' || $status === '' || $totalAmount === '' || $hash === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac(
            'sha256',
            $merchantOid . (string) $this->settings['paytr.merchant_salt'] . $status . $totalAmount,
            (string) $this->settings['paytr.merchant_key'],
            true
        ));

        return hash_equals($expected, $hash);
    }

    private function requestTokenHash(array $payload): string
    {
        $hashStr = (string) $payload['merchant_id']
            . (string) $payload['user_ip']
            . (string) $payload['merchant_oid']
            . (string) $payload['email']
            . (string) $payload['payment_amount']
            . (string) $payload['user_basket']
            . (string) $payload['no_installment']
            . (string) $payload['max_installment']
            . (string) $payload['currency']
            . (string) $payload['test_mode'];

        return base64_encode(hash_hmac(
            'sha256',
            $hashStr . (string) $this->settings['paytr.merchant_salt'],
            (string) $this->settings['paytr.merchant_key'],
            true
        ));
    }

    private function requestToken(array $payload): array
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload, '', '&'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            throw new RuntimeException('PayTR baglantisi basarisiz: ' . $error);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('PayTR yaniti okunamadi. HTTP ' . $status);
        }

        if ($status < 200 || $status >= 300 || (string) ($decoded['status'] ?? '') !== 'success') {
            throw new RuntimeException('PayTR hatasi: ' . (string) ($decoded['reason'] ?? ('HTTP ' . $status)));
        }

        return $decoded;
    }

    private function email(array $renewal): string
    {
        $email = trim((string) ($renewal['customer_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        if ($this->mode() === 'live') {
            throw new RuntimeException('Canli PayTR odemesi icin musteri e-postasi gerekli.');
        }

        return 'musteri@example.com';
    }

    private function customerName(array $renewal): string
    {
        $name = trim((string) (($renewal['contact_name'] ?? '') ?: ($renewal['company_name'] ?? 'Musteri')));

        return mb_substr($name !== '' ? $name : 'Musteri', 0, 60);
    }

    private function address(array $renewal): string
    {
        $address = trim((string) ($renewal['customer_address'] ?? ''));

        return mb_substr($address !== '' ? $address : (string) (($renewal['company_name'] ?? '') ?: 'Adres girilmedi'), 0, 400);
    }

    private function phone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '05555555555';
        }
        if (strlen($digits) === 10) {
            $digits = '0' . $digits;
        }
        if (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        }

        return mb_substr($digits, 0, 20);
    }

    private function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        return match ($currency) {
            'USD' => 'USD',
            'EUR' => 'EUR',
            default => 'TL',
        };
    }

    private function money(float $amount): string
    {
        return number_format(max(0.0, $amount), 2, '.', '');
    }

    private function noInstallment(): string
    {
        return (string) ($this->settings['paytr.no_installment'] ?? '0') === '1' ? '1' : '0';
    }

    private function maxInstallment(): string
    {
        return (string) max(0, min(12, (int) ($this->settings['paytr.max_installment'] ?? 0)));
    }

    private function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = trim((string) ($_SERVER[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $ip = trim(explode(',', $value)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '127.0.0.1';
    }
}
