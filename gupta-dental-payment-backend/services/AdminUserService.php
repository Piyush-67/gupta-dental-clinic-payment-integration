<?php
declare(strict_types=1);

final class AdminUserService
{
    private const USERNAME_PATTERN = '/\A[a-z0-9][a-z0-9._-]{2,63}\z/D';

    public static function normalizeUsername(string $username): string
    {
        $username = strtolower(trim($username));

        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            throw new InvalidArgumentException(
                'Username must be 3-64 characters using letters, numbers, dots, underscores or hyphens.'
            );
        }

        return $username;
    }

    public static function validatePassword(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 72) {
            throw new InvalidArgumentException(
                'Passwords must be between 12 and 72 bytes.'
            );
        }
    }

    public static function createFirstAdmin(
        PDO $db,
        string $username,
        string $password
    ): int {
        $username = self::normalizeUsername($username);
        self::validatePassword($password);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if (!is_string($passwordHash)) {
            throw new RuntimeException('Unable to hash the administrator password.');
        }

        return self::transaction($db, function () use (
            $db,
            $username,
            $passwordHash
        ): int {
            $existing = $db->query(
                'SELECT id FROM admin_users ORDER BY id FOR UPDATE'
            )->fetchAll();
            if ($existing !== []) {
                throw new RuntimeException(
                    'An administrator already exists; use the credential-management utility.'
                );
            }

            $stmt = $db->prepare(
                'INSERT INTO admin_users (username, password_hash)
                 VALUES (?, ?)'
            );
            $stmt->execute([$username, $passwordHash]);
            $adminId = (int) $db->lastInsertId();
            self::recordAudit($db, $adminId, 'created');

            return $adminId;
        });
    }

    public static function changeUsername(
        PDO $db,
        string $currentUsername,
        string $newUsername
    ): string {
        $currentUsername = self::normalizeUsername($currentUsername);
        $newUsername = self::normalizeUsername($newUsername);

        if ($currentUsername === $newUsername) {
            throw new InvalidArgumentException('The new username is unchanged.');
        }

        return self::transaction($db, function () use (
            $db,
            $currentUsername,
            $newUsername
        ): string {
            $adminId = self::findAdminId($db, $currentUsername);
            $stmt = $db->prepare(
                'UPDATE admin_users SET username = ? WHERE id = ?'
            );

            try {
                $stmt->execute([$newUsername, $adminId]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new InvalidArgumentException(
                        'That username is already in use.',
                        0,
                        $e
                    );
                }
                throw $e;
            }

            self::recordAudit($db, $adminId, 'username_changed');
            return $newUsername;
        });
    }

    public static function resetPassword(
        PDO $db,
        string $username,
        string $password
    ): void {
        $username = self::normalizeUsername($username);
        self::validatePassword($password);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if (!is_string($passwordHash)) {
            throw new RuntimeException('Unable to hash the administrator password.');
        }

        self::transaction($db, function () use (
            $db,
            $username,
            $passwordHash
        ): void {
            $adminId = self::findAdminId($db, $username);
            $stmt = $db->prepare(
                'UPDATE admin_users
                 SET password_hash = ?, auth_version = auth_version + 1
                 WHERE id = ?'
            );
            $stmt->execute([$passwordHash, $adminId]);
            self::recordAudit($db, $adminId, 'password_reset');
        });
    }

    public static function setActive(
        PDO $db,
        string $username,
        bool $active
    ): bool {
        $username = self::normalizeUsername($username);

        return self::transaction($db, function () use (
            $db,
            $username,
            $active
        ): bool {
            $adminId = self::findAdminId($db, $username);
            $stmt = $db->prepare(
                'SELECT is_active FROM admin_users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$adminId]);
            $currentActive = (int) $stmt->fetchColumn() === 1;

            if ($currentActive === $active) {
                return false;
            }

            if (!$active) {
                $admins = $db->query(
                    'SELECT id, is_active FROM admin_users FOR UPDATE'
                )->fetchAll();
                $activeCount = count(array_filter(
                    $admins,
                    static fn(array $admin): bool => (int) $admin['is_active'] === 1
                ));

                if ($activeCount <= 1) {
                    throw new RuntimeException(
                        'The last active administrator cannot be deactivated.'
                    );
                }
            }

            $stmt = $db->prepare(
                'UPDATE admin_users
                 SET is_active = ?, auth_version = auth_version + ?
                 WHERE id = ?'
            );
            $stmt->execute([$active ? 1 : 0, $active ? 0 : 1, $adminId]);
            self::recordAudit(
                $db,
                $adminId,
                $active ? 'activated' : 'deactivated'
            );

            return true;
        });
    }

    private static function findAdminId(PDO $db, string $username): int
    {
        $stmt = $db->prepare(
            'SELECT id FROM admin_users WHERE username = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$username]);
        $adminId = $stmt->fetchColumn();

        if ($adminId === false) {
            throw new RuntimeException('Administrator account not found.');
        }

        return (int) $adminId;
    }

    private static function recordAudit(
        PDO $db,
        int $adminId,
        string $action
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO admin_audit_log
                (target_admin_id, action, actor_source)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$adminId, $action, 'cli']);
    }

    private static function transaction(PDO $db, callable $callback): mixed
    {
        $nested = $db->inTransaction();
        $savepoint = 'admin_user_service';

        if ($nested) {
            $db->exec('SAVEPOINT ' . $savepoint);
        } else {
            $db->beginTransaction();
        }

        try {
            $result = $callback();
            if ($nested) {
                $db->exec('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $db->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($nested && $db->inTransaction()) {
                $db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $db->exec('RELEASE SAVEPOINT ' . $savepoint);
            } elseif ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
