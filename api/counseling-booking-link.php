<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';
require_once __DIR__ . '/../lib/RateLimiter.php';
require_once __DIR__ . '/../lib/BookingLink.php';

// "Avail Counseling" on the Student Help Center: emails the signed-in student the CGC's counseling booking link, to the
// email address already on their account (never one typed in the request). The link is the one the guidance staff saved
// on the Staff Help Center (security_policies booking.url, lib/BookingLink.php), so it changes without touching code.
// Sending the link books nothing: the student books on the CGC page, and no request, invite or counseling status is
// recorded here. Success is reported only when the email service accepted the message.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}
$user = Auth::requireLogin();
if ($user['role'] !== 'student') {
    jsonResponse(['success' => false, 'error' => 'Only students can request the counseling booking link.'], 403);
}
$pdo = Database::get();

const FAILED_MESSAGE = 'We couldn\'t send your counseling booking link. Please try again later or contact the Guidance and Counseling Center.';

$setting = $pdo->prepare('SELECT value FROM security_policies WHERE key = ?');
$setting->execute([BookingLink::KEY]);
$bookingUrl = BookingLink::validate((string) ($setting->fetchColumn() ?: ''))['url'];
if ($bookingUrl === '') {
    jsonResponse(['success' => false, 'error' => 'Online counseling booking is not available yet. Please contact the Guidance and Counseling Center, or send them a message below.'], 409);
}

$stmt = $pdo->prepare(
    'SELECT s.first_name_enc, u.email, u.email_verified_at, u.is_active
     FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?'
);
$stmt->execute([(int) $user['id']]);
$student = $stmt->fetch();
$email = trim((string) ($student['email'] ?? ''));
if (!$student || !$student['is_active']) {
    jsonResponse(['success' => false, 'error' => FAILED_MESSAGE], 409);
}
if ($email === '' || $student['email_verified_at'] === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'error' => 'Your account has no verified email address, so the link could not be sent. Please contact the Guidance and Counseling Center.'], 409);
}

// A second click soon after a send is almost always a repeat: one sent link per 5 minutes (only sends that went through
// count, so a failed attempt can be retried at once), and at most 5 tries a day so the button cannot flood an inbox.
$sentKey = 'counseling-link:' . (int) $user['id'];
if (RateLimiter::isLimited($sentKey, 1, 5)) {
    jsonResponse(['success' => false, 'repeat' => true, 'error' => 'Your booking link was sent a few minutes ago. Please check your inbox, including the spam or junk folder.'], 429);
}
if (RateLimiter::tooMany('counseling-link-try:' . (int) $user['id'], 5, 1440)) {
    jsonResponse(['success' => false, 'error' => 'You have asked for the booking link several times today. Please check your inbox, or contact the Guidance and Counseling Center.'], 429);
}

$first = trim((string) Crypto::dec($student['first_name_enc']));
$greeting = $first !== '' ? "Hi $first," : 'Hi,';
$safeGreeting = htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8');
$safeUrl = htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8');
$html = EmailTemplate::render(
    'Your CGC counseling booking link',
    "<p style=\"margin:0 0 12px 0;\">$safeGreeting</p>"
        . '<p style="margin:0 0 12px 0;">Thank you for reaching out. We are glad you want to talk with a counselor.</p>'
        . '<p style="margin:0 0 12px 0;">Click the button below to open the CGC counseling booking page and choose a schedule that works for you. Your session is booked once you complete the booking on that page.</p>'
        . "<p style=\"margin:0;font-size:12px;color:#64748b;\">If the button does not work, copy this link into your browser:<br><span style=\"word-break:break-all;\">$safeUrl</span></p>",
    'Book a counseling session',
    $safeUrl,
    '',
    'Center for Guidance and Counseling (CGC), Mapúa Malayan Colleges Laguna. You received this because you clicked Avail Counseling in ProfilePath.'
);
$text = "$greeting\n\nThank you for reaching out. We are glad you want to talk with a counselor.\n\n"
    . "Open this link to go to the CGC counseling booking page and choose a schedule that works for you. Your session is booked once you complete the booking on that page:\n$bookingUrl\n\n"
    . "Center for Guidance and Counseling (CGC)\nMapúa Malayan Colleges Laguna";

$sent = Mailer::send($email, $first !== '' ? $first : $email, 'CGC Counseling Booking Link', $html, $text);
if (!$sent) {
    AuditLogger::log($user['id'], $user['role'], 'send_counseling_link_failed', 'student', (string) $user['id'], 'Booking link email not accepted by the email service');
    jsonResponse(['success' => false, 'error' => FAILED_MESSAGE], 502);
}
RateLimiter::hit($sentKey);
AuditLogger::log($user['id'], $user['role'], 'send_counseling_link', 'student', (string) $user['id'], 'Counseling booking link emailed to the student');
jsonResponse(['success' => true, 'message' => 'Your CGC counseling booking link has been sent to your registered email address. Please check your inbox.']);
