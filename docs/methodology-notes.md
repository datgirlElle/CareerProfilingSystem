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
| O\*NET Mini-IP scores (study dataset) | The same 30 items answered on O\*NET's own Mini Interest Profiler: each answer 0–4 (Strongly Dislike … Strongly Like), summed per type | 0–20 | the 393-record training dataset; see §1.1 |

- **5 per type:** stored score = raw × 2 exactly.
- **10 per type:** stored = raw.
- **Other counts** are rounded half up, e.g. 4 per type: raw 5 → 12.5 → 13; 3 per type: raw 4 → 13.33 → 13.
- **Defect fixed (2026-10-23):** the earlier floating-point version rounded 14.5 and 28.5 down for 20 questions per type. Every other count from 3 to 20 gave the same results, and the 5-per-type production scoring is unchanged.

### 1.1 The study dataset and ProfilePath use the same questionnaire

The 393 training records were collected with O\*NET's **Mini Interest Profiler (Mini-IP)**, 30 items,
5 per type (confirmed by the team, 2026-10-24). ProfilePath's assessment uses the same 30 items
(`QuestionBank::ONET_MINI_IP`, from the Mini-IP development report). Only the answer coding differs:

| Answer position | O\*NET Mini-IP label | O\*NET value | ProfilePath label | ProfilePath value |
|---|---|---|---|---|
| 1 | Strongly Dislike | 0 | Dislike | 1 |
| 2 | Dislike | 1 | Slightly Dislike | 2 |
| 3 | Unsure | 2 | Neutral | 3 |
| 4 | Like | 3 | Slightly Enjoy | 4 |
| 5 | Strongly Like | 4 | Enjoy | 5 |

Per type (5 items): O\*NET score = sum of the five 0–4 answers (0–20). ProfilePath raw total = O\*NET
score + 5 (5–25), and the stored score = 2 × raw = **2 × O\*NET score + 10** (10–50).

- **Same ranking.** The conversion is a straight increasing line, so the order of the six types,
  and any ties, are the same on both scales. The top three, and so the Prediction Module's 0/1
  inputs, are identical whichever scale is used.
- **Worked example** (a record from the dataset): O\*NET R 3, I 2, A 6, S 1, E 19, C 8 → ProfilePath
  stored R 16, I 14, A 22, S 12, E 48, C 26. Both rank E, C, A, R, I, S, so the top three are E, C, A
  and the inputs are R 0, I 0, A 1, S 0, E 1, C 1.
- **Cosine values differ.** The CBF uses ProfilePath's stored scores ÷ 10 (= 0.2 × O\*NET + 1), which
  is not proportional to the O\*NET score, so a cosine computed from raw O\*NET scores would differ.
  The CBF always uses the application's own scores; no O\*NET scores enter it.
- **Remaining difference:** ProfilePath's answer labels are reworded (e.g. "Neutral" for "Unsure",
  "Slightly Enjoy" for "Like"). The five positions are the same, but students may read the labels
  slightly differently (minor limitation).
- **Where the scoring is documented:** the Mini-IP development report and the O\*NET Interest
  Profiler Manual (§9). The conversion is documented here; no stored records are converted.

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

`modules/ContentBasedFiltering/lib/CBFEngine.php`, `config/cbf.php`, `db/program_codes.php`.

- **Student vector:** the six stored scores ÷ 10.
- **Program vectors:** binary vectors from the counselor-approved three-letter codes (1 for each letter present).
- **Similarity:** cosine similarity with every program.
- **Best RIASEC Match:** every program tied at the highest similarity.
- **Alternative Courses:** every program at the next-highest similarity.
- **Order inside a tie (1-2-3 letter ranking):** programs with the same similarity are listed by the
  counselors' letter order. A program whose 1st letter is the student's 1st-ranked interest comes
  first, then one whose 2nd letter matches the 2nd, then the 3rd (compared in that order), then the
  program-list order (`CBFEngine::letterOrderMatch`).
  - Equal scores share a rank, so a tie between the student's scores favours neither letter.
    For a flat profile every code matches, so the list order is kept.
  - It changes no cosine value and no membership: every tied program stays in Best Match or
    Alternatives. There are no numeric weights.
- **Preferred Course:** shown separately and never enters the calculation.

