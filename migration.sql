-- ============================================================
-- Credit Health Management System — Migration Script
-- Run once against the 'cibil' database:
--   mysql -u root cibil < migration.sql
-- ============================================================

USE cibil;

-- ── users: extra preference columns ──────────────────────────────────────────
ALTER TABLE users ADD COLUMN IF NOT EXISTS notify_email_pref  TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS notify_sms_pref    TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS pref_ai_suggestions TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS pref_tutorial_tips  TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS target_cibil_score  INT NULL;

-- ── reminders: notification flags + recurring + priority ─────────────────────
ALTER TABLE reminders ADD COLUMN IF NOT EXISTS notify_email   TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE reminders ADD COLUMN IF NOT EXISTS notify_sms     TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE reminders ADD COLUMN IF NOT EXISTS recurring      ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none';
ALTER TABLE reminders ADD COLUMN IF NOT EXISTS priority_level ENUM('Low','Medium','High') NOT NULL DEFAULT 'Medium';

-- ── documents: optional date field ───────────────────────────────────────────
ALTER TABLE documents ADD COLUMN IF NOT EXISTS doc_date DATE NULL;

-- ── score_history: tracks every prediction over time ─────────────────────────
CREATE TABLE IF NOT EXISTS score_history (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    score       INT NOT NULL CHECK (score BETWEEN 300 AND 900),
    source      VARCHAR(50) NOT NULL DEFAULT 'Predicted',
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ── chat_messages: ensure chatbot table exists (chatbot.php creates it too) ───
CREATE TABLE IF NOT EXISTS chat_messages (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    role         ENUM('user','assistant') NOT NULL,
    message_text TEXT,
    attachments_json TEXT,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ── tutorials_library: seed educational content ───────────────────────────────
INSERT IGNORE INTO tutorials_library (tutorial_id, type, title, description, url) VALUES
(1,  'Video', 'What is a CIBIL Score?',
     'Understand what a CIBIL score is, how it is calculated, and why lenders care about it.',
     'https://www.youtube.com/results?search_query=what+is+cibil+score'),
(2,  'Video', 'How to Improve Your CIBIL Score',
     '5 proven strategies to boost your credit score within 3–6 months.',
     'https://www.youtube.com/results?search_query=how+to+improve+cibil+score'),
(3,  'Video', 'Understanding Loan EMIs',
     'Learn how EMI is calculated, what affects it, and how to plan your repayments.',
     'https://www.youtube.com/results?search_query=loan+emi+calculation+explained'),
(4,  'Video', 'Credit Utilization Ratio Explained',
     'Why keeping credit utilization below 30% is critical for a good score.',
     'https://www.youtube.com/results?search_query=credit+utilization+ratio+explained'),
(5,  'Audio', 'Managing Multiple Loans',
     'Expert advice on juggling multiple loans without damaging your credit profile.',
     'https://www.google.com/search?q=managing+multiple+loans+podcast'),
(6,  'Audio', 'Debt Consolidation Basics',
     'When and how to consolidate debt to reduce EMI burden and improve score.',
     'https://www.google.com/search?q=debt+consolidation+basics+podcast'),
(7,  'Audio', 'Building Credit from Scratch',
     'Practical steps for first-time borrowers to establish a strong credit history.',
     'https://www.google.com/search?q=building+credit+from+scratch+podcast'),
(8,  'Audio', 'Emergency Fund vs Loan Prepayment',
     'Should you build savings or pay off loans faster? A balanced perspective.',
     'https://www.google.com/search?q=emergency+fund+vs+loan+prepayment+podcast');
