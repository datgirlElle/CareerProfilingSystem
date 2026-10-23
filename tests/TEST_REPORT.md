# ProfilePath Test Report (partial run)

Run date: 2026-10-18. Environment: Windows 11, PHP 8.4.24 CLI built-in server, PostgreSQL 17, database `profilepath_test` (fresh schema, all migrations, seeds; synthetic `qa_` users only). The dev database `profilepath` was not written to by my runs except that the original `rbac_audit_test.php` ran against it before I switched (it uses rolled-back transactions). No browser/UI testing was done. Code was not under git revision control in the OneDrive folder; changes are in D:\Capstone\CareerProfilingSystem (git diff shows them).

## Executive summary
Core algorithms (RIASEC scaling, cosine similarity, Best Match / Alternatives selection) match independent hand calculations. Three confirmed defects were found and fixed. Large parts of the master plan were NOT executed (see Not tested). Readiness: **READY WITH KNOWN LIMITATIONS** for the areas tested, but not a clean bill for the whole system because UI, e-mail, upload, load and cryptographic review items remain open.

## Confirmed defects
| ID | Module | Severity | Finding | Fix | Retest |
|---|---|---|---|---|---|
| BUG-01 | Counseling notes (`api/counseling-notes.php`) | High | A counselor assigned only to section QA-A could read and write notes for a student in another section by passing `studentId` (StaffScope was applied to other endpoints but not here). | Added a StaffScope check to GET and POST; out-of-scope student returns 404. | PASS (http_security_test) |
| BUG-02 | Encryption (`modules/Encryption/lib/Crypto.php`) | High | CBC with no integrity check. Flipping one bit in each of 80 ciphertext bytes: 48 were silently accepted and decrypted to altered plaintext, 32 rejected only by chance via padding. | Version 2 format adds HMAC-SHA256 (key derived from the master key, encrypt-then-MAC, constant-time compare). Version 1 rows remain readable. | PASS: 112/112 flips rejected; legacy v1 read OK; aes_roundtrip_test 23/23 |
| BUG-03 | CSV export (`api/analytics-export.php`) | Low | Names beginning with `= + - @` were exported unescaped (formula injection). Source is staff-uploaded roster data, so risk is low. | Same prefix-quote neutralisation already used in `students.php`. | Syntax check only; no export run. NOT fully retested |
| TEST-01 | `tests/rbac_audit_test.php` | Low | Test expected announcements/sections = Full for counselors, contradicting seeded defaults (View Only). It also depended on live dev DB state. | Updated expectations to the seeded defaults. | 74/74 on test DB |

## Test results (counts are from scripts I ran)
- Existing unit scripts: 26 files, all pass on the test DB after TEST-01 fix.
- New `tests/algorithm_independent_test.php`: 34 pass, 0 fail (scaling 5..25 -> 10..50, vector = scaled/10, hand-computed cosines 0.9258 / 0.8487 / 0.3086 / 0.7379, ties, invalid input, empty program list).
- New `tests/crypto_robustness_test.php`: 19 pass after BUG-02 fix (1 fail before).
- New `tests/http_security_test.php`: 65 pass, 0 fail (after BUG-01 fix 2 failed): unauthenticated access, CSRF, generic login errors, SQLi payloads on login/search/filter/career/help, 5-attempt lockout of ~15 minutes, logout invalidation, student/counselor/facilitator direct-request restrictions, cookie flags and security headers.
- Final regression: all 29 `tests/*_test.php` files exit 0.