**Worked example (student S = 45, I = 40, R = 30, C = 25, A = 20, E = 15; code SIR):**
- Student vector [3.0, 4.0, 2.0, 4.5, 1.5, 2.5]; ‖S‖ = √57.75 = 7.5993.
- BS Nursing (SIR), BS Physical Therapy (SIR) and BS Biology (IRS) all have S·C = 3.0 + 4.0 + 4.5 = 11.5
  and cosine 11.5 ÷ (7.5993 × 1.7321) = 0.8737, so all three are the Best RIASEC Match.
- Inside the tie, Nursing and Physical Therapy (1st, 2nd and 3rd letters match) are listed before
  Biology (none match). BS Pharmacy (ISC, 0.8357) is the Alternative.

**Limitation:** a binary vector records which interests a code contains, not their priority. IRC, RIC
and ICR have identical vectors and always tie, although their ordered codes differ. Ties are kept;
the 1-2-3 ranking only orders the tied programs, it never removes or demotes one.

**Explanation shown, without changing the method:**
- Each program card shows the approved ordered code and its letters by name.
- When the student's code is not decided by ties, a program whose code has the same letters in the same order is marked "Same letters in the same order as your code". Otherwise the card says which of its 1st, 2nd and 3rd letters match the student's.
- The tie note on the results page explains the 1-2-3 order and that all tied programs are equally good matches.
- Not having a letter match is not evidence against a program.
- Letter-order **weights** (e.g. 3-2-1, which would change cosine values) are **not** implemented; they would need a separately approved method.

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

**Installed model (final):** `models/ProfilePath_J48_Binary_393.model`, J48 `-C 0.1 -M 10`, Weka 3.9.6,
trained on 393 students, 28 courses; 7 leaves, so it predicts 7 different courses.

| 10-fold CV, seeds 1–10 | Accuracy mean (sd) | Kappa | Course in top 3 |
|---|---|---|---|
| J48 (deployed) | 17.12% (1.24) | 0.107 | 40.89% |
| ZeroR baseline | 7.76% (0.18) | −0.007 | 24.45% |
| Highest possible with these inputs (ceiling, on the training data itself) | 24.68% | | 53.94% |

- J48 predicts the stated course about 2.2 times as often as ZeroR. It is close to the best
  possible rule under the same cross-validation (17.91%), so the limit is the inputs, not the
  algorithm's settings.
- **80% target:** not reachable with this target and these inputs by any classifier (ceiling
  24.68%). Meeting it would need a different target or different inputs, decided by the adviser.
- **Instruments:** the training data are O\*NET Mini-IP scores (0–20 per type) for the same 30 items
  ProfilePath uses (1–5 answers, stored 10–50). The conversion (stored = 2 × O\*NET + 10, §1.1)
  keeps the order of the types, so the top-three inputs are the same on both scales; only the
  answer labels are reworded (minor limitation). 84 of 393 training students (21%) tie for 3rd
  place, which the R, I, A, S, E, C rule decides.
- Full figures and the retraining steps: `tools/weka/README.md`. Why the accuracy is low (related
  studies) and recommendations: §10 and §11.

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
| CBF program vectors | 3–2–1 weighted vectors by letter position | Binary vectors (letter present = 1); the 1-2-3 letter ranking orders programs inside a tie | Correct the method description, formulas and worked examples to binary vectors (lowest possible top cosine 0.5500; flat profile 0.7071) and add the 1-2-3 tie order (§4). 3–2–1 weights are not implemented |
| Student vector | (check wording) | Six stored scores ÷ 10 | Describe the actual scores, not top-three only |
| Ties | (check wording) | Ties kept; IRC/RIC/ICR always tie; listed by the 1-2-3 letter ranking | Add the limitation and the tie order in §4 |
| Weka inputs | Top-three RIASEC attributes | `top3-binary` model deployed | Describe as top-three binary; describe any raw-score experiment separately as exploratory, not deployed |
| Weka target | (check wording) | Stated intended/preferred course | Do not call it actual or suitable course |
| Weka results | 296-record model (17.97% vs ZeroR 7.23%) or older 291-record results | 393-record model: 17.12% (sd 1.24) vs ZeroR 7.76%, kappa 0.107, top 3 40.89%; ceiling 24.68% | Replace the results table and model description (7 leaves); report the ceiling and explain the gap with the related studies in §10; add the recommendations in §11 |
| Evaluation | (check claims) | See §7 | Remove or qualify any 80% accuracy claim; separate the evaluation types |
| Assessment scoring | (check wording) | §1 table | Use the stored 10–50 scale and the formula in §1 |

The section numbers depend on the manuscript version; map these topics to its Methodology
(system design, CBF, prediction model), Results and Discussion chapters.

