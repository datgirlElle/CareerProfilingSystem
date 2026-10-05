#!/usr/bin/env python3
"""
CBF + Cosine Similarity evaluation toolkit (Python 3 standard library only).

Offline research companion to the web app's recommender (lib/CBFEngine.php).
It builds the course dataset, runs the CBF on student RIASEC profiles,
evaluates Top-N performance against ActualCourse, compares Holland-code rank
encodings, and prepares identical train/test splits for WEKA.

    python3 cbf_eval.py courses                                   -> course_profiles.csv
    python3 cbf_eval.py split   --students student_profiles.csv   -> train/test .csv + .arff
    python3 cbf_eval.py cbf     --students test.csv --k 3         -> cbf_results.csv + metrics
    python3 cbf_eval.py weights --students train.csv --k 3        -> encoding comparison

LEAKAGE RULE: ActualCourse is read only by the evaluation functions. The
scoring function `rank_courses()` receives nothing but the six RIASEC scores.
"""
import argparse
import csv
import math
import os
import random
from collections import Counter

DIMENSIONS = ["R", "I", "A", "S", "E", "C"]  # fixed vector order

# Encodings for a 3-letter Holland code: weight of the 1st, 2nd and 3rd letter
# (absent letters = 0). These are ENGINEERING choices, not weights defined by
# Holland's theory. "binary" (letter present = 1) is what the web app uses
# (config/cbf.php); the others keep letter order and are compared in `weights`.
DEFAULT_ENCODING = "binary"
ENCODINGS = {
    "proposed": (1.00, 0.67, 0.33),
    "binary": (1.00, 1.00, 1.00),
    "steep": (1.00, 0.50, 0.25),
    "flat": (1.00, 0.75, 0.50),
}

# 32 courses. OriginalHollandCode = code in the system's seed data
# (db/seed_programs.php, "verbatim from RIASEC_Program_Crosswalk.docx").
# FinalHollandCode = validator revision of August 7, 2026 (authoritative).
# Courses whose original differs from the revision: Tourism, Computer Engineering.
# See METHODOLOGY.md §2 for two originals that still need confirmation.
COURSES = [
    ("CAS", "BA Communication", "AES", "AES"),
    ("CAS", "B Multimedia Arts", "AER", "AER"),
    ("CCIS", "BS Computer Science", "IRC", "IRC"),
    ("CCIS", "BS Information Technology", "IRC", "IRC"),
    ("CHS", "BS Biology", "IRS", "IRS"),
    ("CHS", "BS Medical Technology", "IRC", "IRC"),
    ("CHS", "BS Pharmacy", "ISC", "ISC"),
    ("CHS", "BS Physical Therapy", "SIR", "SIR"),
    ("CHS", "BS Psychology", "SIA", "SIA"),
    ("CN", "BS Nursing", "SIR", "SIR"),
    ("ETYCB", "BS Accountancy", "CEI", "CEI"),
    ("ETYCB", "BS Accounting Information System", "CIE", "CIE"),
    ("ETYCB", "BSBA Major in Financial Management", "ECI", "ECI"),
    ("ETYCB", "BSBA Major in Operations Management", "EIC", "EIC"),
    ("ETYCB", "BSBA Major in Sustainability Management", "EIS", "EIS"),
    ("ETYCB", "BS Hospitality Management", "ESC", "ESC"),
    ("ETYCB", "BS Tourism Management", "EAS", "SEA"),
    ("ETYCB", "BS International Business", "ECS", "ECS"),
    ("ETYCB", "BS Business Analytics with Artificial Intelligence", "IEC", "IEC"),
    ("ETYCB", "BS Marketing", "EAS", "EAS"),
    ("MITL", "BS Architecture", "ARI", "ARI"),
    ("MITL", "BS Chemical Engineering", "IRC", "IRC"),
    ("MITL", "BS Civil Engineering", "RIC", "RIC"),
    ("MITL", "BS Mechanical Engineering", "RIC", "RIC"),
    ("MITL", "BS Electrical Engineering", "RIC", "RIC"),
    ("MITL", "BS Electronics Engineering", "RIC", "RIC"),
    ("MITL", "BS Industrial Engineering", "REC", "REC"),
    ("MITL", "BS Computer Engineering", "RIC", "ICR"),
    ("MIA", "BS Aeronautical Engineering", "RIC", "RIC"),
    ("MIA", "BS Aviation Management", "ERC", "ERC"),
    ("CMET", "BS Marine Engineering", "RIC", "RIC"),
    ("CMET", "BS Marine Transportation", "REC", "REC"),
]
COURSE_NAMES = [c[1] for c in COURSES]
TIE_EPS = 1e-12  # scores closer than this are treated as equal (identical course vectors)


