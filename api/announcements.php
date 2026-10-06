<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/EmailTemplate.php';

$user = Rbac::requireAccess('announcements', 'limited');
$pdo = Database::get();

const ANNOUNCEMENT_BODY_MAX = 2000;
const REMIND_COOLDOWN_HOURS = 24;

/**
 * Active students an announcement goes to, optionally only those who have not
 * seen it yet (used by "Remind Unread").
 *
 * @return array<int,array{email:string,first_name_enc:string}>
 */
function announcementRecipients(PDO $pdo, int $announcementId, string $targetType, bool $onlyUnread = false): array
{
    $unread = $onlyUnread
        ? ' AND NOT EXISTS (SELECT 1 FROM announcement_reads r WHERE r.announcement_id = ' . (int) $announcementId . ' AND r.student_id = s.user_id)'
        : '';
    if ($targetType === 'all') {
        return $pdo->query(
            "SELECT u.email, s.first_name_enc FROM students s
             JOIN users u ON u.id = s.user_id
             WHERE u.is_active = TRUE AND u.email IS NOT NULL" . $unread
        )->fetchAll();
    }
    $stmt = $pdo->prepare(
        "SELECT u.email, s.first_name_enc FROM announcement_recipients ar
         JOIN students s ON s.user_id = ar.student_id
         JOIN users u ON u.id = s.user_id
         WHERE ar.announcement_id = ? AND u.is_active = TRUE AND u.email IS NOT NULL" . $unread
    );
    $stmt->execute([$announcementId]);
    return $stmt->fetchAll();
}

/** @param array<int,array{email:string,first_name_enc:string}> $recipients */
function sendAnnouncementEmails(array $recipients, string $title, string $bodyText, string $subjectPrefix = ''): void
{
    if (!$recipients) {
        return;
    }
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeBody = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));
    $appUrl = rtrim((string) getenv('APP_URL'), '/');

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
        Mailer::send($r['email'], $firstName, $subjectPrefix . $title, $bodyHtml, "Hi $firstName,\n\n$bodyText");
    }
}

/**
 * Emails an announcement to its recipients and marks it emailed, atomically
 * claiming the row first (`emailed_at IS NULL` in the WHERE) so two staff
 * members loading this page at the same moment — or the immediate-send path
 * racing the lazy scheduled-send check below — can never send it twice.
 */
function emailAnnouncement(PDO $pdo, int $announcementId, string $title, string $bodyText, string $targetType): void
{
    $claim = $pdo->prepare("UPDATE announcements SET emailed_at = NOW() WHERE id = ? AND emailed_at IS NULL AND status = 'sent'");
    $claim->execute([$announcementId]);
    if ($claim->rowCount() === 0) {
        return; // already sent (or sent by a concurrent request), or still a draft
    }
    sendAnnouncementEmails(announcementRecipients($pdo, $announcementId, $targetType), $title, $bodyText);
}

/**
 * Validates the compose form. Sends the JSON error itself and exits when
 * something is wrong; a draft is allowed to be less complete (no audience yet).
 *
 * @return array{title:string,bodyText:string,targetType:string,publishAt:?string,studentIds:array<int,int>}
 */
function readAnnouncementInput(array $body, bool $isDraft): array
{
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
    if (mb_strlen($bodyText) > ANNOUNCEMENT_BODY_MAX) {
        jsonResponse(['success' => false, 'error' => 'Message must be ' . ANNOUNCEMENT_BODY_MAX . ' characters or fewer.'], 400);
    }
    if (!in_array($targetType, ['all', 'specific'], true)) {
        jsonResponse(['success' => false, 'error' => 'Invalid target type.'], 400);
    }
    $studentIds = $targetType === 'specific'
        ? array_values(array_unique(array_filter(array_map('intval', (array) $studentIds))))
        : [];
    if ($targetType === 'specific' && !$studentIds && !$isDraft) {
        jsonResponse(['success' => false, 'error' => 'Select at least one student, or choose "Everyone".'], 400);
    }

    // publishAt is optional — an empty value means "publish immediately".
    // Accepts the value a datetime-local input sends (no timezone), which
    // PHP's strtotime() reads as server-local time, matching NOW() below.
    $publishAt = null;
    if ($publishAtRaw !== '') {
        $ts = strtotime($publishAtRaw);
        if ($ts === false) {
            jsonResponse(['success' => false, 'error' => 'Invalid publish date/time.'], 400);
        }
        // The picker's own "min" is client-side only — enforce the same rule
        // here (school time, see config/env.php's APP_TIMEZONE). A small grace
        // window absorbs normal request latency around the exact minute the
        // form was submitted.
        if ($ts < time() - 60) {
            jsonResponse(['success' => false, 'error' => 'Publish date/time can\'t be in the past.'], 400);
        }
        $publishAt = date('Y-m-d H:i:sP', $ts);
    }

    return ['title' => $title, 'bodyText' => $bodyText, 'targetType' => $targetType, 'publishAt' => $publishAt, 'studentIds' => $studentIds];
}

