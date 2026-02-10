<?php

declare(strict_types=1);

/**
 * Email helper — wraps PHP mail() with proper headers.
 */

/**
 * Send an HTML email via PHP mail().
 *
 * @param string $to      Recipient email address
 * @param string $subject Email subject line
 * @param string $htmlBody HTML body content
 * @return bool True if mail() accepted the message for delivery
 */
function sendMail(string $to, string $subject, string $htmlBody): bool
{
    $env = parseEnvFile();

    $from    = $env['MAIL_FROM'] ?? 'noreply@peoples.ru';
    $replyTo = $env['MAIL_REPLY_TO'] ?? 'alex@peoples.ru';

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=utf-8',
        'From: ' . $from,
        'Reply-To: ' . $replyTo,
        'X-Mailer: peoples.ru',
    ]);

    return mail($to, $subject, $htmlBody, $headers);
}
