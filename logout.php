<?php
/**
 * RentConnect - Logout Action
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

Auth::logout();
header('Location: login.php?logout=1');
exit;
