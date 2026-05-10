<?php

declare(strict_types=1);

namespace App\Core;

final class PasswordHasher
{
    public static function hash(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 3,
                'threads' => 1,
            ]);
        }

        return password_hash($password, PASSWORD_BCRYPT, [
            'cost' => 12,
        ]);
    }

    public static function verify(string $password, string $storedHash): bool
    {
        if (self::isSha256Hash($storedHash)) {
            return hash_equals(substr($storedHash, 7), hash('sha256', $password));
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $storedHash) === 1) {
            return hash_equals(strtolower($storedHash), hash('sha256', $password));
        }

        return password_verify($password, $storedHash);
    }

    public static function needsRehash(string $storedHash): bool
    {
        if (self::isSha256Hash($storedHash) || preg_match('/^[a-f0-9]{64}$/i', $storedHash) === 1) {
            return true;
        }

        if (defined('PASSWORD_ARGON2ID')) {
            return password_needs_rehash($storedHash, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 3,
                'threads' => 1,
            ]);
        }

        return password_needs_rehash($storedHash, PASSWORD_BCRYPT, [
            'cost' => 12,
        ]);
    }

    private static function isSha256Hash(string $storedHash): bool
    {
        return str_starts_with($storedHash, 'sha256$')
            && preg_match('/^[a-f0-9]{64}$/i', substr($storedHash, 7)) === 1;
    }
}
