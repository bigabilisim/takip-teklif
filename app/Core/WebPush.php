<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\SettingsRepository;
use PDO;
use RuntimeException;

final class WebPush
{
    private const RECORD_SIZE = 4096;

    public static function publicKey(): string
    {
        return self::keys()['public'];
    }

    public static function saveSubscription(int $userId, array $subscription, string $userAgent = ''): void
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
        $publicKey = trim((string) ($keys['p256dh'] ?? ''));
        $authToken = trim((string) ($keys['auth'] ?? ''));
        $encoding = trim((string) ($subscription['contentEncoding'] ?? 'aes128gcm')) ?: 'aes128gcm';

        if ($endpoint === '' || $publicKey === '' || $authToken === '') {
            throw new RuntimeException('Bildirim aboneligi eksik.');
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO push_subscriptions
                (user_id, endpoint, endpoint_hash, public_key, auth_token, content_encoding, user_agent, is_active, failure_count, created_at, updated_at)
             VALUES
                (:user_id, :endpoint, :endpoint_hash, :public_key, :auth_token, :content_encoding, :user_agent, 1, 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                public_key = VALUES(public_key),
                auth_token = VALUES(auth_token),
                content_encoding = VALUES(content_encoding),
                user_agent = VALUES(user_agent),
                is_active = 1,
                failure_count = 0,
                updated_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => $publicKey,
            'auth_token' => $authToken,
            'content_encoding' => mb_substr($encoding, 0, 30),
            'user_agent' => mb_substr($userAgent, 0, 255),
        ]);
    }

    public static function deleteSubscription(string $endpoint): void
    {
        if ($endpoint === '') {
            return;
        }

        Database::connection()
            ->prepare('UPDATE push_subscriptions SET is_active = 0, updated_at = NOW() WHERE endpoint_hash = :hash')
            ->execute(['hash' => hash('sha256', $endpoint)]);
    }

    public static function activeCount(?int $userId = null): int
    {
        if ($userId !== null) {
            $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = :user_id AND is_active = 1');
            $stmt->execute(['user_id' => $userId]);

            return (int) $stmt->fetchColumn();
        }

        return (int) Database::connection()->query('SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 1')->fetchColumn();
    }

    public static function sendToAll(array $payload = []): array
    {
        $rows = Database::connection()
            ->query('SELECT * FROM push_subscriptions WHERE is_active = 1 ORDER BY id ASC')
            ->fetchAll();

        return self::sendRows($rows, $payload);
    }

    public static function sendToUser(int $userId, array $payload = []): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM push_subscriptions WHERE user_id = :user_id AND is_active = 1 ORDER BY id ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return self::sendRows($stmt->fetchAll(), $payload);
    }

    private static function sendRows(array $rows, array $payload): array
    {
        $sent = 0;
        $failed = 0;
        $lastStatus = 0;
        $lastError = '';

        foreach ($rows as $row) {
            try {
                $result = self::send((array) $row, $payload);
            } catch (\Throwable $e) {
                $result = [
                    'ok' => false,
                    'status' => 0,
                    'error' => $e->getMessage(),
                ];
                self::recordResult((int) ($row['id'] ?? 0), false, 0, $e->getMessage());
            }

            $lastStatus = (int) ($result['status'] ?? 0);
            $lastError = (string) ($result['error'] ?? '');
            if ($result['ok']) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'total' => count($rows),
            'last_status' => $lastStatus,
            'last_error' => $lastError,
        ];
    }

    private static function send(array $subscription, array $payload): array
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        $jwt = self::vapidJwt($endpoint);
        $publicKey = self::publicKey();
        $body = self::encryptedPayload($subscription, self::payload($payload));

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'TTL: 3600',
                'Urgency: normal',
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'Content-Length: ' . strlen($body),
                'Authorization: vapid t=' . $jwt . ', k=' . $publicKey,
                'Crypto-Key: p256ecdsa=' . $publicKey,
            ],
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $ok = $response !== false && $error === '' && in_array($status, [200, 201, 202, 204], true);
        $message = $error !== '' ? $error : trim((string) $response);
        self::recordResult((int) ($subscription['id'] ?? 0), $ok, $status, $message);

        return ['ok' => $ok, 'status' => $status, 'error' => $message];
    }

    private static function recordResult(int $id, bool $ok, int $status, string $error): void
    {
        if ($id < 1) {
            return;
        }

        if ($ok) {
            Database::connection()
                ->prepare('UPDATE push_subscriptions SET last_success_at = NOW(), failure_count = 0, updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $id]);
            return;
        }

        $deactivate = in_array($status, [404, 410], true) ? ', is_active = 0' : '';
        Database::connection()
            ->prepare('UPDATE push_subscriptions SET last_failure_at = NOW(), failure_count = failure_count + 1' . $deactivate . ', updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    private static function payload(array $payload): string
    {
        $data = array_merge([
            'title' => 'Yenileme Takip Sistemi',
            'body' => 'Yeni yenileme bildirimi var.',
            'url' => '/renewals',
        ], array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== ''));

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Web push icerigi hazirlanamadi.');
        }

        return $json;
    }

    private static function encryptedPayload(array $subscription, string $payload): string
    {
        $clientPublicKey = self::base64UrlDecode((string) ($subscription['public_key'] ?? ''));
        $authSecret = self::base64UrlDecode((string) ($subscription['auth_token'] ?? ''));
        if (strlen($clientPublicKey) !== 65 || strlen($authSecret) < 16) {
            throw new RuntimeException('Web push abonelik anahtari gecersiz.');
        }

        $localKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($localKey === false) {
            throw new RuntimeException('Web push gecici anahtari olusturulamadi.');
        }

        $details = openssl_pkey_get_details($localKey);
        $serverPublicKey = "\x04" . (string) ($details['ec']['x'] ?? '') . (string) ($details['ec']['y'] ?? '');
        if (strlen($serverPublicKey) !== 65) {
            throw new RuntimeException('Web push gecici public key okunamadi.');
        }

        $sharedSecret = openssl_pkey_derive(self::rawPublicKeyToPem($clientPublicKey), $localKey, 32);
        if ($sharedSecret === false || strlen($sharedSecret) !== 32) {
            throw new RuntimeException('Web push ortak anahtari olusturulamadi.');
        }

        $salt = random_bytes(16);
        $context = "WebPush: info\x00" . $clientPublicKey . $serverPublicKey;
        $ikm = self::hkdf($sharedSecret, $authSecret, $context, 32);
        $contentEncryptionKey = self::hkdf($ikm, $salt, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdf($ikm, $salt, "Content-Encoding: nonce\x00", 12);
        $plainText = $payload . "\x02";

        $cipherText = openssl_encrypt(
            $plainText,
            'aes-128-gcm',
            $contentEncryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );

        if ($cipherText === false) {
            throw new RuntimeException('Web push icerigi sifrelenemedi.');
        }

        return $salt
            . pack('N', self::RECORD_SIZE)
            . chr(strlen($serverPublicKey))
            . $serverPublicKey
            . $cipherText
            . $tag;
    }

    private static function rawPublicKeyToPem(string $publicKey): string
    {
        if (strlen($publicKey) !== 65 || ord($publicKey[0]) !== 0x04) {
            throw new RuntimeException('Web push public key formati gecersiz.');
        }

        $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
        if ($prefix === false) {
            throw new RuntimeException('Web push public key hazirlanamadi.');
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($prefix . $publicKey), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function hkdf(string $keyMaterial, string $salt, string $info, int $length): string
    {
        $prk = hash_hmac('sha256', $keyMaterial, $salt, true);
        $lastBlock = '';
        $output = '';
        $counter = 1;

        while (strlen($output) < $length) {
            $lastBlock = hash_hmac('sha256', $lastBlock . $info . chr($counter), $prk, true);
            $output .= $lastBlock;
            $counter++;
        }

        return substr($output, 0, $length);
    }

    private static function keys(): array
    {
        $repo = new SettingsRepository();
        $settings = $repo->all();
        $public = (string) ($settings['webpush.public_key'] ?? '');
        $private = (string) ($settings['webpush.private_key'] ?? '');

        if ($public !== '' && $private !== '') {
            return ['public' => $public, 'private' => $private];
        }

        $keys = self::generateKeys();
        $repo->setMany([
            'webpush.public_key' => $keys['public'],
            'webpush.private_key' => $keys['private'],
        ]);

        return $keys;
    }

    private static function generateKeys(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($key === false || !openssl_pkey_export($key, $privatePem)) {
            throw new RuntimeException('Web push anahtari olusturulamadi.');
        }

        $details = openssl_pkey_get_details($key);
        $x = (string) ($details['ec']['x'] ?? '');
        $y = (string) ($details['ec']['y'] ?? '');
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException('Web push public key okunamadi.');
        }

        return [
            'public' => self::base64UrlEncode("\x04" . $x . $y),
            'private' => $privatePem,
        ];
    }

    private static function vapidJwt(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Push endpoint gecersiz.');
        }

        $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $keys = self::keys();
        $subject = self::subject();

        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
        $claims = self::base64UrlEncode(json_encode([
            'aud' => $audience,
            'exp' => time() + 3600,
            'sub' => $subject,
        ], JSON_UNESCAPED_SLASHES));
        $unsigned = $header . '.' . $claims;

        if (!openssl_sign($unsigned, $derSignature, $keys['private'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Web push imzasi olusturulamadi.');
        }

        return $unsigned . '.' . self::base64UrlEncode(self::ecdsaDerToRaw($derSignature));
    }

    private static function subject(): string
    {
        $email = trim((string) app_config('mail.from_email', ''));
        if ($email !== '') {
            return 'mailto:' . $email;
        }

        return (string) app_config('app.url', 'https://takip.bigabilisim.com');
    }

    private static function ecdsaDerToRaw(string $der): string
    {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('ECDSA imzasi gecersiz.');
        }

        self::readDerLength($der, $offset);
        $r = self::readDerInteger($der, $offset);
        $s = self::readDerInteger($der, $offset);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
            . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    private static function readDerInteger(string $der, int &$offset): string
    {
        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('ECDSA integer gecersiz.');
        }

        $length = self::readDerLength($der, $offset);
        $value = substr($der, $offset, $length);
        $offset += $length;

        return $value;
    }

    private static function readDerLength(string $der, int &$offset): int
    {
        $length = ord($der[$offset++]);
        if ($length < 0x80) {
            return $length;
        }

        $bytes = $length & 0x7f;
        $length = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $length = ($length << 8) | ord($der[$offset++]);
        }

        return $length;
    }

    private static function base64UrlEncode(string|false $data): string
    {
        if ($data === false) {
            return '';
        }

        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padding = str_repeat('=', (4 - strlen($data) % 4) % 4);
        $decoded = base64_decode(strtr($data . $padding, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Base64 web push anahtari gecersiz.');
        }

        return $decoded;
    }
}
