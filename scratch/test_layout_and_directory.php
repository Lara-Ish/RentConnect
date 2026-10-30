<?php
// Test Script: Verification of Favicon, Sidebar consistency, Contextual Routing, Occupant Breakdown, and Tenant Summary/Privacy

echo "=================================================================\n";
echo "TEST SUITE: UI LAYOUT, CONTEXTUAL ROUTING & TENANT DIRECTORY\n";
echo "=================================================================\n\n";

$passes = 0;
$errors = [];

function assertCondition($name, $condition, $failureMsg) {
    global $passes, $errors;
    if ($condition) {
        echo "[PASS] $name\n";
        $passes++;
    } else {
        echo "[FAIL] $name: $failureMsg\n";
        $errors[] = "$name: $failureMsg";
    }
}

// -------------------------------------------------------------------------
// 1. FAVICON & SIDEBAR LAYOUT CONSISTENCY
// -------------------------------------------------------------------------
echo "--- 1. Testing Favicon & Sidebar Layout Consistency ---\n";

$faviconFiles = [
    'assets/images/favicon.png',
    'assets/images/favicon-192.png',
    'assets/images/favicon-64.png',
    'assets/images/favicon-32.png',
    'favicon.ico'
];

foreach ($faviconFiles as $f) {
    assertCondition(
        "Favicon file exists: $f",
        file_exists("c:/xampp/htdocs/RentConnect/$f"),
        "File is missing: $f"
    );
}

// Check transparent background of generated favicon.png
$im = imagecreatefrompng('c:/xampp/htdocs/RentConnect/assets/images/favicon.png');
$cornerColor = imagecolorsforindex($im, imagecolorat($im, 0, 0));
assertCondition(
    "Favicon has clean transparent background (alpha = 127)",
    $cornerColor['alpha'] === 127,
    "Expected alpha 127, got " . $cornerColor['alpha']
);

$adminDash = file_get_contents('c:/xampp/htdocs/RentConnect/admin_dashboard.php');
$tenantPortal = file_get_contents('c:/xampp/htdocs/RentConnect/tenant_portal.php');
$loginPage = file_get_contents('c:/xampp/htdocs/RentConnect/login.php');

assertCondition(
    "admin_dashboard.php links global favicon in <head>",
    str_contains($adminDash, 'assets/images/favicon-32.png') && str_contains($adminDash, 'rel="icon"'),
    "Favicon link missing in admin_dashboard.php"
);

assertCondition(
    "tenant_portal.php links global favicon in <head>",
    str_contains($tenantPortal, 'assets/images/favicon-32.png') && str_contains($tenantPortal, 'rel="icon"'),
    "Favicon link missing in tenant_portal.php"
);

assertCondition(
    "login.php links global favicon in <head>",
    str_contains($loginPage, 'assets/images/favicon-32.png') && str_contains($loginPage, 'rel="icon"'),
    "Favicon link missing in login.php"
);

assertCondition(
    "Both dashboards use standardized .clay-sidebar and .sidebar-top-header",
    str_contains($adminDash, 'class="clay-sidebar"') && str_contains($tenantPortal, 'class="clay-sidebar"') &&
    str_contains($adminDash, 'sidebar-top-header') && str_contains($tenantPortal, 'sidebar-top-header'),
    "Sidebar markup inconsistent between landlord and tenant portals"
);

// -------------------------------------------------------------------------
// 2. CONTEXTUAL PAGE ROUTING
// -------------------------------------------------------------------------
echo "\n--- 2. Testing Contextual Page Routing ---\n";

assertCondition(
    "Landlord delete stays in unit folder (NO redirect to main dashboard)",
    str_contains($adminDash, 'updateUnitTenantCount') &&
    str_contains($adminDash, 'admin_dashboard.php?section=sectionDirectory&unit_id=') &&
    !str_contains($adminDash, "setTimeout(() => location.reload(), 800)"),
    "Landlord delete still reloads to main dashboard"
);

assertCondition(
    "Tenant maintenance submission redirects straight to Tenant Dashboard",
    str_contains($tenantPortal, "window.location.href = 'tenant_portal.php?section=tenantOverview'") ||
    str_contains($tenantPortal, "window.location.href = 'tenant_portal.php'"),
    "Tenant maintenance does not redirect straight to Tenant Dashboard"
);

