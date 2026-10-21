"""Tests for rule_review.py. Run: python3 -m unittest evaluation/test_rule_review.py"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import rule_review as rr  # noqa: E402


class Combos(unittest.TestCase):
    def test_twenty_in_form_order(self):
        c = rr.combos()
        self.assertEqual(len(c), 20)
        self.assertEqual((c[0], c[13], c[19]), ("RIA", "ISE", "SEC"))   # rules 1, 14 and 20 on the form


class Answers(unittest.TestCase):
    def test_codes_and_words(self):
        self.assertEqual([rr.code(x) for x in ["M", "makes sense", "D", "Doesn't make sense", "N", "not sure", ""]],
                         ["M", "M", "D", "D", "N", "N", None])
        with self.assertRaises(SystemExit):
            rr.code("maybe")


class Fleiss(unittest.TestCase):
    def test_perfect_agreement_is_1(self):
        table = [{"M": 3, "D": 0, "N": 0}, {"M": 0, "D": 3, "N": 0}]
        self.assertAlmostEqual(rr.fleiss_kappa(table), 1.0)

    def test_hand_computed_value(self):
        # 2 raters, 4 rules: agree M, agree M, split M/D, agree D.
        # p_M = 5/8, p_D = 3/8 -> Pe = 34/64; P_i = 1, 1, 0, 1 -> Pbar = 0.75; kappa = (0.75 - 34/64) / (1 - 34/64)
        table = [{"M": 2, "D": 0, "N": 0}, {"M": 2, "D": 0, "N": 0}, {"M": 1, "D": 1, "N": 0}, {"M": 0, "D": 2, "N": 0}]
        self.assertAlmostEqual(rr.fleiss_kappa(table), (0.75 - 34 / 64) / (1 - 34 / 64))

    def test_everyone_same_category_is_undefined(self):
        self.assertIsNone(rr.fleiss_kappa([{"M": 3, "D": 0, "N": 0}, {"M": 3, "D": 0, "N": 0}]))


if __name__ == "__main__":
    unittest.main()
