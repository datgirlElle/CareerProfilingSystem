# CBF + Cosine Similarity: Finalization Checklist

Everything below must be ticked before stating that the Content-Based Filtering (CBF) and
Cosine Similarity components are **finalized**.

- **Section A** needs the adviser's decision.
- **Sections B–G** are the team's work.
- Items already completed are marked ✅. Re-run their checks right before freezing (Section G).

---

## A. Adviser approval of design decisions

Each item is already implemented as described in **"Current implementation"** and can be changed in
`config/cbf.php` if the adviser decides otherwise.

| # | Decision | Current implementation | Approved? | Adviser's note / change |
|---|---|---|---|---|
| A1 | **Course-vector encoding** | Rank-preserving 1.00 / 0.67 / 0.33 for the 1st/2nd/3rd Holland letter, 0 if absent. Presented as an *engineering* choice, not a validated psychological weighting | ☐ Yes ☐ No | |
| A2 | **Student features** | Only the six RIASEC scores [R, I, A, S, E, C]. Strand and electives are collected but not used in matching | ☐ Yes ☐ No | |
| A3 | **Score scaling** | Raw score (10–50) ÷ 50 → 0–1. Cosine values are identical to using the raw scores. Alternative: (score − 10) ÷ 40 | ☐ ÷ 50 ☐ (−10) ÷ 40 | |
| A4 | **Similarity measure** | Cosine similarity = (S · C) / (‖S‖ × ‖C‖); 0 when a vector is all zeros | ☐ Yes ☐ No | |
| A5 | **Ranking rule** | All 32 courses ranked by cosine similarity only. The student's stated (worksheet) program gets **no** bonus | ☐ Yes ☐ No | |
| A6 | **Number of recommendations (Top-N)** | N = 3 | ☐ 3 ☐ Other: ___ | |
| A7 | **Tie handling** | Courses with the same Holland code get identical scores. Ties are broken by course list order. The evaluation counts ties as "equally likely" | ☐ Yes ☐ No | |
| A8 | **No similarity threshold** | Every student receives a Top-3. A minimum-score cutoff would have to be chosen from validation data | ☐ Yes ☐ No | |
| A9 | **WEKA comparison model** | Tree classifier to be confirmed (e.g. J48). Same six features; class = ActualCourse | ☐ J48 ☐ Other: ___ | |
| A10 | **Source of ActualCourse (ground truth)** | Must be decided. Grade 11 students have not yet enrolled. Options: follow-up after enrolment, or current college students in the 32 courses | ☐ Follow-up ☐ College students ☐ Other: ___ | |
| A11 | **Primary evaluation metrics** | CBF: Hit Rate@3, MRR (plus Precision@3, Recall@3). WEKA: Accuracy, Precision, Recall, F1, confusion matrix. Fair comparison at Top-1 on the same test students | ☐ Yes ☐ No | |

Adviser: ______________________  Signature: ____________  Date: __________

---

## B. Course data (validator revisions)

- [x] ✅ 32 courses stored with **FinalHollandCode** (validator revision, August 7, 2026), used for the course vectors
- [x] ✅ **OriginalHollandCode** kept for audit (`programs.original_holland_code_enc`, `evaluation/course_profiles.csv`)
- [x] ✅ Revisions applied in the system: Tourism Management EAS → **SEA**; Computer Engineering RIC → **ICR**
- [ ] Confirm the two disputed **original** codes with the validator's signed revision sheet:
  - BSBA Sustainability Management: original **ESI** (as stated) or **EIS** (crosswalk)? → ______
  - BS Tourism Management: original **ESA** (as stated) or **EAS** (crosswalk)? → ______
- [ ] Record the validator's name, role and revision date for Chapter 3
- [ ] Use one naming convention for course names in ActualCourse data. The system uses "BS Multimedia Arts" and "BS Business Administration Major in …"

## C. Implementation verification

