<?php
require_once __DIR__ . '/../config/mail.php';
$conf = MailConfig::get(true);
echo "SMTP Config:\n";
print_r([
    'host' => $conf['host'],
    'port' => $conf['port'],
    'encryption' => $conf['encryption'],
    'auth' => $conf['auth'],
    'username' => !empty($conf['username']) ? substr($conf['username'], 0, 4) . '***' : '(empty)',
    'password_set' => !empty($conf['password']),
    'from_email' => $conf['from_email'],
    'from_name' => $conf['from_name'],
    'is_configured' => $conf['is_configured'],
    'mock_mode' => $conf['mock_mode']
]);
