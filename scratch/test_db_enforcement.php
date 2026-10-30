<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

echo "Testing dual database-level protection:\n";

// 1. Attempt insert into Unit 1 (occupied by Lara Janelle Lucero 107)
try {
    $ins = $pdo->prepare("
        INSERT INTO users (unit_id, username, name, email, password_hash, role, is_active, created_at)
        VALUES (1, 'RC_TEST_DUP', 'Dup Tenant', 'dup1@test.com', 'dummyhash', 'tenant', 1, NOW())
    ");
    $ins->execute();
    echo " [FAIL] Expected insert into occupied Unit 1 to fail, but it succeeded!\n";
} catch (PDOException $e) {
    echo " [PASS] Insert into occupied Unit 1 blocked with message: " . $e->getMessage() . "\n";
}

// 2. Attempt insert into Unit 2 (currently vacant)
try {
    $ins2 = $pdo->prepare("
        INSERT INTO users (unit_id, username, name, email, password_hash, role, is_active, created_at)
        VALUES (2, 'RC_TEST_U2', 'Unit 2 Tenant', 'u2@test.com', 'dummyhash', 'tenant', 1, NOW())
    ");
    $ins2->execute();
    $u2Id = (int)$pdo->lastInsertId();
    echo " [PASS] Insert into vacant Unit 2 succeeded! ID: $u2Id\n";

    // 3. Attempt insert 2nd active tenant into Unit 2 (now occupied)
    try {
        $ins3 = $pdo->prepare("
            INSERT INTO users (unit_id, username, name, email, password_hash, role, is_active, created_at)
            VALUES (2, 'RC_TEST_U2_DUP', 'Unit 2 Dup', 'u2_dup@test.com', 'dummyhash', 'tenant', 1, NOW())
        ");
        $ins3->execute();
        echo " [FAIL] Expected 2nd insert into Unit 2 to fail, but it succeeded!\n";
    } catch (PDOException $e) {
        echo " [PASS] 2nd insert into Unit 2 blocked with message: " . $e->getMessage() . "\n";
    }

    // 4. Deactivate tenant in Unit 2 (Move out / Deactivation)
    $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = :id")->execute([':id' => $u2Id]);
    echo " [PASS] Deactivated tenant in Unit 2 (is_active = 0).\n";

    // 5. Insert new tenant into Unit 2 (now that previous tenant is deactivated)
    try {
        $ins4 = $pdo->prepare("
            INSERT INTO users (unit_id, username, name, email, password_hash, role, is_active, created_at)
            VALUES (2, 'RC_TEST_U2_NEW', 'Unit 2 New Tenant', 'u2_new@test.com', 'dummyhash', 'tenant', 1, NOW())
        ");
        $ins4->execute();
        $u2NewId = (int)$pdo->lastInsertId();
        echo " [PASS] Insert new tenant into previously deactivated Unit 2 succeeded! ID: $u2NewId\n";

        // Clean up test tenants
        $pdo->prepare("DELETE FROM users WHERE id IN (:id1, :id2)")->execute([':id1' => $u2Id, ':id2' => $u2NewId]);
        echo " [PASS] Cleaned up test tenants.\n";
    } catch (PDOException $e) {
        echo " [FAIL] Insert into Unit 2 failed after deactivation: " . $e->getMessage() . "\n";
        $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $u2Id]);
    }

} catch (PDOException $e) {
    echo " [FAIL] Insert into vacant Unit 2 failed: " . $e->getMessage() . "\n";
}
