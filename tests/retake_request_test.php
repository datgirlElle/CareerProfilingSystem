<?php

require_once __DIR__ . '/../modules/CareerProfiling/lib/RetakeRequest.php';
require_once __DIR__ . '/../shared/lib/Database.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

echo "=== the reason a student gives ===\n";
check('too short is refused', RetakeRequest::cleanReason('too short')['error'] !== null);
check('empty is refused', RetakeRequest::cleanReason('   ')['error'] !== null);
check('a real reason is accepted', RetakeRequest::cleanReason('I was sick on the day and could not focus.')['error'] === null);
check('extra spaces and new lines are tidied', RetakeRequest::cleanReason("I  was   sick\n on the day")['text'] === 'I was sick on the day');
check('over the limit is refused', RetakeRequest::cleanReason(str_repeat('a', RetakeRequest::REASON_MAX + 1))['error'] !== null);
check('exactly the limit is accepted', RetakeRequest::cleanReason(str_repeat('a', RetakeRequest::REASON_MAX))['error'] === null);

echo "\n=== request states (rolled back, nothing is kept) ===\n";
$pdo = Database::get();
$sid = $pdo->query('SELECT user_id FROM students ORDER BY user_id LIMIT 1')->fetchColumn();
if ($sid === false) {
    echo "  SKIP: no student in the database\n";
} else {
    $sid = (int) $sid;
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM retake_requests WHERE student_id = ?')->execute([$sid]);
    check('no request: state is none', RetakeRequest::stateFor($pdo, $sid)['status'] === 'none');
    check('no request: nothing is approved', !RetakeRequest::isApproved($pdo, $sid) && RetakeRequest::openFor($pdo, $sid) === null);

    $ins = $pdo->prepare('INSERT INTO retake_requests (student_id, attempt_number, reason_enc) VALUES (?, 1, ?) RETURNING id');
    $ins->execute([$sid, Crypto::enc('reason')]);
    $id = (int) $ins->fetchColumn();
    check('a new request is pending', RetakeRequest::stateFor($pdo, $sid)['status'] === 'pending' && RetakeRequest::openFor($pdo, $sid) !== null);
    check('pending is not an approval', !RetakeRequest::isApproved($pdo, $sid));

    $failedTwice = false;
    $pdo->exec('SAVEPOINT second');
    try {
        $ins->execute([$sid, Crypto::enc('again')]);
    } catch (PDOException $e) {
        $failedTwice = true;
        $pdo->exec('ROLLBACK TO SAVEPOINT second');
    }
    check('a second open request is refused by the database', $failedTwice);

    $pdo->prepare("UPDATE retake_requests SET status = 'approved', decision_note_enc = ? WHERE id = ?")->execute([Crypto::enc('Good luck'), $id]);
    $state = RetakeRequest::stateFor($pdo, $sid);
    check('approved: the student may retake and sees the note', $state['status'] === 'approved' && $state['note'] === 'Good luck' && RetakeRequest::isApproved($pdo, $sid));

    $pdo->prepare("UPDATE retake_requests SET status = 'completed' WHERE id = ?")->execute([$id]);
    check('once used, the approval is gone', RetakeRequest::stateFor($pdo, $sid)['status'] === 'none' && !RetakeRequest::isApproved($pdo, $sid));

    $pdo->prepare("UPDATE retake_requests SET status = 'declined' WHERE id = ?")->execute([$id]);
    check('declined is shown to the student, who may ask again', RetakeRequest::stateFor($pdo, $sid)['status'] === 'declined' && RetakeRequest::openFor($pdo, $sid) === null);
    $pdo->rollBack();
}

echo "\n=== only the latest assessment's recommendation counts ===\n";
check('the rule is written against the latest assessment', strpos(RetakeRequest::LATEST_ONLY, 'is_latest = TRUE') !== false);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
