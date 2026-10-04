<?php
/**
 * One-time backfill: gives every seeded program a starter list of careers
 * (programs.careers). Programs are matched by decrypted title, so it works
 * whatever ids they have. By default only programs whose list is still empty
 * are filled, so careers staff have edited in Career Data Set are never
 * overwritten. Safe to re-run.
 *
 *   php db/backfill_program_careers.php               fill empty lists only
 *   php db/backfill_program_careers.php --overwrite   reset EVERY program to these starter lists
 *                                                     (discards staff edits — use after changing this file)
 *
 * These are starter lists for the counselor to review and edit. They also feed
 * the Career Worksheet: a career a student types is linked to the program whose
 * list (or title) it matches (lib/CareerMatcher.php), so common job names that
 * graduates of a program actually take are worth listing here (max 8 each).
 */
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';
require_once __DIR__ . '/../lib/Careers.php';

$overwrite = in_array('--overwrite', $argv ?? [], true);

$careersByProgram = [
    'BA Communication' => ['Public Relations Officer', 'Broadcast Journalist', 'Journalist', 'Content Creator', 'Content Strategist', 'Corporate Communications Specialist', 'Social Media Manager', 'Radio Host'],
    'BS Multimedia Arts' => ['Graphic Designer', 'Animator', 'Video Editor', 'UI/UX Designer', 'Illustrator', 'Photographer', 'Game Artist', 'Motion Graphics Artist'],
    'BS Computer Science' => ['Software Engineer', 'Programmer', 'Game Developer', 'Data Scientist', 'Machine Learning Engineer', 'Systems Analyst', 'Mobile App Developer', 'AI Engineer'],
    'BS Information Technology' => ['Web Developer', 'Network Administrator', 'IT Support Specialist', 'Cybersecurity Analyst', 'Database Administrator', 'Systems Administrator', 'Cloud Engineer'],
    'BS Biology' => ['Research Scientist', 'Biotechnologist', 'Biologist', 'Microbiologist', 'Environmental Scientist', 'Wildlife Biologist', 'Laboratory Analyst'],
    'BS Medical Technology' => ['Medical Technologist', 'Medical Laboratory Scientist', 'Clinical Laboratory Scientist', 'Pathology Laboratory Analyst', 'Blood Bank Technologist'],
    'BS Pharmacy' => ['Pharmacist', 'Clinical Pharmacist', 'Hospital Pharmacist', 'Pharmaceutical Researcher', 'Regulatory Affairs Officer', 'Drug Safety Specialist'],
    'BS Physical Therapy' => ['Physical Therapist', 'Physiotherapist', 'Rehabilitation Specialist', 'Sports Therapist', 'Wellness Coach'],
    'BS Psychology' => ['Psychologist', 'Psychometrician', 'Guidance Counselor', 'Human Resources Specialist', 'Behavioral Analyst', 'Research Assistant'],
    'BS Nursing' => ['Registered Nurse', 'Community Health Nurse', 'Critical Care Nurse', 'Nurse Educator', 'Clinical Nurse Specialist'],
    'BS Accountancy' => ['Certified Public Accountant', 'Accountant', 'Auditor', 'Tax Specialist', 'Forensic Accountant', 'Financial Controller'],
    'BS Accounting Information System' => ['Systems Auditor', 'IT Auditor', 'Accounting Systems Analyst', 'ERP Consultant', 'Financial Analyst'],
    'BS Business Administration Major in Financial Management' => ['Financial Analyst', 'Investment Banker', 'Bank Manager', 'Credit Analyst', 'Financial Planner', 'Treasury Officer'],
    'BS Business Administration Major in Operations Management' => ['Operations Manager', 'Supply Chain Analyst', 'Logistics Coordinator', 'Production Planner', 'Procurement Officer'],
    'BS Business Administration Major in Sustainability Management' => ['Sustainability Manager', 'ESG Analyst', 'Environmental Consultant', 'Corporate Social Responsibility Officer'],
    'BS Hospitality Management' => ['Hotel Manager', 'Restaurant Manager', 'Front Office Manager', 'Chef', 'Event Planner', 'Restaurant Owner', 'Cruise Ship Hospitality Officer'],
    'BS Tourism Management' => ['Travel Consultant', 'Travel Agent', 'Tour Operator', 'Tour Guide', 'Destination Manager', 'Flight Attendant', 'Airline Customer Service Officer'],
    'BS International Business' => ['International Trade Specialist', 'Export Manager', 'Business Development Manager', 'Foreign Market Analyst'],
    'BS Business Analytics with Artificial Intelligence' => ['Business Analyst', 'Data Analyst', 'Data Engineer', 'AI Specialist', 'Business Intelligence Developer', 'Machine Learning Analyst'],
    'BS Marketing' => ['Marketing Manager', 'Brand Manager', 'Digital Marketing Specialist', 'Market Researcher', 'Advertising Executive', 'Sales Manager'],
    'BS Architecture' => ['Architect', 'Licensed Architect', 'Architectural Designer', 'Interior Designer', 'Urban Planner', 'Landscape Architect'],
    'BS Chemical Engineering' => ['Chemical Engineer', 'Process Engineer', 'Quality Control Engineer', 'Environmental Engineer', 'Petrochemical Engineer'],
    'BS Civil Engineering' => ['Civil Engineer', 'Structural Engineer', 'Construction Manager', 'Site Engineer', 'Geotechnical Engineer', 'Transportation Engineer'],
    'BS Mechanical Engineering' => ['Mechanical Engineer', 'HVAC Engineer', 'Manufacturing Engineer', 'Mechatronics Engineer', 'Automotive Engineer', 'Maintenance Engineer'],
    'BS Electrical Engineering' => ['Electrical Engineer', 'Power Systems Engineer', 'Controls Engineer', 'Electrical Design Engineer', 'Electrical Maintenance Engineer'],
    'BS Electronics Engineering' => ['Electronics Engineer', 'Telecommunications Engineer', 'Embedded Systems Engineer', 'Semiconductor Test Engineer', 'Robotics Engineer'],
    'BS Industrial Engineering' => ['Industrial Engineer', 'Production Manager', 'Quality Assurance Manager', 'Process Improvement Analyst', 'Supply Chain Engineer'],
    'BS Computer Engineering' => ['Computer Engineer', 'Embedded Systems Developer', 'Firmware Engineer', 'Hardware Engineer', 'Network Engineer', 'Robotics Engineer'],
    'BS Aeronautical Engineering' => ['Aeronautical Engineer', 'Aerospace Engineer', 'Aircraft Maintenance Engineer', 'Aerospace Design Engineer', 'Flight Test Engineer'],
    'BS Aviation Management' => ['Airport Operations Manager', 'Airline Operations Officer', 'Airline Manager', 'Air Traffic Coordinator', 'Aviation Safety Officer'],
    'BS Marine Engineering' => ['Marine Engineer', 'Ship Engineer Officer', 'Offshore Systems Engineer', 'Port Engineer'],
    'BS Marine Transportation' => ['Deck Officer', 'Navigation Officer', 'Ship Captain', 'Merchant Marine Officer', 'Port Operations Officer', 'Maritime Safety Inspector'],
];

$pdo = Database::get();
$rows = $pdo->query('SELECT id, title_enc, careers FROM programs')->fetchAll();

$update = $pdo->prepare('UPDATE programs SET careers = ?::text[], updated_at = NOW() WHERE id = ?');
$filled = 0;
$skipped = 0;
$unmatched = [];

foreach ($rows as $row) {
    $title = Crypto::dec($row['title_enc']);
    if (!$overwrite && Careers::parse($row['careers']) !== []) {
        $skipped++;
        continue;
    }
    if (!isset($careersByProgram[$title])) {
        $unmatched[] = $title;
        continue;
    }
    $update->execute([Careers::toLiteral(Careers::normalize($careersByProgram[$title])), (int) $row['id']]);
    $filled++;
}

echo "Careers filled for $filled programs; $skipped already had careers (left alone).\n";
if ($unmatched) {
    echo 'No starter list for: ' . implode('; ', $unmatched) . "\n";
}
