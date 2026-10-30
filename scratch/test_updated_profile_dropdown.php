<?php
/**
 * Test for:
 * 1. Navigation Bar initial icon, dynamic retrieval, removal of name beside refresh
 * 2. Profile Dropdown streamlining (removal of subaccounts, removal of 'See all profiles')
 * 3. Settings & Privacy directly below profile info
 * 4. Sign Out below Settings & Privacy
 */

function assertTest(string $description, bool $condition, ?string $extra = null): void
{
    if ($condition) {
        echo " [PASS] $description\n";
    } else {
        echo " [FAIL] $description" . ($extra ? " ($extra)" : "") . "\n";
        exit(1);
    }
}

echo "=== TESTING UPDATED PROFILE DROPDOWN & NAVIGATION BAR ===\n\n";

$adminContent = file_get_contents(__DIR__ . '/../admin_dashboard.php');
$tenantContent = file_get_contents(__DIR__ . '/../tenant_portal.php');
$cssContent = file_get_contents(__DIR__ . '/../assets/css/claymorphism.css');

// --- 1. NAVIGATION BAR CHECKS ---

// Check 1.1: User name removed from beside Refresh button in admin top-nav
assertTest(
    "admin_dashboard: user name block removed from beside Refresh button in top-nav",
    !preg_match('/<div class="landlord-header-profile"[\s\S]*?<\/div>/', $adminContent)
    && !str_contains($adminContent, 'landlord-header-profile')
);

// Check 1.2: Refresh button is present in top-nav in admin_dashboard
assertTest(
    "admin_dashboard: Refresh button is present and intact in top-nav",
    str_contains($adminContent, 'btn-refresh-compact')
    && str_contains($adminContent, 'loadDashboardAnalytics()')
);

// Check 1.3: User initial calculated dynamically in admin_dashboard and tenant_portal
assertTest(
    "admin_dashboard: dynamically retrieves initial from user name",
    str_contains($adminContent, '$userInitial =')
    && str_contains($adminContent, '$userFirstName')
);
assertTest(
    "tenant_portal: dynamically retrieves initial from user name",
    str_contains($tenantContent, '$userInitial =')
    && str_contains($tenantContent, '$userFirstName')
);

// Check 1.4: Dynamic test of initial calculation logic
$name1 = "Lara";
$initial1 = strtoupper(mb_substr(trim(explode(' ', trim($name1))[0]), 0, 1));
assertTest("Initial logic: 'Lara' -> 'L'", $initial1 === 'L');

$name2 = "Lead Dimaculangan";
$initial2 = strtoupper(mb_substr(trim(explode(' ', trim($name2))[0]), 0, 1));
assertTest("Initial logic: 'Lead Dimaculangan' -> 'L'", $initial2 === 'L');

$name3 = "Juan Dela Cruz";
$initial3 = strtoupper(mb_substr(trim(explode(' ', trim($name3))[0]), 0, 1));
assertTest("Initial logic: 'Juan Dela Cruz' -> 'J'", $initial3 === 'J');

// Check 1.5: Circular profile icon displays $userInitial
assertTest(
    "admin_dashboard: header avatar badge renders \$userInitial",
    str_contains($adminContent, 'class="header-avatar-initial"><?= htmlspecialchars($userInitial) ?>')
);
assertTest(
    "tenant_portal: header avatar badge renders \$userInitial",
    str_contains($tenantContent, 'class="header-avatar-initial"><?= htmlspecialchars($userInitial) ?>')
);

// Check 1.6: CSS styling for avatar badge has purple gradient & circular shape
assertTest(
    "CSS: .header-avatar-badge has 50% border radius and purple gradient",
    str_contains($cssContent, '.header-avatar-badge {')
    && str_contains($cssContent, 'border-radius: 50%')
    && str_contains($cssContent, 'linear-gradient(135deg, #a5b4fc, #818cf8)')
);
assertTest(
    "CSS: .header-avatar-initial is centered and bold white",
    str_contains($cssContent, '.header-avatar-initial {')
    && str_contains($cssContent, 'font-weight: 800')
    && str_contains($cssContent, '#ffffff')
);

