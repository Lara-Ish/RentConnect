<?php
/**
 * RentConnect - Centralized Email Service
 * Powered by PHPMailer with Gmail SMTP TLS encryption.
 * Handles transactional emails, password resets, system event notifications, and scheduled reminders.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config/mail.php';
require_once dirname(__DIR__) . '/includes/email_template.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!function_exists('sendEmail')) {
    /**
     * Reusable centralized email sending function using PHPMailer and Gmail SMTP.
     * The recipient email MUST come dynamically from the database.
     *
     * @param string $recipientEmail Recipient email dynamically retrieved from database
     * @param string $subject Subject line
     * @param string $message HTML message body
     * @param int|null $recipientUserId Optional recipient user/account ID from database
     * @param PDO|null $pdo Optional PDO database connection for logging to email_logs
     * @return bool True if successfully dispatched
     * @throws Exception If recipient email is invalid or delivery fails
     */
    function sendEmail(string $recipientEmail, string $subject, string $message, ?int $recipientUserId = null, ?PDO $pdo = null): bool
    {
        $recipientEmail = trim($recipientEmail);

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            if ($pdo !== null) {
                EmailService::logEmail($pdo, $recipientEmail, '', $subject, 'general', null, null, 'failed', 'Invalid recipient email address.', $recipientUserId);
            }
            throw new Exception("Invalid recipient email address.");
        }

        $config = MailConfig::get();

        // If credentials are not set in .env (Mock Mode), record and return true
        if (!empty($config['mock_mode'])) {
            if ($pdo !== null) {
                EmailService::logEmail($pdo, $recipientEmail, '', $subject, 'general', null, null, 'mock_sent', 'Dispatched via Mock Mode (Configure Gmail SMTP credentials in .env for live delivery).', $recipientUserId);
            }
            return true;
        }

        $mail = new PHPMailer(true);

        try {
            // Configure Gmail SMTP using environment variables
            $mail->isSMTP();
            $mail->Host       = $config['host'];
            $mail->SMTPAuth   = $config['auth'];
            $mail->Username   = $config['username'];
            $mail->Password   = $config['password'];
            $mail->SMTPSecure = ($config['encryption'] === 'ssl' || $config['encryption'] === 'smtps')
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $config['port'];
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 10;

            // Windows / XAMPP SSL stream options to ensure reliable TLS handshake
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

            // Set sender using configured SMTP credentials
            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($recipientEmail);
            $mail->addReplyTo($config['from_email'], $config['from_name']);

            // Set subject and message body
            $mail->Subject = $subject;
            $mail->Body    = $message;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<p>', '</p>'], ["\n", "\n", "\n\n", ''], $message));
            $mail->isHTML(true);

            $result = $mail->send();

            if ($pdo !== null) {
                EmailService::logEmail($pdo, $recipientEmail, '', $subject, 'general', null, null, 'sent', null, $recipientUserId);
            }

            return (bool)$result;

        } catch (PHPMailerException $e) {
            $err = "PHPMailer Error: " . $mail->ErrorInfo;
            if ($pdo !== null) {
                EmailService::logEmail($pdo, $recipientEmail, '', $subject, 'general', null, null, 'failed', $err, $recipientUserId);
            }
            error_log("[TaraRent Mail] Failed to send email to {$recipientEmail}: {$err}");
            throw new Exception($err, (int)$e->getCode(), $e);
        } catch (Throwable $e) {
            $err = "System Error: " . $e->getMessage();
            if ($pdo !== null) {
                EmailService::logEmail($pdo, $recipientEmail, '', $subject, 'general', null, null, 'failed', $err, $recipientUserId);
            }
            error_log("[TaraRent Mail] Exception sending email to {$recipientEmail}: {$err}");
            throw new Exception($err, (int)$e->getCode(), $e);
        }
    }
}

class EmailService
{
    /**
     * Send an email using PHPMailer with Gmail SMTP TLS
     *
     * @param PDO|null $pdo Database connection for logging
     * @param string $toEmail Recipient email address
     * @param string $toName Recipient full name
     * @param string $subject Email subject
     * @param string $htmlBody Rendered HTML body
     * @param string $templateType Category of email (password_reset, payment, etc.)
     * @param int|null $referenceId Optional reference entity ID
     * @param string|null $referenceType Optional reference entity type
     * @param bool $checkDuplicate Whether to block duplicate sends for this reference
     * @param string|null $altBody Optional plain-text alternative body
     * @param int|null $recipientUserId Optional recipient user/account ID from database
     * @return bool True if sent (or mock_sent), false on error
     */
    public static function send(
        ?PDO $pdo,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $templateType = 'general',
        ?int $referenceId = null,
        ?string $referenceType = null,
        bool $checkDuplicate = false,
        ?string $altBody = null,
        ?int $recipientUserId = null
    ): bool {
        $toEmail = trim($toEmail);
        $toName = trim($toName);

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            self::logEmail($pdo, $toEmail, $toName, $subject, $templateType, $referenceId, $referenceType, 'failed', 'Invalid recipient email format.', $recipientUserId);
            return false;
        }

