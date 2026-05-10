<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\PasswordHasher;
use PDO;

final class UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->ensureSchema();
    }

    public function all(): array
    {
        $rows = $this->db->query(
            "SELECT u.id,
                    u.name,
                    u.email,
                    u.role,
                    u.is_active,
                    u.last_login_at,
                    u.deleted_at,
                    u.created_at,
                    GROUP_CONCAT(up.permission_key ORDER BY up.permission_key SEPARATOR ',') AS permissions
             FROM users u
             LEFT JOIN user_permissions up ON up.user_id = u.id
             WHERE u.deleted_at IS NULL
             GROUP BY u.id, u.name, u.email, u.role, u.is_active, u.last_login_at, u.deleted_at, u.created_at
             ORDER BY u.is_active DESC, u.name ASC"
        )->fetchAll();

        return array_map([$this, 'withPermissionList'], $rows);
    }

    public function deleted(): array
    {
        $rows = $this->db->query(
            "SELECT u.id,
                    u.name,
                    u.email,
                    u.role,
                    u.is_active,
                    u.last_login_at,
                    u.deleted_at,
                    u.created_at,
                    GROUP_CONCAT(up.permission_key ORDER BY up.permission_key SEPARATOR ',') AS permissions
             FROM users u
             LEFT JOIN user_permissions up ON up.user_id = u.id
             WHERE u.deleted_at IS NOT NULL
             GROUP BY u.id, u.name, u.email, u.role, u.is_active, u.last_login_at, u.deleted_at, u.created_at
             ORDER BY u.deleted_at DESC, u.name ASC"
        )->fetchAll();

        return array_map([$this, 'withPermissionList'], $rows);
    }

    public function find(int $id, bool $includeDeleted = false): ?array
    {
        $deletedSql = $includeDeleted ? '' : ' AND u.deleted_at IS NULL';
        $stmt = $this->db->prepare(
            "SELECT u.id,
                    u.name,
                    u.email,
                    u.role,
                    u.is_active,
                    u.last_login_at,
                    u.deleted_at,
                    u.created_at,
                    GROUP_CONCAT(up.permission_key ORDER BY up.permission_key SEPARATOR ',') AS permissions
             FROM users u
             LEFT JOIN user_permissions up ON up.user_id = u.id
             WHERE u.id = :id{$deletedSql}
             GROUP BY u.id, u.name, u.email, u.role, u.is_active, u.last_login_at, u.deleted_at, u.created_at
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->withPermissionList($row) : null;
    }

    public function findByEmail(string $email, bool $activeOnly = false): ?array
    {
        $sql = 'SELECT id, name, email, password_hash, role, is_active, last_login_at, created_at
                FROM users
                WHERE email = :email AND deleted_at IS NULL';

        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(array $data, array $permissions): int
    {
        $normalized = $this->normalize($data, true);

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO users (name, email, password_hash, role, is_active)
                 VALUES (:name, :email, :password_hash, :role, :is_active)'
            );
            $stmt->execute($normalized);
            $id = (int) $this->db->lastInsertId();

            if ($normalized['role'] !== 'admin') {
                $this->syncPermissions($id, $permissions);
            }

            $this->db->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data, array $permissions): void
    {
        $normalized = $this->normalize($data, false);
        $normalized['id'] = $id;

        $this->db->beginTransaction();

        try {
            $passwordSql = '';
            if ($normalized['password_hash'] !== null) {
                $passwordSql = 'password_hash = :password_hash,';
            } else {
                unset($normalized['password_hash']);
            }

            $stmt = $this->db->prepare(
                "UPDATE users SET
                    name = :name,
                    email = :email,
                    {$passwordSql}
                    role = :role,
                    is_active = :is_active,
                    updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute($normalized);

            $this->syncPermissions($id, $normalized['role'] === 'admin' ? [] : $permissions);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updatePassword(int $id, string $password): void
    {
        $this->setPasswordHash($id, PasswordHasher::hash($password));
    }

    public function setPasswordHash(int $id, string $passwordHash): void
    {
        $this->db->prepare('UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id')
            ->execute([
                'id' => $id,
                'password_hash' => $passwordHash,
            ]);
    }

    public function activeAdminCount(): int
    {
        return (int) $this->db
            ->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL")
            ->fetchColumn();
    }

    public function delete(int $id): void
    {
        $this->db->prepare('UPDATE users SET is_active = 0, deleted_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL')
            ->execute(['id' => $id]);
    }

    public function restore(int $id): void
    {
        $this->db->prepare('UPDATE users SET is_active = 1, deleted_at = NULL, updated_at = NOW() WHERE id = :id AND deleted_at IS NOT NULL')
            ->execute(['id' => $id]);
    }

    private function syncPermissions(int $id, array $permissions): void
    {
        $valid = \permission_keys();
        $permissions = array_values(array_unique(array_filter(
            array_map('strval', $permissions),
            static fn (string $permission): bool => in_array($permission, $valid, true)
        )));

        $this->db->prepare('DELETE FROM user_permissions WHERE user_id = :user_id')
            ->execute(['user_id' => $id]);

        if ($permissions === []) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO user_permissions (user_id, permission_key) VALUES (:user_id, :permission_key)'
        );

        foreach ($permissions as $permission) {
            $stmt->execute(['user_id' => $id, 'permission_key' => $permission]);
        }
    }

    private function normalize(array $data, bool $creating): array
    {
        $role = (string) ($data['role'] ?? 'staff');
        if (!in_array($role, ['admin', 'staff'], true)) {
            $role = 'staff';
        }

        $password = (string) ($data['password'] ?? '');

        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'email' => strtolower(trim((string) ($data['email'] ?? ''))),
            'password_hash' => $password !== '' ? PasswordHasher::hash($password) : ($creating ? '' : null),
            'role' => $role,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
    }

    private function withPermissionList(array $row): array
    {
        $raw = (string) ($row['permissions'] ?? '');
        $row['permissions'] = $raw === '' ? [] : array_values(array_filter(explode(',', $raw)));

        return $row;
    }

    private function ensureSchema(): void
    {
        if (!$this->columnExists('users', 'deleted_at')) {
            $this->db->exec('ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER last_login_at');
        }

        $this->addIndexIfMissing('users', 'idx_users_deleted_at', 'CREATE INDEX idx_users_deleted_at ON users (deleted_at)');
        $this->addIndexIfMissing('users', 'idx_users_active_deleted', 'CREATE INDEX idx_users_active_deleted ON users (is_active, deleted_at)');
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function addIndexIfMissing(string $table, string $index, string $sql): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND INDEX_NAME = :index_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'index_name' => $index,
        ]);

        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec($sql);
        }
    }
}
