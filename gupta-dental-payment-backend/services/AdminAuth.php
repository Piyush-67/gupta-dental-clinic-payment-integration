<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminUserService.php';

final class AdminAuth
{
    private const SESSION_TIMEOUT_SECONDS = 1800;
    private const USERNAME_ATTEMPT_LIMIT = 5;
    private const IP_ATTEMPT_LIMIT = 30;
    private const GLOBAL_ATTEMPT_LIMIT = 100;
    private const MAX_USERNAME_BUCKETS_PER_IP = 8;
    private static ?string $dummyPasswordHash = null;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            throw new RuntimeException('Session headers have already been sent.');
        }

        session_name('gdc_admin_session');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        $https = isset($_SERVER['HTTPS'])
            && strtolower((string) $_SERVER['HTTPS']) !== 'off'
            && (string) $_SERVER['HTTPS'] !== '';

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Unable to start an administrator session.');
        }

        if (isset($_SESSION['admin_id'])) {
            $lastActivity = $_SESSION['admin_last_activity'] ?? null;

            if (
                !is_int($lastActivity) ||
                time() - $lastActivity > self::SESSION_TIMEOUT_SECONDS
            ) {
                $_SESSION = [];
                if (!session_regenerate_id(true)) {
                    throw new RuntimeException('Unable to rotate the session ID.');
                }
            } else {
                $_SESSION['admin_last_activity'] = time();
            }
        }
    }

    public static function setSecurityHeaders(): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header(
            "Content-Security-Policy: default-src 'self'; " .
            "style-src 'self' https://fonts.googleapis.com; " .
            "font-src https://fonts.gstatic.com; img-src 'self' data:; " .
            "script-src 'self'; connect-src 'self'; form-action 'self'; " .
            "frame-ancestors 'none'; base-uri 'self'"
        );
    }

    public static function hasAdminSession(): bool
    {
        self::start();

        return isset(
            $_SESSION['admin_id'],
            $_SESSION['admin_username'],
            $_SESSION['admin_auth_version']
        )
            && is_int($_SESSION['admin_id'])
            && is_string($_SESSION['admin_username'])
            && is_int($_SESSION['admin_auth_version']);
    }

    public static function isAuthenticated(PDO $db): bool
    {
        if (!self::hasAdminSession()) {
            return false;
        }

        $stmt = $db->prepare(
            'SELECT username, auth_version, is_active
             FROM admin_users
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch();

        if (
            !$admin ||
            (int) $admin['is_active'] !== 1 ||
            (int) $admin['auth_version'] !== $_SESSION['admin_auth_version']
        ) {
            self::destroy();
            return false;
        }

        $_SESSION['admin_username'] = (string) $admin['username'];
        return true;
    }

    public static function csrfToken(): string
    {
        self::start();

        if (
            !isset($_SESSION['admin_csrf_token']) ||
            !is_string($_SESSION['admin_csrf_token'])
        ) {
            $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['admin_csrf_token'];
    }

    public static function verifyCsrf(string $token): bool
    {
        self::start();
        $expected = $_SESSION['admin_csrf_token'] ?? null;

        return is_string($expected)
            && $expected !== ''
            && hash_equals($expected, $token);
    }

    public static function authenticate(
        PDO $db,
        string $username,
        string $password
    ): bool {
        self::start();

        $validUsername = true;
        try {
            $username = AdminUserService::normalizeUsername($username);
        } catch (InvalidArgumentException $e) {
            $username = strtolower(trim($username));
            $validUsername = false;
        }

        $validPassword = $password !== '' && strlen($password) <= 72;
        $verificationCandidate = $validPassword ? $password : 'invalid-password';

        $usernameHash = hash('sha256', $username);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($clientIp) || filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            $clientIp = 'unknown';
        }

        $rateLimit = $db->prepare(
            'SELECT
                COALESCE(SUM(username_hash = ?), 0) AS username_attempts,
                COALESCE(SUM(client_ip = ?), 0) AS ip_attempts,
                COUNT(*) AS global_attempts,
                COUNT(DISTINCT CASE
                    WHEN client_ip = ? THEN username_hash
                    ELSE NULL
                END) AS ip_username_buckets
             FROM admin_login_attempts
             WHERE attempted_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)'
        );
        $rateLimit->execute([$usernameHash, $clientIp, $clientIp]);
        $counts = $rateLimit->fetch();

        if (
            (int) $counts['username_attempts'] >= self::USERNAME_ATTEMPT_LIMIT ||
            (int) $counts['ip_attempts'] >= self::IP_ATTEMPT_LIMIT ||
            (int) $counts['global_attempts'] >= self::GLOBAL_ATTEMPT_LIMIT ||
            (int) $counts['ip_username_buckets'] >= self::MAX_USERNAME_BUCKETS_PER_IP
        ) {
            password_verify($verificationCandidate, self::dummyPasswordHash());
            return false;
        }

        if (!$validUsername || !$validPassword) {
            password_verify($verificationCandidate, self::dummyPasswordHash());
            $attempt = $db->prepare(
                'INSERT INTO admin_login_attempts (username_hash, client_ip)
                 VALUES (?, ?)'
            );
            $attempt->execute([$usernameHash, $clientIp]);
            return false;
        }

        $stmt = $db->prepare(
            'SELECT id, username, password_hash, auth_version, is_active
             FROM admin_users
             WHERE username = ?
             LIMIT 1'
        );
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        $passwordHash = $admin
            ? (string) $admin['password_hash']
            : self::dummyPasswordHash();
        $passwordMatches = password_verify($password, $passwordHash);

        if (!$admin || (int) $admin['is_active'] !== 1 || !$passwordMatches) {
            $attempt = $db->prepare(
                'INSERT INTO admin_login_attempts (username_hash, client_ip)
                 VALUES (?, ?)'
            );
            $attempt->execute([$usernameHash, $clientIp]);
            return false;
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to rotate the session ID.');
        }

        $_SESSION = [
            'admin_id' => (int) $admin['id'],
            'admin_username' => (string) $admin['username'],
            'admin_auth_version' => (int) $admin['auth_version'],
            'admin_last_activity' => time(),
            'admin_csrf_token' => bin2hex(random_bytes(32)),
        ];

        $clearAttempts = $db->prepare(
            'DELETE FROM admin_login_attempts WHERE username_hash = ?'
        );
        $clearAttempts->execute([$usernameHash]);

        return true;
    }

    public static function pruneExpiredLoginAttempts(PDO $db): int
    {
        $deleted = $db->exec(
            'DELETE FROM admin_login_attempts
             WHERE attempted_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)'
        );
        if ($deleted === false) {
            throw new RuntimeException('Unable to prune expired login attempts.');
        }

        return $deleted;
    }

    public static function adminId(): int
    {
        if (!self::hasAdminSession()) {
            throw new RuntimeException('Administrator authentication is required.');
        }

        return $_SESSION['admin_id'];
    }

    public static function adminUsername(): string
    {
        if (!self::hasAdminSession()) {
            throw new RuntimeException('Administrator authentication is required.');
        }

        return $_SESSION['admin_username'];
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Strict',
            ]);
        }

        if (!session_destroy()) {
            throw new RuntimeException('Unable to destroy the administrator session.');
        }
    }

    private static function dummyPasswordHash(): string
    {
        if (self::$dummyPasswordHash === null) {
            self::$dummyPasswordHash = password_hash(
                bin2hex(random_bytes(32)),
                PASSWORD_DEFAULT
            );
        }

        return self::$dummyPasswordHash;
    }
}
