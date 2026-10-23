# ProfilePath: methodology notes for the system and the manuscript

ProfilePath supports career exploration and counselor guidance for MMCL Senior High School
students. It does not decide admission eligibility and does not predict academic success.

Objectives this document serves:

1. Centralize student profiles, RIASEC assessments, worksheets and counseling records.
2. Recommend MMCL programs with content-based filtering (CBF, cosine similarity), compare them with
   the student's Preferred Course, and show the careers associated with each program.
3. Protect sensitive information with the existing encryption (Modified AES-256) and access controls.

---

## 1. Assessment scoring

Production uses the 30-question O\*NET Mini Interest Profiler item set, 5 questions per RIASEC type
(`modules/CareerProfiling/lib/QuestionBank.php`). Staff can change the question bank; the assessment opens only while every
type has the same number of active questions (at least 3).

Keep these values distinct:

| Value | What it is | Range (5 per type) | Where |
|---|---|---|---|
| Item response | One answer: 1 Dislike, 2 Slightly Dislike, 3 Neutral, 4 Slightly Enjoy, 5 Enjoy | 1–5 | sent by `modules/CareerProfiling/pages/riasec-assessment.html`, stored per question in `assessments.answers_enc` |
| Raw total | Sum of a type's responses | 5–25 (n to 5n for n questions) | computed in `AssessmentVersion::score()` |
| Stored score | `QuestionBank::scaleScore`: 10 × raw ÷ n, rounded half up | 10–50 | `assessments.score_r … score_c` |
| Student vector | Stored score ÷ 10 (mean response) | 1.0–5.0 | `CBFEngine::studentVector()` |
| External O\*NET scores | Scores shown by the O\*NET website's own Interest Profiler | different instrument and coding | never converted into, or mixed with, the values above |

- **5 per type:** stored score = raw × 2 exactly.
- **10 per type:** stored = raw.
- **Other counts** are rounded half up, e.g. 4 per type: raw 5 → 12.5 → 13; 3 per type: raw 4 → 13.33 → 13.
- **Defect fixed (2026-10-23):** the earlier floating-point version rounded 14.5 and 28.5 down for 20 questions per type. Every other count from 3 to 20 gave the same results, and the 5-per-type production scoring is unchanged.

Any conversion between instruments (for example, O\*NET website scores to the application's 10–50
scale) must state the instrument version, the response coding and its assumptions. None is
implemented, and historical records are not converted.

## 2. Reproducible assessments

`modules/CareerProfiling/lib/AssessmentVersion.php`, migration `db/migrate_add_assessment_versions.php`.

- **Question sets.** A question set is an immutable snapshot of the active questions shown: id, type, exact wording and order. Its id is tied to the SHA-256 fingerprint of that snapshot, so editing, adding, hiding or reordering a question creates a new set; an existing set never changes.
- **Snapshot storage.** The snapshot is stored encrypted (`assessment_question_sets.snapshot_enc`).
- **What the student receives.** `modules/CareerProfiling/api/assessment-questions.php` gives the student the set id. Saved progress (`modules/CareerProfiling/lib/AssessmentProgress.php`) records the question ids shown, so a student who signs in again continues the same questions.
- **Submission.** `modules/CareerProfiling/api/assessment-submit.php` accepts `{questionSetId, answers: [{questionId, answer}]}`. The set must still be the active one: its fingerprint must match the questions active now. It is not tied to the sign-in session, so continuing from saved progress after signing in again still works. Every question must be answered once, with an integer from 1 to 5. Anything else is refused: a missing, duplicate or foreign answer, or an old page sending positional answers. Access-code and retake rules are unchanged.
- **What each attempt stores.** Its `question_set_id`, its answers (encrypted, by question id, in the order shown) and `scoring_version` (`riasec-10-50-v1`). `AssessmentVersion::reproduce()` re-scores an attempt from storage.
- **Legacy attempts.** Attempts saved before this change have NULL in these columns and are reported as legacy. No set or answers are invented for them; their stored scores remain usable.

## 3. Ranked interests and the ordered Holland code

`modules/CareerProfiling/lib/RiasecProfile.php` is the single implementation used by the assessment result, the results
page, the API, the dataset export and the Prediction Module's inputs.

- **What it reports:** the ranking of all six scores (highest first), plus top 1, top 2, top 3 and the three-letter ordered code.
- **Ties:** equal scores keep the order R, I, A, S, E, C. This convention keeps results compatible with earlier ones; **it does not mean the earlier letter is the stronger interest**.
- **Provisional codes:** the code is marked provisional when equal scores decide its order (`orderTie`) or which letter is third (`thirdPlaceTie`). The results page explains which letters tied.
- **Not a suitability label:** a student's top-three code is not a counselor-approved suitability label and is not used as one.

## 4. The CBF method

`modules/ContentBasedFiltering/lib/CBFEngine.php`, `config/cbf.php`, `db/program_codes.php` (unchanged in this update).

- **Student vector:** the six stored scores ÷ 10.
- **Program vectors:** binary vectors from the counselor-approved three-letter codes (1 for each letter present).
- **Similarity:** cosine similarity with every program.
- **Best RIASEC Match:** every program tied at the highest similarity.
- **Alternative Courses:** every program at the next-highest similarity.
- **Preferred Course:** shown separately and never enters the calculation.

**Limitation:** a binary vector records which interests a code contains, not their priority. IRC, RIC
and ICR have identical vectors and always tie, although their ordered codes differ. Ties are kept;
the system never picks one tied program over another.

**Explanation shown, without changing the method:**
- Each program card shows the approved ordered code and its letters by name.
- When the student's code is not decided by ties, a program whose code has the same letters in the same order is marked "Same letters in the same order as your code". This note changes no cosine value, no order and no recommendation.
- Not having the note is not evidence against a program.
- Position-sensitive ranking (letter-order weights) is **not** implemented; it would need a separately approved method.

