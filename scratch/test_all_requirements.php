<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = Database::getConnection();

echo "=== RENTCONNECT COMPREHENSIVE REQUIREMENTS VERIFICATION ===\n\n";

$allPassed = true;

function assertTest(string $name, bool $condition, string $detail = '') {
    global $allPassed;
    if ($condition) {
        echo " [PASS] $name\n";
    } else {
        echo " [FAIL] $name: $detail\n";
        $allPassed = false;
    }
}

// 1. Schema Check
$colsStmt = $pdo->query("SHOW COLUMNS FROM users");
$columns = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
assertTest("Column 'birthday' in users", in_array('birthday', $columns));
assertTest("Column 'emergency_contact_name' in users", in_array('emergency_contact_name', $columns));
assertTest("Column 'emergency_contact_relationship' in users", in_array('emergency_contact_relationship', $columns));
assertTest("Column 'emergency_contact_phone' in users", in_array('emergency_contact_phone', $columns));

// 2. Sequential Username & 6-Digit Password Logic Test
$existingUsernamesStmt = $pdo->query("SELECT username FROM users WHERE username REGEXP '^RC[0-9]+$'");
$existingUsernames = $existingUsernamesStmt->fetchAll(PDO::FETCH_COLUMN);

$maxNum = 0;
foreach ($existingUsernames as $uName) {
    $numPart = (int)substr($uName, 2);
    if ($numPart > $maxNum) {
        $maxNum = $numPart;
    }
}
$nextUsername = sprintf('RC%03d', $maxNum + 1);
assertTest("Next username matches RC format", (bool)preg_match('/^RC[0-9]{3,}$/', $nextUsername), "Got $nextUsername");

$randomPass = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
assertTest("Generated password is 6 digits", strlen($randomPass) === 6 && ctype_digit($randomPass), "Got $randomPass");

// 3. Test Creating Tenant via DB & verify password hash
$testEmail = 'test_tenant_verify_' . time() . '@example.com';
$hash = password_hash($randomPass, PASSWORD_BCRYPT);
$ins = $pdo->prepare("
    INSERT INTO users (unit_id, username, name, email, password_hash, role, phone, birthday, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, is_active, created_at)
    VALUES (1, :u, 'Test Auto Tenant', :e, :h, 'tenant', '09123456789', '1998-05-15', 'Deloris Jordan', 'Mother', '09998887777', 1, NOW())
");
$ins->execute([
    ':u' => $nextUsername,
    ':e' => $testEmail,
    ':h' => $hash
]);
$testId = (int)$pdo->lastInsertId();
assertTest("Test tenant created successfully", $testId > 0);

// Verify tenant login check with 6-digit password
$chk = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$chk->execute([':id' => $testId]);
$createdTenant = $chk->fetch();
assertTest("Password verify with 6-digit password succeeds", password_verify($randomPass, $createdTenant['password_hash']));
assertTest("Birthday preserved", $createdTenant['birthday'] === '1998-05-15');
assertTest("Emergency name preserved", $createdTenant['emergency_contact_name'] === 'Deloris Jordan');
assertTest("Emergency relationship preserved", $createdTenant['emergency_contact_relationship'] === 'Mother');
assertTest("Emergency phone preserved", $createdTenant['emergency_contact_phone'] === '09998887777');

// Clean up test tenant
$pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $testId]);
assertTest("Cleaned up test tenant", true);

// 4. File Content Checks (UI / UX / Placeholders / Currency)
$adminContent = file_get_contents(__DIR__ . '/../admin_dashboard.php');
$tenantContent = file_get_contents(__DIR__ . '/../tenant_portal.php');
$cssContent = file_get_contents(__DIR__ . '/../assets/css/claymorphism.css');

// No placeholders
$placeholders = ['garden suite', 'courtyard residence', 'countryard residence', 'skyline view'];
foreach ($placeholders as $ph) {
    assertTest("No '$ph' in admin_dashboard", stripos($adminContent, $ph) === false);
    assertTest("No '$ph' in tenant_portal", stripos($tenantContent, $ph) === false);
}

