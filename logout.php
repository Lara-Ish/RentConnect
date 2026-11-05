<?php
/**
 * RentConnect - Logout Action
 */

declare(strict_types=1);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once __DIR__ . '/config/auth.php';

Auth::logout();
header('Location: login.php?logout=1');
exit;
