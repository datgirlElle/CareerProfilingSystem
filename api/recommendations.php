<?php
/**
 * The student's latest recommendation (lib/RecommendationPipeline.php, computed when the
 * Career Electives Worksheet is submitted).
 *
 * Students get exactly what the results page shows:
 *   preferredCourse   the course the student selected (worksheet), shown on its own
 *   bestRiasecMatch   CBF: course(s) with the highest cosine similarity to the student's
 *                     RIASEC profile (ties listed together)
 *   alternatives      CBF: course(s) at the next-highest cosine similarity
 *   each course carries its cosine similarity (0-1), never a percentage
 *   finalBestMatch    null: needs the prediction model (WEKA), which is not available yet
 * Staff (studentId=...) also get `cbf`: every course's cosine calculation.
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
                cbf_program_ids, final_program_ids
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

$scores = json_decode($row['scores'], true) ?: [];
usort($scores, fn($a, $b) => [$b['cosine'], $a['programId']] <=> [$a['cosine'], $b['programId']]);
$cosineOf = array_column($scores, 'cosine', 'programId');
$preferredId = $row['stated_program_id'] !== null ? (int) $row['stated_program_id'] : null;

// Results saved by this CBF version store each course's dot product; older results
// (before the CBF revision) should be recomputed (php db/recompute_recommendations.php).
$isCurrent = isset($scores[0]['dotProduct']);
// Best RIASEC Match: courses tied at the highest cosine similarity (CBF only).
$bestIds = $isCurrent && $row['cbf_program_ids'] !== null
    ? CBFData::parseIntArray($row['cbf_program_ids'])
    : array_map('intval', array_column(CBFEngine::selectCandidates($scores, 'cosine'), 'programId'));
// Alternative Courses: courses at the next-highest cosine similarity (CBF only).
$alternativeIds = array_map('intval', array_column(CBFEngine::selectAlternatives($scores, 'cosine'), 'programId'));
$candidateIds = array_merge($bestIds, $alternativeIds);

$neededIds = array_merge($candidateIds, $preferredId !== null ? [$preferredId] : [], $isStaff ? array_column($scores, 'programId') : []);
$programs = [];
if ($neededIds = array_values(array_unique(array_map('intval', $neededIds)))) {
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
            // Cosine similarity between the student's RIASEC profile and this course (0-1).
            'cosineSimilarity' => isset($cosineOf[(int) $r['id']]) ? (float) $cosineOf[(int) $r['id']] : null,
        ];
    }
}
$programsFor = fn(array $ids) => array_values(array_filter(array_map(fn($id) => $programs[$id] ?? null, $ids)));

$response = [
    'hasRecommendation' => true,
    'computedAt' => $row['computed_at'],
    // CBF (RIASEC profile + course RIASEC codes + cosine similarity) only:
    'bestRiasecMatch' => $programsFor($bestIds),         // tied courses all listed, never tie-broken
    'alternatives' => $programsFor($alternativeIds),     // next-highest similarity
    // The student's own selection (Career Electives Worksheet), shown separately; it never
    // changes the CBF result.
    'preferredCourse' => $preferredId !== null && isset($programs[$preferredId])
        ? $programs[$preferredId] + ['inBestRiasecMatch' => in_array($preferredId, $bestIds, true)]
        : null,
    // The final Best Match (CBF + prediction model + worksheet) needs the prediction model,
    // which is not available yet, so it is not computed.
    'finalBestMatch' => null,
    'sources' => ['cbf' => 'available', 'worksheet' => $preferredId !== null ? 'available' : 'empty', 'prediction' => 'unavailable'],
    'isComplete' => false,
    'electives' => $electives,
    'matchStatus' => $row['match_status'],
    'mismatchReason' => $row['mismatch_reason'],
    'needsRecompute' => !$isCurrent,
];
if ($isStaff) {
    $response['cbf'] = [
        'bestRiasecMatch' => array_column($programsFor($bestIds), 'title'),
        'alternatives' => array_column($programsFor($alternativeIds), 'title'),
        'studentVector' => $scores[0]['studentVector'] ?? null,
        'studentMagnitude' => $scores[0]['studentMagnitude'] ?? null,
        'results' => array_map(fn($sc) => [
            'title' => $programs[(int) $sc['programId']]['title'] ?? (string) $sc['programId'],
            'hollandCode' => $sc['hollandCode'] ?? ($programs[(int) $sc['programId']]['hollandCode'] ?? null),
            'courseVector' => $sc['courseVector'] ?? null,
            'dotProduct' => $sc['dotProduct'] ?? null,
            'courseMagnitude' => $sc['courseMagnitude'] ?? null,
            'cosineSimilarity' => (float) $sc['cosine'],
        ], $scores),
    ];
}
jsonResponse($response);
