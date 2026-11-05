<?php
/**
 * Test Suite: Tenant Profile Dropdown Functionality
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$pdo = Database::getConnection();

function loginViaHttp(string $username, string $password): string {
    $ch = curl_init('http://localhost/RentConnect/login.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['username' => $username, 'password' => $password]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false
    ]);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    curl_close($ch);

    if (preg_match_all('/Set-Cookie:\s*([^;]+)/mi', $headers, $matches)) {
        return end($matches[1]);
    }
    throw new RuntimeException("Failed to login as {$username}");
}

function assertTest(bool $condition, string $message): void {
    if (!$condition) {
        echo "❌ FAILED: {$message}\n";
        exit(1);
    }
    echo "✅ PASSED: {$message}\n";
}

echo "=================================================================\n";
echo "TEST SUITE: TENANT PROFILE DROPDOWN FUNCTIONALITY\n";
echo "=================================================================\n\n";

// -------------------------------------------------------------------------
// 1. Setup a Test Tenant in Unit 1
// -------------------------------------------------------------------------
echo "--- 1. Provisioning Test Tenant ---\n";
// Remove any existing tenant in Unit 1 first
$pdo->exec("DELETE FROM users WHERE role = 'tenant' AND unit_id = 1");

$testPassword = password_hash('password123', PASSWORD_BCRYPT);
$ins = $pdo->prepare("
    INSERT INTO users (
        unit_id, username, name, email, password_hash, role, verification_status,
        phone, birthday, emergency_contact_name, move_in_date, is_active, created_at
    ) VALUES (
        1, 'test_tenant_elena', 'Elena Rostova', 'elena.rostova@test.com', :pw, 'tenant', 'verified',
        '+63 917 111 2222', '1996-03-12', 'Ivan Rostov', '2026-11-01', 1, NOW()
    )
");
$ins->execute([':pw' => $testPassword]);
$tenant1Id = (int)$pdo->lastInsertId();
assertTest($tenant1Id > 0, "Created test tenant 'Elena Rostova' in Unit 1 (ID: {$tenant1Id})");

// -------------------------------------------------------------------------
// 2. Authenticate as Elena Rostova & Inspect Portal
// -------------------------------------------------------------------------
echo "\n--- 2. Tenant Portal Rendering & Dynamic Profile Initial ---\n";

$sessionCookie1 = loginViaHttp('test_tenant_elena', 'password123');

$ch = curl_init('http://localhost/RentConnect/tenant_portal.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $sessionCookie1]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$headers = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

assertTest($httpCode === 200, "tenant_portal.php loaded with HTTP 200 for tenant");
assertTest(stripos($headers, 'Cache-Control: no-store') !== false, "tenant_portal.php sends 'Cache-Control: no-store' header");
assertTest(stripos($headers, 'Pragma: no-cache') !== false, "tenant_portal.php sends 'Pragma: no-cache' header");

// Check Profile Initial Icon in Navigation Bar
assertTest(strpos($body, 'id="headerAvatarBadge"') !== false, "Navigation bar contains circular profile icon (#headerAvatarBadge)");
assertTest(strpos($body, 'onclick="toggleSettingsMenu(event)"') !== false, "Profile icon has toggleSettingsMenu(event) trigger");
assertTest(strpos($body, '<span class="header-avatar-initial">E</span>') !== false, "Profile initial icon displays 'E' dynamically for 'Elena Rostova'");

// Check Profile Dropdown Structure
assertTest(strpos($body, 'id="settingsDropdown"') !== false, "Profile dropdown panel (#settingsDropdown) exists");
assertTest(strpos($body, 'fb-profile-dropdown') !== false, "Profile dropdown uses glassmorphic fb-profile-dropdown styling");
assertTest(strpos($body, 'fb-profile-display-only') !== false, "Profile card has fb-profile-display-only non-clickable styling");
assertTest(strpos($body, 'id="profileDropdownName">Elena Rostova</span>') !== false, "Profile dropdown displays full tenant name 'Elena Rostova'");

// Check Clickable Options
assertTest(strpos($body, 'id="settingsToggleBtn"') !== false, "Settings & Privacy option (#settingsToggleBtn) exists");
assertTest(strpos($body, 'onclick="openProfileView(\'accountInfo\')"') !== false, "Settings & Privacy option calls openProfileView('accountInfo')");
assertTest(strpos($body, 'href="logout.php"') !== false, "Sign Out link (href='logout.php') exists inside dropdown");

// Check Settings Section (Role-Appropriate)
assertTest(strpos($body, 'id="tenantProfile"') !== false, "Resident Profile & Settings section (#tenantProfile) exists");
assertTest(strpos($body, 'id="paneAccountInfo"') !== false, "Account Information pane (#paneAccountInfo) exists");
assertTest(strpos($body, 'id="paneChangePassword"') !== false, "Change Password pane (#paneChangePassword) exists");

// Verify Admin/Landlord controls are NOT exposed
assertTest(strpos($body, 'sectionAnalytics') === false, "Landlord analytics dashboard NOT exposed to tenant");
assertTest(strpos($body, 'sectionDirectory') === false, "Landlord tenant directory management NOT exposed to tenant");
assertTest(strpos($body, 'reviewPaymentModal') === false, "Landlord payment verification modal NOT exposed to tenant");

// -------------------------------------------------------------------------
// 3. Test Dynamic Tenant Name & Initial with Second Tenant
// -------------------------------------------------------------------------
echo "\n--- 3. Testing Dynamic Resolution with Second Tenant (Mark Vance) ---\n";

// Remove any existing tenant in Unit 2
$pdo->exec("DELETE FROM users WHERE role = 'tenant' AND unit_id = 2");

$ins2 = $pdo->prepare("
    INSERT INTO users (
        unit_id, username, name, email, password_hash, role, verification_status,
        phone, birthday, emergency_contact_name, move_in_date, is_active, created_at
    ) VALUES (
        2, 'test_tenant_mark', 'Mark Vance', 'mark.vance@test.com', :pw, 'tenant', 'verified',
        '+63 918 333 4444', '1994-08-22', 'Sarah Vance', '2026-11-01', 1, NOW()
    )
");
$ins2->execute([':pw' => $testPassword]);
$tenant2Id = (int)$pdo->lastInsertId();
assertTest($tenant2Id > 0, "Created second tenant 'Mark Vance' in Unit 2 (ID: {$tenant2Id})");

$sessionCookie2 = loginViaHttp('test_tenant_mark', 'password123');

$ch = curl_init('http://localhost/RentConnect/tenant_portal.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $sessionCookie2]);
$body2 = curl_exec($ch);
curl_close($ch);

assertTest(strpos($body2, '<span class="header-avatar-initial">M</span>') !== false, "Profile initial icon displays 'M' dynamically for 'Mark Vance'");
assertTest(strpos($body2, 'id="profileDropdownName">Mark Vance</span>') !== false, "Profile dropdown displays full tenant name 'Mark Vance'");

// -------------------------------------------------------------------------
// 4. Test Sign Out & Back-Button Session Termination
// -------------------------------------------------------------------------
echo "\n--- 4. Testing Sign Out Action & Back-Button Protection ---\n";

// Execute logout for tenant 2
$ch = curl_init('http://localhost/RentConnect/logout.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $sessionCookie2]);
$logoutResp = curl_exec($ch);
$logoutCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assertTest($logoutCode === 302, "logout.php returns HTTP 302 redirect");
assertTest(stripos($logoutResp, 'Location: login.php?logout=1') !== false, "Redirects to login.php?logout=1");

// Attempt to access tenant_portal.php with the logged-out session cookie
$ch = curl_init('http://localhost/RentConnect/tenant_portal.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $sessionCookie2]);
$blockedResp = curl_exec($ch);
$blockedCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assertTest($blockedCode === 302, "Protected portal access after logout redirected with HTTP 302");
assertTest(stripos($blockedResp, 'Location: login.php') !== false, "Redirected to login.php (Back-button protected)");

// -------------------------------------------------------------------------
// 5. Test Landlord / Owner Profile Dropdown Intact
// -------------------------------------------------------------------------
echo "\n--- 5. Verify Landlord / Admin Functionality Remains Intact ---\n";

$adminCookie = loginViaHttp('admin', 'admin123');

$ch = curl_init('http://localhost/RentConnect/admin_dashboard.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $adminCookie]);
$adminBody = curl_exec($ch);
$adminCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assertTest($adminCode === 200, "admin_dashboard.php loaded with HTTP 200 for landlord");
assertTest(strpos($adminBody, '<span class="header-avatar-initial">L</span>') !== false, "Admin profile initial displays 'L' for 'Lead Dimaculangan'");
assertTest(strpos($adminBody, 'id="settingsDropdown"') !== false, "Admin profile dropdown exists");
assertTest(strpos($adminBody, 'onclick="openProfileView(\'accountInfo\')"') !== false, "Admin Settings & Privacy option works");
assertTest(strpos($adminBody, 'href="logout.php"') !== false, "Admin Sign Out option works");

// Cleanup test tenants created during this test
$pdo->exec("DELETE FROM users WHERE id IN ({$tenant1Id}, {$tenant2Id})");
echo "\nCleaned up test tenant records.\n";

echo "\n🎉 ALL TENANT PROFILE DROPDOWN TESTS PASSED SUCCESSFULLY! 🎉\n";
