<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/Sections.php';

$pdo = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Public by default — registration.html needs this before a student has
    // an account, and a section code isn't sensitive. ?all=1 is the
    // Section Management staff view: every section (including inactive) with
    // its id, for anyone with at least Limited access to the 'sections'
    // module (see Security Configuration's permission table).
    if (($_GET['all'] ?? '') === '1') {
        Rbac::requireAccess('sections', 'limited');
        $rows = $pdo->query('SELECT id, strand, code, is_active FROM sections ORDER BY strand, code')->fetchAll();
        jsonResponse(['sections' => array_map(fn($r) => [
            'id' => (int) $r['id'],
            'strand' => $r['strand'],
            'code' => $r['code'],
            'isActive' => (bool) $r['is_active'],
        ], $rows)]);
    }

    jsonResponse(['sectionsByStrand' => Sections::byStrand($pdo)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = readJsonBody();
    $type = $body['type'] ?? '';

    // Adding a section needs Limited access to the 'sections' module;
    // deactivating/reactivating one needs Full, since it changes what new
    // registrations and exam schedules can pick. Defaults: admin Full,
    // counselor Limited (db/seed_security_defaults.php).
    if ($type === 'create') {
        $user = Rbac::requireAccess('sections', 'limited');
        $strand = (string) ($body['strand'] ?? '');
        $code = trim((string) ($body['code'] ?? ''));

        if (!in_array($strand, Sections::STRANDS, true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid strand.'], 400);
        }
        if ($code === '' || mb_strlen($code) > 20) {
            jsonResponse(['success' => false, 'error' => 'Enter a section code (up to 20 characters).'], 400);
        }

        $existing = $pdo->prepare('SELECT 1 FROM sections WHERE strand = ? AND LOWER(code) = LOWER(?)');
        $existing->execute([$strand, $code]);
        if ($existing->fetch()) {
            jsonResponse(['success' => false, 'error' => 'That section already exists for this strand.'], 409);
        }

        $insert = $pdo->prepare('INSERT INTO sections (strand, code, created_by) VALUES (?, ?, ?) RETURNING id');
        $insert->execute([$strand, $code, $user['id']]);
        $id = (int) $insert->fetchColumn();

        AuditLogger::log($user['id'], $user['role'], 'create_section', 'section', (string) $id, "$strand: $code");
        jsonResponse(['success' => true, 'id' => $id]);
    }

    if ($type === 'toggleActive') {
        $user = Rbac::requireAccess('sections', 'full');
        $id = (int) ($body['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, strand, code, is_active FROM sections WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonResponse(['success' => false, 'error' => 'Section not found.'], 404);
        }

        $newState = !$row['is_active'];
        // PDOStatement::execute() stringifies a bound PHP bool — false
        // becomes '' (not '0'), which Postgres's boolean type rejects.
        // Bind an int instead; Postgres accepts 0/1 for boolean.
        $pdo->prepare('UPDATE sections SET is_active = ? WHERE id = ?')->execute([(int) $newState, $id]);

        AuditLogger::log(
            $user['id'], $user['role'], $newState ? 'activate_section' : 'deactivate_section',
            'section', (string) $id, "{$row['strand']}: {$row['code']}"
        );
        jsonResponse(['success' => true, 'isActive' => $newState]);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown type.'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
