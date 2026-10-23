<?php
/**
 * RentConnect - Main Entry Point & Role Dispatcher
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

if (Auth::isLandlord()) {
    header('Location: admin_dashboard.php');
    exit;
} else {
    header('Location: tenant_portal.php');
    exit;
}
