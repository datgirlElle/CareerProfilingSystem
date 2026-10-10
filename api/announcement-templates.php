<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/AnnouncementTemplate.php';

// Starting templates for announcements. Staff can read them; whoever may post announcements (the admin and
// Guidance Counselors, not a Facilitator) types, edits and deletes them.
Rbac::requireRole('admin', 'counselor'); // students have view-only access to announcements, and no business here
$user = Rbac::requireAccess('announcements', 'limited');
$pdo = Database::get();

function templateRow(array $r): array
{
    return ['id' => (int) $r['id'], 'name' => $r['name'], 'title' => $r['title'], 'body' => $r['body']];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query('SELECT id, name, title, body FROM announcement_templates ORDER BY name, id')->fetchAll();
    jsonResponse(['templates' => array_map('templateRow', $rows)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

Rbac::requireAccess('announcements', 'full');
$body = readJsonBody();
$type = (string) ($body['type'] ?? '');

if ($type === 'create' || $type === 'update') {
    $name = AnnouncementTemplate::clean((string) ($body['name'] ?? ''), false);
    $title = AnnouncementTemplate::clean((string) ($body['title'] ?? ''), false);
    $text = AnnouncementTemplate::clean((string) ($body['body'] ?? ''), true);
    $problem = AnnouncementTemplate::validate($name, $title, $text);
    if ($problem !== null) {
        jsonResponse(['success' => false, 'error' => $problem], 400);
    }

    if ($type === 'create') {
        if ((int) $pdo->query('SELECT COUNT(*) FROM announcement_templates')->fetchColumn() >= AnnouncementTemplate::MAX_TEMPLATES) {
            jsonResponse(['success' => false, 'error' => 'There are already ' . AnnouncementTemplate::MAX_TEMPLATES . ' templates. Delete one before adding another.'], 409);
        }
        $insert = $pdo->prepare('INSERT INTO announcement_templates (name, title, body, created_by, updated_by) VALUES (?, ?, ?, ?, ?) RETURNING id');
        $insert->execute([$name, $title, $text, $user['id'], $user['id']]);
        $id = (int) $insert->fetchColumn();
        AuditLogger::log($user['id'], $user['role'], 'create_announcement_template', 'announcement_template', (string) $id, $name);
        jsonResponse(['success' => true, 'id' => $id]);
    }

    $id = (int) ($body['id'] ?? 0);
    $update = $pdo->prepare('UPDATE announcement_templates SET name = ?, title = ?, body = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
    $update->execute([$name, $title, $text, $user['id'], $id]);
    if ($update->rowCount() === 0) {
        jsonResponse(['success' => false, 'error' => 'That template no longer exists. Refresh the page.'], 404);
    }
    AuditLogger::log($user['id'], $user['role'], 'update_announcement_template', 'announcement_template', (string) $id, $name);
    jsonResponse(['success' => true]);
}

if ($type === 'delete') {
    $id = (int) ($body['id'] ?? 0);
    $find = $pdo->prepare('SELECT name FROM announcement_templates WHERE id = ?');
    $find->execute([$id]);
    $name = $find->fetchColumn();
    if ($name === false) {
        jsonResponse(['success' => false, 'error' => 'That template no longer exists. Refresh the page.'], 404);
    }
    $pdo->prepare('DELETE FROM announcement_templates WHERE id = ?')->execute([$id]);
    AuditLogger::log($user['id'], $user['role'], 'delete_announcement_template', 'announcement_template', (string) $id, (string) $name);
    jsonResponse(['success' => true]);
}

jsonResponse(['success' => false, 'error' => 'Unknown action.'], 400);
