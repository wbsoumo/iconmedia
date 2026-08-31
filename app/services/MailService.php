<?php
/**
 * Email Service - HTML Registration Welcome Emails & SMTP Support
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

    $htmlBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 40px 0; color: #1e293b; }
            .email-container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
            .header { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 35px 30px; text-align: center; color: #ffffff; }
            .header h1 { margin: 0; font-size: 26px; font-weight: 800; color: #38bdf8; letter-spacing: -0.5px; }
            .header p { margin: 6px 0 0; color: #94a3b8; font-size: 14px; }
            .content { padding: 35px 30px; line-height: 1.6; font-size: 15px; }
            .greeting { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 15px; }
            .role-badge { display: inline-block; background: #e0f2fe; color: #0369a1; font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 20px; text-transform: uppercase; margin-bottom: 20px; }
            .details-box { background: #f8fafc; border-left: 4px solid #38bdf8; border-radius: 6px; padding: 18px; margin: 20px 0; }
            .details-box p { margin: 4px 0; color: #475569; font-size: 14px; }
            .cta-button { display: block; width: 220px; margin: 30px auto; padding: 14px 0; background: #0284c7; color: #ffffff !important; text-align: center; text-decoration: none; font-weight: 700; border-radius: 8px; font-size: 15px; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3); }
            .footer { background: #f8fafc; padding: 25px 30px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 13px; color: #94a3b8; }
            .footer a { color: #0284c7; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class='email-container'>
            <div class='header'>
                <h1>GVS Icon Media</h1>
                <p>Global Affiliate & Performance Marketing Network</p>
            </div>
            <div class='content'>
                <div class='greeting'>Welcome aboard, " . htmlspecialchars($userName) . "!</div>
                <div class='role-badge'>" . htmlspecialchars($roleTitle) . " Account Created</div>
                <p>Thank you for joining GVS Icon Media. Your partner account has been successfully registered on our enterprise performance marketing platform.</p>
                
                <div class='details-box'>
                    <p><strong>Account Name:</strong> " . htmlspecialchars($userName) . "</p>
                    <p><strong>Registered Email:</strong> " . htmlspecialchars($userEmail) . "</p>
                    <p><strong>Account Type:</strong> " . htmlspecialchars($roleTitle) . "</p>
                    <p><strong>Account Status:</strong> Pending Verification / Activation</p>
                </div>

                <p>Our compliance and account manager team is reviewing your profile details. You can log into your dashboard below to complete your KYC details and set up postbacks.</p>
                
                <a href='" . $loginUrl . "' class='cta-button'>Log In to Portal</a>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " GVS Icon Media Network. All rights reserved.</p>
                <p>Need assistance? Contact our team at <a href='mailto:support@iconmedianetwork.in'>support@iconmedianetwork.in</a></p>
            </div>
        </div>
    </body>
    </html>
    ";

    $fromEmail = "support@iconmedianetwork.in";
    $fromName  = "GVS Icon Media Support";

    // Clean RFC 2822 Headers
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    // Mode 1: Standard send
    $sent1 = @mail($userEmail, $subject, $htmlBody, $headers);

    // Mode 2: Envelope parameter fallback if Mode 1 fails
    if (!$sent1) {
        @mail($userEmail, $subject, $htmlBody, $headers, "-f" . $fromEmail);
    }
    
    return true;
}