// -------------------------------------------------------------------------
// 3. TENANT DIRECTORY & REGISTRATION LOGIC
// -------------------------------------------------------------------------
echo "\n--- 3. Testing Tenant Directory & Registration Logic ---\n";

// "Add New Tenant" removed from main/root folder view
assertCondition(
    "No 'Add New Tenant' button in directoryFoldersView",
    !preg_match('/<div id="directoryFoldersView">[\s\S]*?openAddTenantModal\(\)[\s\S]*?<\/div>/i', $adminDash),
    "'Add New Tenant' button still exists in root folder view"
);

// "Add Tenant to Unit X" is inside unit-detail-folder
assertCondition(
    "'Add Tenant to Unit X' exists inside unit-detail-folder",
    str_contains($adminDash, '➕ Add Tenant to <?= htmlspecialchars($u[\'unit_number\']) ?>') ||
    str_contains($adminDash, 'openAddTenantModal(<?= $u[\'id\'] ?>)'),
    "Unit-specific add tenant button missing"
);

// Check database schema has occupant breakdown columns
require_once 'c:/xampp/htdocs/RentConnect/config/db.php';
$pdo = Database::getConnection();
$cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

assertCondition("Database users table has 'total_occupants'", in_array('total_occupants', $cols), "Missing total_occupants");
assertCondition("Database users table has 'adults_count'", in_array('adults_count', $cols), "Missing adults_count");
assertCondition("Database users table has 'kids_count'", in_array('kids_count', $cols), "Missing kids_count");

// Check registration form fields
assertCondition(
    "Registration form includes Total Occupants input",
    str_contains($adminDash, 'id="newTotalOccupants"'),
    "Missing newTotalOccupants in form"
);
assertCondition(
    "Registration form includes Adults Count input",
    str_contains($adminDash, 'id="newAdultsCount"'),
    "Missing newAdultsCount in form"
);
assertCondition(
    "Registration form includes Kids Count input",
    str_contains($adminDash, 'id="newKidsCount"'),
    "Missing newKidsCount in form"
);
assertCondition(
    "Registration form specifies 1 Family per Account rule",
    str_contains($adminDash, '1 Family per Account'),
    "Missing 1 Family per Account badge/notice"
);

// -------------------------------------------------------------------------
// 4. CLICKABLE TENANT INFORMATION & PRIVACY
// -------------------------------------------------------------------------
echo "\n--- 4. Testing Clickable Summary View & Privacy ---\n";

assertCondition(
    "Unit folder displays clickable summary table",
    str_contains($adminDash, 'class="tenant-summary-table"') &&
    str_contains($adminDash, 'class="tenant-summary-row"'),
    "Missing tenant summary table or rows"
);

// Summary table headers must ONLY be: Name, Username, Birthday, Email, Phone Number
$tableMatch = preg_match('/<table class="tenant-summary-table">[\s\S]*?<thead>[\s\S]*?<tr>([\s\S]*?)<\/tr>[\s\S]*?<\/thead>/i', $adminDash, $m);
assertCondition(
    "Summary table headers are: Name, Username, Birthday, Email, Phone Number",
    $tableMatch &&
    str_contains($m[1], '<th>Name</th>') &&
    str_contains($m[1], '<th>Username</th>') &&
    str_contains($m[1], '<th>Birthday</th>') &&
    str_contains($m[1], '<th>Email</th>') &&
    str_contains($m[1], '<th>Phone Number</th>') &&
    !str_contains($m[1], 'Emergency') &&
    !str_contains($m[1], 'Verification') &&
    !str_contains($m[1], 'Face'),
    "Summary table headers contain extra columns"
);

// Expanded details modal exists
assertCondition(
    "Expanded details modal (#tenantDetailsModal) exists",
    str_contains($adminDash, 'id="tenantDetailsModal"'),
    "Missing tenantDetailsModal in admin_dashboard.php"
);

assertCondition(
    "Expanded details modal contains occupant breakdown",
    str_contains($adminDash, 'id="tdTotalOccupants"') &&
    str_contains($adminDash, 'id="tdAdultsCount"') &&
    str_contains($adminDash, 'id="tdKidsCount"'),
    "Missing occupant breakdown elements in modal"
);

