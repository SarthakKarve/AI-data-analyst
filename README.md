AI CIBIL Store — Project Overview

This project is a PHP/MySQL web application that helps users manage loans, reminders, documents, estimate CIBIL-like credit scores, and explore insurance suitability. It uses secure session-based flows, PDO for database access, and a clean Tailwind UI.

**Key Capabilities**
- Authentication with email/password (secure hashing) and demo Google Sign-In.
- Dashboard with KPIs (loans, CIBIL score, reminders, documents) and actionable recommendations.
- Loans management: create/update/delete, EMI calculation, next-due reminders, CSV export.
- Documents management: secure uploads/downloads, optional AWS S3 storage.
- Reminders: CRUD, link to loans, statuses and counts for badges.
- Insurance calculator: eligibility, claim probability, and coverage suggestions.
- ChatBot: context-aware answers, finance definitions, EMI calculator, and file text review.
- CIBIL Predictor: estimates scores from database metrics or manual inputs and persists predictions.

**Tech Stack**
- PHP 8+ (procedural style) with `PDO` for MySQL.
- MySQL database (`cibil`).
- Frontend: Tailwind CSS CDN and Remixicon.
- Optional integrations: AWS S3 (for document storage).

**Project Structure**
- `db.php` – PDO connection bootstrap.
- `login.php`, `register.php`, `logout.php` – Auth flows (password login, OTP demo, Google sign-in, logout).
- `index.php` – Main dashboard and recommendations.
- `loans.php` – Loans CRUD, EMI calculation, reminder scheduling, CSV export.
- `documents.php` – File uploads/downloads, optional S3, stats and pagination.
- `reminders.php` – Reminders CRUD with loan linking and filters.
- `predictor.php` – Score prediction from DB or manual features; persists to `cibil_scores`.
- `insurance.php` – Insurance eligibility, heuristic claim probability, coverage suggestions.
- `chatbot.php` – Context-aware assistant, simple definitions, EMI calculator, and file text extraction.
- `profile.php` – User profile management and preference columns.
- `database.txt` – SQL to initialize the schema.

Working Principles
------------------

**Sessions & Security**
- All feature pages start with `session_start()` and enforce login: unauthenticated users are redirected to `login.php`.
- CSRF protection via `$_SESSION['csrf']` tokens and `hash_equals()` checks for POST actions.
- `PDO` prepared statements used throughout to prevent SQL injection.
- Output is HTML-escaped via a common helper `h()`.

**Loans & EMI Calculation**
- EMI formula (fixed-rate): `EMI = P * r * (1+r)^n / ((1+r)^n - 1)` where `P` is principal, `r` is monthly rate, and `n` is number of months.
- Next due date helpers compute installment schedules (Monthly/Quarterly/Yearly) and auto-create upcoming reminders for active loans.
- CSV export lets users download filtered loan lists via `?export=csv`.

**Documents Handling**
- Files are stored under `uploads/` with unique names and sanitized filenames.
- Optional S3: If environment variables are set, files upload to S3 and are retrieved via presigned URLs.
- Download flow serves files with correct MIME and content headers.

**Reminders**
- Reminders can be standalone or linked to loans; statuses: `Upcoming`, `Completed`, `High Priority`.
- Pages display counts and badges in headers.

**Insurance Calculator**
- Inputs: age, employment type, monthly income, dependents, existing coverage, health risk.
- Eligibility considers age range and minimum monthly income; documents presence informs the message.
- Claim probability heuristic adjusts a base probability with factors: EMI/income ratio, doc count, high-priority reminders, employment, age, health risk.
- Coverage suggestions compute:
  - Loan Protection Coverage ≈ outstanding active loan sum minus existing coverage.
  - Income Protection Coverage ≈ 12–18 months of income (more if dependents).
  - Health Coverage typical bands by health risk.

**ChatBot**
- Responds using platform context (loans, EMIs, reminders, documents, latest score) and simple intents:
  - Finance definitions (CIBIL, EMI, interest rate, secured vs unsecured, KYC, NBFC).
  - EMI calculator from free-form text (“Calculate EMI 250000 11 36”).
  - Show active loans, upcoming reminders, document counts.
- Handles file attachments (PDF/DOCX/TXT/images) with light text extraction for context and cross-check.

**CIBIL Predictor (AI Logic)**
- Baseline heuristic model:
  - Derives features from user data (e.g., credit utilization, EMI/monthly income ratio, loan count, pending reminders, average interest rate, credit age).
  - Applies weighted adjustments to a baseline score and bounds to the 300–900 range.
