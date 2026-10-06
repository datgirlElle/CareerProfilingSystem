# Respondent Survey (Grade 12)

This folder holds the data-collection survey for the decision-tree dataset. The survey is
**generated** from the website's own question bank, so it always matches the RIASEC assessment
on the site: the same 60 statements, in the same order (grouped R, I, A, S, E, C), with the same
5-point scale (Dislike … Enjoy).

| File | What it is |
|---|---|
| `SURVEY_FOR_REVIEW.md` | Full survey text for Dr. Ilao's review (consent notice, 60 statements, program question) |
| `build_google_form.gs` | Google Apps Script that creates the Google Form automatically |
| `riasec_items.csv` | Statement → RIASEC type list, used later to score the responses |
| `generate_survey.php` | Regenerates the three files above |

## Steps

1. **Fill in the consent notice placeholders.** In `generate_survey.php`, replace the
   `[bracketed]` items: project title, researchers, adviser, retention date, contact email,
   MMCL Data Protection Officer email, and how parent/guardian consent forms are handled.
   Then run `php survey/generate_survey.php`.
   - If staff edited questions on the live site's Question Bank page, use
     `php survey/generate_survey.php --live` so the survey matches the live questions.
2. **Review.** Send `SURVEY_FOR_REVIEW.md` to Dr. Ilao. Apply any changes in the generator
   (not in the generated files), then regenerate.
3. **Create the form.**
   1. Go to https://script.google.com → **New project**.
   2. Paste `build_google_form.gs`, then run **buildSurvey** and allow access.
   3. The Execution log shows the form's edit link and the link to share.
4. **Release to Grade 12 students.** Collect the signed parent/guardian consent forms for
   respondents below 18 *before* they answer.
5. **Download the responses.** In the form's Responses tab, open the linked sheet and use
   File → Download → CSV. Scoring the responses is the next step (Dataset Preparation).

## Survey design decisions

- **No "top three Holland codes" field.** The code is computed from the 60 answers (adviser).
- **One program question.** Single choice from the 32 MCL programs, using the same names as
  the website. This becomes the class attribute in Weka (adviser).
- **No identifying data.** No name, email or student number is collected (data minimisation,
  RA 10173). Respondents who do not consent, or who are below 18 without guardian consent, are
  taken straight to the end of the form.
- **Worksheet questions are not included.** The worksheet is excluded from the dataset
  (adviser).
