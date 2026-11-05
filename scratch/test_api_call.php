<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Log in as admin
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'landlord';

// Let's test what tenant_directory_api does when we create a tenant
$payload = [
    'action' => 'create',
    'unit_id' => 1,
    'name' => 'Test Tenant',
    'move_in_date' => '2026-11-10',
    'birthday' => '1995-05-15',
    'email' => 'testtenant' . time() . '@example.com',
    'phone' => '09123456789',
    'emergency_contact_name' => 'Emergency Person',
    'emergency_contact_relationship' => 'Parent',
    'emergency_contact_phone' => '09987654321',
    'total_occupants' => 1,
    'adults_count' => 1,
    'kids_count' => 0
];

echo "Simulating POST to api/tenant_directory_api.php with payload:\n";
print_r($payload);

// We can test the PHP execution directly
$_SERVER['REQUEST_METHOD'] = 'POST';
file_put_contents('php://input', json_encode($payload)); // note php://input cannot be written this way directly in CLI, so let's mock or include
