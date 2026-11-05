<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

echo "=== FOREIGN KEY CONSTRAINTS REFERENCING USERS ===\n";
$fks = $pdo->query("
    SELECT 
        TABLE_NAME, 
        COLUMN_NAME, 
        CONSTRAINT_NAME, 
        REFERENCED_TABLE_NAME, 
        REFERENCED_COLUMN_NAME
    FROM 
        INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE 
        TABLE_SCHEMA = DATABASE() 
        AND REFERENCED_TABLE_NAME = 'users'
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($fks as $fk) {
    echo "Table: {$fk['TABLE_NAME']} -> Column: {$fk['COLUMN_NAME']} (Constraint: {$fk['CONSTRAINT_NAME']})\n";
}

echo "\n=== REFERENTIAL CONSTRAINTS (ON DELETE ACTIONS) ===\n";
$rcs = $pdo->query("
    SELECT 
        TABLE_NAME, 
        CONSTRAINT_NAME, 
        DELETE_RULE, 
        UPDATE_RULE 
    FROM 
        INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS 
    WHERE 
        CONSTRAINT_SCHEMA = DATABASE()
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rcs as $rc) {
    echo "Table: {$rc['TABLE_NAME']} (Constraint: {$rc['CONSTRAINT_NAME']}) - ON DELETE: {$rc['DELETE_RULE']}\n";
}
