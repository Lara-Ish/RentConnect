<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Log in as admin
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'landlord';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'action' => 'create',
    'unit_id' => 1,
    'name' => 'Test Tenant',
    'move_in_date' => '2026-11-10',
    'birthday' => '1995-05-15',
    'email' => 'testtenant_' . time() . '@example.com',
    'phone' => '09123456789',
    'emergency_contact_name' => 'Emergency Person',
    'emergency_contact_relationship' => 'Parent',
    'emergency_contact_phone' => '09987654321',
    'total_occupants' => 1,
    'adults_count' => 1,
    'kids_count' => 0
];

// Let's capture output of tenant_directory_api.php
ob_start();
try {
    include __DIR__ . '/../api/tenant_directory_api.php';
} catch (Throwable $t) {
    echo "CAUGHT THROWABLE: " . $t->getMessage() . " at " . $t->getFile() . ":" . $t->getLine() . "\n";
}
$out = ob_get_clean();
echo "HTTP RESPONSE CODE: " . http_response_code() . "\n";
echo "OUTPUT:\n" . $out . "\n";
