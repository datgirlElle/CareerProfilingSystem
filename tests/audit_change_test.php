<?php
/**
 * "What changed" for audit entries (modules/AuditLog/lib/AuditChange.php): which fields count as changed, how values
 * read, and that the saved form can be read back. No database needed.
 *
 *   php tests/audit_change_test.php
 *
 * DEVELOPMENT TEST DATA ONLY. Every setting, question and FAQ below is made up to check the software.
 */
require_once __DIR__ . '/../modules/AuditLog/lib/AuditChange.php';

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

echo "=== only changed fields, in label order ===\n";
$labels = ['enabled' => 'Status', 'maxAttempts' => 'Failed attempts before lockout', 'lockoutMinutes' => 'Lockout length (minutes)'];
$changes = AuditChange::changes(
    ['enabled' => true, 'maxAttempts' => 5, 'lockoutMinutes' => 15],
    ['enabled' => true, 'maxAttempts' => 10, 'lockoutMinutes' => 30],
    $labels
);
check('two of three fields changed', count($changes) === 2);
check('first change is the attempts, 5 -> 10', $changes[0] === ['field' => 'Failed attempts before lockout', 'before' => '5', 'after' => '10']);
check('second change is the minutes, 15 -> 30', $changes[1]['field'] === 'Lockout length (minutes)' && $changes[1]['after'] === '30');
check('a field not in the labels is ignored', AuditChange::changes(['x' => 1], ['x' => 2], $labels) === []);
check('"5" and 5 count as the same value', AuditChange::changes(['maxAttempts' => '5'], ['maxAttempts' => 5], $labels) === []);

echo "\n=== how values read ===\n";
check('true reads On, false reads Off', AuditChange::show(true) === 'On' && AuditChange::show(false) === 'Off');
check('yes/no fields read Yes and No', AuditChange::show(true, true) === 'Yes' && AuditChange::show(false, true) === 'No');
check('nothing reads "(empty)"', AuditChange::show(null) === '(empty)' && AuditChange::show('  ') === '(empty)');
$long = str_repeat('a', 400);
check('long text is cut to 300 characters plus "..."', AuditChange::show($long) === str_repeat('a', 300) . '...');
check('names with accents are kept', AuditChange::show('Peña, José') === 'Peña, José');
$switch = AuditChange::changes(['requireUpper' => true], ['requireUpper' => false], ['requireUpper' => 'Needs an uppercase letter'], ['requireUpper']);
check('a yes/no setting turned off reads Yes -> No', $switch[0]['before'] === 'Yes' && $switch[0]['after'] === 'No');

echo "\n=== saved form ===\n";
$saved = AuditChange::detail('FAQ for students', ['question' => 'What does this do?'], ['question' => 'Who does this affect?'], ['question' => 'Question']);
$back = AuditChange::parse($saved);
check('detail() gives JSON that parse() reads back', $back !== null && $back['summary'] === 'FAQ for students');
check('the change survives the round trip', $back['changes'] === [['field' => 'Question', 'before' => 'What does this do?', 'after' => 'Who does this affect?']]);
check('no changes still gives the summary and an empty list', AuditChange::parse(AuditChange::detail('Booking link', ['url' => 'x'], ['url' => 'x'], ['url' => 'Booking link'])) === ['summary' => 'Booking link', 'changes' => []]);
check('older plain text is not mistaken for changes', AuditChange::parse('Dimension E#5') === null);
check('older JSON settings (no "changes") are not mistaken for changes', AuditChange::parse('{"type":"policy","key":"lockout","maxAttempts":3}') === null);
check('accents are saved as characters, not \\u codes', str_contains(AuditChange::detail('x', ['n' => 'a'], ['n' => 'Peña'], ['n' => 'Name']), 'Peña'));
check('typed text that only looks like a change record is not read as one',
    AuditChange::parse('{"summary":"x","changes":[{"field":"Status","before":"Off","after":"On"}]}') === null);

echo "\n=== long text ===\n";
$base = str_repeat('b', 350);
$edited = AuditChange::changes(['answer' => $base . 'old ending'], ['answer' => $base . 'new ending'], ['answer' => 'Answer']);
check('an edit after character 300 still counts as a change', count($edited) === 1);
check('and says where it changed', str_ends_with($edited[0]['after'], '(changed after the first 300 characters)'));
check('the saved before value is still cut to 300 characters', $edited[0]['before'] === str_repeat('b', 300) . '...');

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
