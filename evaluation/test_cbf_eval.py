"""Tests for cbf_eval.py. Run: python3 -m unittest evaluation/test_cbf_eval.py
All student rows here are synthetic fixtures for checking the algorithm, not study data."""
import csv
import math
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(__file__))
import cbf_eval as m  # noqa: E402


def student(sid, vec, actual="BS Architecture"):
    return {"StudentID": sid, **dict(zip(m.DIMENSIONS, map(str, vec))), "ActualCourse": actual}


class Vectors(unittest.TestCase):
    def test_proposed_encoding_examples(self):
        self.assertEqual(m.holland_to_vector("IRC"), [0.67, 1.00, 0.0, 0.0, 0.0, 0.33])
        self.assertEqual(m.holland_to_vector("RIC"), [1.00, 0.67, 0.0, 0.0, 0.0, 0.33])
        self.assertEqual(m.holland_to_vector("AES"), [0.0, 0.0, 1.00, 0.33, 0.67, 0.0])

    def test_invalid_codes_rejected(self):
        for bad in ("RI", "RRI", "RIX", "RIAS"):
            with self.assertRaises(ValueError):
                m.holland_to_vector(bad)

    def test_all_32_final_codes_valid_and_vectors_in_range(self):
        self.assertEqual(len(m.COURSES), 32)
        for vec in m.course_vectors(m.ENCODINGS["proposed"]).values():
            self.assertEqual(sorted(vec, reverse=True)[:3], [1.0, 0.67, 0.33])

    def test_binary_encoding_loses_letter_order(self):
        v = m.course_vectors(m.ENCODINGS["binary"])
        self.assertEqual(v["BS Computer Science"], v["BS Civil Engineering"])     # IRC vs RIC
        self.assertEqual(v["BS Computer Engineering"], v["BS Civil Engineering"])  # ICR vs RIC
        p = m.course_vectors(m.ENCODINGS["proposed"])
        self.assertNotEqual(p["BS Computer Science"], p["BS Civil Engineering"])


class Cosine(unittest.TestCase):
    def test_identical_orthogonal_opposite_zero(self):
        a = [0.67, 1, 0, 0, 0, 0.33]
        self.assertAlmostEqual(m.cosine(a, a), 1.0)
        self.assertAlmostEqual(m.cosine(a, [x * 7 for x in a]), 1.0)
        self.assertEqual(m.cosine([1, 0, 0, 0, 0, 0], [0, 1, 0, 0, 0, 0]), 0.0)
        self.assertAlmostEqual(m.cosine([1, 2, 3], [-1, -2, -3]), -1.0)
        self.assertEqual(m.cosine([0] * 6, a), 0.0)
        self.assertEqual(m.cosine([0] * 6, [0] * 6), 0.0)

    def test_hand_computed(self):
        s = [0.72, 0.91, 0.40, 0.35, 0.60, 0.78]
        c = [0.67, 1.00, 0, 0, 0, 0.33]
        expected = (0.72 * 0.67 + 0.91 + 0.78 * 0.33) / (math.sqrt(sum(x * x for x in s)) * math.sqrt(0.67**2 + 1 + 0.33**2))
        self.assertAlmostEqual(m.cosine(s, c), expected)


