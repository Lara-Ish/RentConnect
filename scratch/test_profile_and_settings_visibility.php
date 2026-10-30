<?php
/**
 * Test Profile and Settings Button Behavior
 *
 * Requirements:
 * 1. Profile Button:
 *    - Keep the Profile option inside the existing dropdown menu.
 *    - When the user clicks Profile, display the currently logged-in user's name.
 *    - Retrieve the user's name dynamically from the account information or database.
 *    - Ensure the user's name appears only once.
 *
 * 2. Settings Button Visibility:
 *    - Hide the Settings button by default.
 *    - Only display the Settings button after the user clicks the Profile option.
 *    - When the Profile section is opened, show the Settings button along with the user's profile information.
 *    - Hide the Settings button again when the Profile section is closed.
 *    - Keep the Settings button clickable and preserve its existing functionality.
 *
 * 3. UI Design:
 *    - Maintain the existing glassmorphism design.
 *    - Keep the gear/cog icon without the "Settings" text.
 *    - Ensure proper alignment, spacing, and responsiveness.
 *    - Preserve the existing navigation bar design.
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

echo "=== TESTING PROFILE & SETTINGS BUTTON BEHAVIOR ===\n\n";

$adminContent = file_get_contents(__DIR__ . '/../admin_dashboard.php');
$tenantContent = file_get_contents(__DIR__ . '/../tenant_portal.php');
$cssContent = file_get_contents(__DIR__ . '/../assets/css/claymorphism.css');

// 1. Profile option inside existing dropdown menu
assertTest(
    "admin_dashboard: Profile button inside settingsDropdown",
    preg_match('/<div[^>]*id="settingsDropdown"[^>]*>[\s\S]*?<button[^>]*id="settingsItemProfile"[^>]*>[\s\S]*?Profile[\s\S]*?<\/button>/', $adminContent) === 1
);
assertTest(
    "tenant_portal: Profile button inside settingsDropdown",
    preg_match('/<div[^>]*id="settingsDropdown"[^>]*>[\s\S]*?<button[^>]*id="settingsItemProfile"[^>]*>[\s\S]*?Profile[\s\S]*?<\/button>/', $tenantContent) === 1
);

// 2. Settings button hidden by default in initial markup
assertTest(
    "admin_dashboard: settingsToggleBtn is hidden by default (display: none)",
    preg_match('/<button[^>]*id="settingsToggleBtn"[^>]*style="[^"]*display:\s*none;?[^"]*"/', $adminContent) === 1
);
assertTest(
    "tenant_portal: settingsToggleBtn is hidden by default (display: none)",
    preg_match('/<button[^>]*id="settingsToggleBtn"[^>]*style="[^"]*display:\s*none;?[^"]*"/', $tenantContent) === 1
);

// 3. User name retrieved dynamically from DB / account info (not hardcoded)
assertTest(
    "admin_dashboard: headerUserName is dynamically retrieved from \$user['name']",
    preg_match('/<div[^>]*id="headerUserName"[^>]*>\s*<\?=\s*htmlspecialchars\(\$user\[\'name\'\]/i', $adminContent) === 1
);
assertTest(
    "admin_dashboard: profileFullName is dynamically retrieved from \$user['name']",
    preg_match('/<span[^>]*id="profileFullName"[^>]*>\s*<\?=\s*htmlspecialchars\(\$user\[\'name\'\]/i', $adminContent) === 1
);
assertTest(
    "tenant_portal: headerUserName is dynamically retrieved from \$user['name']",
    preg_match('/<div[^>]*id="headerUserName"[^>]*>\s*<\?=\s*htmlspecialchars\(\$user\[\'name\'\]/i', $tenantContent) === 1
);
assertTest(
    "tenant_portal: profileFullName is dynamically retrieved from \$user['name']",
    preg_match('/<span[^>]*id="profileFullName"[^>]*>\s*<\?=\s*htmlspecialchars\(\$user\[\'name\'\]/i', $tenantContent) === 1
);

// 4. UpdateProfileSettingsVisibility function exists and controls visibility
assertTest(
    "admin_dashboard: updateProfileSettingsVisibility function defined",
    str_contains($adminContent, 'function updateProfileSettingsVisibility(isProfileOpen)')
);
assertTest(
    "tenant_portal: updateProfileSettingsVisibility function defined",
    str_contains($tenantContent, 'function updateProfileSettingsVisibility(isProfileOpen)')
);

// 5. openProfileView displays settings button (calls updateProfileSettingsVisibility(true))
assertTest(
    "admin_dashboard: openProfileView displays Settings button",
    preg_match('/function openProfileView[\s\S]*?updateProfileSettingsVisibility\(true\)/', $adminContent) === 1
);
assertTest(
    "tenant_portal: openProfileView displays Settings button",
    preg_match('/function openProfileView[\s\S]*?updateProfileSettingsVisibility\(true\)/', $tenantContent) === 1
);

// 6. switchSection hides settings button when not on profile (calls updateProfileSettingsVisibility(false))
assertTest(
    "admin_dashboard: switchSection hides Settings button on section navigation",
    preg_match('/function switchSection[\s\S]*?updateProfileSettingsVisibility\(false\)/', $adminContent) === 1
);
assertTest(
    "tenant_portal: switchTenantSection hides Settings button on section navigation",
    preg_match('/function switchTenantSection[\s\S]*?updateProfileSettingsVisibility\(false\)/', $tenantContent) === 1
);

// 7. Initial page load synchronizes Settings button visibility based on whether Profile is open
assertTest(
    "admin_dashboard: DOMContentLoaded checks active profile section",
    str_contains($adminContent, "updateProfileSettingsVisibility(targetSection === 'sectionProfile')")
);
assertTest(
    "tenant_portal: initTenantEventListeners checks active profile section",
    str_contains($tenantContent, "updateProfileSettingsVisibility(targetSec === 'tenantProfile')")
);

// 8. Navigation bar interactive triggers allow opening dropdown when Settings button is hidden
assertTest(
    "admin_dashboard: headerProfileTrigger and headerAvatarBadge trigger toggleSettingsMenu",
    str_contains($adminContent, 'id="headerProfileTrigger" onclick="toggleSettingsMenu(event)"')
    && str_contains($adminContent, 'id="headerAvatarBadge" onclick="toggleSettingsMenu(event)"')
);
assertTest(
    "tenant_portal: headerProfileTrigger and headerAvatarBadge trigger toggleSettingsMenu",
    str_contains($tenantContent, 'id="headerProfileTrigger" onclick="toggleSettingsMenu(event)"')
    && str_contains($tenantContent, 'id="headerAvatarBadge" onclick="toggleSettingsMenu(event)"')
);

// 9. Outside click handlers account for header profile triggers
assertTest(
    "admin_dashboard: outside click listener preserves dropdown when clicking triggers",
    str_contains($adminContent, "!e.target.closest('#headerProfileTrigger')")
    && str_contains($adminContent, "!e.target.closest('#headerAvatarBadge')")
);
assertTest(
    "tenant_portal: outside click listener preserves dropdown when clicking triggers",
    str_contains($tenantContent, "!e.target.closest('#headerProfileTrigger')")
    && str_contains($tenantContent, "!e.target.closest('#headerAvatarBadge')")
);

// 10. Gear icon strictly without Settings text label
assertTest(
    "admin_dashboard: button displays ONLY gear icon (no text)",
    preg_match('/<button[^>]*id="settingsToggleBtn"[^>]*>([\s\S]*?)<\/button>/', $adminContent, $m)
    && trim(strip_tags($m[1])) === ''
);
assertTest(
    "tenant_portal: button displays ONLY gear icon (no text)",
    preg_match('/<button[^>]*id="settingsToggleBtn"[^>]*>([\s\S]*?)<\/button>/', $tenantContent, $m)
    && trim(strip_tags($m[1])) === ''
);

// 11. Clean Heroicon gear icon
assertTest(
    "Heroicon cog-6-tooth path present in both templates",
    str_contains($adminContent, 'd="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593')
    && str_contains($tenantContent, 'd="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593')
);

// 12. Glassmorphism styling preserved
assertTest(
    "claymorphism.css: header-settings-btn glassmorphism intact",
    str_contains($cssContent, '.header-settings-btn')
    && str_contains($cssContent, 'backdrop-filter: blur(8px)')
    && str_contains($cssContent, 'border-radius: 12px')
);

echo "\nAll 18 Profile & Settings Button Behavior tests PASSED!\n";
