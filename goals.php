<?php
require __DIR__ . '/includes/auth_check.php';

$csrf   = csrfToken();
$errors = []; $success = '';

// ── POST: set/update goal ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrfVerify($_POST['csrf']??'')) { $errors[]='Invalid CSRF token.'; }
    else {
        $target=(int)($_POST['target_cibil_score']??0);
        if ($target<300||$target>900) $errors[]='Target score must be between 300 and 900.';
        if (empty($errors)) {
            $pdo->prepare("UPDATE users SET target_cibil_score=? WHERE user_id=?")->execute([$target,$userId]);
            logActivity($pdo,$userId,'goal_set',"Set target CIBIL score to $target");
            $success="Goal set to $target!";
        }
    }
}

// ── Page data ─────────────────────────────────────────────────────────────────
$userRow       = fetchOne($pdo,"SELECT first_name, annual_income, target_cibil_score FROM users WHERE user_id=?",[$userId]);
$firstName     = $userRow['first_name'] ?? 'User';
$annualIncome  = (float)($userRow['annual_income'] ?? 0);
$targetScore   = $userRow['target_cibil_score'] ? (int)$userRow['target_cibil_score'] : null;

$latestScore   = fetchOne($pdo,"SELECT cibil_score, prediction_date FROM cibil_scores WHERE user_id=? ORDER BY prediction_date DESC LIMIT 1",[$userId]);
$currentScore  = $latestScore ? (int)$latestScore['cibil_score'] : null;

$monthlyEmi    = (float)fetchValue($pdo,"SELECT COALESCE(SUM(emi_amount),0) FROM loans WHERE user_id=? AND status='Active'",[$userId]);
$totalDebt     = (float)fetchValue($pdo,"SELECT COALESCE(SUM(loan_amount),0) FROM loans WHERE user_id=? AND status='Active'",[$userId]);
$pendingRem    = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status IN ('Upcoming','High Priority')",[$userId]);
$docCount      = (int)fetchValue($pdo,"SELECT COUNT(*) FROM documents WHERE user_id=?",[$userId]);

$scoreHistory  = fetchAll($pdo,"SELECT score,recorded_at FROM score_history WHERE user_id=? ORDER BY recorded_at ASC LIMIT 10",[$userId]);

// Gap analysis
$gap = ($currentScore && $targetScore) ? max(0,$targetScore-$currentScore) : null;
$monthlyIncome = $annualIncome > 0 ? $annualIncome/12 : 0;
$emiRatio = $monthlyIncome > 0 ? $monthlyEmi/$monthlyIncome : 0;

// Generate steps based on gap and profile weaknesses
$steps = [];
if ($gap !== null && $gap > 0) {
    $months = max(2, (int)ceil($gap/15)); // ~15 pts per month with good behaviour
    if ($pendingRem > 0)   $steps[] = ['title'=>'Clear Pending Reminders','desc'=>"Pay off $pendingRem pending EMI/payment reminders. Payment history is 35% of your score.",'months'=>1,'points'=>15,'icon'=>'ri-alarm-line','color'=>'red'];
    if ($emiRatio > 0.35)  $steps[] = ['title'=>'Reduce EMI Burden','desc'=>"Your EMI-to-income ratio is ".round($emiRatio*100)."%. Prepay a small loan to bring it below 35%.",'months'=>2,'points'=>20,'icon'=>'ri-trending-down-line','color'=>'orange'];
    if ($totalDebt > 0)    $steps[] = ['title'=>'Lower Credit Utilization','desc'=>"Reduce outstanding debt to below 30% of your credit capacity for a utilization boost.",'months'=>3,'points'=>20,'icon'=>'ri-percent-line','color'=>'yellow'];
    if ($docCount < 3)     $steps[] = ['title'=>'Upload KYC Documents','desc'=>"Upload Aadhaar, PAN, and income proof to build a complete, lender-ready profile.",'months'=>0,'points'=>5,'icon'=>'ri-folder-add-line','color'=>'blue'];
    $steps[] = ['title'=>'Make All Payments On Time','desc'=>"Set up autopay reminders for every EMI due date. Consistent on-time payments improve score month by month.",'months'=>3,'points'=>25,'icon'=>'ri-time-line','color'=>'green'];
    $steps[] = ['title'=>'Avoid New Loan Applications','desc'=>"Multiple hard inquiries in a short period lower your score. Wait at least 6 months between applications.",'months'=>6,'points'=>10,'icon'=>'ri-ban-line','color'=>'purple'];
    if ($currentScore < 700) $steps[] = ['title'=>'Consider a Small Secured Loan','desc'=>"A small, easily repayable loan diversifies your credit mix and can add 10–20 points if paid on time.",'months'=>6,'points'=>15,'icon'=>'ri-bank-line','color'=>'blue'];
}