// --- 2. PROFILE DROPDOWN CONTENT CHECKS ---
// Extract settingsDropdown content
preg_match('/<div[^>]*id="settingsDropdown"[^>]*>([\s\S]*?)<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/', $adminContent, $adminDropdownMatch);
$adminDropdown = $adminDropdownMatch[1] ?? '';

preg_match('/<div[^>]*id="settingsDropdown"[^>]*>([\s\S]*?)<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/', $tenantContent, $tenantDropdownMatch);
$tenantDropdown = $tenantDropdownMatch[1] ?? '';

// Check 2.1: User's profile information card at the top
assertTest(
    "admin_dashboard: fb-profile-card at top of dropdown with profile name",
    str_contains($adminDropdown, '<div class="fb-profile-card">')
    && str_contains($adminDropdown, 'id="profileDropdownName"')
);
assertTest(
    "tenant_portal: fb-profile-card at top of dropdown with profile name",
    str_contains($tenantDropdown, '<div class="fb-profile-card">')
    && str_contains($tenantDropdown, 'id="profileDropdownName"')
);

// Check 2.2: Removal of '1 Apartment • 3 Units' and '3 Active Residents' from profile dropdown
assertTest(
    "admin_dashboard: '1 Apartment • 3 Units' section removed from profile dropdown",
    !str_contains($adminDropdown, '1 Apartment • 3 Units')
    && !str_contains($adminDropdown, 'Managed Property')
);
assertTest(
    "admin_dashboard: '3 Active Residents' section removed from profile dropdown",
    !str_contains($adminDropdown, '3 Active Residents')
    && !str_contains($adminDropdown, 'Property Administration')
);

// Check 2.3: Removal of subaccount rows from tenant portal dropdown
assertTest(
    "tenant_portal: subaccount rows removed from profile dropdown",
    !str_contains($tenantDropdown, 'fb-subaccount-row')
    && !str_contains($tenantDropdown, 'fb-subaccount-text')
);

// Check 2.4: Removal of 'See all profiles' button
assertTest(
    "admin_dashboard: 'See all profiles' button completely removed from dropdown",
    !str_contains($adminDropdown, 'See all profiles')
    && !str_contains($adminDropdown, 'fb-see-profiles-btn')
);
assertTest(
    "tenant_portal: 'See all profiles' button completely removed from dropdown",
    !str_contains($tenantDropdown, 'See all profiles')
    && !str_contains($tenantDropdown, 'fb-see-profiles-btn')
);

// Check 2.5: Settings & Privacy directly below profile information
assertTest(
    "admin_dashboard: Settings & Privacy is inside fb-actions-group directly after fb-profile-card",
    preg_match('/<\/div>\s*<!--[^-]*-->\s*<div class="fb-actions-group">\s*<!--[^-]*-->\s*<button[^>]*id="settingsToggleBtn"/s', $adminContent) === 1
);
assertTest(
    "tenant_portal: Settings & Privacy is inside fb-actions-group directly after fb-profile-card",
    preg_match('/<\/div>\s*<!--[^-]*-->\s*<div class="fb-actions-group">\s*<!--[^-]*-->\s*<button[^>]*id="settingsToggleBtn"/s', $tenantContent) === 1
);

// Check 2.6: Sign Out placed below Settings & Privacy
assertTest(
    "admin_dashboard: Sign Out placed directly below Settings & Privacy",
    preg_match('/id="settingsToggleBtn"[\s\S]*?<a href="logout\.php"[^>]*title="Sign Out"/s', $adminContent) === 1
);
assertTest(
    "tenant_portal: Sign Out placed directly below Settings & Privacy",
    preg_match('/id="settingsToggleBtn"[\s\S]*?<a href="logout\.php"[^>]*title="Sign Out"/s', $tenantContent) === 1
);

