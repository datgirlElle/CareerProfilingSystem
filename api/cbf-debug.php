<?php
/**
 * Developer / thesis verification view of the CBF engine for one student:
 * student vector, every program vector, cosine similarity per block and
 * overall, Final Match Score, ranking, and matching features.
 *
 * GET api/cbf-debug.php?schoolId=<school id>   (or ?studentId=<user id>)
 *
 * Recomputed live from the student's CURRENT profile and program dataset, so
 * it can differ from the saved recommendation snapshot if either has changed
 * since the worksheet was submitted.
 *
 * Staff only: students also hold 'recommendations' access (to see their own
 * results), so the role is checked explicitly as well.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CBFEngine.php';
require_once __DIR__ . '/../lib/CBFData.php';

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

jsonResponse(['studentId' => $studentId] + CBFEngine::trace($profile, CBFData::activePrograms($pdo), $profile['statedProgramId']));
