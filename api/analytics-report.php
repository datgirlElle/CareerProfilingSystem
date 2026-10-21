<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/AnalyticsReport.php';
require_once __DIR__ . '/../lib/AnalyticsXlsx.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';
require_once __DIR__ . '/../lib/RateLimiter.php';

// The Analytics Summary Report (Analytics & Report page), built from the same numbers the page shows
// (AnalyticsReport::compute). Totals and shares only, no student details.
//   GET                 the report as tables, for the page's preview
//   GET ?format=xlsx    Download Report (Excel): the report as an Excel file
//   POST {email, ...}   Send Analytics Report: builds the report again from the latest data and emails it, as an Excel
//                       attachment, to an address the staff member types in (the principal, say; no account needed)
// Strand, section and academic year follow the page's filters, so the report matches what is on screen.
//
// Every Excel file is protected against editing, as the old Export Report (Excel) was. Each file gets its own random
// password, which is emailed to the staff member who downloaded or sent it, never to the recipient: they can unprotect
// their copy if they need to change it, and nobody who is only passed a copy can. The password is stored nowhere else.
//
// Previewing and downloading: anyone who can see the whole analytics page (Full on Student Monitoring).
// Sending: the administrator (Head of Guidance) and Guidance Counselors, not Guidance Facilitators.
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = Rbac::requireAccess('monitoring', 'full');
} else {
    $user = Auth::requireLogin();
    if (!in_array(Rbac::roleKey($user), ['admin', 'counselor'], true)) {
        jsonResponse(['success' => false, 'error' => 'Only the administrator and Guidance Counselors can send the analytics report.'], 403);
    }
}
$pdo = Database::get();
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? readJsonBody() : $_GET;

$strand = trim((string) ($input['strand'] ?? ''));
if ($strand !== '' && !in_array($strand, ['STEM', 'ABM', 'ICT', 'HUMSS'], true)) {
    $strand = '';
}
$section = mb_substr(trim((string) ($input['section'] ?? '')), 0, 40);
$academicYear = trim((string) ($input['academicYear'] ?? ''));

$report = AnalyticsXlsx::summaryReport(
    AnalyticsReport::compute($pdo, $strand, $section, $academicYear),
    date('F j, Y, g:i A') . ' (Philippine time)'
);
$fileName = 'ProfilePath-Analytics-Summary-Report-' . date('Y-m-d') . '.xlsx';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($input['format'] ?? '') !== 'xlsx') {
    jsonResponse(['success' => true, 'fileName' => $fileName] + $report);
}

// ---- Both the download and the send make a protected file whose password goes to this staff member.
$account = $pdo->prepare('SELECT email, full_name, username FROM users WHERE id = ?');
$account->execute([(int) $user['id']]);
$me = $account->fetch();
$myEmail = trim((string) ($me['email'] ?? ''));
$myName = trim((string) ($me['full_name'] ?? '')) !== '' ? (string) $me['full_name'] : (string) $me['username'];
if ($myEmail === '') {
    // The password has to reach the person somehow; without an email on the account it would be lost.
    jsonResponse(['success' => false, 'error' => 'Your account has no email address, so the password to edit the report could not be sent to you. Ask the system administrator to add one.'], 409);
}

/** Emails the edit password of a report to the staff member; $sentTo is the recipient when the report was emailed. */
function emailEditPassword(string $toEmail, string $toName, string $fileName, string $password, ?string $sentTo): bool
{
    $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $safeFile = htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8');
    $what = $sentTo === null
        ? "You downloaded <strong>$safeFile</strong> from ProfilePath."
        : "You sent <strong>$safeFile</strong> from ProfilePath to " . htmlspecialchars($sentTo, ENT_QUOTES, 'UTF-8') . '.';
    $html = EmailTemplate::renderCode(
        'Edit password for your report',
        "<p style=\"margin:0 0 12px 0;\">Hi $safeName,</p>"
            . "<p style=\"margin:0 0 12px 0;\">$what The workbook is protected so it can't be changed by accident.</p>"
            . '<p style="margin:0;">You only need the password below if you want to edit it: in Excel choose Review, then Unprotect Sheet, and enter it. Keep it private. Anyone who has the file can read it but not change it unless they have this password.</p>',
        $password,
        'A new password is made for every report.'
    );
    $text = "Hi $toName,\n\n" . strip_tags($what) . " The workbook is protected so it can't be changed by accident.\n\nEdit password: $password\n\n"
        . 'In Excel choose Review, then Unprotect Sheet, and enter it. Keep it private. A new password is made for every report.';
    return Mailer::send($toEmail, $toName, 'Edit password for your ProfilePath report', $html, $text);
}

