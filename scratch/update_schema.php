<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$pdo = Database::getConnection();

$cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'live_photo_filename'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("
        ALTER TABLE users 
        ADD COLUMN live_photo_filename VARCHAR(255) NULL AFTER id_photo_filename,
        ADD COLUMN verification_notes TEXT NULL AFTER id_verified_at
    ");
    echo "Columns live_photo_filename and verification_notes added successfully.\n";
} else {
    echo "Columns already present.\n";
}