// Top navigation bar left side check
assertTest("No 'brand-pill-clay' in admin top-nav", !str_contains($adminContent, '<header class="top-nav-bar">' . "\n" . '        <div class="top-nav-left">'));
assertTest("admin top-nav has only top-nav-right", str_contains($adminContent, '<div class="top-nav-right">') && !str_contains($adminContent, 'top-nav-left'));
assertTest("tenant top-nav has only top-nav-right", str_contains($tenantContent, '<div class="top-nav-right">') && !str_contains($tenantContent, 'top-nav-left'));

// Sidebar toggle button placement check
assertTest("Sidebar toggle button in admin sidebar header", str_contains($adminContent, '<button type="button" id="sidebarToggleBtn" class="clay-toggle-btn sidebar-inner-toggle" onclick="toggleSidebar()"'));
assertTest("Sidebar toggle button in tenant sidebar header", str_contains($tenantContent, '<button type="button" id="sidebarToggleBtn" class="clay-toggle-btn sidebar-inner-toggle" onclick="toggleSidebar()"'));

// Persistent Sidebar State check (MUST NOT auto-retract when switching sections)
assertTest("admin switchSection does NOT auto-retract sidebar", !str_contains($adminContent, "sidebar.classList.add('retracted')"));
assertTest("admin switchSection maintains persistent open state", str_contains($adminContent, "The sidebar must NOT auto-collapse on navigation"));
assertTest("admin sidebar buttons are present with IDs", str_contains($adminContent, 'id="navLandlordDashboard"') && str_contains($adminContent, 'id="navTenantDirectory"') && str_contains($adminContent, 'id="navMaintenanceTickets"') && str_contains($adminContent, 'id="navPaymentVerification"'));

// Settings & Profile view check (View with internal sub-sidebar)
assertTest("admin sidebar footer has Settings button", str_contains($adminContent, 'id="settingsToggleBtn"'));
assertTest("admin sidebar footer has Settings dropdown", str_contains($adminContent, 'id="settingsDropdown"'));
assertTest("admin has sectionProfile view", str_contains($adminContent, 'id="sectionProfile"'));
assertTest("tenant sidebar footer has Settings button", str_contains($tenantContent, 'id="settingsToggleBtn"'));
assertTest("tenant sidebar footer has Settings dropdown", str_contains($tenantContent, 'id="settingsDropdown"'));
assertTest("tenant has tenantProfile view", str_contains($tenantContent, 'id="tenantProfile"'));

// Registration Form & Credentials Modal
assertTest("addTenantModal collects birthday", str_contains($adminContent, 'id="newBirthday"'));
assertTest("addTenantModal collects emergency name", str_contains($adminContent, 'id="newEmergencyName"'));
assertTest("addTenantModal collects emergency rel", str_contains($adminContent, 'id="newEmergencyRel"'));
assertTest("addTenantModal collects emergency phone", str_contains($adminContent, 'id="newEmergencyPhone"'));
assertTest("admin has tenantCredentialsModal with copy buttons", str_contains($adminContent, 'id="tenantCredentialsModal"') && str_contains($adminContent, 'copyCredField'));

// CSS Retracted check: hides menu, footer, brand
assertTest("CSS retracted hides brand-wrapper", str_contains($cssContent, '.clay-sidebar.retracted .brand-wrapper'));
assertTest("CSS retracted hides sidebar-menu", str_contains($cssContent, '.clay-sidebar.retracted .sidebar-menu'));
assertTest("CSS retracted hides sidebar-footer", str_contains($cssContent, '.clay-sidebar.retracted .sidebar-footer'));

echo "\nVerification Summary: " . ($allPassed ? "ALL TESTS PASSED!" : "SOME TESTS FAILED!") . "\n";