- [x] ✅ PHP engine tests: `php tests/cbf_test.php` → **59 passed, 0 failed** (run on the team's PC)
- [x] ✅ Evaluation tests: `python -m unittest evaluation/test_cbf_eval.py` → **16 tests OK**
- [x] ✅ The web app and the evaluation script give identical rankings (checked on 1,000 random students)
- [x] ✅ Browser test: a student completed the assessment and worksheet, and the trace was checked by hand (BS Accountancy, cosine 0.6989)
- [x] ✅ The stated program receives no bonus (the test student's stated program ranked #32 on cosine alone)
- [ ] Browser test with a student who has a **clear** interest profile (strong in 1–2 types), to demonstrate a well-separated ranking
- [ ] Confirm the Career Results page shows the same Top-3 and percentages as `php db/cbf_debug.php <school_id>`
- [ ] If any A-decision changes: update `config/cbf.php` and re-run all the tests above

## D. Documentation (Chapter 3)

- [ ] Describe the process: student scores → student vector → course vectors → cosine similarity → ranking → Top-3
- [ ] Include the full table of 32 courses: original code, final code and vector (`evaluation/METHODOLOGY.md` §3)
- [ ] Include one worked example by hand (e.g. the BS Accountancy calculation)
- [ ] Separate **RIASEC theory** from **engineering decisions** (encoding values, scaling, ties)
- [ ] State the limitations:
  - the encoding values are design choices
  - only 22 distinct codes for 32 courses, so some courses are indistinguishable
  - profiles where all six scores are similar produce scores that are close together
  - the questionnaire's validity

## E. Evaluation (Chapter 4). Needs real data.

- [ ] Ethics/consent and Data Privacy Act (RA 10173) approval for collecting student data
- [ ] Collect `student_profiles.csv`: StudentID (pseudonymous), R, I, A, S, E, C, ActualCourse
- [ ] Report the sample size and the number of students per course
- [ ] `python evaluation/cbf_eval.py split --students student_profiles.csv` (fixed seed 42)
- [ ] **Weight validation** on the training split only: `python evaluation/cbf_eval.py weights --students evaluation/train.csv --k 3`
  - compares Binary / 1.00-0.50-0.25 / 1.00-0.75-0.50 / **1.00-0.67-0.33**
  - decision rule fixed in advance (METHODOLOGY.md §10)
- [ ] CBF on the test split: `python evaluation/cbf_eval.py cbf --students evaluation/test.csv --k 3` → `cbf_results.csv`
- [ ] WEKA tree classifier: train on `train.arff`, test on `test.arff`. Record the WEKA version, parameters, metrics and confusion matrix
- [ ] Fair comparison table on the same test students:
  - Top-1 accuracy, precision, recall and F1 for both models
  - Hit@3 for both (WEKA via class probabilities)
  - random and most-frequent-course baselines
- [ ] Significance test (McNemar at Top-1; bootstrap confidence intervals for MRR / Hit@3)

## F. Security and deployment

- [ ] Change the admin password from the default `ChangeMe123!`
- [ ] Generate a new `APP_AES_KEY`, and change the Render database password. Earlier values were exposed during setup.
- [ ] Confirm `.env` is not tracked by Git: `git check-ignore -v .env` and `git status`
- [ ] Back up or export the Render database before **November 4, 2026** (free database expiry), or upgrade the plan
- [ ] If deploying online: run the migrations on the live database
  - `migrate_add_program_related_strands.php`
  - `migrate_final_holland_codes.php`

## G. Freeze the final version

- [ ] All Section A items approved, and any changes applied
- [ ] Sections B–D complete; C re-run with all tests passing
- [ ] Create the Git tag `cbf-final-v1.0` on `main`, and cite it in the thesis as the version that produced the results
- [ ] After freezing: any change to the CBF requires a new tag (e.g. `cbf-final-v1.1`) and re-running Section E

---

**Statement allowed after A–D and G:** "The Content-Based Filtering and Cosine Similarity components
have been implemented, verified, and finalized according to the approved methodology."

**After E:** performance results and the comparison with the WEKA model may be reported.
