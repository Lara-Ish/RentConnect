<?php
/**
 * RentConnect - Complete Database Cleanup and Rent Configuration
 * 
 * Safely removes all existing tenant data, transaction records, uploaded tenant files,
 * and backup copies while preserving the existing Owner/Admin account and setting
 * standard monthly rent to ₱3,000 across all units and settings.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getConnection();
    echo "=== RENTCONNECT COMPLETE DATABASE CLEANUP ===\n";
    echo "1. Verifying existing Owner/Admin account...\n";

    $adminStmt = $pdo->prepare("SELECT id, username, email, role, password_hash FROM users WHERE id = 1 AND role = 'landlord' LIMIT 1");
    $adminStmt->execute();
    $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        throw new RuntimeException("CRITICAL ERROR: Owner/Admin account (id=1, role='landlord') was not found! Aborting cleanup.");
    }
    echo "   Owner/Admin verified: '{$admin['username']}' ({$admin['email']}) [ID: {$admin['id']}]\n";

    echo "\n2. Beginning database transaction for transactional cleanup...\n";
    $pdo->beginTransaction();

    // 1. Reset apartment settings references
    echo "   - Resetting apartment_settings active_tenant_id to NULL and updating monthly rent to 3000.00\n";
    $pdo->exec("UPDATE apartment_settings SET active_tenant_id = NULL, monthly_rent = 3000.00, security_deposit = 3000.00");

    // 2. Clear child tables with foreign key relationships
    echo "   - Deleting announcement_recipients...\n";
    $delRecipients = $pdo->exec("DELETE FROM announcement_recipients");
    echo "     Removed {$delRecipients} announcement recipient records.\n";

    echo "   - Deleting announcements...\n";
    $delAnnouncements = $pdo->exec("DELETE FROM announcements");
    echo "     Removed {$delAnnouncements} announcement records.\n";

    echo "   - Deleting payments...\n";
    $delPayments = $pdo->exec("DELETE FROM payments");
    echo "     Removed {$delPayments} payment records.\n";

    echo "   - Deleting maintenance_tickets...\n";
    $delTickets = $pdo->exec("DELETE FROM maintenance_tickets");
    echo "     Removed {$delTickets} maintenance tickets.\n";

    echo "   - Deleting leases...\n";
    $delLeases = $pdo->exec("DELETE FROM leases");
    echo "     Removed {$delLeases} lease records.\n";

    echo "   - Deleting password_resets for non-admins...\n";
    $delResets = $pdo->exec("DELETE FROM password_resets");
    echo "     Removed {$delResets} password reset records.\n";

    echo "   - Deleting notifications...\n";
    $delNotifs = $pdo->exec("DELETE FROM notifications");
    echo "     Removed {$delNotifs} notification records.\n";

    echo "   - Deleting email_logs...\n";
    $delEmails = $pdo->exec("DELETE FROM email_logs");
    echo "     Removed {$delEmails} email log records.\n";

    // 3. Delete tenant accounts from users table
    echo "   - Deleting all tenant accounts from users table...\n";
    $deletedTenants = $pdo->exec("DELETE FROM users WHERE role = 'tenant' OR id != 1");
    echo "     Deleted {$deletedTenants} tenant user account(s).\n";

    // 4. Update monthly rent for all units to ₱3,000.00
    echo "   - Setting standard monthly rent to ₱3,000.00 for all units...\n";
    $pdo->exec("UPDATE units SET monthly_rent = 3000.00");

    // 5. CRITICAL SAFETY CHECK: Ensure Admin account still exists and untouched
    $adminCheck = $pdo->query("SELECT id, username, role FROM users WHERE id = 1 AND role = 'landlord'")->fetch(PDO::FETCH_ASSOC);
    if (!$adminCheck) {
        throw new RuntimeException("CRITICAL SAFETY FAILURE: Owner/Admin account was affected! Rolling back transaction.");
    }

    $remainingUsersCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($remainingUsersCount !== 1) {
        throw new RuntimeException("CRITICAL: Expected exactly 1 user (Admin), found {$remainingUsersCount}. Rolling back.");
    }
    echo "   - Remaining users in database: {$remainingUsersCount} (Owner/Admin only: '{$adminCheck['username']}')\n";

    $pdo->commit();
    echo "Transaction committed successfully.\n";

    // 6. Reset AUTO_INCREMENT counters
    echo "\n3. Resetting AUTO_INCREMENT counters...\n";
    $pdo->exec("ALTER TABLE payments AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE maintenance_tickets AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE leases AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE password_resets AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE notifications AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE announcement_recipients AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE announcements AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE email_logs AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE users AUTO_INCREMENT = 2");
    echo "   Table AUTO_INCREMENT counters successfully reset.\n";

    // 7. Remove existing database backup files
    echo "\n4. Removing existing database backup files and backup copies...\n";
    $backupFiles = [
        __DIR__ . '/backup_before_cleanup_20261030_124329.sql',
        __DIR__ . '/backup_before_cleanup_20261031_113243.sql',
    ];
    $removedBackups = 0;
    foreach ($backupFiles as $bf) {
        if (file_exists($bf)) {
            unlink($bf);
            $removedBackups++;
            echo "   - Removed: " . basename($bf) . "\n";
        }
    }
    echo "   Removed {$removedBackups} backup copy file(s).\n";

    // 8. Clean uploaded tenant files from storage directories
    echo "\n5. Purging uploaded tenant files...\n";
    $uploadDirectories = [
        __DIR__ . '/../uploads/receipts',
        __DIR__ . '/../uploads/id_verifications',
        __DIR__ . '/../uploads/temp_face_login',
        __DIR__ . '/../uploads/maintenance'
    ];

    $purgedFilesCount = 0;
    foreach ($uploadDirectories as $dir) {
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== '.gitkeep') {
                    @unlink($file);
                    $purgedFilesCount++;
                }
            }
        }
    }
    echo "   Removed {$purgedFilesCount} file(s) from uploads directory.\n";

    // 9. Verify Unit availability and rent values
    echo "\n6. Verifying apartment units...\n";
    $units = $pdo->query("
        SELECT u.id, u.unit_number, u.monthly_rent,
               (SELECT COUNT(*) FROM users usr WHERE usr.unit_id = u.id AND usr.is_active = 1) AS occupant_count
        FROM units u
        ORDER BY u.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($units as $u) {
        echo "   Unit: {$u['unit_number']} (ID: {$u['id']}) | Rent: ₱" . number_format((float)$u['monthly_rent'], 2) . " | Active Occupants: {$u['occupant_count']} (Available)\n";
    }

    echo "\n=== COMPLETE DATABASE CLEANUP FINISHED SUCCESSFULLY ===\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
        echo "Transaction rolled back due to error.\n";
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
