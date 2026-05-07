<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class SettingsRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function all(): array
    {
        $settings = self::defaults();

        try {
            $rows = $this->db->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
        } catch (\Throwable) {
            return $settings;
        }

        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        if (($settings['m365.redirect_uri'] ?? '') === '') {
            $settings['m365.redirect_uri'] = \url('/settings/microsoft/callback');
        }

        return $settings;
    }

    public function setMany(array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_at)
             VALUES (:setting_key, :setting_value, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );

        foreach ($values as $key => $value) {
            $stmt->execute([
                'setting_key' => (string) $key,
                'setting_value' => (string) $value,
            ]);
        }
    }

    public function setMicrosoftToken(array $token): void
    {
        $token['expires_at'] = time() + (int) ($token['expires_in'] ?? 3600);
        $this->setMany([
            'm365.token_json' => json_encode($token, JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function microsoftToken(): array
    {
        $json = $this->all()['m365.token_json'] ?? '';
        $token = json_decode($json, true);

        return is_array($token) ? $token : [];
    }

    public function clearMicrosoftToken(): void
    {
        $this->setMany(['m365.token_json' => '']);
    }

    public static function defaults(): array
    {
        return [
            'mail.driver' => \app_config('mail.transport', 'log'),
            'mail.from_email' => \app_config('mail.from_email', 'yenileme@example.com'),
            'mail.from_name' => \app_config('mail.from_name', 'Yenileme Takip Sistemi'),
            'smtp.host' => '',
            'smtp.port' => '587',
            'smtp.encryption' => 'tls',
            'smtp.username' => '',
            'smtp.password' => '',
            'smtp.timeout' => '20',
            'm365.tenant_id' => 'common',
            'm365.client_id' => '',
            'm365.client_secret' => '',
            'm365.redirect_uri' => \url('/settings/microsoft/callback'),
            'm365.from_user' => '',
            'm365.token_json' => '',
            'company.name' => 'Biga Bilişim',
            'company.email' => \app_config('mail.from_email', 'yenileme@example.com'),
            'company.phone' => '',
            'company.address' => '',
            'company.website' => \app_config('app.url', ''),
            'branding.logo_path' => '',
            'webpush.public_key' => '',
            'webpush.private_key' => '',
            'notifications.send_time' => '09:00',
            'backup.enabled' => '1',
            'backup.email' => '',
            'backup.time' => '02:00',
            'backup.keep_days' => '14',
            'backup.last_run_date' => '',
            'backup.last_run_at' => '',
            'backup.last_file' => '',
            'backup.last_size' => '',
            'backup.last_mail_status' => '',
            'backup.last_error' => '',
            'template.renewal.enabled' => '0',
            'template.renewal.html' => '',
            'template.renewal.css' => '',
            'template.renewal.project_json' => '',
            'template.renewal.test_email' => '',
            'iyzico.enabled' => '0',
            'iyzico.mode' => 'sandbox',
            'iyzico.api_key' => '',
            'iyzico.secret_key' => '',
            'iyzico.installments' => '1',
            'paytr.enabled' => '0',
            'paytr.mode' => 'test',
            'paytr.merchant_id' => '',
            'paytr.merchant_key' => '',
            'paytr.merchant_salt' => '',
            'paytr.no_installment' => '0',
            'paytr.max_installment' => '0',
            'paytr.timeout_limit' => '30',
            'paytr.debug_on' => '1',
            'bank_transfer.iban_info' => '',
            'bank_transfer.notify_emails' => '',
        ];
    }
}
