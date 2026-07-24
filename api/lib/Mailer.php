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
 */
function sendMail(string $toEmail, string $subject, string $body): bool
{
    $host = $_SERVER['SERVER_NAME'] ?? 'horizonplan.local';
    $headers = [
        'From: HorizonPlan <no-reply@' . $host . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    return mail($toEmail, $subject, $body, implode("\r\n", $headers));
}
