<?php
/**
 * Database Backup Script
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$pdo = Database::getConnection();
$backupPath = __DIR__ . '/backup_before_cleanup_' . date('Ymd_His') . '.sql';

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$sqlDump = "-- RentConnect Complete Database Backup\n";
$sqlDump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
$sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    // Structure
    $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
    $sqlDump .= "DROP TABLE IF EXISTS `$table`;\n";
    $sqlDump .= $createTable['Create Table'] . ";\n\n";

    // Data
    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        $cols = array_keys($rows[0]);
        $colNames = implode('`, `', $cols);
        $sqlDump .= "INSERT INTO `$table` (`$colNames`) VALUES\n";
        $valStrings = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $vals[] = "NULL";
                } else {
                    $vals[] = $pdo->quote((string)$val);
                }
            }
            $valStrings[] = "(" . implode(', ', $vals) . ")";
        }
        $sqlDump .= implode(",\n", $valStrings) . ";\n\n";
    }
}

$sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";

file_put_contents($backupPath, $sqlDump);
echo "Database successfully backed up to: " . basename($backupPath) . " (" . strlen($sqlDump) . " bytes)\n";
