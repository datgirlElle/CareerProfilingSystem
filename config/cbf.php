<?php
/**
 * Content-Based Filtering (CBF) settings. lib/CBFEngine.php reads them from here.
 *
 * The CBF compares the student's RIASEC profile with each program's Holland (RIASEC)
 * code using cosine similarity. There are no weights: no 70/30 split with the worksheet,
 * no 1.00/0.67/0.33 letter-position weights, no strand or elective block.
 */
return [
    // RIASEC scores are stored on a 10-50 scale per type, whatever the number of questions
    // (lib/QuestionBank.php scaleScore; e.g. the 30-item O*NET Mini Interest Profiler, 5 per
    // type), i.e. as if each type had 10 items answered 1-5. The student vector uses each
    // type's mean item score = score / 10 (1.0-5.0). A score outside 10-50 is rejected as
    // invalid instead of being corrected.
    'riasec_items_per_type' => 10,
    'riasec_answer_min'     => 1,
    'riasec_answer_max'     => 5,

    // Used only by db/export_riasec_csv.php (dataset export: x for the top N types).
    // Not used by the CBF.
    'student_top_n' => 3,

    // ---- Prediction model (WEKA) -------------------------------------------------
    // OUT OF SCOPE for the current CBF revision: lib/RecommendationPipeline.php does not
    // read these settings and does not run the prediction model. Left unchanged for the
    // separate WEKA work (lib/PredictionModel.php, db/import_weka_tree.php).
    'decision_tree' => [
        'enabled' => false,
    ],
    'mismatch' => [
        'definition' => 'no_common_course',      // future rule, once the prediction model exists
        'interim'    => 'preferred_not_matched', // rule in use now
    ],

    // ---- Career Electives Worksheet data (NOT used by the CBF) ---------------------
    // SHS strands offered (same list as the students.strand CHECK constraint).
    'strands' => ['STEM', 'ABM', 'ICT', 'HUMSS'],

    // MMCL SHS elective clusters shown on the Career Electives Worksheet.
    'elective_clusters' => [
        'Arts, Social Sciences and Humanities Cluster' => [
            'Introduction to the Philosophy of the Human Person', 'Creative Writing', 'Citizenship and Civic Engagement',
            'Creative Industries (Music, Dance, Theater)', 'Creative Industries (Visual, Media and Traditional Art)', 'The Social Sciences in Theory and Practice',
        ],
        'Business and Entrepreneurship Cluster' => [
            'Introduction to Organization and Management', 'Basic Accounting', 'Business Finance and Income Taxation',
            'Contemporary Marketing and Business Economics', 'Entrepreneurship',
        ],
        'Information and Communication Technology Cluster' => [
            'Animation', 'Contact Center Services', 'Computer Programming (JAVA)',
        ],
        'Sports, Health, and Wellness Cluster' => [
            'Physical Education (Sports and Dance)',
        ],
        'Field Experience' => [
            'Research Methods', 'Design and Innovation',
        ],
        'Science, Technology, Engineering and Mathematics Cluster' => [
            'Biology 1-2', 'Chemistry 1-2', 'Physics 1-2', 'Pre-Calculus 1-2', 'Trigonometry 1-2', 'Earth and Space Science 1-2',
        ],
    ],

    // College -> electives that support its programs; the worksheet uses it to suggest
    // electives for the chosen Preferred Course.
    'college_electives' => [
        'CAS'   => ['Creative Writing', 'Creative Industries (Visual, Media and Traditional Art)', 'Creative Industries (Music, Dance, Theater)', 'Animation'],
        'CCIS'  => ['Computer Programming (JAVA)', 'Design and Innovation', 'Research Methods', 'Contact Center Services'],
        'CHS'   => ['Biology 1-2', 'Chemistry 1-2', 'Research Methods', 'Physical Education (Sports and Dance)'],
        'CN'    => ['Biology 1-2', 'Chemistry 1-2', 'Physical Education (Sports and Dance)', 'Research Methods'],
        'ETYCB' => ['Introduction to Organization and Management', 'Basic Accounting', 'Business Finance and Income Taxation', 'Contemporary Marketing and Business Economics', 'Entrepreneurship'],
        'MITL'  => ['Physics 1-2', 'Pre-Calculus 1-2', 'Trigonometry 1-2', 'Design and Innovation', 'Research Methods'],
        'MIA'   => ['Physics 1-2', 'Trigonometry 1-2', 'Earth and Space Science 1-2', 'Research Methods'],
        'CMET'  => ['Physics 1-2', 'Earth and Space Science 1-2', 'Trigonometry 1-2', 'Research Methods'],
    ],
];