$highPriorityReminders = $pendingRem;
$pageTitle  = 'Credit Goals';
$activePage = 'goals';

$chartLabels = json_encode(array_map(fn($r)=>date('d M',strtotime($r['recorded_at'])),$scoreHistory));
$chartData   = json_encode(array_map(fn($r)=>(int)$r['score'],$scoreHistory));
$colorMap=['red'=>'bg-red-100 text-red-600','orange'=>'bg-orange-100 text-orange-600','yellow'=>'bg-yellow-100 text-yellow-600','blue'=>'bg-blue-100 text-blue-600','green'=>'bg-green-100 text-green-600','purple'=>'bg-purple-100 text-purple-600'];
?>
<?php require __DIR__ . '/includes/page_head.php'; ?>
<div class="flex">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <div class="ml-64 flex-1 min-h-screen">
    <?php require __DIR__ . '/includes/header.php'; ?>
    <div class="p-8 space-y-6 fade-in">

      <?php if (!empty($errors)): ?>
      <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm"><?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
      <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl text-sm"><?= h($success) ?></div>
      <?php endif; ?>

      <!-- Set Goal + Current Progress -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Set Goal Form -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
          <h3 class="font-semibold text-gray-800 mb-1 flex items-center gap-2"><i class="ri-focus-3-line text-primary"></i>Set Your Goal</h3>
          <p class="text-sm text-gray-400 mb-5">What CIBIL score do you want to achieve?</p>
          <form method="post" class="space-y-4">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <div>
              <label class="text-xs text-gray-500 mb-1 block">Target CIBIL Score: <span id="sliderVal" class="font-bold text-primary"><?= $targetScore??750 ?></span></label>
              <input type="range" name="target_cibil_score" min="300" max="900" step="10" value="<?= h($targetScore??750) ?>" id="goalSlider" class="w-full accent-primary h-2 rounded-full" oninput="document.getElementById('sliderVal').textContent=this.value">
              <div class="flex justify-between text-xs text-gray-400 mt-1"><span>300 (Poor)</span><span>900 (Excellent)</span></div>
            </div>
            <div class="grid grid-cols-3 gap-2">
              <?php foreach([650=>'Fair',750=>'Good',800=>'Excellent'] as $s=>$l): ?>
              <button type="button" onclick="document.getElementById('goalSlider').value=<?= $s ?>;document.getElementById('sliderVal').textContent=<?= $s ?>;" class="py-2 rounded-lg border text-xs font-medium text-gray-600 hover:border-primary hover:text-primary hover:bg-primary/5 transition-colors"><?= $s ?><br><span class="text-gray-400"><?= $l ?></span></button>
              <?php endforeach; ?>
            </div>
            <button type="submit" class="w-full py-2.5 bg-primary text-white rounded-xl font-medium hover:bg-purple-700 transition-colors">
              <i class="ri-flag-line mr-1"></i>Set Goal
            </button>
          </form>
        </div>

        <!-- Progress Bar -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border p-6">
          <h3 class="font-semibold text-gray-800 mb-5">Progress Towards Goal</h3>
          <?php if ($currentScore && $targetScore): ?>
          <?php
            $pct = min(100, max(0, round(($currentScore-300)/($targetScore-300)*100)));
            $onTrack = $currentScore >= $targetScore;
          ?>
          <div class="flex items-center justify-between mb-4">
            <div class="text-center">
              <p class="text-xs text-gray-400 mb-1">Current Score</p>
              <p class="text-3xl font-bold <?= $currentScore>=740?'text-green-600':($currentScore>=670?'text-yellow-600':'text-red-500') ?>"><?= $currentScore ?></p>
              <?php $band=scoreBand($currentScore); ?><p class="text-xs text-gray-400"><?= $band['label'] ?></p>
            </div>
            <div class="flex-1 mx-8">
              <div class="relative">
                <div class="w-full bg-gray-100 rounded-full h-5">
                  <div class="<?= $onTrack?'bg-green-500':'bg-primary' ?> h-5 rounded-full transition-all relative" style="width:<?= $pct ?>%">
                    <?php if ($pct>10): ?><span class="absolute right-2 top-0 h-full flex items-center text-xs text-white font-bold"><?= $pct ?>%</span><?php endif; ?>
                  </div>
                </div>
                <div class="absolute top-7 left-0 right-0">
                  <p class="text-xs text-gray-400 text-center">
                    <?= $onTrack ? '🎉 Goal achieved!' : "Gap: $gap points — Est. ~$months months" ?>
                  </p>
                </div>
              </div>
            </div>
            <div class="text-center">
              <p class="text-xs text-gray-400 mb-1">Target Score</p>
              <p class="text-3xl font-bold text-primary"><?= $targetScore ?></p>
              <?php $tBand=scoreBand($targetScore); ?><p class="text-xs text-gray-400"><?= $tBand['label'] ?></p>
            </div>
          </div>
          <?php elseif (!$currentScore): ?>
          <div class="flex flex-col items-center justify-center py-8 text-center">
            <i class="ri-line-chart-line text-3xl text-gray-300 mb-2"></i>
            <p class="text-gray-500 text-sm">No score yet — run a prediction first.</p>
            <a href="predictor.php" class="text-primary text-sm font-medium mt-2 hover:underline">Predict My Score →</a>
          </div>
          <?php else: ?>
          <div class="flex flex-col items-center justify-center py-8 text-center">
            <i class="ri-focus-3-line text-3xl text-gray-300 mb-2"></i>
            <p class="text-gray-500 text-sm">Set a goal using the form on the left.</p>
          </div>
          <?php endif; ?>

          <!-- Score History Mini Chart -->
          <?php if (count($scoreHistory) >= 2): ?>
          <div class="mt-8 border-t pt-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Score History</p>
            <canvas id="goalHistoryChart" height="100"></canvas>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Action Steps -->
      <?php if (!empty($steps)): ?>
      <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold text-gray-800 mb-1">Your Personalised Action Plan</h3>
        <p class="text-sm text-gray-400 mb-5">Follow these steps to reach your target of <?= h($targetScore??'your goal') ?></p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <?php foreach ($steps as $i=>$step):
            $cls = $colorMap[$step['color']] ?? 'bg-gray-100 text-gray-600';
          ?>
          <div class="flex items-start gap-4 p-4 rounded-xl bg-gray-50 border hover:border-primary/30 transition-all">
            <div class="flex-shrink-0">
              <div class="w-8 h-8 bg-primary/10 rounded-lg flex items-center justify-center text-primary text-xs font-bold"><?= $i+1 ?></div>
            </div>
            <div class="flex-1">
              <div class="flex items-center gap-2 mb-1">
                <div class="w-6 h-6 <?= $cls ?> rounded-md flex items-center justify-center"><i class="<?= h($step['icon']) ?> text-xs"></i></div>
                <p class="font-semibold text-sm text-gray-800"><?= h($step['title']) ?></p>
              </div>
              <p class="text-xs text-gray-500"><?= h($step['desc']) ?></p>
              <div class="flex items-center gap-3 mt-2">
                <?php if ($step['months'] > 0): ?>
                <span class="text-xs text-gray-400"><i class="ri-time-line mr-0.5"></i><?= $step['months'] ?> month<?= $step['months']>1?'s':'' ?></span>
                <?php endif; ?>
                <span class="text-xs text-green-600 font-medium"><i class="ri-arrow-up-line mr-0.5"></i>+<?= $step['points'] ?> pts est.</span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
(function(){
  const ctx=document.getElementById('goalHistoryChart');
  if(!ctx) return;
  const labels=<?= $chartLabels ?>;
  const data=<?= $chartData ?>;
  if(data.length<2) return;
  const target=<?= $targetScore ?? 'null' ?>;
  const datasets=[{label:'Score',data,borderColor:'#7c3aed',backgroundColor:'rgba(124,58,237,0.06)',borderWidth:2,pointRadius:4,pointBackgroundColor:'#7c3aed',tension:0.3,fill:true}];
  if(target){datasets.push({label:'Target',data:labels.map(()=>target),borderColor:'#22c55e',borderWidth:1.5,borderDash:[4,4],pointRadius:0,fill:false});}
  new Chart(ctx,{type:'line',data:{labels,datasets},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{min:300,max:900,grid:{color:'#f3f4f6'},ticks:{font:{size:9}}},x:{grid:{display:false},ticks:{font:{size:9}}}}}});
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
