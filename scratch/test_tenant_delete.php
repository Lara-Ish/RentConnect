<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

$tenants = $pdo->query("SELECT id, name, unit_id, is_active FROM users WHERE role = 'tenant'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($tenants as $t) {
    $tid = $t['id'];
    $pCount = $pdo->query("SELECT COUNT(*) FROM payments WHERE tenant_id = $tid")->fetchColumn();
    $mCount = $pdo->query("SELECT COUNT(*) FROM maintenance_tickets WHERE tenant_id = $tid")->fetchColumn();
    $nCount = $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $tid")->fetchColumn();
    $lCount = $pdo->query("SELECT COUNT(*) FROM leases WHERE tenant_id = $tid")->fetchColumn();
    $aCount = $pdo->query("SELECT COUNT(*) FROM announcement_recipients WHERE tenant_id = $tid")->fetchColumn();
    echo "Tenant #$tid ({$t['name']}) [Active: {$t['is_active']}, Unit: {$t['unit_id']}]:\n";
    echo "  Payments: $pCount, Maintenance: $mCount, Notifications: $nCount, Leases: $lCount, Announcements: $aCount\n";
}
