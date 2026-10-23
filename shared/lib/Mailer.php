<?php

require_once __DIR__ . '/../../config/env.php';

/**
 * Sends transactional email via Brevo's HTTPS REST API rather than raw SMTP.
 * Render's free web service tier blocks outbound SMTP ports (25/587/465)
 * entirely — PHPMailer/SMTP connections there fail with ETIMEDOUT no matter
 * how the credentials are set up. HTTPS (443) isn't blocked, so this posts
 * to Brevo's API endpoint instead of opening an SMTP socket.
 *
 * When BREVO_API_KEY isn't set (local dev has no real Brevo account),
 * sending is skipped and, on a local machine (APP_ENV=local), the message is
 * written to the PHP error log instead, so the reset/notification flow stays
 * testable without real credentials.
 *
 * $attachments: optional files to attach, each ['name' => 'file.xlsx', 'content' => the file's raw bytes].
 * BREVO_API_URL can point at a stand-in server for automated tests; it defaults to Brevo's real endpoint.
 */
class Mailer
{
    public const DEFAULT_API_URL = 'https://api.brevo.com/v3/smtp/email';

    /**
     * @param array<int,array{name:string,content:string}> $attachments
     */
    public static function send(string $toEmail, string $toName, string $subject, string $bodyHtml, string $bodyText, array $attachments = []): bool
    {
        $apiKey = envValue('BREVO_API_KEY');
        if (!$apiKey) {
            // The whole message (name, address, links, codes) goes to the log only on a local machine. Anywhere else
            // the log is not a safe place for personal data (RA 10173), so only the subject is noted.
            if (envValue('APP_ENV') === 'local') {
                error_log("[Mailer] BREVO_API_KEY not configured — logging instead of sending.\nTo: $toName <$toEmail>\nSubject: $subject\n\n$bodyText");
            } else {
                error_log("[Mailer] BREVO_API_KEY not configured — email not sent (subject: $subject).");
            }
            return false;
        }

        $payload = json_encode(self::payload($toEmail, $toName, $subject, $bodyHtml, $bodyText, $attachments));

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\napi-key: $apiKey\r\n",
                'content' => $payload,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents(envValue('BREVO_API_URL') ?: self::DEFAULT_API_URL, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
                break;
            }
        }

        if ($status >= 200 && $status < 300) {
            return true;
        }

        error_log("[Mailer] Brevo API send failed (HTTP $status): " . ($result !== false ? $result : 'no response — request may not have reached Brevo'));
        return false;
    }

    /**
     * The Brevo request body. Attachments go as base64, which is how Brevo's API takes file content.
     *
     * @param array<int,array{name:string,content:string}> $attachments
     * @return array<string,mixed>
     */
    public static function payload(string $toEmail, string $toName, string $subject, string $bodyHtml, string $bodyText, array $attachments = []): array
    {
        $payload = [
            'sender' => ['name' => envValue('SMTP_FROM_NAME') ?: 'ProfilePath', 'email' => envValue('SMTP_FROM_EMAIL')],
            'to' => [['email' => $toEmail, 'name' => $toName]],
            'subject' => $subject,
            'htmlContent' => $bodyHtml,
            'textContent' => $bodyText,
        ];
        if ($attachments) {
            $payload['attachment'] = array_map(fn($a) => ['name' => $a['name'], 'content' => base64_encode($a['content'])], $attachments);
        }
        return $payload;
    }
}