## 9. References and their limits

The links below were supplied by the team. They could not be opened from the build environment
(network policy), so check each against the source before citing.

- Rounds, J., Hoff, K., & Lewis, P. (2021). *O\*NET Interest Profiler Manual*. National Center for O\*NET Development. https://www.onetcenter.org/dl_files/IP_Manual.pdf (Chapter 3: scoring and reporting)
- Rounds, J., Wee, S., Cao, M., Song, C., & Lewis, P. *Development of an O\*NET Mini Interest Profiler (Mini-IP) for Mobile Devices: Psychometric Characteristics*. National Center for O\*NET Development. https://www.onetcenter.org/dl_files/Mini-IP.pdf (the 30 items, Appendix A; computer scoring with answers 0 = Strongly Dislike … 4 = Strongly Like; check the year on the title page)
- Gregory, C., & Lewis, P. (2016). *Linking Client Assessment Profiles to O\*NET Occupational Profiles*. National Center for O\*NET Development. https://www.onetcenter.org/dl_files/Mini-IP_Linking.pdf
- Lops, P., de Gemmis, M., & Semeraro, G. (2011). Content-based Recommender Systems: State of the Art and Trends. In *Recommender Systems Handbook* (Springer). https://doi.org/10.1007/978-0-387-85820-3_3

**Limits when citing them:**
- **Interest Profiler Manual:** ranked-code reporting (high-point codes) supports reporting an ordered code. It does not establish numerical weights such as 3–2–1.
- **Linking report:** describes correlation-based matching of client profiles to O\*NET occupational profiles. It is not a validation of this system's binary-cosine program method.
- **Lops et al.:** describes content-based recommendation in general. It supports the CBF framing, not these program codes or results.

## 10. Why the prediction accuracy is below the 80% target (related literature)

The adviser asked that, if the 80% accuracy target is not reached, the study explain the low
accuracy with related studies (possible causes include a small dataset and missing attributes).
This section collects the evidence from the study's own data and the related literature.

### 10.1 Evidence from the study's data (393 records)

| Finding | Figure | Source |
|---|---|---|
| J48 accuracy (10-fold CV, seeds 1–10) | 17.12% (sd 1.24) vs ZeroR 7.76%; kappa 0.107; top 3 40.89% | §6, `tools/weka/README.md` |
| Weka Experimenter, J48 vs ZeroR (corrected paired t-test, 0.05) | J48 17.12 (SD 4.45 over 100 folds) significantly better than ZeroR 7.76 (0.58); t = 6.04 | `tools/weka/README.md` |
| Highest accuracy any classifier can reach with these inputs | 24.68% (top 3: 53.94%) | `evaluation/accuracy_ceiling.py` |
| Best possible rule under the same cross-validation | 17.91% (sd 0.78): J48 is close to it | same |
| Input patterns | only 20; e.g. the 51 students with A, S, E chose 17 different courses | same |
| Courses with fewer than 10 students | 12 of 28 (Marketing 1; Hospitality Management and Operations Management 2 each) | dataset check |
| Courses the tree can predict | 7 of 28 (J48 `-M 10` needs at least 10 students per leaf) | §6 |
| Students whose top three is decided by a tie for 3rd place | 84 of 393 (21%) | `cbf_eval.py arff` |
| Learning curve (J48, 10-fold CV; 10 random subsamples per size) | 98 students 15.00% (sd 3.79); 197: 15.26% (1.52); 295: 17.22% (2.15); all 393: 17.12% (1.24) | exploratory run, 2026-10-24 |

The learning curve levels off from about 300 students: adding students *of the same kind* (same
inputs, same target) is not expected to approach 80%. The limit is set mainly by the inputs: students
with identical inputs chose different courses, an error no classifier can remove (the Bayes error).

### 10.2 Related studies

1. **Interests alone predict career or major choice only moderately, even in large studies.**
   - Hanna & Rounds (2020): a meta-analysis of over 75 years of interest-inventory research found
     that measured interests predict career choice (including college major) correctly about 50%
     of the time; accuracy depends on the inventory, the scale type and the hit-rate method.
   - Gasser, Larson & Borgen (2007): with the Strong Interest Inventory and 31 college majors
     (1,403 women, 469 men), the most specific scales (Basic Interest Scales) predicted major best,
     with hit rates about 6 times chance; broad themes were less predictive.
   - Song et al. (*Personnel Psychology*, DOI 10.1111/peps.12529): with 81,267 people, matching on
     the highest RIASEC types predicted career choice about 45% of the time; machine learning
     improved accuracy for common categories but under-predicted rare ones.
   - *This study:* 28 exact courses from top-3 binary inputs, a harder task than those studies;
     J48's 17.12% is about 4.8 times a uniform 1-in-28 guess (3.6%). The definitions of "chance"
     differ between studies, so the comparison is indicative only.
