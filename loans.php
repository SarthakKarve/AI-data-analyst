<?php
require __DIR__ . '/includes/auth_check.php';

// ── Helpers ──────────────────────────────────────────────────────────────────
function s($v): string { return trim((string)$v); }
function validInstallment($x): string { return in_array($x,['Monthly','Quarterly','Yearly'],true)?$x:'Monthly'; }
function computeEmi(float $P, float $rate, int $months): float {
    $r = ($rate/100)/12;
    if ($r <= 0 || $months <= 0) return $months > 0 ? round($P/$months,2) : 0;
    $pw = pow(1+$r,$months);
    return round($P*$r*$pw/($pw-1),2);
}
function addMonthsFrom(string $start, int $months): string {
    $d = new DateTime($start ?: date('Y-m-d'));
    $d->modify('+'.$months.' months');
    return $d->format('Y-m-d');
}
function nextDueDate(string $start, string $ins): string {
    $m = $ins==='Quarterly'?3:($ins==='Yearly'?12:1);
    return addMonthsFrom($start,$m);
}
function scheduleNextReminder(PDO $pdo, int $userId, int $loanId, string $start, string $ins, float $emi): void {
    $due = nextDueDate($start,$ins);
    $pdo->prepare("DELETE FROM reminders WHERE user_id=? AND loan_id=? AND status='Upcoming' AND title LIKE 'Loan EMI due%'")->execute([$userId,$loanId]);
    $pdo->prepare("INSERT INTO reminders (user_id,loan_id,title,description,due_date,status,notify_email,notify_sms) VALUES (?,?,?,?,?,'Upcoming',0,0)")
        ->execute([$userId,$loanId,'Loan EMI due','Upcoming installment for Loan #'.$loanId.' — ₹'.number_format($emi,2),$due]);
}

