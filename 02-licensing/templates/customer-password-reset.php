<?php
/**
 * Email template — customer password reset.
 *
 * Variables in scope when rendered:
 *   $site_name   string
 *   $accent      string  hex like #2563EB
 *   $name        string
 *   $reset_url   string  full URL with token
 */
if (!isset($site_name, $accent, $name, $reset_url)) return;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Reset your <?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') ?> password</title>
</head>
<body style="margin:0; padding:0; background:#F5F4F1; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; color:#111217;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#F5F4F1;">
    <tr><td align="center" style="padding:32px 16px;">

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width:520px; background:#FFFFFF; border:1px solid rgba(0,0,0,0.08); border-radius:12px;">
            <tr><td style="padding:32px 32px 24px;">

                <div style="display:inline-block; width:48px; height:48px; background:<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>; color:#FFFFFF; border-radius:12px; text-align:center; line-height:48px; font-size:22px; font-weight:700; letter-spacing:-0.02em; margin-bottom:20px;">
                    <?= htmlspecialchars(strtoupper(substr($site_name, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                </div>

                <h1 style="margin:0 0 12px; font-size:22px; font-weight:600; letter-spacing:-0.02em; color:#111217;">
                    Reset your password
                </h1>

                <p style="margin:0 0 24px; font-size:14px; line-height:1.55; color:#1F2937;">
                    Hi <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>, we received a request to reset your <?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') ?> password. Click the button below to choose a new one.
                </p>

                <p style="margin:0 0 24px;">
                    <a href="<?= htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8') ?>"
                       style="display:inline-block; background:<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>; color:#FFFFFF; text-decoration:none; padding:11px 20px; border-radius:8px; font-size:14px; font-weight:500; line-height:1.2;">
                        Reset password
                    </a>
                </p>

                <p style="margin:0 0 4px; font-size:12px; color:#6B7280;">
                    Or paste this link into your browser:
                </p>
                <p style="margin:0 0 24px; font-size:12px; color:#6B7280; word-break:break-all;">
                    <?= htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8') ?>
                </p>

                <p style="margin:0; font-size:12px; color:#6B7280;">
                    This link expires in 2 hours. If you didn't request a password reset, you can ignore this email — your password is unchanged.
                </p>

            </td></tr>
        </table>

        <p style="margin:16px 0 0; font-size:11px; color:#9CA3AF;">
            Sent by <?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') ?>
        </p>

    </td></tr>
</table>
</body>
</html>
