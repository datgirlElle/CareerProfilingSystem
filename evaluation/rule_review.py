#!/usr/bin/env python3
"""
Counselor rule-review analysis: expert (face/content) validity of the imported WEKA J48 rules.

    python3 rule_review.py template                      -> rule_reviews.csv + overall_reviews.csv to fill in
    python3 rule_review.py analyze --rules rule_reviews.csv [--overall overall_reviews.csv]

rule_reviews.csv: one row per rule (the 20 top-3 combinations and the course the imported model
predicts for each), one column per counselor. In each counselor's column write
  M = Makes sense,  D = Doesn't make sense,  N = Not sure   (the words themselves also work).
Leave a cell empty if that counselor skipped the rule.

overall_reviews.csv (optional): one row per counselor; Q1-Q3 = the 1-5 ratings of the form's
three statements, StudentsSee = Yes / No / Not sure.

What is computed (Lynn, 1986; Polit & Beck, 2006 — check the citations yourself):
  I-CVI   per rule = counselors who marked "Makes sense" / counselors who rated it.
          "Not sure" counts as not agreeing. A rule is accepted when I-CVI = 1.00 with 5 or fewer
          raters, or I-CVI >= 0.78 with 6 or more.
  S-CVI/Ave = mean I-CVI over the rules;  S-CVI/UA = share of rules every counselor accepted.
  Fleiss' kappa = agreement between counselors beyond chance, over the three answers.
These measure how reasonable experts find the rules. They do not change the model or its accuracy.
Python standard library only.
"""
import argparse
import csv
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import cbf_eval as ce  # noqa: E402

CATS = ["M", "D", "N"]
NAMES = {"M": "Makes sense", "D": "Doesn't make sense", "N": "Not sure"}
WORDS = {"m": "M", "makes sense": "M", "yes": "M", "d": "D", "doesn't make sense": "D", "does not make sense": "D",
         "no": "D", "n": "N", "not sure": "N", "unsure": "N", "?": "N"}
DEFAULT_MODEL = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "config", "prediction_model.json")


def combos():
    """The 20 top-3 combinations in R,I,A,S,E,C order (RIA, RIS, ... SEC)."""
    d = ce.DIMENSIONS
    return [d[a] + d[b] + d[c] for a in range(6) for b in range(a + 1, 6) for c in range(b + 1, 6)]


def predicted_courses(model_path):
    with open(model_path, encoding="utf-8") as f:
        model = json.load(f)
    out = {}
    for combo in combos():
        row = {d: ("x" if d in combo else "") for d in ce.DIMENSIONS}
        label = ce.predict_tree(model, row)
        out[combo] = ce.COURSE_LOOKUP.get(ce._key(label), label) if label else "(no prediction)"
    return out, model.get("version", "?")


def code(answer):
    a = str(answer).strip().lower()
    if a == "":
        return None
    if a not in WORDS:
        raise SystemExit(f"Unknown answer {answer!r}: use M (Makes sense), D (Doesn't make sense) or N (Not sure).")
    return WORDS[a]


def fleiss_kappa(table):
    """table = list of {category: count} per item, every item rated by the same number of raters."""
    n = sum(table[0].values())
    N = len(table)
    if n < 2 or N == 0:
        return None
    p = {c: sum(r[c] for r in table) / (N * n) for c in CATS}
    P_i = [(sum(r[c] ** 2 for c in CATS) - n) / (n * (n - 1)) for r in table]
    P_bar = sum(P_i) / N
    P_e = sum(v ** 2 for v in p.values())
    return None if abs(1 - P_e) < 1e-12 else (P_bar - P_e) / (1 - P_e)


def cmd_template(a):
    preds, version = predicted_courses(a.model)
    raters = [f"Counselor{i}" for i in range(1, a.raters + 1)]
    with open(a.out, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["Rule", "Top3", "PredictedCourse", *raters])
        for i, combo in enumerate(combos(), start=1):
            w.writerow([i, " ".join(combo), preds[combo], *[""] * len(raters)])
    with open(a.overall_out, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["Counselor", "Q1", "Q2", "Q3", "StudentsSee"])
        for r in raters:
            w.writerow([r, "", "", "", ""])
    print(f"Wrote {a.out} (20 rules from model '{version}', {len(raters)} counselor columns) and {a.overall_out}.")
    print("Fill in M / D / N per counselor; rename the CounselorN columns if you like (no real names in the thesis).")