## Observations (not confirmed defects)
- Dev database `profilepath` has the Counselor/Facilitator "counselor" (staff account management) permission set to No Access, unlike the seed default (Full). Likely an admin setting; confirm it is intended.
- Legacy `db/migrate_counselor_write_access.php` raises counselor announcements/sections to Full, contradicting the current View Only rule if re-run on a fresh database. `db/migrate_encrypt_help_requests.php` fails on a fresh schema. Both are install-order hazards.
- The modified AES-256 (key-derived S-box) is not shown to be stronger than standard AES; the team's own benchmark shows lower nonlinearity. Recommend standard AES-256-GCM (OpenSSL) for new data. Round-trip tests do not prove strength.
- No IP-based rate limit on `api/login.php` (only per-account lockout); the 423 lockout reply reveals that the account exists.
- Tie-break for the RIASEC code is implicit (R,I,A,S,E,C order via stable sort); it is documented in code comments only.
- The J48 module is disabled (`decision_tree.enabled=false`); its tests pass but it was not exercised end to end.

## Performance (single-threaded PHP built-in server, sequential requests, local machine; not a load test)
session.php n=50 median 170 ms, p95 307 ms; students list n=15 median 318 ms; analytics median 340 ms; monitoring median 255 ms; audit-log median 340 ms; programs median 526 ms. Login timing is inconclusive: 5 of 10 scripted logins failed because of my harness (cause not investigated), so no login figure is claimed. Concurrency, CPU and memory were not measured.

## NOT TESTED / BLOCKED
Browser UI, responsive and accessibility checks; e-mail delivery (no Brevo key); registration with verification, password reset expiry, retake flow, access-code windows; roster upload (CSV/Excel malformed, oversized); XSS rendering in the frontend (169 innerHTML uses not audited); report/principal exports and Excel output; transaction rollback and DB failure modes; session timeout; concurrent load; WEKA evaluation metrics (no dataset); database constraint review beyond the schema loading.

## Addendum: browser UI pass (2026-10-18, test database, built-in browser, synthetic student `qa_ui_stu`)
| ID | Scenario | Result |
|---|---|---|
| UI-01 | Student login through the form | PASS |
| UI-02 | Student first name `<img src=x onerror=alert(1)>` rendered in sidebar/header on 4 pages | PASS: shown as plain text, no script fired |
| UI-03 | Wrong access code, then correct code | PASS: wrong logged as access_code_failed, correct as access_code_verified; assessment opened |
| UI-04 | Next disabled until a question is answered | PASS |
| UI-05 | Answered 30 items (R5,I4,A3,S2,E1,C3 per item) | PASS: stored scores R50 I40 A30 S20 E10 C30 exactly as expected; Holland code R-I-A (A/C tie broken by letter order) |
| UI-06 | Results page score display | PASS: matches stored values |
| UI-07 | Worksheet: empty submit and electives rule | PASS: validation message shown |
| UI-08 | Worksheet with career "Software Engineer" | PASS: matched BS Computer Science, saved |
| UI-09 | Best RIASEC Match count vs independent calculation | PASS: page shows 12 tied courses; my separate script finds 12 at cosine 0.866 |
| UI-10 | No percentages/"cosine" shown to student | PASS |
| UI-11 | Registration with a Student Number not on the roster | PASS: rejected (404 message) |
| UI-12 | Mobile 375 px: horizontal overflow on assessment, results, settings, help center | PASS: none |
| UI-13 | Alt text, button/link names | PASS on the 4 pages checked |

Minor findings, not fixed (Low): login page title says "CareerPath Login" while the product is ProfilePath; `results` page has 3 `<h1>`; file input `photoInput` on student-settings has no label; 20 links/buttons under 32 px tall on `results` at mobile width. Not tested in the browser: staff and admin dashboards, email verification and password reset, tablet width, keyboard-only navigation, color contrast, retake-free second attempt, saved careers, counseling requests. Note: the pane started with a 0x0 viewport; I set sizes explicitly. Pages cannot be framed (X-Frame-Options DENY), which blocked an iframe-based bulk scan, so only 4 pages got the accessibility script.

