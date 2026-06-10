<?php
require __DIR__ . '/includes/auth_check.php';

// ── Page data ────────────────────────────────────────────────────────────────
$userRow       = fetchOne($pdo, "SELECT first_name, annual_income FROM users WHERE user_id = ?", [$userId]);
$firstName     = $userRow['first_name'] ?? 'User';
$annualIncome  = (float)($userRow['annual_income'] ?? 0);

$activeLoansCount      = (int)fetchValue($pdo, "SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'Active'", [$userId]);
$pendingRemindersCount = (int)fetchValue($pdo, "SELECT COUNT(*) FROM reminders WHERE user_id = ? AND status IN ('Upcoming','High Priority')", [$userId]);
$highPriorityReminders = (int)fetchValue($pdo, "SELECT COUNT(*) FROM reminders WHERE user_id = ? AND status = 'High Priority'", [$userId]);
$documentsCount        = (int)fetchValue($pdo, "SELECT COUNT(*) FROM documents WHERE user_id = ?", [$userId]);

$latestScore      = fetchOne($pdo, "SELECT cibil_score, score_type, prediction_date FROM cibil_scores WHERE user_id = ? ORDER BY prediction_date DESC LIMIT 1", [$userId]);
$scoreValue       = $latestScore ? (int)$latestScore['cibil_score'] : null;
$scoreType        = $latestScore['score_type'] ?? null;

$activeLoanSum  = (float)fetchValue($pdo, "SELECT COALESCE(SUM(loan_amount),0) FROM loans WHERE user_id = ? AND status = 'Active'", [$userId]);
$monthlyEmiSum  = (float)fetchValue($pdo, "SELECT COALESCE(SUM(emi_amount),0) FROM loans WHERE user_id = ? AND status = 'Active'", [$userId]);

// Score history for sparkline (last 10 predictions)
$scoreHistory = fetchAll($pdo,
    "SELECT score, recorded_at FROM score_history WHERE user_id = ? ORDER BY recorded_at ASC LIMIT 10",
    [$userId]);

// Upcoming reminders (next 5 non-completed)
$upcomingReminders = fetchAll($pdo,
    "SELECT title, due_date, status, priority_level FROM reminders
     WHERE user_id = ? AND status != 'Completed'
     ORDER BY due_date ASC LIMIT 5",
    [$userId]);

// Latest recommendations from recommendations table
$recentRecs = fetchAll($pdo,
    "SELECT recommendation_text, created_at FROM recommendations
     WHERE user_id = ? ORDER BY created_at DESC LIMIT 4",
    [$userId]);

// Build inline recommendations if table empty
$inlineRecs = [];
if ($scoreValue !== null) {
    if ($scoreValue < 650) $inlineRecs[] = ['icon' => 'ri-timer-line', 'text' => 'Pay all EMIs on time for 3+ consecutive months to see score improvement.'];
    elseif ($scoreValue < 700) $inlineRecs[] = ['icon' => 'ri-arrow-down-line', 'text' => 'Reduce outstanding balances and avoid new credit inquiries this quarter.'];
    else $inlineRecs[] = ['icon' => 'ri-check-line', 'text' => 'Maintain timely payments; avoid closing old accounts to preserve credit history.'];
} else {
    $inlineRecs[] = ['icon' => 'ri-search-line', 'text' => 'Run the CIBIL Predictor to unlock your personalised recommendations.'];
}
if ($annualIncome > 0) {
    $emiRatio = $monthlyEmiSum / ($annualIncome / 12);
    if ($emiRatio > 0.4) $inlineRecs[] = ['icon' => 'ri-funds-line', 'text' => 'EMI-to-income ratio is high (' . round($emiRatio * 100) . '%). Consider closing a small loan.'];
    elseif ($emiRatio > 0.3) $inlineRecs[] = ['icon' => 'ri-wallet-line', 'text' => 'EMI burden is moderate — avoid taking new loans until balances reduce.'];
}
if ($documentsCount === 0) $inlineRecs[] = ['icon' => 'ri-folder-add-line', 'text' => 'Upload KYC and income documents to strengthen your credit profile.'];
if ($highPriorityReminders > 0) $inlineRecs[] = ['icon' => 'ri-alarm-warning-line', 'text' => $highPriorityReminders . ' high-priority reminder(s) pending — clear them to avoid missed payments.'];

