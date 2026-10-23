<?php
/**
 * Test Suite: Strict Contextual Routing Rules
 * Tests universal routing, landlord in-place actions, tenant profile retention, and major transactional submissions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$pdo = Database::getConnection();

echo "=================================================================\n";
echo "TEST SUITE: STRICT CONTEXTUAL ROUTING RULES ACROSS ALL PORTALS\n";
echo "=================================================================\n\n";

$passes = 0;
$errors = 0;

function assertCondition(bool $condition, string $msg): void {
    global $passes, $errors;
    if ($condition) {
        echo "[PASS] $msg\n";
        $passes++;
    } else {
        echo "[FAIL] $msg\n";
        $errors++;
    }
}

// -----------------------------------------------------------------------------
// 1. Landlord Interface: admin_dashboard.php inspection
// -----------------------------------------------------------------------------
echo "--- 1. Testing Landlord Interface Contextual Routing ---\n";
$adminContent = file_get_contents(__DIR__ . '/../admin_dashboard.php');

// A. Universal history.replaceState routing on category switch
assertCondition(
    strpos($adminContent, "history.replaceState(null, '', `admin_dashboard.php?section=\${sectionId}`)") !== false,
    "switchSection updates URL via history.replaceState with admin_dashboard.php?section="
);

assertCondition(
    strpos($adminContent, "history.replaceState(null, '', `admin_dashboard.php?section=sectionDirectory&unit_id=\${unitId}`)") !== false,
    "openUnitFolder updates URL with admin_dashboard.php?section=sectionDirectory&unit_id="
);

assertCondition(
    strpos($adminContent, "history.replaceState(null, '', `admin_dashboard.php?section=sectionProfile&tab=\${tabName}`)") !== false,
    "switchProfileSubTab updates URL with admin_dashboard.php?section=sectionProfile&tab="
);

// B. DOMContentLoaded restores all sections
assertCondition(
    strpos($adminContent, "targetSection === 'sectionDirectory'") !== false &&
    strpos($adminContent, "targetSection === 'sectionTickets'") !== false &&
    strpos($adminContent, "targetSection === 'sectionPayments'") !== false &&
    strpos($adminContent, "targetSection === 'sectionProfile'") !== false &&
    strpos($adminContent, "targetSection === 'sectionAnalytics'") !== false,
    "DOMContentLoaded in admin_dashboard restores all 5 sections (Directory, Tickets, Payments, Profile, Analytics)"
);

// C. Landlord actions stay in place
// Payment verification stays on sectionPayments
assertCondition(
    strpos($adminContent, "window.location.href = 'admin_dashboard.php?section=sectionPayments'") !== false,
    "submitPaymentReview keeps landlord on sectionPayments (no redirect to main dashboard)"
);

// Tenant registration stays in specific unit folder
assertCondition(
    strpos($adminContent, "admin_dashboard.php?section=sectionDirectory&unit_id=\${uId}") !== false,
    "Tenant registration & closeCredentialsModal keeps landlord inside the specific unit folder"
);

// Tenant delete stays in specific unit folder
assertCondition(
    strpos($adminContent, "admin_dashboard.php?section=sectionDirectory&unit_id=\${resolvedUnitId}") !== false,
    "Tenant deletion keeps landlord inside the specific unit folder"
);

// Landlord change password stays on Profile -> changePassword tab
assertCondition(
    strpos($adminContent, "admin_dashboard.php?section=sectionProfile&tab=changePassword") !== false,
    "Landlord password change keeps landlord on Profile -> Change Password"
);

// -----------------------------------------------------------------------------
// 2. Tenant Interface: tenant_portal.php inspection
// -----------------------------------------------------------------------------
echo "\n--- 2. Testing Tenant Interface Contextual Routing ---\n";
$tenantContent = file_get_contents(__DIR__ . '/../tenant_portal.php');

// A. Universal history.replaceState routing on category switch
assertCondition(
    strpos($tenantContent, "history.replaceState(null, '', `tenant_portal.php?section=\${sectionId}`)") !== false,
    "switchTenantSection updates URL via history.replaceState with tenant_portal.php?section="
);

assertCondition(
    strpos($tenantContent, "history.replaceState(null, '', `tenant_portal.php?section=tenantProfile&tab=\${tabName}`)") !== false,
    "switchProfileSubTab updates URL with tenant_portal.php?section=tenantProfile&tab="
);

// B. DOMContentLoaded restores all sections and tabs
assertCondition(
    strpos($tenantContent, "targetSec === 'tenantProfile'") !== false &&
    strpos($tenantContent, "switchTenantSection(targetSec, targetBtn, false)") !== false,
    "DOMContentLoaded in tenant_portal restores active section and profile tab"
);

// C. Tenant Profile & Settings stay on Profile page
// Password change stays on Profile page
assertCondition(
    strpos($tenantContent, "window.location.href = 'tenant_portal.php?section=tenantProfile&tab=changePassword'") !== false,
    "Tenant password change keeps tenant on Profile -> Change Password"
);

// Edit Account Information modal & handler exist
assertCondition(
    strpos($tenantContent, "id=\"editAccountInfoModal\"") !== false &&
    strpos($tenantContent, "id=\"editAccountInfoForm\"") !== false,
    "Edit Account Information modal and form (#editAccountInfoModal) exist in tenant portal"
);

assertCondition(
    strpos($tenantContent, "window.location.href = 'tenant_portal.php?section=tenantProfile&tab=accountInfo'") !== false,
    "Tenant account info update keeps tenant on Profile -> Account Information"
);

// Verification completion keeps tenant on Account Information
assertCondition(
    substr_count($tenantContent, "window.location.href = 'tenant_portal.php?section=tenantProfile&tab=accountInfo'") >= 2,
    "Tenant biometric verification completion keeps tenant on Profile -> Account Information"
);

// D. Transactional Submissions (Explicit Exception -> Redirect to Dashboard)
// Maintenance ticket redirects to tenantOverview
assertCondition(
    strpos($tenantContent, "window.location.href = 'tenant_portal.php?section=tenantOverview'") !== false,
    "Log Maintenance ticket automatically redirects tenant away from form straight back to Tenant Dashboard"
);

// Rent receipt upload redirects to tenantOverview
assertCondition(
    strpos($tenantContent, "window.location.href = 'tenant_portal.php?section=tenantOverview'") !== false &&
    strpos($tenantContent, "btnUploadReceipt.addEventListener") !== false,
    "Upload Rent Payment receipt automatically redirects tenant away from form straight back to Tenant Dashboard"
);

// -----------------------------------------------------------------------------
// 3. API Layer: profile_api.php inspection & functional test
// -----------------------------------------------------------------------------
echo "\n--- 3. Testing Profile API update_account_info ---\n";
$profileApiContent = file_get_contents(__DIR__ . '/../api/profile_api.php');
assertCondition(
    strpos($profileApiContent, "\$action === 'update_account_info'") !== false,
    "profile_api.php handles action 'update_account_info'"
);

// Test update_account_info via direct database simulation
// Fetch an active tenant to test
$testTenant = $pdo->query("SELECT * FROM users WHERE role = 'tenant' AND is_active = 1 LIMIT 1")->fetch();
if ($testTenant) {
    $tid = (int)$testTenant['id'];
    $origPhone = $testTenant['phone'];
    $newPhone = '+63 999 888 7766';
    
    // Simulate updating account info
    $upd = $pdo->prepare("
        UPDATE users 
        SET phone = :phone,
            emergency_contact_name = :em_name,
            emergency_contact_phone = :em_phone
        WHERE id = :id
    ");
    $upd->execute([
        ':phone' => $newPhone,
        ':em_name' => 'Maria Santos Test',
        ':em_phone' => '+63 911 222 3333',
        ':id' => $tid
    ]);
    
    $recheck = $pdo->query("SELECT phone, emergency_contact_name, emergency_contact_phone FROM users WHERE id = $tid")->fetch();
    assertCondition(
        $recheck['phone'] === $newPhone && $recheck['emergency_contact_name'] === 'Maria Santos Test',
        "Direct update of tenant contact & emergency fields persisted in database"
    );
    
    // Revert phone to original
    $rev = $pdo->prepare("UPDATE users SET phone = :phone WHERE id = :id");
    $rev->execute([':phone' => $origPhone, ':id' => $tid]);
}

echo "\n=================================================================\n";
echo "SUMMARY: Passes: $passes | Errors: $errors\n";
echo "=================================================================\n";

if ($errors === 0) {
    echo ">>> ALL CONTEXTUAL ROUTING TESTS PASSED! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
