<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/QuestionBank.php';

$pdo = Database::get();
$method = $_SERVER['REQUEST_METHOD'];

/** Active questions per type right now. */
function activeCounts(PDO $pdo): array
{
    $rows = $pdo->query('SELECT dimension, COUNT(*) FROM assessment_questions WHERE is_active = TRUE GROUP BY dimension')->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_map('intval', $rows);
}

function readText(array $body): string
{
    $text = trim((string) ($body['text'] ?? ''));
    if ($text === '') {
        jsonResponse(['success' => false, 'error' => 'Question text cannot be empty.'], 400);
    }
    if (mb_strlen($text) > QuestionBank::MAX_TEXT_LENGTH) {
        jsonResponse(['success' => false, 'error' => 'Question text must be ' . QuestionBank::MAX_TEXT_LENGTH . ' characters or fewer.'], 400);
    }
    return $text;
}

if ($method === 'GET') {
    $user = Auth::requireLogin();
    $isStaff = $user['role'] !== 'student';
    $includeInactive = $isStaff && isset($_GET['all']); // the staff question-bank page passes ?all=1
    $balance = QuestionBank::balance(activeCounts($pdo));

    // Students only get a question set that is ready: the same number of active questions for every type.
    if (!$isStaff && !$balance['ok']) {
        jsonResponse(['questions' => [], 'unavailable' => true, 'error' => 'The assessment is being updated by the Guidance Office. Please check back soon.'], 409);
    }

    $sql = 'SELECT id, dimension, order_index, question_text_enc, is_active FROM assessment_questions';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = TRUE';
    }
    $sql .= " ORDER BY array_position(ARRAY['R','I','A','S','E','C'], dimension), order_index";
    $rows = $pdo->query($sql)->fetchAll();

    $questions = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'dimension' => $r['dimension'],
        'orderIndex' => (int) $r['order_index'],
        'text' => Crypto::dec($r['question_text_enc']),
        'isActive' => (bool) $r['is_active'],
    ], $rows);

    $out = ['questions' => $questions];
    if ($isStaff) {
        $out['balance'] = $balance;
    }
    jsonResponse($out);
}

if ($method === 'PUT') {
    Rbac::requireAccess('rac', 'full');

    $body = readJsonBody();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Missing question id.'], 400);
    }

    $existing = $pdo->prepare('SELECT * FROM assessment_questions WHERE id = ?');
    $existing->execute([$id]);
    $question = $existing->fetch();
    if (!$question) {
        jsonResponse(['success' => false, 'error' => 'Question not found.'], 404);
    }

    $text = array_key_exists('text', $body) ? readText($body) : Crypto::dec($question['question_text_enc']);
    $isActive = array_key_exists('isActive', $body) ? (bool) $body['isActive'] : $question['is_active'];

    $update = $pdo->prepare('UPDATE assessment_questions SET question_text_enc = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
    $update->execute([Crypto::enc($text), $isActive, $id]);

    $user = Auth::currentUser();
    AuditLogger::log($user['id'], $user['role'], 'update_question', 'assessment_question', (string) $id, "Dimension {$question['dimension']}#{$question['order_index']}");

    jsonResponse(['success' => true, 'balance' => QuestionBank::balance(activeCounts($pdo))]);
}

if ($method === 'POST') {
    $user = Rbac::requireAccess('rac', 'full');
    $body = readJsonBody();
    $action = (string) ($body['action'] ?? 'add');

    // Put the 30-question O*NET Mini Interest Profiler in place: every other question is hidden (kept, not deleted).
    if ($action === 'loadOnet') {
        $existing = $pdo->query('SELECT id, dimension, question_text_enc FROM assessment_questions')->fetchAll();
        $byText = [];
        foreach ($existing as $r) {
            $byText[$r['dimension'] . '|' . mb_strtolower(trim(Crypto::dec($r['question_text_enc'])))] = (int) $r['id'];
        }

        $added = 0;
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE assessment_questions SET is_active = FALSE, updated_at = NOW() WHERE is_active = TRUE');
            $reactivate = $pdo->prepare('UPDATE assessment_questions SET is_active = TRUE, updated_at = NOW() WHERE id = ?');
            $nextOrder = $pdo->prepare('SELECT COALESCE(MAX(order_index), 0) + 1 FROM assessment_questions WHERE dimension = ?');
            $insert = $pdo->prepare('INSERT INTO assessment_questions (dimension, question_text_enc, order_index, is_active, created_by) VALUES (?, ?, ?, TRUE, ?)');
            // Re-use the existing question when the wording is already there; otherwise add it after the last one of that type.
            foreach (QuestionBank::ONET_MINI_IP as $dimension => $texts) {
                foreach ($texts as $text) {
                    $key = $dimension . '|' . mb_strtolower($text);
                    if (isset($byText[$key])) {
                        $reactivate->execute([$byText[$key]]);
                        continue;
                    }
                    $nextOrder->execute([$dimension]);
                    $insert->execute([$dimension, Crypto::enc($text), (int) $nextOrder->fetchColumn(), $user['id']]);
                    $added++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Could not load the question set. The database may need the latest update first.'], 500);
        }

        AuditLogger::log($user['id'], $user['role'], 'load_question_set', 'assessment_question', null, 'O*NET Mini Interest Profiler (30 questions)');
        jsonResponse(['success' => true, 'added' => $added, 'balance' => QuestionBank::balance(activeCounts($pdo))]);
    }

    // Add one question to a type.
    $dimension = (string) ($body['dimension'] ?? '');
    if (!in_array($dimension, QuestionBank::DIMENSIONS, true)) {
        jsonResponse(['success' => false, 'error' => 'Choose a valid type (R, I, A, S, E or C).'], 400);
    }
    $text = readText($body);
    $isActive = !array_key_exists('isActive', $body) || (bool) $body['isActive'];

    $next = $pdo->prepare('SELECT COALESCE(MAX(order_index), 0) + 1 FROM assessment_questions WHERE dimension = ?');
    $next->execute([$dimension]);
    try {
        $insert = $pdo->prepare('INSERT INTO assessment_questions (dimension, question_text_enc, order_index, is_active, created_by) VALUES (?, ?, ?, ?, ?) RETURNING id');
        $insert->execute([$dimension, Crypto::enc($text), (int) $next->fetchColumn(), $isActive, $user['id']]);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'error' => 'Could not add the question. The database may need the latest update first.'], 500);
    }
    $newId = (int) $insert->fetchColumn();

    AuditLogger::log($user['id'], $user['role'], 'add_question', 'assessment_question', (string) $newId, "Dimension $dimension");
    jsonResponse(['success' => true, 'id' => $newId, 'balance' => QuestionBank::balance(activeCounts($pdo))]);
}

if ($method === 'DELETE') {
    $user = Rbac::requireAccess('rac', 'full');
    $body = readJsonBody();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Missing question id.'], 400);
    }

    $stmt = $pdo->prepare('DELETE FROM assessment_questions WHERE id = ? RETURNING dimension, order_index');
    $stmt->execute([$id]);
    $gone = $stmt->fetch();
    if (!$gone) {
        jsonResponse(['success' => false, 'error' => 'Question not found.'], 404);
    }

    AuditLogger::log($user['id'], $user['role'], 'delete_question', 'assessment_question', (string) $id, "Dimension {$gone['dimension']}#{$gone['order_index']}");
    jsonResponse(['success' => true, 'balance' => QuestionBank::balance(activeCounts($pdo))]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
