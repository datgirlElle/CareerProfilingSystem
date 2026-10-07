<?php
// Stores each stage of the recommendation pipeline (lib/RecommendationPipeline.php)
// with every saved recommendation:
//   cbf_program_ids         CBF candidates (highest cosine similarity)
//   prediction_program_ids  prediction model output (NULL while the model is not enabled)
//   final_program_ids       Best Match shown to the student (CBF candidates ∩ worksheet)
//   model_version           imported WEKA model used (NULL while not enabled)
// Existing rows stay NULL until db/recompute_recommendations.php is run.
// Safe to run more than once.

require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec("ALTER TABLE recommendations ADD COLUMN IF NOT EXISTS cbf_program_ids INT[]");
$pdo->exec("ALTER TABLE recommendations ADD COLUMN IF NOT EXISTS prediction_program_ids INT[]");
$pdo->exec("ALTER TABLE recommendations ADD COLUMN IF NOT EXISTS final_program_ids INT[]");
$pdo->exec("ALTER TABLE recommendations ADD COLUMN IF NOT EXISTS model_version VARCHAR(80)");

echo "recommendations: cbf_program_ids, prediction_program_ids, final_program_ids, model_version added (or already present).\n";