# ---------------------------------------------------------------- vectors

def holland_to_vector(code, weights=ENCODINGS[DEFAULT_ENCODING]):
    """'IRC' -> [1, 1, 0, 0, 0, 1] (binary); [0.67, 1.00, 0, 0, 0, 0.33] with the proposed encoding."""
    code = code.strip().upper()
    if len(code) != 3 or len(set(code)) != 3 or any(c not in DIMENSIONS for c in code):
        raise ValueError(f"Invalid Holland code: {code!r}")
    vec = [0.0] * 6
    for rank, letter in enumerate(code):
        vec[DIMENSIONS.index(letter)] = weights[rank]
    return vec


def student_vector(row, scale="unit"):
    """Six RIASEC scores -> [R,I,A,S,E,C] on a 0-1 scale.

    unit   : scores are already 0-1 (validated)
    raw50  : raw totals 10-50 divided by 50 (does NOT change cosine values)
    minmax : (raw - 10) / 40, so an all-"1" dimension becomes 0 (DOES change cosine)
    """
    vals = [float(row[d]) for d in DIMENSIONS]
    if scale == "unit":
        if any(v < 0 or v > 1 for v in vals):
            raise ValueError(f"Student {row.get('StudentID')}: scores must be 0-1 (use --scale raw50/minmax for raw totals)")
        return vals
    if any(v < 10 or v > 50 for v in vals):
        raise ValueError(f"Student {row.get('StudentID')}: raw scores must be 10-50")
    return [v / 50 for v in vals] if scale == "raw50" else [(v - 10) / 40 for v in vals]


def cosine(a, b):
    """(a . b) / (|a| |b|); 0.0 when either vector has zero magnitude."""
    dot = sum(x * y for x, y in zip(a, b))
    na = math.sqrt(sum(x * x for x in a))
    nb = math.sqrt(sum(y * y for y in b))
    return dot / (na * nb) if na > 0 and nb > 0 else 0.0


def course_vectors(weights):
    return {name: holland_to_vector(final, weights) for _, name, _, final in COURSES}


# ---------------------------------------------------------------- CBF

def rank_courses(svec, cvecs):
    """Score EVERY course and rank by cosine, highest first.

    Returns [(course, cosine, competition_rank)], ordered by score with ties
    broken by catalogue order (display only). Tied courses share a rank
    (1, 2, 2, 4 ...), because identical course vectors cannot be told apart.
    Only the student's RIASEC vector is used — never ActualCourse.
    """
    scored = [(name, cosine(svec, vec)) for name, vec in cvecs.items()]
    ordered = sorted(scored, key=lambda t: (-t[1], COURSE_NAMES.index(t[0])))
    out = []
    for name, s in ordered:
        rank = 1 + sum(1 for _, o in scored if o > s + TIE_EPS)
        out.append((name, s, rank))
    return out


def tie_aware(ranked, actual, k):
    """Expected Hit@K and reciprocal rank when ties are broken at random.

    If `better` courses score strictly higher than the actual course and `t`
    courses (including it) share its score, the actual course is equally likely
    to occupy any position better+1 .. better+t.
    """
    s = next(sc for name, sc, _ in ranked if name == actual)
    better = sum(1 for _, o, _ in ranked if o > s + TIE_EPS)
    t = sum(1 for _, o, _ in ranked if abs(o - s) <= TIE_EPS)
    hit = min(max(k - better, 0), t) / t
    rr = sum(1 / p for p in range(better + 1, better + t + 1)) / t
    return hit, rr, better + 1, t


