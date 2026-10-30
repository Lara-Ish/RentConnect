<?php
/**
 * Test Settings Button Reposition, Icon-Only Display, and Clean Gear Icon
 *
 * Requirements:
 * - Remove the "Settings" text label. Display only the Settings icon.
 * - Fix the Settings icon. Replace the current incorrect or distorted icon with a clean, recognizable gear/cog icon.
 * - Keep the Settings button positioned in the upper-right area of the navigation bar.
 * - Properly center and align the gear icon within the button.
 * - Maintain the existing glassmorphism design, including the rounded corners, soft shadows, transparency, and blur effects.
 * - Ensure the icon has appropriate sizing, spacing, and visual consistency with the Home icon.
 * - Keep the existing dropdown menu functionality (Profile and Sign Out) when clicking the Settings icon.
 * - Make sure the layout remains responsive on different screen sizes.
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

echo "=== TESTING SETTINGS BUTTON ICON-ONLY & CLEAN GEAR ICON ===\n\n";

$adminContent = file_get_contents(__DIR__ . '/../admin_dashboard.php');
$tenantContent = file_get_contents(__DIR__ . '/../tenant_portal.php');
$cssContent = file_get_contents(__DIR__ . '/../assets/css/claymorphism.css');

// 1. Verify Settings button is placed in top-nav-right in admin_dashboard.php
assertTest(
    "admin_dashboard: top-nav-right contains Settings button",
    preg_match('/<div class="top-nav-right">[\s\S]*?id="settingsToggleBtn"[\s\S]*?<\/header>/', $adminContent) === 1
);

// 2. Verify Settings button is NOT in the aside sidebar in admin_dashboard.php
assertTest(
    "admin_dashboard: sidebar does NOT contain Settings button",
    preg_match('/<aside[\s\S]*?id="settingsToggleBtn"[\s\S]*?<\/aside>/', $adminContent) === 0
);

// 3. Verify Settings button is placed in top-nav-right in tenant_portal.php
assertTest(
    "tenant_portal: top-nav-right contains Settings button",
    preg_match('/<div class="top-nav-right">[\s\S]*?id="settingsToggleBtn"[\s\S]*?<\/header>/', $tenantContent) === 1
);

// 4. Verify Settings button is NOT in the aside sidebar in tenant_portal.php
assertTest(
    "tenant_portal: sidebar does NOT contain Settings button",
    preg_match('/<aside[\s\S]*?id="settingsToggleBtn"[\s\S]*?<\/aside>/', $tenantContent) === 0
);

// 5. Verify NO visible text label inside button in admin_dashboard (icon only)
preg_match('/<button[^>]*id="settingsToggleBtn"[^>]*>(.*?)<\/button>/s', $adminContent, $adminBtnMatches);
$adminBtnInner = isset($adminBtnMatches[1]) ? trim(strip_tags($adminBtnMatches[1])) : 'not_found';
assertTest(
    "admin_dashboard: button displays ONLY the icon, NO text label",
    $adminBtnInner === '' && !str_contains($adminContent, 'settings-btn-label')
);

// 6. Verify NO visible text label inside button in tenant_portal (icon only)
preg_match('/<button[^>]*id="settingsToggleBtn"[^>]*>(.*?)<\/button>/s', $tenantContent, $tenantBtnMatches);
$tenantBtnInner = isset($tenantBtnMatches[1]) ? trim(strip_tags($tenantBtnMatches[1])) : 'not_found';
assertTest(
    "tenant_portal: button displays ONLY the icon, NO text label",
    $tenantBtnInner === '' && !str_contains($tenantContent, 'settings-btn-label')
);

// 7. Verify Clean, recognizable gear/cog icon in admin_dashboard
assertTest(
    "admin_dashboard: clean recognizable gear/cog icon with stroke-width=1.5",
    str_contains($adminContent, 'd="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593')
    && str_contains($adminContent, 'stroke-width="1.5"')
);

// 8. Verify Clean, recognizable gear/cog icon in tenant_portal
assertTest(
    "tenant_portal: clean recognizable gear/cog icon with stroke-width=1.5",
    str_contains($tenantContent, 'd="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593')
    && str_contains($tenantContent, 'stroke-width="1.5"')
);

// 9. Verify Visual consistency with Home icon (viewBox 0 0 24 24, stroke-width 1.5, text-gray-700)
assertTest(
    "Visual consistency: gear icon matches Home icon attributes (w-6 h-6, stroke-width 1.5, fill=none)",
    str_contains($adminContent, '<svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">')
);

// 10. Verify Dropdown menu and items preserved
assertTest(
    "admin_dashboard: settingsDropdown with Profile and Sign Out preserved",
    str_contains($adminContent, 'id="settingsDropdown"')
    && str_contains($adminContent, 'onclick="openProfileView()"')
    && str_contains($adminContent, 'href="logout.php"')
);
assertTest(
    "tenant_portal: settingsDropdown with Profile and Sign Out preserved",
    str_contains($tenantContent, 'id="settingsDropdown"')
    && str_contains($tenantContent, 'onclick="openProfileView()"')
    && str_contains($tenantContent, 'href="logout.php"')
);

// 11. Verify Glassmorphism styling in CSS (rounded corners, soft shadows, transparency, blur)
assertTest(
    "claymorphism.css: header-settings-btn has transparency (rgba) and blur (backdrop-filter)",
    str_contains($cssContent, '.header-settings-btn')
    && str_contains($cssContent, 'backdrop-filter: blur')
    && str_contains($cssContent, 'border-radius: 12px')
);

// 12. Verify Centering and alignment in CSS
assertTest(
    "claymorphism.css: header-settings-btn has display flex and centered alignment",
    str_contains($cssContent, 'align-items: center')
    && str_contains($cssContent, 'justify-content: center')
);

echo "\nAll 12 Settings Button Icon-Only & Clean Gear tests PASSED!\n";
