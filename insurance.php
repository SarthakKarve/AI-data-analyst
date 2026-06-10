<?php
require __DIR__ . '/includes/auth_check.php';

// ── Context from DB ───────────────────────────────────────────────────────────
$userRow       = fetchOne($pdo,"SELECT first_name, annual_income FROM users WHERE user_id=?",[$userId]);
$firstName     = $userRow['first_name'] ?? 'User';
$annualIncome  = (float)($userRow['annual_income'] ?? 0);
$monthlyIncDef = $annualIncome > 0 ? round($annualIncome/12,2) : 0;

$activeLoansCount    = (int)fetchValue($pdo,"SELECT COUNT(*) FROM loans WHERE user_id=? AND status='Active'",[$userId]);
$activeLoanSum       = (float)fetchValue($pdo,"SELECT COALESCE(SUM(loan_amount),0) FROM loans WHERE user_id=? AND status='Active'",[$userId]);
$monthlyEmiSum       = (float)fetchValue($pdo,"SELECT COALESCE(SUM(emi_amount),0) FROM loans WHERE user_id=? AND status='Active'",[$userId]);
$documentsCount      = (int)fetchValue($pdo,"SELECT COUNT(*) FROM documents WHERE user_id=?",[$userId]);
$highPriorityRem     = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status='High Priority'",[$userId]);
$highPriorityReminders = $highPriorityRem;

// Previous calculations
$prevInsurance = fetchAll($pdo,"SELECT * FROM insurance WHERE user_id=? ORDER BY insurance_id DESC LIMIT 3",[$userId]);

$csrf = csrfToken();
$errors = []; $calculated = false;
$eligibility=null; $eligibilityReason=''; $claimProb=null;
$coverage=[]; $plans=[]; $recommended='';
$inputAge=30; $inputEmp='Salaried'; $inputMonthly=$monthlyIncDef;
$inputExisting=0; $inputDep=0; $inputRisk='Low';

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrfVerify($_POST['csrf']??'')) { $errors[]='Invalid CSRF token.'; }
    else {
        $inputAge      = max(0,(int)($_POST['age']??0));
        $inputEmp      = trim($_POST['employment']??'Salaried');
        $inputMonthly  = (float)($_POST['monthly_income']??$monthlyIncDef);
        $inputExisting = max(0,(float)($_POST['existing_coverage']??0));
        $inputDep      = max(0,(int)($_POST['dependents']??0));
        $inputRisk     = trim($_POST['health_risk']??'Low');
        if ($inputMonthly<=0&&$monthlyIncDef>0) $inputMonthly=$monthlyIncDef;

        // Eligibility
        $hasDocs  = $documentsCount>0;
        $withinAge= $inputAge>=18&&$inputAge<=65;
        $hasInc   = $inputMonthly>=20000;
        if ($withinAge&&$hasInc) {
            $eligibility=true;
            $eligibilityReason='Eligible — age and income criteria met.'
                .($hasDocs?' KYC documents found, which will speed up approval.':' Upload KYC documents to finalize your application.');
        } else {
            $eligibility=false;
            $reasons=[];
            if (!$withinAge) $reasons[]='Age must be between 18 and 65 (you entered '.$inputAge.')';
            if (!$hasInc) $reasons[]='Monthly income must be ≥ ₹20,000 (you entered ₹'.number_format($inputMonthly,0).')';
            $eligibilityReason=implode('. ',$reasons).'.';
        }

        // Claim probability
        $prob=70;
        $emiRatio = $inputMonthly>0 ? $monthlyEmiSum/$inputMonthly : 0;
        if ($emiRatio>0.5) $prob-=15; elseif ($emiRatio>0.35) $prob-=8; elseif ($emiRatio>0.2) $prob-=3;
        if ($documentsCount>=5) $prob+=10; elseif ($documentsCount>=2) $prob+=4;
        if ($highPriorityRem>=3) $prob-=12; elseif ($highPriorityRem>=1) $prob-=5;
        if ($inputEmp==='Salaried') $prob+=5; elseif ($inputEmp==='Unemployed') $prob-=15;
        if ($inputAge<21) $prob-=5; elseif ($inputAge>60) $prob-=10;
        if ($inputRisk==='High') $prob-=10; elseif ($inputRisk==='Moderate') $prob-=4;
        $claimProb=max(5,min(95,(int)round($prob)));

        // Coverage suggestions
        $loanCov   = max(0,$activeLoanSum-$inputExisting);
        $incCov    = (int)round($inputMonthly*($inputDep>0?18:12));
        $healthCov = $inputRisk==='High'?2000000:($inputRisk==='Moderate'?1000000:500000);
        $coverage  = [
            ['label'=>'Loan Protection','amount'=>$loanCov,'note'=>'Covers outstanding active loan balance.','icon'=>'ri-bank-line','color'=>'blue'],
            ['label'=>'Income Protection','amount'=>$incCov,'note'=>'Covers '.($inputDep>0?'18':'12').' months of income for dependents.','icon'=>'ri-wallet-line','color'=>'green'],
            ['label'=>'Health Coverage','amount'=>$healthCov,'note'=>'Recommended sum insured for your risk level.','icon'=>'ri-heart-pulse-line','color'=>'red'],
        ];

        // Premium estimates (simplified)
        $termPremium   = round(max($loanCov,$incCov)*0.005/12);   // ~0.5% per year
        $loanPremium   = round($loanCov*0.006/12);                 // ~0.6% per year
        $healthPremium = $inputAge<35?500:($inputAge<50?1200:2500);// per month estimate

        $plans = [
            ['name'=>'Term Life Insurance','icon'=>'ri-shield-star-line','color'=>'blue',
             'coverage'=>max($loanCov,$incCov),'premium'=>$termPremium,
             'best_for'=>'Primary earners with dependents or active loans',
             'eligible'=>$withinAge&&$hasInc,'recommended'=>$inputDep>0||$activeLoanSum>0],
            ['name'=>'Loan Protection Insurance','icon'=>'ri-bank-card-line','color'=>'purple',
             'coverage'=>$loanCov,'premium'=>$loanPremium,
             'best_for'=>'Borrowers wanting their loans covered on death/disability',
             'eligible'=>$withinAge&&$loanCov>0,'recommended'=>$activeLoanSum>0],
            ['name'=>'Health Insurance','icon'=>'ri-hospital-line','color'=>'red',
             'coverage'=>$healthCov,'premium'=>$healthPremium,
             'best_for'=>'Everyone — medical emergencies, hospitalization',
             'eligible'=>$withinAge,'recommended'=>true],
        ];
        foreach ($plans as $p) { if ($p['recommended']) { $recommended=$p['name']; break; } }

        // Save to insurance table
        if ($eligibility) {
            foreach ($plans as $p) {
                if ($p['eligible']) {
                    try {
                        $pdo->prepare("INSERT INTO insurance (user_id,insurance_type,premium_amount,coverage_amount,eligibility_status,remarks) VALUES (?,?,?,?,?,?)")
                            ->execute([$userId,$p['name'],$p['premium'],$p['coverage'],'Eligible','Calculated on '.date('Y-m-d')]);
                    } catch(PDOException $e){}
                }
            }
            logActivity($pdo,$userId,'insurance_calculated',"Calculated insurance eligibility (age:$inputAge, risk:$inputRisk)");
        }

        $calculated=true;
    }
}

