<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$pdo->exec("UPDATE users SET birthday = '1995-04-12' WHERE username = 'tenant1' AND (birthday IS NULL OR birthday = '' OR birthday = '0000-00-00')");
$pdo->exec("UPDATE users SET birthday = '1998-08-23' WHERE username = 'tenant2' AND (birthday IS NULL OR birthday = '' OR birthday = '0000-00-00')");
echo "Updated birthdays in users.\n";
