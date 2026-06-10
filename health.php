<?php
require __DIR__ . '/includes/auth_check.php';

// ── Data collection ───────────────────────────────────────────────────────────
$userRow       = fetchOne($pdo,"SELECT first_name, annual_income FROM users WHERE user_id=?",[$userId]);
$firstName     = $userRow['first_name'] ?? 'User';
$annualIncome  = (float)($userRow['annual_income'] ?? 0);
$monthlyIncome = $annualIncome > 0 ? $annualIncome/12 : 0;

$latestScore   = fetchOne($pdo,"SELECT cibil_score FROM cibil_scores WHERE user_id=? ORDER BY prediction_date DESC LIMIT 1",[$userId]);
$scoreValue    = $latestScore ? (int)$latestScore['cibil_score'] : null;

$activeLoans   = (int)fetchValue($pdo,"SELECT COUNT(*) FROM loans WHERE user_id=? AND status='Active'",[$userId]);
$monthlyEmi    = (float)fetchValue($pdo,"SELECT COALESCE(SUM(emi_amount),0) FROM loans WHERE user_id=? AND status='Active'",[$userId]);
$totalDebt     = (float)fetchValue($pdo,"SELECT COALESCE(SUM(loan_amount),0) FROM loans WHERE user_id=? AND status='Active'",[$userId]);
$pendingRem    = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status IN ('Upcoming','High Priority')",[$userId]);
$highPriorityRem=(int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status='High Priority'",[$userId]);
$docCount      = (int)fetchValue($pdo,"SELECT COUNT(*) FROM documents WHERE user_id=?",[$userId]);

$emiRatio = $monthlyIncome > 0 ? $monthlyEmi/$monthlyIncome : 0;
$utilRatio= $annualIncome  > 0 ? $totalDebt/($annualIncome*0.5) : 0;

// ── Health Score Calculation ─────────────────────────────────────────────────
// Total: 100 points
// 1. CIBIL Score   — 40 pts
$cibilScore = 0;
if ($scoreValue !== null) {
    $cibilScore = min(40, round(($scoreValue - 300) / 600 * 40));
}

// 2. EMI Burden    — 20 pts (0 = ratio ≥ 50%, 20 = ratio 0%)
$emiScore = 0;
if ($emiRatio <= 0) $emiScore = 20;
elseif ($emiRatio < 0.20) $emiScore = 18;
elseif ($emiRatio < 0.30) $emiScore = 14;
elseif ($emiRatio < 0.40) $emiScore = 10;
elseif ($emiRatio < 0.50) $emiScore = 5;
else $emiScore = 0;

// 3. Documents     — 15 pts
$docScore = min(15, $docCount * 3);

// 4. Reminders     — 15 pts (fully on top if none pending)
$remScore = max(0, 15 - $pendingRem * 3);

// 5. Income stability — 10 pts
$incScore = 0;
if ($annualIncome >= 1000000) $incScore = 10;
elseif ($annualIncome >= 500000) $incScore = 8;
elseif ($annualIncome >= 300000) $incScore = 6;
elseif ($annualIncome >= 100000) $incScore = 3;
else $incScore = 0;

$healthScore = $cibilScore + $emiScore + $docScore + $remScore + $incScore;
$healthScore = max(0, min(100, $healthScore));

$factors = [
    ['CIBIL Score','credit card','40',$cibilScore,'blue', $scoreValue!==null ? 'Score: '.$scoreValue : 'No prediction yet', $scoreValue!==null && $scoreValue<700 ? 'Run predictor to improve' : ''],
    ['EMI Burden','wallet','20',$emiScore,'purple', 'Ratio: '.round($emiRatio*100).'%', $emiRatio>0.35 ? 'High EMI ratio — prepay a loan' : ''],
    ['Documents','folder','15',$docScore,'green', $docCount.' document(s) uploaded', $docCount<3 ? 'Upload more documents' : ''],
    ['Payment Reminders','alarm','15',$remScore,'orange', $pendingRem.' pending reminder(s)', $pendingRem>0 ? 'Clear pending reminders' : ''],
    ['Income Level','coins','10',$incScore,'teal', $annualIncome>0 ? '₹'.number_format($annualIncome,0).'/yr' : 'Not set', $annualIncome<=0 ? 'Update income in profile' : ''],
];

// Health band
if ($healthScore >= 80) { $band='Excellent'; $bandColor='text-green-600'; $bandBg='bg-green-50 border-green-200'; $gaugeColor='#22c55e'; }
elseif ($healthScore >= 60) { $band='Good'; $bandColor='text-blue-600'; $bandBg='bg-blue-50 border-blue-200'; $gaugeColor='#3b82f6'; }
elseif ($healthScore >= 40) { $band='Fair'; $bandColor='text-yellow-600'; $bandBg='bg-yellow-50 border-yellow-200'; $gaugeColor='#eab308'; }
else { $band='Poor'; $bandColor='text-red-600'; $bandBg='bg-red-50 border-red-200'; $gaugeColor='#ef4444'; }

// Weak areas
$weakAreas = [];
foreach ($factors as [$name,,$max,$got,,,$tip]) {
    if ($got < (int)$max * 0.6 && $tip) $weakAreas[] = ['name'=>$name,'tip'=>$tip,'pct'=>$got/(int)$max*100];
}

$highPriorityReminders = $highPriorityRem;
$pageTitle  = 'Financial Health Score';
$activePage = 'health';
?>
<?php require __DIR__ . '/includes/page_head.php'; ?>
<div class="flex">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <div class="ml-64 flex-1 min-h-screen">
    <?php require __DIR__ . '/includes/header.php'; ?>
    <div class="p-8 space-y-6 fade-in">

      <!-- Score Hero -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Gauge -->
        <div class="bg-white rounded-2xl shadow-sm border p-8 flex flex-col items-center">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-4">Financial Health</p>
          <div class="relative w-48 h-48">
            <canvas id="healthGauge"></canvas>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
              <span class="text-5xl font-bold text-gray-800"><?= $healthScore ?></span>
              <span class="text-xs text-gray-400 mt-1">out of 100</span>
              <span class="mt-2 px-3 py-0.5 rounded-full text-sm font-bold <?= $bandColor ?> <?= $bandBg ?> border"><?= $band ?></span>
            </div>
          </div>
          <p class="text-xs text-gray-400 mt-4 text-center">Based on your CIBIL score, EMI burden, documents, reminders, and income</p>
        </div>

        <!-- Band explanation -->
        <div class="lg:col-span-2 space-y-4">
          <div class="<?= $bandBg ?> border rounded-2xl p-6">
            <h3 class="font-bold text-gray-800 text-xl mb-2">Health Status: <span class="<?= $bandColor ?>"><?= $band ?></span></h3>
            <p class="text-gray-600 text-sm leading-relaxed">
              <?php
              echo match(true) {
                $healthScore>=80=>"Excellent financial health! Your CIBIL score, EMI management, and documentation are all in great shape. Keep this up to access the best loan rates.",
                $healthScore>=60=>"Good financial health. A few areas need attention to move from Good to Excellent. Focus on the weak areas below.",
                $healthScore>=40=>"Fair financial health. Multiple factors are dragging your score. Address the high-priority items first to see quick improvements.",
                default=>"Poor financial health. Immediate action needed. Start with clearing overdue reminders and running a CIBIL prediction to understand your standing."
              };
              ?>
            </p>
          </div>
          <!-- Score bands legend -->
          <div class="bg-white rounded-xl shadow-sm border p-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Health Score Ranges</p>
            <div class="space-y-2">
              <?php foreach([['80-100','Excellent','bg-green-500',80],['60-79','Good','bg-blue-500',60],['40-59','Fair','bg-yellow-500',40],['0-39','Poor','bg-red-500',0]] as [$range,$label,$color,$min]): ?>
              <div class="flex items-center gap-3">
                <div class="w-3 h-3 <?= $color ?> rounded-full flex-shrink-0"></div>
                <span class="text-xs text-gray-500 w-16"><?= $range ?></span>
                <span class="text-xs font-medium text-gray-700"><?= $label ?></span>
                <?php if ($healthScore>=$min&&($min===0||$healthScore<$min+20||(end(array_keys(array_filter([80,60,40,0],fn($x)=>$x<=$healthScore)))??0)===$min)): ?>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Factor Breakdown -->
      <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold text-gray-800 mb-5">Score Breakdown</h3>
        <div class="space-y-4">
          <?php $factorColors=['blue'=>['bg-blue-500','bg-blue-50 text-blue-600'],'purple'=>['bg-purple-500','bg-purple-50 text-purple-600'],'green'=>['bg-green-500','bg-green-50 text-green-600'],'orange'=>['bg-orange-500','bg-orange-50 text-orange-600'],'teal'=>['bg-teal-500','bg-teal-50 text-teal-600']]; ?>
          <?php foreach ($factors as [$name,$icon,$max,$got,$color,$detail,$tip]):
            $pct = (int)$max > 0 ? round($got/(int)$max*100) : 0;
            [$barColor,$badgeCls] = $factorColors[$color] ?? ['bg-gray-500','bg-gray-50 text-gray-600'];
          ?>
          <div class="flex items-center gap-4">
            <div class="w-36 flex-shrink-0">
              <p class="text-sm font-medium text-gray-700"><?= $name ?></p>
              <p class="text-xs text-gray-400"><?= $detail ?></p>
            </div>
            <div class="flex-1">
              <div class="w-full bg-gray-100 rounded-full h-3 relative">
                <div class="<?= $barColor ?> h-3 rounded-full transition-all" style="width:<?= $pct ?>%"></div>
              </div>
              <?php if ($tip): ?>
              <p class="text-xs text-orange-500 mt-0.5"><i class="ri-arrow-right-line mr-0.5"></i><?= h($tip) ?></p>
              <?php endif; ?>
            </div>
            <div class="text-right w-20 flex-shrink-0">
              <span class="text-sm font-bold text-gray-800"><?= $got ?> / <?= $max ?></span>
              <p class="text-xs text-gray-400"><?= $pct ?>%</p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <!-- Total -->
        <div class="mt-5 pt-5 border-t flex items-center justify-between">
          <span class="font-bold text-gray-800">Total Health Score</span>
          <span class="text-2xl font-bold <?= $bandColor ?>"><?= $healthScore ?> / 100</span>
        </div>
      </div>

      <!-- What's dragging your score? -->
      <?php if (!empty($weakAreas)): ?>
      <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="ri-arrow-down-circle-line text-red-500"></i>What's Dragging Your Score?</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <?php foreach ($weakAreas as $w): ?>
          <div class="flex items-start gap-3 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0"><i class="ri-arrow-down-line text-red-500"></i></div>
            <div>
              <p class="font-semibold text-sm text-gray-800"><?= h($w['name']) ?></p>
              <p class="text-xs text-red-600 mt-0.5"><?= h($w['tip']) ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Quick Actions -->
      <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold text-gray-800 mb-4">Improve Your Health Score</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <?php $qlinks=[['predictor.php','ri-line-chart-line','purple','Predict Score'],['goals.php','ri-focus-3-line','blue','Set a Goal'],['reminders.php','ri-alarm-line','orange','Manage Reminders'],['documents.php','ri-folder-add-line','green','Upload Docs']]; ?>
          <?php foreach($qlinks as [$href,$icon,$c,$lbl]): ?>
          <a href="<?= $href ?>" class="flex items-center gap-3 p-3 rounded-xl border hover:border-primary/30 hover:bg-primary/5 transition-all card-hover">
            <div class="w-9 h-9 bg-<?= $c ?>-100 rounded-lg flex items-center justify-center"><i class="<?= $icon ?> text-<?= $c ?>-600 text-base"></i></div>
            <span class="text-xs font-medium text-gray-700"><?= $lbl ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function(){
  const ctx=document.getElementById('healthGauge');
  if(!ctx) return;
  const score=<?= $healthScore ?>;
  const color='<?= $gaugeColor ?>';
  const pct=score/100;
  new Chart(ctx,{type:'doughnut',data:{datasets:[{data:[pct,1-pct],backgroundColor:[color,'#f3f4f6'],borderWidth:0,circumference:270,rotation:225}]},options:{cutout:'80%',plugins:{legend:{display:false},tooltip:{enabled:false}},animation:{duration:1200}}});
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
