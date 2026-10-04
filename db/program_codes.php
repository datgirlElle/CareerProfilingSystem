<?php
/**
 * The 32 MMCL programs and their Holland codes — single source for
 * db/seed_programs.php (fresh installs) and db/migrate_final_holland_codes.php
 * (existing databases).
 *
 *   [college code, program title as stored in `programs`, original code, final code]
 *
 * original = RIASEC_Program_Crosswalk.docx (the codes first seeded).
 * final    = validator revision of August 7, 2026 — the code the CBF uses.
 * Revised: BS Tourism Management (EAS -> SEA), BS Computer Engineering (RIC -> ICR).
 * Must stay in step with evaluation/cbf_eval.py's COURSES list.
 */
return [
    ['CAS',   'BA Communication', 'AES', 'AES'],
    ['CAS',   'BS Multimedia Arts', 'AER', 'AER'],
    ['CCIS',  'BS Computer Science', 'IRC', 'IRC'],
    ['CCIS',  'BS Information Technology', 'IRC', 'IRC'],
    ['CHS',   'BS Biology', 'IRS', 'IRS'],
    ['CHS',   'BS Medical Technology', 'IRC', 'IRC'],
    ['CHS',   'BS Pharmacy', 'ISC', 'ISC'],
    ['CHS',   'BS Physical Therapy', 'SIR', 'SIR'],
    ['CHS',   'BS Psychology', 'SIA', 'SIA'],
    ['CN',    'BS Nursing', 'SIR', 'SIR'],
    ['ETYCB', 'BS Accountancy', 'CEI', 'CEI'],
    ['ETYCB', 'BS Accounting Information System', 'CIE', 'CIE'],
    ['ETYCB', 'BS Business Administration Major in Financial Management', 'ECI', 'ECI'],
    ['ETYCB', 'BS Business Administration Major in Operations Management', 'EIC', 'EIC'],
    ['ETYCB', 'BS Business Administration Major in Sustainability Management', 'EIS', 'EIS'],
    ['ETYCB', 'BS Hospitality Management', 'ESC', 'ESC'],
    ['ETYCB', 'BS Tourism Management', 'EAS', 'SEA'],
    ['ETYCB', 'BS International Business', 'ECS', 'ECS'],
    ['ETYCB', 'BS Business Analytics with Artificial Intelligence', 'IEC', 'IEC'],
    ['ETYCB', 'BS Marketing', 'EAS', 'EAS'],
    ['MITL',  'BS Architecture', 'ARI', 'ARI'],
    ['MITL',  'BS Chemical Engineering', 'IRC', 'IRC'],
    ['MITL',  'BS Civil Engineering', 'RIC', 'RIC'],
    ['MITL',  'BS Mechanical Engineering', 'RIC', 'RIC'],
    ['MITL',  'BS Electrical Engineering', 'RIC', 'RIC'],
    ['MITL',  'BS Electronics Engineering', 'RIC', 'RIC'],
    ['MITL',  'BS Industrial Engineering', 'REC', 'REC'],
    ['MITL',  'BS Computer Engineering', 'RIC', 'ICR'],
    ['MIA',   'BS Aeronautical Engineering', 'RIC', 'RIC'],
    ['MIA',   'BS Aviation Management', 'ERC', 'ERC'],
    ['CMET',  'BS Marine Engineering', 'RIC', 'RIC'],
    ['CMET',  'BS Marine Transportation', 'REC', 'REC'],
];