class Ranking(unittest.TestCase):
    def setUp(self):
        self.cv = m.course_vectors(m.ENCODINGS["proposed"])

    def test_known_best_course(self):
        ranked = m.rank_courses(m.holland_to_vector("ARI"), self.cv)
        self.assertEqual(ranked[0][:1], ("BS Architecture",))
        self.assertAlmostEqual(ranked[0][1], 1.0)

    def test_all_32_ranked_in_descending_order(self):
        ranked = m.rank_courses([0.1, 0.9, 0.3, 0.2, 0.6, 0.4], self.cv)
        self.assertEqual(len(ranked), 32)
        scores = [s for _, s, _ in ranked]
        self.assertEqual(scores, sorted(scores, reverse=True))
        self.assertEqual([r for _, _, r in ranked], sorted(r for _, _, r in ranked))

    def test_profile_with_no_exact_course_code_still_ranks_everything(self):
        # Student's top-3 letters are A, I, S ("AIS") - no course has that code.
        ranked = m.rank_courses([0.2, 0.8, 0.9, 0.7, 0.1, 0.1], self.cv)
        self.assertEqual(len(ranked), 32)
        self.assertLess(ranked[0][1], 1.0)

    def test_identical_course_vectors_share_rank_and_tie_metrics(self):
        ranked = m.rank_courses(m.holland_to_vector("IRC"), self.cv)
        irc = [n for n, _, r in ranked if r == 1]
        self.assertEqual(sorted(irc), sorted(["BS Computer Science", "BS Information Technology",
                                              "BS Medical Technology", "BS Chemical Engineering"]))
        hit1, rr, best_rank, ties = m.tie_aware(ranked, "BS Information Technology", 1)
        self.assertEqual((best_rank, ties), (1, 4))
        self.assertAlmostEqual(hit1, 0.25)
        self.assertAlmostEqual(rr, (1 + 1 / 2 + 1 / 3 + 1 / 4) / 4)
        self.assertAlmostEqual(m.tie_aware(ranked, "BS Information Technology", 3)[0], 0.75)
        self.assertAlmostEqual(m.tie_aware(ranked, "BS Information Technology", 4)[0], 1.0)

    def test_zero_vector_student_does_not_crash(self):
        ranked = m.rank_courses([0] * 6, self.cv)
        self.assertTrue(all(s == 0.0 and r == 1 for _, s, r in ranked))

    def test_actual_course_cannot_influence_ranking(self):
        a = student("X", [0.3, 0.4, 0.9, 0.5, 0.7, 0.2], "BS Nursing")
        b = dict(a, ActualCourse="BS Marketing")
        self.assertEqual(m.rank_courses(m.student_vector(a), self.cv), m.rank_courses(m.student_vector(b), self.cv))


class Scaling(unittest.TestCase):
    def test_raw50_preserves_cosine_minmax_changes_it(self):
        raw = student("X", [30, 45, 20, 18, 25, 40])
        cv = m.holland_to_vector("IRC")
        unit = m.cosine([float(raw[d]) for d in m.DIMENSIONS], cv)
        self.assertAlmostEqual(m.cosine(m.student_vector(raw, "raw50"), cv), unit)
        self.assertNotAlmostEqual(m.cosine(m.student_vector(raw, "minmax"), cv), unit)

    def test_out_of_range_rejected(self):
        with self.assertRaises(ValueError):
            m.student_vector(student("X", [1.2, 0, 0, 0, 0, 0]))
        with self.assertRaises(ValueError):
            m.student_vector(student("X", [5, 20, 20, 20, 20, 20]), "raw50")


class Evaluation(unittest.TestCase):
    def test_metrics_relationships(self):
        cv = m.course_vectors(m.ENCODINGS["proposed"])
        rows = [student("1", m.holland_to_vector("ARI"), "BS Architecture"),   # rank 1
                student("2", m.holland_to_vector("ARI"), "BS Nursing")]        # far down
        res = m.evaluate(rows, cv, 3, "unit")
        self.assertAlmostEqual(res["HitRate@3"], 0.5)
        self.assertAlmostEqual(res["Recall@3"], res["HitRate@3"])
        self.assertAlmostEqual(res["Precision@3"], 0.5 / 3)
        self.assertGreater(res["MRR"], 0.5)

    def test_split_and_arff(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "students.csv")
            rows = [student(f"S{i}", [0.5] * 6, c) for i, c in
                    enumerate(["BS Nursing"] * 4 + ["BS Marketing"] * 3 + ["BS Biology"])]
            with open(path, "w", newline="") as f:
                w = csv.DictWriter(f, ["StudentID", *m.DIMENSIONS, "ActualCourse"])
                w.writeheader()
                w.writerows(rows)

            class A: students, scale, test_fraction, seed = path, "unit", 0.3, 1
            m.cmd_split(A)
            test_ids = {r["StudentID"] for r in csv.DictReader(open(os.path.join(d, "test.csv")))}
            train_ids = {r["StudentID"] for r in csv.DictReader(open(os.path.join(d, "train.csv")))}
            self.assertFalse(test_ids & train_ids)                 # no student in both
            self.assertEqual(len(test_ids | train_ids), 8)
            self.assertIn("S7", train_ids)                         # single-student class stays in train
            arff = open(os.path.join(d, "train.arff")).read()
            self.assertNotIn("StudentID", arff)                    # ID is never a WEKA attribute
            self.assertEqual(arff.count("@ATTRIBUTE"), 7)          # R..C + class only


if __name__ == "__main__":
    unittest.main()