$pageTitle  = 'Insurance Calculator';
$activePage = 'insurance';
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

      <!-- Form + Results -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Input Form -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
          <h3 class="font-semibold text-gray-800 mb-1 flex items-center gap-2"><i class="ri-calculator-line text-primary"></i>Insurance Calculator</h3>
          <p class="text-sm text-gray-400 mb-5">Enter your details to get personalized insurance recommendations.</p>
          <form method="post" class="space-y-4">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <div><label class="text-xs text-gray-500 mb-1 block">Age</label>
              <input type="number" name="age" min="1" max="100" value="<?= h($inputAge) ?>" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
            <div><label class="text-xs text-gray-500 mb-1 block">Employment Status</label>
              <select name="employment" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
                <?php foreach(['Salaried','Self-Employed','Business Owner','Freelancer','Unemployed'] as $emp): ?>
                <option <?= $inputEmp===$emp?'selected':'' ?>><?= $emp ?></option>
                <?php endforeach; ?>
              </select></div>
            <div><label class="text-xs text-gray-500 mb-1 block">Monthly Income (₹)</label>
              <input type="number" step="100" name="monthly_income" value="<?= h($inputMonthly) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
            <div><label class="text-xs text-gray-500 mb-1 block">Dependents</label>
              <input type="number" name="dependents" min="0" value="<?= h($inputDep) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
            <div><label class="text-xs text-gray-500 mb-1 block">Existing Coverage (₹)</label>
              <input type="number" step="1000" name="existing_coverage" value="<?= h($inputExisting) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
            <div><label class="text-xs text-gray-500 mb-1 block">Health Risk</label>
              <select name="health_risk" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
                <?php foreach(['Low','Moderate','High'] as $r): ?>
                <option <?= $inputRisk===$r?'selected':'' ?>><?= $r ?></option>
                <?php endforeach; ?>
              </select></div>
            <button type="submit" class="w-full py-2.5 bg-primary text-white rounded-xl font-medium hover:bg-purple-700 transition-colors">
              <i class="ri-shield-check-line mr-1"></i>Calculate & Save
            </button>
          </form>
        </div>

        <!-- Results -->
        <div class="lg:col-span-2 space-y-5">
          <?php if ($calculated): ?>

          <!-- Eligibility Banner -->
          <div class="<?= $eligibility ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?> border rounded-xl p-5">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 <?= $eligibility?'bg-green-100':'bg-red-100' ?> rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="<?= $eligibility?'ri-checkbox-circle-line text-green-600':'ri-close-circle-line text-red-500' ?> text-xl"></i>
              </div>
              <div>
                <p class="font-bold text-sm"><?= $eligibility?'You are Eligible for Insurance':'Eligibility Check Failed' ?></p>
                <p class="text-xs mt-0.5 opacity-80"><?= h($eligibilityReason) ?></p>
              </div>
              <?php if ($eligibility): ?>
              <div class="ml-auto text-right">
                <p class="text-xs opacity-70">Claim Probability</p>
                <p class="text-2xl font-bold"><?= $claimProb ?>%</p>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Coverage Needs -->
          <div class="bg-white rounded-xl shadow-sm border p-5">
            <h4 class="font-semibold text-gray-800 mb-4">Recommended Coverage Amounts</h4>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <?php $cColors=['blue'=>'bg-blue-50 border-blue-200 text-blue-700','green'=>'bg-green-50 border-green-200 text-green-700','red'=>'bg-red-50 border-red-200 text-red-700']; ?>
              <?php foreach ($coverage as $c): ?>
              <div class="<?= $cColors[$c['color']] ?> border rounded-xl p-4">
                <i class="<?= h($c['icon']) ?> text-xl mb-2 block"></i>
                <p class="text-xs font-semibold mb-1"><?= h($c['label']) ?></p>
                <p class="text-xl font-bold"><?= inrShort((float)$c['amount']) ?></p>
                <p class="text-xs mt-1 opacity-75"><?= h($c['note']) ?></p>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Plan Comparison Table -->
          <div class="bg-white rounded-xl shadow-sm border p-5">
            <h4 class="font-semibold text-gray-800 mb-4">Plan Comparison</h4>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <?php $planColors=['blue'=>['ring-blue-400','bg-blue-600','text-blue-600','bg-blue-50'],'purple'=>['ring-purple-400','bg-purple-600','text-purple-600','bg-purple-50'],'red'=>['ring-red-400','bg-red-600','text-red-600','bg-red-50']]; ?>
              <?php foreach ($plans as $p):
                [$ring,$btnBg,$textC,$cardBg] = $planColors[$p['color']];
                $isRec = $p['name']===$recommended;
              ?>
              <div class="border-2 rounded-xl p-5 relative <?= $isRec ? 'border-primary shadow-md' : 'border-gray-200' ?>">
                <?php if ($isRec): ?>
                <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-primary text-white text-xs font-bold px-3 py-1 rounded-full">Recommended</span>
                <?php endif; ?>
                <div class="flex items-center gap-2 mb-3">
                  <div class="w-9 h-9 <?= $cardBg ?> rounded-xl flex items-center justify-center">
                    <i class="<?= h($p['icon']) ?> <?= $textC ?> text-base"></i>
                  </div>
                  <p class="font-semibold text-sm text-gray-800"><?= h($p['name']) ?></p>
                </div>
                <div class="space-y-2 text-xs">
                  <div class="flex justify-between"><span class="text-gray-400">Coverage</span><span class="font-bold text-gray-700"><?= inrShort((float)$p['coverage']) ?></span></div>
                  <div class="flex justify-between"><span class="text-gray-400">Est. Premium/mo</span><span class="font-bold text-gray-700">₹<?= number_format((float)$p['premium'],0) ?></span></div>
                  <p class="text-gray-500 mt-2 border-t pt-2"><?= h($p['best_for']) ?></p>
                </div>
                <div class="mt-3">
                  <?php if ($p['eligible']): ?>
                  <span class="w-full block text-center py-1.5 rounded-lg text-xs font-medium bg-green-100 text-green-700">Eligible</span>
                  <?php else: ?>
                  <span class="w-full block text-center py-1.5 rounded-lg text-xs font-medium bg-gray-100 text-gray-500">Not Eligible</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <?php else: ?>
          <!-- Placeholder before calculation -->
          <div class="bg-white rounded-xl shadow-sm border p-14 flex flex-col items-center text-center">
            <div class="w-16 h-16 bg-primary/10 rounded-2xl flex items-center justify-center mb-4"><i class="ri-shield-check-line text-3xl text-primary"></i></div>
            <p class="font-semibold text-gray-700">Fill in the form to get your insurance recommendations</p>
            <p class="text-sm text-gray-400 mt-2">Your data is pre-filled from your profile. Just verify the values and click Calculate.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Previous Calculations -->
      <?php if (!empty($prevInsurance)): ?>
      <div class="bg-white rounded-xl shadow-sm border p-6">
        <h4 class="font-semibold text-gray-800 mb-4">Previous Calculations</h4>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead><tr class="text-left bg-gray-50 text-xs text-gray-500">
              <th class="px-4 py-2 font-medium">Type</th>
              <th class="px-4 py-2 font-medium">Coverage</th>
              <th class="px-4 py-2 font-medium">Premium/mo</th>
              <th class="px-4 py-2 font-medium">Status</th>
              <th class="px-4 py-2 font-medium">Calculated On</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
              <?php foreach ($prevInsurance as $ins): ?>
              <tr>
                <td class="px-4 py-3 font-medium text-gray-800"><?= h($ins['insurance_type']) ?></td>
                <td class="px-4 py-3"><?= inrShort((float)$ins['coverage_amount']) ?></td>
                <td class="px-4 py-3">₹<?= number_format((float)$ins['premium_amount'],0) ?></td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700"><?= h($ins['eligibility_status']) ?></span></td>
                <td class="px-4 py-3 text-gray-400"><?= h($ins['remarks']??'') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
