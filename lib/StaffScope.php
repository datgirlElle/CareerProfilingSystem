<?php

/**
 * Which students a guidance counselor / facilitator can see. The administrator assigns sections
 * to each staff member (Account Management); that person then sees only those sections' students
 * on Student Information, Monitoring and the email buttons. The administrator, and any staff
 * member with no sections assigned, see everyone — so no existing account loses access — and so
 * does everyone if the staff_sections table hasn't been created yet.
 */
class StaffScope
{
    /**
     * @param array{id:int,role:string} $user
     * @return array<int,array{strand:string,section:string}>|null null = no restriction
     */
    public static function forUser(PDO $pdo, array $user): ?array
    {
        if ($user['role'] !== 'counselor') {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT strand, section FROM staff_sections WHERE user_id = ?');
            $stmt->execute([(int) $user['id']]);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            return null; // table not created yet
        }
        return $rows ?: null;
    }

    /** @param array<int,array{strand:string,section:string}>|null $scope */
    public static function allows(?array $scope, ?string $strand, ?string $section): bool
    {
        if ($scope === null) {
            return true;
        }
        foreach ($scope as $s) {
            if ($s['strand'] === $strand && strcasecmp((string) $s['section'], (string) $section) === 0) {
                return true;
            }
        }
        return false;
    }
}
