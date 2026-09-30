<?php
/**
 * Content-Based Filtering (CBF) configuration — the ONE place where the
 * recommendation engine's weights and feature vocabularies are defined.
 * lib/CBFEngine.php reads everything from here; nothing is hard-coded there.
 *
 * Feature blocks (each becomes one section of the student/program vectors):
 *   riasec    — 6 dims: R, I, A, S, E, C
 *   strand    — one dim per SHS strand in 'strands'
 *   electives — one dim per elective in 'elective_clusters'
 *
 * Changing a weight or vocabulary here only affects recommendations computed
 * AFTER the change; saved snapshots in the `recommendations` table are kept
 * as they were computed.
 */
return [
    // Relative importance of each feature block inside the cosine similarity.
    // Only the ratios matter (the engine rescales them to sum to 1). A weight
    // of 0 removes that block from the vectors entirely.
    //
    // PENDING THESIS DECISION: equal weights = every attribute group counts
    // the same (the plain, unweighted form of block-normalised cosine
    // similarity). Replace with the values documented in Chapter 3 once final.
    'weights' => [
        'riasec'    => 1.0,
        'strand'    => 1.0,
        'electives' => 1.0,
    ],

    // Final Match Score = similarity * cosine + stated_program * indicator,
    // where indicator = 1 for the program the student stated on the Career
    // Worksheet (0 otherwise). 0.70 / 0.30 is the existing Chapter 3 formula.
    'final_score' => [
        'similarity'     => 0.70,
        'stated_program' => 0.30,
    ],

    // PENDING THESIS DECISION: each RIASEC dimension has 10 items answered
    // 1-5, so every score is at least 10. When true, that floor is subtracted
    // (score 10..50 -> 0..40) so a dimension the student rated all-"1" counts
    // as 0 interest instead of 10. false = original raw-score behaviour.
    'riasec_subtract_floor' => false,
    'riasec_floor'          => 10,

    // A program's 3-letter Holland code -> RIASEC vector: 1st letter = 3,
    // 2nd = 2, 3rd = 1, absent letters = 0 (the project's existing rule).
    'holland_rank_weights' => [3, 2, 1],

    // SHS strands offered (same list as the students.strand CHECK constraint).
    'strands' => ['STEM', 'ABM', 'ICT', 'HUMSS'],

    // MMCL SHS elective clusters (moved here from career-worksheet.html so the
    // worksheet and the engine share one list). Every elective listed here is
    // one dimension of the electives block.
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

    // College -> electives that support its programs (moved unchanged from
    // career-worksheet.html). This is a program's elective profile: every
    // program inherits the electives of the college it belongs to.
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
