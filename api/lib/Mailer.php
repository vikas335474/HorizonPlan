<?php
declare(strict_types=1);

/**
 * Minimal outbound email — wraps PHP's native mail(), which Hostinger shared
 * hosting sends through its own local MTA without needing SMTP credentials.
 * No composer/PHPMailer dependency, matching the project's no-framework,
 * minimal-dependency stack (docs/02 Section 1).
 *
 * If deliverability becomes a real problem (landing in spam, SPF/DKIM
 * alignment with the sending domain), swap sendMail()'s internals for
 * Hostinger's SMTP (Titan Email) credentials — a same-shaped drop-in change,
 * not a rewrite. Not built speculatively now since mail() is Hostinger's
 * documented default path and there's no evidence yet it's insufficient.
 *
 * docs/09 Piece 1: when platform_settings.demo_mode = 'on', every outbound
 * email is suppressed — a demo environment must never actually email a real
 * inbox with fabricated advisor-invite credentials. Logged to error_log so
 * suppression is debuggable rather than a silent no-op. Requires db_config.php
 * (for getPdo()) to already be loaded by the caller, same as every other
 * function here that touches the DB.
 */
function sendMail(string $toEmail, string $subject, string $body): bool
{
    if (function_exists('getPdo')) {
        try {
            $db = getPdo();
            $stmt = $db->query("SELECT demo_mode FROM platform_settings WHERE id = 1 LIMIT 1");
            $row = $stmt ? $stmt->fetch() : false;
            if (($row['demo_mode'] ?? 'off') === 'on') {
                error_log("Mailer: suppressed (demo_mode=on) — to={$toEmail}, subject={$subject}");
                return true;
            }
        } catch (Throwable $e) {
            // If platform_settings can't be read for any reason, fail open
            // toward sending — demo-mode suppression is a convenience, not a
            // security control, so an unreadable table shouldn't block a
            // real production email.
        }
    }

    $host = $_SERVER['SERVER_NAME'] ?? 'horizonplan.local';
    $headers = [
        'From: HorizonPlan <no-reply@' . $host . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    return mail($toEmail, $subject, $body, implode("\r\n", $headers));
}
