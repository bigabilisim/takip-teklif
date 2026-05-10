<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $cachedUser = null;
    private static ?array $cachedPermissions = null;
    private static bool $sessionSchemaEnsured = false;

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM users WHERE email = :email AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !PasswordHasher::verify($password, (string) $user['password_hash'])) {
            return false;
        }

        self::revokeCurrentSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        self::createUserSession((int) $user['id']);
        self::$cachedUser = null;
        self::$cachedPermissions = null;

        $passwordHash = (string) $user['password_hash'];
        if (PasswordHasher::needsRehash($passwordHash)) {
            Database::connection()
                ->prepare('UPDATE users SET password_hash = :password_hash, last_login_at = NOW() WHERE id = :id')
                ->execute([
                    'id' => $user['id'],
                    'password_hash' => PasswordHasher::hash($password),
                ]);
        } else {
            Database::connection()
                ->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
                ->execute(['id' => $user['id']]);
        }

        return true;
    }

    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }

        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $userId = (int) $_SESSION['user_id'];
        if (!self::validateCurrentSession($userId)) {
            self::clearSession();
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, name, email, role FROM users WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        self::$cachedUser = $user ?: null;

        return self::$cachedUser;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function permissions(): array
    {
        if (self::$cachedPermissions !== null) {
            return self::$cachedPermissions;
        }

        $user = self::user();
        if (!$user) {
            self::$cachedPermissions = [];
            return self::$cachedPermissions;
        }

        if (($user['role'] ?? '') === 'admin') {
            self::$cachedPermissions = ['*'];
            return self::$cachedPermissions;
        }

        $stmt = Database::connection()->prepare(
            'SELECT permission_key FROM user_permissions WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => (int) $user['id']]);

        self::$cachedPermissions = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        return self::$cachedPermissions;
    }

    public static function can(string $permission): bool
    {
        $permissions = self::permissions();

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can((string) $permission)) {
                return true;
            }
        }

        return false;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('/login');
        }
    }

    public static function logout(): void
    {
        self::revokeCurrentSession();
        self::clearSession();
        session_regenerate_id(true);
    }

    private static function clearSession(): void
    {
        unset($_SESSION['user_id'], $_SESSION['auth_session_token'], $_SESSION['auth_session_expires_at']);
        self::$cachedUser = null;
        self::$cachedPermissions = null;
    }

    private static function validateCurrentSession(int $userId): bool
    {
        $token = (string) ($_SESSION['auth_session_token'] ?? '');
        if ($token === '') {
            self::createUserSession($userId);
            return true;
        }

        if (!empty($_SESSION['auth_session_expires_at']) && strtotime((string) $_SESSION['auth_session_expires_at']) < time()) {
            self::revokeCurrentSession();
            return false;
        }

        self::ensureSessionSchema();
        $stmt = Database::connection()->prepare(
            'SELECT id, expires_at, revoked_at
             FROM user_sessions
             WHERE user_id = :user_id
               AND token_hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
        ]);
        $session = $stmt->fetch();

        if (!$session || !empty($session['revoked_at']) || strtotime((string) $session['expires_at']) < time()) {
            return false;
        }

        $_SESSION['auth_session_expires_at'] = (string) $session['expires_at'];
        Database::connection()
            ->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE id = :id AND last_seen_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)')
            ->execute(['id' => (int) $session['id']]);

        return true;
    }

    private static function createUserSession(int $userId): void
    {
        self::ensureSessionSchema();
        self::pruneExpiredSessions($userId);

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable('+' . self::sessionLifetimeSeconds() . ' seconds'))->format('Y-m-d H:i:s');

        Database::connection()
            ->prepare(
                'INSERT INTO user_sessions
                    (user_id, token_hash, session_id, ip_address, user_agent, expires_at, last_seen_at, created_at)
                 VALUES
                    (:user_id, :token_hash, :session_id, :ip_address, :user_agent, :expires_at, NOW(), NOW())'
            )
            ->execute([
                'user_id' => $userId,
                'token_hash' => hash('sha256', $token),
                'session_id' => session_id(),
                'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'expires_at' => $expiresAt,
            ]);

        $_SESSION['auth_session_token'] = $token;
        $_SESSION['auth_session_expires_at'] = $expiresAt;

        self::enforceDeviceLimit($userId);
    }

    private static function revokeCurrentSession(): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $token = (string) ($_SESSION['auth_session_token'] ?? '');
        if ($userId < 1 || $token === '') {
            return;
        }

        self::ensureSessionSchema();
        Database::connection()
            ->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = :user_id AND token_hash = :token_hash AND revoked_at IS NULL')
            ->execute([
                'user_id' => $userId,
                'token_hash' => hash('sha256', $token),
            ]);
    }

    private static function pruneExpiredSessions(int $userId): void
    {
        Database::connection()
            ->prepare('UPDATE user_sessions SET revoked_at = COALESCE(revoked_at, NOW()) WHERE user_id = :user_id AND expires_at < NOW()')
            ->execute(['user_id' => $userId]);
    }

    private static function enforceDeviceLimit(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT id
             FROM user_sessions
             WHERE user_id = :user_id
               AND revoked_at IS NULL
               AND expires_at > NOW()
             ORDER BY last_seen_at DESC, id DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        $activeIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $revokeIds = array_slice($activeIds, self::maxActiveSessions());
        if ($revokeIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($revokeIds), '?'));
        Database::connection()->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE id IN ({$placeholders})")
            ->execute($revokeIds);
    }

    private static function ensureSessionSchema(): void
    {
        if (self::$sessionSchemaEnsured) {
            return;
        }

        Database::connection()->exec(
            "CREATE TABLE IF NOT EXISTS user_sessions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                session_id VARCHAR(128) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                expires_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_sessions_token (token_hash),
                INDEX idx_user_sessions_user_active (user_id, revoked_at, expires_at),
                INDEX idx_user_sessions_expires_at (expires_at),
                CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$sessionSchemaEnsured = true;
    }

    private static function maxActiveSessions(): int
    {
        return max(3, (int) \app_config('auth.max_active_sessions', 3));
    }

    private static function sessionLifetimeSeconds(): int
    {
        return max(604800, (int) \app_config('auth.session_lifetime', 604800));
    }
}
