<?php
/**
 * Generates the respondent survey from the SAME sources the website uses, so the
 * questionnaire matches the website exactly (adviser's requirement):
 *   - 60 RIASEC statements, order and wording: db/seed_questions.php
 *     (pass --live to read the live database's active question bank instead)
 *   - answer scale: riasec-assessment.html (Dislike ... Enjoy = 1 ... 5)
 *   - 32 programs: db/program_codes.php
 *
 *   php survey/generate_survey.php          from the seed question bank
 *   php survey/generate_survey.php --live   from the live database (if staff edited questions)
 *
 * Writes survey/build_google_form.gs, survey/SURVEY_FOR_REVIEW.md, survey/riasec_items.csv.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

const DIMENSIONS = ['R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];
const SCALE = ['Dislike', 'Slightly Dislike', 'Neutral', 'Slightly Enjoy', 'Enjoy']; // scored 1..5, as on the website

// ---- 60 statements, grouped by type in R, I, A, S, E, C order (as on the website) ----
if (in_array('--live', $argv, true)) {
    require_once __DIR__ . '/../lib/Database.php';
    require_once __DIR__ . '/../lib/Crypto.php';
    $questions = array_fill_keys(array_keys(DIMENSIONS), []);
    $rows = Database::get()->query(
        "SELECT dimension, question_text_enc FROM assessment_questions WHERE is_active = TRUE
         ORDER BY array_position(ARRAY['R','I','A','S','E','C'], dimension), order_index"
    )->fetchAll();
    foreach ($rows as $r) {
        $questions[$r['dimension']][] = Crypto::dec($r['question_text_enc']);
    }
    $source = 'live database question bank';
} else {
    $src = file_get_contents(__DIR__ . '/../db/seed_questions.php');
    $start = strpos($src, '$questionBank');
    eval(substr($src, $start, strpos($src, '];', $start) + 2 - $start) . ';');
    $questions = $questionBank;
    $source = 'db/seed_questions.php';
}
$total = array_sum(array_map('count', $questions));
if ($total !== 60) {
    fwrite(STDERR, "Expected 60 statements, found $total.\n");
    exit(1);
}
$programs = array_column(require __DIR__ . '/../db/program_codes.php', 1); // 32 titles, grouped by college

// ---- consent / data privacy notice (RA 10173); [bracketed] items must be filled in ----
$consent = <<<TXT
DATA PRIVACY NOTICE AND CONSENT

You are invited to take part in a research study for the capstone project "[Project title]" by [researchers' names], [program], Mapúa Malayan Colleges Laguna (MMCL), under the supervision of [adviser's name].

Purpose: To collect career-interest (RIASEC) responses and intended college programs of Grade 12 students, used to build and evaluate a course recommendation model for the Career Profiling System.

What we collect: Only your answers to 60 interest statements and your intended or preferred MCL program. We do NOT collect your name, email address, student number, or any other information that identifies you.

Voluntary participation: Taking part is voluntary. You may stop at any time before submitting, and choosing not to participate will not affect your grades or standing in any way.

Use, storage and retention: Responses are stored in a password-protected account accessible only to the researchers and their adviser, used only for this study, reported only in summary (aggregate) form, and deleted on or before [retention date, e.g. one year after the project's final defense].

Your rights: Under the Data Privacy Act of 2012 (Republic Act No. 10173), you have the right to be informed, to object, to access, to correct, and to have your data erased. Because responses are anonymous, individual answers cannot be retrieved after submission.

Contact: [researcher contact email]. MMCL Data Protection Officer: [DPO email].

Respondents below 18 years old may take part only if their parent or guardian has signed the consent form given separately [describe how it is distributed and collected].
TXT;

$ageChoices = [
    'I am 18 years old or older.',
    'I am below 18, and my parent/guardian has signed the consent form.',
    'I am below 18, and my parent/guardian has NOT signed the consent form.',
];
$consentChoices = ['Yes, I have read the notice above and I agree to participate.', 'No, I do not agree to participate.'];
$courseQuestion = 'Which MCL program do you intend or prefer to take in college?';
$intro = "Rate each statement honestly, from Dislike to Enjoy, based on your genuine interest - not what you think sounds best. There are no right or wrong answers. This takes about 10-15 minutes.";

// ---- riasec_items.csv: statement -> type, used later to score the responses ----
$csv = fopen(__DIR__ . '/riasec_items.csv', 'w');
fputcsv($csv, ['Order', 'Dimension', 'TypeName', 'IndexInType', 'Statement']);
$n = 0;
foreach ($questions as $d => $list) {
    foreach (array_values($list) as $i => $text) {
        fputcsv($csv, [++$n, $d, DIMENSIONS[$d], $i + 1, $text]);
    }
}
fclose($csv);

// ---- Google Apps Script that builds the Google Form ----
$js = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$gs = <<<GS
/**
 * Builds the RIASEC respondent survey as a Google Form.
 * GENERATED by survey/generate_survey.php from {$source} - do not edit by hand;
 * edit the source and regenerate so the form keeps matching the website.
 *
 * How to use: go to https://script.google.com -> New project -> paste this file ->
 * Run "buildSurvey" -> allow access. The new form's edit and response links are
 * printed in the Execution log.
 */
