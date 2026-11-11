<?php
/**
 * RentConnect - Session Management & Role-Based Authentication
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

class Auth {
    /**
     * Check if a user is currently authenticated
     */
    public static function check(): bool {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
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
    public static function attempt(string $username, string $password): bool {
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
            session_regenerate_id(true);

            $_SESSION['user_id']     = (int)$user['id'];
            $_SESSION['username']    = $user['username'];
            $_SESSION['name']        = $user['name'];
            $_SESSION['email']       = $user['email'];
            $_SESSION['role']        = $user['role'];
            $_SESSION['phone']       = $user['phone'];
            $_SESSION['unit_id']     = $user['unit_id'] ? (int)$user['unit_id'] : null;
            $_SESSION['unit_number'] = $user['unit_number'] ?? null;

            return true;
        }

        return false;
    }

    /**
     * Terminate session and log user out
     */
    public static function logout(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}
