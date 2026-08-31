<?php
/**
 * Email Service - HTML & Plain Text Dual-Format Registration Welcome Emails
 * PHP 7.1+
 */

if (!defined('APP_INIT')) {
    die('Direct access not allowed');
}

/**
 * Send Welcome Email to newly registered user (Publisher / Advertiser / Manager)
 */
function send_welcome_email($userEmail, $userName, $roleName)
{
    $roleTitle = ucfirst($roleName);
    $loginUrl  = "https://iconmedianetwork.in/login.php";
    if ($roleName === 'manager') {
        $loginUrl = "https://iconmedianetwork.in/manager/login.php";
    }

    $subject = "Welcome to GVS Icon Media Network - Your {$roleTitle} Account is Created!";

    $boundary = "---=" . md5(uniqid((string)time(), true));

    // Plain text version for high deliverability
    $plainText = "Hello " . $userName . ",\n\n" .
                 "Welcome to GVS Icon Media Network!\n" .
                 "Your " . $roleTitle . " account has been successfully registered on our enterprise performance marketing platform.\n\n" .
                 "Account Details:\n" .
                 "- Name: " . $userName . "\n" .
                 "- Registered Email: " . $userEmail . "\n" .
                 "- Account Type: " . $roleTitle . "\n" .
                 "- Account Status: Pending Verification / Activation\n\n" .
                 "Log into your partner portal to manage campaigns and tracking details:\n" .
                 $loginUrl . "\n\n" .
                 "Best Regards,\n" .
                 "GVS Icon Media Support Team\n" .
                 "support@iconmedianetwork.in";

    // HTML version
    $htmlContent = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='utf-8'></head>
    <body style='font-family: Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 30px 0; color: #1e293b;'>
        <div style='max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.08); border: 1px solid #e2e8f0;'>
            <div style='background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 30px; text-align: center; color: #ffffff;'>
                <h1 style='margin: 0; font-size: 24px; font-weight: 800; color: #38bdf8;'>GVS Icon Media</h1>
                <p style='margin: 4px 0 0; color: #94a3b8; font-size: 13px;'>Global Affiliate & Performance Marketing Network</p>
            </div>
            <div style='padding: 30px; line-height: 1.6; font-size: 14px;'>
                <div style='font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 12px;'>Welcome aboard, " . htmlspecialchars($userName) . "!</div>
                <div style='display: inline-block; background: #e0f2fe; color: #0369a1; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; text-transform: uppercase; margin-bottom: 15px;'>" . htmlspecialchars($roleTitle) . " Account Created</div>
                <p>Thank you for joining GVS Icon Media. Your partner account has been successfully registered on our enterprise performance marketing platform.</p>
                
                <div style='background: #f8fafc; border-left: 4px solid #38bdf8; border-radius: 6px; padding: 15px; margin: 20px 0;'>
                    <p style='margin: 3px 0;'><strong>Account Name:</strong> " . htmlspecialchars($userName) . "</p>
                    <p style='margin: 3px 0;'><strong>Registered Email:</strong> " . htmlspecialchars($userEmail) . "</p>
                    <p style='margin: 3px 0;'><strong>Account Type:</strong> " . htmlspecialchars($roleTitle) . "</p>
                    <p style='margin: 3px 0;'><strong>Account Status:</strong> Pending Verification / Activation</p>
                </div>

                <p>You can log into your dashboard below to complete your profile and set up your postbacks.</p>
                
                <a href='" . $loginUrl . "' style='display: block; width: 200px; margin: 25px auto; padding: 12px 0; background: #0284c7; color: #ffffff !important; text-align: center; text-decoration: none; font-weight: 700; border-radius: 6px; font-size: 14px;'>Log In to Portal</a>
            </div>
            <div style='background: #f8fafc; padding: 20px 30px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8;'>
                <p style='margin: 0 0 5px;'>&copy; " . date('Y') . " GVS Icon Media Network. All rights reserved.</p>
                <p style='margin: 0;'>Support: <a href='mailto:support@iconmedianetwork.in' style='color: #0284c7; text-decoration: none;'>support@iconmedianetwork.in</a></p>
            </div>
        </div>
    </body>
    </html>
    ";

    // Multipart MIME Content Body
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $plainText . "\r\n\r\n";

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $htmlContent . "\r\n\r\n";

    $body .= "--{$boundary}--";

    $fromEmail = "support@iconmedianetwork.in";
    $fromName  = "GVS Icon Media Support";

    // Headers with Multipart MIME Type
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    // Send mail
    @mail($userEmail, $subject, $body, $headers);
    return true;
}