var TYPES = {$js(DIMENSIONS)};
var QUESTIONS = {$js($questions)};
var SCALE = {$js(SCALE)};
var PROGRAMS = {$js($programs)};
var CONSENT = {$js($consent)};
var AGE_CHOICES = {$js($ageChoices)};
var CONSENT_CHOICES = {$js($consentChoices)};
var COURSE_QUESTION = {$js($courseQuestion)};
var INTRO = {$js($intro)};

function buildSurvey() {
  var form = FormApp.create('Career Interest (RIASEC) Survey - Grade 12');
  form.setDescription('A research survey for the Career Profiling System capstone project.');
  form.setCollectEmail(false);          // no email addresses (data minimisation, RA 10173)
  form.setShuffleQuestions(false);      // keep the website's order
  form.setProgressBar(true);
  form.setAllowResponseEdits(false);
  form.setConfirmationMessage('Thank you for answering the survey. Your responses have been recorded.');

  // Section 1: data privacy notice, consent and age/guardian consent
  form.addSectionHeaderItem().setTitle('Data Privacy Notice and Consent').setHelpText(CONSENT);
  var consent = form.addMultipleChoiceItem().setTitle('Do you agree to participate in this study?').setRequired(true);
  var age = form.addMultipleChoiceItem().setTitle('Please confirm your age and consent status.').setRequired(true);

  // Sections 2-7: the 60 statements, one section per RIASEC type, in R, I, A, S, E, C order
  var firstQuestionPage = null;
  Object.keys(QUESTIONS).forEach(function (code) {
    var page = form.addPageBreakItem().setTitle(TYPES[code]).setHelpText(INTRO);
    if (!firstQuestionPage) firstQuestionPage = page;
    QUESTIONS[code].forEach(function (text, i) {
      form.addMultipleChoiceItem()
        .setTitle(text)
        .setHelpText(TYPES[code] + ' - Question ' + (i + 1) + ' out of 10')
        .setChoiceValues(SCALE)
        .setRequired(true);
    });
  });

  // Section 8: intended / preferred program (single choice from the 32 MCL programs)
  form.addPageBreakItem().setTitle('Intended College Program');
  form.addMultipleChoiceItem().setTitle(COURSE_QUESTION).setChoiceValues(PROGRAMS).setRequired(true);

  // Branching: anyone who does not consent, or is a minor without guardian consent, ends here
  consent.setChoices([
    consent.createChoice(CONSENT_CHOICES[0], FormApp.PageNavigationType.CONTINUE),
    consent.createChoice(CONSENT_CHOICES[1], FormApp.PageNavigationType.SUBMIT)
  ]);
  age.setChoices([
    age.createChoice(AGE_CHOICES[0], firstQuestionPage),
    age.createChoice(AGE_CHOICES[1], firstQuestionPage),
    age.createChoice(AGE_CHOICES[2], FormApp.PageNavigationType.SUBMIT)
  ]);

  Logger.log('Edit the form:     ' + form.getEditUrl());
  Logger.log('Share with students: ' + form.getPublishedUrl());
}

GS;
file_put_contents(__DIR__ . '/build_google_form.gs', $gs);

// ---- Review copy for Dr. Ilao ----
$md = "# Career Interest (RIASEC) Survey: Review Copy\n\n";
$md .= "_Generated by `survey/generate_survey.php` from {$source}. The statements, their order and the answer scale are identical to the website's RIASEC assessment._\n\n";
$md .= "**Changes from the earlier draft:**\n- Removed: the \"top three Holland codes, highest to lowest\" field. The code is computed from the answers.\n- Added: one question on the intended or preferred program (single choice, 32 MCL programs).\n- Added: data privacy notice and consent (RA 10173), including parent/guardian consent for respondents below 18.\n- Not collected: name, email address, student number.\n\n---\n\n";
$md .= "## Section 1: Data Privacy Notice and Consent\n\n" . $consent . "\n\n";
$md .= "**Q1. Do you agree to participate in this study?** (required)\n" . implode('', array_map(fn($c) => "- ( ) $c\n", $consentChoices)) . "\n_\"No\" ends the survey._\n\n";
$md .= "**Q2. Please confirm your age and consent status.** (required)\n" . implode('', array_map(fn($c) => "- ( ) $c\n", $ageChoices)) . "\n_The third option ends the survey._\n\n";
$md .= "## Sections 2–7: Interest Statements (60 items)\n\n" . $intro . "\n\nAnswer choices for every statement: " . implode(' / ', SCALE) . " (scored 1–5, as on the website).\n\n";
$q = 3;
foreach ($questions as $d => $list) {
    $md .= "### " . DIMENSIONS[$d] . "\n\n";
    foreach (array_values($list) as $i => $text) {
        $md .= "**Q" . $q++ . ".** $text  _(" . DIMENSIONS[$d] . " – Question " . ($i + 1) . " out of 10)_\n\n";
    }
}
$md .= "## Section 8: Intended College Program\n\n**Q{$q}. {$courseQuestion}** (required, choose one)\n\n";
foreach ($programs as $p) {
    $md .= "- ( ) $p\n";
}
$md .= "\n---\n\n**Reviewer:** Dr. Ilao  Signature: ____________  Date: __________\n";
file_put_contents(__DIR__ . '/SURVEY_FOR_REVIEW.md', $md);

echo "Generated from {$source}: 60 statements, " . count($programs) . " programs.\n";
echo "  survey/build_google_form.gs\n  survey/SURVEY_FOR_REVIEW.md\n  survey/riasec_items.csv\n";
