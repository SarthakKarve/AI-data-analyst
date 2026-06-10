<?php
/**
 * CIBIL Manager — Demo Seed Data
 * ─────────────────────────────
 * Run once via browser: http://localhost/cibil/seed.php
 * Or CLI: php seed.php
 *
 * Creates two demo accounts:
 *   rahul@demo.com  / Demo@1234  (Good financial health, score ~720)
 *   priya@demo.com  / Demo@1234  (Poor financial health, score ~565)
 *
 * Safe to re-run — skips if demo users already exist.
 * Add ?reset=1 to wipe and re-seed: http://localhost/cibil/seed.php?reset=1
 */

require __DIR__ . '/db.php';

$reset = isset($_GET['reset']) || in_array('--reset', $argv ?? []);

// ── Helpers ───────────────────────────────────────────────────────────────────
function out(string $msg, string $type = 'info'): void {
    $isCli = php_sapi_name() === 'cli';
    $colors = ['ok' => '#16a34a', 'info' => '#2563eb', 'warn' => '#d97706', 'head' => '#7c3aed'];
    if ($isCli) {
        $prefix = ['ok' => '✓', 'info' => '·', 'warn' => '!', 'head' => '►'][$type] ?? '·';
        echo $prefix . ' ' . $msg . PHP_EOL;
    } else {
        $color = $colors[$type] ?? '#374151';
        echo "<p style='margin:2px 0;color:{$color};font-family:monospace;font-size:13px'>" . htmlspecialchars($msg) . "</p>";
    }
}
function section(string $title): void {
    out('', 'info');
    out("── $title", 'head');
}

