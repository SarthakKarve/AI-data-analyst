<?php
require __DIR__ . '/includes/auth_check.php';

// ── Template download ─────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'download_template') {
    $tpl = __DIR__ . '/passbook_template.csv';
    if (file_exists($tpl)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="passbook_template.csv"');
        header('Content-Length: ' . filesize($tpl));
        readfile($tpl);
    }
    exit;
}

$GEMINI_API_KEY = getenv('GEMINI_API_KEY') ?: '';

function s($v): string { return trim((string)$v); }

// ── Prediction engines ────────────────────────────────────────────────────────
function heuristicPredict(array $f): array {
    // Weighted factor system (mirrors real CIBIL methodology)
    $maxScore = 900; $minScore = 300; $range = $maxScore - $minScore;

    // 1. Payment History (35%) — proxy: pending/high-priority reminders
    $ph = 1.0;
    $rem = (int)($f['pendingReminders']??0);
    if ($rem >= 5) $ph = 0.30;
    elseif ($rem >= 3) $ph = 0.55;
    elseif ($rem >= 1) $ph = 0.78;

    // 2. Credit Utilization (30%) — loan_amount vs 6x monthly income
    $monthlyIncome = (float)($f['annualIncome']??0) / 12;
    $creditLimit = $monthlyIncome * 6;
    $util = $creditLimit > 0 ? (float)($f['totalLoanAmount']??0) / $creditLimit : 0.0;
    $util = min($util, 1.0);
    $cu = $util <= 0.30 ? 1.0 : ($util <= 0.50 ? 0.75 : ($util <= 0.75 ? 0.50 : 0.25));

    // 3. Credit Age (15%) — months since oldest loan
    $ageMonths = (int)($f['ageMonths']??0);
    $ca = $ageMonths >= 84 ? 1.0 : ($ageMonths >= 36 ? 0.75 : ($ageMonths >= 12 ? 0.55 : 0.30));

    // 4. Loan Mix (10%) — variety of purposes
    $loanMix = min(1.0, (int)($f['numLoans']??0) / 4.0);

    // 5. New Inquiries (10%) — recent loans (proxy: loans created last 6 months)
    $recentLoans = (int)($f['recentLoans']??0);
    $inq = $recentLoans <= 1 ? 1.0 : ($recentLoans <= 3 ? 0.7 : 0.4);

    // Weighted composite score
    $composite = ($ph*0.35) + ($cu*0.30) + ($ca*0.15) + ($loanMix*0.10) + ($inq*0.10);
    $score = (int)round($minScore + $composite * $range);

    // Small penalty for high EMI ratio
    $emiRatio = (float)($f['emiMonthlyRatio']??0);
    if ($emiRatio > 0.40) $score -= 40;
    elseif ($emiRatio > 0.25) $score -= 20;

    $score = max(300, min(900, $score));

    $steps = [];
    $reasons = [];
    if ($ph < 0.9)  { $reasons[]='Pending payments/reminders hurt score'; $steps[]='Clear all pending EMIs and set up autopay reminders.'; }
    if ($cu < 0.9)  { $reasons[]='High credit utilization'; $steps[]='Reduce total debt to below 30% of credit limit.'; }
    if ($ca < 0.75) { $reasons[]='Short credit history'; $steps[]='Maintain existing accounts — credit age builds over time.'; }
    if ($loanMix < 0.5) { $steps[]='Having a healthy mix of loan types (secured + unsecured) improves your score.'; }
    if ($inq < 0.9) { $steps[]='Avoid applying for multiple new loans in a short period.'; }
    if ($emiRatio > 0.40) { $steps[]='Your EMI-to-income ratio is high. Close or prepay a loan to reduce burden.'; }
    if (empty($steps)) $steps[] = 'Maintain timely payments and low credit utilization.';

    $band = $score >= 800 ? 'Excellent' : ($score >= 740 ? 'Very Good' : ($score >= 670 ? 'Good' : ($score >= 580 ? 'Fair' : 'Poor')));
    $explanation = "Score: $score ($band). Key factors: ".(implode('; ', $reasons) ?: 'Strong overall credit profile.');
    return ['score'=>$score,'explanation'=>$explanation,'steps'=>$steps];
}

