<?php
/**
 * RentConnect - Responsive HTML Email Template Builder
 * Generates mobile-responsive, beautifully styled emails with RentConnect signature purple branding.
 */

declare(strict_types=1);

class EmailTemplate
{
    /**
     * Render a standard branded RentConnect HTML email
     */
    public static function render(
        string $heading,
        string $badgeText,
        string $recipientName,
        string $introMessage,
        array $details = [],
        ?string $buttonText = null,
        ?string $buttonUrl = null,
        ?string $secondaryNotice = null
    ): string {
        $detailRowsHtml = '';
        if (!empty($details)) {
            $rows = '';
            foreach ($details as $label => $val) {
                $safeLabel = htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
                $safeVal = htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
                $rows .= "
                    <tr>
                        <td style=\"padding: 10px 14px; font-size: 13px; font-weight: 600; color: #64748b; background-color: #f8fafc; border-bottom: 1px solid #edf2f7; width: 35%;\">{$safeLabel}</td>
                        <td style=\"padding: 10px 14px; font-size: 13.5px; font-weight: 700; color: #0f172a; background-color: #ffffff; border-bottom: 1px solid #edf2f7;\">{$safeVal}</td>
                    </tr>
                ";
            }
            $detailRowsHtml = "
                <table role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"border-collapse: separate; border-spacing: 0; border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin: 20px 0;\">
                    {$rows}
                </table>
            ";
        }

        $buttonHtml = '';
        if (!empty($buttonText) && !empty($buttonUrl)) {
            $safeBtnText = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');
            $safeBtnUrl = htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8');
            $buttonHtml = "
                <div style=\"text-align: center; margin: 28px 0 20px 0;\">
                    <a href=\"{$safeBtnUrl}\" target=\"_blank\" style=\"display: inline-block; padding: 13px 32px; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: #ffffff; text-decoration: none; font-size: 14.5px; font-weight: 700; border-radius: 12px; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4); text-align: center;\">
                        {$safeBtnText}
                    </a>
                </div>
            ";
        }

        $secondaryNoticeHtml = '';
        if (!empty($secondaryNotice)) {
            $safeNotice = htmlspecialchars($secondaryNotice, ENT_QUOTES, 'UTF-8');
            $secondaryNoticeHtml = "
                <p style=\"font-size: 12px; color: #94a3b8; line-height: 1.5; margin-top: 18px; text-align: center;\">
                    {$safeNotice}
                </p>
            ";
        }

        $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $safeBadge = htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $currentDate = date('F j, Y • g:i A');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safeHeading}</title>
    <!--[if mso]>
    <style type="text/css">
        table {border-collapse: collapse; border-spacing: 0; margin: 0;}
        div, td {padding: 0;}
        div {margin: 0 !important;}
    </style>
    <![endif]-->
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f1f5f9; padding: 32px 12px;">
        <tr>
            <td align="center">
                <!-- Main Email Container -->
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08); border: 1px solid rgba(226, 232, 240, 0.8);">
                    
                    <!-- Header Banner -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); padding: 32px 36px; text-align: center;">
                            <div style="display: inline-block; background: rgba(255, 255, 255, 0.18); border-radius: 999px; padding: 5px 14px; margin-bottom: 12px; border: 1px solid rgba(255, 255, 255, 0.25);">
                                <span style="color: #ffffff; font-size: 11px; font-weight: 800; letter-spacing: 0.8px; text-transform: uppercase;">
                                    {$safeBadge}
                                </span>
                            </div>
                            <h1 style="color: #ffffff; font-size: 22px; font-weight: 800; margin: 0; letter-spacing: -0.4px; line-height: 1.3;">
                                {$safeHeading}
                            </h1>
                            <p style="color: rgba(255, 255, 255, 0.85); font-size: 12.5px; margin: 6px 0 0 0; font-weight: 500;">
                                RentConnect Apartment Management System
                            </p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 34px 36px 28px 36px; background-color: #ffffff;">
                            <p style="font-size: 15px; font-weight: 700; color: #0f172a; margin: 0 0 12px 0;">
                                Hello {$safeName},
                            </p>
                            <div style="font-size: 14.5px; color: #334155; line-height: 1.6; margin: 0 0 18px 0;">
                                {$introMessage}
                            </div>

                            {$detailRowsHtml}

                            {$buttonHtml}

                            {$secondaryNoticeHtml}
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; padding: 24px 36px; text-align: center; border-top: 1px solid #e2e8f0;">
                            <div style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 8px;">
                                <span style="font-size: 13px; font-weight: 800; color: #4f46e5;">RentConnect</span>
                                <span style="font-size: 12px; color: #94a3b8;">• Apartment Community</span>
                            </div>
                            <p style="font-size: 11.5px; color: #94a3b8; margin: 0 0 6px 0; line-height: 1.5;">
                                This is an automated notification sent on {$currentDate}.<br>
                                Please do not reply directly to this email.
                            </p>
                            <p style="font-size: 11px; color: #cbd5e1; margin: 0;">
                                &copy; 2026 RentConnect. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Render a 6-digit verification code email
     */
    public static function renderVerificationCode(
        string $recipientName,
        string $code,
        int $expireMinutes = 5
    ): string {
        $formattedCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $codeBoxHtml = "
            <div style=\"text-align: center; margin: 24px 0;\">
                <div style=\"font-size: 13px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;\">Verification Code:</div>
                <div style=\"display: inline-block; background: #f8fafc; border: 2px dashed #6366f1; border-radius: 16px; padding: 16px 36px; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.12);\">
                    <span style=\"font-family: 'Courier New', Courier, monospace; font-size: 36px; font-weight: 800; letter-spacing: 10px; color: #4338ca;\">{$formattedCode}</span>
                </div>
                <p style=\"font-size: 13.5px; font-weight: 600; color: #dc2626; margin-top: 14px;\">
                    ⏰ This code is valid for {$expireMinutes} minutes.
                </p>
            </div>
        ";

        return self::render(
            'Password Reset Verification Code',
            'SECURITY VERIFICATION',
            $recipientName,
            "We received a request to reset your password. Use the verification code below to complete the process:" . $codeBoxHtml,
            [],
            null,
            null,
            "This code is valid for {$expireMinutes} minutes. If you did not request a password reset, please ignore this email.<br><br>Thank you!"
        );
    }
}