- ML Concepts for a production-grade predictor (no specific vendor API):
  - Problem framing: Regression to predict a score in `[300, 900]`.
  - Feature engineering: 
    - Credit utilization (total outstanding ÷ annual income).
    - EMI burden ratio (monthly EMIs ÷ monthly income).
    - Loan count and mix; average interest rate; recent delinquencies; credit age; inquiries.
    - Document completeness or KYC signals; reminders history.
  - Model choices: 
    - Start simple with linear regression or elastic net.
    - Progress to tree-based models such as Random Forest, Gradient Boosted Trees (e.g., histogram-based boosting).
  - Training & evaluation:
    - Train/validation splits with cross-validation.
    - Metrics: MAE/RMSE; calibration curves for prediction reliability.
    - Regularization and early stopping to avoid overfitting; monitor feature importance.
  - Explainability:
    - Use SHAP or permutation importance to surface top factors driving each prediction.
    - Provide concise per-user recommendations based on influential features.
  - Serving predictions:
    - Expose a lightweight inference endpoint or batch job.
    - Save predictions to `cibil_scores` and surface them in the dashboard with explanations and suggested next steps.

Database Schema Summary
-----------------------

- `users` — identity, contact, `password_hash`, `google_login`, optional preferences (added at runtime in `profile.php`).
- `loans` — principal, rate, EMI, term, schedule, and status; reminders can be auto-scheduled for active loans.
- `cibil_scores` — predicted/official scores with `recommendation` text. Ensure schema includes any columns referenced by code.
- `insurance` — policy metadata (not heavily persisted by calculator; available for extensions).
- `chatbot_history` (schema file) and `chat_messages` (runtime) — align to one table for chat storage.
- `reminders` — standalone or loan-linked reminders with statuses.
- `documents` — uploaded file metadata; `doc_date` column added on-demand in `documents.php`.
- Additional tables: `activity_log`, `recommendations`, `tutorials_library` for extended features.

Setup & Configuration
---------------------

**Requirements**
- XAMPP or equivalent PHP/MySQL stack.
- PHP extensions: `pdo_mysql`, `curl`, `zip` (for DOCX extraction), and OpenSSL.

**Database**
- Create the schema using `database.txt` in MySQL (phpMyAdmin or CLI).
- Update `db.php` with proper MySQL credentials (default uses `root` + empty password on `127.0.0.1`).

**Optional: AWS S3 for Documents**
- Environment variables (Windows PowerShell):
  - `setx S3_ENABLED true`
  - `setx AWS_BUCKET your-bucket-name`
  - `setx AWS_REGION your-region`
  - `setx AWS_ACCESS_KEY_ID your-access-key`
  - `setx AWS_SECRET_ACCESS_KEY your-secret-key`
- Install AWS SDK for PHP to enable S3 client (`vendor/autoload.php` expected).

**Google Sign-In (Demo)**
- Set your Google OAuth Client ID directly in `login.php` (`$GOOGLE_CLIENT_ID`).
- In production, secure this configuration and handle failure cases robustly.

Run & Use
---------

1) Start XAMPP (Apache + MySQL).
2) Navigate to `http://localhost/cibil/login.php`.
3) Register a user (OTP demo displays the code on-screen) and login.
4) Explore:
   - Dashboard (`index.php`) for KPIs and recommendations.
   - Loans (`loans.php`) to add/update loans; check EMIs and export CSV.
   - Documents (`documents.php`) to upload/download files; view stats.
   - Reminders (`reminders.php`) to schedule or track dues.
   - CIBIL Predictor (`predictor.php`) to estimate scores and save them.
   - Insurance (`insurance.php`) for eligibility, claim probability, and coverage suggestions.
   - ChatBot (`chatbot.php`) for context-aware guidance and quick calculations.

Known Differences & Migrations
------------------------------

- `cibil_scores` insert may reference columns not present in your base schema. Align the table or adjust inserts. Example migration:
  - `ALTER TABLE cibil_scores ADD COLUMN ai_model_used VARCHAR(100) NULL;`
- Chat storage:
  - The code creates/uses `chat_messages`. The schema file defines `chatbot_history`.
  - Choose one and align both code and DB; or add a migration to create `chat_messages` consistently.
- Runtime schema changes (adding `doc_date` or preference columns) are done best via migrations rather than page code.

Security Notes
--------------

- Disable OTP demo in production and integrate a real SMS provider.
- Restrict upload types and ensure the web server cannot execute files from `uploads/`.
- Use strong passwords (`password_hash` with defaults) and enforce password rules.
- Keep environment secrets out of the repo and rotate regularly.

Roadmap Ideas
-------------

- Unified migrations and schema versioning.
- Add tests for EMI math, reminder scheduling, and predictor heuristics.
- Build a Tutorials page powered by `tutorials_library`.
- Replace heuristic predictor with a trained model hosted behind an internal inference endpoint.
- Improve file text extraction for PDFs via a dedicated library.
- Better analytics and audit logging in `activity_log`.

Contributing
------------

- Keep changes minimal and consistent with the procedural style.
- Use prepared statements, CSRF tokens, and `h()` for all user-supplied output.
- Propose schema changes via migration scripts instead of page-level `ALTER TABLE`.