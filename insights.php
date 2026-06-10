<?php
require __DIR__ . '/includes/auth_check.php';

// ── Gather user data ──────────────────────────────────────────────────────────
$userRow       = fetchOne($pdo,"SELECT first_name, annual_income FROM users WHERE user_id=?",[$userId]);
$firstName     = $userRow['first_name'] ?? 'User';
$annualIncome  = (float)($userRow['annual_income'] ?? 0);
$monthlyIncome = $annualIncome > 0 ? $annualIncome/12 : 0;

$activeLoans    = (int)fetchValue($pdo,"SELECT COUNT(*) FROM loans WHERE user_id=? AND status='Active'",[$userId]);
$totalLoans     = (int)fetchValue($pdo,"SELECT COUNT(*) FROM loans WHERE user_id=?",[$userId]);
$totalDebt      = (float)fetchValue($pdo,"SELECT COALESCE(SUM(loan_amount),0) FROM loans WHERE user_id=? AND status='Active'",[$userId]);
$monthlyEmi     = (float)fetchValue($pdo,"SELECT COALESCE(SUM(emi_amount),0) FROM loans WHERE user_id=? AND status='Active'",[$userId]);
$avgRate        = (float)fetchValue($pdo,"SELECT COALESCE(AVG(interest_rate),0) FROM loans WHERE user_id=?",[$userId]);
$pendingRem     = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status IN ('Upcoming','High Priority')",[$userId]);
$highPriorityRem= (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status='High Priority'",[$userId]);
$documentsCount = (int)fetchValue($pdo,"SELECT COUNT(*) FROM documents WHERE user_id=?",[$userId]);
$latestScore    = fetchOne($pdo,"SELECT cibil_score, prediction_date FROM cibil_scores WHERE user_id=? ORDER BY prediction_date DESC LIMIT 1",[$userId]);
$scoreValue     = $latestScore ? (int)$latestScore['cibil_score'] : null;
$lastScoreDays  = $latestScore ? (int)ceil((time()-strtotime($latestScore['prediction_date']))/86400) : null;
$oldestStart    = fetchValue($pdo,"SELECT MIN(start_date) FROM loans WHERE user_id=? AND start_date IS NOT NULL",[$userId]);
$ageMonths = 0;
if ($oldestStart) { $d1=new DateTime($oldestStart); $d2=new DateTime('today'); $ageMonths=(int)($d1->diff($d2)->y*12+$d1->diff($d2)->m); }
$recentLoans = (int)fetchValue($pdo,"SELECT COUNT(*) FROM loans WHERE user_id=? AND start_date>=DATE_SUB(CURDATE(),INTERVAL 6 MONTH)",[$userId]);

$emiRatio = $monthlyIncome > 0 ? $monthlyEmi/$monthlyIncome : 0;
$utilRatio = ($annualIncome>0) ? $totalDebt/($annualIncome*0.5) : 0; // vs 6 months income

// ── Generate insights ─────────────────────────────────────────────────────────
$insights = [];
$priority = []; // track priority levels

function addInsight(array &$list, string $icon, string $color, string $title, string $body, string $level, string $action='', string $href=''): void {
    $list[]=compact('icon','color','title','body','level','action','href');
}
$cGood='green'; $cWarn='yellow'; $cBad='red'; $cInfo='blue'; $cPurple='purple';

// 1. Score check
if ($scoreValue===null) {
    addInsight($insights,'ri-line-chart-line','blue','No Score on Record','You haven\'t run a CIBIL score prediction yet. Run one now to get personalized recommendations and track your progress.','high','Predict My Score','predictor.php');
} elseif ($scoreValue < 580) {
    addInsight($insights,'ri-error-warning-line','red','Your Score Needs Urgent Attention',"Your CIBIL score is $scoreValue (Poor). This significantly limits your loan eligibility and interest rate. Focus on timely payments and reducing debt.",'high','View Score Details','predictor.php');
} elseif ($scoreValue < 670) {
    addInsight($insights,'ri-alert-line','yellow','Score in Fair Range',"Your score of $scoreValue is fair but below lender thresholds for the best rates. Improving by 30–50 points will unlock better loan terms.",'medium','How to Improve','predictor.php');
} elseif ($scoreValue >= 800) {
    addInsight($insights,'ri-trophy-line','green','Excellent Credit Score!',"Your score of $scoreValue is excellent. Maintain timely payments and keep utilization low to preserve this.",'low','','');
}

// 2. Score freshness
if ($lastScoreDays !== null && $lastScoreDays > 30) {
    addInsight($insights,'ri-refresh-line','blue','Score Not Updated Recently',"Your last prediction was $lastScoreDays days ago. Run a new prediction to track your progress and get up-to-date recommendations.",'medium','Run Prediction','predictor.php');
}

// 3. EMI burden
if ($emiRatio > 0.50) {
    addInsight($insights,'ri-funds-line','red','Critical EMI Burden',"Your EMI payments are ".round($emiRatio*100)."% of monthly income — dangerously high. This signals financial stress to lenders. Consider closing or prepaying a loan.",'high','Simulate Prepayment','loans.php');
} elseif ($emiRatio > 0.35) {
    addInsight($insights,'ri-wallet-line','yellow','High EMI-to-Income Ratio',"EMI burden is ".round($emiRatio*100)."% of income. Recommended threshold is under 35%. Avoid new loans until balances reduce.",'medium','View Loans','loans.php');
}

// 4. Credit utilization
if ($utilRatio > 0.80) {
    addInsight($insights,'ri-percent-line','red','Very High Credit Utilization',"You are using ".round($utilRatio*100)."% of your estimated credit capacity. High utilization directly reduces your score. Pay down balances immediately.",'high','','');
} elseif ($utilRatio > 0.50) {
    addInsight($insights,'ri-percent-line','yellow','Moderate Credit Utilization',"Utilization at ".round($utilRatio*100)."%. Keep it below 30% for optimal score impact.",'medium','','');
}

// 5. Pending reminders
if ($highPriorityRem > 0) {
    addInsight($insights,'ri-alarm-warning-line','red','High Priority Reminders Pending',"You have $highPriorityRem high-priority reminder(s). Missed payments are the single biggest score damage factor — act now.",'high','View Reminders','reminders.php');
} elseif ($pendingRem > 3) {
    addInsight($insights,'ri-alarm-line','yellow','Several Reminders Pending',"$pendingRem reminders are upcoming. Stay ahead of due dates to protect your payment history.",'medium','View Reminders','reminders.php');
}

// 6. Documents
if ($documentsCount === 0) {
    addInsight($insights,'ri-folder-add-line','yellow','No Documents Uploaded',"Uploading KYC and income documents improves your loan approval odds and enables faster processing by lenders.",'medium','Upload Documents','documents.php');
} elseif ($documentsCount < 3) {
    addInsight($insights,'ri-folder-line','blue','Upload More Documents',"You have $documentsCount document(s). Adding more (ID proof, income proof, bank statement) builds a complete financial profile.",'low','','');
}

// 7. Loan count
if ($totalLoans > 5) {
    addInsight($insights,'ri-bank-line','yellow','Multiple Concurrent Loans',"You have $totalLoans loans. Multiple open accounts increase perceived risk. Focus on closing smaller ones before applying for new credit.",'medium','View Loans','loans.php');
}

// 8. Recent credit inquiries (proxy: recent loans)
if ($recentLoans >= 3) {
    addInsight($insights,'ri-search-line','yellow','Frequent Recent Loan Applications',"$recentLoans loans opened in the last 6 months. Multiple recent applications can lower your score — space out future applications by at least 6 months.",'medium','','');
}

// 9. Credit age
if ($ageMonths < 12 && $totalLoans > 0) {
    addInsight($insights,'ri-calendar-line','blue','Building Credit History',"Your credit history is $ageMonths months old. Credit age takes time to build — keep existing accounts open and active.",'low','','');
} elseif ($ageMonths === 0 && $totalLoans === 0) {
    addInsight($insights,'ri-add-circle-line','blue','No Credit History Yet',"You have no loan history. Consider a secured credit card or a small loan to start building credit.",'low','','');
}

// 10. Positive reinforcement
if ($scoreValue !== null && $scoreValue >= 740 && $emiRatio < 0.30 && $pendingRem === 0) {
    addInsight($insights,'ri-star-line','green','Strong Financial Health',"Your score, EMI ratio, and reminders are all in great shape. Keep up the discipline — you're positioned for preferential loan rates.",'low','View Health Score','health.php');
}

// Sort: high → medium → low
$order=['high'=>0,'medium'=>1,'low'=>2];
usort($insights,fn($a,$b)=>($order[$a['level']]??3)-($order[$b['level']]??3));

// Save top insights to recommendations table (clear old and insert fresh)
try {
    $pdo->prepare("DELETE FROM recommendations WHERE user_id=? AND score_id IS NULL")->execute([$userId]);
    foreach (array_slice($insights,0,5) as $ins) {
        $pdo->prepare("INSERT INTO recommendations (user_id,score_id,recommendation_text) VALUES (?,NULL,?)")->execute([$userId,$ins['title'].': '.$ins['body']]);
    }
} catch(PDOException $e){}

$levelColors=['high'=>['bg-red-50 border-red-200','bg-red-100 text-red-600','text-red-700'],'medium'=>['bg-yellow-50 border-yellow-200','bg-yellow-100 text-yellow-600','text-yellow-700'],'low'=>['bg-green-50 border-green-200','bg-green-100 text-green-600','text-green-700']];
$iconColors=['red'=>'text-red-500','yellow'=>'text-yellow-500','green'=>'text-green-600','blue'=>'text-blue-600','purple'=>'text-purple-600'];

$highCount  = count(array_filter($insights,fn($i)=>$i['level']==='high'));
$medCount   = count(array_filter($insights,fn($i)=>$i['level']==='medium'));
$lowCount   = count(array_filter($insights,fn($i)=>$i['level']==='low'));

$highPriorityReminders = $highPriorityRem;
$pageTitle  = 'My Insights';
$activePage = 'insights';
?>
<?php require __DIR__ . '/includes/page_head.php'; ?>
<div class="flex">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <div class="ml-64 flex-1 min-h-screen">
    <?php require __DIR__ . '/includes/header.php'; ?>
    <div class="p-8 space-y-6 fade-in">

      <!-- Header -->
      <div class="bg-gradient-to-br from-yellow-500 to-orange-600 text-white rounded-2xl p-8">
        <p class="text-yellow-100 text-sm font-medium mb-1">Personalised for <?= h($firstName) ?></p>
        <h2 class="text-3xl font-bold mb-2">My Financial Insights</h2>
        <p class="text-yellow-100 leading-relaxed">AI-generated recommendations based on your loans, score, reminders, and documents.</p>
      </div>

      <!-- Insight counts -->
      <div class="grid grid-cols-3 gap-5">
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-error-warning-line text-red-600 text-lg"></i></div>
          <div class="text-2xl font-bold text-gray-800"><?= $highCount ?></div>
          <div class="text-sm text-gray-500">High Priority</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-alert-line text-yellow-600 text-lg"></i></div>
          <div class="text-2xl font-bold text-gray-800"><?= $medCount ?></div>
          <div class="text-sm text-gray-500">Medium Priority</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-checkbox-circle-line text-green-600 text-lg"></i></div>
          <div class="text-2xl font-bold text-gray-800"><?= $lowCount ?></div>
          <div class="text-sm text-gray-500">Informational</div>
        </div>
      </div>

      <!-- Insight Cards -->
      <div class="space-y-4">
        <?php if (empty($insights)): ?>
        <div class="bg-white rounded-xl shadow-sm border p-14 flex flex-col items-center text-center">
          <i class="ri-star-line text-4xl text-yellow-400 mb-3"></i>
          <p class="font-semibold text-gray-700">Excellent financial profile!</p>
          <p class="text-sm text-gray-400 mt-1">No actionable insights right now — keep maintaining your great habits.</p>
        </div>
        <?php else: ?>
        <?php foreach ($insights as $ins):
          [$cardBg,$badgeCls,$textCls] = $levelColors[$ins['level']] ?? $levelColors['low'];
          $iconCls = $iconColors[$ins['color']] ?? 'text-gray-600';
        ?>
        <div class="border rounded-xl p-5 <?= $cardBg ?> card-hover">
          <div class="flex items-start gap-4">
            <div class="w-10 h-10 bg-white/80 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm">
              <i class="<?= h($ins['icon']) ?> <?= $iconCls ?> text-lg"></i>
            </div>
            <div class="flex-1">
              <div class="flex items-center gap-2 flex-wrap mb-1">
                <p class="font-semibold text-gray-800 text-sm"><?= h($ins['title']) ?></p>
                <span class="px-2 py-0.5 rounded-full text-xs font-bold <?= $badgeCls ?>"><?= ucfirst($ins['level']) ?></span>
              </div>
              <p class="text-sm <?= $textCls ?> leading-relaxed"><?= h($ins['body']) ?></p>
              <?php if ($ins['action']&&$ins['href']): ?>
              <a href="<?= h($ins['href']) ?>" class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-primary bg-white/80 hover:bg-white px-3 py-1.5 rounded-lg transition-colors">
                <?= h($ins['action']) ?> <i class="ri-arrow-right-line"></i>
              </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Quick Links -->
      <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold text-gray-800 mb-4">Take Action</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <?php $links=[['predictor.php','ri-line-chart-line','primary','Run Score Prediction'],['goals.php','ri-focus-3-line','blue','Set a Goal'],['health.php','ri-heart-pulse-line','red','Financial Health'],['tutorials.php','ri-graduation-cap-line','green','Learn More']]; ?>
          <?php foreach($links as [$href,$icon,$c,$lbl]): ?>
          <a href="<?= $href ?>" class="flex items-center gap-3 p-3 rounded-xl border hover:border-primary/30 hover:bg-primary/5 transition-all">
            <div class="w-8 h-8 bg-<?= $c ?>-100 rounded-lg flex items-center justify-center"><i class="<?= $icon ?> text-<?= $c ?>-600"></i></div>
            <span class="text-xs font-medium text-gray-700"><?= $lbl ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
