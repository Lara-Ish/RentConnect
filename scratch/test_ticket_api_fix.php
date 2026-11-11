<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = Database::getConnection();

echo "=== TESTING TICKET STATUS UPDATE API QUERY FIX ===\n";

// 1. Create a test ticket
$stmt = $pdo->prepare("
    INSERT INTO maintenance_tickets (tenant_id, unit_id, title, description, category, priority, status, created_at)
    VALUES (2, 1, 'API Test Leak', 'Sink leaking', 'Plumbing', 'high', 'Pending', NOW())
");
$stmt->execute();
$testTicketId = (int)$pdo->lastInsertId();
echo "Created test ticket #{$testTicketId} with status 'Pending'.\n";

// 2. Simulate API status update from Pending to On-Going
$statusToSave = 'On-Going';
$costToSave = 0.0;
$replyToSave = 'Contractor has been notified.';
$isResolved = in_array(strtolower($statusToSave), ['done', 'resolved', 'closed'], true);
$resolvedAt = $isResolved ? date('Y-m-d H:i:s') : null;

$updateStmt = $pdo->prepare("
    UPDATE maintenance_tickets 
    SET status = :status,
        actual_cost = :actual_cost,
        resolution_notes = :resolution_notes,
        resolved_at = :resolved_at
    WHERE id = :id
");
$updateStmt->execute([
    ':status' => $statusToSave,
    ':actual_cost' => $costToSave,
    ':resolution_notes' => $replyToSave,
    ':resolved_at' => $resolvedAt,
    ':id' => $testTicketId
]);
echo "Updated status to 'On-Going' without errors.\n";

// Verify database
$checkStmt = $pdo->prepare("SELECT status, resolution_notes, resolved_at FROM maintenance_tickets WHERE id = :id");
$checkStmt->execute([':id' => $testTicketId]);
$row = $checkStmt->fetch(PDO::FETCH_ASSOC);
assert($row['status'] === 'On-Going', "Expected 'On-Going'");
assert($row['resolution_notes'] === 'Contractor has been notified.');
assert($row['resolved_at'] === null, "Expected null resolved_at");
echo "Verified DB record for 'On-Going'.\n";

// 3. Simulate API status update from On-Going to Done
$statusToSave = 'Done';
$replyToSave = 'Repairs completed by plumber.';
$isResolved = in_array(strtolower($statusToSave), ['done', 'resolved', 'closed'], true);
$resolvedAt = $isResolved ? date('Y-m-d H:i:s') : null;

$updateStmt->execute([
    ':status' => $statusToSave,
    ':actual_cost' => $costToSave,
    ':resolution_notes' => $replyToSave,
    ':resolved_at' => $resolvedAt,
    ':id' => $testTicketId
]);
echo "Updated status to 'Done' without errors.\n";

$checkStmt->execute([':id' => $testTicketId]);
$row = $checkStmt->fetch(PDO::FETCH_ASSOC);
assert($row['status'] === 'Done', "Expected 'Done'");
assert($row['resolution_notes'] === 'Repairs completed by plumber.');
assert(!empty($row['resolved_at']), "Expected non-null resolved_at for Done");
echo "Verified DB record for 'Done'.\n";

// 4. Simulate API status update from Done back to Pending
$statusToSave = 'Pending';
$replyToSave = 'Reopened for inspection.';
$isResolved = in_array(strtolower($statusToSave), ['done', 'resolved', 'closed'], true);
$resolvedAt = $isResolved ? date('Y-m-d H:i:s') : null;

$updateStmt->execute([
    ':status' => $statusToSave,
    ':actual_cost' => $costToSave,
    ':resolution_notes' => $replyToSave,
    ':resolved_at' => $resolvedAt,
    ':id' => $testTicketId
]);
echo "Reopened status to 'Pending' without errors.\n";

$checkStmt->execute([':id' => $testTicketId]);
$row = $checkStmt->fetch(PDO::FETCH_ASSOC);
assert($row['status'] === 'Pending', "Expected 'Pending'");
assert($row['resolved_at'] === null, "Expected null resolved_at when reopened");
echo "Verified DB record for 'Pending'.\n";

// 5. Clean up
$pdo->prepare("DELETE FROM maintenance_tickets WHERE id = :id")->execute([':id' => $testTicketId]);
echo "Cleaned up test ticket.\n";

echo "=== ALL CHECKS PASSED SUCCESSFULLY! ===\n";
