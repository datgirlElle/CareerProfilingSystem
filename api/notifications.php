<?php

require_once __DIR__ . '/_bootstrap.php';

$user = Auth::requireLogin();
$pdo = Database::get();

// ?full=1 (used by the "view all notifications" pages) widens the lookback
// window and per-category/overall caps that the header dropdown otherwise
// keeps small.
$full = isset($_GET['full']);
$days = $full ? 90 : 14;
$each = $full ? 50 : 5;
$total = $full ? 50 : 8;

$items = [];
$isStaff = $user['role'] === 'admin' || $user['role'] === 'counselor';

if ($isStaff) {
    // Recently registered students
    $regDays = $full ? 90 : 7;
    $stmt = $pdo->query(
        "SELECT s.user_id, s.school_id, s.first_name_enc, s.last_name_enc, s.registered_at
         FROM students s
         WHERE s.registered_at > NOW() - INTERVAL '$regDays days'
         ORDER BY s.registered_at DESC LIMIT $each"
    );
    foreach ($stmt->fetchAll() as $row) {
        $name = Crypto::dec($row['first_name_enc']) . ' ' . Crypto::dec($row['last_name_enc']);
        $items[] = [
            'type' => 'registration',
            'title' => 'New student registered',
            'text' => "$name just signed up.",
            // student-profile.html (and api/students.php's single-student
            // lookup) reads ?schoolId=, never ?id= — this previously
            // linked with the wrong param name, so clicking it always
            // landed on "Student not found."
            'link' => 'student-profile?schoolId=' . urlencode($row['school_id']),
            'ts' => $row['registered_at'],
        ];
    }

    // Pending monitoring flags awaiting review
    $stmt = $pdo->query(
        "SELECT mf.id, mf.reason, mf.created_at, s.first_name_enc, s.last_name_enc
         FROM monitoring_flags mf
         JOIN students s ON s.user_id = mf.student_id
         WHERE mf.status = 'pending'
         ORDER BY mf.created_at DESC LIMIT $each"
    );
    foreach ($stmt->fetchAll() as $row) {
        $name = Crypto::dec($row['first_name_enc']) . ' ' . Crypto::dec($row['last_name_enc']);
        $reasonLabel = $row['reason'] === 'low_confidence' ? 'low RIASEC confidence' : $row['reason'];
        $items[] = [
            'type' => 'flag',
            'title' => 'Assessment flagged',
            'text' => "$name ($reasonLabel).",
            'link' => 'monitoring',
            'ts' => $row['created_at'],
        ];
    }

    // Open counseling requests awaiting a response
    $stmt = $pdo->query(
        "SELECT id, name_enc, subject_enc, sent_at FROM help_requests
         WHERE status = 'open' ORDER BY sent_at DESC LIMIT $each"
    );
    foreach ($stmt->fetchAll() as $row) {
        $who = Crypto::dec($row['name_enc']) ?: 'A student';
        $items[] = [
            'type' => 'help_request',
            'title' => 'New counseling request',
            'text' => "$who: " . (Crypto::dec($row['subject_enc']) ?: 'No subject'),
            'link' => 'help-requests',
            'ts' => $row['sent_at'],
        ];
    }
} else {
    // A brand-new account starts with an empty inbox: only things that
    // happened after the student registered are ever shown.
    $sinceStmt = $pdo->prepare('SELECT registered_at FROM students WHERE user_id = ?');
    $sinceStmt->execute([$user['id']]);
    $since = (string) ($sinceStmt->fetchColumn() ?: '1970-01-01');

    // Student: recent resolutions of things they submitted
    $stmt = $pdo->prepare(
        "SELECT id, subject_enc, resolved_at FROM help_requests
         WHERE student_id = ? AND status = 'resolved' AND resolved_at > NOW() - INTERVAL '$days days'
           AND resolved_at >= ?
         ORDER BY resolved_at DESC LIMIT $each"
    );
    $stmt->execute([$user['id'], $since]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'key' => 'help:' . $row['id'],
            'type' => 'help_resolved',
            'title' => 'Counseling request resolved',
            'text' => 'Your counseling request "' . (Crypto::dec($row['subject_enc']) ?: 'General inquiry') . '" has been resolved.',
            'link' => 'help-center',
            'ts' => $row['resolved_at'],
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT id, status, resolved_at FROM monitoring_flags
         WHERE student_id = ? AND status IN ('approved', 'escalated') AND resolved_at > NOW() - INTERVAL '$days days'
           AND resolved_at >= ?
         ORDER BY resolved_at DESC LIMIT $each"
    );
    $stmt->execute([$user['id'], $since]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'key' => 'flag:' . $row['id'],
            'type' => 'flag_resolved',
            'title' => 'Assessment reviewed',
            'text' => 'A counselor reviewed your assessment.',
            'link' => 'results',
            'ts' => $row['resolved_at'],
        ];
    }

    // Announcements sent to everyone or to this student, published after
    // they registered.
    $stmt = $pdo->prepare(
        "SELECT a.id, a.title, a.body_enc, a.publish_at FROM announcements a
         WHERE a.publish_at <= NOW() AND a.publish_at > NOW() - INTERVAL '$days days'
           AND a.publish_at >= ?
           AND (a.target_type = 'all' OR EXISTS (
                 SELECT 1 FROM announcement_recipients ar
                 WHERE ar.announcement_id = a.id AND ar.student_id = ?
               ))
         ORDER BY a.publish_at DESC LIMIT $each"
    );
    $stmt->execute([$since, $user['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $body = trim((string) Crypto::dec($row['body_enc']));
        $items[] = [
            'key' => 'ann:' . $row['id'],
            'type' => 'announcement',
            'title' => $row['title'],
            'text' => mb_strimwidth($body, 0, 140, '…'),
            'link' => 'assessment',
            'ts' => $row['publish_at'],
        ];
    }

    // Newly created exam schedules matching this student's own
    // strand/section/grade level/AY (same matching rule as
    // api/exam-schedules.php?mine=1). NULL on any of those three columns
    // means the schedule applies to everyone in that dimension.
    $stmt = $pdo->prepare(
        "SELECT es.id, es.exam_date, es.room, es.created_at FROM exam_schedules es
         JOIN students s ON s.user_id = ?
         WHERE es.created_at > NOW() - INTERVAL '$days days'
           AND es.created_at >= ?
           AND es.academic_year = s.academic_year
           AND (es.grade_level IS NULL OR es.grade_level = s.grade_level)
           AND (es.strand IS NULL OR es.strand = s.strand)
           AND (es.section IS NULL OR es.section = s.section)
         ORDER BY es.created_at DESC LIMIT $each"
    );
    $stmt->execute([$user['id'], $since]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'key' => 'exam:' . $row['id'],
            'type' => 'schedule_published',
            'title' => 'Exam scheduled',
            'text' => $row['exam_date'] . ' in ' . $row['room'] . '.',
            'link' => 'assessment',
            'ts' => $row['created_at'],
        ];
    }

    // Staff-granted retakes (see retake_grants / api/retake-grants.php).
    $stmt = $pdo->prepare(
        "SELECT id, granted_at FROM retake_grants
         WHERE student_id = ? AND status = 'granted' AND completed_attempt_number IS NULL
           AND granted_at > NOW() - INTERVAL '$days days'
           AND granted_at >= ?
         ORDER BY granted_at DESC LIMIT $each"
    );
    $stmt->execute([$user['id'], $since]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'key' => 'retake:' . $row['id'],
            'type' => 'retake_granted',
            'title' => 'Retake granted',
            'text' => 'You have been granted a retake of the RIASEC assessment.',
            'link' => 'assessment',
            'ts' => $row['granted_at'],
        ];
    }

    // Anything the student deleted stays gone. If the table isn't there yet
    // (migration not run), nothing is hidden rather than the feed failing.
    try {
        $dismissedStmt = $pdo->prepare('SELECT item_key FROM notification_dismissals WHERE user_id = ?');
        $dismissedStmt->execute([$user['id']]);
        $dismissed = array_flip($dismissedStmt->fetchAll(PDO::FETCH_COLUMN));
        $items = array_values(array_filter($items, fn($i) => !isset($dismissed[$i['key']])));
    } catch (Throwable $e) {
    }
}

usort($items, fn($a, $b) => strcmp($b['ts'], $a['ts']));
$items = array_slice($items, 0, $total);

// Read state. Notifications here are computed live rather than stored, so
// staff read state is a single per-user "read up to" timestamp
// (users.notifications_read_at): anything at or before it counts as read,
// anything newer as unread. Students keep the original behaviour (every
// item shown counts toward the badge). If the column isn't there yet
// (migration not run) everything simply counts as unread instead of the
// whole feed failing to load.
$readAt = null;
if ($isStaff) {
    try {
        $stmt = $pdo->prepare('SELECT notifications_read_at FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $value = $stmt->fetchColumn();
        $readAt = $value ? strtotime($value) : null;
    } catch (Throwable $e) {
        $readAt = null;
    }
}
$unreadCount = 0;
foreach ($items as &$item) {
    $item['unread'] = $readAt === null || strtotime($item['ts']) > $readAt;
    if ($item['unread']) {
        $unreadCount++;
    }
}
unset($item);

jsonResponse(['items' => $items, 'count' => count($items), 'unreadCount' => $unreadCount, 'tracksRead' => $isStaff]);