**Cosine similarity** is a measure of similarity between two interest patterns. It is not accuracy,
not a probability of success and not a percentage fit, and it is not shown to students.

## 5. Flat and insufficiently differentiated profiles

- **All six scores equal:** the student vector points equally at every interest. Every three-letter binary program vector then has cosine 3 ÷ (√6 × √3) ≈ 0.7071, so all 32 programs tie. A high similarity value alone therefore does not mean a clear recommendation.
- **Detected and handled** (`RiasecProfile`, results page):
  - **flat** (all equal) and **all-minimum** (every answer "Dislike"): all tied programs are still listed, with a message that the answers do not single out programs and a link to a Guidance Counselor;
  - **ties that decide the code:** the code is marked provisional and the tie is explained;
  - **missing or invalid scores:** refused with a reason; no value is substituted.
- **Records are kept.** The assessment record is kept and the student is not excluded from any evaluation.
- **Pending decision:** broader "low differentiation" thresholds (e.g. a small spread between the highest and lowest scores) are **not** implemented. They need counselor validation before any threshold is used.

## 6. Prediction Module (Weka J48), staff only

`modules/ContentBasedFiltering/lib/PredictionModel.php`, `config/prediction_model.json`, `tools/weka/` (see `tools/weka/README.md`).

- **What it predicts:** the student's stated **intended/preferred course** (dataset column "Actual Course"). Not verified enrolment, and not an independently assessed suitable course.
- **Inputs:** `top3-binary`, i.e. 1 for each of the three highest RIASEC scores (ties in R, I, A, S, E, C order), 0 otherwise. This allows only 20 input patterns.
- **How it runs:** the deployment holds Weka's own prediction and class probabilities for those 20 patterns.
- **Raw-score models cannot be deployed this way:**
  - the export requires `--encoding top3-binary`;
  - it refuses a tree whose splits do not fit 0/1 inputs (e.g. `C <= 12`);
  - the application refuses such a file at run time.
- **What staff see:** the model's encoding, target, version, training record count (as declared by the team) and evaluation protocol. Anything not recorded is shown as unknown.
- **Students never receive it.** It never changes the CBF results or the Preferred Course.

## 7. What each evaluation shows

| Evaluation | What it establishes | What it does not establish |
|---|---|---|
| Scoring and implementation correctness (tests, worked examples) | The software computes scores, codes, cosine values and ties as specified | That any recommendation suits a student |
| Agreement with the approved program mappings | Program vectors match the counselor-approved codes | Independent validation of the codes or the method |
| Intended-course prediction (Weka J48 accuracy, top-3, MRR vs ZeroR) | How well RIASEC top-three patterns predict students' stated intended courses | Suitability; agreement with counselors; academic success |
| Independent counselor suitability evaluation (not yet done) | Whether counselors, judging independently, find the recommended programs appropriate | Admission or success outcomes |
| Cosine similarity | How similar a student's interest pattern is to a program code | Accuracy, probability or percentage fit |

Outputs derived from the approved mappings must not be reported as independent counselor
validation. No claim of 80% accuracy is supported: that would need a defined target, suitable labels
and an appropriate independent evaluation.

## 8. Manuscript alignment

| Topic | Manuscript (as reviewed) | Repository | Action |
|---|---|---|---|
| CBF program vectors | 3–2–1 weighted vectors by letter position | Binary vectors (letter present = 1) | Correct the method description, formulas and worked examples to binary vectors, or approve and implement the weighted method separately. This update does **not** implement 3–2–1 weights |
| Student vector | (check wording) | Six stored scores ÷ 10 | Describe the actual scores, not top-three only |
| Ties | (check wording) | Ties kept; IRC/RIC/ICR always tie | Add the limitation in §4 |
| Weka inputs | Top-three RIASEC attributes | `top3-binary` model deployed | Describe as top-three binary; describe any raw-score experiment separately as exploratory, not deployed |
| Weka target | (check wording) | Stated intended/preferred course | Do not call it actual or suitable course |
| Evaluation | (check claims) | See §7 | Remove or qualify any 80% accuracy claim; separate the evaluation types |
| Assessment scoring | (check wording) | §1 table | Use the stored 10–50 scale and the formula in §1 |

The section numbers depend on the manuscript version; map these topics to its Methodology
(system design, CBF, prediction model), Results and Discussion chapters.

## 9. References and their limits

The links below were supplied by the team. They could not be opened from the build environment
(network policy), so check each against the source before citing.

- Rounds, J., Hoff, K., & Lewis, P. (2021). *O\*NET Interest Profiler Manual*. National Center for O\*NET Development. https://www.onetcenter.org/dl_files/IP_Manual.pdf
- Gregory, C., & Lewis, P. (2016). *Linking Client Assessment Profiles to O\*NET Occupational Profiles*. National Center for O\*NET Development. https://www.onetcenter.org/dl_files/Mini-IP_Linking.pdf
- Lops, P., de Gemmis, M., & Semeraro, G. (2011). Content-based Recommender Systems: State of the Art and Trends. In *Recommender Systems Handbook* (Springer). https://doi.org/10.1007/978-0-387-85820-3_3

**Limits when citing them:**
- **Interest Profiler Manual:** ranked-code reporting (high-point codes) supports reporting an ordered code. It does not establish numerical weights such as 3–2–1.
- **Linking report:** describes correlation-based matching of client profiles to O\*NET occupational profiles. It is not a validation of this system's binary-cosine program method.
- **Lops et al.:** describes content-based recommendation in general. It supports the CBF framing, not these program codes or results.
