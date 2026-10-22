# ProfilePath

A web-based career profiling system for senior high school students, built around a RIASEC
interest assessment, content-based career recommendations, and counselor/admin tooling for
academic monitoring, security, and reporting.

- **Backend:** PHP 8.0+ (8.4 in production; built-in server, no framework)
- **Database:** PostgreSQL 17
- **Frontend:** Static HTML/CSS/JS (no build step)
- **Deployment:** [Render](https://render.com) via Docker (see `render.yaml`, `Dockerfile`)

## Roles

- **Student** — registers with an MMCL email, completes the RIASEC assessment, views career
  recommendations, worksheets, and program info.
- **Counselor** — manages student rosters, counseling requests/notes, announcements, and
  monitoring.
- **Admin** — everything a counselor can do, plus staff account management, security/RBAC
  configuration, analytics, and audit logs.

## Project layout

```
api/        JSON API endpoints (one file per action, e.g. api/login.php)
lib/        Shared PHP classes (Database, Auth, Crypto, CBFEngine, Rbac, Mailer, ...)
db/         Schema, migrations, and seed scripts
tests/      Standalone PHP test scripts (no test framework/DB dependency for most)
images/     Static assets, grouped by page/section
*.html      One static page per route (served without the .html extension, see router.php)
config/     .env loader
```

## Prerequisites

- PHP 8.0+ (8.4 in production) with the `pdo_pgsql` and `pgsql` extensions enabled
- PostgreSQL 17 (running locally, or a connection string to a remote instance)

## Local setup

1. **Copy the environment file and fill it in:**

   ```bash
   cp .env.example .env
   ```

   - `DB_*` — your local Postgres connection details.
   - `APP_AES_KEY` — generate with `php -r "echo base64_encode(random_bytes(32));"`.
   - `BREVO_API_KEY`, `SMTP_FROM_EMAIL` — only needed if you want real emails to send
     (registration/verification/password-reset). Leave blank for local dev; in `APP_ENV=local`,
     unsent emails are logged instead of failing silently (see `api/forgot-password.php`).

   **Shortcut for a new empty database (local or Render):** steps 2 to 4 in one command, which also
   runs every migration in order and stops if the database already has accounts:

   ```bash
   php db/setup_new_database.php
   ```

2. **Create the database and apply the schema:**

   ```bash
   php db/create_database.php
   php db/run_schema.php
   ```

3. **Run the migrations** (each is idempotent/`IF NOT EXISTS`-guarded; run in this order):

   ```bash
   php db/migrate_add_sessions_table.php
   php db/migrate_add_email_verification.php
   php db/migrate_add_avatar.php
   php db/migrate_academic_year.php
   php db/migrate_add_section.php
   php db/migrate_add_announcements.php
   php db/migrate_add_program_description.php
   php db/migrate_drop_faculty.php
   php db/migrate_update_strands.php
   php db/migrate_add_assessment_roster.php
   php db/migrate_add_examinations.php
   php db/migrate_add_counseling_notes.php
   php db/migrate_add_program_careers.php
   php db/migrate_add_staff_approval.php
   php db/migrate_announcement_features.php
   php db/migrate_add_program_related_strands.php
   php db/migrate_final_holland_codes.php
   php db/migrate_add_recommendation_status.php
   php db/migrate_add_recommendation_sets.php
   php db/migrate_add_counseling_invites.php
   php db/migrate_add_facilitator_role.php
   php db/migrate_add_retake_requests.php
   php db/migrate_add_staff_accounts_rbac.php
   ```

4. **Seed reference data and a default admin account:**

   ```bash
   php db/seed_colleges.php
   php db/seed_programs.php
   php db/backfill_program_careers.php
   php db/seed_questions.php
   php db/seed_security_defaults.php
   php db/seed_admin.php
   ```

   This creates the `admin` account with a temporary password (see `db/seed_admin.php`, never post it anywhere). Sign in and change it right away, and always before any real deployment.

5. **Run the app:**

   ```bash
   php -S localhost:8000 router.php
   ```

   Visit `http://localhost:8000`.

## Tests

```bash
php tests/aes_roundtrip_test.php
php tests/cbf_test.php
php tests/pipeline_test.php
php tests/prediction_model_test.php
php tests/result_email_test.php
php tests/rbac_audit_test.php
```

The first two are self-contained (no database needed). `rbac_audit_test.php` needs a database
connection matching `.env`.

## Recommendation engine (CBF with cosine similarity)

Three recommendation sources are kept separate (`lib/RecommendationPipeline.php`):

1. **CBF** (`lib/CBFEngine.php`): the student's RIASEC profile (mean item score per type,
   [R, I, A, S, E, C]) is compared with every program's Holland code using cosine
   similarity, cos(S, C) = (S · C) / (||S|| × ||C||). The guidance counselors rank the three letters of a
   code (1st = strongest type, 3rd = weakest), so the program vector uses rank weights 3, 2, 1
   (`holland_rank_weights` in `config/cbf.php`; RIC = [3, 2, 0, 0, 0, 1]). No 70/30 blend with the worksheet.
2. **Worksheet**: the Preferred Course from the Career Electives Worksheet.
3. **Prediction model (WEKA)**: not available yet; excluded and not called. The existing WEKA
   code (`lib/PredictionModel.php`, `db/import_weka_tree.php`) is kept for the separate WEKA work.

The results page shows, in order: RIASEC profile, **Preferred Course** (what the student selected),
**Best RIASEC Match** (courses tied at the highest cosine similarity; CBF only) and **Alternative
Courses** (next-highest similarity). The Preferred Course never changes the CBF result. The final
Best Match (CBF + prediction model + worksheet) is not computed until the prediction model exists.
Cosine similarity is shown as "Cosine Similarity: 0.xxx", never as a percentage or an accuracy.

To see the full CBF calculation for a student (student vector, and for every course its vector,
dot product, magnitudes and cosine similarity):

```bash
php db/cbf_debug.php <school_id>          # readable report
php db/cbf_debug.php <school_id> --csv    # all courses (e.g. for Chapter 4)
php db/cbf_debug.php <school_id> --json   # everything as JSON
```

## Deployment

`render.yaml` provisions a free Postgres instance and a Docker web service on Render. Push to
the connected branch, or use Render's Blueprint deploy from this repo. Secrets marked
`sync: false` (`APP_URL`, `APP_AES_KEY`, `BREVO_API_KEY`, `SMTP_FROM_EMAIL`) must be set manually
in the Render dashboard after the first deploy.

## Security notes

- Encrypted columns (suffixed `_enc`) use a Modified AES-256-CBC cipher with a key-dependent
  S-box (`lib/Crypto.php`, `lib/ModifiedAES256.php`) and are never used in `WHERE`/`ORDER BY`/
  `JOIN` — see the header comment in `db/schema.sql` for the convention.
- Module-level access is enforced via `lib/Rbac.php`; see `security-configuration.html` and
  `api/security-config.php` for the admin-facing config UI.
- All sensitive/state-changing actions are recorded via `lib/AuditLogger.php` and viewable on
  `audit-log.html`.