// ── Reset guard ───────────────────────────────────────────────────────────────
if ($reset) {
    out('Resetting demo data...', 'warn');
    $pdo->exec("DELETE FROM users WHERE email IN ('rahul@demo.com','priya@demo.com')
                   OR phone IN ('+910000000001','+910000000002')");
    out('Old demo data removed.', 'ok');
}

// ── Check already seeded ──────────────────────────────────────────────────────
$existing = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email IN ('rahul@demo.com','priya@demo.com')
    OR phone IN ('+910000000001','+910000000002')");
$existing->execute();
if ((int)$existing->fetchColumn() > 0) {
    out('Demo users already exist. Use ?reset=1 to re-seed.', 'warn');
    if (php_sapi_name() !== 'cli') {
        echo "<p style='font-family:monospace;margin-top:12px'><a href='index.php'>→ Go to Dashboard</a></p>";
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// USER 1 — Rahul Sharma  (Good health, score 720)
// ══════════════════════════════════════════════════════════════════════════════
section('USER 1 — Rahul Sharma (rahul@demo.com)');

$pdo->prepare("INSERT INTO users
    (first_name, last_name, email, phone, password_hash, address, dob,
     marital_status, employment_status, annual_income, target_cibil_score,
     notify_email_pref, pref_ai_suggestions, pref_tutorial_tips)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
->execute([
    'Rahul', 'Sharma', 'rahul@demo.com', '+910000000001',
    password_hash('Demo@1234', PASSWORD_DEFAULT),
    '42, MG Road, Koregaon Park, Pune, Maharashtra - 411001',
    '1990-07-15', 'Married', 'Salaried',
    1200000, 800, 1, 1, 1,
]);
$u1 = (int)$pdo->lastInsertId();
out("Created user #$u1 — Rahul Sharma", 'ok');

// ── Loans ─────────────────────────────────────────────────────────────────────
$pdo->prepare("INSERT INTO loans (user_id, loan_amount, purpose, repayment_period, installment_option, status, interest_rate, emi_amount, start_date, end_date) VALUES (?,?,?,?,?,?,?,?,?,?)")
->execute([$u1, 2500000, 'Home Loan', 180, 'Monthly', 'Active', 8.50, 24613.00, '2022-04-01', '2037-04-01']);
$l1a = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO loans (user_id, loan_amount, purpose, repayment_period, installment_option, status, interest_rate, emi_amount, start_date, end_date) VALUES (?,?,?,?,?,?,?,?,?,?)")
->execute([$u1, 600000, 'Car Loan', 60, 'Monthly', 'Active', 9.00, 12454.00, '2023-01-15', '2028-01-15']);
$l1b = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO loans (user_id, loan_amount, purpose, repayment_period, installment_option, status, interest_rate, emi_amount, start_date, end_date) VALUES (?,?,?,?,?,?,?,?,?,?)")
->execute([$u1, 200000, 'Personal Loan', 24, 'Monthly', 'Closed', 13.50, 9563.00, '2021-03-01', '2023-03-01']);

$pdo->prepare("INSERT INTO loans (user_id, loan_amount, purpose, repayment_period, installment_option, status, interest_rate, emi_amount, start_date, end_date) VALUES (?,?,?,?,?,?,?,?,?,?)")
->execute([$u1, 800000, 'Education Loan', 84, 'Monthly', 'Closed', 10.00, 13279.00, '2018-06-01', '2025-06-01']);
out("Created 4 loans (2 active, 2 closed)", 'ok');

// ── CIBIL Scores & Score History ──────────────────────────────────────────────
$scores1 = [
    ['2023-10-01', 645], ['2023-12-01', 660], ['2024-02-01', 672],
    ['2024-04-01', 688], ['2024-06-01', 700], ['2024-08-01', 712], ['2024-10-01', 720],
];
foreach ($scores1 as [$date, $score]) {
    $pdo->prepare("INSERT INTO score_history (user_id, score, source, recorded_at) VALUES (?,?,?,?)")
        ->execute([$u1, $score, 'Predicted', $date . ' 10:00:00']);
}
$pdo->prepare("INSERT INTO cibil_scores (user_id, cibil_score, score_type, prediction_source, ai_model_used, prediction_date, recommendation) VALUES (?,?,?,?,?,?,?)")
->execute([$u1, 720, 'Predicted', 'Database', 'Kimi K2.5', '2024-10-01 10:00:00',
    "Your score of 720 is Good. Focus on reducing EMI-to-income ratio and maintain on-time payments to cross 750."]);
out("Created 7 score history entries, latest score: 720", 'ok');

// ── Reminders ─────────────────────────────────────────────────────────────────
$remData1 = [
    [$u1, $l1a, 'Home Loan EMI', 'Monthly EMI of ₹24,613 due for Home Loan',
        date('Y-m-d', strtotime('+5 days')), 'Upcoming', 'Medium'],
    [$u1, $l1b, 'Car Loan EMI', 'Monthly EMI of ₹12,454 due for Car Loan',
        date('Y-m-d', strtotime('+8 days')), 'Upcoming', 'Medium'],
    [$u1, null, 'Income Tax Filing', 'Advance tax Q3 payment deadline approaching',
        date('Y-m-d', strtotime('+45 days')), 'Upcoming', 'High'],
    [$u1, null, 'Car Insurance Renewal', 'Annual car insurance renewal',
        date('Y-m-d', strtotime('-30 days')), 'Completed', 'Low'],
    [$u1, null, 'Investment Review', 'Review SIP and mutual fund portfolio performance',
        date('Y-m-d', strtotime('+20 days')), 'Upcoming', 'Low'],
];
foreach ($remData1 as $r) {
    $pdo->prepare("INSERT INTO reminders (user_id, loan_id, title, description, due_date, status, priority_level) VALUES (?,?,?,?,?,?,?)")
        ->execute($r);
}
out("Created 5 reminders (4 upcoming/completed, 1 high-priority)", 'ok');

// ── Documents ─────────────────────────────────────────────────────────────────
$docData1 = [
    [$u1, 'PAN Card',         'Identity',       '/uploads/demo_pan.pdf',      'PDF',  0.18, '2024-01-10'],
    [$u1, 'Aadhaar Card',     'Identity',       '/uploads/demo_aadhaar.pdf',  'PDF',  0.22, '2024-01-10'],
    [$u1, 'Salary Slip',      'Income Proof',   '/uploads/demo_salary.pdf',   'PDF',  0.34, '2024-09-01'],
    [$u1, 'Bank Statement',   'Financial',      '/uploads/demo_bank.pdf',     'PDF',  0.65, '2024-09-15'],
    [$u1, 'Form 16',          'Tax Document',   '/uploads/demo_form16.pdf',   'PDF',  0.41, '2024-07-20'],
];
foreach ($docData1 as $d) {
    $pdo->prepare("INSERT INTO documents (user_id, doc_title, category, file_path, file_type, storage_size_mb, doc_date) VALUES (?,?,?,?,?,?,?)")
        ->execute($d);
}
out("Created 5 documents", 'ok');

// ── Insurance ─────────────────────────────────────────────────────────────────
$pdo->prepare("INSERT INTO insurance (user_id, insurance_type, premium_amount, coverage_amount, eligibility_status, remarks) VALUES (?,?,?,?,?,?)")
->execute([$u1, 'Term Life Insurance', 18500.00, 10000000.00, 'Eligible', 'Eligible based on age (34), income (₹12L), and CIBIL score (720). Coverage: ₹1 Crore.']);
$pdo->prepare("INSERT INTO insurance (user_id, insurance_type, premium_amount, coverage_amount, eligibility_status, remarks) VALUES (?,?,?,?,?,?)")
->execute([$u1, 'Loan Protection Insurance', 8200.00, 3100000.00, 'Eligible', 'Covers outstanding Home + Car loan balance. Premium ₹8,200/year.']);
out("Created 2 insurance records (both eligible)", 'ok');

// ── Recommendations ───────────────────────────────────────────────────────────
$recs1 = [
    "Reduce EMI-to-Income Ratio: Your current ratio is ~30.8%. Prepaying ₹50,000 on your car loan can bring this below 28%.",
    "Maintain Payment Discipline: You have 2 active loans with clean history. Continue on-time payments for 6 more months to push past 740.",
    "Upload more financial documents: Adding Form 26AS and investment statements strengthens your lender profile.",
    "Avoid new credit applications for the next 6 months to prevent hard inquiry penalties.",
    "Consider increasing SIP contributions — income stability signals positively to credit bureaus.",
];
foreach ($recs1 as $rec) {
    $pdo->prepare("INSERT INTO recommendations (user_id, score_id, recommendation_text) VALUES (?,NULL,?)")->execute([$u1, $rec]);
}
out("Created 5 recommendations", 'ok');

// ── Activity Log ──────────────────────────────────────────────────────────────
$acts1 = [
    ['loan_added',      'Added Home Loan of ₹25,00,000 at 8.5%',                       '-180 days'],
    ['loan_added',      'Added Car Loan of ₹6,00,000 at 9%',                           '-120 days'],
    ['doc_uploaded',    'Uploaded PAN Card (Identity)',                                  '-90 days'],
    ['doc_uploaded',    'Uploaded Aadhaar Card (Identity)',                              '-90 days'],
    ['score_predicted', 'CIBIL score predicted: 688',                                   '-75 days'],
    ['reminder_added',  'Added reminder: Home Loan EMI due '.date('Y-m-d',strtotime('-60 days')), '-60 days'],
    ['doc_uploaded',    'Uploaded Salary Slip (Income Proof)',                           '-45 days'],
    ['score_predicted', 'CIBIL score predicted: 700',                                   '-45 days'],
    ['profile_updated', 'Updated annual income and employment details',                  '-30 days'],
    ['doc_uploaded',    'Uploaded Bank Statement (Financial)',                           '-20 days'],
    ['score_predicted', 'CIBIL score predicted: 712',                                   '-15 days'],
    ['insurance',       'Calculated Term Life Insurance — Eligible (₹1 Crore coverage)','-10 days'],
    ['goal_set',        'Set target CIBIL score to 800',                                '-8 days'],
    ['doc_uploaded',    'Uploaded Form 16 (Tax Document)',                               '-5 days'],
    ['score_predicted', 'CIBIL score predicted: 720',                                   '-2 days'],
    ['reminder_added',  'Added reminder: Income Tax Filing deadline',                   '-1 days'],
];
foreach ($acts1 as [$action, $desc, $offset]) {
    $pdo->prepare("INSERT INTO activity_log (user_id, action, description, created_at) VALUES (?,?,?,?)")
        ->execute([$u1, $action, $desc, date('Y-m-d H:i:s', strtotime($offset))]);
}
out("Created " . count($acts1) . " activity log entries", 'ok');


// ══════════════════════════════════════════════════════════════════════════════
// USER 2 — Priya Verma  (Poor health, score 565)
// ══════════════════════════════════════════════════════════════════════════════
section('USER 2 — Priya Verma (priya@demo.com)');

$pdo->prepare("INSERT INTO users
    (first_name, last_name, email, phone, password_hash, address, dob,
     marital_status, employment_status, annual_income, target_cibil_score,
     notify_email_pref, pref_ai_suggestions, pref_tutorial_tips)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
->execute([
    'Priya', 'Verma', 'priya@demo.com', '+910000000002',
    password_hash('Demo@1234', PASSWORD_DEFAULT),
    '15, Tilak Nagar, Nagpur, Maharashtra - 440010',
    '1997-03-22', 'Single', 'Self-Employed',
    480000,   // ₹4.8L annual income
    700,      // target CIBIL
    1, 1, 0,
]);
$u2 = (int)$pdo->lastInsertId();
out("Created user #$u2 — Priya Verma", 'ok');

// ── Loans ─────────────────────────────────────────────────────────────────────
$pdo->prepare("INSERT INTO loans (user_id, loan_amount, purpose, repayment_period, installment_option, status, interest_rate, emi_amount, start_date, end_date) VALUES (?,?,?,?,?,?,?,?,?,?)")
->execute([$u2, 250000, 'Personal Loan', 24, 'Monthly', 'Active', 16.00, 12277.00, '2024-01-10', '2026-01-10']);
$l2a = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO loans (user_id, loan_amount, purpose, repayment_period, installment_option, status, interest_rate, emi_amount, start_date, end_date) VALUES (?,?,?,?,?,?,?,?,?,?)")
->execute([$u2, 45000, 'Consumer Loan', 12, 'Monthly', 'Active', 24.00, 4245.00, '2024-06-01', '2025-06-01']);
$l2b = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO loans (user_id, loan_amount, purpose, repayment_period, installment_option, status, interest_rate, emi_amount, start_date, end_date) VALUES (?,?,?,?,?,?,?,?,?,?)")
->execute([$u2, 100000, 'Education Loan', 36, 'Monthly', 'Closed', 12.00, 3321.00, '2021-07-01', '2024-07-01']);

out("Created 3 loans (2 active, 1 closed)", 'ok');

// ── CIBIL Scores & Score History ──────────────────────────────────────────────
$scores2 = [
    ['2024-02-01', 520], ['2024-04-01', 538],
    ['2024-06-01', 550], ['2024-08-01', 560], ['2024-10-01', 565],
];
foreach ($scores2 as [$date, $score]) {
    $pdo->prepare("INSERT INTO score_history (user_id, score, source, recorded_at) VALUES (?,?,?,?)")
        ->execute([$u2, $score, 'Predicted', $date . ' 14:00:00']);
}
$pdo->prepare("INSERT INTO cibil_scores (user_id, cibil_score, score_type, prediction_source, ai_model_used, prediction_date, recommendation) VALUES (?,?,?,?,?,?,?)")
->execute([$u2, 565, 'Predicted', 'Database', 'Kimi K2.5', '2024-10-01 14:00:00',
    "Your score of 565 is Poor. Prioritise clearing overdue EMIs, reduce credit utilisation, and avoid new loan applications."]);
out("Created 5 score history entries, latest score: 565", 'ok');

// ── Reminders ─────────────────────────────────────────────────────────────────
$remData2 = [
    [$u2, $l2a, 'Personal Loan EMI — OVERDUE', 'EMI of ₹12,277 is overdue! Missed payments damage your CIBIL score severely.',
        date('Y-m-d', strtotime('-3 days')), 'High Priority', 'High'],
    [$u2, $l2b, 'Consumer Loan EMI', 'Monthly EMI of ₹4,245 for Consumer Loan due soon.',
        date('Y-m-d', strtotime('+4 days')), 'High Priority', 'High'],
    [$u2, null, 'GST Filing Deadline', 'Quarterly GST return filing due for self-employment.',
        date('Y-m-d', strtotime('+12 days')), 'Upcoming', 'Medium'],
    [$u2, null, 'Credit Score Review', 'Scheduled monthly CIBIL score review.',
        date('Y-m-d', strtotime('+30 days')), 'Upcoming', 'Low'],
];
foreach ($remData2 as $r) {
    $pdo->prepare("INSERT INTO reminders (user_id, loan_id, title, description, due_date, status, priority_level) VALUES (?,?,?,?,?,?,?)")
        ->execute($r);
}
out("Created 4 reminders (2 high priority — including 1 overdue)", 'ok');

// ── Documents ─────────────────────────────────────────────────────────────────
$pdo->prepare("INSERT INTO documents (user_id, doc_title, category, file_path, file_type, storage_size_mb, doc_date) VALUES (?,?,?,?,?,?,?)")
->execute([$u2, 'Aadhaar Card', 'Identity', '/uploads/demo_aadhaar2.pdf', 'PDF', 0.19, '2024-03-05']);
out("Created 1 document (needs more — weak profile)", 'ok');

// ── Insurance ─────────────────────────────────────────────────────────────────
$pdo->prepare("INSERT INTO insurance (user_id, insurance_type, premium_amount, coverage_amount, eligibility_status, remarks) VALUES (?,?,?,?,?,?)")
->execute([$u2, 'Term Life Insurance', 0.00, 0.00, 'Not Eligible',
    'Not eligible: CIBIL score (565) below minimum threshold of 600. Improve score and reapply.']);
out("Created 1 insurance record (not eligible)", 'ok');

// ── Recommendations ───────────────────────────────────────────────────────────
$recs2 = [
    "URGENT: Clear overdue Personal Loan EMI immediately. Each day of delay further damages your score.",
    "Reduce Credit Utilisation: Your outstanding debt is 83% of estimated credit capacity. Pay down balances.",
    "Upload KYC Documents: Only 1 document on file. Add PAN, income proof, and bank statement to strengthen your profile.",
    "Avoid new loan applications for at least 6 months. Your recent Consumer Loan already triggered a hard inquiry.",
    "Set up autopay for both active EMIs to ensure no future missed payments.",
];
foreach ($recs2 as $rec) {
    $pdo->prepare("INSERT INTO recommendations (user_id, score_id, recommendation_text) VALUES (?,NULL,?)")->execute([$u2, $rec]);
}
out("Created 5 recommendations", 'ok');

// ── Activity Log ──────────────────────────────────────────────────────────────
$acts2 = [
    ['loan_added',      'Added Personal Loan of ₹2,50,000 at 16%',          '-270 days'],
    ['score_predicted', 'CIBIL score predicted: 520',                         '-240 days'],
    ['doc_uploaded',    'Uploaded Aadhaar Card (Identity)',                   '-210 days'],
    ['score_predicted', 'CIBIL score predicted: 538',                         '-180 days'],
    ['loan_added',      'Added Consumer Loan of ₹45,000 at 24%',             '-120 days'],
    ['reminder_added',  'Added reminder: Personal Loan EMI',                  '-100 days'],
    ['score_predicted', 'CIBIL score predicted: 550',                         '-90 days'],
    ['score_predicted', 'CIBIL score predicted: 560',                         '-60 days'],
    ['insurance',       'Calculated Term Life Insurance — Not Eligible',       '-30 days'],
    ['goal_set',        'Set target CIBIL score to 700',                       '-25 days'],
    ['score_predicted', 'CIBIL score predicted: 565',                         '-10 days'],
    ['reminder_added',  'Added high-priority reminder: Personal Loan overdue', '-3 days'],
];
foreach ($acts2 as [$action, $desc, $offset]) {
    $pdo->prepare("INSERT INTO activity_log (user_id, action, description, created_at) VALUES (?,?,?,?)")
        ->execute([$u2, $action, $desc, date('Y-m-d H:i:s', strtotime($offset))]);
}
out("Created " . count($acts2) . " activity log entries", 'ok');


// ══════════════════════════════════════════════════════════════════════════════
// Done
// ══════════════════════════════════════════════════════════════════════════════
section('Seed Complete');
out("Demo data seeded successfully!", 'ok');

if (php_sapi_name() !== 'cli'):
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Seed — CIBIL Manager</title>
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>body{font-family:'Inter',sans-serif}</style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-6">
  <div class="bg-white rounded-2xl shadow-sm border p-8 max-w-lg w-full">
    <div class="flex items-center gap-3 mb-6">
      <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <h2 class="text-lg font-bold text-gray-900">Seed data created!</h2>
        <p class="text-sm text-gray-500">Two demo accounts are ready to use.</p>
      </div>
    </div>

    <div class="space-y-3 mb-6">
      <!-- User 1 -->
      <div class="bg-green-50 border border-green-200 rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-semibold text-gray-800">Rahul Sharma</span>
          <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">Good Health · Score 720</span>
        </div>
        <div class="grid grid-cols-2 gap-1 text-xs text-gray-600">
          <span>📧 rahul@demo.com</span>
          <span>🔑 Demo@1234</span>
          <span>💰 Income ₹12L/yr</span>
          <span>🏦 2 active loans</span>
          <span>📄 5 documents</span>
          <span>🎯 Target: 800</span>
        </div>
      </div>

      <!-- User 2 -->
      <div class="bg-red-50 border border-red-200 rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-semibold text-gray-800">Priya Verma</span>
          <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-medium">Poor Health · Score 565</span>
        </div>
        <div class="grid grid-cols-2 gap-1 text-xs text-gray-600">
          <span>📧 priya@demo.com</span>
          <span>🔑 Demo@1234</span>
          <span>💰 Income ₹4.8L/yr</span>
          <span>🏦 2 active loans</span>
          <span>📄 1 document</span>
          <span>⚠️ 2 overdue EMIs</span>
        </div>
      </div>
    </div>

    <div class="flex gap-3">
      <a href="login.php"
        class="flex-1 py-2.5 bg-violet-600 text-white rounded-xl text-sm font-semibold text-center hover:bg-violet-700 transition-colors">
        Go to Login →
      </a>
      <a href="seed.php?reset=1" onclick="return confirm('Wipe and re-seed all demo data?')"
        class="px-4 py-2.5 border border-gray-200 text-gray-600 rounded-xl text-sm font-medium hover:bg-gray-50 transition-colors">
        Re-seed
      </a>
    </div>
  </div>
</body>
</html>
<?php
endif;