## Addendum 2: staff and admin dashboards (2026-10-19, browser + API, test database)
| ID | Scenario | Result |
|---|---|---|
| ST-01 | Admin login; dashboard totals (2 students, 1 assessed, 1 pending, 0 mismatched) vs list, CSV and database | PASS: all four agree |
| ST-02 | Student names with HTML and formula text in the staff table | PASS: shown as text; no script ran |
| ST-03 | Student list CSV and analytics CSV with a name starting `=`/`+` | PASS: prefixed with `'` (confirms BUG-03 fix in the analytics export) |
| ST-04 | Student profile: scores, cosine values (0.866 Best Match, 0.794 Alternatives) | PASS: match my independent calculation |
| ST-05 | Add counseling note containing HTML; display and storage | PASS: shown as text; stored encrypted (version-2 value, no plaintext); audit entry written |
| ST-06 | Security policies: change lockout/password policy, read back, restore | PASS: persisted; values clamped (lockout 20/1440, password min length 6); unknown policy key rejected (400) |
| ST-07 | Set Administrator column to No Access / Student column to Full | PASS: both refused (403) |
| ST-08 | Audit log entries for policy changes | PASS: actor, role, time, module, IP recorded; the old and new values were NOT checked |
| ST-09 | Logout invalidates session | PASS (401 afterwards) |
| ST-10 | Facilitator: notes 403, staff approvals 403 | PASS |
| BUG-04 | Facilitator and counselor received the Principal's name and email from `GET security-config.php` | Fixed (Low): only the administrator receives them; regression checks added to `http_security_test.php` (67 pass) |

Observations (not defects): facilitators see the Account Management, Security Configuration and Audit Log links in the sidebar (the server limits what they can do; pages not opened in the UI); staff with RIASEC-assessment access can read the audit log, which includes administrator security-setting entries but hides student identities; the password policy allows a minimum length as low as 6. Not tested: Excel exports, Principal report e-mail, staff approval flow, roster upload UI, question bank editing, scheduling UI.

Process note: during this pass I ran one stray `git stash` in the repository, noticed it, and restored everything with `git stash pop`; I re-ran the syntax checks and tests afterwards.