// Merge DB recs on top
$allRecs = [];
foreach ($recentRecs as $r) $allRecs[] = ['icon' => 'ri-lightbulb-flash-line', 'text' => $r['recommendation_text']];
foreach ($inlineRecs as $r) $allRecs[] = $r;
$allRecs = array_slice($allRecs, 0, 5);

// Score gauge segments
$scoreBandData = $scoreValue !== null ? scoreBand($scoreValue) : null;

// Chart.js score history data
$chartLabels = json_encode(array_map(fn($r) => date('d M', strtotime($r['recorded_at'])), $scoreHistory));
$chartScores = json_encode(array_map(fn($r) => (int)$r['score'], $scoreHistory));

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
?>
<?php require __DIR__ . '/includes/page_head.php'; ?>
<div class="flex">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <!-- Main content -->
  <div class="ml-64 flex-1 min-h-screen">
    <?php require __DIR__ . '/includes/header.php'; ?>
    <div class="p-8 space-y-8 fade-in">

      <!-- Welcome Banner + Score Gauge -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Welcome -->
        <div class="lg:col-span-2 bg-gradient-to-br from-primary to-purple-700 text-white rounded-2xl p-8 flex flex-col justify-between">
          <div>
            <p class="text-purple-200 text-sm font-medium mb-1">Welcome back,</p>
            <h2 class="text-3xl font-bold mb-3"><?= h($firstName) ?> 👋</h2>
            <p class="text-purple-100 text-sm leading-relaxed">
              <?php if ($scoreValue !== null): ?>
                Your current CIBIL score is <strong><?= h($scoreValue) ?></strong>
                (<?= h($scoreBandData['label'] ?? '') ?>).
                <?= $scoreValue >= 700 ? "Keep it up — you're in great shape!" : "With a few targeted actions you can improve it." ?>
              <?php else: ?>
                You haven't run a score prediction yet. Head to the CIBIL Predictor to get started.
              <?php endif; ?>
            </p>
          </div>
          <div class="mt-6 flex gap-3">
            <a href="predictor.php" class="bg-white text-primary px-5 py-2 rounded-button text-sm font-semibold hover:bg-purple-50 transition-colors">Predict Score →</a>
            <a href="insights.php" class="bg-white/20 text-white border border-white/30 px-5 py-2 rounded-button text-sm font-medium hover:bg-white/30 transition-colors">View Insights</a>
          </div>
        </div>
        <!-- Score Gauge -->
        <div class="bg-white rounded-2xl shadow-sm border p-6 flex flex-col items-center justify-center">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-4">Credit Score</p>
          <div class="relative w-40 h-40">
            <canvas id="scoreGauge"></canvas>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
              <span class="text-3xl font-bold text-gray-800"><?= $scoreValue !== null ? h($scoreValue) : '—' ?></span>
              <?php if ($scoreBandData): ?>
              <span class="text-xs font-medium mt-1 px-2 py-0.5 rounded-full <?= $scoreBandData['bg'] ?> <?= $scoreBandData['tailwind'] ?>"><?= h($scoreBandData['label']) ?></span>
              <?php else: ?>
              <span class="text-xs text-gray-400 mt-1">Not predicted</span>
              <?php endif; ?>
            </div>
          </div>
          <p class="text-xs text-gray-400 mt-3">Range: 300 – 900</p>
        </div>
      </div>

      <!-- KPI Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mb-3">
            <i class="ri-bank-line text-blue-600 text-lg"></i>
          </div>
          <div class="text-2xl font-bold text-gray-800"><?= h($activeLoansCount) ?></div>
          <div class="text-sm text-gray-500 mt-0.5">Active Loans</div>
          <?php if ($activeLoanSum > 0): ?>
          <div class="text-xs text-blue-600 mt-1 font-medium"><?= inrShort($activeLoanSum) ?> outstanding</div>
          <?php endif; ?>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mb-3">
            <i class="ri-funds-box-line text-purple-600 text-lg"></i>
          </div>
          <div class="text-2xl font-bold text-gray-800"><?= $monthlyEmiSum > 0 ? inrShort($monthlyEmiSum) : '₹0' ?></div>
          <div class="text-sm text-gray-500 mt-0.5">Monthly EMI</div>
          <?php if ($annualIncome > 0 && $monthlyEmiSum > 0): ?>
          <?php $emiPct = round(($monthlyEmiSum / ($annualIncome/12)) * 100); ?>
          <div class="text-xs <?= $emiPct > 40 ? 'text-red-500' : ($emiPct > 30 ? 'text-orange-500' : 'text-green-600') ?> mt-1 font-medium"><?= $emiPct ?>% of monthly income</div>
          <?php endif; ?>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mb-3">
            <i class="ri-alarm-line text-orange-600 text-lg"></i>
          </div>
          <div class="text-2xl font-bold text-gray-800"><?= h($pendingRemindersCount) ?></div>
          <div class="text-sm text-gray-500 mt-0.5">Pending Reminders</div>
          <?php if ($highPriorityReminders > 0): ?>
          <div class="text-xs text-red-500 mt-1 font-medium"><?= $highPriorityReminders ?> high priority</div>
          <?php endif; ?>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mb-3">
            <i class="ri-folder-3-line text-green-600 text-lg"></i>
          </div>
          <div class="text-2xl font-bold text-gray-800"><?= h($documentsCount) ?></div>
          <div class="text-sm text-gray-500 mt-0.5">Documents</div>
          <?php if ($documentsCount === 0): ?>
          <div class="text-xs text-orange-500 mt-1 font-medium">Upload KYC docs</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Score History + Recommendations -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Score History Chart -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">Score History</h3>
            <a href="predictor.php" class="text-xs text-primary font-medium hover:underline">Run Prediction →</a>
          </div>
          <?php if (count($scoreHistory) >= 2): ?>
          <canvas id="scoreHistoryChart" height="140"></canvas>
          <?php elseif (count($scoreHistory) === 1): ?>
          <div class="flex items-center gap-4 py-4">
            <div class="w-14 h-14 rounded-full bg-primary/10 flex items-center justify-center">
              <span class="text-primary font-bold text-lg"><?= $scoreHistory[0]['score'] ?></span>
            </div>
            <div>
              <p class="text-sm text-gray-700 font-medium">First prediction recorded</p>
              <p class="text-xs text-gray-400"><?= date('d M Y', strtotime($scoreHistory[0]['recorded_at'])) ?></p>
              <p class="text-xs text-gray-400 mt-1">Run more predictions to see your trend.</p>
            </div>
          </div>
          <?php else: ?>
          <div class="flex flex-col items-center justify-center py-10 text-center">
            <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mb-3">
              <i class="ri-line-chart-line text-gray-400 text-xl"></i>
            </div>
            <p class="text-sm text-gray-500">No score history yet.</p>
            <a href="predictor.php" class="text-xs text-primary font-medium mt-2 hover:underline">Predict your score now →</a>
          </div>
          <?php endif; ?>
        </div>

        <!-- Recommendations -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">Recommended Actions</h3>
            <a href="insights.php" class="text-xs text-primary font-medium hover:underline">All Insights →</a>
          </div>
          <?php if (empty($allRecs)): ?>
          <p class="text-sm text-gray-500 py-4 text-center">No recommendations yet. Run a score prediction first.</p>
          <?php else: ?>
          <ul class="space-y-3">
            <?php foreach ($allRecs as $i => $rec): ?>
            <li class="flex items-start gap-3">
              <div class="w-7 h-7 bg-primary/10 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5">
                <i class="<?= h($rec['icon']) ?> text-primary text-sm"></i>
              </div>
              <p class="text-sm text-gray-600 leading-relaxed"><?= h($rec['text']) ?></p>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
      </div>

      <!-- Upcoming Reminders + Quick Actions -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Upcoming Reminders -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">Upcoming Reminders</h3>
            <a href="reminders.php" class="text-xs text-primary font-medium hover:underline">View All →</a>
          </div>
          <?php if (empty($upcomingReminders)): ?>
          <div class="flex flex-col items-center justify-center py-8 text-center">
            <i class="ri-checkbox-circle-line text-3xl text-green-400 mb-2"></i>
            <p class="text-sm text-gray-500">All caught up! No pending reminders.</p>
          </div>
          <?php else: ?>
          <ul class="space-y-2.5">
            <?php foreach ($upcomingReminders as $r):
              $daysLeft = (int)ceil((strtotime($r['due_date']) - time()) / 86400);
              $isOverdue = $daysLeft < 0;
              $isToday   = $daysLeft === 0;
            ?>
            <li class="flex items-center justify-between p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
              <div class="flex items-center gap-3 min-w-0">
                <div class="w-2 h-2 rounded-full flex-shrink-0 <?= $r['status'] === 'High Priority' || $isOverdue ? 'bg-red-500' : ($isToday ? 'bg-orange-500' : 'bg-blue-400') ?>"></div>
                <span class="text-sm text-gray-700 font-medium truncate"><?= h($r['title']) ?></span>
              </div>
              <span class="text-xs flex-shrink-0 ml-3 <?= $isOverdue ? 'text-red-500 font-medium' : ($isToday ? 'text-orange-500 font-medium' : 'text-gray-400') ?>">
                <?= $isOverdue ? 'Overdue' : ($isToday ? 'Today' : date('d M', strtotime($r['due_date']))) ?>
              </span>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
          <h3 class="font-semibold text-gray-800 mb-4">Quick Actions</h3>
          <div class="grid grid-cols-2 gap-3">
            <?php
            $quickActions = [
              ['href' => 'tutorials.php?type=video', 'icon' => 'ri-play-circle-line', 'color' => 'blue',   'label' => 'Video Tutorials',     'desc' => 'Learn CIBIL basics'],
              ['href' => 'tutorials.php?type=audio', 'icon' => 'ri-headphone-line',   'color' => 'green',  'label' => 'Audio Guides',         'desc' => 'Listen on the go'],
              ['href' => 'goals.php',                'icon' => 'ri-focus-3-line',     'color' => 'purple', 'label' => 'Set Score Goal',       'desc' => 'Track your target'],
              ['href' => 'health.php',               'icon' => 'ri-heart-pulse-line', 'color' => 'red',    'label' => 'Health Score',         'desc' => 'Financial overview'],
              ['href' => 'insights.php',             'icon' => 'ri-lightbulb-line',   'color' => 'yellow', 'label' => 'My Insights',          'desc' => 'Personalized tips'],
              ['href' => 'documents.php',            'icon' => 'ri-folder-open-line', 'color' => 'indigo', 'label' => 'Documents Library',    'desc' => 'Manage your files'],
            ];
            $colorMap = ['blue'=>'bg-blue-100 text-blue-600','green'=>'bg-green-100 text-green-600','purple'=>'bg-purple-100 text-purple-600','red'=>'bg-red-100 text-red-600','yellow'=>'bg-yellow-100 text-yellow-600','indigo'=>'bg-indigo-100 text-indigo-600'];
            foreach ($quickActions as $qa):
            ?>
            <a href="<?= h($qa['href']) ?>" class="flex items-center gap-3 p-3 rounded-lg border hover:border-primary/30 hover:bg-primary/5 transition-all card-hover">
              <div class="w-9 h-9 <?= $colorMap[$qa['color']] ?> rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="<?= h($qa['icon']) ?> text-base"></i>
              </div>
              <div class="min-w-0">
                <p class="text-xs font-semibold text-gray-800 leading-tight"><?= h($qa['label']) ?></p>
                <p class="text-xs text-gray-400 truncate"><?= h($qa['desc']) ?></p>
              </div>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Improve Score Tips -->
      <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold text-gray-800 mb-1">Improve Your CIBIL Score</h3>
        <p class="text-sm text-gray-500 mb-5">Key factors that impact your credit score</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <?php
          $tips = [
            ['label'=>'Payment History',       'weight'=>35, 'color'=>'primary',  'desc'=>'Pay all EMIs and credit card bills on time without fail.'],
            ['label'=>'Credit Utilization',    'weight'=>30, 'color'=>'blue',     'desc'=>'Keep total debt below 30% of your total credit limit.'],
            ['label'=>'Credit Age',            'weight'=>15, 'color'=>'green',    'desc'=>'Maintain older accounts — a longer history boosts your score.'],
            ['label'=>'Credit Mix',            'weight'=>10, 'color'=>'orange',   'desc'=>'A healthy mix of secured and unsecured loans is beneficial.'],
            ['label'=>'New Credit Inquiries',  'weight'=>10, 'color'=>'red',      'desc'=>'Avoid multiple loan applications in a short period.'],
          ];
          $barColors = ['primary'=>'bg-primary','blue'=>'bg-blue-500','green'=>'bg-green-500','orange'=>'bg-orange-500','red'=>'bg-red-500'];
          foreach ($tips as $tip):
          ?>
          <div class="p-4 rounded-lg bg-gray-50">
            <div class="flex items-center justify-between mb-2">
              <span class="text-sm font-semibold text-gray-800"><?= h($tip['label']) ?></span>
              <span class="text-xs font-bold text-gray-500"><?= $tip['weight'] ?>%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-1.5 mb-2">
              <div class="<?= $barColors[$tip['color']] ?> h-1.5 rounded-full" style="width:<?= $tip['weight'] ?>%"></div>
            </div>
            <p class="text-xs text-gray-500"><?= h($tip['desc']) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div><!-- end .p-8 -->
  </div><!-- end main content -->