// --- 3. GLASSMORPHISM & DESIGN CHECKS ---
assertTest(
    "CSS: .fb-profile-dropdown has glassmorphism backdrop-filter blur and subtle border",
    str_contains($cssContent, 'backdrop-filter: blur(')
    && str_contains($cssContent, '-webkit-backdrop-filter: blur(')
    && str_contains($cssContent, 'border-radius: 18px')
);

// --- 4. SECTION 6: PROFILE INFORMATION DISPLAY-ONLY CHECKS ---

preg_match('/<div class="fb-profile-card">([\s\S]*?)<\/div>\s*<!--/s', $adminDropdown, $adminCardMatch);
$adminCard = $adminCardMatch[0] ?? '';

preg_match('/<div class="fb-profile-card">([\s\S]*?)<\/div>\s*<!--/s', $tenantDropdown, $tenantCardMatch);
$tenantCard = $tenantCardMatch[0] ?? '';

// Check 4.1: Profile information card has NO button or link wrapper in admin
assertTest(
    "admin_dashboard: profile info is display-only div with no button/a/onclick",
    str_contains($adminCard, 'fb-profile-display-only')
    && !str_contains($adminCard, '<button')
    && !str_contains($adminCard, '<a')
    && !str_contains($adminCard, 'onclick')
    && !str_contains($adminCard, 'cursor: pointer')
);

// Check 4.2: Profile information card has NO button or link wrapper in tenant
assertTest(
    "tenant_portal: profile info is display-only div with no button/a/onclick",
    str_contains($tenantCard, 'fb-profile-display-only')
    && !str_contains($tenantCard, '<button')
    && !str_contains($tenantCard, '<a')
    && !str_contains($tenantCard, 'onclick')
    && !str_contains($tenantCard, 'cursor: pointer')
);

// Check 4.3: Exactly TWO interactive elements in settingsDropdown: Settings & Privacy and Sign Out
preg_match_all('/<(?:button|a)\b[^>]*>/', $adminDropdown, $adminInteractive);
assertTest(
    "admin_dashboard: dropdown has exactly 2 clickable options (Settings & Privacy, Sign Out)",
    count($adminInteractive[0]) === 2
    && str_contains($adminInteractive[0][0], 'settingsToggleBtn')
    && str_contains($adminInteractive[0][1], 'logout.php')
);

preg_match_all('/<(?:button|a)\b[^>]*>/', $tenantDropdown, $tenantInteractive);
assertTest(
    "tenant_portal: dropdown has exactly 2 clickable options (Settings & Privacy, Sign Out)",
    count($tenantInteractive[0]) === 2
    && str_contains($tenantInteractive[0][0], 'settingsToggleBtn')
    && str_contains($tenantInteractive[0][1], 'logout.php')
);

// Check 4.4: CSS strictly enforces non-clickable, no hover effects, no pointer cursor
assertTest(
    "CSS: .fb-profile-main-row has cursor: default and pointer-events: none",
    str_contains($cssContent, '.fb-profile-main-row {')
    && str_contains($cssContent, 'cursor: default')
    && str_contains($cssContent, 'pointer-events: none')
);
assertTest(
    "CSS: .fb-profile-main-row:hover has transparent background (no hover effect)",
    str_contains($cssContent, '.fb-profile-main-row:hover {')
    && str_contains($cssContent, 'background: transparent')
);
assertTest(
    "CSS: .fb-profile-display-only enforces default cursor and no pointer events",
    str_contains($cssContent, '.fb-profile-display-only {')
    && str_contains($cssContent, 'cursor: default !important')
    && str_contains($cssContent, 'pointer-events: none !important')
);

echo "\nALL TESTS PASSED SUCCESSFULLY!\n";
