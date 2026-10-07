<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CBFData.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
$pdo = Database::get();

$requestedStudentId = isset($_GET['studentId']) ? (int) $_GET['studentId'] : null;
if ($requestedStudentId !== null && $requestedStudentId !== (int) $user['id']) {
    Rbac::requireAccess('recommendations', 'full');
    $studentId = $requestedStudentId;
} else {
    if ($user['role'] !== 'student') {
        jsonResponse(['error' => 'studentId is required for non-student accounts.'], 400);
    }
    $studentId = (int) $user['id'];
}

$stmt = $pdo->prepare(
    'SELECT id, computed_at, stated_program_id, scores, top_program_id, top_score, source_worksheet_id, match_status, mismatch_reason
     FROM recommendations WHERE student_id = ? ORDER BY computed_at DESC LIMIT 1'
);
$stmt->execute([$studentId]);
$row = $stmt->fetch();

if (!$row) {
    jsonResponse(['hasRecommendation' => false]);
}

$electives = [];
if ($row['source_worksheet_id'] !== null) {
    $wsStmt = $pdo->prepare('SELECT electives FROM worksheets WHERE id = ?');
    $wsStmt->execute([(int) $row['source_worksheet_id']]);
    $wsRow = $wsStmt->fetch();
    if ($wsRow) {
        $electives = CBFData::parseTextArray($wsRow['electives']);
    }
}

$scores = json_decode($row['scores'], true);
// Same order as the engine: score, then cosine, then lower program id.
usort($scores, fn($a, $b) => [$b['score'], $b['cosine'], $a['programId']] <=> [$a['score'], $a['cosine'], $b['programId']]);
// The student's mapped courses ("Top Matches"), selected by the same rule as the engine.
$top3Scores = CBFEngine::selectMatches($scores, 'score');

$statedProgramId = $row['stated_program_id'] !== null ? (int) $row['stated_program_id'] : null;
$top3Ids = array_column($top3Scores, 'programId');
$statedInTop3 = $statedProgramId !== null && in_array($statedProgramId, $top3Ids, true);

$statedOutsideTop3Score = null;
if ($statedProgramId !== null && !$statedInTop3) {
    foreach ($scores as $s) {
        if ($s['programId'] === $statedProgramId) {
            $statedOutsideTop3Score = $s;
            break;
        }
    }
}

$neededIds = $top3Ids;
if ($statedProgramId !== null) {
    $neededIds[] = $statedProgramId;
}
$neededIds = array_values(array_unique($neededIds));

$programs = [];
if ($neededIds) {
    $placeholders = implode(',', array_fill(0, count($neededIds), '?'));
    $programStmt = $pdo->prepare(
        "SELECT p.id, p.title_enc, p.holland_code_enc, p.description_enc, p.status, c.code AS college_code, c.name AS college_name
         FROM programs p JOIN colleges c ON c.id = p.college_id WHERE p.id IN ($placeholders)"
    );
    $programStmt->execute($neededIds);
    foreach ($programStmt->fetchAll() as $r) {
        $programs[(int) $r['id']] = [
            'id' => (int) $r['id'],
            'title' => Crypto::dec($r['title_enc']),
            'hollandCode' => Crypto::dec($r['holland_code_enc']),
            'description' => $r['description_enc'] !== null ? Crypto::dec($r['description_enc']) : '',
            'collegeCode' => $r['college_code'],
            'collegeName' => $r['college_name'],
            // The program row is never hard-deleted (see api/programs.php),
            // so a student's existing result still resolves correctly even
            // after staff deactivate it — this just lets the UI flag it.
            'isActive' => $r['status'] === 'Active',
        ];
    }
}

function enrichEntry(array $scoreEntry, array $programs): ?array
{
    $program = $programs[$scoreEntry['programId']] ?? null;
    if ($program === null) {
        return null;
    }
    return $program + [
        'cosine' => (float) $scoreEntry['cosine'],
        'score' => (float) $scoreEntry['score'],
        'matchPercent' => (int) round($scoreEntry['score'] * 100),
        // Present on snapshots computed by the multi-attribute CBF engine;
        // null on older RIASEC-only snapshots, where the UI keeps its old text.
        'matchingFactors' => $scoreEntry['matches'] ?? null,
        'explanation' => $scoreEntry['explanation'] ?? null,
        // Final-score weights used for this snapshot; null on older snapshots,
        // which all used 0.70 x cosine + 0.30 x stated-program indicator.
        'formula' => $scoreEntry['formula'] ?? null,
    ];
}

$top3 = array_values(array_filter(array_map(fn($s) => enrichEntry($s, $programs), $top3Scores)));

$statedProgramScore = null;
foreach ($scores as $s) {
    if ($s['programId'] === $statedProgramId) {
        $statedProgramScore = $s;
        break;
    }
}

// Mismatch: the student is referred to the Guidance Office and is not shown any course
// (adviser decision), so the matched courses are not sent to the student's own browser.
// Staff viewing the student still get the full result.
$hideCourses = $user['role'] === 'student' && $row['match_status'] === 'mismatch';

jsonResponse([
    'hasRecommendation' => true,
    'computedAt' => $row['computed_at'],
    'statedProgramId' => $statedProgramId,
    'statedProgram' => $statedProgramScore !== null ? enrichEntry($statedProgramScore, $programs) : null,
    'electives' => $electives,
    'topProgramId' => $hideCourses ? null : (int) $row['top_program_id'],
    'topScore' => $hideCourses ? null : (float) $row['top_score'],
    'top3' => $hideCourses ? [] : $top3,
    // 'match' / 'mismatch' (CBFEngine::classify); null on results saved before it existed.
    'matchStatus' => $row['match_status'],
    'mismatchReason' => $row['mismatch_reason'],
    'statedOutsideTop3' => $statedOutsideTop3Score !== null ? enrichEntry($statedOutsideTop3Score, $programs) : null,
]);