function geminiPredict(array $f, string $apiKey): ?array {
    $url='https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key='.urlencode($apiKey);
    $prompt='Predict an Indian CIBIL score (300-900) from these features. Return STRICT JSON only with keys: "score" (int 300-900), "explanation" (1-2 sentences), "steps" (array 3-5 concise improvement actions). No text outside JSON. Features: '.json_encode($f);
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['contents'=>[['parts'=>[['text'=>$prompt]]]]]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
    $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if (!$resp||$code<200||$code>=300) return null;
    $text=json_decode($resp,true)['candidates'][0]['content']['parts'][0]['text']??'';
    if (!$text) return null;
    $json=json_decode($text,true);
    if (!is_array($json)||!isset($json['score'])) {
        if (preg_match('/\{[\s\S]*\}/',$text,$m)) $json=json_decode($m[0],true);
    }
    if (!is_array($json)||!isset($json['score'])) return null;
    $json['steps']=isset($json['steps'])&&is_array($json['steps'])?$json['steps']:[];
    return $json;
}

function predictScore(array $f, string $apiKey, string &$modelUsed): array {
    if ($apiKey) {
        $res=geminiPredict($f,$apiKey);
        if ($res&&isset($res['score'])) { $modelUsed='Gemini API'; return $res; }
    }
    $modelUsed='Heuristic';
    return heuristicPredict($f);
}

function saveScoreHistory(PDO $pdo, int $userId, int $score, string $source): void {
    try { $pdo->prepare("INSERT INTO score_history (user_id,score,source) VALUES (?,?,?)")->execute([$userId,$score,$source]); } catch(PDOException $e){}
}

// ── Passbook CSV parser ───────────────────────────────────────────────────────
function parsePassbook(array $rows): array {
    // Expected columns: date, narration, debit, credit, balance
    $salaryCredits   = [];  // [ 'YYYY-MM' => total ]
    $emiMonthlyMap   = [];  // [ 'YYYY-MM' => total emi debits ]
    $bounceCount     = 0;
    $loanNames       = [];  // distinct loan names detected
    $dates           = [];
    $recentEmiStart  = [];  // loan names first seen in last 6 months

    $now = new DateTime('today');
    $sixMonthsAgo = (clone $now)->modify('-6 months');

    foreach ($rows as $row) {
        if (count($row) < 4) continue;
        $rawDate    = trim($row[0] ?? '');
        $narration  = strtolower(trim($row[1] ?? ''));
        $debit      = (float)preg_replace('/[^0-9.]/', '', $row[2] ?? '0');
        $credit     = (float)preg_replace('/[^0-9.]/', '', $row[3] ?? '0');

        // Parse date (DD/MM/YYYY or YYYY-MM-DD)
        $dt = null;
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $rawDate);
            if ($dt) break;
        }
        if (!$dt) continue;
        $dates[]  = $dt;
        $monthKey = $dt->format('Y-m');

        // Salary / income detection
        if ($credit > 0 && preg_match('/salary|sal credit|payroll|ctc|income|stipend/i', $narration)) {
            $salaryCredits[$monthKey] = ($salaryCredits[$monthKey] ?? 0) + $credit;
        }

        // EMI detection
        if ($debit > 0 && preg_match('/emi|loan|installment|instalment|repay/i', $narration)) {
            $emiMonthlyMap[$monthKey] = ($emiMonthlyMap[$monthKey] ?? 0) + $debit;
            // Extract a loan name (first 3-4 meaningful words after 'emi')
            if (preg_match('/(?:emi|loan)[^\w]*[-–]?\s*([\w\s]{3,30})/i', $row[1] ?? '', $m)) {
                $loanKey = strtolower(trim($m[1]));
                $loanNames[$loanKey] = true;
                // Check if first seen within last 6 months
                if ($dt >= $sixMonthsAgo && !isset($recentEmiStart[$loanKey])) {
                    $recentEmiStart[$loanKey] = true;
                }
            }
        }

        // Bounce / returned payment detection
        if (preg_match('/bounce|bounced|return|returned|insufficient|dishonour|dishonour/i', $narration)) {
            $bounceCount++;
        }
    }

    // ── Derive features ───────────────────────────────────────────────────────
    $avgMonthlySalary = count($salaryCredits) > 0 ? array_sum($salaryCredits) / count($salaryCredits) : 0.0;
    $annualIncome     = $avgMonthlySalary * 12;

    $avgMonthlyEmi    = count($emiMonthlyMap) > 0 ? array_sum($emiMonthlyMap) / count($emiMonthlyMap) : 0.0;
    $totalLoanAmount  = $avgMonthlyEmi * 60; // proxy: 5-year outstanding

    $numLoans         = max(count($loanNames), count($emiMonthlyMap) > 0 ? 1 : 0);
    $recentLoans      = count($recentEmiStart);

    // Credit age = months between first and last transaction date
    $ageMonths = 0;
    if (count($dates) >= 2) {
        usort($dates, fn($a,$b) => $a <=> $b);
        $ageMonths = (int)($dates[0]->diff(end($dates))->y * 12 + $dates[0]->diff(end($dates))->m);
        $ageMonths = max(1, $ageMonths);
    }

    $creditUtilization = $annualIncome > 0 ? $totalLoanAmount / $annualIncome : 0.0;
    $emiMonthlyRatio   = $annualIncome > 0 ? $avgMonthlyEmi / max(1, $annualIncome / 12) : 0.0;

    return [
        'activeLoansCount'  => $numLoans,
        'pendingReminders'  => $bounceCount,
        'documentsCount'    => 0,
        'totalLoanAmount'   => $totalLoanAmount,
        'monthlyEmiSum'     => $avgMonthlyEmi,
        'annualIncome'      => $annualIncome,
        'avgInterestRate'   => 10.5, // default; not derivable from passbook
        'numLoans'          => $numLoans,
        'recentLoans'       => $recentLoans,
        'ageMonths'         => $ageMonths,
        'creditUtilization' => $creditUtilization,
        'emiMonthlyRatio'   => $emiMonthlyRatio,
        // summary for UI display
        '_summary' => [
            'monthly_income'  => $avgMonthlySalary,
            'monthly_emi'     => $avgMonthlyEmi,
            'num_loans'       => $numLoans,
            'bounces'         => $bounceCount,
            'age_months'      => $ageMonths,
            'rows_parsed'     => count($rows),
        ],
    ];
}

