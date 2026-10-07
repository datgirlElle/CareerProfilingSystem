<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/EmailTemplate.php';

/**
 * Automatic result email sent after the Career Electives Worksheet is submitted
 * (adviser/researcher decision):
 *   match    -> the Best Match and the Alternative Courses.
 *   mismatch -> only asks the student to visit the Guidance Office; no course is named.
 */
class ResultEmail
{
    /**
     * @param string[] $bestMatch    Best Match title(s)
     * @param string[] $alternatives Alternative Courses titles
     * @return array{subject: string, heading: string, bodyHtml: string, bodyText: string, ctaLabel: string, path: string}
     */
    public static function compose(string $firstName, string $status, array $bestMatch, array $alternatives = []): array
    {
        $safeName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
        $intro = 'Thank you for completing the RIASEC Assessment and the Career Electives Worksheet.';
        $p = '<p style="margin:0 0 12px 0;">';
        $esc = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');

        if ($status === 'match' && $bestMatch) {
            $altHtml = $alternatives
                ? "{$p}Alternative courses:</p>" . '<ul style="margin:0 0 12px 0;padding-left:20px;color:#0f172a;">'
                    . implode('', array_map(fn($t) => '<li style="margin:0 0 4px 0;">' . $esc($t) . '</li>', $alternatives)) . '</ul>'
                : '';
            return [
                'subject' => 'Your ProfilePath career results',
                'heading' => 'Your career results are ready',
                'bodyHtml' => "{$p}Hi $safeName,</p>{$p}$intro</p>"
                    . "{$p}Your Best Match: <strong style=\"color:#0f172a;\">" . implode(', ', array_map($esc, $bestMatch)) . '</strong></p>'
                    . $altHtml
                    . '<p style="margin:0;">You may visit the Guidance Office if you would like to discuss your results with a Guidance Counselor.</p>',
                'bodyText' => "Hi $firstName,\n\n$intro\n\nYour Best Match: " . implode(', ', $bestMatch) . "\n"
                    . ($alternatives ? "\nAlternative courses:\n" . implode('', array_map(fn($t) => "- $t\n", $alternatives)) : '')
                    . "\nYou may visit the Guidance Office if you would like to discuss your results with a Guidance Counselor.",
                'ctaLabel' => 'View My Results',
                'path' => '/results',
            ];
        }

        return [
            'subject' => 'Your ProfilePath career results',
            'heading' => 'Please visit the Guidance Office',
            'bodyHtml' => "{$p}Hi $safeName,</p>{$p}$intro</p>"
                . '<p style="margin:0;">Please visit the Center for Guidance and Counseling to discuss your results with a Guidance Counselor.</p>',
            'bodyText' => "Hi $firstName,\n\n$intro\n\nPlease visit the Center for Guidance and Counseling to discuss your results with a Guidance Counselor.",
            'ctaLabel' => 'See Guidance Office Hours',
            'path' => '/help-center',
        ];
    }

    /** Sends the result email to the student. Never throws; returns whether it was sent. */
    public static function send(PDO $pdo, int $studentId, string $status, array $bestMatch, array $alternatives = []): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT u.username, u.email, s.first_name_enc FROM users u JOIN students s ON s.user_id = u.id WHERE u.id = ?');
            $stmt->execute([$studentId]);
            $row = $stmt->fetch();
            if (!$row) {
                return false;
            }
            // Same fallback as forgot-password.php for accounts without an email on file.
            $email = $row['email'] ?: ($row['username'] . '@mymail.mapua.edu.ph');
            $firstName = Crypto::dec($row['first_name_enc']);

            $mail = self::compose($firstName, $status, $bestMatch, $alternatives);
            $link = rtrim((string) envValue('APP_URL'), '/') . $mail['path'];
            $bodyHtml = EmailTemplate::render(
                $mail['heading'], $mail['bodyHtml'], $mail['ctaLabel'], $link, '',
                'You are receiving this because you submitted the Career Electives Worksheet on ProfilePath.'
            );
            return Mailer::send($email, $firstName, $mail['subject'], $bodyHtml, $mail['bodyText'] . "\n\n$link");
        } catch (Throwable $e) {
            error_log('[ResultEmail] ' . $e->getMessage());
            return false;
        }
    }
}
