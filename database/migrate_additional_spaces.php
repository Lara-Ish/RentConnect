<?php
/**
 * Migration: Create additional_spaces table and add billing columns to payments table.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getConnection();

    echo "Running migration for Additional Rental Spaces...\n";

    // 1. Create additional_spaces table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `additional_spaces` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `space_number` INT UNSIGNED NOT NULL UNIQUE,
            `space_name` VARCHAR(50) NOT NULL,
            `monthly_fee` DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
            `status` ENUM('available', 'occupied') NOT NULL DEFAULT 'available',
            `assigned_tenant_id` INT UNSIGNED NULL,
            `assigned_unit_id` INT UNSIGNED NULL,
            `rented_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT `fk_additional_spaces_tenant` 
                FOREIGN KEY (`assigned_tenant_id`) REFERENCES `users` (`id`) 
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_additional_spaces_unit` 
                FOREIGN KEY (`assigned_unit_id`) REFERENCES `units` (`id`) 
                ON DELETE SET NULL ON UPDATE CASCADE,
            INDEX `idx_spaces_status` (`status`),
            INDEX `idx_spaces_tenant` (`assigned_tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo " Table `additional_spaces` created or already exists.\n";

    // Seed the 2 spaces if not yet present
    $count = (int)$pdo->query("SELECT COUNT(*) FROM `additional_spaces`")->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO `additional_spaces` (`space_number`, `space_name`, `monthly_fee`, `status`) VALUES
            (1, 'Space 1', 1500.00, 'available'),
            (2, 'Space 2', 1500.00, 'available')
        ");
        $stmt->execute();
        echo " Seeded 2 rental spaces (Space 1 & Space 2 at ₱1,500/mo each).\n";
    } else {
        echo " `additional_spaces` already has $count spaces seeded.\n";
    }

    // 2. Add columns to `payments` table for detailed line item breakdown
    $cols = $pdo->query("SHOW COLUMNS FROM `payments` LIKE 'base_rent_amount'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("
            ALTER TABLE `payments`
            ADD COLUMN `base_rent_amount` DECIMAL(10,2) NOT NULL DEFAULT 3000.00 AFTER `due_date`,
            ADD COLUMN `additional_space_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `base_rent_amount`,
            ADD COLUMN `additional_spaces_breakdown` VARCHAR(255) NULL DEFAULT NULL AFTER `additional_space_fee`
        ");
        echo " Added `base_rent_amount`, `additional_space_fee`, and `additional_spaces_breakdown` columns to `payments`.\n";
    } else {
        echo " `payments` table already has additional spaces columns.\n";
    }

    echo "Migration completed successfully!\n";

} catch (Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
