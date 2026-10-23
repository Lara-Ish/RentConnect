<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$pdo = Database::getConnection();

// Check if verification_status column already exists
$cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'verification_status'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("
        ALTER TABLE users 
        ADD COLUMN verification_status ENUM('unverified', 'pending', 'verified', 'rejected') NOT NULL DEFAULT 'unverified' AFTER role,
        ADD COLUMN id_type VARCHAR(50) NULL AFTER verification_status,
        ADD COLUMN id_photo_filename VARCHAR(255) NULL AFTER id_type,
        ADD COLUMN id_submitted_at DATETIME NULL AFTER id_photo_filename,
        ADD COLUMN id_verified_at DATETIME NULL AFTER id_submitted_at
    ");
    echo "Columns added successfully.\n";
} else {
    echo "Columns already exist.\n";
}

// Ensure landlord is verified and default tenants are unverified
$pdo->exec("UPDATE users SET verification_status = 'verified' WHERE role = 'landlord'");
$pdo->exec("UPDATE users SET verification_status = 'unverified' WHERE role = 'tenant' AND verification_status IS NULL");

echo "Update complete.\n";
