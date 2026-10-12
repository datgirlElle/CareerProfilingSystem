<?php
/**
 * CBF calculation view for one student (staff only), used to show that the content-based
 * filtering is really computed: the student's RIASEC scores and vector, and for EVERY
 * course its RIASEC code, course vector, dot product, magnitudes and cosine similarity,
 * plus the recommendation sources (CBF candidates, worksheet, prediction model unavailable).
 *
 * GET api/cbf-debug.php?schoolId=<school id>   (or ?studentId=<user id>)
 *
 * Recomputed live from the student's CURRENT assessment and program dataset, so it can
 * differ from the saved result if either has changed since the worksheet was submitted.
 *
 * Staff only: students also hold 'recommendations' access (to see their own results),
 * so the role is checked explicitly as well.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CBFEngine.php';
require_once __DIR__ . '/../lib/CBFData.php';
require_once __DIR__ . '/../lib/RecommendationPipeline.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$user = Rbac::requireAccess('recommendations', 'full');
if (!in_array($user['role'], ['admin', 'counselor'], true)) {
    jsonResponse(['error' => 'Insufficient permissions for this action.'], 403);
}

$pdo = Database::get();

if (isset($_GET['studentId'])) {
    $studentId = (int) $_GET['studentId'];
} elseif (isset($_GET['schoolId'])) {
    $stmt = $pdo->prepare('SELECT user_id FROM students WHERE LOWER(school_id) = LOWER(?)');
    $stmt->execute([trim((string) $_GET['schoolId'])]);
    $studentId = (int) $stmt->fetchColumn();
} else {
    jsonResponse(['error' => 'studentId or schoolId is required.'], 400);
}

$profile = $studentId > 0 ? CBFData::studentProfile($pdo, $studentId) : null;
if ($profile === null) {
    jsonResponse(['error' => 'Student not found.'], 404);
}

AuditLogger::log((int) $user['id'], $user['role'], 'view_cbf_debug', 'student', (string) $studentId);

$programs = CBFData::activePrograms($pdo);
$titles = array_column($programs, 'title', 'id');
$named = fn(array $ids) => array_map(fn($id) => $titles[$id] ?? $id, $ids);
$run = RecommendationPipeline::run($profile, $programs, $profile['statedProgramId'] !== null ? [$profile['statedProgramId']] : []);
$cbf = $run['cbf'];

jsonResponse([
    'studentId' => $studentId,
    'formula' => 'cos(S, C) = (S · C) / (||S|| × ||C||)',
    'dimensions' => CBFEngine::DIMENSIONS,
    'cbf' => [
        'status' => $cbf['status'],
        'reason' => $cbf['reason'],
        'studentScores' => $cbf['studentScores'] ?? $profile['riasec'],
        'studentVector' => $cbf['studentVector'] ?? null,
        'studentMagnitude' => $cbf['studentMagnitude'] ?? null,
        'results' => array_map(fn($r) => $r + [
            'isBestRiasecMatch' => in_array($r['id'], $cbf['candidateIds'], true),
            'isAlternative' => in_array($r['id'], $cbf['alternatives'] ?? [], true),
        ], $cbf['results']),
        'excluded' => $cbf['excluded'],
        'bestRiasecMatch' => $named($cbf['candidateIds']),
        'alternatives' => $named($cbf['alternatives'] ?? []),
    ],
    'worksheet' => ['status' => $run['worksheet']['status'], 'preferredCourse' => $named($run['worksheet']['courseIds'])],
    'prediction' => $run['prediction'],
    'final' => [
        'preferredInBestRiasecMatch' => array_map(fn($p) => $p['inBestRiasecMatch'], $run['preferred']),
        'finalBestMatch' => null, // needs the prediction model
        'status' => $run['status'],
        'reason' => $run['reason'],
        'sourcesUsed' => $run['sourcesUsed'],
        'pendingSources' => $run['pendingSources'],
        'isComplete' => $run['isComplete'],
    ],
]);