        // Check deduplication if requested (prevent duplicate sends for the same reference event)
        if ($checkDuplicate && $pdo !== null) {
            try {
                if ($referenceId !== null && $referenceId > 0 && $referenceType !== null) {
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM email_logs 
                        WHERE recipient_email = :email 
                          AND template_type = :ttype 
                          AND reference_id = :ref_id 
                          AND reference_type = :ref_type 
                          AND status IN ('sent', 'mock_sent')
                        LIMIT 1
                    ");
                    $checkStmt->execute([
                        ':email'    => $toEmail,
                        ':ttype'    => $templateType,
                        ':ref_id'   => $referenceId,
                        ':ref_type' => (string)$referenceType
                    ]);
                } else {
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM email_logs 
                        WHERE recipient_email = :email 
                          AND template_type = :ttype 
                          AND subject = :subject
                          AND status IN ('sent', 'mock_sent')
                          AND sent_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                        LIMIT 1
                    ");
                    $checkStmt->execute([
                        ':email'   => $toEmail,
                        ':ttype'   => $templateType,
                        ':subject' => $subject
                    ]);
                }
                if ($checkStmt->fetchColumn()) {
                    // Already sent; return true to indicate handled
                    return true;
                }
            } catch (Throwable $e) {
                // Ignore DB lookup error and proceed with sending
            }
        }

        $config = MailConfig::get();

        // Mock / Development mode when SMTP credentials are not yet configured
        if (!empty($config['mock_mode'])) {
            self::logEmail($pdo, $toEmail, $toName, $subject, $templateType, $referenceId, $referenceType, 'mock_sent', 'Dispatched via Mock Mode (Configure Gmail SMTP credentials in .env for live delivery).', $recipientUserId);
            return true;
        }

        $mail = new PHPMailer(true);