// ── DB-derived features ───────────────────────────────────────────────────────
$activeLoansCount = (int)fetchValue($pdo,"SELECT COUNT(*) FROM loans WHERE user_id=? AND status='Active'",[$userId]);
$totalLoanAmount  = (float)fetchValue($pdo,"SELECT COALESCE(SUM(loan_amount),0) FROM loans WHERE user_id=?",[$userId]);
$monthlyEmiSum    = (float)fetchValue($pdo,"SELECT COALESCE(SUM(emi_amount),0) FROM loans WHERE user_id=? AND status='Active' AND installment_option='Monthly'",[$userId]);
$annualIncome     = (float)fetchValue($pdo,"SELECT COALESCE(annual_income,0) FROM users WHERE user_id=?",[$userId]);
$pendingReminders = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status IN ('Upcoming','High Priority')",[$userId]);
$avgInterestRate  = (float)fetchValue($pdo,"SELECT COALESCE(AVG(interest_rate),0) FROM loans WHERE user_id=?",[$userId]);
$documentsCount   = (int)fetchValue($pdo,"SELECT COUNT(*) FROM documents WHERE user_id=?",[$userId]);
$numLoans         = (int)fetchValue($pdo,"SELECT COUNT(*) FROM loans WHERE user_id=?",[$userId]);
$recentLoans      = (int)fetchValue($pdo,"SELECT COUNT(*) FROM loans WHERE user_id=? AND start_date >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH)",[$userId]);
$oldestStart      = fetchValue($pdo,"SELECT MIN(start_date) FROM loans WHERE user_id=? AND start_date IS NOT NULL",[$userId]);
$ageMonths = 0;
if ($oldestStart) { $d1=new DateTime($oldestStart); $d2=new DateTime('today'); $ageMonths=(int)($d1->diff($d2)->y*12+$d1->diff($d2)->m); }
$creditUtilization = $annualIncome > 0 ? $totalLoanAmount/$annualIncome : 0.0;
$emiMonthlyRatio   = $annualIncome > 0 ? ($monthlyEmiSum/max(1,$annualIncome/12)) : 0.0;

$dbFeatures = ['activeLoansCount'=>$activeLoansCount,'pendingReminders'=>$pendingReminders,'documentsCount'=>$documentsCount,'totalLoanAmount'=>$totalLoanAmount,'monthlyEmiSum'=>$monthlyEmiSum,'annualIncome'=>$annualIncome,'avgInterestRate'=>$avgInterestRate,'numLoans'=>$numLoans,'recentLoans'=>$recentLoans,'ageMonths'=>$ageMonths,'creditUtilization'=>$creditUtilization,'emiMonthlyRatio'=>$emiMonthlyRatio];

// ── Allow 'Passbook' as prediction_source (idempotent migration) ──────────────
try {
    $pdo->exec("ALTER TABLE cibil_scores MODIFY COLUMN prediction_source ENUM('Manual','Database','Passbook') NOT NULL DEFAULT 'Manual'");
} catch (PDOException $e) { /* already altered or column differs — safe to ignore */ }

