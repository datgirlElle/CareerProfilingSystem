<?php

/**
 * A student's result "matches" when the program the career on their worksheet
 * leads to is among the programs RIASEC put at the top for them; otherwise it
 * is a mismatch and they are asked to see the Guidance Office. A career that
 * could not be linked to any program is a mismatch too. A student who has not
 * done the worksheet yet has no recommendation, so there is nothing to compare.
 */
class Mismatch
{
    /** How many top programs count as "Top Matches". */
    public const TOP_COUNT = 3;

    /**
     * @param array<int,array{programId:int,score:float}> $scores the stored per-program scores of the recommendation
     */
    public static function isMismatch(?int $statedProgramId, array $scores): bool
    {
        if ($statedProgramId === null) {
            return true;
        }
        usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_map(fn($s) => (int) $s['programId'], array_slice($scores, 0, self::TOP_COUNT));
        return !in_array($statedProgramId, $top, true);
    }

    /**
     * The student ids (user ids) whose latest recommendation is a mismatch.
     *
     * @return array<int,true> keyed by user id
     */
    public static function studentIds(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT DISTINCT ON (student_id) student_id, stated_program_id, scores
             FROM recommendations ORDER BY student_id, computed_at DESC'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $scores = json_decode((string) $r['scores'], true) ?: [];
            $stated = $r['stated_program_id'] !== null ? (int) $r['stated_program_id'] : null;
            if (self::isMismatch($stated, $scores)) {
                $out[(int) $r['student_id']] = true;
            }
        }
        return $out;
    }
}
