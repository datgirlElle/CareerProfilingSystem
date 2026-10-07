<?php
/**
 * The student's latest recommendation (computed by lib/RecommendationPipeline.php when
 * the Career Electives Worksheet is submitted).
 *
 * Students get exactly what the results page shows:
 *   topMatches       the one Top Matches list (a set: program-list order, no ranks, no scores)
 *   preferredCourse  the Preferred Course from the worksheet + whether it is in the Top Matches
 *   matchStatus      'match' / 'mismatch' (mismatch -> advise seeing a Guidance Counselor)
 * Staff (studentId=...) additionally get `stages`: the CBF match set, the prediction
 * model's output and the common courses, for monitoring and research transparency.
 */

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
$isStaff = $user['role'] !== 'student';

try {
    $stmt = $pdo->prepare(
        'SELECT id, computed_at, stated_program_id, scores, source_worksheet_id, match_status, mismatch_reason,
                cbf_program_ids, prediction_program_ids, final_program_ids, model_version
         FROM recommendations WHERE student_id = ? ORDER BY computed_at DESC LIMIT 1'
    );
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    // Most likely a database that has not been migrated yet (missing pipeline columns).
    error_log('[recommendations] ' . $e->getMessage());
    jsonResponse(['error' => 'Your results could not be loaded right now. Please try again later or contact the Guidance Office.',
        'setupHint' => 'Run: php db/migrate_add_recommendation_status.php && php db/migrate_add_recommendation_sets.php'], 500);
}

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

$cbfIds = CBFData::parseIntArray($row['cbf_program_ids']);
$predictionIds = CBFData::parseIntArray($row['prediction_program_ids']);
$finalIds = CBFData::parseIntArray($row['final_program_ids']);
if ($finalIds === null) {
    // Saved before the pipeline columns existed: the Top Matches were the CBF matches.
    $scores = json_decode($row['scores'], true);
    usort($scores, fn($a, $b) => [$b['score'], $b['cosine'], $a['programId']] <=> [$a['score'], $a['cosine'], $b['programId']]);
    $cbfIds = array_map('intval', array_column(CBFEngine::selectMatches($scores, 'score'), 'programId'));
    sort($cbfIds);
    $finalIds = $cbfIds;
}
$commonIds = $predictionIds !== null ? array_values(array_intersect($cbfIds, $predictionIds)) : null;
$preferredId = $row['stated_program_id'] !== null ? (int) $row['stated_program_id'] : null;

$neededIds = array_merge($finalIds, $preferredId !== null ? [$preferredId] : [], $isStaff ? array_merge($cbfIds, $predictionIds ?? []) : []);
$programs = [];
if ($neededIds = array_values(array_unique($neededIds))) {
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
            // Programs are never hard-deleted (see api/programs.php), so an existing result
            // still resolves after staff deactivate one; this lets the UI flag it.
            'isActive' => $r['status'] === 'Active',
        ];
    }
}
$programsFor = fn(array $ids) => array_values(array_filter(array_map(fn($id) => $programs[$id] ?? null, $ids)));
$titlesFor = fn(?array $ids) => $ids === null ? null : array_column($programsFor($ids), 'title');

$response = [
    'hasRecommendation' => true,
    'computedAt' => $row['computed_at'],
    'topMatches' => $programsFor($finalIds),
    'preferredCourse' => $preferredId !== null && isset($programs[$preferredId])
        ? $programs[$preferredId] + [
            'inTopMatches' => in_array($preferredId, $finalIds, true),
            // fits the student's RIASEC letters (CBF), even if not among the Top Matches
            'inGuidanceMapping' => in_array($preferredId, $cbfIds, true),
        ]
        : null,
    // How the Top Matches were formed: 'guidance' (guidance mapping/CBF only; prediction
    // model not enabled), 'guidance_and_model' (courses common to both), or
    // 'guidance_fallback' (no common course, so the guidance mapping is shown).
    'basis' => $predictionIds === null ? 'guidance' : ($commonIds ? 'guidance_and_model' : 'guidance_fallback'),
    'electives' => $electives,
    // 'match' / 'mismatch' (RecommendationPipeline::combine); null on results saved before it existed.
    'matchStatus' => $row['match_status'],
    'mismatchReason' => $row['mismatch_reason'],
];
if ($isStaff) {
    $response['stages'] = [
        'cbfMatches' => $titlesFor($cbfIds),
        'predictionModel' => $titlesFor($predictionIds), // null = model not enabled for this result
        'commonCourses' => $titlesFor($commonIds),
        'modelVersion' => $row['model_version'],
    ];
}
jsonResponse($response);
