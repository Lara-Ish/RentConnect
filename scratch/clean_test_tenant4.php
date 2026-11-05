<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$pdo->exec("DELETE FROM payments WHERE tenant_id = 4");
$pdo->exec("DELETE FROM notifications WHERE user_id = 4");
$pdo->exec("DELETE FROM email_logs WHERE recipient_user_id = 4");
$pdo->exec("DELETE FROM users WHERE id = 4");
$pdo->exec("UPDATE apartment_settings SET active_tenant_id = NULL WHERE active_tenant_id = 4");
echo "Cleaned up tenant 4\n";
