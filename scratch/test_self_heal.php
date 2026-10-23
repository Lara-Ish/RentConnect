<?php
require_once __DIR__ . '/../config/db.php';

// Simulate a database where live_photo_filename was dropped
$pdo = Database::getConnection();
$pdo->exec("ALTER TABLE `users` DROP COLUMN IF EXISTS `live_photo_filename`");
$pdo->exec("ALTER TABLE `users` DROP COLUMN IF EXISTS `verification_notes`");

// Verify they are gone
$cols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
echo "Columns without verification columns: " . (in_array('live_photo_filename', $cols) ? "Present" : "Dropped") . "\n";

// Reset the static singleton and check self-healing
$ref = new ReflectionClass('Database');
$prop = $ref->getProperty('instance');
$prop->setAccessible(true);
$prop->setValue(null);

$propChecked = $ref->getProperty('schemaChecked');
$propChecked->setAccessible(true);
$propChecked->setValue(false);

$pdo2 = Database::getConnection();
$cols2 = $pdo2->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
echo "Columns after self-healing reconnect: " . (in_array('live_photo_filename', $cols2) ? "RESTORED SUCCESS" : "FAILED") . "\n";
