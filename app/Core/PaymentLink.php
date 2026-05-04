<?php

declare(strict_types=1);

namespace App\Core;

final class PaymentLink
{
    public static function urlForRenewal(int $renewalId, int $ttlDays = 60, string $recipientEmail = ''): string
    {
        $expires = time() + (max(1, $ttlDays) * 86400);
        $email = self::normalizeEmail($recipientEmail);
        $signature = self::signature($renewalId, $expires, $email);
        $query = [
            'expires' => $expires,
            'sig' => $signature,
        ];

        if ($email !== '') {
            $query['email'] = $email;
        }

        return \url('/renewals/' . $renewalId . '/payment') . '?' . http_build_query($query);
    }

    public static function isValid(int $renewalId, string $expires, string $signature, string $recipientEmail = ''): bool
    {
        if (!ctype_digit($expires) || (int) $expires < time()) {
            return false;
        }

        $expected = self::signature($renewalId, (int) $expires, self::normalizeEmail($recipientEmail));

        return $signature !== '' && hash_equals($expected, $signature);
    }

    public static function normalizeEmail(string $email): string
    {
        $email = trim(mb_strtolower($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private static function signature(int $renewalId, int $expires, string $email = ''): string
    {
        $payload = $renewalId . '|' . $expires;
        if ($email !== '') {
            $payload .= '|' . $email;
        }

        return hash_hmac('sha256', $payload, self::secret());
    }

    private static function secret(): string
    {
        $path = ROOT_PATH . '/storage/payment_link_secret.key';
        if (is_file($path)) {
            $secret = trim((string) file_get_contents($path));
            if ($secret !== '') {
                return $secret;
            }
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $secret = bin2hex(random_bytes(32));
        file_put_contents($path, $secret, LOCK_EX);
        @chmod($path, 0640);

        return $secret;
    }
}