$csrf   = csrfToken();
$errors = []; $success = '';

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if (!csrfVerify($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        if ($act === 'create') {
            $purpose    = s($_POST['purpose'] ?? $_POST['loan_type'] ?? '');
            $amount     = (float)($_POST['loan_amount']    ?? 0);
            $rate       = (float)($_POST['interest_rate']  ?? 0);
            $start      = s($_POST['start_date']  ?? date('Y-m-d'));
            $inst       = validInstallment($_POST['installment_option'] ?? 'Monthly');
            $months     = max(1,(int)($_POST['repayment_period'] ?? $_POST['duration_months'] ?? 12));
            $status     = s($_POST['status'] ?? 'Active');
            if (!in_array($status,['Active','Approved','Pending','Closed'],true)) $status='Active';
            if ($amount <= 0) $errors[] = 'Loan amount must be greater than 0.';
            if (empty($errors)) {
                $emi    = computeEmi($amount,$rate,$months);
                $end    = addMonthsFrom($start,$months);
                $st = $pdo->prepare("INSERT INTO loans (user_id,purpose,loan_amount,interest_rate,start_date,end_date,installment_option,repayment_period,emi_amount,status) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$userId,$purpose,$amount,$rate,$start,$end,$inst,$months,$emi,$status]);
                $loanId = (int)$pdo->lastInsertId();
                if ($status==='Active') scheduleNextReminder($pdo,$userId,$loanId,$start,$inst,$emi);
                logActivity($pdo,$userId,'loan_added',"Added loan: ".($purpose?:"Loan")." ₹".number_format($amount,2));
                $success = 'Loan added successfully.';
            }
        } elseif ($act === 'update') {
            $loanId     = (int)($_POST['loan_id'] ?? 0);
            $purpose    = s($_POST['purpose'] ?? $_POST['loan_type'] ?? '');
            $amount     = (float)($_POST['loan_amount']    ?? 0);
            $rate       = (float)($_POST['interest_rate']  ?? 0);
            $start      = s($_POST['start_date']  ?? date('Y-m-d'));
            $inst       = validInstallment($_POST['installment_option'] ?? 'Monthly');
            $months     = max(1,(int)($_POST['repayment_period'] ?? $_POST['duration_months'] ?? 12));
            $status     = s($_POST['status'] ?? 'Active');
            if (!in_array($status,['Active','Approved','Pending','Closed'],true)) $status='Active';
            if ($loanId < 1) $errors[] = 'Invalid loan ID.';
            if (empty($errors)) {
                $emi = computeEmi($amount,$rate,$months);
                $end = addMonthsFrom($start,$months);
                $pdo->prepare("UPDATE loans SET purpose=?,loan_amount=?,interest_rate=?,start_date=?,end_date=?,installment_option=?,repayment_period=?,emi_amount=?,status=? WHERE loan_id=? AND user_id=?")
                    ->execute([$purpose,$amount,$rate,$start,$end,$inst,$months,$emi,$status,$loanId,$userId]);
                if ($status==='Active') scheduleNextReminder($pdo,$userId,$loanId,$start,$inst,$emi);
                logActivity($pdo,$userId,'loan_updated',"Updated loan #$loanId");
                $success = 'Loan updated successfully.';
            }
        } elseif ($act === 'delete') {
            $loanId = (int)($_POST['loan_id'] ?? 0);
            if ($loanId < 1) { $errors[] = 'Invalid loan ID.'; }
            else {
                $pdo->prepare("DELETE FROM loans WHERE loan_id=? AND user_id=?")->execute([$loanId,$userId]);
                logActivity($pdo,$userId,'loan_deleted',"Deleted loan #$loanId");
                $success = 'Loan deleted.';
            }
        } elseif ($act === 'set_reminder') {
            $loanId  = (int)($_POST['loan_id'] ?? 0);
            $start   = s($_POST['start_date'] ?? date('Y-m-d'));
            $inst    = validInstallment($_POST['installment_option'] ?? 'Monthly');
            $emi     = (float)($_POST['emi_amount'] ?? 0);
            if ($loanId > 0) { scheduleNextReminder($pdo,$userId,$loanId,$start,$inst,$emi); $success='Reminder scheduled.'; }
        }
    }
}

// ── CSV export ────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'all';
$q    = trim($_GET['q']    ?? '');
$from = s($_GET['from'] ?? '');
$to   = s($_GET['to']   ?? '');
$where  = "user_id=?"; $params = [$userId];
if (in_array($filterStatus,['Active','Approved','Closed','Pending'],true)) { $where.=" AND status=?"; $params[]=$filterStatus; }
if ($q!=='') { $where.=" AND (purpose LIKE ? OR CAST(loan_id AS CHAR) LIKE ?)"; $params[]='%'.$q.'%'; $params[]='%'.$q.'%'; }
if ($from!=='') { $where.=" AND start_date >= ?"; $params[]=$from; }
if ($to!=='')   { $where.=" AND start_date <= ?"; $params[]=$to; }

if (($_GET['export'] ?? '') === 'csv') {
    $rows = fetchAll($pdo, "SELECT loan_id,purpose,loan_amount,interest_rate,emi_amount,start_date,end_date,installment_option,status FROM loans WHERE $where ORDER BY loan_id DESC", $params);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="loans_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,['Loan ID','Purpose','Amount (₹)','Rate (%)','EMI (₹)','Start Date','End Date','Installment','Status']);
    foreach ($rows as $r) fputcsv($out,[$r['loan_id'],$r['purpose'],$r['loan_amount'],$r['interest_rate'],$r['emi_amount'],$r['start_date'],$r['end_date'],$r['installment_option'],$r['status']]);
    fclose($out); exit;
}

// ── Page data ─────────────────────────────────────────────────────────────────
$loans = fetchAll($pdo, "SELECT loan_id,purpose,loan_amount,interest_rate,emi_amount,start_date,end_date,installment_option,repayment_period,status FROM loans WHERE $where ORDER BY loan_id DESC LIMIT 100", $params);

$totalLoans  = (int)fetchValue($pdo, "SELECT COUNT(*) FROM loans WHERE user_id=?", [$userId]);
$activeLoans = (int)fetchValue($pdo, "SELECT COUNT(*) FROM loans WHERE user_id=? AND status='Active'", [$userId]);
$totalAmount = (float)fetchValue($pdo, "SELECT COALESCE(SUM(loan_amount),0) FROM loans WHERE user_id=?", [$userId]);
$monthlyEmi  = (float)fetchValue($pdo, "SELECT COALESCE(SUM(emi_amount),0) FROM loans WHERE user_id=? AND status='Active'", [$userId]);
$annualIncome = (float)(fetchValue($pdo,"SELECT annual_income FROM users WHERE user_id=?",[$userId]) ?? 0);
$avgRate     = (float)(fetchValue($pdo,"SELECT AVG(interest_rate) FROM loans WHERE user_id=? AND status='Active'",[$userId]) ?? 0);

$upcoming = [];
foreach ($loans as $L) {
    if ($L['status']==='Active') {
        $upcoming[] = ['loan_id'=>$L['loan_id'],'title'=>$L['purpose']?:'Loan','due'=>nextDueDate($L['start_date'],$L['installment_option']),'emi'=>$L['emi_amount']];
    }
}
usort($upcoming, fn($a,$b)=>strcmp($a['due'],$b['due']));
$upcoming = array_slice($upcoming,0,3);

$monthlyIncome = $annualIncome > 0 ? $annualIncome/12 : 0;
$emiRatioPct   = $monthlyIncome > 0 ? round(($monthlyEmi/$monthlyIncome)*100) : 0;

$userRow   = fetchOne($pdo,"SELECT first_name FROM users WHERE user_id=?",[$userId]);
$firstName = $userRow['first_name'] ?? 'User';
$highPriorityReminders = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status='High Priority'",[$userId]);

$pageTitle  = 'Loans';
$activePage = 'loans';
?>
<?php require __DIR__ . '/includes/page_head.php'; ?>
<div class="flex">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <div class="ml-64 flex-1 min-h-screen">
    <?php require __DIR__ . '/includes/header.php'; ?>
    <div class="p-8 space-y-6 fade-in">

      <!-- Alerts -->
      <?php if (!empty($errors)): ?>
      <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm">
        <?php foreach($errors as $e) echo '<div>'.h($e).'</div>'; ?>
      </div>
      <?php endif; ?>
      <?php if ($success): ?>
      <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl text-sm"><?= h($success) ?></div>
      <?php endif; ?>

      <!-- KPI Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-file-list-3-line text-blue-600 text-lg"></i></div>
          <div class="text-2xl font-bold text-gray-800"><?= h($totalLoans) ?></div>
          <div class="text-sm text-gray-500">Total Loans</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-bank-line text-green-600 text-lg"></i></div>
          <div class="text-2xl font-bold text-gray-800"><?= h($activeLoans) ?></div>
          <div class="text-sm text-gray-500">Active Loans</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-money-dollar-circle-line text-purple-600 text-lg"></i></div>
          <div class="text-2xl font-bold text-gray-800"><?= inrShort($totalAmount) ?></div>
          <div class="text-sm text-gray-500">Total Outstanding</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 <?= $emiRatioPct > 40 ? 'bg-red-100' : 'bg-orange-100' ?> rounded-lg flex items-center justify-center mb-3">
            <i class="ri-calendar-check-line <?= $emiRatioPct > 40 ? 'text-red-600' : 'text-orange-600' ?> text-lg"></i>
          </div>
          <div class="text-2xl font-bold text-gray-800"><?= inrShort($monthlyEmi) ?></div>
          <div class="text-sm text-gray-500">Monthly EMI</div>
          <?php if ($monthlyIncome > 0): ?>
          <div class="mt-2 w-full bg-gray-100 rounded-full h-1.5">
            <div class="<?= $emiRatioPct>40?'bg-red-500':($emiRatioPct>30?'bg-orange-500':'bg-green-500') ?> h-1.5 rounded-full" style="width:<?= min($emiRatioPct,100) ?>%"></div>
          </div>
          <div class="text-xs <?= $emiRatioPct>40?'text-red-500':($emiRatioPct>30?'text-orange-500':'text-gray-400') ?> mt-1"><?= $emiRatioPct ?>% of income<?= $emiRatioPct>40?' — High!':'' ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Loan Insights Bar -->
      <?php if ($activeLoans > 0): ?>
      <div class="bg-gradient-to-r from-primary/5 to-purple-50 border border-primary/20 rounded-xl p-5">
        <h3 class="font-semibold text-gray-800 mb-3 flex items-center gap-2"><i class="ri-bar-chart-2-line text-primary"></i>Loan Insights</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div><span class="text-gray-500">Active Debt</span><div class="font-bold text-gray-800 mt-0.5"><?= inr($totalAmount) ?></div></div>
          <div><span class="text-gray-500">Avg. Interest Rate</span><div class="font-bold text-gray-800 mt-0.5"><?= round($avgRate,2) ?>%</div></div>
          <div><span class="text-gray-500">EMI Burden</span><div class="font-bold <?= $emiRatioPct>40?'text-red-600':'text-gray-800' ?> mt-0.5"><?= $emiRatioPct ?>% of income</div></div>
          <div><span class="text-gray-500">Next Payment</span><div class="font-bold text-gray-800 mt-0.5"><?= !empty($upcoming) ? date('d M Y',strtotime($upcoming[0]['due'])) : '—' ?></div></div>
        </div>
        <?php if ($emiRatioPct > 40): ?>
        <div class="mt-3 flex items-center gap-2 text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">
          <i class="ri-error-warning-line"></i>
          <span>EMI-to-income ratio is high. Consider prepaying a loan to reduce your burden.</span>
          <button onclick="document.getElementById('prepayModal').classList.remove('hidden')" class="ml-auto text-xs bg-red-100 hover:bg-red-200 px-2 py-1 rounded font-medium">Simulate Prepayment →</button>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Add Loan + Filters Bar -->
      <div class="bg-white rounded-xl shadow-sm border p-6">
        <div class="flex items-center justify-between mb-5">
          <h2 class="text-lg font-semibold text-gray-800">My Loans</h2>
          <div class="flex gap-2">
            <a href="?export=csv" class="flex items-center gap-1.5 px-3 py-2 rounded-lg border text-sm text-gray-600 hover:bg-gray-50 transition-colors">
              <i class="ri-download-line"></i>Export CSV
            </a>
            <button id="openAddLoan" class="flex items-center gap-1.5 px-4 py-2 rounded-lg bg-primary text-white text-sm font-medium hover:bg-purple-700 transition-colors">
              <i class="ri-add-line"></i>Add Loan
            </button>
          </div>
        </div>
        <!-- Filters -->
        <form method="get" class="flex flex-wrap gap-3 mb-5">
          <input type="text" name="q" placeholder="Search purpose..." value="<?= h($q) ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-sm flex-1 min-w-[140px] focus:outline-none focus:border-primary">
          <select name="status" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
            <option value="all" <?= $filterStatus==='all'?'selected':'' ?>>All Status</option>
            <?php foreach(['Active','Approved','Pending','Closed'] as $s): ?>
            <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
          <input type="date" name="from" value="<?= h($from) ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
          <input type="date" name="to" value="<?= h($to) ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
          <button class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-purple-700 transition-colors">Apply</button>
          <a href="loans.php" class="px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50 transition-colors">Clear</a>
        </form>

        <!-- Loan Cards -->
        <?php if (empty($loans)): ?>
        <div class="flex flex-col items-center justify-center py-14 text-center">
          <div class="w-16 h-16 bg-gray-100 rounded-2xl flex items-center justify-center mb-4"><i class="ri-bank-line text-2xl text-gray-400"></i></div>
          <p class="text-gray-500 font-medium">No loans found</p>
          <p class="text-sm text-gray-400 mt-1">Add your first loan using the button above.</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
          <?php
          $statusColors = ['Active'=>'bg-green-100 text-green-700','Approved'=>'bg-blue-100 text-blue-700','Pending'=>'bg-yellow-100 text-yellow-700','Closed'=>'bg-gray-100 text-gray-600'];
          $monthlyIncForCalc = $annualIncome > 0 ? $annualIncome/12 : 0;
          foreach ($loans as $L):
            $lStatus = $L['status'] ?? 'Active';
            $lEmiRatio = ($monthlyIncForCalc > 0 && $lStatus === 'Active') ? ($L['emi_amount']/$monthlyIncForCalc*100) : 0;
            $isRisk = $lEmiRatio > 40;
          ?>
          <div class="border border-gray-200 rounded-xl p-5 hover:border-primary/30 hover:shadow-sm transition-all">
            <div class="flex items-start justify-between gap-4">
              <div class="flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                  <h4 class="font-semibold text-gray-800"><?= h($L['purpose'] ?: 'Loan #'.$L['loan_id']) ?></h4>
                  <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $statusColors[$lStatus] ?? 'bg-gray-100 text-gray-600' ?>"><?= h($lStatus) ?></span>
                  <?php if ($isRisk): ?>
                  <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-600">⚠ At Risk</span>
                  <?php endif; ?>
                  <span class="text-xs text-gray-400">#<?= $L['loan_id'] ?></span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-3 text-sm">
                  <div><span class="text-gray-400 text-xs">Amount</span><div class="font-semibold text-gray-800"><?= inr((float)$L['loan_amount']) ?></div></div>
                  <div><span class="text-gray-400 text-xs">EMI</span><div class="font-semibold text-gray-800"><?= inr((float)$L['emi_amount']) ?></div></div>
                  <div><span class="text-gray-400 text-xs">Rate</span><div class="font-semibold text-gray-800"><?= h($L['interest_rate']) ?>%</div></div>
                  <div><span class="text-gray-400 text-xs">End Date</span><div class="font-semibold text-gray-800"><?= $L['end_date'] ? date('M Y',strtotime($L['end_date'])) : '—' ?></div></div>
                </div>
              </div>
              <div class="flex gap-2 flex-shrink-0">
                <!-- Amortization -->
                <button onclick="showAmortization(<?= (int)$L['loan_id'] ?>,<?= (float)$L['loan_amount'] ?>,<?= (float)$L['interest_rate'] ?>,<?= (int)$L['repayment_period'] ?>,<?= (float)$L['emi_amount'] ?>,'<?= h($L['purpose']?:'Loan') ?>')"
                        class="px-3 py-1.5 rounded-lg border border-blue-300 text-blue-600 text-xs font-medium hover:bg-blue-50 transition-colors">
                  <i class="ri-table-line"></i> Schedule
                </button>
                <!-- Set Reminder -->
                <form method="post" class="inline">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="set_reminder">
                  <input type="hidden" name="loan_id" value="<?= $L['loan_id'] ?>">
                  <input type="hidden" name="start_date" value="<?= h($L['start_date']) ?>">
                  <input type="hidden" name="installment_option" value="<?= h($L['installment_option']) ?>">
                  <input type="hidden" name="emi_amount" value="<?= h($L['emi_amount']) ?>">
                  <button type="submit" class="px-3 py-1.5 rounded-lg border border-primary text-primary text-xs font-medium hover:bg-primary/5 transition-colors">
                    <i class="ri-alarm-line"></i> Remind
                  </button>
                </form>
                <!-- Edit toggle -->
                <button onclick="toggleEdit('edit-<?= $L['loan_id'] ?>')" class="px-3 py-1.5 rounded-lg border text-xs font-medium text-gray-600 hover:bg-gray-50 transition-colors">
                  <i class="ri-edit-line"></i> Edit
                </button>
                <!-- Delete -->
                <form method="post" class="inline" onsubmit="return confirm('Delete this loan?')">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="loan_id" value="<?= $L['loan_id'] ?>">
                  <button type="submit" class="px-3 py-1.5 rounded-lg border border-red-300 text-red-500 text-xs font-medium hover:bg-red-50 transition-colors">
                    <i class="ri-delete-bin-line"></i>
                  </button>
                </form>
              </div>
            </div>
            <!-- Inline Edit Form (hidden by default) -->
            <div id="edit-<?= $L['loan_id'] ?>" class="hidden mt-4 border-t pt-4">
              <form method="post" class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="loan_id" value="<?= $L['loan_id'] ?>">
                <div><label class="text-xs text-gray-500 mb-1 block">Purpose</label>
                  <input type="text" name="purpose" value="<?= h($L['purpose']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
                <div><label class="text-xs text-gray-500 mb-1 block">Amount (₹)</label>
                  <input type="number" step="0.01" name="loan_amount" value="<?= h($L['loan_amount']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
                <div><label class="text-xs text-gray-500 mb-1 block">Rate (%)</label>
                  <input type="number" step="0.01" name="interest_rate" value="<?= h($L['interest_rate']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
                <div><label class="text-xs text-gray-500 mb-1 block">Period (months)</label>
                  <input type="number" name="repayment_period" value="<?= h($L['repayment_period']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
                <div><label class="text-xs text-gray-500 mb-1 block">Start Date</label>
                  <input type="date" name="start_date" value="<?= h($L['start_date']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
                <div><label class="text-xs text-gray-500 mb-1 block">Installment</label>
                  <select name="installment_option" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
                    <?php foreach(['Monthly','Quarterly','Yearly'] as $opt): ?>
                    <option <?= $L['installment_option']===$opt?'selected':'' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div><label class="text-xs text-gray-500 mb-1 block">Status</label>
                  <select name="status" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
                    <?php foreach(['Active','Approved','Pending','Closed'] as $opt): ?>
                    <option <?= $L['status']===$opt?'selected':'' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div class="flex items-end">
                  <button type="submit" class="w-full px-3 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-purple-700 transition-colors">Save Changes</button>
                </div>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Upcoming Payments -->
      <?php if (!empty($upcoming)): ?>
      <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="ri-calendar-event-line text-primary"></i>Upcoming Payments</h3>
        <div class="space-y-3">
          <?php foreach ($upcoming as $U): ?>
          <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl">
            <div>
              <p class="text-sm font-semibold text-gray-800">Loan #<?= h($U['loan_id']) ?> — <?= h($U['title']) ?></p>
              <p class="text-xs text-gray-500 mt-0.5">Due: <strong><?= date('d M Y',strtotime($U['due'])) ?></strong> &nbsp;·&nbsp; EMI: <strong><?= inr((float)$U['emi']) ?></strong></p>
            </div>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="set_reminder">
              <input type="hidden" name="loan_id" value="<?= h($U['loan_id']) ?>">
              <input type="hidden" name="start_date" value="<?= h($U['due']) ?>">
              <input type="hidden" name="installment_option" value="Monthly">
              <input type="hidden" name="emi_amount" value="<?= h($U['emi']) ?>">
              <button type="submit" class="px-3 py-1.5 rounded-lg border border-primary text-primary text-xs font-medium hover:bg-primary/5 transition-colors">
                <i class="ri-alarm-line"></i> Set Reminder
              </button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- end p-8 -->
  </div><!-- end main -->
</div><!-- end flex -->

<!-- ═══════════════════════════════ ADD LOAN MODAL ═══════════════════════════ -->
<div id="addLoanModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-start justify-center pt-16 px-4">
  <div class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl">
    <div class="flex items-center justify-between px-6 py-4 border-b">
      <h3 class="text-lg font-semibold">Add New Loan</h3>
      <button id="closeAddLoan" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center"><i class="ri-close-line text-lg"></i></button>
    </div>
    <form method="post" class="px-6 py-5 space-y-4">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="create">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div><label class="text-sm text-gray-600 mb-1 block">Purpose / Type</label>
          <input type="text" name="purpose" placeholder="e.g., Home Loan" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Loan Amount (₹)</label>
          <input type="number" step="0.01" id="ml_amount" name="loan_amount" placeholder="₹" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Interest Rate (%)</label>
          <input type="number" step="0.01" id="ml_rate" name="interest_rate" placeholder="%" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Start Date</label>
          <input type="date" id="ml_start" name="start_date" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Repayment Period (months)</label>
          <input type="number" id="ml_months" name="repayment_period" value="12" min="1" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Installment Option</label>
          <select name="installment_option" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
            <option>Monthly</option><option>Quarterly</option><option>Yearly</option>
          </select></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Status</label>
          <select name="status" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
            <option>Active</option><option>Approved</option><option>Pending</option><option>Closed</option>
          </select></div>
        <div><label class="text-sm text-gray-600 mb-1 block">End Date (calculated)</label>
          <input type="date" id="ml_end" disabled class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 text-gray-500"></div>
      </div>
      <div class="bg-primary/5 border border-primary/20 rounded-xl p-4 flex items-center justify-between">
        <div><p class="text-xs text-gray-500">Monthly EMI Preview</p><p class="text-2xl font-bold text-primary">₹<span id="ml_emi_preview">0.00</span></p></div>
        <div class="flex gap-2">
          <button type="button" id="closeAddLoanFooter" class="px-4 py-2 rounded-lg border text-sm text-gray-600 hover:bg-gray-50">Cancel</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-primary text-white text-sm font-medium hover:bg-purple-700">Create Loan</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════ AMORTIZATION MODAL ══════════════════════════ -->
<div id="amortModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-start justify-center pt-10 px-4">
  <div class="w-full max-w-4xl bg-white rounded-2xl shadow-2xl max-h-[85vh] flex flex-col">
    <div class="flex items-center justify-between px-6 py-4 border-b flex-shrink-0">
      <div>
        <h3 class="text-lg font-semibold" id="amortTitle">Amortization Schedule</h3>
        <p class="text-xs text-gray-400" id="amortSubtitle"></p>
      </div>
      <button onclick="document.getElementById('amortModal').classList.add('hidden')" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center"><i class="ri-close-line text-lg"></i></button>
    </div>
    <div class="overflow-auto flex-1 p-6">
      <div id="amortSummary" class="grid grid-cols-3 gap-4 mb-5 text-sm"></div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-xs border-collapse">
          <thead>
            <tr class="bg-gray-50 text-gray-500 text-left">
              <th class="px-3 py-2 font-medium">Month</th>
              <th class="px-3 py-2 font-medium">Opening Balance</th>
              <th class="px-3 py-2 font-medium">EMI</th>
              <th class="px-3 py-2 font-medium">Principal</th>
              <th class="px-3 py-2 font-medium">Interest</th>
              <th class="px-3 py-2 font-medium">Closing Balance</th>
            </tr>
          </thead>
          <tbody id="amortBody" class="divide-y divide-gray-100"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════ PREPAYMENT MODAL ════════════════════════════ -->
<div id="prepayModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center px-4">
  <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl p-6">
    <div class="flex items-center justify-between mb-5">
      <h3 class="text-lg font-semibold">Prepayment Simulator</h3>
      <button onclick="document.getElementById('prepayModal').classList.add('hidden')" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center"><i class="ri-close-line text-lg"></i></button>
    </div>
    <div class="space-y-4">
      <div class="grid grid-cols-2 gap-4">
        <div><label class="text-sm text-gray-600 mb-1 block">Loan Amount (₹)</label>
          <input type="number" id="pp_amount" value="500000" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Interest Rate (%)</label>
          <input type="number" id="pp_rate" step="0.01" value="10" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Tenure (months)</label>
          <input type="number" id="pp_months" value="120" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Prepayment Amount (₹)</label>
          <input type="number" id="pp_prepay" value="50000" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
      </div>
      <button onclick="simulatePrepayment()" class="w-full py-2.5 bg-primary text-white rounded-lg font-medium hover:bg-purple-700 transition-colors">Simulate</button>
      <div id="pp_result" class="hidden bg-green-50 border border-green-200 rounded-xl p-4 text-sm space-y-2"></div>
    </div>
  </div>
</div>

<script>
// ── Add Loan Modal ────────────────────────────────────────────────────────────
(function() {
  const modal = document.getElementById('addLoanModal');
  const open  = document.getElementById('openAddLoan');
  const close = document.getElementById('closeAddLoan');
  const closeF= document.getElementById('closeAddLoanFooter');
  const show  = () => { modal.classList.remove('hidden'); document.body.style.overflow='hidden'; };
  const hide  = () => { modal.classList.add('hidden');    document.body.style.overflow=''; };
  open?.addEventListener('click',show);
  close?.addEventListener('click',hide);
  closeF?.addEventListener('click',hide);
  modal?.addEventListener('click', e => { if(e.target===modal) hide(); });
  document.addEventListener('keydown', e => { if(e.key==='Escape') hide(); });

  const amt = document.getElementById('ml_amount');
  const rate= document.getElementById('ml_rate');
  const mth = document.getElementById('ml_months');
  const st  = document.getElementById('ml_start');
  const end = document.getElementById('ml_end');
  const emi = document.getElementById('ml_emi_preview');

  function calcEmi(p,r,n){ r=r/100/12; if(r<=0) return p/n; const pw=Math.pow(1+r,n); return p*r*pw/(pw-1); }
  function update(){
    const p=parseFloat(amt?.value||0), r=parseFloat(rate?.value||0), n=parseInt(mth?.value||0);
    if(emi) emi.textContent = (n>0&&p>0) ? calcEmi(p,r,n).toFixed(2) : '0.00';
    if(st&&mth&&end){
      const d=new Date(st.value); if(!isNaN(d)){
        d.setMonth(d.getMonth()+parseInt(mth.value||0));
        end.value=d.toISOString().split('T')[0];
      }
    }
  }
  [amt,rate,mth,st].forEach(el=>el?.addEventListener('input',update));
  update();
})();

// ── Toggle inline edit ────────────────────────────────────────────────────────
function toggleEdit(id) {
  const el = document.getElementById(id);
  if(el) el.classList.toggle('hidden');
}

// ── Amortization Schedule ─────────────────────────────────────────────────────
function showAmortization(id, P, rPct, months, emiAmt, title) {
  const modal   = document.getElementById('amortModal');
  const body    = document.getElementById('amortBody');
  const summary = document.getElementById('amortSummary');
  document.getElementById('amortTitle').textContent = title + ' — Amortization';
  document.getElementById('amortSubtitle').textContent = `₹${P.toLocaleString('en-IN')} @ ${rPct}% for ${months} months`;

  const r = rPct/100/12;
  const emi = emiAmt > 0 ? emiAmt : (r>0 ? P*r*Math.pow(1+r,months)/(Math.pow(1+r,months)-1) : P/months);
  let balance = P, totalInterest = 0, totalPrincipal = 0;
  body.innerHTML = '';

  for (let m=1; m<=months && balance>0.01; m++) {
    const interest  = balance * r;
    const principal = Math.min(emi - interest, balance);
    balance -= principal;
    totalInterest  += interest;
    totalPrincipal += principal;
    const tr = document.createElement('tr');
    tr.className = m%2===0 ? 'bg-gray-50' : '';
    tr.innerHTML = `
      <td class="px-3 py-1.5">${m}</td>
      <td class="px-3 py-1.5">₹${(balance+principal).toLocaleString('en-IN',{maximumFractionDigits:0})}</td>
      <td class="px-3 py-1.5 font-medium">₹${emi.toLocaleString('en-IN',{maximumFractionDigits:0})}</td>
      <td class="px-3 py-1.5 text-green-700">₹${principal.toLocaleString('en-IN',{maximumFractionDigits:0})}</td>
      <td class="px-3 py-1.5 text-orange-600">₹${interest.toLocaleString('en-IN',{maximumFractionDigits:0})}</td>
      <td class="px-3 py-1.5 ${balance<0.01?'text-green-600 font-bold':''}">₹${Math.max(0,balance).toLocaleString('en-IN',{maximumFractionDigits:0})}</td>`;
    body.appendChild(tr);
  }

  const totalPayable = totalPrincipal + totalInterest;
  summary.innerHTML = `
    <div class="bg-blue-50 rounded-xl p-3"><p class="text-xs text-blue-500 font-medium">Total Payable</p><p class="text-lg font-bold text-blue-700">₹${totalPayable.toLocaleString('en-IN',{maximumFractionDigits:0})}</p></div>
    <div class="bg-orange-50 rounded-xl p-3"><p class="text-xs text-orange-500 font-medium">Total Interest</p><p class="text-lg font-bold text-orange-700">₹${totalInterest.toLocaleString('en-IN',{maximumFractionDigits:0})}</p></div>
    <div class="bg-green-50 rounded-xl p-3"><p class="text-xs text-green-500 font-medium">Principal Repaid</p><p class="text-lg font-bold text-green-700">₹${totalPrincipal.toLocaleString('en-IN',{maximumFractionDigits:0})}</p></div>`;

  modal.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

// ── Prepayment Simulator ──────────────────────────────────────────────────────
function simulatePrepayment() {
  const P      = parseFloat(document.getElementById('pp_amount').value||0);
  const r      = parseFloat(document.getElementById('pp_rate').value||0)/100/12;
  const n      = parseInt(document.getElementById('pp_months').value||0);
  const prepay = parseFloat(document.getElementById('pp_prepay').value||0);
  if (!P||!n) return;

  // Original
  const emi0 = r>0 ? P*r*Math.pow(1+r,n)/(Math.pow(1+r,n)-1) : P/n;
  const totalOrig = emi0 * n;

  // After prepayment (new balance, same EMI, shorter tenure)
  const newP = Math.max(0, P - prepay);
  let bal = newP, mLeft = 0, totalNew = prepay;
  while (bal > 0.01 && mLeft < n) {
    const interest  = bal * r;
    const principal = Math.min(emi0 - interest, bal);
    bal -= principal; totalNew += emi0; mLeft++;
  }
  const savedInterest = totalOrig - totalNew;
  const savedMonths   = n - mLeft;

  const res = document.getElementById('pp_result');
  res.classList.remove('hidden');
  res.innerHTML = `
    <div class="font-semibold text-green-700 text-base">Prepayment saves you ₹${savedInterest.toLocaleString('en-IN',{maximumFractionDigits:0})} in interest!</div>
    <div class="grid grid-cols-2 gap-3 mt-2">
      <div class="bg-white rounded-lg p-2"><p class="text-xs text-gray-500">Original Tenure</p><p class="font-bold">${n} months</p></div>
      <div class="bg-white rounded-lg p-2"><p class="text-xs text-gray-500">New Tenure</p><p class="font-bold text-green-700">${mLeft} months</p></div>
      <div class="bg-white rounded-lg p-2"><p class="text-xs text-gray-500">Months Saved</p><p class="font-bold text-green-700">${savedMonths} months</p></div>
      <div class="bg-white rounded-lg p-2"><p class="text-xs text-gray-500">Total Saving</p><p class="font-bold text-green-700">₹${savedInterest.toLocaleString('en-IN',{maximumFractionDigits:0})}</p></div>
    </div>`;
}

// ── Prepayment modal open button (if not in insight bar) ─────────────────────
document.addEventListener('DOMContentLoaded', function() {
  const prepayBtn = document.getElementById('openPrepay');
  prepayBtn?.addEventListener('click', () => document.getElementById('prepayModal').classList.remove('hidden'));
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