</div><!-- end flex -->

<script>
// ── Score Gauge (doughnut) ───────────────────────────────────────────────────
(function() {
  const score = <?= $scoreValue !== null ? (int)$scoreValue : 'null' ?>;
  const ctx = document.getElementById('scoreGauge');
  if (!ctx || score === null) return;

  const pct = (score - 300) / 600;  // 0–1
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      datasets: [{
        data: [pct, 1 - pct],
        backgroundColor: [
          score >= 800 ? '#3b82f6' : score >= 740 ? '#22c55e' : score >= 670 ? '#eab308' : score >= 580 ? '#f97316' : '#ef4444',
          '#f3f4f6'
        ],
        borderWidth: 0,
        circumference: 270,
        rotation: 225,
      }]
    },
    options: { cutout: '78%', plugins: { legend: { display: false }, tooltip: { enabled: false } }, animation: { duration: 1000 } }
  });
})();

// ── Score History Sparkline ──────────────────────────────────────────────────
(function() {
  const ctx = document.getElementById('scoreHistoryChart');
  if (!ctx) return;
  const labels = <?= $chartLabels ?>;
  const scores = <?= $chartScores ?>;
  if (scores.length < 2) return;

  new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'CIBIL Score',
        data: scores,
        borderColor: '#7c3aed',
        backgroundColor: 'rgba(124,58,237,0.08)',
        borderWidth: 2,
        pointBackgroundColor: '#7c3aed',
        pointRadius: 4,
        tension: 0.3,
        fill: true,
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => 'Score: ' + ctx.raw } } },
      scales: {
        y: { min: 300, max: 900, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 } } },
        x: { grid: { display: false }, ticks: { font: { size: 10 } } }
      }
    }
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
