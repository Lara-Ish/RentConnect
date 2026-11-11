<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getConnection();
    echo "Connected to database.\n";

    // 1. Alter status column enum
    $pdo->exec("
        ALTER TABLE maintenance_tickets 
        MODIFY COLUMN status ENUM('Pending', 'On-Going', 'Done', 'open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'Pending'
    ");
    echo "Altered status column enum successfully.\n";

    // 2. Map existing data
    $stmt1 = $pdo->exec("UPDATE maintenance_tickets SET status = 'Pending' WHERE status = 'open'");
    echo "Migrated 'open' -> 'Pending' ($stmt1 rows updated).\n";

    $stmt2 = $pdo->exec("UPDATE maintenance_tickets SET status = 'On-Going' WHERE status = 'in_progress'");
    echo "Migrated 'in_progress' -> 'On-Going' ($stmt2 rows updated).\n";

    $stmt3 = $pdo->exec("UPDATE maintenance_tickets SET status = 'Done' WHERE status IN ('resolved', 'closed')");
    echo "Migrated 'resolved'/'closed' -> 'Done' ($stmt3 rows updated).\n";

    // 3. Inspect final rows
    $rows = $pdo->query("SELECT id, title, status, actual_cost, created_at, resolved_at FROM maintenance_tickets")->fetchAll(PDO::FETCH_ASSOC);
    echo "Current tickets in table:\n";
    print_r($rows);

    echo "Migration completed successfully.\n";
} catch (Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
