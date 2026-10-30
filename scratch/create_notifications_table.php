<?php
/**
 * Migration script: Create notifications table and initial index setup
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$pdo = Database::getConnection();

$sql = "
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `role` ENUM('landlord', 'tenant') NOT NULL DEFAULT 'tenant',
  `type` VARCHAR(50) NOT NULL DEFAULT 'general',
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `reference_id` INT(10) UNSIGNED DEFAULT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_user_read` (`user_id`, `is_read`),
  INDEX `idx_user_created` (`user_id`, `created_at`),
  INDEX `idx_ref` (`reference_id`, `reference_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

$pdo->exec($sql);
echo "Notifications table created successfully.\n";

// Describe table to verify
$cols = $pdo->query("DESCRIBE `notifications`")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo " - {$c['Field']} ({$c['Type']})\n";
}
