<?php
// The question bank is no longer fixed at 10 questions per type: staff can add and remove questions, so a type's
// order numbers can go past 10. Replaces the old "order_index BETWEEN 1 AND 10" rule with "order_index >= 1". Safe to re-run.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec(<<<'SQL'
DO $$
DECLARE c text;
BEGIN
    FOR c IN SELECT conname FROM pg_constraint
             WHERE conrelid = 'assessment_questions'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) ILIKE '%order_index%'
    LOOP
        EXECUTE format('ALTER TABLE assessment_questions DROP CONSTRAINT %I', c);
    END LOOP;
END $$;
SQL);
$pdo->exec('ALTER TABLE assessment_questions ADD CONSTRAINT assessment_questions_order_index_check CHECK (order_index >= 1)');
echo "assessment_questions.order_index may now go above 10.\n";
