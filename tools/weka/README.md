# Prediction Module: Weka J48 in ProfilePath

The Prediction Module shows guidance staff the course a Weka J48 model predicts for a student,
beside the content-based results (Best RIASEC Match, Alternative Courses) and the student's
Preferred Course. It never changes those results.

## What the model is

| | |
|---|---|
| File | `models/ProfilePath_J48_Binary.model` (SHA-256 `7c1bd2eb…495ad`) |
| Classifier | `weka.classifiers.trees.J48 -C 0.1 -M 10`, saved with Weka 3.9.6, training header included |
| Inputs | `R, I, A, S, E, C`, numeric, 1 = one of the student's three highest RIASEC scores, 0 = not |
| Class | `Actual Course` (index 6), 28 labels: the students' stated intended/preferred courses |
| Tree | 8 leaves, size 15 |

The class labels are kept exactly as trained, including the spellings `BS Pyschology`,
`BS Hospitality Managment`, `… Operations Managements` and `… Financial Managements`;
`config/course_aliases.php` maps them to the program list. Four programs are not among the 28
classes (BS Accounting Information System, BSBA Sustainability Management, BS International
Business, BS Business Analytics with AI): the model gives them no prediction and no probability.

## How it runs (no Java on the server)

The inputs always have exactly three 1s, so there are only **20 possible inputs**.
`ExportJ48Lookup` loads the `.model` file with Weka, checks its header, and records Weka's own
`classifyInstance()` and `distributionForInstance()` for each of the 20 inputs (class set to
missing) in `config/prediction_model.json`. PHP (`lib/PredictionModel.php`) then:

1. checks the six raw scores against the assessment's rules (whole numbers, 10–50); a missing or
   invalid score is rejected, never replaced by 0;
2. sets 1 for the three highest scores, 0 for the rest; equal scores are taken in R, I, A, S, E, C
   order (the rule used to prepare the training data; a project rule, not an O\*NET rule);
3. looks up Weka's prediction and probabilities for that input.

Because every possible input was answered by Weka itself, the application's predictions and
probabilities are Weka's. `db/verify_prediction_model.php` checks this against Weka's command line.

**Display rule for equal probabilities:** courses with equal probability are listed in the
model's class-label order (the order in the .model header) and marked "tied" on the staff card.
Weka's own predicted class is the first class with the highest probability, so the first course
shown is always Weka's prediction. Tie-averaged evaluation metrics (Hit@3, MRR) do not describe
this display order exactly.

**Probabilities** are the share of past students at the tree's leaf who intended each course.
They are not suitability scores and are not shown as percentages.

## Re-export after retraining

Run from the project folder. Use Weka's own `weka.jar` (same version that saved the model). In
Windows Command Prompt (paths for a Weka 3.9.6 install in `D:\Weka\Weka-3-9-6`):

```
set WEKA=D:\Weka\Weka-3-9-6\weka.jar

:: 1. Export Weka's predictions for all 20 inputs
java -cp "%WEKA%;tools\weka\export-j48.jar" ExportJ48Lookup models\ProfilePath_J48_Binary.model config\prediction_model.json "ProfilePath J48 Binary (J48 -C 0.1 -M 10, 296 records)" --encoding top3-binary --training-records 296 --evaluation "10-fold cross-validation (development results; run seeds not yet confirmed)"

:: 2. Check the application against Weka's own command line
php db\verify_prediction_model.php --write-arff profiles20.arff
java -cp "%WEKA%" weka.classifiers.trees.J48 -l models\ProfilePath_J48_Binary.model -T profiles20.arff -classifications "weka.classifiers.evaluation.output.prediction.CSV -distribution -decimals 16" > weka_predictions.csv
php db\verify_prediction_model.php weka_predictions.csv

:: 3. Recompute saved recommendations with the new model
php db\recompute_recommendations.php
```

`--encoding top3-binary` is required: it declares that the model was trained on the 0/1
top-three inputs. The export also refuses a tree whose splits do not fit 0/1 inputs (a model
trained on raw scores splits at values such as `C <= 12`), and the application refuses such a
file at run time (`PredictionModel::validateLookup`). `--training-records`, `--training-data` and
`--evaluation` record what the team declares; the .model file does not contain them, and anything
left out is shown to staff as "unknown". Raw-score models (e.g. exploratory experiments) cannot be
deployed through this lookup.

If `java` is not found, use the `java.exe` inside Weka's own folder (`dir /s /b D:\Weka\Weka-3-9-6\java.exe`).
On macOS/Linux use `:` instead of `;` in `-cp`. If the model was saved without its training
header, add `--header train.arff` to step 1.

`export-j48.jar` is built from `ExportJ48Lookup.java` for Java 8+:
`javac --release 8 -cp weka.jar ExportJ48Lookup.java && jar cf export-j48.jar ExportJ48Lookup.class`.

The export stops (and the old file is kept) if the classifier is not J48, the inputs are not
`R, I, A, S, E, C` in that order and numeric, or the class is not the 7th, nominal attribute.
The application refuses a damaged file (`PredictionModel::validateLookup`): the staff card then
says the Prediction Module is unavailable, and the content-based results are shown as usual.

## API (staff only)

`GET api/recommendations.php?studentId=<id>` with a staff session returns `predictionDetail`:

```json
{
  "status": "available",
  "inputs": {"R": 1, "I": 1, "A": 0, "S": 1, "E": 0, "C": 0},
  "predicted": {"label": "BS Civil Engineering", "title": "BS Civil Engineering", "programId": 23,
                "probability": 0.21739130434782608, "inBestRiasecMatch": false, "inAlternatives": false, "tied": false},
  "top3": ["... the three highest, ties in the model's class order"],
  "distribution": ["... all 28 classes in the model's class order"],
  "model": {"version": "ProfilePath J48 Binary (J48 -C 0.1 -M 10, 296 records)", "wekaVersion": "3.9.6",
            "classifier": "weka.classifiers.trees.J48 -C 0.1 -M 10", "classAttribute": "Actual Course", "classes": 28,
            "sha256": "7c1bd2eb…"},
  "savedPredictionDiffers": false
}
```

When it cannot run: `{"status": "unavailable", "reason": "…"}`. Students never receive
`predictionDetail`. The model and configuration files are not served over the web (`router.php`).

## What the evaluation results mean

The class is the students' stated intended course, so accuracy measures agreement with stated
intentions, not whether a course suits a student. The 10-fold cross-validation figures supplied
by the team (J48 mean accuracy 17.97%, ZeroR 7.23% over ten runs) are development results, not an
independent test, and the seeds of those runs are not yet confirmed. They are not comparable with
the earlier 291-record / 200-91 split results.