$csrf   = csrfToken();
$errors = []; $success = '';
$predScore=null; $aiExplanation=''; $aiSteps=[]; $modelUsed='';
$passbookSummary = null;

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrfVerify($_POST['csrf']??'')) { $errors[]='Invalid CSRF token.'; }
    else {
        $act=$_POST['action']??'';
        if ($act==='predict_db') {
            $res=predictScore($dbFeatures,$GEMINI_API_KEY,$modelUsed);
            $predScore=(int)$res['score']; $aiExplanation=$res['explanation']; $aiSteps=$res['steps'];
            if (($_POST['save']??'0')==='1') {
                $rec=$aiExplanation."\n".implode("\n- ",array_merge(['Steps:'],$aiSteps));
                $pdo->prepare("INSERT INTO cibil_scores (user_id,cibil_score,score_type,prediction_source,ai_model_used,recommendation) VALUES (?,?,'Predicted','Database',?,?)")
                    ->execute([$userId,$predScore,$modelUsed,$rec]);
                $scoreId=(int)$pdo->lastInsertId();
                foreach ($aiSteps as $step) {
                    try { $pdo->prepare("INSERT INTO recommendations (user_id,score_id,recommendation_text) VALUES (?,?,?)")->execute([$userId,$scoreId,$step]); } catch(PDOException $e){}
                }
                saveScoreHistory($pdo,$userId,$predScore,$modelUsed);
                logActivity($pdo,$userId,'score_predicted',"Predicted score: $predScore via $modelUsed");
                $success="Score $predScore saved to dashboard.";
            }
        } elseif ($act==='predict_passbook') {
            $file = $_FILES['passbook_file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Please select a CSV file to upload.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'File exceeds 2 MB limit.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($file['tmp_name']);
                $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['csv']) || !in_array($mime, ['text/plain','text/csv','application/csv','application/vnd.ms-excel'])) {
                    $errors[] = 'Only CSV files are accepted.';
                } else {
                    $rows = [];
                    if (($fh = fopen($file['tmp_name'], 'r')) !== false) {
                        $header = null;
                        while (($line = fgetcsv($fh)) !== false) {
                            if (!$header) { $header = $line; continue; } // skip header row
                            $rows[] = $line;
                        }
                        fclose($fh);
                    }
                    if (count($rows) < 2) {
                        $errors[] = 'CSV must have at least 2 data rows (excluding the header).';
                    } else {
                        $pbFeatures      = parsePassbook($rows);
                        $passbookSummary = $pbFeatures['_summary'];
                        unset($pbFeatures['_summary']);
                        $res = predictScore($pbFeatures, $GEMINI_API_KEY, $modelUsed);
                        $predScore = (int)$res['score']; $aiExplanation = $res['explanation']; $aiSteps = $res['steps'];
                        if (($_POST['save'] ?? '0') === '1') {
                            $rec = $aiExplanation."\n".implode("\n- ", array_merge(['Steps:'], $aiSteps));
                            $pdo->prepare("INSERT INTO cibil_scores (user_id,cibil_score,score_type,prediction_source,ai_model_used,recommendation) VALUES (?,?,'Predicted','Passbook',?,?)")
                                ->execute([$userId, $predScore, $modelUsed, $rec]);
                            $scoreId = (int)$pdo->lastInsertId();
                            foreach ($aiSteps as $step) {
                                try { $pdo->prepare("INSERT INTO recommendations (user_id,score_id,recommendation_text) VALUES (?,?,?)")->execute([$userId,$scoreId,$step]); } catch(PDOException $e){}
                            }
                            saveScoreHistory($pdo, $userId, $predScore, $modelUsed);
                            logActivity($pdo, $userId, 'score_predicted', "Passbook prediction: $predScore via $modelUsed");
                            $success = "Passbook prediction $predScore saved to dashboard.";
                        }
                    }
                }
            }
        } elseif ($act==='predict_manual') {
            $manIncome   = (float)($_POST['annual_income']   ?? 0);
            $manDebt     = (float)($_POST['total_debt']      ?? 0);
            $manEmi      = (float)($_POST['monthly_emi']     ?? 0);
            $manLoans    = (int)($_POST['num_loans']         ?? 0);
            $manRem      = (int)($_POST['pending_reminders'] ?? 0);
            $manAge      = (int)($_POST['credit_age_months'] ?? 0);
            $manRate     = (float)($_POST['avg_interest']    ?? 0);
            $manFeatures = ['activeLoansCount'=>$manLoans,'pendingReminders'=>$manRem,'documentsCount'=>0,'totalLoanAmount'=>$manDebt,'monthlyEmiSum'=>$manEmi,'annualIncome'=>$manIncome,'avgInterestRate'=>$manRate,'numLoans'=>$manLoans,'recentLoans'=>0,'ageMonths'=>$manAge,'creditUtilization'=>$manIncome>0?$manDebt/$manIncome:0,'emiMonthlyRatio'=>$manIncome>0?$manEmi/max(1,$manIncome/12):0];
            $res=predictScore($manFeatures,$GEMINI_API_KEY,$modelUsed);
            $predScore=(int)$res['score']; $aiExplanation=$res['explanation']; $aiSteps=$res['steps'];
            if (($_POST['save']??'0')==='1') {
                $rec=$aiExplanation."\n".implode("\n- ",array_merge(['Steps:'],$aiSteps));
                $pdo->prepare("INSERT INTO cibil_scores (user_id,cibil_score,score_type,prediction_source,ai_model_used,recommendation) VALUES (?,?,'Predicted','Manual',?,?)")
                    ->execute([$userId,$predScore,$modelUsed,$rec]);
                $scoreId=(int)$pdo->lastInsertId();
                foreach ($aiSteps as $step) {
                    try { $pdo->prepare("INSERT INTO recommendations (user_id,score_id,recommendation_text) VALUES (?,?,?)")->execute([$userId,$scoreId,$step]); } catch(PDOException $e){}
                }
                saveScoreHistory($pdo,$userId,$predScore,$modelUsed);
                logActivity($pdo,$userId,'score_predicted',"Manual prediction: $predScore via $modelUsed");
                $success="Manual prediction $predScore saved.";
            }
        }
    }
}