// ---- GET ?format=xlsx: Download Report (Excel)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Every download sends an email: keep one person from using it to flood an inbox.
    if (RateLimiter::tooMany('analytics-xlsx:' . (int) $user['id'], 10, 60)) {
        jsonResponse(['success' => false, 'error' => 'Too many downloads in the last hour. Please try again later.'], 429);
    }
    $password = AnalyticsXlsx::generatePassword();
    $xlsx = AnalyticsXlsx::buildSummaryReport($report, $password, $myEmail);
    $sent = emailEditPassword($myEmail, $myName, $fileName, $password, null);
    if (!$sent && envValue('APP_ENV') !== 'local') {
        // Never hand over a locked file whose password did not get sent.
        AuditLogger::log($user['id'], $user['role'], 'export_analytics_xlsx_failed', 'analytics', null, 'Edit password email could not be sent');
        jsonResponse(['success' => false, 'error' => 'The edit password could not be emailed, so the report was not downloaded. Please try again in a moment.'], 502);
    }
    AuditLogger::log($user['id'], $user['role'], 'export_analytics_xlsx', 'analytics', null, "$fileName ({$report['scope']})" . ($sent ? ', edit password emailed' : ', local: password not emailed'));
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($xlsx));
    header('X-Password-Emailed-To: ' . $myEmail);
    if (!$sent) {
        // Local development has no email service: show the password so the flow can still be tested.
        header('X-Edit-Password: ' . $password);
    }
    echo $xlsx;
    exit;
}

// ---- POST: Send Analytics Report
$email = trim((string) ($input['email'] ?? ''));
if ($email === '') {
    jsonResponse(['success' => false, 'field' => 'email', 'error' => 'Enter the recipient\'s email address.'], 400);
}
if (mb_strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'field' => 'email', 'error' => 'Enter a valid email address, like name@school.edu.ph.'], 400);
}

// A second click, or sending the same report to the same person again within a minute, is almost always a mistake.
// Only a send that went through counts (hit() below), so a failed attempt can be retried straight away.
$sentKey = 'analytics-report-to:' . (int) $user['id'] . ':' . strtolower($email);
if (RateLimiter::isLimited($sentKey, 1, 1)) {
    jsonResponse(['success' => false, 'error' => 'This report was just sent to ' . $email . '. Wait a minute before sending it again.'], 429);
}
// Each send is an email from the school's account: keep it from being used to flood inboxes.
if (RateLimiter::tooMany('analytics-report:' . (int) $user['id'], 10, 60)) {
    jsonResponse(['success' => false, 'error' => 'Too many reports sent in the last hour. Please try again later.'], 429);
}

$password = AnalyticsXlsx::generatePassword();
try {
    $xlsx = AnalyticsXlsx::buildSummaryReport($report, $password, $myEmail);
} catch (Throwable $e) {
    error_log('[analytics-report] Excel build failed: ' . $e->getMessage());
    AuditLogger::log($user['id'], $user['role'], 'send_analytics_report_failed', 'analytics', null, "To $email: the Excel file could not be made");
    jsonResponse(['success' => false, 'error' => 'The Excel report could not be made, so nothing was sent. Please try again.'], 500);
}

$html = '<p style="margin:0 0 12px 0;">Good day,</p>'
    . '<p style="margin:0 0 12px 0;">Please find attached the latest Analytics Summary Report from the ProfilePath Career Profiling System. '
    . 'The report provides an overview of student assessment participation and the career interest and career-related trends available in the system.</p>'
    . '<p style="margin:0;">Thank you.</p>';
$text = "Good day,\n\nPlease find attached the latest Analytics Summary Report from the ProfilePath Career Profiling System. "
    . "The report provides an overview of student assessment participation and the career interest and career-related trends available in the system.\n\nThank you.";

$sent = Mailer::send($email, $email, 'ProfilePath Analytics Summary Report', $html, $text, [['name' => $fileName, 'content' => $xlsx]]);
if (!$sent) {
    AuditLogger::log($user['id'], $user['role'], 'send_analytics_report_failed', 'analytics', null, "To $email: the email service did not accept the message");
    jsonResponse(['success' => false, 'error' => 'The email service did not accept the report, so it was not sent. Check the email settings or try again in a moment.'], 502);
}
RateLimiter::hit($sentKey);

// The report has gone; now its edit password goes to the sender. If that email fails the recipient can still read the
// report, only nobody can edit it, so this is reported but does not undo the send.
$passwordSent = emailEditPassword($myEmail, $myName, $fileName, $password, $email);
AuditLogger::log($user['id'], $user['role'], 'send_analytics_report', 'analytics', null,
    "Analytics Summary Report ($fileName, {$report['scope']}) sent to $email" . ($passwordSent ? '; edit password emailed to the sender' : '; edit password email failed'));
$message = 'The Analytics Summary Report was sent to ' . $email . '. '
    . ($passwordSent
        ? 'The file is protected against editing; its edit password was emailed to you (' . $myEmail . ').'
        : 'The edit password could not be emailed to you, so nobody can edit this copy. Send it again if you need an editable copy.');
jsonResponse(['success' => true, 'email' => $email, 'fileName' => $fileName, 'passwordEmailed' => $passwordSent, 'message' => $message]);
