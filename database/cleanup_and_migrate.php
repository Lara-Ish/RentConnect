<?php
/**
 * RentConnect - Database Cleanup & Payments Schema Migration
 * 
 * Cleans up all existing tenant accounts, personal records, tickets, payments,
 * notifications, and uploaded proofs while preserving Admin and system configurations.
 * Alters payments table to support placeholder rent ('pending_setup', nullable amounts/dates).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getConnection();
    echo "Connected to database.\n";

    $pdo->beginTransaction();

    // 1. Reset apartment_settings active_tenant_id
    echo "Resetting apartment_settings.active_tenant_id...\n";
    $pdo->exec("UPDATE apartment_settings SET active_tenant_id = NULL");

    // 2. Delete child records referencing users or units
    echo "Cleaning payments table...\n";
    $pdo->exec("DELETE FROM payments");

    echo "Cleaning maintenance_tickets table...\n";
    $pdo->exec("DELETE FROM maintenance_tickets");

    echo "Cleaning leases table...\n";
    $pdo->exec("DELETE FROM leases");

    echo "Cleaning password_resets table...\n";
    $pdo->exec("DELETE FROM password_resets");

    echo "Cleaning notifications table...\n";
    $pdo->exec("DELETE FROM notifications");

    echo "Cleaning email_logs table...\n";
    $pdo->exec("DELETE FROM email_logs");

    // 3. Delete tenant accounts (preserve Admin: id=1, role='landlord')
    echo "Removing tenant accounts from users table...\n";
    $delTenants = $pdo->exec("DELETE FROM users WHERE role = 'tenant'");
    echo "Deleted {$delTenants} tenant account(s).\n";

    // Verify Admin is preserved
    $admin = $pdo->query("SELECT id, username, role FROM users WHERE id = 1 AND role = 'landlord'")->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        throw new RuntimeException("CRITICAL: Admin account was not found! Aborting rollback.");
    }
    echo "Admin account verified: {$admin['username']} (ID: {$admin['id']}).\n";

    $pdo->commit();

    // Reset AUTO_INCREMENT outside transaction
    echo "Resetting AUTO_INCREMENT counters...\n";
    $pdo->exec("ALTER TABLE payments AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE maintenance_tickets AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE leases AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE password_resets AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE notifications AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE email_logs AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE users AUTO_INCREMENT = 2");

    // 4. Alter payments table schema
    echo "Altering payments table schema to support placeholder rent...\n";
    $pdo->exec("ALTER TABLE payments MODIFY COLUMN status ENUM('pending_setup','pending','approved','rejected','overdue') NOT NULL DEFAULT 'pending_setup'");
    $pdo->exec("ALTER TABLE payments MODIFY COLUMN billing_cycle DATE NULL DEFAULT NULL COMMENT 'First day of billing month e.g. 2026-10-01'");
    $pdo->exec("ALTER TABLE payments MODIFY COLUMN due_date DATE NULL DEFAULT NULL");
    $pdo->exec("ALTER TABLE payments MODIFY COLUMN amount_due DECIMAL(10,2) NULL DEFAULT NULL");

    // 5. Clean up upload files (preserve directory structure)
    echo "Cleaning up uploaded tenant sample files...\n";
    $dirsToClean = [
        __DIR__ . '/../uploads/receipts',
        __DIR__ . '/../uploads/id_verifications',
        __DIR__ . '/../uploads/temp_face_login',
        __DIR__ . '/../uploads/maintenance'
    ];

    $deletedFilesCount = 0;
    foreach ($dirsToClean as $dir) {
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== '.gitkeep') {
                    @unlink($file);
                    $deletedFilesCount++;
                }
            }
        }
    }
    echo "Removed {$deletedFilesCount} uploaded proof/receipt/temp file(s).\n";

    echo "\n=== CLEANUP & MIGRATION COMPLETED SUCCESSFULLY ===\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