// ── Page data ─────────────────────────────────────────────────────────────────
$latestScore = fetchOne($pdo,"SELECT cibil_score,score_type,prediction_date,recommendation FROM cibil_scores WHERE user_id=? ORDER BY prediction_date DESC LIMIT 1",[$userId]);
$scoreHistory = fetchAll($pdo,"SELECT score,recorded_at FROM score_history WHERE user_id=? ORDER BY recorded_at ASC LIMIT 12",[$userId]);
$recentRecs   = fetchAll($pdo,"SELECT recommendation_text FROM recommendations WHERE user_id=? ORDER BY created_at DESC LIMIT 5",[$userId]);

$chartLabels = json_encode(array_map(fn($r)=>date('d M',strtotime($r['recorded_at'])),$scoreHistory));
$chartData   = json_encode(array_map(fn($r)=>(int)$r['score'],$scoreHistory));

$displayScore = $predScore ?? ($latestScore ? (int)$latestScore['cibil_score'] : null);
$band = $displayScore ? scoreBand($displayScore) : null;

$userRow   = fetchOne($pdo,"SELECT first_name FROM users WHERE user_id=?",[$userId]);
$firstName = $userRow['first_name'] ?? 'User';
$highPriorityReminders = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status='High Priority'",[$userId]);

$pageTitle  = 'CIBIL Predictor';
$activePage = 'predictor';
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

      <!-- Score Display + Gauge -->
      <?php if ($predScore !== null || $latestScore): ?>
      <div class="bg-gradient-to-br from-primary to-purple-700 text-white rounded-2xl p-8">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-center">
          <!-- Gauge -->
          <div class="flex flex-col items-center">
            <div class="relative w-44 h-44">
              <canvas id="scoreGaugeResult"></canvas>
              <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-4xl font-bold"><?= h($displayScore) ?></span>
                <?php if ($band): ?>
                <span class="text-sm font-medium mt-1 text-purple-200"><?= h($band['label']) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <p class="text-purple-200 text-xs mt-2">Score Range: 300 – 900</p>
          </div>
          <!-- Explanation -->
          <div class="md:col-span-2">
            <div class="flex items-center gap-2 mb-3">
              <span class="px-3 py-1 rounded-full text-xs font-bold bg-white/20 text-white"><?= h($modelUsed ?: ($latestScore['ai_model_used']??'')) ?></span>
              <?php if ($band): ?>
              <span class="px-3 py-1 rounded-full text-xs font-bold bg-white/20"><?= h($band['label']) ?></span>
              <?php endif; ?>
            </div>
            <p class="text-purple-100 text-sm leading-relaxed">
              <?= h($predScore ? $aiExplanation : ($latestScore['recommendation']??'')) ?>
            </p>
            <!-- Score bands legend -->
            <div class="flex flex-wrap gap-2 mt-4">
              <?php foreach([['300-579','Poor','bg-red-400'],['580-669','Fair','bg-orange-400'],['670-739','Good','bg-yellow-400'],['740-799','Very Good','bg-green-400'],['800-900','Excellent','bg-blue-400']] as [$r,$l,$c]): ?>
              <span class="<?= $c ?>/80 text-white text-xs px-2 py-1 rounded-lg font-medium"><?= $r ?> <span class="opacity-75"><?= $l ?></span></span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Prediction Forms -->
      <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
        <!-- DB-based Prediction -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
          <h3 class="font-semibold text-gray-800 mb-1 flex items-center gap-2"><i class="ri-database-2-line text-primary"></i>Predict from Your Data</h3>
          <p class="text-sm text-gray-400 mb-5">Uses your actual loans, income, and reminders from the system.</p>
          <!-- Feature Summary -->
          <div class="bg-gray-50 rounded-xl p-4 mb-5 grid grid-cols-2 gap-3 text-xs">
            <?php $featureItems=[['Active Loans',$activeLoansCount],['Total Debt','₹'.number_format($totalLoanAmount,0)],['Monthly EMI','₹'.number_format($monthlyEmiSum,0)],['Pending Reminders',$pendingReminders],['Credit Age',$ageMonths.' months'],['Avg Rate',$avgInterestRate.'%']]; ?>
            <?php foreach($featureItems as [$fl,$fv]): ?>
            <div><span class="text-gray-400"><?= $fl ?>:</span> <span class="font-semibold text-gray-700"><?= $fv ?></span></div>
            <?php endforeach; ?>
          </div>
          <form method="post" class="space-y-3">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="predict_db">
            <div class="flex gap-3">
              <button name="save" value="0" class="flex-1 py-2.5 rounded-xl border border-primary text-primary font-medium text-sm hover:bg-primary/5 transition-colors">
                <i class="ri-refresh-line mr-1"></i>Preview Only
              </button>
              <button name="save" value="1" class="flex-1 py-2.5 rounded-xl bg-primary text-white font-medium text-sm hover:bg-purple-700 transition-colors">
                <i class="ri-save-line mr-1"></i>Predict &amp; Save
              </button>
            </div>
          </form>
        </div>

        <!-- Manual Prediction -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
          <h3 class="font-semibold text-gray-800 mb-1 flex items-center gap-2"><i class="ri-edit-line text-primary"></i>Manual Prediction</h3>
          <p class="text-sm text-gray-400 mb-4">Enter values manually to simulate different scenarios.</p>
          <form method="post" class="space-y-3">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="predict_manual">
            <div class="grid grid-cols-2 gap-3">
              <div><label class="text-xs text-gray-500 mb-1 block">Annual Income (₹)</label>
                <input type="number" name="annual_income" value="<?= h($annualIncome) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Total Debt (₹)</label>
                <input type="number" name="total_debt" value="<?= h($totalLoanAmount) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Monthly EMI (₹)</label>
                <input type="number" name="monthly_emi" value="<?= h($monthlyEmiSum) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">No. of Loans</label>
                <input type="number" name="num_loans" value="<?= h($numLoans) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Pending Reminders</label>
                <input type="number" name="pending_reminders" value="<?= h($pendingReminders) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Credit Age (months)</label>
                <input type="number" name="credit_age_months" value="<?= h($ageMonths) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
            </div>
            <div class="flex gap-3">
              <button name="save" value="0" class="flex-1 py-2.5 rounded-xl border border-primary text-primary font-medium text-sm hover:bg-primary/5 transition-colors">Preview Only</button>
              <button name="save" value="1" class="flex-1 py-2.5 rounded-xl bg-primary text-white font-medium text-sm hover:bg-purple-700 transition-colors">Predict &amp; Save</button>
            </div>
          </form>
        </div>

        <!-- Passbook Upload Prediction -->
        <div class="bg-white rounded-xl shadow-sm border p-6 flex flex-col">
          <div class="flex items-start justify-between mb-1">
            <h3 class="font-semibold text-gray-800 flex items-center gap-2"><i class="ri-bank-card-line text-emerald-600"></i>Upload Passbook / Statement</h3>
            <a href="?action=download_template" class="flex items-center gap-1 text-xs text-emerald-600 font-medium border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 transition-colors px-2.5 py-1.5 rounded-lg whitespace-nowrap" title="Download template CSV, fill it, then upload here">
              <i class="ri-download-2-line"></i>Template
            </a>
          </div>
          <p class="text-sm text-gray-400 mb-4">Download the template, fill in your bank transactions, and upload to auto-detect income &amp; EMIs.</p>

          <?php if ($passbookSummary): ?>
          <!-- Parsed summary -->
          <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 mb-4 grid grid-cols-2 gap-2 text-xs">
            <div><span class="text-gray-400">Monthly Income:</span> <span class="font-semibold text-gray-700">₹<?= number_format((float)$passbookSummary['monthly_income'], 0) ?></span></div>
            <div><span class="text-gray-400">Monthly EMI:</span> <span class="font-semibold text-gray-700">₹<?= number_format((float)$passbookSummary['monthly_emi'], 0) ?></span></div>
            <div><span class="text-gray-400">Loans Detected:</span> <span class="font-semibold text-gray-700"><?= (int)$passbookSummary['num_loans'] ?></span></div>
            <div><span class="text-gray-400">Bounced Payments:</span> <span class="font-semibold <?= (int)$passbookSummary['bounces'] > 0 ? 'text-red-600' : 'text-gray-700' ?>"><?= (int)$passbookSummary['bounces'] ?></span></div>
            <div><span class="text-gray-400">Statement Period:</span> <span class="font-semibold text-gray-700"><?= (int)$passbookSummary['age_months'] ?> months</span></div>
            <div><span class="text-gray-400">Rows Parsed:</span> <span class="font-semibold text-gray-700"><?= (int)$passbookSummary['rows_parsed'] ?></span></div>
          </div>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data" class="space-y-3 flex-1 flex flex-col justify-between" id="passbookForm">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="predict_passbook">
            <div>
              <!-- Drop zone -->
              <div id="pbDropZone" class="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center cursor-pointer hover:border-emerald-400 hover:bg-emerald-50/40 transition-all"
                   onclick="document.getElementById('passbookFile').click()">
                <i class="ri-file-upload-line text-3xl text-gray-300 mb-2 block"></i>
                <p class="text-sm text-gray-500" id="pbFileName">Click or drag &amp; drop your CSV file here</p>
                <p class="text-xs text-gray-400 mt-1">Accepted: CSV only &middot; Max 2 MB</p>
              </div>
              <input type="file" id="passbookFile" name="passbook_file" accept=".csv" class="hidden">
            </div>
            <div class="flex gap-3">
              <button name="save" value="0" class="flex-1 py-2.5 rounded-xl border border-emerald-500 text-emerald-600 font-medium text-sm hover:bg-emerald-50 transition-colors">
                <i class="ri-eye-line mr-1"></i>Preview Only
              </button>
              <button name="save" value="1" class="flex-1 py-2.5 rounded-xl bg-emerald-600 text-white font-medium text-sm hover:bg-emerald-700 transition-colors">
                <i class="ri-save-line mr-1"></i>Predict &amp; Save
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- How to Improve + Score History -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Improvement Steps -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
          <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="ri-arrow-up-circle-line text-green-500"></i>How to Improve Your Score</h3>
          <?php
          $displaySteps = !empty($aiSteps) ? $aiSteps : array_map(fn($r)=>$r['recommendation_text'],$recentRecs);
          if (empty($displaySteps)) $displaySteps = ['Pay all EMIs on time — payment history is the #1 factor.','Keep credit utilization below 30% of your limit.','Avoid multiple loan applications in a short period.','Maintain older accounts to build credit history.','Diversify between secured (home/auto) and unsecured loans.'];
          ?>
          <ol class="space-y-3">
            <?php foreach(array_slice($displaySteps,0,6) as $i=>$step): ?>
            <li class="flex items-start gap-3">
              <span class="w-6 h-6 rounded-full bg-primary/10 text-primary text-xs font-bold flex items-center justify-center flex-shrink-0 mt-0.5"><?= $i+1 ?></span>
              <p class="text-sm text-gray-600"><?= h($step) ?></p>
            </li>
            <?php endforeach; ?>
          </ol>
          <a href="insights.php" class="mt-4 flex items-center gap-1 text-sm text-primary font-medium hover:underline"><i class="ri-lightbulb-line"></i>See all personalized insights →</a>
        </div>

        <!-- Score History Chart -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
          <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="ri-line-chart-line text-primary"></i>Score History</h3>
          <?php if (count($scoreHistory) >= 2): ?>
          <canvas id="scoreHistoryChart" height="200"></canvas>
          <?php elseif (count($scoreHistory)===1): ?>
          <div class="flex items-center gap-4 py-6">
            <div class="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center">
              <span class="text-primary font-bold text-xl"><?= $scoreHistory[0]['score'] ?></span>
            </div>
            <div>
              <p class="font-medium text-gray-700">First prediction recorded</p>
              <p class="text-sm text-gray-400 mt-0.5"><?= date('d M Y',strtotime($scoreHistory[0]['recorded_at'])) ?></p>
              <p class="text-sm text-gray-400 mt-1">Predict again to build your trend chart.</p>
            </div>
          </div>
          <?php else: ?>
          <div class="flex flex-col items-center justify-center py-10 text-center">
            <i class="ri-line-chart-line text-4xl text-gray-300 mb-3"></i>
            <p class="text-gray-500 text-sm">No history yet — save a prediction to start tracking.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Factor Breakdown -->
      <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold text-gray-800 mb-4">CIBIL Score Factor Breakdown</h3>
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
          <?php
          $factors = [
            ['Payment History','35%','ri-time-line','Timely payment of EMIs and bills.','bg-blue-500',35],
            ['Credit Utilization','30%','ri-percent-line','Total debt vs credit limit.','bg-purple-500',30],
            ['Credit Age','15%','ri-calendar-line','Length of credit history.','bg-green-500',15],
            ['Credit Mix','10%','ri-shuffle-line','Variety of loan types.','bg-orange-500',10],
            ['New Inquiries','10%','ri-search-line','Recent loan applications.','bg-red-500',10],
          ];
          foreach ($factors as [$name,$weight,$icon,$desc,$color,$pct]):
          ?>
          <div class="text-center p-4 bg-gray-50 rounded-xl">
            <div class="w-10 h-10 bg-white rounded-xl shadow-sm flex items-center justify-center mx-auto mb-2"><i class="<?= $icon ?> text-gray-600"></i></div>
            <p class="font-bold text-gray-800 text-lg"><?= $weight ?></p>
            <p class="text-xs font-semibold text-gray-700 mt-1"><?= $name ?></p>
            <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2 mb-2"><div class="<?= $color ?> h-1.5 rounded-full" style="width:<?= $pct ?>%"></div></div>
            <p class="text-xs text-gray-400"><?= $desc ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function(){
  const score=<?= $displayScore !== null ? (int)$displayScore : 'null' ?>;
  const ctx=document.getElementById('scoreGaugeResult');
  if(!ctx||score===null) return;
  const pct=(score-300)/600;
  new Chart(ctx,{type:'doughnut',data:{datasets:[{data:[pct,1-pct],backgroundColor:[score>=800?'#3b82f6':score>=740?'#22c55e':score>=670?'#eab308':score>=580?'#f97316':'#ef4444','rgba(255,255,255,0.2)'],borderWidth:0,circumference:270,rotation:225}]},options:{cutout:'78%',plugins:{legend:{display:false},tooltip:{enabled:false}},animation:{duration:1200}}});
})();
(function(){
  const ctx=document.getElementById('scoreHistoryChart');
  if(!ctx) return;
  const labels=<?= $chartLabels ?>;
  const data=<?= $chartData ?>;
  if(data.length<2) return;
  new Chart(ctx,{type:'line',data:{labels,datasets:[{label:'CIBIL Score',data,borderColor:'#7c3aed',backgroundColor:'rgba(124,58,237,0.08)',borderWidth:2.5,pointBackgroundColor:'#7c3aed',pointRadius:5,tension:0.3,fill:true}]},options:{responsive:true,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'Score: '+c.raw}}},scales:{y:{min:300,max:900,grid:{color:'#f3f4f6'},ticks:{font:{size:10}}},x:{grid:{display:false},ticks:{font:{size:10}}}}}});
})();
// ── Passbook drag-and-drop ────────────────────────────────────────────────────
(function(){
  const zone = document.getElementById('pbDropZone');
  const input = document.getElementById('passbookFile');
  const label = document.getElementById('pbFileName');
  if (!zone || !input) return;

  function setFile(file) {
    if (!file) return;
    if (!file.name.match(/\.csv$/i)) {
      label.textContent = 'Only CSV files are accepted.';
      label.className = 'text-sm text-red-500';
      input.value = '';
      return;
    }
    label.textContent = file.name + ' (' + (file.size > 1024 ? Math.round(file.size/1024) + ' KB' : file.size + ' B') + ')';
    label.className = 'text-sm text-emerald-600 font-medium';
    zone.classList.replace('border-gray-200','border-emerald-400');
    zone.classList.add('bg-emerald-50/40');
    // Transfer to real input via DataTransfer
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
  }

  input.addEventListener('change', function() { setFile(this.files[0]); });

  zone.addEventListener('dragover', function(e) {
    e.preventDefault();
    zone.classList.replace('border-gray-200','border-emerald-400');
  });
  zone.addEventListener('dragleave', function() {
    if (!input.files.length) zone.classList.replace('border-emerald-400','border-gray-200');
  });
  zone.addEventListener('drop', function(e) {
    e.preventDefault();
    if (e.dataTransfer.files.length) setFile(e.dataTransfer.files[0]);
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
