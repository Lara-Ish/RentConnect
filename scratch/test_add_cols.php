<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

// Check if MariaDB / MySQL supports ADD COLUMN IF NOT EXISTS or check information_schema
try {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `live_photo_filename` VARCHAR(255) NULL AFTER `id_photo_filename`");
    $pdo->exec("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `verification_notes` TEXT NULL AFTER `id_verified_at`");
    echo "Added columns using IF NOT EXISTS.\n";
} catch (Throwable $e) {
    echo "IF NOT EXISTS failed: " . $e->getMessage() . "\n";
    // Fallback using information_schema
    $cols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('live_photo_filename', $cols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `live_photo_filename` VARCHAR(255) NULL AFTER `id_photo_filename`");
    }
    if (!in_array('verification_notes', $cols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `verification_notes` TEXT NULL AFTER `id_verified_at`");
    }
    echo "Added columns via fallback.\n";
}

$cols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
print_r($cols);
