<?php
/**
 * RentConnect Database Configuration & PDO Connection
 * Standard MySQL / MariaDB Relational Database
 */

declare(strict_types=1);

class Database {
    // MySQL / SQL Server Database Credentials
    private static string $host = '127.0.0.1';
    private static string $db   = 'rentconnect';
    private static string $user = 'root';
    private static string $pass = '';
    private static int    $port = 3306;
    private static string $charset = 'utf8mb4';

    private static ?PDO $instance = null;
    private static bool $schemaChecked = false;

    /**
     * Self-healing schema validation: ensures verification columns exist in users table
     * and payments table has payment_type and unpaid status
     */
    private static function ensureVerificationColumns(PDO $pdo): void {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;
        try {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `live_photo_filename` VARCHAR(255) NULL AFTER `id_photo_filename`");
            $pdo->exec("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `verification_notes` TEXT NULL AFTER `id_verified_at`");
            $pdo->exec("ALTER TABLE `payments` ADD COLUMN IF NOT EXISTS `payment_type` VARCHAR(50) NOT NULL DEFAULT 'Rent' AFTER `unit_id`");
            $pdo->exec("ALTER TABLE `payments` MODIFY COLUMN `status` ENUM('pending_setup', 'unpaid', 'pending', 'approved', 'rejected', 'overdue') NOT NULL DEFAULT 'pending_setup'");
        } catch (Throwable $e) {
            // Silently ignore if already present or user lacks DDL permissions
        }
    }

    /**
     * Get singleton PDO MySQL connection instance
     */
    public static function getConnection(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            self::$host,
            self::$port,
            self::$db,
            self::$charset
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            self::$instance = new PDO($dsn, self::$user, self::$pass, $options);
            self::ensureVerificationColumns(self::$instance);
            return self::$instance;
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'status' => 'error',
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'hint' => 'Ensure MySQL is running on port 3306 and database "rentconnect" is imported from database/schema.sql.'
            ], JSON_PRETTY_PRINT);
            exit;
        }
    }
}
