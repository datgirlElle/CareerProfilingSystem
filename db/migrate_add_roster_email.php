<?php
// Adds the student's email to the class roster (Student Number, Student Name, Email, Section).
// Safe to re-run.

require_once __DIR__ . '/../lib/Database.php';

Database::get()->exec('ALTER TABLE assessment_roster ADD COLUMN IF NOT EXISTS email VARCHAR(255)');

echo "assessment_roster.email added (or already present).\n";