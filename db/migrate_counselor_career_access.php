<?php
// Guidance Counselors add and edit programs and their RIASEC codes (Career Dataset: the "programs" feature);
// Guidance Facilitators keep View Only. The administrator keeps Full. This is Rbac::DEFAULTS['programs'] now; this
// migration brings a table set up with the earlier default (counselor View Only) up to it. A level the administrator
// set in Security Configuration (updated_by filled in) is never overwritten. Run after migrate_rbac_by_page.php.
// Safe to re-run.

require_once __DIR__ . '/../shared/lib/Database.php';

$pdo = Database::get();
$counselor = $pdo->exec(
    "UPDATE security_rbac SET access_level = 'full', updated_at = NOW()
     WHERE module = 'programs' AND role = 'counselor' AND access_level <> 'full' AND updated_by IS NULL"
);
$facilitator = $pdo->exec(
    "INSERT INTO security_rbac (module, role, access_level) VALUES ('programs', 'facilitator', 'limited')
     ON CONFLICT (module, role) DO NOTHING"
);

echo "Career Dataset: Guidance Counselors Full ($counselor row(s) changed), Facilitators View Only ($facilitator row(s) added).\n";
