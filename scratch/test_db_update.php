<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$pdo = Database::getConnection();

// Reset test user
$stmt = $pdo->query("SELECT * FROM users WHERE role = 'tenant' LIMIT 1");
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);
$tenantId = (int)$tenant['id'];

$pdo->prepare("UPDATE users SET is_verified = 0, verification_status = 'unverified' WHERE id = :id")->execute([':id' => $tenantId]);

// Verify initial state
$check = $pdo->prepare("SELECT is_verified, verification_status FROM users WHERE id = :id");
$check->execute([':id' => $tenantId]);
$state0 = $check->fetch(PDO::FETCH_ASSOC);
echo "Initial state: is_verified={$state0['is_verified']}, status={$state0['verification_status']}\n";

// Execute SQL UPDATE statement matching verify_face.php:
$confidence = 88.50;
$notes = sprintf('Face++ Verified: confidence %.2f%% (>= 75.00%%)', $confidence);
$updateStmt = $pdo->prepare("
    UPDATE users 
    SET is_verified = 1,
        verification_status = 'verified',
        live_photo_filename = 'face_test.jpg',
        id_verified_at = NOW(),
        verification_notes = :notes
    WHERE id = :id
");
$updateStmt->execute([
    ':notes' => $notes,
    ':id' => $tenantId
]);

$check->execute([':id' => $tenantId]);
$state1 = $check->fetch(PDO::FETCH_ASSOC);
echo "Updated state: is_verified={$state1['is_verified']}, status={$state1['verification_status']}\n";

if ((int)$state1['is_verified'] === 1 && $state1['verification_status'] === 'verified') {
    echo "SUCCESS: is_verified = 1 successfully confirmed in DB!\n";
} else {
    echo "FAILED\n";
}
