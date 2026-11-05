<?php
/**
 * Migration: Create announcements and announcement_recipients tables
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getConnection();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `announcements` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `owner_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `due_date` DATE NULL,
            `target_type` ENUM('all', 'selected') NOT NULL DEFAULT 'all',
            `recipient_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_owner` (`owner_id`),
            CONSTRAINT `fk_announcements_owner` 
                FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) 
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "announcements table ensured.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `announcement_recipients` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `announcement_id` INT UNSIGNED NOT NULL,
            `tenant_id` INT UNSIGNED NOT NULL,
            `email` VARCHAR(191) NOT NULL,
            `status` ENUM('sent', 'failed', 'mock_sent') NOT NULL DEFAULT 'sent',
            `error_message` TEXT NULL,
            `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_announcement` (`announcement_id`),
            INDEX `idx_tenant` (`tenant_id`),
            CONSTRAINT `fk_ann_rec_ann` 
                FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) 
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_ann_rec_tenant` 
                FOREIGN KEY (`tenant_id`) REFERENCES `users` (`id`) 
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "announcement_recipients table ensured.\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
