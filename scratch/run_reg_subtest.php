<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$unitId = (int)($argv[1] ?? 1);
$suffix = $argv[2] ?? (string)time();

$_SESSION['user'] = ['id' => 1, 'role' => 'landlord', 'name' => 'Lead Dimaculangan'];
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'landlord';
$_SERVER['REQUEST_METHOD'] = 'POST';

$input = [
    'action' => 'create',
    'unit_id' => $unitId,
    'name' => 'Test Tenant Unit ' . $unitId,
    'birthday' => '1996-05-10',
    'email' => 'test_unit_' . $unitId . '_' . $suffix . '@rentconnect.ph',
    'phone' => '+63 917 555 010' . $unitId,
    'move_in_date' => date('Y-m-d'),
    'adults_count' => 1,
    'kids_count' => 0,
    'total_occupants' => 1
];

$_POST = $input;

require __DIR__ . '/../api/tenant_directory_api.php';
