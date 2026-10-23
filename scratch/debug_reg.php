<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$_SESSION['user'] = ['id' => 1, 'role' => 'landlord', 'name' => 'Lead Dimaculangan'];
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'landlord';
$_SERVER['REQUEST_METHOD'] = 'POST';

$input = [
    'action' => 'create',
    'unit_id' => 1,
    'name' => 'Debug Tenant',
    'birthday' => '1996-05-10',
    'email' => 'debug_' . time() . '@rentconnect.ph',
    'phone' => '+63 917 555 0101',
    'move_in_date' => date('Y-m-d'),
    'adults_count' => 1,
    'kids_count' => 0,
    'total_occupants' => 1
];

$_POST = $input;

require __DIR__ . '/../api/tenant_directory_api.php';
