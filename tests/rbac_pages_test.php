<?php

// The permission table by feature and page is written down in four places that must agree: the features and their pages
// (modules/Authentication/lib/Rbac.php), the sidebar (js/admin-nav.js), the table on Security Configuration
// (security-configuration.html) and the names written to the audit log (security-config.php). No database needed.
//   php tests\rbac_pages_test.php

require_once __DIR__ . '/../modules/Authentication/lib/Rbac.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    }
}

$root = __DIR__ . '/..';
$features = Rbac::modules();
$nav = file_get_contents("$root/js/admin-nav.js");
$page = file_get_contents("$root/modules/SecurityConfiguration/pages/security-configuration.html");
$api = file_get_contents("$root/modules/SecurityConfiguration/api/security-config.php");
$router = file_get_contents("$root/router.php");

echo "=== the features and their defaults ===\n";
check('21 features', count($features) === 21, (string) count($features));
$levelsOk = true;
foreach (Rbac::DEFAULTS as $module => $d) {
    $levelsOk = $levelsOk && in_array($d['counselor'] ?? '', Rbac::LEVELS, true) && in_array($d['facilitator'] ?? '', Rbac::LEVELS, true);
}
check('every default is full, limited or none for both staff columns', $levelsOk);
check('the older features are gone', !array_intersect(['career', 'rac', 'counselor', 'counselingNotes', 'sections'], $features));
check('a Student only ever holds Career recommendations', Rbac::STUDENT_MODULES === ['recommendations'] && in_array('recommendations', $features, true));
check('Security Configuration and Audit Log are View Only or No Access for staff by design',
    Rbac::VIEW_ONLY_FEATURES === ['settings', 'auditLog'] && Rbac::DEFAULTS['settings']['counselor'] !== 'full' && Rbac::DEFAULTS['auditLog']['facilitator'] !== 'full');

echo "\n=== pages ===\n";
$unknown = [];
foreach (Rbac::PAGES as $slug => $mods) {
    foreach ($mods as $m) {
        if (!in_array($m, $features, true)) $unknown[] = "$slug:$m";
    }
}
check('every page lists only real features', !$unknown, implode(', ', $unknown));
preg_match_all("/\\{ id: '([a-z-]+)', href:/", $nav, $m);
$sidebar = $m[1];
check('the first twelve pages are the sidebar, in its order', array_slice(array_keys(Rbac::PAGES), 0, 12) === $sidebar, implode(',', $sidebar));
preg_match("/const PROTECTED_PAGES = \\[(.*?)\\];/s", $router, $pm);
$staffPages = [];
if (preg_match_all("/'([a-z-]+)' => \\['admin', 'counselor'\\]/", $pm[1] ?? '', $sm)) {
    $staffPages = $sm[1];
}
$notProtected = array_diff(array_keys(Rbac::PAGES), $staffPages);
check('every page in the table is a protected staff page in router.php', !$notProtected, implode(', ', $notProtected));
check('the fallback page is a staff page that is always open', in_array(Rbac::FALLBACK_PAGE, $staffPages, true) && !isset(Rbac::PAGES[Rbac::FALLBACK_PAGE]));

echo "\n=== the table on Security Configuration ===\n";
preg_match("/const FEATURES = \\{(.*?)\\n    \\};/s", $page, $fm);
preg_match_all("/^\\s+([a-zA-Z]+): \\{ label:/m", $fm[1] ?? '', $fk);
check('the page shows exactly the 21 features', $fk[1] === $features, implode(',', $fk[1]));
preg_match("/const PAGE_FEATURES = \\{(.*?)\\n    \\};/s", $page, $gm);
preg_match_all("/'([a-z-]+)': \\[([^\\]]*)\\]/", $gm[1] ?? '', $gk, PREG_SET_ORDER);
$shown = [];
$pagesInTable = [];
foreach ($gk as $g) {
    $pagesInTable[] = $g[1];
    preg_match_all("/'([a-zA-Z]+)'/", $g[2], $ids);
    foreach ($ids[1] as $id) $shown[] = $id;
}
check('the table groups features under the twelve sidebar pages', $pagesInTable === $sidebar, implode(',', $pagesInTable));
sort($shown);
$sortedFeatures = $features;
sort($sortedFeatures);
check('each feature appears exactly once in the table', $shown === $sortedFeatures);
$opensWrong = [];
foreach (array_slice(Rbac::PAGES, 0, 12, true) as $slug => $mods) {
    foreach ($mods as $mod) {
        if (!in_array("'$mod'", explode(', ', preg_replace('/.*' . preg_quote("'$slug': [", '/') . '([^\]]*)\].*/s', '$1', $gm[1] ?? '')), true)) $opensWrong[] = "$slug:$mod";
    }
}
check('a feature that opens a page is shown under that page', !$opensWrong, implode(', ', $opensWrong));

echo "\n=== the audit log names every feature ===\n";
preg_match("/const RBAC_LABELS = \\[(.*?)\\];/s", $api, $lm);
preg_match_all("/'([a-zA-Z]+)' => '/", $lm[1] ?? '', $lk);
check('security-config.php has a label for each feature', $lk[1] === $features, implode(',', $lk[1]));

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
