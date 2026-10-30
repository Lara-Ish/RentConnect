<?php
/**
 * Test Suite: Forgot Password - Login Interface (UI Only)
 */

declare(strict_types=1);

echo "=================================================================\n";
echo "TEST SUITE: FORGOT PASSWORD LOGIN INTERFACE (UI ONLY)\n";
echo "=================================================================\n\n";

$passes = 0;
$failures = [];

function assertTest(string $name, bool $condition, string $detail = '') {
    global $passes, $failures;
    if ($condition) {
        echo " [PASS] $name\n";
        $passes++;
    } else {
        echo " [FAIL] $name: $detail\n";
        $failures[] = "$name: $detail";
    }
}

$loginContent = file_get_contents(__DIR__ . '/../login.php');
$cssContent = file_get_contents(__DIR__ . '/../assets/css/claymorphism.css');

// 1. Text link presence
assertTest(
    "login.php contains 'Forgot Password?' text link",
    str_contains($loginContent, 'Forgot Password?')
);

// 2. Positioned below Password input field and before Log in submit button
$pwInputPos = strpos($loginContent, 'id="password"');
$forgotPos = strpos($loginContent, 'Forgot Password?');
$loginBtnPos = strpos($loginContent, '<span>Log in</span>');

assertTest(
    "Forgot Password link is positioned below the Password input field",
    $pwInputPos !== false && $forgotPos !== false && $forgotPos > $pwInputPos,
    "Forgot link must come after password input"
);

assertTest(
    "Forgot Password link is positioned before the Log in submit button",
    $loginBtnPos !== false && $forgotPos !== false && $forgotPos < $loginBtnPos,
    "Forgot link must come before submit button"
);

// 3. Right-side alignment
assertTest(
    "Forgot Password wrapper has flex right alignment (justify-content: flex-end)",
    (str_contains($loginContent, 'justify-content: flex-end') || str_contains($cssContent, 'justify-content: flex-end')) &&
    str_contains($loginContent, 'class="forgot-password-wrapper"')
);

// 4. Purple theme & styling
assertTest(
    "Forgot Password link uses purple palette (--clay-primary-dark)",
    str_contains($loginContent, 'color: var(--clay-primary-dark)') || str_contains($cssContent, 'color: var(--clay-primary-dark)')
);

// 5. Clickable appearance & hover effect
assertTest(
    "Forgot Password link specifies pointer cursor",
    str_contains($loginContent, 'cursor: pointer') || str_contains($cssContent, 'cursor: pointer')
);

assertTest(
    "Forgot Password link has hover effect with underline",
    (str_contains($loginContent, '.forgot-password-link:hover') && str_contains($loginContent, 'text-decoration: underline')) ||
    (str_contains($cssContent, '.forgot-password-link:hover') && str_contains($cssContent, 'text-decoration: underline'))
);

// 6. UI only: No form submission / reset token logic
assertTest(
    "Forgot Password is a placeholder (no reset token form or submission)",
    str_contains($loginContent, 'onclick="event.preventDefault();"') || str_contains($loginContent, 'href="#"')
);

// 7. Verify existing authentication logic unmodified
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = Database::getConnection();
$adminUser = $pdo->query("SELECT * FROM users WHERE role = 'landlord' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest("Existing landlord account intact in database", !empty($adminUser));

$tenantUser = $pdo->query("SELECT * FROM users WHERE role = 'tenant' AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest("Existing tenant account intact in database", !empty($tenantUser));

echo "\n=================================================================\n";
echo "SUMMARY: Passes: $passes | Failures: " . count($failures) . "\n";
echo "=================================================================\n";

if (empty($failures)) {
    echo ">>> ALL FORGOT PASSWORD UI REQUIREMENTS VERIFIED & PASSED! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED <<<\n";
    exit(1);
}
