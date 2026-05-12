<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class InternalNotifier
{
    public static function recipient(): ?array
    {
        static $recipient = false;
        if ($recipient !== false) {
            return $recipient;
        }

        try {
            $stmt = Database::connection()->query(
                "SELECT id, name, email, role
                 FROM users
                 WHERE is_active = 1
                   AND deleted_at IS NULL
                 ORDER BY
                   CASE
                     WHEN LOWER(name) LIKE '%bilal%' AND LOWER(name) LIKE '%bozduman%' THEN 0
                     WHEN LOWER(email) LIKE '%bilal%' THEN 1
                     WHEN role = 'admin' THEN 2
                     ELSE 3
                   END,
                   id ASC
                 LIMIT 1"
            );
            $row = $stmt->fetch();
            $recipient = $row ?: null;
        } catch (Throwable $e) {
            error_log('İç bildirim alıcısı bulunamadı: ' . $e->getMessage());
            $recipient = null;
        }

        return $recipient;
    }

    public static function push(string $title, string $body, string $urlPath, string $tag = ''): array
    {
        $recipient = self::recipient();
        $payload = [
            'title' => $title,
            'body' => $body,
            'url' => $urlPath,
            'tag' => $tag !== '' ? $tag : 'takip-' . hash('sha1', $title . '|' . $body . '|' . $urlPath),
        ];

        try {
            $result = $recipient !== null
                ? WebPush::sendToUser((int) $recipient['id'], $payload)
                : WebPush::sendToAll($payload);

            if ($recipient !== null) {
                $otherResult = WebPush::sendToAllExceptUser((int) $recipient['id'], $payload);
                $result['sent'] = (int) ($result['sent'] ?? 0) + (int) ($otherResult['sent'] ?? 0);
                $result['failed'] = (int) ($result['failed'] ?? 0) + (int) ($otherResult['failed'] ?? 0);
                $result['total'] = (int) ($result['total'] ?? 0) + (int) ($otherResult['total'] ?? 0);
            }
        } catch (Throwable $e) {
            $result = [
                'sent' => 0,
                'failed' => 1,
                'total' => 0,
                'last_status' => 0,
                'last_error' => $e->getMessage(),
            ];
        }

        if ((int) ($result['sent'] ?? 0) < 1) {
            error_log('İç bildirim push gönderilemedi: ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $result;
    }

    public static function mail(string $subject, string $body): array
    {
        $recipient = self::recipient();
        $email = trim((string) ($recipient['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result = ['ok' => false, 'error' => 'İç bildirim maili için geçerli Bilal e-postası bulunamadı.'];
            error_log((string) $result['error']);

            return $result;
        }

        $result = Mailer::sendWithResult($email, $subject, $body, true);
        if (empty($result['ok'])) {
            error_log('İç bildirim maili gönderilemedi: ' . (string) ($result['error'] ?? 'transport-failed'));
        }

        return $result;
    }
}
