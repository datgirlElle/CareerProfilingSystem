<?php

require_once __DIR__ . '/../lib/AnnouncementTemplate.php';

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

echo "=== tidying what the counselor types ===\n";
check('a name or title is one line: extra spaces and line breaks collapse', AnnouncementTemplate::clean("  Assessment \n  Notice  ", false) === 'Assessment Notice');
check('a message keeps its line breaks', AnnouncementTemplate::clean("1. First\n2. Second", true) === "1. First\n2. Second");
check('many blank lines shrink to one', AnnouncementTemplate::clean("A\n\n\n\nB", true) === "A\n\nB");
check('hidden control characters are dropped', AnnouncementTemplate::clean("Hi\x00 there\x07", false) === 'Hi there');
check('accents and Ñ are kept', AnnouncementTemplate::clean('Para los estudiantes de Peña', false) === 'Para los estudiantes de Peña');

echo "\n=== what is accepted ===\n";
check('a name, title and message are fine', AnnouncementTemplate::validate('Reminder', 'Guidance Reminder', 'Visit us.') === null);
check('anything left empty is refused', AnnouncementTemplate::validate('', 't', 'b') !== null && AnnouncementTemplate::validate('n', '', 'b') !== null && AnnouncementTemplate::validate('n', 't', '') !== null);
check('name limit is 80', AnnouncementTemplate::validate(str_repeat('n', 80), 't', 'b') === null && AnnouncementTemplate::validate(str_repeat('n', 81), 't', 'b') !== null);
check('title limit is 255, the same as an announcement', AnnouncementTemplate::validate('n', str_repeat('t', 255), 'b') === null && AnnouncementTemplate::validate('n', str_repeat('t', 256), 'b') !== null);
check('message limit is 2000, the same as an announcement', AnnouncementTemplate::validate('n', 't', str_repeat('b', 2000)) === null && AnnouncementTemplate::validate('n', 't', str_repeat('b', 2001)) !== null);

echo "\n=== the starting templates fit the rules ===\n";
$allFit = true;
foreach (require __DIR__ . '/../db/announcement_template_defaults.php' as [$name, $title, $body]) {
    if (AnnouncementTemplate::validate($name, $title, $body) !== null) { $allFit = false; }
}
check('all four starting templates are valid', $allFit);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
