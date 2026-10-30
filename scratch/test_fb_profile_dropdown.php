<?php
/**
 * Test Facebook-Style Profile Dropdown and Settings & Privacy Feature
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

echo "=== TESTING FACEBOOK-STYLE PROFILE DROPDOWN & SETTINGS & PRIVACY ===\n\n";

$adminContent = file_get_contents(__DIR__ . '/../admin_dashboard.php');
$tenantContent = file_get_contents(__DIR__ . '/../tenant_portal.php');
$cssContent = file_get_contents(__DIR__ . '/../assets/css/claymorphism.css');

// 1. Profile picture trigger in navigation bar with down-arrow badge
assertTest(
    "admin_dashboard: profile picture trigger with down-arrow badge in top-nav-right",
    str_contains($adminContent, 'id="headerAvatarBadge"')
    && str_contains($adminContent, 'header-avatar-arrow-badge')
    && str_contains($adminContent, 'onclick="toggleSettingsMenu(event)"')
);
assertTest(
    "tenant_portal: profile picture trigger with down-arrow badge in top-nav-right",
    str_contains($tenantContent, 'id="headerAvatarBadge"')
    && str_contains($tenantContent, 'header-avatar-arrow-badge')
    && str_contains($tenantContent, 'onclick="toggleSettingsMenu(event)"')
);

// 2. Dynamic Avatar URL and Name Retrieval
assertTest(
    "admin_dashboard: resolves \$userAvatarUrl dynamically from db / uploads",
    str_contains($adminContent, '$userAvatarUrl =')
    && str_contains($adminContent, "user['live_photo_filename']")
);
assertTest(
    "tenant_portal: resolves \$userAvatarUrl dynamically from db / uploads",
    str_contains($tenantContent, '$userAvatarUrl =')
    && str_contains($tenantContent, "user['live_photo_filename']")
);

// 3. User name rendered once in profile card
assertTest(
    "admin_dashboard: user name displayed in profile card",
    preg_match('/<span[^>]*class="fb-profile-name"[^>]*>[\s\S]*?htmlspecialchars\(\$user\[\'name\'\]/i', $adminContent) === 1
);
assertTest(
    "tenant_portal: user name displayed in profile card",
    preg_match('/<span[^>]*class="fb-profile-name"[^>]*>[\s\S]*?htmlspecialchars\(\$user\[\'name\'\]/i', $tenantContent) === 1
);

// 4. Additional profile details or associated accounts displayed below main name
assertTest(
    "admin_dashboard: displays associated accounts / managed property details",
    str_contains($adminContent, 'fb-subaccount-row')
    && str_contains($adminContent, '1 Apartment • 3 Units')
    && str_contains($adminContent, 'Active Residents')
);
assertTest(
    "tenant_portal: displays associated accounts / unit lease details",
    str_contains($tenantContent, 'fb-subaccount-row')
    && str_contains($tenantContent, "unit['unit_number']")
    && str_contains($tenantContent, 'Active Lease')
);

// 5. "See all profiles" pill button present
assertTest(
    "admin_dashboard: contains 'See all profiles' pill button",
    str_contains($adminContent, 'fb-see-profiles-btn')
    && str_contains($adminContent, 'See all profiles')
);
assertTest(
    "tenant_portal: contains 'See all profiles' pill button",
    str_contains($tenantContent, 'fb-see-profiles-btn')
    && str_contains($tenantContent, 'See all profiles')
);

// 6. Settings & Privacy positioned BELOW profile information card
assertTest(
    "admin_dashboard: Settings & privacy row positioned below fb-profile-card",
    preg_match('/class="fb-profile-card"[\s\S]*?<\/div>[\s\S]*?class="fb-actions-group"[\s\S]*?Settings &amp; privacy/', $adminContent) === 1
);
assertTest(
    "tenant_portal: Settings & privacy row positioned below fb-profile-card",
    preg_match('/class="fb-profile-card"[\s\S]*?<\/div>[\s\S]*?class="fb-actions-group"[\s\S]*?Settings &amp; privacy/', $tenantContent) === 1
);

// 7. Settings & Privacy features gear icon in circular background and right arrow
assertTest(
    "admin_dashboard: Settings & privacy has gear icon in circle background and right arrow",
    str_contains($adminContent, 'fb-action-icon-circle')
    && str_contains($adminContent, 'd="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593')
    && str_contains($adminContent, 'fb-action-arrow')
    && str_contains($adminContent, 'd="m8.25 4.5 7.5 7.5-7.5 7.5"')
);
assertTest(
    "tenant_portal: Settings & privacy has gear icon in circle background and right arrow",
    str_contains($tenantContent, 'fb-action-icon-circle')
    && str_contains($tenantContent, 'd="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593')
    && str_contains($tenantContent, 'fb-action-arrow')
    && str_contains($tenantContent, 'd="m8.25 4.5 7.5 7.5-7.5 7.5"')
);

// 8. Settings & Privacy clicks open existing settings / profile view
assertTest(
    "admin_dashboard: Settings & privacy clicks invoke openProfileView('accountInfo')",
    preg_match('/id="settingsToggleBtn"[^>]*onclick="openProfileView\(\'accountInfo\'\)"/', $adminContent) === 1
);
assertTest(
    "tenant_portal: Settings & privacy clicks invoke openProfileView('accountInfo')",
    preg_match('/id="settingsToggleBtn"[^>]*onclick="openProfileView\(\'accountInfo\'\)"/', $tenantContent) === 1
);

// 9. Glassmorphism styling and rounded container in CSS
assertTest(
    "claymorphism.css: .fb-profile-dropdown has rounded corners (18px) and backdrop-filter blur",
    str_contains($cssContent, '.fb-profile-dropdown')
    && str_contains($cssContent, 'border-radius: 18px')
    && str_contains($cssContent, 'backdrop-filter: blur(16px)')
);
assertTest(
    "claymorphism.css: .fb-profile-card has rounded corners (14px) and shadow",
    str_contains($cssContent, '.fb-profile-card')
    && str_contains($cssContent, 'border-radius: 14px')
);
assertTest(
    "claymorphism.css: .fb-action-icon-circle has circular background (border-radius: 50%)",
    str_contains($cssContent, '.fb-action-icon-circle')
    && str_contains($cssContent, 'border-radius: 50%')
);

// 10. Clicking outside closes dropdown
assertTest(
    "admin_dashboard: outside click listener closes dropdown",
    str_contains($adminContent, '!e.target.closest(\'#settingsDropdown\')')
    && str_contains($adminContent, '!e.target.closest(\'#headerAvatarBadge\')')
);
assertTest(
    "tenant_portal: outside click listener closes dropdown",
    str_contains($tenantContent, '!e.target.closest(\'#settingsDropdown\')')
    && str_contains($tenantContent, '!e.target.closest(\'#headerAvatarBadge\')')
);

echo "\nAll 16 Facebook-Style Profile Dropdown & Settings & Privacy tests PASSED!\n";
