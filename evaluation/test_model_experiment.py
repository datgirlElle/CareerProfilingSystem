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


if __name__ == "__main__":
    unittest.main()