2. **Missing attributes: interests are one of several determinants of course choice.**
   - Lent, Brown & Hackett (1994), Social Cognitive Career Theory: choices also depend on
     self-efficacy, outcome expectations and contextual supports and barriers (e.g. family, finances).
   - Allen & Robbins (2008): in about 50,000 students, interest–major fit and first-year academic
     performance each predicted persistence in a major, so academic information adds to interests.
   - *This study:* the dataset contains only the six RIASEC scores and the stated course: no grades,
     SHS strand, abilities, self-efficacy, family or financial factors.
3. **Information lost by reducing scores to top-three yes/no.**
   - MacCallum, Zhang, Preacher & Rucker (2002): dichotomizing quantitative variables loses
     information and weakens the relationships that can be detected.
   - *This study:* "R = 1" does not show how strong R was; 21% of students' top three depend on a tie.
4. **Many classes, few and uneven students per class.**
   - He & Garcia (2009): classifiers favour majority classes and miss minority ones.
   - Figueroa, Zeng-Treitler, Kandula & Ngo (2012): classifier performance grows with training size
     along a learning curve with diminishing returns.
   - *This study:* 12 of 28 courses have fewer than 10 students and are never predicted; the
     learning curve (§10.1) has levelled off.
5. **A limit that no classifier can exceed when inputs overlap.**
   - Hastie, Tibshirani & Friedman (2009), Ch. 2 (the Bayes classifier and Bayes error rate).
   - *This study:* the ceiling of 24.68% is that limit for these inputs, measured on the data.

### 10.3 Draft paragraph (Results / Discussion)

> The J48 model achieved a mean accuracy of 17.12% (SD 1.24) under 10-fold cross-validation
> repeated ten times, compared with 7.76% for the ZeroR baseline, below the 80% target. This is
> consistent with prior research: even large interest-inventory studies predict career or major
> choice correctly about 45–50% of the time (Hanna & Rounds, 2020; Song et al.), and predicting
> among many specific majors is harder than predicting broad categories (Gasser et al., 2007).
> Course choice also depends on factors not in the dataset, such as self-efficacy, academic
> performance and contextual supports and barriers (Lent et al., 1994; Allen & Robbins, 2008).
> Representing each student only by their top three RIASEC types further discards information
> (MacCallum et al., 2002), and twelve of the 28 courses had fewer than ten students, which limits
> what a classifier can learn (He & Garcia, 2009; Figueroa et al., 2012). An analysis of the dataset
> showed that no classifier using these inputs could exceed 24.68% accuracy, because students with
> the same top three types chose different courses, and accuracy levelled off at about 17% from
> roughly 300 students onward. J48's 17.12% approaches the best achievable rule (17.91%).

### 10.5 Why the significance test matters

With an accuracy far below the target, the first question a panel will ask is whether the model
learned anything or only guessed. The Weka Experimenter answers it with evidence:

1. **A fair baseline.** ZeroR always predicts the most common course (Architecture) and is right
   7.76% of the time. A model that learned something must do better than this.
2. **A reliable estimate.** One cross-validation run can be lucky (18.83%) or unlucky (14.50%). Ten
   runs of 10 folds (100 tests, seeds 1–10 fixed in advance) give 17.12%, which does not depend on
   one chosen run.