def cmd_analyze(a):
    with open(a.rules, newline="", encoding="utf-8-sig") as f:
        rows = list(csv.DictReader(f))
    if not rows:
        raise SystemExit(f"{a.rules} is empty")
    raters = [c for c in rows[0] if c not in ("Rule", "Top3", "PredictedCourse")]
    if not raters:
        raise SystemExit("No counselor columns found.")
    per_rule, full_rows = [], []
    for r in rows:
        answers = [code(r[c]) for c in raters]
        given = [x for x in answers if x]
        counts = {c: given.count(c) for c in CATS}
        n = len(given)
        icvi = counts["M"] / n if n else None
        ok = None if not n else (icvi >= 1.0 - 1e-9 if n <= 5 else icvi >= 0.78)
        per_rule.append((r, counts, n, icvi, ok))
        if n == len(raters):
            full_rows.append(counts)

    print(f"\nCounselors: {len(raters)}  ({', '.join(raters)})   Rules: {len(rows)}\n")
    print(f"{'#':>2} {'Top 3':7} {'Predicted course':45} {'M':>2} {'D':>2} {'N':>2}  {'I-CVI':>5}  Accepted")
    for r, c, n, icvi, ok in per_rule:
        print(f"{r.get('Rule', ''):>2} {r.get('Top3', ''):7} {r.get('PredictedCourse', '')[:45]:45} {c['M']:>2} {c['D']:>2} {c['N']:>2}  "
              f"{(f'{icvi:.2f}' if icvi is not None else '  -  '):>5}  {('yes' if ok else 'no') if ok is not None else '-'}")
    rated = [x for x in per_rule if x[3] is not None]
    s_ave = sum(x[3] for x in rated) / len(rated) if rated else float("nan")
    s_ua = sum(1 for x in rated if x[3] >= 1.0 - 1e-9) / len(rated) if rated else float("nan")
    accepted = sum(1 for x in rated if x[4])
    kappa = fleiss_kappa(full_rows) if len(full_rows) >= 2 else None
    agree = sum(1 for c in full_rows if max(c.values()) == len(raters)) / len(full_rows) if full_rows else float("nan")
    rule_note = "I-CVI = 1.00 (5 or fewer counselors)" if len(raters) <= 5 else "I-CVI >= 0.78 (6 or more counselors)"
    print(f"\nRules accepted ({rule_note}): {accepted} of {len(rated)}")
    print(f"S-CVI/Ave (mean I-CVI): {s_ave:.2f}    S-CVI/UA (all counselors said Makes sense): {s_ua:.2f}")
    if kappa is None:
        print("Fleiss' kappa: not computed (needs 2+ counselors who rated every rule, and some variety in answers).")
    else:
        print(f"Fleiss' kappa: {kappa:.2f}   (rules where all counselors gave the same answer: {agree:.0%}, over {len(full_rows)} fully rated rules)")
    if len(raters) < 3:
        print("Note: fewer than 3 counselors; report this as a limitation.")

    with open(a.out, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["Rule", "Top3", "PredictedCourse", "MakesSense", "DoesNotMakeSense", "NotSure", "Raters", "I_CVI", "Accepted"])
        for r, c, n, icvi, ok in per_rule:
            w.writerow([r.get("Rule", ""), r.get("Top3", ""), r.get("PredictedCourse", ""), c["M"], c["D"], c["N"], n,
                        f"{icvi:.2f}" if icvi is not None else "", "" if ok is None else ("yes" if ok else "no")])
    print(f"\nPer-rule results: {a.out}")

    if a.overall:
        with open(a.overall, newline="", encoding="utf-8-sig") as f:
            ov = [r for r in csv.DictReader(f) if any(v.strip() for k, v in r.items() if k != "Counselor")]
        statements = {"Q1": "The predictions are reasonable for these RIASEC profiles",
                      "Q2": "Useful to counselors as supporting information",
                      "Q3": "Showing it next to the Best RIASEC Match helps advising"}
        print("\nOverall assessment (1 = strongly disagree, 5 = strongly agree):")
        for q, text in statements.items():
            vals = [float(r[q]) for r in ov if r.get(q, "").strip()]
            if vals:
                print(f"  {q} {text}: mean {sum(vals) / len(vals):.2f} (n = {len(vals)})")
        votes = [r.get("StudentsSee", "").strip().lower() for r in ov if r.get("StudentsSee", "").strip()]
        if votes:
            yes, no = votes.count("yes"), votes.count("no")
            print(f"  Should students see the prediction? Yes {yes}, No {no}, Not sure {len(votes) - yes - no}")

    print("\nThesis wording (fill in the numbers above): \"N counselors independently reviewed the 20 rules of the J48 model. "
          "X of 20 rules were accepted (S-CVI/Ave = …, Fleiss' kappa = …). The review assesses expert face validity; "
          "it does not change the model's measured accuracy.\"")


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)
    t = sub.add_parser("template")
    t.add_argument("--model", default=DEFAULT_MODEL)
    t.add_argument("--raters", type=int, default=3)
    t.add_argument("--out", default="rule_reviews.csv")
    t.add_argument("--overall-out", default="overall_reviews.csv")
    an = sub.add_parser("analyze")
    an.add_argument("--rules", required=True)
    an.add_argument("--overall")
    an.add_argument("--out", default="rule_review_results.csv")
    a = p.parse_args()
    {"template": cmd_template, "analyze": cmd_analyze}[a.cmd](a)


if __name__ == "__main__":
    main()
