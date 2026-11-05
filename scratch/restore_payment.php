<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$pdo->query("UPDATE payments SET status = 'pending', verified_by_landlord = 0, verified_at = NULL, verification_notes = 'Rent configured by Landlord. Awaiting tenant payment.' WHERE id = 29");
echo "Restored payment 29\n";