3. **Evidence that the difference is real.** The corrected paired t-test (Nadeau & Bengio's
   correction, used by Weka's Paired T-Tester (corrected)) compares J48 and ZeroR on the same 100
   test folds. J48 is significantly better (t = 6.04, df 99, p < .05), so RIASEC top-three patterns
   carry real information about students' stated intended courses.

**How it fits the findings:** the model did not reach 80%, but it performs significantly better
than the baseline; its accuracy is limited by the inputs (ceiling 24.68%, §10.1) for reasons the
related studies explain (§10.2).

**What it does not show:** that 17% is a high accuracy; that the predicted course suits the
student (the target is the stated intended course); or that the model can replace counselor
judgement. The model therefore stays a supporting, staff-only tool.

**What to report in Chapter 4, in order of importance:** (1) the Experimenter's mean accuracy
against ZeroR with the significance result; (2) the ceiling and the related studies; (3) kappa
(0.107) and top-3 accuracy (40.89%); (4) the tree figure (Weka Explorer, Visualize tree).

Sentence for the manuscript:

> Using the Weka Experimenter (10-fold cross-validation, 10 repetitions), J48 achieved a mean
> accuracy of 17.12% (SD 4.45), significantly higher than the ZeroR baseline of 7.76% (SD 0.58) by
> the corrected paired t-test (p < .05).

### 10.4 References for this section

Confirmed through library and index records (the publishers' pages could not be opened from the
build environment, so check page numbers against the article before final submission):

- Allen, J., & Robbins, S. B. (2008). Prediction of college major persistence based on vocational interests, academic preparation, and first-year academic performance. *Research in Higher Education, 49*(1), 62–79.
- Figueroa, R. L., Zeng-Treitler, Q., Kandula, S., & Ngo, L. H. (2012). Predicting sample size required for classification performance. *BMC Medical Informatics and Decision Making, 12*, 8. https://doi.org/10.1186/1472-6947-12-8
- Gasser, C. E., Larson, L. M., & Borgen, F. H. (2007). Concurrent validity of the 2005 Strong Interest Inventory: An examination of gender and major field of study. *Journal of Career Assessment, 15*(1). https://doi.org/10.1177/1069072706294516 (sources differ on the pages: 1–21 or 5–22)
- Hanna, A., & Rounds, J. (2020). How accurate are interest inventories? A quantitative review of career choice hit rates. *Psychological Bulletin, 146*(9), 765–796. https://doi.org/10.1037/bul0000269 (the 50.3% figure is from the authors' thesis version; confirm the published value)
- He, H., & Garcia, E. A. (2009). Learning from imbalanced data. *IEEE Transactions on Knowledge and Data Engineering, 21*(9), 1263–1284.
- Lent, R. W., Brown, S. D., & Hackett, G. (1994). Toward a unifying social cognitive theory of career and academic interest, choice, and performance. *Journal of Vocational Behavior, 45*(1), 79–122.
- MacCallum, R. C., Zhang, S., Preacher, K. J., & Rucker, D. D. (2002). On the practice of dichotomization of quantitative variables. *Psychological Methods, 7*(1), 19–40.

To be completed before citing:

- Song et al. Investigating machine learning's capacity to enhance the prediction of career choices. *Personnel Psychology*. https://doi.org/10.1111/peps.12529 (full author list, year, volume and the 45% figure not yet confirmed from the article)
- Hastie, T., Tibshirani, R., & Friedman, J. (2009). *The Elements of Statistical Learning* (2nd ed.). Springer. (confirm the page of the Bayes classifier discussion in Ch. 2)
- Not yet citable: a study of 100 Grade 10 students in Saudi Arabia reporting that RIASEC types did not significantly predict intended majors (HRMARS); authors and journal not confirmed.

## 11. Recommendations (the 80% target was not reached)

Each recommendation follows from a cause in §10. None of them is implemented; each needs the
adviser's approval, and new data needs consent and data-privacy review.

1. **Add attributes beyond interests** (§10.2, item 2). Collect, with consent: SHS strand, general
   weighted average and Math/Science/English grades, and validated measures of self-efficacy and
   contextual factors (family influence, financial considerations). Retrain and compare with the
   current model under the same cross-validation.
2. **Use the full six scores instead of top-three yes/no** (item 3). Train on the numeric scores
   so the strength of each interest is kept. This needs a change to the deployment, which today
   accepts only top-three binary models.
   *Exploratory check (2026-10-24, same 393 records, Weka 3.9.6, 10-fold CV, seeds 1–10):* J48
   `-C 0.1 -M 10` on the raw 0–20 scores gives 16.49% (sd 1.72; 20 leaves, first split `I <= 7`), and
   with Weka's defaults (`-C 0.25 -M 2`) 13.21% (sd 1.72; 121 leaves, over-fitted). Neither is better
   than the top-three model (17.12%), so the full scores alone do not raise accuracy with J48 on this
   dataset; together with new attributes (item 1) they may. A raw-score tree cannot be deployed in
   ProfilePath (the Prediction Module accepts only top-three 0/1 models).
3. **Use the same instrument for training and use.** Train on results from ProfilePath's own
   assessment (10–50 scale) rather than the O\*NET website's scores (0–20), so the model sees the
   same kind of scores it is applied to.
4. **More records, aimed at the small courses** (item 4). The learning curve has levelled off, so
   simply adding more students of the same kind is unlikely to help much; the most useful additions
   are students in the 12 courses with fewer than 10 records. Re-run the learning curve and the
   ceiling check after each collection.
5. **Handle class imbalance** (item 4): cost-sensitive learning or resampling, applied inside each
   cross-validation training fold only, and report per-course recall, not accuracy alone.
6. **Choose an outcome and measure that fit the purpose** (item 1), if the adviser agrees:
   - report top-3 accuracy (40.89%) beside exact accuracy, since staff are shown the top three;
   - or predict a broader target (program group or college) with an agreed definition.
   Any change of target must be decided before measuring and reported under its own name.
7. **Report honestly.** State that the 80% target was not met, give the cross-validated result
   with ZeroR and the ceiling (24.68%), and explain it with §10. Do not report training-set
   accuracy, results from choosing seeds, or results after removing students.
8. **Keep the model's role limited.** It stays a supporting, staff-only prediction of the stated
   intended course, separate from the CBF recommendation, until a validated model exists.

## 12. Finalization checklist (open items before the system and manuscript are checked)

What is final in the repository: the CBF (binary program vectors, cosine similarity, ties kept and
listed by the 1-2-3 letter ranking) and the Prediction Module (J48, 393 records, top-3 binary,
staff only). The items below need the team, the adviser or the counselors.

### 12.1 Confirmations (the team)

| # | Item | Why it matters | Send |
|---|---|---|---|
| 1 | **Done (2026-10-24):** the 393 records used the 30-item O\*NET Mini Interest Profiler, the same items as ProfilePath | Settles the instrument limitation; conversion in §1.1 | — |
| 2 | **Tree confirmed (2026-10-25):** the team's Weka Explorer builds the same 7-leaf tree from `all_binary.arff` (screenshot). Still to send: the seed-1 output (68, 17.3028 %, Kappa 0.1103) | Confirms the team's Weka reproduces the installed model | Screenshot of the Classify output |
| 3 | **Percent_correct confirmed (2026-10-25)** on the team's Weka: ZeroR 7.76 (0.58), J48 17.12 (4.45) v, 100 results each. Still to send: the Kappa_statistic output | The figures for Chapter 4 | Analyse output with Kappa_statistic |
| 4 | **Done (2026-10-25):** the counselors' list of 32 codes matches `db/program_codes.php`, `evaluation/course_profiles.csv` and a seeded database exactly (32 of 32, including the revised BS Tourism Management SEA and BS Computer Engineering ICR). On Render, `php db/migrate_final_holland_codes.php` sets the live database to these codes (safe to run again) | The CBF uses them as the program vectors | — |
| 5 | Song et al. and Hastie et al. details (§10.4) | Citations not yet confirmed from the article | Full citation from the library database |

### 12.2 Decisions (adviser and counselors)

| # | Decision | Current state |
|---|---|---|
| 1 | CBF: binary vectors with the 1-2-3 ranking used only to order tied programs | Implemented; 3-2-1 weights not used |
| 2 | Weka: accept the 393-record model and explain the 80% gap with §10 | Implemented and documented |
| 3 | Which standard deviation to report (Experimenter over 100 folds, or over 10 run means) | Both documented (`tools/weka/README.md`) |
| 4 | Whether any §11 recommendation is done now or left as future work | None implemented |

### 12.3 Running the updated system (the team)

1. Local: `git pull origin main`; run the five migrations (`db/setup_new_database.php` lists them);
   `php db/recompute_recommendations.php`; open the results page and a staff student profile.
2. Render: the same migrations and `php db/recompute_recommendations.php` on the live database.
3. Security: change the Render database password, the AES keys and the admin password; never
   commit `.env`.

### 12.4 Manuscript (send the latest Chapters 1–5)

- Ch. 3: CBF method (binary vectors, cosine, ties, 1-2-3 order inside a tie: §4); Weka method
  (top-3 binary inputs, 393 records, J48 -C 0.1 -M 10, 10×10-fold CV, ZeroR, corrected t-test).
- Ch. 4: CBF worked example (§4) and Tables 19–21 with binary values (lowest top cosine 0.5500);
  Weka results (§10.5 order), the ceiling, the tree figure.
- Ch. 5: the 80% gap explained with §10; recommendations from §11; limitations (§6, §10.1).

