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
 *   prediction        the WEKA J48 model's course ("students with a similar RIASEC profile
 *                     chose"), shown on its own. Staff always get it; students only when
 *                     config/cbf.php decision_tree.show_to_students is true.
 *   finalBestMatch    {status, courses}: the course(s) common to the Best RIASEC Match, the
 *                     prediction and the Preferred Course (lib/RecommendationPipeline.php).
 *                     status best_match | no_common_course | incomplete | disabled; courses is
 *                     empty unless best_match. Never filled in with a substitute.
 *   every course carries supportedBy: the sources it comes from ('cbf', 'prediction' when the
 *   prediction is shown to this user, 'worksheet' for the Preferred Course)
 * Staff (studentId=...) also get `cbf` (every course's cosine calculation) and `audit`
 * (inputs, the WEKA rule that fired, WEKA's own evaluation, the integration step by step).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CBFData.php';
require_once __DIR__ . '/../lib/Careers.php';

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
        'SELECT id, computed_at, stated_program_id, scores, source_assessment_id, source_worksheet_id, match_status, mismatch_reason,
                cbf_program_ids, final_program_ids, prediction_program_ids, model_version
         FROM recommendations WHERE student_id = ? AND source_assessment_id = (SELECT id FROM assessments WHERE student_id = recommendations.student_id AND is_latest = TRUE)
         ORDER BY computed_at DESC LIMIT 1'
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
$statedCareer = null;
if ($row['source_worksheet_id'] !== null) {
    $wsStmt = $pdo->prepare('SELECT electives, stated_career, submitted_at FROM worksheets WHERE id = ?');
    $wsStmt->execute([(int) $row['source_worksheet_id']]);
    $wsRow = $wsStmt->fetch();
    if ($wsRow) {
        $electives = CBFData::parseTextArray($wsRow['electives']);
        $statedCareer = $wsRow['stated_career'];
        $worksheetSubmittedAt = $wsRow['submitted_at'];
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
// Prediction model (WEKA): NULL = did not run; {} = ran but predicted no active program.
$predictionIds = CBFData::parseIntArray($row['prediction_program_ids'] ?? null);
$showPrediction = $isStaff || !empty(CBFEngine::config()['decision_tree']['show_to_students']);
if ($showPrediction && $predictionIds) {
    $candidateIds = array_merge($candidateIds, $predictionIds);
}
// Final Best Match from the saved source results, with the same rule used when it was saved.
$final = RecommendationPipeline::finalRecommendation(
    ['status' => 'available', 'candidateIds' => $bestIds, 'alternatives' => $alternativeIds],
    ['status' => $preferredId !== null ? 'available' : 'empty', 'courseIds' => $preferredId !== null ? [$preferredId] : []],
    ['status' => $predictionIds === null ? 'unavailable' : ($predictionIds ? 'available' : 'no_prediction'), 'courseIds' => $predictionIds ?? []]
);
$finalIds = $final['finalBestMatchIds'] ?? [];
$candidateIds = array_merge($candidateIds, $finalIds);

$neededIds = array_merge($candidateIds, $preferredId !== null ? [$preferredId] : [], $isStaff ? array_column($scores, 'programId') : []);
$programs = [];
if ($neededIds = array_values(array_unique(array_map('intval', $neededIds)))) {
    $placeholders = implode(',', array_fill(0, count($neededIds), '?'));
    $programStmt = $pdo->prepare(
        "SELECT p.id, p.title_enc, p.holland_code_enc, p.description_enc, p.careers, p.status, c.code AS college_code, c.name AS college_name
         FROM programs p JOIN colleges c ON c.id = p.college_id WHERE p.id IN ($placeholders)"
    );
    $programStmt->execute($neededIds);
    foreach ($programStmt->fetchAll() as $r) {
        $title = Crypto::dec($r['title_enc']);
        $programs[(int) $r['id']] = [
            'id' => (int) $r['id'],
            'title' => $title,
            'hollandCode' => Crypto::dec($r['holland_code_enc']),
            'description' => $r['description_enc'] !== null ? Crypto::dec($r['description_enc']) : '',
            // Careers that fit this program (its title if staff haven't listed any).
            'careers' => Careers::effective(Careers::parse($r['careers']), $title),
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
// Which sources each course comes from (the prediction only for users who may see it).
foreach ($programs as $id => &$program) {
    $program['supportedBy'] = array_keys(array_filter([
        'cbf' => in_array($id, $bestIds, true) || in_array($id, $alternativeIds, true),
        'prediction' => $showPrediction && in_array($id, $predictionIds ?? [], true),
        'worksheet' => $id === $preferredId,
    ]));
}
unset($program);
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
    // Final Best Match: course(s) supported by all three sources, or a status saying why none.
    'finalBestMatch' => ['status' => $final['finalStatus'], 'courses' => $programsFor($finalIds)],
    'sources' => ['cbf' => 'available', 'worksheet' => $preferredId !== null ? 'available' : 'empty',
        'prediction' => $predictionIds === null ? 'unavailable' : ($predictionIds ? 'available' : 'no_prediction')],
    'isComplete' => $final['isComplete'],
    // What the student typed on the Career Worksheet, and the program it was linked to (if any).
    'statedProgramId' => $preferredId,
    'statedCareer' => $statedCareer,
    'electives' => $electives,
    'matchStatus' => $row['match_status'],
    'mismatchReason' => $row['mismatch_reason'],
    'needsRecompute' => !$isCurrent,
];
if (!$showPrediction) {
    $response['sources']['prediction'] = 'staff_only';
} else {
    // Source 3, on its own: never changes the Best RIASEC Match or the Preferred Course.
    $predicted = $predictionIds ? ($programs[$predictionIds[0]] ?? null) : null;
    $response['prediction'] = [
        'status' => $response['sources']['prediction'],
        'course' => $predicted ? $predicted + ['inBestRiasecMatch' => in_array($predicted['id'], $bestIds, true),
            'inAlternatives' => in_array($predicted['id'], $alternativeIds, true)] : null,
        'modelVersion' => $row['model_version'],
    ];
}
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
if ($isStaff) {
    // Audit: everything needed to check this result by hand (staff only; no school ID or name).
    $raw = null;
    if ($row['source_assessment_id'] !== null) {
        $aStmt = $pdo->prepare('SELECT score_r, score_i, score_a, score_s, score_e, score_c FROM assessments WHERE id = ?');
        $aStmt->execute([(int) $row['source_assessment_id']]);
        if ($a = $aStmt->fetch()) {
            $raw = ['R' => (int) $a['score_r'], 'I' => (int) $a['score_i'], 'A' => (int) $a['score_a'],
                'S' => (int) $a['score_s'], 'E' => (int) $a['score_e'], 'C' => (int) $a['score_c']];
        }
    }
    $model = PredictionModel::load();
    $explain = null;
    if ($model !== null && $raw !== null) {
        try {
            $explain = PredictionModel::explain($model, $raw);
        } catch (InvalidArgumentException $e) {
            $explain = null;
        }
    }
    $titleOf = fn(array $ids) => array_values(array_map(fn($id) => $programs[$id]['title'] ?? (string) $id, $ids));
    $cfg = CBFEngine::config();
    $response['audit'] = [
        'student' => [
            'riasecScores' => $raw,            // 10-50 per type (lib/QuestionBank.php)
            'studentVector' => $scores[0]['studentVector'] ?? null,   // score / 10, order R,I,A,S,E,C
            'preferredCourse' => $preferredId !== null ? ($programs[$preferredId]['title'] ?? null) : null,
        ],
        'prediction' => [
            'enabled' => !empty($cfg['decision_tree']['enabled']),
            'shownToStudents' => !empty($cfg['decision_tree']['show_to_students']),
            'savedModelVersion' => $row['model_version'],
            'savedCourse' => $predictionIds ? $titleOf($predictionIds) : [],
            'savedStatus' => $response['sources']['prediction'],
            'currentModelVersion' => $model['version'] ?? null,
            'modelFile' => $model ? ($model['source'] ?? null) : null,
            'features' => $model['features'] ?? null,
            'featureNote' => ($model['features'] ?? 'top3') === 'top3'
                ? 'x = one of the student\'s three highest RIASEC types, blank = not (adviser\'s dataset format)'
                : 'rank of each RIASEC type within the student (1 = highest)',
            'targetClass' => 'Preferred course in the CGC dataset (the student\'s preferred course, not an enrolment record)',
            'excludedColumns' => ['student_id'],
            'inputs' => $explain['inputs'] ?? null,
            'rule' => $explain['path'] ?? null,     // conditions from the root to the leaf, under the current model
            'ruleLabel' => $explain['label'] ?? null,
            // The course the current model gives now; differs from savedCourse after a model change (recompute).
            'ruleCourse' => isset($explain['label']) ? PredictionModel::resolveLabel($explain['label'], array_column($programs, 'title')) : null,
            'training' => $model['training'] ?? null, // WEKA's own run info and evaluation, verbatim (if imported with it)
        ],
        'worksheet' => [
            'statedCareer' => $statedCareer,
            'electives' => $electives,
            'submittedAt' => $worksheetSubmittedAt ?? null,
            'linkedCourse' => $preferredId !== null ? ($programs[$preferredId]['title'] ?? null) : null,
            'mapping' => 'The typed career is matched by words against each active program\'s careers list and title '
                . '(lib/CareerMatcher.php); ties go to the first program in the list. RIASEC scores are not used. '
                . 'Electives are recorded but not mapped to courses (no approved elective-to-course rule).',
        ],
        'integration' => [
            'rule' => 'Final Best Match = Best RIASEC Match ∩ WEKA predicted course ∩ Preferred Course; no weights, no fallback',
            'enabled' => !empty($cfg['final_best_match']['enabled']),
            'cbfCandidates' => $titleOf($bestIds),
            'prediction' => $predictionIds ? $titleOf($predictionIds) : [],
            'worksheetCandidates' => $preferredId !== null ? $titleOf([$preferredId]) : [],
            'overlaps' => array_map($titleOf, $final['overlaps']),
            'missingSources' => $final['finalMissing'],
            'status' => $final['finalStatus'],
            'finalBestMatch' => $titleOf($finalIds),
            'savedFinal' => $row['final_program_ids'] === null ? null : $titleOf(CBFData::parseIntArray($row['final_program_ids'])),
        ],
    ];
}
jsonResponse($response);
