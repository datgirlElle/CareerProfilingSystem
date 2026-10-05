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
    // Methodology: the CBF compares the student's six RIASEC scores with each
    // course's Holland code ONLY (same features as the WEKA comparison model).
    // Strand and electives are still collected but do not affect the ranking.
    'weights' => [
        'riasec'    => 1.0,
        'strand'    => 0.0,
        'electives' => 0.0,
    ],

    // Final Match Score = similarity * cosine + stated_program * indicator,
    // where indicator = 1 for the program the student stated on the Career
    // Worksheet (0 otherwise).
    //
    // Methodology: courses are ranked by cosine similarity alone, so the
    // stated program gets no bonus (it is still shown to the student for
    // comparison). The earlier formula was 0.70 / 0.30.
    'final_score' => [
        'similarity'     => 1.0,
        'stated_program' => 0.0,
    ],

    // Number of recommended courses returned to the student (Top-N).
    'top_n' => 3,

    // Student scores -> 0-1 scale. Each RIASEC dimension has 10 items answered
    // 1-5, so a raw total is 10..50.
    //   false: score / riasec_max           (10..50 -> 0.20..1.00). Cosine values
    //          are identical to using the raw totals.
    //   true : (score - floor) / (max - floor) (10..50 -> 0..1). This changes
    //          cosine values; it is a sensitivity option for the thesis.
    'riasec_subtract_floor' => false,
    'riasec_floor'          => 10,
    'riasec_max'            => 50,

    // How the student's RIASEC result becomes the student vector:
    //   'top_binary': the student's top N RIASEC types (by score) = 1 ("x"),
    //                 the others = 0, e.g. top C, I, E -> [0, 1, 0, 0, 1, 1].
    //                 Ties keep the R, I, A, S, E, C order (same rule as the
    //                 assessment's stored top types).
    //   'scores'    : the six scores scaled to 0-1 (see the scaling settings above).
    // Adviser's decision: top-3 binary, compared with the course's binary letters.
    'student_vector' => 'top_binary',
    'student_top_n'  => 3,

    // A course's 3-letter Holland code -> RIASEC vector, weight per letter position
    // (1st, 2nd, 3rd); letters not in the code are 0.
    //
    // Binary encoding: a dimension is 1 if its letter appears in the code (YES)
    // and 0 if not (NO), e.g. IRC -> [1, 1, 0, 0, 0, 1]. Letter ORDER is not
    // used, so codes with the same three letters (IRC, RIC, ICR) get the same
    // vector. Previous rank-preserving encoding: [1.00, 0.67, 0.33].
    // Alternatives are compared in evaluation/ (cbf_eval.py weights).
    'holland_rank_weights' => [1, 1, 1],

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