        try {
            // Server Settings
            $mail->isSMTP();
            $mail->Host       = $config['host'];
            $mail->SMTPAuth   = $config['auth'];
            $mail->Username   = $config['username'];
            $mail->Password   = $config['password'];
            $mail->SMTPSecure = ($config['encryption'] === 'ssl' || $config['encryption'] === 'smtps')
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $config['port'];
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 10; // Keep timeout short so web requests aren't held up

            // Windows / XAMPP SSL stream options to ensure reliable TLS handshake
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

            // Sender and Recipient
            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($toEmail, $toName ?: $toEmail);
            $mail->addReplyTo($config['from_email'], $config['from_name']);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $altBody ?: strip_tags(str_replace(['<br>', '<br/>', '<p>', '</p>'], ["\n", "\n", "\n\n", ''], $htmlBody));

            $mail->send();

            self::logEmail($pdo, $toEmail, $toName, $subject, $templateType, $referenceId, $referenceType, 'sent', null, $recipientUserId);
            return true;

        } catch (PHPMailerException $e) {
            $errorMsg = "PHPMailer Error: " . $mail->ErrorInfo;
            self::logEmail($pdo, $toEmail, $toName, $subject, $templateType, $referenceId, $referenceType, 'failed', $errorMsg, $recipientUserId);
            error_log("[TaraRent Mail] Failed to send email to {$toEmail}: {$errorMsg}");
            return false;
        } catch (Throwable $e) {
            $errorMsg = "System Error: " . $e->getMessage();
            self::logEmail($pdo, $toEmail, $toName, $subject, $templateType, $referenceId, $referenceType, 'failed', $errorMsg, $recipientUserId);
            error_log("[TaraRent Mail] Exception sending email to {$toEmail}: {$errorMsg}");
            return false;
        }
    }

    /**
     * Send an email via the centralized sendEmail function
     */
    public static function sendEmail(string $recipientEmail, string $subject, string $message, ?int $recipientUserId = null, ?PDO $pdo = null): bool
    {
        return sendEmail($recipientEmail, $subject, $message, $recipientUserId, $pdo);
    }

    /**
     * Dynamically retrieve property Owner (Landlord) record from database.
     * Never uses hardcoded or fallback email strings.
     */
    public static function getOwnerFromDatabase(PDO $pdo): ?array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT id, name, email 
                FROM users 
                WHERE role = 'landlord' AND is_active = 1 
                ORDER BY id ASC 
                LIMIT 1
            ");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                return [
                    'id'    => (int)$row['id'],
                    'name'  => (string)$row['name'],
                    'email' => (string)$row['email']
                ];
            }
        } catch (Throwable $e) {
            error_log('[EmailService] Database query error for Owner: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Dynamically retrieve a Tenant record from database by user ID.
     * Never uses hardcoded or fallback email strings.
     */
    public static function getTenantFromDatabase(PDO $pdo, int $tenantId): ?array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.email, u.unit_id, un.unit_number
                FROM users u
                LEFT JOIN units un ON u.unit_id = un.id
                WHERE u.id = :id AND u.role = 'tenant' AND u.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([':id' => $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                return [
                    'id'          => (int)$row['id'],
                    'name'        => (string)$row['name'],
                    'email'       => (string)$row['email'],
                    'unit_id'     => $row['unit_id'] !== null ? (int)$row['unit_id'] : null,
                    'unit_number' => $row['unit_number'] ?? 'Unit'
                ];
            }
        } catch (Throwable $e) {
            error_log('[EmailService] Database query error for Tenant: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Send a general custom email with HTML and plain-text fallback
     */
    public static function sendCustomEmail(
        ?PDO $pdo,
        string $toEmail,
        string $subject,
        string $htmlBody,
        ?string $altBody = null,
        string $toName = '',
        string $templateType = 'general',
        ?int $referenceId = null,
        ?string $referenceType = null,
        bool $checkDuplicate = false,
        ?int $recipientUserId = null
    ): bool {
        return self::send(
            $pdo,
            $toEmail,
            $toName ?: $toEmail,
            $subject,
            $htmlBody,
            $templateType,
            $referenceId,
            $referenceType,
            $checkDuplicate,
            $altBody,
            $recipientUserId
        );
    }

    /**
     * Test and diagnose the Gmail SMTP connection
     */
    public static function testSmtpConnection(): array
    {
        $config = MailConfig::get(true);
        if (empty($config['username']) || empty($config['password'])) {
            return [
                'success' => false,
                'message' => 'Gmail SMTP credentials are not configured in environment or .env file.',
                'details' => 'Set SMTP_USER (your Gmail address) and SMTP_PASS (16-character App Password).'
            ];
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $config['host'];
            $mail->SMTPAuth   = $config['auth'];
            $mail->Username   = $config['username'];
            $mail->Password   = $config['password'];
            $mail->SMTPSecure = ($config['encryption'] === 'ssl' || $config['encryption'] === 'smtps')
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $config['port'];
            $mail->Timeout    = 10;

            // Windows / XAMPP SSL stream options to ensure reliable TLS handshake
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

            if (!$mail->smtpConnect()) {
                return [
                    'success' => false,
                    'message' => 'Failed to connect to SMTP server: ' . $mail->ErrorInfo,
                    'details' => $mail->ErrorInfo
                ];
            }
            $mail->smtpClose();

            return [
                'success' => true,
                'message' => "Successfully connected and authenticated with Gmail SMTP ({$config['host']}:{$config['port']}).",
                'details' => 'STARTTLS connection established and authentication succeeded.'
            ];
        } catch (PHPMailerException $e) {
            return [
                'success' => false,
                'message' => 'PHPMailer Error: ' . $e->getMessage(),
                'details' => !empty($mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage()
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Connection Exception: ' . $e->getMessage(),
                'details' => $e->getMessage()
            ];
        }
    }

    /**
     * Safely log email activity to database without sensitive tokens or passwords
     */
    public static function logEmail(
        ?PDO $pdo,
        string $email,
        string $name,
        string $subject,
        string $templateType,
        ?int $referenceId,
        ?string $referenceType,
        string $status,
        ?string $errorMessage,
        ?int $recipientUserId = null
    ): void {
        if ($pdo === null) return;
        try {
            $stmt = $pdo->prepare("
                INSERT INTO email_logs 
                    (recipient_user_id, recipient_email, recipient_name, subject, template_type, reference_id, reference_type, status, error_message, sent_at)
                VALUES 
                    (:user_id, :email, :name, :subject, :ttype, :ref_id, :ref_type, :status, :err, NOW())
            ");
            $stmt->execute([
                ':user_id'  => $recipientUserId,
                ':email'    => substr($email, 0, 191),
                ':name'     => substr($name, 0, 100),
                ':subject'  => substr($subject, 0, 255),
                ':ttype'    => substr($templateType, 0, 50),
                ':ref_id'   => $referenceId,
                ':ref_type' => $referenceType !== null ? substr($referenceType, 0, 50) : null,
                ':status'   => $status,
                ':err'      => $errorMessage
            ]);
        } catch (Throwable $e) {
            // Silently fail logging rather than breaking application execution
        }
    }

    // =========================================================================
    // 1. FORGOT PASSWORD EMAILS
    // =========================================================================

    /**
     * Send a 6-digit password reset verification code
     */
    public static function sendPasswordResetCode(
        PDO $pdo,
        string $recipientEmail,
        string $recipientName,
        string $code,
        int $expireMinutes = 5,
        ?int $recipientUserId = null
    ): bool {
        $subject = 'Password Reset Verification Code';
        $html = EmailTemplate::renderVerificationCode($recipientName, $code, $expireMinutes);
        return self::send($pdo, $recipientEmail, $recipientName, $subject, $html, 'password_reset_code', null, null, false, null, $recipientUserId);
    }

    /**
     * Send password reset link to user (Legacy / direct link support)
     */
    public static function sendPasswordReset(
        PDO $pdo,
        string $recipientEmail,
        string $recipientName,
        string $resetUrl,
        int $expireMinutes = 60,
        ?int $recipientUserId = null
    ): bool {
        $subject = 'TaraRent - Password Reset Request';
        $intro = "We received a request to reset the password for your TaraRent account. Click the button below to choose a new password. This link is valid for the next <strong>{$expireMinutes} minutes</strong>.";

        $details = [
            'Account Email' => $recipientEmail,
            'Request Time'  => date('F j, Y • g:i A'),
            'Link Expiry'   => "Within {$expireMinutes} minutes",
            'Security Tip'  => 'Never share your password reset link with anyone.'
        ];

        $html = EmailTemplate::render(
            'Password Reset Request',
            'SECURITY & ACCESS',
            $recipientName,
            $intro,
            $details,
            'Reset My Password',
            $resetUrl,
            'If you did not request a password reset, you can safely ignore this email. Your account remains secure.'
        );

        return self::send($pdo, $recipientEmail, $recipientName, $subject, $html, 'password_reset', null, null, false, null, $recipientUserId);
    }

    // =========================================================================
    // 2. OWNER NOTIFICATION EMAILS
    // =========================================================================

    /**
     * Notify Owner when a tenant submits payment proof
     */
    public static function notifyOwnerPaymentProofSubmitted(
        PDO $pdo,
        string $ownerEmail,
        string $ownerName,
        string $tenantName,
        string $unitNumber,
        float $amountPaid,
        string $billingCycle,
        int $paymentId,
        ?int $recipientUserId = null
    ): bool {
        $subject = "New Payment Proof Submitted - {$unitNumber}";
        $monthStr = date('F Y', strtotime($billingCycle));
        $formattedAmount = '₱' . number_format($amountPaid, 2);

        $intro = "<strong>{$tenantName}</strong> from <strong>{$unitNumber}</strong> has submitted payment proof for the <strong>{$monthStr}</strong> billing cycle. Please review and verify this payment in your management portal.";

        $details = [
            'Apartment Unit'  => $unitNumber,
            'Resident Name'   => $tenantName,
            'Billing Cycle'   => $monthStr,
            'Amount Submitted'=> $formattedAmount,
            'Submission Date' => date('F j, Y • g:i A'),
            'Status'          => 'Pending Owner Verification'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/admin_dashboard.php?section=sectionPayments';

        $html = EmailTemplate::render(
            'Payment Verification Required',
            'PAYMENT RECEIVED',
            $ownerName,
            $intro,
            $details,
            'Review Payment in Portal',
            $actionUrl,
            'Prompt verification helps keep tenant billing records accurate.'
        );

        return self::send($pdo, $ownerEmail, $ownerName, $subject, $html, 'payment_proof_submitted', $paymentId, 'payment', true, null, $recipientUserId);
    }

    /**
     * Notify Owner when a tenant submits a new maintenance ticket
     */
    public static function notifyOwnerMaintenanceTicketCreated(
        PDO $pdo,
        string $ownerEmail,
        string $ownerName,
        string $tenantName,
        string $unitNumber,
        string $ticketTitle,
        string $category,
        string $priority,
        int $ticketId,
        ?int $recipientUserId = null
    ): bool {
        $subject = "New Maintenance Request Received - {$unitNumber} (#{$ticketId})";

        $intro = "A new maintenance request has been submitted by <strong>{$tenantName}</strong> for <strong>{$unitNumber}</strong>. Please review the ticket details to take action.";

        $details = [
            'Ticket ID'       => "#{$ticketId}",
            'Apartment Unit'  => $unitNumber,
            'Resident Name'   => $tenantName,
            'Issue Title'     => $ticketTitle,
            'Category'        => ucfirst($category),
            'Priority'        => strtoupper($priority),
            'Date Reported'   => date('F j, Y • g:i A'),
            'Current Status'  => 'Open / Awaiting Action'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/admin_dashboard.php?section=sectionTickets';

        $html = EmailTemplate::render(
            'New Maintenance Request',
            'MAINTENANCE ALERT',
            $ownerName,
            $intro,
            $details,
            'View Ticket in Portal',
            $actionUrl
        );

        return self::send($pdo, $ownerEmail, $ownerName, $subject, $html, 'maintenance_ticket_created', $ticketId, 'ticket', true, null, $recipientUserId);
    }

    /**
     * Notify Owner when a tenant is registered or assigned to a unit
     */
    public static function notifyOwnerTenantRegistered(
        PDO $pdo,
        string $ownerEmail,
        string $ownerName,
        string $tenantName,
        string $unitNumber,
        int $tenantId,
        ?int $recipientUserId = null
    ): bool {
        $subject = "Tenant Activity Notification - {$unitNumber}";

        $intro = "A new resident account for <strong>{$tenantName}</strong> has been successfully provisioned and assigned to <strong>{$unitNumber}</strong>.";

        $details = [
            'Apartment Unit'  => $unitNumber,
            'Resident Name'   => $tenantName,
            'Assignment Date' => date('F j, Y • g:i A'),
            'Occupancy Status'=> 'Active Resident'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/admin_dashboard.php?section=sectionDirectory';

        $html = EmailTemplate::render(
            'New Tenant Assigned',
            'TENANT DIRECTORY',
            $ownerName,
            $intro,
            $details,
            'Open Tenant Directory',
            $actionUrl
        );

        return self::send($pdo, $ownerEmail, $ownerName, $subject, $html, 'tenant_registered', $tenantId, 'tenant', true, null, $recipientUserId);
    }

    // =========================================================================
    // 3. TENANT NOTIFICATION EMAILS
    // =========================================================================

    /**
     * Send account creation notification to Tenant (Owner to Tenant)
     * Dispatches login username and temporary password to the tenant's registered email.
     */
    public static function sendTenantAccountCreated(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $username,
        string $temporaryPassword,
        string|int $unitNumber = '',
        int $tenantId = 0,
        ?int $recipientUserId = null
    ): bool {
        $subject = 'Your Apartment Management System Account Has Been Created';
        $config = MailConfig::get();
        $loginUrl = $config['app_url'] . '/login.php';

        // Support backward compatibility if called with (pdo, email, name, unitNumber, setupPasswordUrl, tenantId, recipientUserId)
        if (!empty($temporaryPassword) && (filter_var($temporaryPassword, FILTER_VALIDATE_URL) || str_starts_with($temporaryPassword, 'http'))) {
            $displayUnit = $username;
            $displayUsername = $tenantEmail;
            $displayPassword = '(Sent via setup link)';
            $buttonUrl = $temporaryPassword;
            $buttonText = 'Set Up Your Password';
            $actualTenantId = is_numeric($unitNumber) ? (int)$unitNumber : $tenantId;
            $actualRecipientUserId = $tenantId ?: ($recipientUserId ?: ($actualTenantId > 0 ? $actualTenantId : null));
        } else {
            $displayUnit = (string)$unitNumber;
            $displayUsername = $username;
            $displayPassword = $temporaryPassword;
            $buttonUrl = $loginUrl;
            $buttonText = 'Log In to System';
            $actualTenantId = $tenantId;
            $actualRecipientUserId = $recipientUserId ?: ($actualTenantId > 0 ? $actualTenantId : null);
        }

        $intro = "Your tenant account has been successfully created!<br><br>Here are your account details:";

        $details = [
            'Username'           => $displayUsername,
            'Temporary Password' => $displayPassword,
        ];
        if (!empty($displayUnit)) {
            $details['Assigned Unit'] = $displayUnit;
        }

        $secondaryNotice = "You can now log in to your account using these credentials. Once logged in, you can change your password through your account settings.<br><br>Thank you!";
        $html = EmailTemplate::render(
            'Your Apartment Management System Account Has Been Created',
            'ACCOUNT CREATED',
            $tenantName,
            $intro,
            $details,
            $buttonText,
            $buttonUrl,
            $secondaryNotice
        );

        $altBody = "Hello {$tenantName},\n\n" .
            "Your tenant account has been successfully created!\n\n" .
            "Here are your account details:\n\n" .
            "- Username: {$displayUsername}\n" .
            "- Temporary Password: {$displayPassword}\n\n" .
            "You can now log in to your account using these credentials. Once logged in, you can change your password through your account settings.\n\n" .
            "Thank you!\n\n" .
            "Apartment Management System";

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'tenant_account_created', $actualTenantId, 'tenant', true, $altBody, $actualRecipientUserId);
    }

    /**
     * Notify Tenant when their payment proof is verified
     */
    public static function notifyTenantPaymentVerified(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $unitNumber,
        float $amountPaid,
        string $billingCycle,
        int $paymentId,
        ?int $recipientUserId = null
    ): bool {
        $subject = "Your Payment Has Been Verified - {$unitNumber}";
        $monthStr = date('F Y', strtotime($billingCycle));
        $formattedAmount = '₱' . number_format($amountPaid, 2);

        $intro = "Great news! Your rental payment for <strong>{$monthStr}</strong> has been officially verified and recorded by property management.";

        $details = [
            'Apartment Unit'  => $unitNumber,
            'Billing Cycle'   => $monthStr,
            'Verified Amount' => $formattedAmount,
            'Verification Date' => date('F j, Y • g:i A'),
            'Payment Status'  => 'Verified & Approved'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantPayments';

        $html = EmailTemplate::render(
            'Payment Verified Successfully',
            'RENTAL PAYMENT',
            $tenantName,
            $intro,
            $details,
            'View Payment History',
            $actionUrl,
            'Thank you for keeping your account in good standing!'
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'payment_verified', $paymentId, 'payment', true, null, $recipientUserId);
    }

    /**
     * Notify Tenant when their payment proof is rejected / requires correction
     */
    public static function notifyTenantPaymentRejected(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $unitNumber,
        float $amountPaid,
        string $reason,
        int $paymentId,
        ?int $recipientUserId = null
    ): bool {
        $subject = "Payment Proof Requires Correction - {$unitNumber}";

        $intro = "Your recently submitted payment proof for <strong>{$unitNumber}</strong> could not be verified. Please review the management feedback below and re-submit a valid receipt.";

        $details = [
            'Apartment Unit'  => $unitNumber,
            'Status'          => 'Action Required (Rejected)',
            'Management Note' => $reason ?: 'Payment proof was unclear or did not match the expected amount.',
            'Action Needed'   => 'Please upload a clear screenshot or official receipt'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantPayments';

        $html = EmailTemplate::render(
            'Payment Proof Requires Correction',
            'ACTION REQUIRED',
            $tenantName,
            $intro,
            $details,
            'Re-Upload Payment Proof',
            $actionUrl,
            'If you have questions regarding this notice, please contact property management.'
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'payment_rejected', $paymentId, 'payment', true, null, $recipientUserId);
    }

    /**
     * Notify Tenant of Maintenance Ticket status updates
     */
    public static function notifyTenantMaintenanceUpdated(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $unitNumber,
        string $ticketTitle,
        string $status,
        string $resolutionNotes,
        int $ticketId,
        ?int $recipientUserId = null
    ): bool {
        $statusLabels = [
            'open'        => 'Open / Received',
            'in_progress' => 'In Progress / Assigned',
            'resolved'    => 'Resolved'
        ];
        $readableStatus = $statusLabels[$status] ?? ucfirst($status);
        $subject = "Maintenance Request Update - {$ticketTitle} (#{$ticketId})";

        $intro = "There is an update on your maintenance request <strong>#{$ticketId} ({$ticketTitle})</strong> for <strong>{$unitNumber}</strong>.";

        $details = [
            'Ticket ID'       => "#{$ticketId}",
            'Apartment Unit'  => $unitNumber,
            'Issue'           => $ticketTitle,
            'Updated Status'  => $readableStatus,
            'Resolution Note' => $resolutionNotes ?: 'Status updated by property administration.',
            'Update Time'     => date('F j, Y • g:i A')
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantMaintenance';

        $html = EmailTemplate::render(
            'Maintenance Status Update',
            'MAINTENANCE UPDATE',
            $tenantName,
            $intro,
            $details,
            'View Ticket Details',
            $actionUrl
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'maintenance_updated_' . $status, $ticketId, 'ticket', false, null, $recipientUserId);
    }

    /**
     * Notify Tenant when Owner replies to a maintenance request
     *
     * Sample email structure:
     * Subject: Maintenance Request Update - [Ticket Title]
     * Hello [Tenant Name],
     * The Owner has responded to your maintenance request.
     * Ticket Details:
     * - Ticket Title: [Ticket Title]
     * - Owner's Response: [Owner's Message/Response]
     * - Status: [Current Status]
     * You can view the full details of your request by visiting your dashboard:
     * [Link to Tenant Maintenance Page]
     * Thank you!
     */
    public static function notifyTenantTicketReply(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $ticketTitle,
        string $ownerReply,
        string $ticketStatus,
        int $ticketId = 0,
        ?int $recipientUserId = null
    ): bool {
        $subject = "Maintenance Request Update - {$ticketTitle}";
        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantMaintenance';

        $statusLabels = [
            'open'        => 'Open',
            'in_progress' => 'In Progress',
            'resolved'    => 'Resolved',
            'closed'      => 'Closed'
        ];
        $readableStatus = $statusLabels[strtolower($ticketStatus)] ?? ucfirst($ticketStatus);

        $intro = "The Owner has responded to your maintenance request.<br><br><strong>Ticket Details:</strong>";

        $details = [
            'Ticket Title'     => $ticketTitle,
            'Owner\'s Response' => !empty(trim($ownerReply)) ? htmlspecialchars($ownerReply) : 'Status updated by Owner.',
            'Status'           => $readableStatus
        ];

        $closing = "You can view the full details of your request by visiting your dashboard:<br><a href=\"{$actionUrl}\" style=\"color: #4f46e5; font-weight: 600;\">{$actionUrl}</a><br><br>Thank you!";

        $html = EmailTemplate::render(
            'Maintenance Request Update',
            'MAINTENANCE UPDATE',
            $tenantName,
            $intro,
            $details,
            'View Maintenance in Dashboard',
            $actionUrl,
            $closing
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'ticket_reply', $ticketId ?: null, 'ticket', false, null, $recipientUserId);
    }

    /**
     * Send rental payment reminder to Tenant (upcoming_3days, upcoming_1day, due_today, overdue)
     *
     * Sample email structure:
     * Subject: Rental Payment Reminder - [Unit Number]
     * Hello [Tenant Name],
     * This is a reminder regarding your upcoming rental payment.
     * Payment Details:
     * - Apartment Unit: [Unit Number]
     * - Rental Amount: ₱[Amount]
     * - Due Date: [Due Date]
     * - Payment Status: Unpaid
     * Please make sure to complete your payment on or before the due date.
     * Thank you!
     */
    public static function notifyTenantPaymentReminder(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $unitNumber,
        float $amountDue,
        string $dueDate,
        string $reminderType, // 'upcoming', 'upcoming_3days', 'upcoming_1day', 'due_today', 'overdue'
        int $paymentId,
        ?int $recipientUserId = null
    ): bool {
        $formattedAmount = '₱' . number_format($amountDue, 2);
        $dueFormatted = date('F j, Y', strtotime($dueDate));
        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantPayments';

        switch ($reminderType) {
            case 'overdue':
                $subject = "Overdue Payment Notice - {$unitNumber}";
                $heading = "Payment Overdue Notice";
                $badge   = "OVERDUE NOTICE";
                $intro   = "Our records indicate that your rent payment for <strong>{$unitNumber}</strong> is currently overdue.<br><br><strong>Payment Details:</strong>";
                $closing = "Please make sure to complete your payment and upload your receipt promptly.<br><br>Thank you!";
                break;
            case 'due_today':
                $subject = "Rental Payment Reminder - {$unitNumber}";
                $heading = "Rent Payment Due Today";
                $badge   = "DUE TODAY";
                $intro   = "This is a reminder that your rental payment for <strong>{$unitNumber}</strong> is due today.<br><br><strong>Payment Details:</strong>";
                $closing = "Please make sure to complete your payment on or before the end of the day.<br><br>Thank you!";
                break;
            case 'upcoming_1day':
                $subject = "Rental Payment Reminder - {$unitNumber}";
                $heading = "Upcoming Rent Reminder (Due Tomorrow)";
                $badge   = "DUE TOMORROW";
                $intro   = "This is a reminder regarding your upcoming rental payment for <strong>{$unitNumber}</strong> due tomorrow.<br><br><strong>Payment Details:</strong>";
                $closing = "Please make sure to complete your payment on or before the due date.<br><br>Thank you!";
                break;
            case 'upcoming_3days':
            case 'upcoming':
            default:
                $subject = "Rental Payment Reminder - {$unitNumber}";
                $heading = "Rental Payment Reminder";
                $badge   = "UPCOMING RENT";
                $intro   = "This is a reminder regarding your upcoming rental payment.<br><br><strong>Payment Details:</strong>";
                $closing = "Please make sure to complete your payment on or before the due date.<br><br>Thank you!";
                break;
        }

        $details = [
            'Apartment Unit' => $unitNumber,
            'Rental Amount'  => $formattedAmount,
            'Due Date'       => $dueFormatted,
            'Payment Status' => 'Unpaid'
        ];

        $html = EmailTemplate::render(
            $heading,
            $badge,
            $tenantName,
            $intro,
            $details,
            'View & Pay Rent in Dashboard',
            $actionUrl,
            $closing
        );

        $templateType = 'payment_reminder_' . $reminderType;
        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, $templateType, $paymentId, 'payment', true, null, $recipientUserId);
    }

    /**
     * Send lease expiration reminder to Tenant
     */
    public static function notifyTenantLeaseExpiring(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $unitNumber,
        string $leaseEndDate,
        int $daysRemaining,
        int $tenantId,
        ?int $recipientUserId = null
    ): bool {
        $subject = "Upcoming Lease Expiration Notice - {$unitNumber}";
        $endFormatted = date('F j, Y', strtotime($leaseEndDate));

        $intro = "Your residential lease agreement for <strong>{$unitNumber}</strong> is scheduled to conclude on <strong>{$endFormatted}</strong> (approximately <strong>{$daysRemaining} days</strong> remaining).";

        $details = [
            'Apartment Unit'  => $unitNumber,
            'Lease End Date'  => $endFormatted,
            'Days Remaining'  => "{$daysRemaining} days",
            'Next Steps'      => 'Contact property administration regarding lease renewal or move-out procedures.'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantProfile';

        $html = EmailTemplate::render(
            'Lease Expiration Notice',
            'LEASE NOTICE',
            $tenantName,
            $intro,
            $details,
            'View Lease Details',
            $actionUrl
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'lease_expiring_' . $daysRemaining, $tenantId, 'lease', true, null, $recipientUserId);
    }

    /**
     * Notify Owner when a tenant updates their profile or contact info
     */
    public static function notifyOwnerTenantAccountUpdated(
        PDO $pdo,
        string $ownerEmail,
        string $ownerName,
        string $tenantName,
        string $unitNumber,
        string $updateDetails,
        int $tenantId,
        ?int $recipientUserId = null
    ): bool {
        $subject = "Tenant Profile Updated - {$unitNumber} ({$tenantName})";

        $intro = "<strong>{$tenantName}</strong> from <strong>{$unitNumber}</strong> has updated their account or contact information in the portal.";

        $details = [
            'Apartment Unit'  => $unitNumber,
            'Resident Name'   => $tenantName,
            'Update Summary'  => $updateDetails ?: 'Profile information updated',
            'Update Time'     => date('F j, Y • g:i A'),
            'Action'          => 'Review updated record in tenant directory'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/admin_dashboard.php?section=sectionDirectory';

        $html = EmailTemplate::render(
            'Tenant Profile Updated',
            'ACCOUNT UPDATE',
            $ownerName,
            $intro,
            $details,
            'Open Tenant Directory',
            $actionUrl
        );

        return self::send($pdo, $ownerEmail, $ownerName, $subject, $html, 'tenant_profile_update', $tenantId, 'tenant', true, null, $recipientUserId);
    }

    /**
     * Send community / management announcement to Tenant
     */
    public static function notifyTenantAnnouncement(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $title,
        string $announcementMessage,
        int $announcementId = 0,
        ?int $recipientUserId = null
    ): bool {
        $subject = "Important Announcement: {$title}";

        $intro = "Property management has posted an important announcement for all residents:";

        $details = [
            'Announcement'   => $title,
            'Date Posted'    => date('F j, Y • g:i A'),
            'Message'        => $announcementMessage
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php';

        $html = EmailTemplate::render(
            $title,
            'COMMUNITY ANNOUNCEMENT',
            $tenantName,
            $intro,
            $details,
            'View in Tenant Portal',
            $actionUrl,
            'If you have questions regarding this announcement, please contact property management.'
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'announcement', $announcementId ?: null, 'announcement', false, null, $recipientUserId);
    }

    /**
     * Send rent due announcement to Tenant (Owner to Tenants)
     *
     * Sample email structure matching specification:
     * Subject: Rent Due Announcement - [Announcement Title]
     * Hello [Tenant Name],
     * This is an announcement regarding your rent payment for [Unit Number].
     * Announcement Details:
     * - Title: [Announcement Title]
     * - Message: [Owner's Custom Message]
     * - Due Date: [Due Date]
     * - Unit: [Unit Number]
     * - Rental Amount: ₱[Amount]
     * Please ensure payment is settled on or before the specified due date.
     * Thank you!
     * Apartment Management
     */
    public static function sendRentDueAnnouncement(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $title,
        string $ownerMessage,
        ?string $dueDate,
        string $unitNumber,
        float $amount,
        int $announcementId = 0,
        ?int $recipientUserId = null
    ): bool {
        $subject = "Rent Due Announcement - {$title}";
        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantPayments';

        $dueFormatted = (!empty($dueDate)) ? date('F j, Y', strtotime($dueDate)) : 'As scheduled in lease';
        $formattedAmount = '₱' . number_format($amount, 2);

        $intro = "This is an announcement regarding your rent payment for <strong>{$unitNumber}</strong>.<br><br><strong>Announcement Details:</strong>";

        $details = [
            'Title'         => $title,
            'Message'       => nl2br(htmlspecialchars($ownerMessage, ENT_QUOTES, 'UTF-8')),
            'Due Date'      => $dueFormatted,
            'Unit'          => $unitNumber,
            'Rental Amount' => $formattedAmount
        ];

        $closing = "Please ensure payment is settled on or before the specified due date.<br><br>Thank you!<br><strong>Apartment Management</strong>";

        $html = EmailTemplate::render(
            'Rent Due Announcement',
            'RENT ANNOUNCEMENT',
            $tenantName,
            $intro,
            $details,
            'View in Tenant Portal',
            $actionUrl,
            $closing
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'rent_announcement', $announcementId ?: null, 'announcement', false, null, $recipientUserId);
    }

    /**
     * Send welcome onboarding email to new Tenant
     */
    public static function notifyTenantWelcome(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $unitNumber,
        int $tenantId,
        ?int $recipientUserId = null
    ): bool {
        $recipientUserId = $recipientUserId ?: $tenantId;
        $subject = "Welcome to TaraRent - {$unitNumber}";

        $intro = "Welcome to your new home! Your resident account for <strong>{$unitNumber}</strong> has been successfully configured.";

        $details = [
            'Apartment Unit'  => $unitNumber,
            'Resident Name'   => $tenantName,
            'Portal Access'   => 'Active',
            'Online Features' => 'Pay rent, submit maintenance tickets, view notices'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php';

        $html = EmailTemplate::render(
            'Welcome to TaraRent',
            'WELCOME ABOARD',
            $tenantName,
            $intro,
            $details,
            'Log In to Tenant Portal',
            $actionUrl,
            'Please keep your login credentials secure.'
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'tenant_welcome', $tenantId, 'tenant', true, null, $recipientUserId);
    }

    /**
     * Send security confirmation when password has been successfully reset
     */
    public static function notifyPasswordResetSuccess(
        PDO $pdo,
        string $recipientEmail,
        string $recipientName,
        ?int $recipientUserId = null
    ): bool {
        $subject = 'TaraRent - Password Changed Successfully';

        $intro = "The password for your TaraRent account (<strong>{$recipientEmail}</strong>) was changed successfully. If you initiated this change, no further action is required.";

        $details = [
            'Account Email' => $recipientEmail,
            'Change Time'   => date('F j, Y • g:i A'),
            'Security Status' => 'Password Updated'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/login.php';

        $html = EmailTemplate::render(
            'Password Changed Successfully',
            'SECURITY NOTICE',
            $recipientName,
            $intro,
            $details,
            'Log In to Your Account',
            $actionUrl,
            'If you did NOT change your password, please contact property management immediately to secure your account.'
        );

        return self::send($pdo, $recipientEmail, $recipientName, $subject, $html, 'password_changed', null, null, false, null, $recipientUserId);
    }

    /**
     * Send general system notification email
     */
    public static function sendGeneralNotification(
        PDO $pdo,
        string $toEmail,
        string $toName,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $actionText = null,
        ?int $recipientUserId = null
    ): bool {
        $subject = "TaraRent Notification: {$title}";

        $config = MailConfig::get();
        $url = $actionUrl ?: $config['app_url'];
        $btnText = $actionText ?: 'Open TaraRent';

        $details = [
            'Subject'   => $title,
            'Sent Time' => date('F j, Y • g:i A'),
            'Details'   => $message
        ];

        $html = EmailTemplate::render(
            $title,
            'SYSTEM NOTIFICATION',
            $toName,
            $message,
            $details,
            $btnText,
            $url
        );

        return self::send($pdo, $toEmail, $toName, $subject, $html, 'general_notification', null, null, false, null, $recipientUserId);
    }
}
