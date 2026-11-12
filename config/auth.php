<?php
/**
 * RentConnect - Session Management & Role-Based Authentication
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/access_control.php';

class Auth {
    private const REMEMBER_COOKIE = 'tararent_remember';
    private const SAVED_LOGIN_COOKIE = 'tararent_saved_login';
    private const REMEMBER_TTL_SECONDS = 2592000; // 30 days

    /**
     * Derive a stable server-side HMAC key for Remember Me cookies without altering schema.
     */
    private static function getRememberSecret(): string {
        $envSecret = getenv('APP_KEY') ?: (getenv('APP_SECRET') ?: '');
        if ($envSecret !== '') {
            return (string)$envSecret;
        }
        $dbFile = __DIR__ . '/db.php';
        $anchor = (realpath($dbFile) ?: $dbFile) . '|' . (string)@fileinode($dbFile) . '|' . php_uname('n');
        return hash('sha256', 'TaraRentRememberV1|' . $anchor);
    }

    /**
     * Build a cookie options array compatible with HTTP localhost and HTTPS deployments.
     */
    private static function cookieOptions(int $expires, bool $httpOnly = true): array {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        return [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => $httpOnly,
            'samesite' => 'Lax'
        ];
    }

    /**
     * Write a cookie safely when headers have not yet been sent.
     */
    private static function safeSetCookie(string $name, string $value, int $expires, bool $httpOnly = true): void {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie($name, $value, self::cookieOptions($expires, $httpOnly));
        }
        if ($expires < time()) {
            unset($_COOKIE[$name]);
        } else {
            $_COOKIE[$name] = $value;
        }
    }

    /**
     * Store user identity fields in the active PHP session.
     */
    private static function populateSession(array $user): void {
        $_SESSION['user_id']     = (int)$user['id'];
        $_SESSION['username']    = $user['username'];
        $_SESSION['name']        = $user['name'];
        $_SESSION['email']       = $user['email'];
        $_SESSION['role']        = $user['role'];
        $_SESSION['phone']       = $user['phone'];
        $_SESSION['unit_id']     = !empty($user['unit_id']) ? (int)$user['unit_id'] : null;
        $_SESSION['unit_number'] = $user['unit_number'] ?? null;
    }

    /**
     * Sign and set persistent Remember Me token + convenience login identifier cookie.
     */
    private static function issueRememberCookies(array $user, string $loginInput): void {
        $expires = time() + self::REMEMBER_TTL_SECONDS;
        $userId = (int)$user['id'];
        $hashFingerprint = substr((string)($user['password_hash'] ?? ''), 0, 24);
        $payload = $userId . ':' . $expires;
        $sig = hash_hmac('sha256', $payload . ':' . $hashFingerprint, self::getRememberSecret());
        $token = base64_encode($payload . ':' . $sig);

        // Extend active session cookie lifetime to match Remember Me duration
        if (session_status() === PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(session_name(), session_id(), self::cookieOptions($expires, true));
        }

        self::safeSetCookie(self::REMEMBER_COOKIE, $token, $expires, true);
        self::safeSetCookie(self::SAVED_LOGIN_COOKIE, $loginInput, $expires, true);
    }

    /**
     * Clear persistent Remember Me cookies.
     */
    public static function clearRememberCookies(bool $clearSavedLogin = false): void {
        $past = time() - 42000;
        self::safeSetCookie(self::REMEMBER_COOKIE, '', $past, true);
        if ($clearSavedLogin) {
            self::safeSetCookie(self::SAVED_LOGIN_COOKIE, '', $past, true);
        }
    }

    /**
     * Return the last remembered login identifier (email/username) if present.
     */
    public static function getSavedLoginIdentifier(): string {
        return isset($_COOKIE[self::SAVED_LOGIN_COOKIE]) ? trim((string)$_COOKIE[self::SAVED_LOGIN_COOKIE]) : '';
    }

    /**
     * Attempt to transparently resume an authenticated session from a valid Remember Me cookie.
     */
    private static function resumeFromRememberCookie(): bool {
        $rawCookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (!is_string($rawCookie) || $rawCookie === '') {
            return false;
        }

        $decoded = base64_decode($rawCookie, true);
        if ($decoded === false) {
            self::clearRememberCookies(false);
            return false;
        }

        $parts = explode(':', $decoded, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            self::clearRememberCookies(false);
            return false;
        }

        $userId = (int)$parts[0];
        $expires = (int)$parts[1];
        $providedSig = $parts[2];

        if ($userId <= 0 || $expires < time()) {
            self::clearRememberCookies(false);
            return false;
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("
                SELECT u.id, u.unit_id, u.username, u.name, u.email, u.password_hash, u.role, u.phone, u.is_active,
                       un.unit_number
                FROM users u
                LEFT JOIN units un ON u.unit_id = un.id
                WHERE u.id = :id AND u.is_active = 1 AND (u.is_archived = 0 OR u.is_archived IS NULL)
                LIMIT 1
            ");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();

            if (!$user) {
                self::clearRememberCookies(false);
                return false;
            }

            $hashFingerprint = substr((string)($user['password_hash'] ?? ''), 0, 24);
            $expectedSig = hash_hmac('sha256', $userId . ':' . $expires . ':' . $hashFingerprint, self::getRememberSecret());
            if (!hash_equals($expectedSig, $providedSig)) {
                self::clearRememberCookies(false);
                return false;
            }

            if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                session_regenerate_id(true);
            }
            self::populateSession($user);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Check if a user is currently authenticated
     */
    public static function check(): bool {
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            return true;
        }
        return self::resumeFromRememberCookie();
    }

    /**
     * Retrieve the current logged-in user data
     */
    public static function user(): ?array {
        if (!self::check()) {
            return null;
        }

        return [
            'id' => (int)$_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'name' => $_SESSION['name'] ?? '',
            'email' => $_SESSION['email'] ?? '',
            'role' => $_SESSION['role'] ?? 'tenant',
            'phone' => $_SESSION['phone'] ?? '',
            'unit_id' => isset($_SESSION['unit_id']) ? (int)$_SESSION['unit_id'] : null,
            'unit_number' => $_SESSION['unit_number'] ?? null,
            'unit_name' => $_SESSION['unit_name'] ?? null
        ];
    }

    /**
     * Check if current user is the Landlord (Admin)
     */
    public static function isLandlord(): bool {
        return self::check() && in_array(strtolower((string)($_SESSION['role'] ?? '')), ['landlord', 'admin'], true);
    }

    /**
     * Check if current user is the Tenant
     */
    public static function isTenant(): bool {
        return self::check() && ($_SESSION['role'] ?? '') === 'tenant';
    }

    /**
     * Require authentication; redirect to login if guest
     */
    public static function requireAuth(): void {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    /**
     * Guard: Require Landlord role
     */
    public static function requireLandlord(): void {
        self::requireAuth();
        if (!self::isLandlord()) {
            header('Location: tenant_portal.php');
            exit;
        }
    }

    /**
     * Guard: Require Tenant role
     */
    public static function requireTenant(): void {
        self::requireAuth();
        if (!self::isTenant()) {
            header('Location: admin_dashboard.php');
            exit;
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)($_SESSION['user_id'] ?? 0)]);
        $active = $stmt->fetchColumn();
        if ($active === false || (int)$active !== 1) {
            self::logout();
            header('Location: login.php?msg=deactivated');
            exit;
        }
    }

    /**
     * Authenticate user credentials against SQL database
     */
    public static function attempt(string $username, string $password, bool $rememberMe = false): bool {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT u.id, u.unit_id, u.username, u.name, u.email, u.password_hash, u.role, u.phone, u.is_active,
                   un.unit_number
            FROM users u
            LEFT JOIN units un ON u.unit_id = un.id
            WHERE (u.username = :u1 OR u.email = :u2) AND u.is_active = 1 AND (u.is_archived = 0 OR u.is_archived IS NULL)
            LIMIT 1
        ");
        $stmt->execute([':u1' => $username, ':u2' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                session_regenerate_id(true);
            }

            self::populateSession($user);

            if ($rememberMe) {
                self::issueRememberCookies($user, $username);
            } else {
                self::clearRememberCookies(true);
            }

            return true;
        }

        return false;
    }

    /**
     * Terminate session and log user out
     */
    public static function logout(): void {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        $_SESSION = [];
        self::clearRememberCookies(false);
        if (ini_get("session.use_cookies") && PHP_SAPI !== 'cli' && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
