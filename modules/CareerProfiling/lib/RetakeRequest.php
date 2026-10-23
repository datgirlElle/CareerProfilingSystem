<?php

require_once __DIR__ . '/../../Encryption/lib/Crypto.php';

/**
 * Retake by request. A student who has finished the assessment can ask the Guidance Office to take it again; a Guidance
 * Counselor or the Head of Guidance approves or declines. An approved request lets the student retake once, without a
 * session or access code (the approval is the authorization). The earlier result is never deleted: it stays as an older
 * attempt on the student's record, and only recommendations built from the latest assessment count.
 */
class RetakeRequest
{
    public const REASON_MIN = 10;
    public const REASON_MAX = 500;
    public const NOTE_MAX = 300;

    /**
     * @return array{error:?string,text:string}
     */
    public static function cleanReason(string $reason): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $reason));
        $len = mb_strlen($text);
        if ($len < self::REASON_MIN) {
            return ['error' => 'Tell the Guidance Office why you would like to retake the assessment (at least ' . self::REASON_MIN . ' characters).', 'text' => ''];
        }
        if ($len > self::REASON_MAX) {
            return ['error' => 'Please keep the reason to ' . self::REASON_MAX . ' characters or fewer.', 'text' => ''];
        }
        return ['error' => null, 'text' => $text];
    }

    /** The student's request that is waiting or approved, or null. */
    public static function openFor(PDO $pdo, int $studentId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM retake_requests WHERE student_id = ? AND status IN ('pending', 'approved') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$studentId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * What the student sees: 'none', 'pending', 'approved' or 'declined' (the latest request, unless it was already used).
     *
     * @return array{status:string,requestedAt:?string,decidedAt:?string,note:?string}
     */
    public static function stateFor(PDO $pdo, int $studentId): array
    {
        $stmt = $pdo->prepare('SELECT status, requested_at, decided_at, decision_note_enc FROM retake_requests WHERE student_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$studentId]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] === 'completed') {
            return ['status' => 'none', 'requestedAt' => null, 'decidedAt' => null, 'note' => null];
        }
        $note = $row['decision_note_enc'] !== null ? trim((string) Crypto::dec($row['decision_note_enc'])) : '';
        return [
            'status' => $row['status'],
            'requestedAt' => $row['requested_at'],
            'decidedAt' => $row['decided_at'],
            'note' => $note !== '' ? $note : null,
        ];
    }

    /** True when the student has an approved retake waiting to be used. */
    public static function isApproved(PDO $pdo, int $studentId): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM retake_requests WHERE student_id = ? AND status = 'approved' LIMIT 1");
        $stmt->execute([$studentId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * SQL fragment: a recommendation counts only when it was built from the student's latest assessment. After a retake,
     * the recommendation from the earlier attempt no longer applies (it stays in the table as history).
     */
    public const LATEST_ONLY = 'source_assessment_id IN (SELECT id FROM assessments WHERE is_latest = TRUE)';
}