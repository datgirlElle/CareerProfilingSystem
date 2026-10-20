"""Tests for model_experiment.py (no WEKA needed). Run: python3 -m unittest evaluation/test_model_experiment.py"""
import math
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import model_experiment as me  # noqa: E402

C = me.COURSES


def scores(**kw):
    s = {c: 0.0 for c in C}
    s.update(kw)
    return s


class Ranks(unittest.TestCase):
    def test_ties_share_the_average_rank(self):
        self.assertEqual(me.ranks([40, 50, 10, 10, 20, 40]), [2.5, 1.0, 5.5, 5.5, 4.0, 2.5])

    def test_scale_free(self):
        self.assertEqual(me.ranks([8, 10, 2, 2, 4, 8]), me.ranks([40, 50, 10, 10, 20, 40]))

    def test_rank_features_match_php(self):
        row = {"R": "40", "I": "50", "A": "10", "S": "10", "E": "20", "C": "40"}
        self.assertEqual(me.features(row, "rank"), ["2.5", "1", "5.5", "5.5", "4", "2.5"])
        self.assertEqual(me.features(row, "top3"), ["x", "x", "blank", "blank", "blank", "x"])


class RankStats(unittest.TestCase):
    def test_unique_first(self):
        h1, h3, rr, ndcg = me.rank_stats(scores(**{C[0]: 0.9, C[1]: 0.5}), C[0], 3)
        self.assertEqual((h1, h3, rr, ndcg), (1.0, 1.0, 1.0, 1.0))

    def test_second_place(self):
        h1, h3, rr, ndcg = me.rank_stats(scores(**{C[0]: 0.9, C[1]: 0.5}), C[1], 3)
        self.assertEqual((h1, h3, rr), (0.0, 1.0, 0.5))
        self.assertAlmostEqual(ndcg, 1 / math.log2(3))

    def test_tie_is_an_expected_value(self):
        # 4 courses tied at the top: expected Hit@1 = 1/4, Hit@3 = 3/4.
        s = scores(**{C[i]: 0.9 for i in range(4)})
        h1, h3, rr, ndcg = me.rank_stats(s, C[2], 3)
        self.assertAlmostEqual(h1, 0.25)
        self.assertAlmostEqual(h3, 0.75)
        self.assertAlmostEqual(rr, (1 + 1 / 2 + 1 / 3 + 1 / 4) / 4)
        self.assertAlmostEqual(ndcg, (1 + 1 / math.log2(3) + 1 / math.log2(4)) / 4)


class Folds(unittest.TestCase):
    def test_stratified_and_deterministic(self):
        rows = [{"ActualCourse": C[i % 3]} for i in range(30)]
        f1 = me.stratified_folds(rows, 5, 7)
        self.assertEqual(f1, me.stratified_folds(rows, 5, 7))
        for c in C[:3]:
            per_fold = [sum(1 for r, f in zip(rows, f1) if r["ActualCourse"] == c and f == k) for k in range(5)]
            self.assertEqual(sorted(per_fold), [2] * 5)


class Classification(unittest.TestCase):
    def test_perfect_and_kappa(self):
        m = me.classification(["a", "b", "a"], ["a", "b", "a"])
        self.assertEqual((m["accuracy"], m["macroF1"], m["kappa"]), (1.0, 1.0, 1.0))

    def test_always_majority_has_zero_kappa(self):
        m = me.classification(["a", "a", "b", "c"], ["a", "a", "a", "a"])
        self.assertAlmostEqual(m["accuracy"], 0.5)
        self.assertAlmostEqual(m["kappa"], 0.0)


class HybridShare(unittest.TestCase):
    def test_share_sums_to_one(self):
        self.assertAlmostEqual(sum(me.share(scores(**{C[0]: 0.9, C[1]: 0.3})).values()), 1.0)


class Filtered(unittest.TestCase):
    def test_candidates_come_first_ordered_by_probability(self):
        cos = scores(**{C[0]: 0.9, C[1]: 0.9, C[2]: 0.7, C[3]: 0.5})
        prob = scores(**{C[1]: 0.6, C[3]: 0.4})          # classifier prefers C1, and C3 (not a candidate)
        out = me.filtered(cos, prob, "best")
        ranked = sorted(out, key=lambda c: -out[c])
        self.assertEqual(ranked[:2], [C[1], C[0]])        # both Best Match courses first, C1 above C0
        self.assertEqual(ranked[2], C[2])                 # then cosine order; C3's probability is ignored
        self.assertLess(out[C[3]], out[C[0]])

    def test_best_plus_alternatives(self):
        cos = scores(**{C[0]: 0.9, C[2]: 0.7})
        prob = scores(**{C[2]: 0.8})
        out = me.filtered(cos, prob, "best+alt")
        self.assertGreater(out[C[2]], out[C[0]])           # an Alternative can lead when the classifier prefers it

    def test_cbf_levels(self):
        best, alt = me.cbf_levels(scores(**{C[0]: 0.9, C[1]: 0.9, C[2]: 0.7}))
        self.assertEqual((best, alt), ({C[0], C[1]}, {C[2]}))


class TreeWalker(unittest.TestCase):
    def test_python_walker_matches_php_rules(self):
        import cbf_eval as ce
        tree = {"attribute": "R", "branches": [
            {"op": "=", "value": "x", "node": {"class": "BS Civil Engineering"}},
            {"op": "=", "value": "blank", "node": {"class": "BS Nursing"}}]}
        ric = {"R": "40", "I": "50", "A": "10", "S": "10", "E": "20", "C": "40"}
        isa = {"R": "10", "I": "50", "A": "30", "S": "40", "E": "20", "C": "15"}
        self.assertEqual(ce.predict_tree({"tree": tree}, ric), "BS Civil Engineering")
        self.assertEqual(ce.predict_tree({"tree": tree}, isa), "BS Nursing")
        rank_tree = {"attribute": "S", "branches": [
            {"op": "<=", "value": "2", "node": {"class": "BS Psychology"}},
            {"op": ">", "value": "2", "node": {"class": "BS Civil Engineering"}}]}
        self.assertEqual(ce.predict_tree({"tree": rank_tree, "features": "rank"}, isa), "BS Psychology")


class Confirm(unittest.TestCase):
    def test_j48_course_inside_best_match_goes_first(self):
        cos = scores(**{C[0]: 0.9, C[1]: 0.9, C[2]: 0.7})
        out, ok = me.confirm(cos, scores(**{C[1]: 0.6, C[2]: 0.3}))
        self.assertTrue(ok)
        self.assertEqual(sorted(out, key=lambda c: -out[c])[:3], [C[1], C[0], C[2]])

    def test_j48_course_outside_best_match_changes_nothing(self):
        cos = scores(**{C[0]: 0.9, C[1]: 0.9, C[2]: 0.7})
        out, ok = me.confirm(cos, scores(**{C[2]: 0.9}))
        self.assertFalse(ok)
        self.assertEqual(out, cos)


if __name__ == "__main__":
    unittest.main()
