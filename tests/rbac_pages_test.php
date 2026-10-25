<?php

// The permission table by feature and page is written down in four places that must agree: the features and their pages
// (modules/Authentication/lib/Rbac.php), the sidebar (js/admin-nav.js), the rows shown on Security Configuration and My Access
// (js/rbac-features.js) and the names written to the audit log (security-config.php). No database needed.
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
$page = file_get_contents("$root/js/rbac-features.js");
$api = file_get_contents("$root/modules/SecurityConfiguration/api/security-config.php");
$router = file_get_contents("$root/router.php");

echo "=== the features and their defaults ===\n";
check('19 features', count($features) === 19, (string) count($features));
$levelsOk = true;
foreach (Rbac::DEFAULTS as $module => $d) {
    $levelsOk = $levelsOk && in_array($d['counselor'] ?? '', Rbac::LEVELS, true) && in_array($d['facilitator'] ?? '', Rbac::LEVELS, true);
}
check('every default is full, limited or none for both staff columns', $levelsOk);
check('the older features are gone', !array_intersect(['career', 'rac', 'counselor', 'counselingNotes', 'sections'], $features));
check('a Student only ever holds Career recommendations', Rbac::STUDENT_MODULES === ['recommendations'] && in_array('recommendations', $features, true));
check('Security Configuration and the Audit Log are the administrator\'s own pages, not rows of the table',
    Rbac::ADMIN_PAGES === ['security-configuration', 'audit-log'] && !array_intersect(['settings', 'auditLog'], $features)
    && !array_intersect(Rbac::ADMIN_PAGES, array_keys(Rbac::PAGES)));

echo "\n=== pages ===\n";
$unknown = [];
foreach (Rbac::PAGES as $slug => $mods) {
    foreach ($mods as $m) {
        if (!in_array($m, $features, true)) $unknown[] = "$slug:$m";
    }
}
check('every page lists only real features', !$unknown, implode(', ', $unknown));
preg_match_all("/\\{ id: '([a-z-]+)', href: '[a-z-]+', label: '[^']*', icon: '[a-z]+'(, only: '(admin|staff)')?(, coAdmin: true)? \\}/", $nav, $m, PREG_SET_ORDER);
$sidebar = [];
$onlyAdmin = [];
$onlyStaff = [];
$coAdmin = [];
foreach ($m as $item) {
    if (($item[4] ?? '') !== '') $coAdmin[] = $item[1];
    if (($item[3] ?? '') === 'admin') $onlyAdmin[] = $item[1];
    elseif (($item[3] ?? '') === 'staff') $onlyStaff[] = $item[1];
    else $sidebar[] = $item[1];
}
check('Rbac::SIDEBAR is the sidebar\'s work pages, in its order', Rbac::SIDEBAR === $sidebar, implode(',', $sidebar));
check('every sidebar work page is in the table', !array_diff(Rbac::SIDEBAR, array_keys(Rbac::PAGES)));
check('the sidebar shows the administrator\'s pages to the administrator only', $onlyAdmin === Rbac::ADMIN_PAGES, implode(',', $onlyAdmin));
check('the sidebar shows My Access to other staff only', $onlyStaff === ['my-access'], implode(',', $onlyStaff));
check('the sidebar shows co-admins only Rbac::COADMIN_PAGES of the administrator\'s pages', $coAdmin === Rbac::COADMIN_PAGES, implode(',', $coAdmin));
preg_match("/const PROTECTED_PAGES = \\[(.*?)\\];/s", $router, $pm);
$staffPages = [];
if (preg_match_all("/'([a-z-]+)' => \\['admin', 'counselor'\\]/", $pm[1] ?? '', $sm)) {
    $staffPages = $sm[1];
}
$notProtected = array_diff([...array_keys(Rbac::PAGES), ...Rbac::ADMIN_PAGES, 'my-access'], $staffPages);
check('every page in the table, the administrator\'s pages and My Access are protected staff pages in router.php', !$notProtected, implode(', ', $notProtected));
check('the fallback page (My Access) is a staff page that is always open', Rbac::FALLBACK_PAGE === 'my-access' && in_array(Rbac::FALLBACK_PAGE, $staffPages, true)
    && !isset(Rbac::PAGES[Rbac::FALLBACK_PAGE]) && !in_array(Rbac::FALLBACK_PAGE, Rbac::ADMIN_PAGES, true));

echo "\n=== the rows shown on Security Configuration and My Access (js/rbac-features.js) ===\n";
preg_match("/var FEATURES = \\{(.*?)\\n  \\};/s", $page, $fm);
preg_match_all("/^\\s+([a-zA-Z]+): \\{ label:/m", $fm[1] ?? '', $fk);
check('the pages show exactly the 19 features', $fk[1] === $features, implode(',', $fk[1]));
$adminRows = array_filter(Rbac::ADMIN_PAGES, fn($p) => str_contains($page, "'$p'"));
check('the administrator\'s pages are not rows of the shown table', !$adminRows, implode(',', $adminRows));
preg_match("/var PAGE_FEATURES = \\{(.*?)\\n  \\};/s", $page, $gm);
preg_match_all("/'([a-z-]+)': \\[([^\\]]*)\\]/", $gm[1] ?? '', $gk, PREG_SET_ORDER);
$shown = [];
$pagesInTable = [];
foreach ($gk as $g) {
    $pagesInTable[] = $g[1];
    preg_match_all("/'([a-zA-Z]+)'/", $g[2], $ids);
    foreach ($ids[1] as $id) $shown[] = $id;
}
check('the rows are grouped under the ten sidebar work pages', $pagesInTable === Rbac::SIDEBAR, implode(',', $pagesInTable));
sort($shown);
$sortedFeatures = $features;
sort($sortedFeatures);
check('each feature appears exactly once in the table', $shown === $sortedFeatures);
$opensWrong = [];
foreach (array_intersect_key(Rbac::PAGES, array_flip(Rbac::SIDEBAR)) as $slug => $mods) {
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