function saveRecipients(PDO $pdo, int $announcementId, string $targetType, array $studentIds): void
{
    $pdo->prepare('DELETE FROM announcement_recipients WHERE announcement_id = ?')->execute([$announcementId]);
    if ($targetType !== 'specific') {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO announcement_recipients (announcement_id, student_id) VALUES (?, ?)');
    foreach ($studentIds as $sid) {
        $stmt->execute([$announcementId, $sid]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($user['role'] === 'student') {
        // Only sent, published announcements that are either sent to everyone
        // or specifically target this student. Drafts are never visible.
        $stmt = $pdo->prepare(
            "SELECT a.id, a.title, a.body_enc, a.target_type, a.publish_at, a.created_at
             FROM announcements a
             WHERE a.status = 'sent' AND a.publish_at <= NOW()
               AND (a.target_type = 'all' OR EXISTS (
                     SELECT 1 FROM announcement_recipients ar
                     WHERE ar.announcement_id = a.id AND ar.student_id = ?
                   ))
             ORDER BY a.publish_at DESC"
        );
        $stmt->execute([(int) $user['id']]);
        $announcements = array_map(fn($r) => [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'body' => Crypto::dec($r['body_enc']),
            'targetType' => $r['target_type'],
            'publishAt' => $r['publish_at'],
            'createdAt' => $r['created_at'],
        ], $stmt->fetchAll());
        jsonResponse(['announcements' => $announcements]);
    }

    // A scheduled announcement (publish_at in the future when created)
    // has no background job to email it the moment it comes due — the
    // closest this project has to a scheduler is: check for it here,
    // the one place staff are looking at announcements, whenever this
    // page loads.
    $dueUnemailed = $pdo->query(
        "SELECT id, title, body_enc, target_type FROM announcements
         WHERE status = 'sent' AND publish_at <= NOW() AND emailed_at IS NULL"
    )->fetchAll();
    foreach ($dueUnemailed as $a) {
        emailAnnouncement($pdo, (int) $a['id'], $a['title'], Crypto::dec($a['body_enc']), $a['target_type']);
    }

    $activeStudents = (int) $pdo->query(
        'SELECT COUNT(*) FROM students s JOIN users u ON u.id = s.user_id WHERE u.is_active = TRUE'
    )->fetchColumn();

    // Staff management view: everything, including drafts and scheduled ones.
    $rows = $pdo->query(
        "SELECT a.id, a.title, a.body_enc, a.target_type, a.status, a.publish_at, a.created_at, a.emailed_at, a.last_reminded_at,
                u.username AS created_by_username,
                (SELECT COUNT(*) FROM announcement_recipients ar WHERE ar.announcement_id = a.id) AS recipient_count,
                (SELECT COUNT(*) FROM announcement_reads r
                   JOIN users ru ON ru.id = r.student_id AND ru.is_active = TRUE
                  WHERE r.announcement_id = a.id) AS read_count
         FROM announcements a
         LEFT JOIN users u ON u.id = a.created_by
         ORDER BY a.publish_at DESC"
    )->fetchAll();

    $recipientIds = [];
    foreach ($pdo->query('SELECT announcement_id, student_id FROM announcement_recipients')->fetchAll() as $r) {
        $recipientIds[(int) $r['announcement_id']][] = (int) $r['student_id'];
    }

    $now = time();
    $summary = ['published' => 0, 'scheduled' => 0, 'drafts' => 0, 'activeStudents' => $activeStudents];
    $reachedAll = false;
    $reachedIds = [];
    $readPercents = [];
    $nextScheduled = null;

    $announcements = array_map(function ($r) use ($activeStudents, $recipientIds, $now, &$summary, &$reachedAll, &$reachedIds, &$readPercents, &$nextScheduled) {
        $id = (int) $r['id'];
        $isDraft = $r['status'] === 'draft';
        $isPublished = !$isDraft && strtotime($r['publish_at']) <= $now;
        $state = $isDraft ? 'draft' : ($isPublished ? 'published' : 'scheduled');
        $audience = $r['target_type'] === 'all' ? $activeStudents : (int) $r['recipient_count'];
        $read = min((int) $r['read_count'], $audience);
        $readPercent = $audience > 0 ? (int) round($read / $audience * 100) : 0;

        if ($state === 'draft') {
            $summary['drafts']++;
        } elseif ($state === 'scheduled') {
            $summary['scheduled']++;
            if ($nextScheduled === null || $r['publish_at'] < $nextScheduled) {
                $nextScheduled = $r['publish_at'];
            }
        } else {
            $summary['published']++;
            if ($r['target_type'] === 'all') {
                $reachedAll = true;
            } else {
                foreach ($recipientIds[$id] ?? [] as $sid) { $reachedIds[$sid] = true; }
            }
            if ($audience > 0) {
                $readPercents[] = $readPercent;
            }
        }

        return [
            'id' => $id,
            'title' => $r['title'],
            'body' => Crypto::dec($r['body_enc']),
            'targetType' => $r['target_type'],
            'state' => $state,
            'publishAt' => $r['publish_at'],
            'createdAt' => $r['created_at'],
            'isPublished' => $isPublished,
            'createdByUsername' => $r['created_by_username'] ?? null,
            'audienceCount' => $audience,
            'recipientIds' => $r['target_type'] === 'specific' ? ($recipientIds[$id] ?? []) : [],
            'readCount' => $read,
            'readPercent' => $readPercent,
            // Once it has been emailed, students have already received that text.
            'editable' => $r['emailed_at'] === null,
            'lastRemindedAt' => $r['last_reminded_at'],
        ];
    }, $rows);

    $summary['reached'] = $reachedAll ? $activeStudents : count($reachedIds);
    $summary['avgReadPercent'] = $readPercents ? (int) round(array_sum($readPercents) / count($readPercents)) : null;
    $summary['nextScheduledAt'] = $nextScheduled;

    jsonResponse(['announcements' => $announcements, 'summary' => $summary]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    $type = $body['type'] ?? '';

    // A student's page reports which announcements it just showed them.
    // Only ones actually visible to that student can be recorded.
    if ($type === 'markRead') {
        if ($user['role'] !== 'student') {
            jsonResponse(['success' => true]);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($body['ids'] ?? [])))));
        if ($ids) {
            $stmt = $pdo->prepare(
                "INSERT INTO announcement_reads (announcement_id, student_id)
                 SELECT a.id, ? FROM announcements a
                 WHERE a.id = ? AND a.status = 'sent' AND a.publish_at <= NOW()
                   AND (a.target_type = 'all' OR EXISTS (
                         SELECT 1 FROM announcement_recipients ar WHERE ar.announcement_id = a.id AND ar.student_id = ?))
                 ON CONFLICT DO NOTHING"
            );
            foreach (array_slice($ids, 0, 50) as $aid) {
                $stmt->execute([(int) $user['id'], $aid, (int) $user['id']]);
            }
        }
        jsonResponse(['success' => true]);
    }

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
        $isDraft = !empty($body['draft']);
        $in = readAnnouncementInput($body, $isDraft);

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO announcements (title, body_enc, created_by, target_type, status, publish_at)
                 VALUES (?, ?, ?, ?, ?, COALESCE(?, NOW())) RETURNING id'
            );
            $insert->execute([$in['title'], Crypto::enc($in['bodyText']), $user['id'], $in['targetType'], $isDraft ? 'draft' : 'sent', $in['publishAt']]);
            $announcementId = (int) $insert->fetchColumn();
            saveRecipients($pdo, $announcementId, $in['targetType'], $in['studentIds']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[announcements] create failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to save the announcement. Please try again.'], 500);
        }

        AuditLogger::log(
            $user['id'], $user['role'], $isDraft ? 'draft_announcement' : 'create_announcement', 'announcement', (string) $announcementId,
            "\"{$in['title']}\" -> " . ($in['targetType'] === 'all' ? 'everyone' : count($in['studentIds']) . ' student(s)')
        );

        // Only an announcement published right now emails immediately; a
        // future publishAt is picked up by the lazy check in the GET branch
        // above once it's actually due. A draft is never emailed.
        if (!$isDraft && ($in['publishAt'] === null || strtotime($in['publishAt']) <= time())) {
            emailAnnouncement($pdo, $announcementId, $in['title'], $in['bodyText'], $in['targetType']);
        }

        jsonResponse(['success' => true, 'id' => $announcementId, 'draft' => $isDraft]);
    }

    // Edit a draft or a scheduled announcement that hasn't been emailed yet.
    // Sending a draft is just an edit with draft = false.
    if ($type === 'update') {
        Rbac::requireAccess('announcements', 'full');
        $id = (int) ($body['id'] ?? 0);
        $existing = $pdo->prepare('SELECT id, emailed_at, status FROM announcements WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) {
            jsonResponse(['success' => false, 'error' => 'Announcement not found.'], 404);
        }
        if ($row['emailed_at'] !== null) {
            jsonResponse(['success' => false, 'error' => 'This announcement has already been emailed to students, so it can no longer be edited. Delete it and send a new one instead.'], 409);
        }
        $isDraft = !empty($body['draft']);
        $in = readAnnouncementInput($body, $isDraft);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE announcements SET title = ?, body_enc = ?, target_type = ?, status = ?,
                        publish_at = COALESCE(?, NOW()), updated_at = NOW()
                 WHERE id = ? AND emailed_at IS NULL"
            )->execute([$in['title'], Crypto::enc($in['bodyText']), $in['targetType'], $isDraft ? 'draft' : 'sent', $in['publishAt'], $id]);
            saveRecipients($pdo, $id, $in['targetType'], $in['studentIds']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[announcements] update failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to save your changes. Please try again.'], 500);
        }

        AuditLogger::log($user['id'], $user['role'], 'update_announcement', 'announcement', (string) $id, "\"{$in['title']}\"" . ($isDraft ? ' (draft)' : ''));

        if (!$isDraft && ($in['publishAt'] === null || strtotime($in['publishAt']) <= time())) {
            emailAnnouncement($pdo, $id, $in['title'], $in['bodyText'], $in['targetType']);
        }
        jsonResponse(['success' => true, 'id' => $id, 'draft' => $isDraft]);
    }

    // Email a reminder to the students who haven't seen a published
    // announcement yet. At most once per 24 hours per announcement.
    if ($type === 'remind') {
        Rbac::requireAccess('announcements', 'full');
        $id = (int) ($body['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, title, body_enc, target_type FROM announcements WHERE id = ? AND status = 'sent' AND publish_at <= NOW()");
        $stmt->execute([$id]);
        $a = $stmt->fetch();
        if (!$a) {
            jsonResponse(['success' => false, 'error' => 'Only a published announcement can have a reminder sent.'], 404);
        }
        $claim = $pdo->prepare(
            "UPDATE announcements SET last_reminded_at = NOW()
             WHERE id = ? AND (last_reminded_at IS NULL OR last_reminded_at < NOW() - INTERVAL '" . REMIND_COOLDOWN_HOURS . " hours')"
        );
        $claim->execute([$id]);
        if ($claim->rowCount() === 0) {
            jsonResponse(['success' => false, 'error' => 'A reminder was already sent in the last ' . REMIND_COOLDOWN_HOURS . ' hours.'], 429);
        }
        $recipients = announcementRecipients($pdo, $id, $a['target_type'], true);
        sendAnnouncementEmails($recipients, $a['title'], Crypto::dec($a['body_enc']), 'Reminder: ');
        AuditLogger::log($user['id'], $user['role'], 'remind_announcement', 'announcement', (string) $id, count($recipients) . ' unread student(s)');
        jsonResponse(['success' => true, 'sent' => count($recipients)]);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
