<?php
/**
 * Migration: Create password_resets and email_logs tables
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getConnection();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `password_resets` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `email` VARCHAR(191) NOT NULL,
            `token_hash` VARCHAR(64) NOT NULL,
            `ip_address` VARCHAR(45) NULL,
            `expires_at` DATETIME NOT NULL,
            `used_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_token_hash` (`token_hash`),
            INDEX `idx_user_resets` (`user_id`, `expires_at`),
            CONSTRAINT `fk_password_resets_user` 
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) 
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "password_resets table ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `email_logs` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `recipient_email` VARCHAR(191) NOT NULL,
            `recipient_name` VARCHAR(100) NULL,
            `subject` VARCHAR(255) NOT NULL,
            `template_type` VARCHAR(50) NOT NULL,
            `reference_id` INT UNSIGNED NULL,
            `reference_type` VARCHAR(50) NULL,
            `status` ENUM('sent', 'failed', 'mock_sent') NOT NULL DEFAULT 'sent',
            `error_message` TEXT NULL,
            `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_email_dedup` (`recipient_email`, `template_type`, `reference_id`, `reference_type`),
            INDEX `idx_sent_at` (`sent_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "email_logs table ensured.\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