def evaluate(students, cvecs, k, scale):
    """Top-N metrics. With exactly one relevant course per student:
    Recall@K == HitRate@K and Precision@K == HitRate@K / K."""
    hits, rrs, top1, code_hits = [], [], [], []
    final_code = {name: final for _, name, _, final in COURSES}
    for row in students:
        ranked = rank_courses(student_vector(row, scale), cvecs)
        hit, rr, _, _ = tie_aware(ranked, row["ActualCourse"], k)
        hits.append(hit)
        rrs.append(rr)
        top1.append(tie_aware(ranked, row["ActualCourse"], 1)[0])
        # Code-level hit: is the actual course's Holland code among the top-K courses' codes?
        code_hits.append(float(final_code[row["ActualCourse"]] in {final_code[n] for n, _, _ in ranked[:k]}))
    n = len(students)
    hr = sum(hits) / n
    return {
        "n": n, "k": k,
        f"HitRate@{k}": hr, f"Recall@{k}": hr, f"Precision@{k}": hr / k,
        "MRR": sum(rrs) / n, "Top1Accuracy(expected)": sum(top1) / n,
        f"CodeHit@{k}": sum(code_hits) / n,
        "_per_student_rr": rrs,
    }


def paired_bootstrap(a, b, iters=2000, seed=7):
    """95% CI of mean(a - b) over students, resampling students with replacement."""
    rng = random.Random(seed)
    n = len(a)
    diffs = sorted(sum(a[i] - b[i] for i in (rng.randrange(n) for _ in range(n))) / n for _ in range(iters))
    return diffs[int(0.025 * iters)], diffs[int(0.975 * iters)]


# ---------------------------------------------------------------- I/O

def read_students(path):
    with open(path, newline="", encoding="utf-8") as f:
        rows = list(csv.DictReader(f))
    need = {"StudentID", *DIMENSIONS, "ActualCourse"}
    if not rows or not need <= set(rows[0]):
        raise SystemExit(f"{path} must have columns: StudentID,R,I,A,S,E,C,ActualCourse")
    unknown = sorted({r["ActualCourse"] for r in rows} - set(COURSE_NAMES))
    if unknown:
        raise SystemExit(f"ActualCourse values not in the 32-course list: {unknown}")
    dupes = [s for s, c in Counter(r["StudentID"] for r in rows).items() if c > 1]
    if dupes:
        raise SystemExit(f"Duplicate StudentID values: {dupes[:5]}")
    return rows


def write_csv(path, header, rows):
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(header)
        w.writerows(rows)


def write_arff(path, rows, scale):
    """WEKA file: R..C numeric + ActualCourse nominal (all 32 classes declared so
    train/test headers match). StudentID is deliberately NOT an attribute."""
    classes = ",".join("'" + c.replace("'", "\\'") + "'" for c in COURSE_NAMES)
    with open(path, "w", encoding="utf-8") as f:
        f.write("@RELATION student_riasec\n\n")
        for d in DIMENSIONS:
            f.write(f"@ATTRIBUTE {d} NUMERIC\n")
        f.write(f"@ATTRIBUTE ActualCourse {{{classes}}}\n\n@DATA\n")
        for r in rows:
            vec = student_vector(r, scale)
            f.write(",".join(f"{v:.6f}" for v in vec) + ",'" + r["ActualCourse"].replace("'", "\\'") + "'\n")


# ---------------------------------------------------------------- commands

def cmd_courses(a):
    rows = [[col, name, orig, final, *[f"{v:.2f}" for v in holland_to_vector(final)]] for col, name, orig, final in COURSES]
    write_csv(a.out, ["College", "Course", "OriginalHollandCode", "FinalHollandCode", *DIMENSIONS], rows)
    print(f"Wrote {a.out} ({len(rows)} courses, {DEFAULT_ENCODING} encoding {ENCODINGS[DEFAULT_ENCODING]} on FinalHollandCode)")