## Addendum 3: roster upload, staff sign-up/approval, Excel export (2026-10-19, test database, `tests/roster_staff_http_test.php`: 56 pass, 0 fail)
- Roster upload: valid CSV and a generated .xlsx accepted; re-upload is idempotent; names stored encrypted. Rejected cleanly (4xx, no SQL or stack text, no rows saved): empty file, wrong or double extension, text or random bytes as .xlsx/.csv, header only, missing Strand/Section, bad strand, short or non-numeric Student Number, non-school email, missing email, duplicate numbers, a section code containing SQL text, a file over 2 MB and one of 12 MB (the 12 MB case is stopped by PHP's 8 MB post limit, logged as a warning). Anonymous and student uploads refused; students cannot download the template.
- Staff flow: sign-up creates a pending counselor-role account (the extra field `role=admin` is ignored; position `admin` refused); duplicates, non-school email, missing consent and all-digit usernames refused; approved accounts cannot sign in until activated with the emailed one-time code; wrong code, weak password and code reuse refused; deactivated and rejected accounts cannot sign in; approval, activation and deactivation are in the audit log; the sign-up form is rate-limited to 5 per hour per IP (seen as HTTP 429 during testing). No real e-mail was sent (no mail key configured; messages go to the PHP log).
- Excel export: a valid .xlsx is returned, can be read back, text is stored as plain text cells (no formulas); anonymous request refused.
- Measured: a 3000-row roster upload took 8.8 s (about 3 ms per row, mostly the pure-PHP encryption). A normal class of about 40 students is not affected; a very large roster could time out on slow hosting.
- Observations (not defects): a roster name such as `=CMD|calc!A1, X` is accepted and stored; the CSV exports neutralise it and the Excel export stores it as text. Any roster editor can replace any section's roster (no per-section scope on upload). The old Excel fixture `tests/fixtures/roster_excel.xlsx` is in the pre-Email layout and is rejected by the live upload; it is only used by the parser unit test.
- Not tested: the upload and approval pages in the browser, real e-mail delivery, `ZipArchive` is not installed in this PHP (the test reads the workbook with the app's own reader), password-reset and email-verification flows.

## Addendum 4: password reset, email verification, registration, session timeout (2026-10-19, `tests/auth_flows_http_test.php`: 51 pass, 0 fail, server run with APP_ENV=production)
| ID | Finding | Result |
|---|---|---|
| BUG-05 | `api/register.php` put the e-mail verification link in the HTTP response whenever the e-mail could not be sent, in any environment. With a Student Number and its roster e-mail an outsider could register and activate that account without the inbox. Confirmed: the test failed before the fix. | Medium. Fixed: the link is returned only when `APP_ENV=local` (same rule `password-reset.php` already uses for its debug code). Retest PASS. |

Passing checks: reset code request answers the same for real and unknown e-mails; 60 s cooldown (429); wrong-code counter, lock after 5 wrong tries (even the correct code is then refused); expired code refused; student code unusable on the staff portal; correct code gives a one-time token; weak and reused passwords refused; token cannot be reused or guessed; SQL text as token refused; sessions open before a reset are signed out; old password stops working; bcrypt storage; a reset clears an account lockout; unverified accounts cannot reset. Registration: non-roster number (404), roster e-mail mismatch, weak password, missing consent, SQL text and duplicate all refused; name comes from the roster and is stored encrypted; unverified students cannot sign in; verification tokens are single-use, expire, and cannot be used by a plain GET; resend answers identically for real and unknown accounts. Session timeout: with a 5 minute limit a session idle 4 minutes works and one idle 6 minutes is signed out and stays signed out; with the timeout switched off an old session still works. (The idle time was set by editing the stored session, not by waiting.)

Remaining risk (not fixed, design level): **pre-registration takeover.** Registration sets the password before the e-mail is proven. Someone who knows a student's Student Number and roster e-mail (the e-mail pattern is predictable) can register first with their own password; when the real student later verifies through the link, the account becomes active with the attacker's password. Suggested fix for the team to decide: have the student choose the password after the e-mail link is opened, or e-mail a code first. Also: the e-mail text and links were not inspected because no mail service is configured; the pages (forgot-password, verify-email, register) were tested through the API only.

Note on the Excel export: with the server in production mode and no mail service, `api/analytics-export-xlsx.php` answers 502 ("the edit password could not be emailed"). This is intended (the workbook's edit password is delivered only by e-mail), so it is not a defect; `roster_staff_http_test.php` now accepts it, and checks the workbook content only when the server runs with APP_ENV=local. Final regression: all 31 test scripts pass against a production-mode server, and the three HTTP suites pass against a local-mode server.

## Defect summary so far
BUG-01 High (counseling notes out of section scope), BUG-02 High (no encryption integrity check), BUG-05 Medium (verification link in response), BUG-03 Low (CSV formula injection), BUG-04 Low (Principal contact shown to other staff), TEST-01 Low (stale RBAC test). All fixed and retested. Open risks: pre-registration takeover (design), modified-AES strength, no per-IP login limit, Low UI items.

## Addendum 5: pre-registration takeover fixed (2026-10-19)
Change: the password is no longer taken at registration. `api/register.php` stores an unusable random password hash; `api/verify-email.php` requires the person who opens the emailed link to choose the password (checked against the password policy; a refused password leaves the link usable); `student-register.html` no longer has a password field; `verify-email.html` has a "Choose your password" step with the policy rules. `api/login.php` now tells an unverified student to verify (403, shows the resend button) before checking the password, because the account has no usable password until then (this reveals that a Student Number is registered but unverified; judged low impact).
Evidence: `auth_flows_http_test.php` 56 pass, including "TAKEOVER CHECK": a password sent at registration is ignored and does not work after the owner verifies; the owner's chosen password works. Browser run (production-mode server, test database): register page without password, verify page rejected a weak password and a mismatch, accepted a strong one, redirected to login, and the student signed in. All 31 test scripts pass.
Side effects to know: students who registered with the old form but had not yet verified must choose a password when they open their link; staff sign-up (separate flow) is unchanged. Not re-tested: e-mail text (no mail service), tablet widths of the changed pages.
