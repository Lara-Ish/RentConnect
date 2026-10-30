<?php
require_once __DIR__ . '/../config/auth.php';
$pdo = Database::getConnection();
$ver = $pdo->query("SELECT VERSION()")->fetchColumn();
echo "DATABASE VERSION: $ver\n";