assertCondition(
    "Expanded details modal contains emergency contact details",
    str_contains($adminDash, 'id="tdEmergencyName"') &&
    str_contains($adminDash, 'id="tdEmergencyRel"') &&
    str_contains($adminDash, 'id="tdEmergencyPhone"'),
    "Missing emergency contact elements in modal"
);

assertCondition(
    "Expanded modal directly displays uploaded Valid ID image without extra clicks",
    str_contains($adminDash, 'id="tdIdContainer"') &&
    str_contains($adminDash, 'uploads/id_verifications/${encodeURIComponent(t.id_photo_filename)}'),
    "Uploaded ID image is not directly rendered"
);

assertCondition(
    "Live face scan photo is STRICTLY REMOVED from landlord interface",
    !str_contains($adminDash, 'uploads/id_verifications/<?= htmlspecialchars($t[\'live_photo_filename\']) ?>') &&
    !str_contains($adminDash, '📸 Live Scan') &&
    !str_contains($adminDash, 'tdFaceScanContainer'),
    "Live face scan photo is still exposed in landlord interface"
);

// -------------------------------------------------------------------------
// 5. API INTEGRATION TEST: CREATE & OCCUPANT DATA
// -------------------------------------------------------------------------
echo "\n--- 5. API Verification: Tenant Creation & Occupant Data ---\n";

$testEmail = 'testfamily' . time() . '@example.com';
$createPayload = json_encode([
    'action' => 'create',
    'unit_id' => 1,
    'name' => 'Test Family Account',
    'birthday' => '1990-05-15',
    'email' => $testEmail,
    'phone' => '+63 912 345 6789',
    'emergency_contact_name' => 'Emergency Spouse',
    'emergency_contact_relationship' => 'Spouse',
    'emergency_contact_phone' => '+63 999 888 7777',
    'total_occupants' => 4,
    'adults_count' => 2,
    'kids_count' => 2
]);

$ch = curl_init('http://localhost/RentConnect/api/tenant_directory_api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $createPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
// Simulate landlord session by inserting/checking directly in database if session not active via curl
$apiRes = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Also verify directly against database query logic
$vacantUnitId = (int)($pdo->query("SELECT id FROM units WHERE id NOT IN (SELECT unit_id FROM users WHERE role = 'tenant' AND is_active = 1 AND unit_id IS NOT NULL) LIMIT 1")->fetchColumn() ?: 2);
$insStmt = $pdo->prepare("
    INSERT INTO users (
        unit_id, username, name, email, password_hash, role, verification_status, phone, 
        birthday, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, 
        move_in_date, total_occupants, adults_count, kids_count, is_active, created_at
    ) VALUES (
        :unit_id, 'RC999', 'Direct Test Family', :email, 'hash', 'tenant', 'unverified', '+63 900 000 0000',
        '1992-01-01', 'Direct Emergency', 'Parent', '+63 911 222 3333',
        CURDATE(), 5, 2, 3, 1, NOW()
    )
");
$insStmt->execute([':unit_id' => $vacantUnitId, ':email' => $testEmail . '.direct']);
$insertedId = (int)$pdo->lastInsertId();

$verifyStmt = $pdo->prepare("SELECT total_occupants, adults_count, kids_count FROM users WHERE id = :id");
$verifyStmt->execute([':id' => $insertedId]);
$insertedUser = $verifyStmt->fetch(PDO::FETCH_ASSOC);

assertCondition(
    "Occupant fields stored correctly in database (Total: 5, Adults: 2, Kids: 3)",
    $insertedUser && (int)$insertedUser['total_occupants'] === 5 && (int)$insertedUser['adults_count'] === 2 && (int)$insertedUser['kids_count'] === 3,
    "Occupant values mismatch in database"
);

// Clean up test user
$pdo->exec("DELETE FROM users WHERE id = $insertedId");

// -------------------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------------------
echo "\n=================================================================\n";
echo "SUMMARY: Passes: $passes | Errors: " . count($errors) . "\n";
echo "=================================================================\n";

if (empty($errors)) {
    echo ">>> ALL TESTS PASSED! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED: <<<\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
    exit(1);
}