def cmd_split(a):
    """Stratified split by ActualCourse. Classes with a single student go to train
    (they cannot be tested) and are reported."""
    rows = read_students(a.students)
    rng = random.Random(a.seed)
    by_class = {}
    for r in rows:
        by_class.setdefault(r["ActualCourse"], []).append(r)
    train, test, singletons = [], [], []
    for course, group in sorted(by_class.items()):
        rng.shuffle(group)
        if len(group) < 2:
            train += group
            singletons.append(course)
            continue
        n_test = max(1, round(len(group) * a.test_fraction))
        test += group[:n_test]
        train += group[n_test:]
    header = ["StudentID", *DIMENSIONS, "ActualCourse"]
    base = os.path.dirname(os.path.abspath(a.students))
    for name, part in (("train", train), ("test", test)):
        write_csv(os.path.join(base, f"{name}.csv"), header, [[r[h] for h in header] for r in part])
        write_arff(os.path.join(base, f"{name}.arff"), part, a.scale)
    print(f"train={len(train)} test={len(test)} (seed {a.seed}); files in {base}")
    if singletons:
        print(f"Courses with only 1 student (train only, not testable): {singletons}")


def cmd_cbf(a):
    rows = read_students(a.students)
    cvecs = course_vectors(ENCODINGS[a.encoding])
    out = []
    for r in rows:
        for pos, (name, s, rank) in enumerate(rank_courses(student_vector(r, a.scale), cvecs), start=1):
            out.append([r["StudentID"], name, f"{s:.6f}", rank, int(pos <= a.k), r["ActualCourse"]])
    write_csv(a.out, ["StudentID", "Course", "CosineSimilarity", "Rank", "Recommended", "ActualCourse"], out)
    m = evaluate(rows, cvecs, a.k, a.scale)
    print(f"Wrote {a.out} ({len(out)} rows = {len(rows)} students x 32 courses)")
    print(f"Encoding={a.encoding} {ENCODINGS[a.encoding]}  scale={a.scale}")
    for key, v in m.items():
        if not key.startswith("_"):
            print(f"  {key:24} {v:.4f}" if isinstance(v, float) else f"  {key:24} {v}")


def cmd_weights(a):
    rows = read_students(a.students)
    results = {name: evaluate(rows, course_vectors(w), a.k, a.scale) for name, w in ENCODINGS.items()}
    print(f"n={len(rows)} students, K={a.k}, scale={a.scale}  (tie-aware, expected values)")
    print(f"{'encoding':10} {'weights':18} {'HitRate@K':>10} {'MRR':>8} {'Top1':>8}   MRR diff vs proposed [95% CI]")
    base = results["proposed"]["_per_student_rr"]
    for name, m in results.items():
        lo, hi = paired_bootstrap(m["_per_student_rr"], base) if name != "proposed" else (0.0, 0.0)
        diff = m["MRR"] - results["proposed"]["MRR"]
        print(f"{name:10} {str(ENCODINGS[name]):18} {m[f'HitRate@{a.k}']:10.4f} {m['MRR']:8.4f} "
              f"{m['Top1Accuracy(expected)']:8.4f}   {diff:+.4f} [{lo:+.4f}, {hi:+.4f}]")


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)
    c = sub.add_parser("courses")
    c.add_argument("--out", default="course_profiles.csv")
    for name in ("split", "cbf", "weights"):
        s = sub.add_parser(name)
        s.add_argument("--students", required=True)
        s.add_argument("--scale", choices=["unit", "raw50", "minmax"], default="unit")
        if name == "split":
            s.add_argument("--test-fraction", type=float, default=0.3)
            s.add_argument("--seed", type=int, default=42)
        else:
            s.add_argument("--k", type=int, default=3)
        if name == "cbf":
            s.add_argument("--encoding", choices=list(ENCODINGS), default=DEFAULT_ENCODING)
            s.add_argument("--out", default="cbf_results.csv")
    a = p.parse_args()
    {"courses": cmd_courses, "split": cmd_split, "cbf": cmd_cbf, "weights": cmd_weights}[a.cmd](a)


if __name__ == "__main__":
    main()
