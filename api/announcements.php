<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';

$user = Rbac::requireAccess('announcements', 'limited');
$pdo = Database::get();

/**
 * Emails an announcement to its recipients and marks it emailed, atomically
 * claiming the row first (`emailed_at IS NULL` in the WHERE) so two staff
 * members loading this page at the same moment — or the immediate-send path
 * racing the lazy scheduled-send check below — can never send it twice.
 */
function emailAnnouncement(PDO $pdo, int $announcementId, string $title, string $bodyText, string $targetType): void
{
    $claim = $pdo->prepare('UPDATE announcements SET emailed_at = NOW() WHERE id = ? AND emailed_at IS NULL');
    $claim->execute([$announcementId]);
    if ($claim->rowCount() === 0) {
        return; // already sent (or sent by a concurrent request)
    }

    if ($targetType === 'all') {
        $recipients = $pdo->query(
            "SELECT u.email, s.first_name_enc FROM students s
             JOIN users u ON u.id = s.user_id
             WHERE u.is_active = TRUE AND u.email IS NOT NULL"
        )->fetchAll();
    } else {
        $stmt = $pdo->prepare(
            "SELECT u.email, s.first_name_enc FROM announcement_recipients ar
             JOIN students s ON s.user_id = ar.student_id
             JOIN users u ON u.id = s.user_id
             WHERE ar.announcement_id = ? AND u.is_active = TRUE AND u.email IS NOT NULL"
        );
        $stmt->execute([$announcementId]);
        $recipients = $stmt->fetchAll();
    }
    if (!$recipients) {
        return;
    }

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeBody = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));
    $appUrl = rtrim((string) envValue('APP_URL'), '/');

    // A school-wide announcement can mean sending to every registered
    // student, well past a single request's usual runtime — there's no
    // background job queue in this project, so this raises the limit for
    // just this request rather than risk it being killed mid-batch.
    set_time_limit(300);

    foreach ($recipients as $r) {
        $firstName = Crypto::dec($r['first_name_enc']);
        $bodyHtml = EmailTemplate::render(
            $safeTitle,
            "<p style=\"margin:0 0 12px 0;\">Hi $firstName,</p><p style=\"margin:0;\">$safeBody</p>",
            'View in ProfilePath',
            $appUrl !== '' ? $appUrl . '/assessment' : '#',
            'You are receiving this because you have a ProfilePath account.'
        );
        $bodyTextPlain = "Hi $firstName,\n\n$bodyText";
        Mailer::send($r['email'], $firstName, $title, $bodyHtml, $bodyTextPlain);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($user['role'] === 'student') {
        // Only published announcements that are either sent to everyone or
        // specifically target this student.
        $stmt = $pdo->prepare(
            "SELECT a.id, a.title, a.body_enc, a.target_type, a.publish_at, a.created_at
             FROM announcements a
             WHERE a.publish_at <= NOW()
               AND (a.target_type = 'all' OR EXISTS (
                     SELECT 1 FROM announcement_recipients ar
                     WHERE ar.announcement_id = a.id AND ar.student_id = ?
                   ))
             ORDER BY a.publish_at DESC"
        );
        $stmt->execute([(int) $user['id']]);
    } else {
        // A scheduled announcement (publish_at in the future when created)
        // has no background job to email it the moment it comes due — the
        // closest this project has to a scheduler is: check for it here,
        // the one place staff are looking at announcements, whenever this
        // page loads.
        $dueUnemailed = $pdo->query(
            "SELECT id, title, body_enc, target_type FROM announcements
             WHERE publish_at <= NOW() AND emailed_at IS NULL"
        )->fetchAll();
        foreach ($dueUnemailed as $a) {
            emailAnnouncement($pdo, (int) $a['id'], $a['title'], Crypto::dec($a['body_enc']), $a['target_type']);
        }

        // Admin/counselor management view: everything, including unpublished
        // (scheduled) announcements.
        $stmt = $pdo->query(
            "SELECT a.id, a.title, a.body_enc, a.target_type, a.publish_at, a.created_at,
                    u.username AS created_by_username,
                    (SELECT COUNT(*) FROM announcement_recipients ar WHERE ar.announcement_id = a.id) AS recipient_count
             FROM announcements a
             LEFT JOIN users u ON u.id = a.created_by
             ORDER BY a.publish_at DESC"
        );
    }

    $announcements = array_map(function ($r) {
        return [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'body' => Crypto::dec($r['body_enc']),
            'targetType' => $r['target_type'],
            'publishAt' => $r['publish_at'],
            'createdAt' => $r['created_at'],
            'isPublished' => strtotime($r['publish_at']) <= time(),
            'createdByUsername' => $r['created_by_username'] ?? null,
            'recipientCount' => isset($r['recipient_count']) ? (int) $r['recipient_count'] : null,
        ];
    }, $stmt->fetchAll());

    jsonResponse(['announcements' => $announcements]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    $type = $body['type'] ?? '';

    if ($type === 'delete') {
        Rbac::requireAccess('announcements', 'full');
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Missing id.'], 400);
        }
        $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
        AuditLogger::log($user['id'], $user['role'], 'delete_announcement', 'announcement', (string) $id, null);
        jsonResponse(['success' => true]);
    }

    if ($type === 'create') {
        Rbac::requireAccess('announcements', 'full');

        $title = trim((string) ($body['title'] ?? ''));
        $bodyText = trim((string) ($body['body'] ?? ''));
        $targetType = (string) ($body['targetType'] ?? 'all');
        $publishAtRaw = trim((string) ($body['publishAt'] ?? ''));
        $studentIds = $body['studentIds'] ?? [];

        if ($title === '' || $bodyText === '') {
            jsonResponse(['success' => false, 'error' => 'Title and message are required.'], 400);
        }
        if (mb_strlen($title) > 255) {
            jsonResponse(['success' => false, 'error' => 'Title must be 255 characters or fewer.'], 400);
        }
        if (!in_array($targetType, ['all', 'specific'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid target type.'], 400);
        }
        if ($targetType === 'specific') {
            $studentIds = array_values(array_unique(array_filter(array_map('intval', (array) $studentIds))));
            if (!$studentIds) {
                jsonResponse(['success' => false, 'error' => 'Select at least one student, or choose "Everyone".'], 400);
            }
        }

        // publishAt is optional — an empty value means "publish immediately".
        // Accepts the value a datetime-local input sends (no timezone), which
        // PHP's strtotime() reads as server-local time, matching NOW() below.
        if ($publishAtRaw === '') {
            $publishAt = null; // -> NOW() at insert time
        } else {
            $ts = strtotime($publishAtRaw);
            if ($ts === false) {
                jsonResponse(['success' => false, 'error' => 'Invalid publish date/time.'], 400);
            }
            // The picker's own "min" is client-side only — enforce the same
            // rule here (school time, see config/env.php's APP_TIMEZONE). A
            // small grace window absorbs normal request latency around the
            // exact minute the form was submitted, rather than rejecting a
            // value that was valid when the user picked it.
            if ($ts < time() - 60) {
                jsonResponse(['success' => false, 'error' => 'Publish date/time can\'t be in the past.'], 400);
            }
            $publishAt = date('Y-m-d H:i:sP', $ts);
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO announcements (title, body_enc, created_by, target_type, publish_at)
                 VALUES (?, ?, ?, ?, COALESCE(?, NOW())) RETURNING id'
            );
            $insert->execute([$title, Crypto::enc($bodyText), $user['id'], $targetType, $publishAt]);
            $announcementId = (int) $insert->fetchColumn();

            if ($targetType === 'specific') {
                $recipientStmt = $pdo->prepare(
                    'INSERT INTO announcement_recipients (announcement_id, student_id) VALUES (?, ?)'
                );
                foreach ($studentIds as $sid) {
                    $recipientStmt->execute([$announcementId, $sid]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Failed to save the announcement. Please try again.'], 500);
        }

        AuditLogger::log(
            $user['id'], $user['role'], 'create_announcement', 'announcement', (string) $announcementId,
            "\"$title\" -> " . ($targetType === 'all' ? 'everyone' : count($studentIds) . ' student(s)')
        );

        // Only an announcement published right now emails immediately; a
        // future publishAt is picked up by the lazy check in the GET branch
        // above once it's actually due.
        $isImmediate = $publishAt === null || strtotime($publishAt) <= time();
        if ($isImmediate) {
            emailAnnouncement($pdo, $announcementId, $title, $bodyText, $targetType);
        }

        jsonResponse(['success' => true, 'id' => $announcementId]);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
