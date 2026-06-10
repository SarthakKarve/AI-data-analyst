<?php
require __DIR__ . '/includes/auth_check.php';

function s($v): string { return trim((string)$v); }
function nextDueDate(string $start, string $opt): string {
    $d   = new DateTime($start ?: 'today');
    $now = new DateTime('today');
    $step = $opt==='Quarterly'?'P3M':($opt==='Yearly'?'P12M':'P1M');
    $int  = new DateInterval($step);
    while ($d <= $now) $d->add($int);
    return $d->format('Y-m-d');
}
function scheduleNextReminder(PDO $pdo, int $userId, int $loanId, string $start, string $opt, float $emi): void {
    $due = nextDueDate($start,$opt);
    $pdo->prepare("DELETE FROM reminders WHERE user_id=? AND loan_id=? AND status='Upcoming' AND title LIKE 'Loan EMI due%'")->execute([$userId,$loanId]);
    $pdo->prepare("INSERT INTO reminders (user_id,loan_id,title,description,due_date,status,notify_email,notify_sms) VALUES (?,?,'Loan EMI due',?,?,'Upcoming',0,0)")
        ->execute([$userId,$loanId,'Upcoming installment for Loan #'.$loanId.' — ₹'.number_format($emi,2),$due]);
}

$csrf   = csrfToken();
$errors = []; $success = '';

// Loans list for linking
$loanRows  = fetchAll($pdo, "SELECT loan_id, purpose, loan_amount, emi_amount, start_date, installment_option, status FROM loans WHERE user_id=? ORDER BY loan_id DESC", [$userId]);
$loanDueMap = [];
foreach ($loanRows as $L) $loanDueMap[$L['loan_id']] = nextDueDate($L['start_date']??date('Y-m-d'),$L['installment_option']??'Monthly');

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if (!csrfVerify($_POST['csrf'] ?? '')) { $errors[] = 'Invalid CSRF token.'; }
    else {
        if (in_array($act,['create','update'],true)) {
            $title      = s($_POST['title']       ?? '');
            $desc       = s($_POST['description'] ?? '');
            $due        = s($_POST['due_date']    ?? '');
            $status     = $_POST['status']        ?? 'Upcoming';
            $loan_id    = (int)($_POST['loan_id'] ?? 0); if ($loan_id < 1) $loan_id = null;
            $notify_em  = isset($_POST['notify_email']) ? 1 : 0;
            $notify_sms = isset($_POST['notify_sms'])   ? 1 : 0;
            $recurring  = in_array($_POST['recurring']??'none',['none','daily','weekly','monthly'],true) ? $_POST['recurring'] : 'none';
            $priority   = in_array($_POST['priority_level']??'Medium',['Low','Medium','High'],true) ? $_POST['priority_level'] : 'Medium';
            if ($title==='')  $errors[]='Title is required.';
            if ($due==='')    $errors[]='Due date is required.';
            if (!in_array($status,['Upcoming','Completed','High Priority'],true)) $status='Upcoming';
            if (empty($errors)) {
                if ($act==='create') {
                    $pdo->prepare("INSERT INTO reminders (user_id,loan_id,title,description,due_date,status,notify_email,notify_sms,recurring,priority_level) VALUES (?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$userId,$loan_id,$title,$desc,$due,$status,$notify_em,$notify_sms,$recurring,$priority]);
                    logActivity($pdo,$userId,'reminder_added',"Added reminder: $title");
                    $success='Reminder created.';
                } else {
                    $rid = (int)($_POST['reminder_id']??0);
                    if ($rid<1) { $errors[]='Invalid reminder ID.'; }
                    else {
                        $pdo->prepare("UPDATE reminders SET loan_id=?,title=?,description=?,due_date=?,status=?,notify_email=?,notify_sms=?,recurring=?,priority_level=? WHERE reminder_id=? AND user_id=?")
                            ->execute([$loan_id,$title,$desc,$due,$status,$notify_em,$notify_sms,$recurring,$priority,$rid,$userId]);
                        $success='Reminder updated.';
                    }
                }
            }
        } elseif ($act==='delete') {
            $rid = (int)($_POST['reminder_id']??0);
            if ($rid<1) $errors[]='Invalid reminder ID.';
            else { $pdo->prepare("DELETE FROM reminders WHERE reminder_id=? AND user_id=?")->execute([$rid,$userId]); $success='Reminder deleted.'; }
        } elseif ($act==='complete') {
            $rid = (int)($_POST['reminder_id']??0);
            if ($rid>0) { $pdo->prepare("UPDATE reminders SET status='Completed' WHERE reminder_id=? AND user_id=?")->execute([$rid,$userId]); $success='Marked as completed.'; }
        } elseif ($act==='priority') {
            $rid = (int)($_POST['reminder_id']??0);
            if ($rid>0) { $pdo->prepare("UPDATE reminders SET status='High Priority' WHERE reminder_id=? AND user_id=?")->execute([$rid,$userId]); $success='Marked as high priority.'; }
        } elseif ($act==='refresh_loans') {
            $active = fetchAll($pdo,"SELECT loan_id,start_date,installment_option,emi_amount FROM loans WHERE user_id=? AND status='Active'",[$userId]);
            foreach ($active as $L) scheduleNextReminder($pdo,$userId,(int)$L['loan_id'],$L['start_date'],$L['installment_option'],(float)$L['emi_amount']);
            $success='EMI reminders refreshed for all active loans.';
        }
    }
}

// ── Filter / Tab ──────────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'upcoming';
$whereTab = "user_id=?";
$paramsTab = [$userId];
if ($tab==='today') {
    $whereTab .= " AND due_date = CURDATE() AND status != 'Completed'";
} elseif ($tab==='completed') {
    $whereTab .= " AND status = 'Completed'";
} else { // upcoming (default)
    $whereTab .= " AND status != 'Completed'";
}
$reminders = fetchAll($pdo, "SELECT * FROM reminders WHERE $whereTab ORDER BY due_date ASC LIMIT 100", $paramsTab);

// Stats
$total    = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=?",[$userId]);
$todayC   = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND due_date=CURDATE() AND status!='Completed'",[$userId]);
$upcoming = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status IN ('Upcoming','High Priority')",[$userId]);
$highP    = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status='High Priority'",[$userId]);

$userRow   = fetchOne($pdo,"SELECT first_name FROM users WHERE user_id=?",[$userId]);
$firstName = $userRow['first_name'] ?? 'User';
$highPriorityReminders = $highP;

$pageTitle  = 'Reminders';
$activePage = 'reminders';

$statusColors = ['Upcoming'=>'bg-blue-100 text-blue-700','High Priority'=>'bg-red-100 text-red-700','Completed'=>'bg-green-100 text-green-700'];
$priorityColors = ['High'=>'bg-red-100 text-red-600','Medium'=>'bg-yellow-100 text-yellow-600','Low'=>'bg-green-100 text-green-600'];
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
          <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-list-check-2 text-blue-600 text-lg"></i></div>
          <div class="text-2xl font-bold"><?= $total ?></div>
          <div class="text-sm text-gray-500">Total Reminders</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-calendar-todo-line text-orange-600 text-lg"></i></div>
          <div class="text-2xl font-bold"><?= $todayC ?></div>
          <div class="text-sm text-gray-500">Due Today</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-alarm-line text-indigo-600 text-lg"></i></div>
          <div class="text-2xl font-bold"><?= $upcoming ?></div>
          <div class="text-sm text-gray-500">Upcoming</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-error-warning-line text-red-600 text-lg"></i></div>
          <div class="text-2xl font-bold"><?= $highP ?></div>
          <div class="text-sm text-gray-500">High Priority</div>
        </div>
      </div>

      <!-- Action Bar + Tabs -->
      <div class="bg-white rounded-xl shadow-sm border p-6">
        <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
          <div class="flex items-center gap-1 bg-gray-100 rounded-xl p-1">
            <?php
            $tabs = ['upcoming'=>'Upcoming','today'=>'Due Today','completed'=>'Completed'];
            foreach ($tabs as $tk => $tl):
            ?>
            <a href="?tab=<?= $tk ?>" class="px-4 py-1.5 rounded-lg text-sm font-medium transition-all <?= $tab===$tk ? 'bg-white text-primary shadow-sm' : 'text-gray-500 hover:text-gray-700' ?>"><?= $tl ?></a>
            <?php endforeach; ?>
          </div>
          <div class="flex gap-2">
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="refresh_loans">
              <button type="submit" class="flex items-center gap-1.5 px-3 py-2 rounded-lg border text-sm text-gray-600 hover:bg-gray-50 transition-colors">
                <i class="ri-refresh-line"></i> Sync Loan EMIs
              </button>
            </form>
            <button id="openAddReminder" class="flex items-center gap-1.5 px-4 py-2 rounded-lg bg-primary text-white text-sm font-medium hover:bg-purple-700 transition-colors">
              <i class="ri-add-line"></i> Add Reminder
            </button>
          </div>
        </div>

        <!-- Reminder Cards -->
        <?php if (empty($reminders)): ?>
        <div class="flex flex-col items-center justify-center py-14 text-center">
          <i class="ri-checkbox-circle-line text-4xl text-green-400 mb-3"></i>
          <p class="text-gray-500 font-medium">All clear!</p>
          <p class="text-sm text-gray-400 mt-1">No reminders found for this filter.</p>
        </div>
        <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($reminders as $R):
            $daysLeft = (int)ceil((strtotime($R['due_date']) - time()) / 86400);
            $isOverdue = $daysLeft < 0 && $R['status'] !== 'Completed';
            $isToday   = $daysLeft === 0;
            $priority  = $R['priority_level'] ?? 'Medium';
            $recurring = $R['recurring'] ?? 'none';
          ?>
          <div class="flex items-start gap-4 p-4 border rounded-xl hover:border-primary/30 hover:shadow-sm transition-all <?= $isOverdue ? 'border-red-200 bg-red-50/30' : '' ?>">
            <!-- Priority dot -->
            <div class="mt-1 w-2.5 h-2.5 rounded-full flex-shrink-0 <?= $priority==='High'||$isOverdue?'bg-red-500':($priority==='Medium'?'bg-yellow-400':'bg-green-500') ?>"></div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-semibold text-gray-800 text-sm"><?= h($R['title']) ?></span>
                <!-- Status badge -->
                <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $statusColors[$R['status']] ?? 'bg-gray-100 text-gray-600' ?>"><?= h($R['status']) ?></span>
                <!-- Priority badge -->
                <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $priorityColors[$priority] ?>"><?= h($priority) ?></span>
                <!-- Recurring badge -->
                <?php if ($recurring !== 'none'): ?>
                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-600"><i class="ri-repeat-line text-xs"></i> <?= ucfirst($recurring) ?></span>
                <?php endif; ?>
              </div>
              <?php if ($R['description']): ?>
              <p class="text-xs text-gray-500 mt-1 line-clamp-2"><?= h($R['description']) ?></p>
              <?php endif; ?>
              <div class="flex items-center gap-3 mt-2 text-xs text-gray-400">
                <span class="<?= $isOverdue?'text-red-500 font-medium':($isToday?'text-orange-500 font-medium':'') ?>">
                  <i class="ri-calendar-line mr-1"></i><?= $isOverdue?'Overdue — ':($isToday?'Due Today — ':'Due: ') ?><?= date('d M Y',strtotime($R['due_date'])) ?>
                </span>
                <?php if ($R['loan_id']): ?>
                <span><i class="ri-bank-line mr-1"></i>Loan #<?= h($R['loan_id']) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <!-- Actions -->
            <div class="flex gap-1.5 flex-shrink-0">
              <?php if ($R['status'] !== 'Completed'): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="complete"><input type="hidden" name="reminder_id" value="<?= $R['reminder_id'] ?>">
                <button type="submit" title="Mark complete" class="w-8 h-8 rounded-lg bg-green-100 text-green-600 hover:bg-green-200 flex items-center justify-center transition-colors"><i class="ri-check-line text-sm"></i></button>
              </form>
              <?php if ($R['status'] !== 'High Priority'): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="priority"><input type="hidden" name="reminder_id" value="<?= $R['reminder_id'] ?>">
                <button type="submit" title="Mark high priority" class="w-8 h-8 rounded-lg bg-red-100 text-red-500 hover:bg-red-200 flex items-center justify-center transition-colors"><i class="ri-flag-line text-sm"></i></button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
              <button onclick="toggleEdit('redit-<?= $R['reminder_id'] ?>')" class="w-8 h-8 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 flex items-center justify-center transition-colors"><i class="ri-edit-line text-sm"></i></button>
              <form method="post" onsubmit="return confirm('Delete this reminder?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="reminder_id" value="<?= $R['reminder_id'] ?>">
                <button type="submit" class="w-8 h-8 rounded-lg bg-red-100 text-red-500 hover:bg-red-200 flex items-center justify-center transition-colors"><i class="ri-delete-bin-line text-sm"></i></button>
              </form>
            </div>
          </div>
          <!-- Inline Edit Form -->
          <div id="redit-<?= $R['reminder_id'] ?>" class="hidden border border-primary/20 rounded-xl p-4 bg-primary/5 -mt-2 mb-1">
            <form method="post" class="grid grid-cols-2 md:grid-cols-3 gap-3">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="reminder_id" value="<?= $R['reminder_id'] ?>">
              <div class="col-span-2 md:col-span-1"><label class="text-xs text-gray-500 mb-1 block">Title</label>
                <input type="text" name="title" value="<?= h($R['title']) ?>" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Due Date</label>
                <input type="date" name="due_date" value="<?= h($R['due_date']) ?>" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
                  <?php foreach(['Upcoming','High Priority','Completed'] as $s): ?>
                  <option <?= $R['status']===$s?'selected':'' ?>><?= $s ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Priority</label>
                <select name="priority_level" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
                  <?php foreach(['Low','Medium','High'] as $p): ?>
                  <option <?= ($R['priority_level']??'Medium')===$p?'selected':'' ?>><?= $p ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Recurring</label>
                <select name="recurring" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
                  <?php foreach(['none','daily','weekly','monthly'] as $r): ?>
                  <option <?= ($R['recurring']??'none')===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Link to Loan</label>
                <select name="loan_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
                  <option value="0">None</option>
                  <?php foreach ($loanRows as $L): ?>
                  <option value="<?= $L['loan_id'] ?>" <?= $R['loan_id']==$L['loan_id']?'selected':'' ?>>
                    #<?= $L['loan_id'] ?> – <?= h($L['purpose']?:'Loan') ?>
                  </option>
                  <?php endforeach; ?>
                </select></div>
              <div class="col-span-2 md:col-span-3"><label class="text-xs text-gray-500 mb-1 block">Description</label>
                <input type="text" name="description" value="<?= h($R['description']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div class="col-span-2 md:col-span-3 flex gap-2">
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-purple-700">Save Changes</button>
                <button type="button" onclick="toggleEdit('redit-<?= $R['reminder_id'] ?>')" class="px-4 py-2 border rounded-lg text-sm text-gray-600 hover:bg-gray-50">Cancel</button>
              </div>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<!-- ADD REMINDER MODAL -->
<div id="addReminderModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-start justify-center pt-16 px-4">
  <div class="w-full max-w-xl bg-white rounded-2xl shadow-2xl">
    <div class="flex items-center justify-between px-6 py-4 border-b">
      <h3 class="text-lg font-semibold">New Reminder</h3>
      <button id="closeAddReminder" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center"><i class="ri-close-line text-lg"></i></button>
    </div>
    <form method="post" class="px-6 py-5 space-y-4">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="create">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2"><label class="text-sm text-gray-600 mb-1 block">Title *</label>
          <input type="text" name="title" placeholder="e.g., EMI Payment due" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Due Date *</label>
          <input type="date" name="due_date" value="<?= date('Y-m-d') ?>" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Status</label>
          <select name="status" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
            <option>Upcoming</option><option>High Priority</option><option>Completed</option>
          </select></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Priority Level</label>
          <select name="priority_level" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
            <option value="Low">Low</option><option value="Medium" selected>Medium</option><option value="High">High</option>
          </select></div>
        <div><label class="text-sm text-gray-600 mb-1 block">Recurring</label>
          <select name="recurring" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
            <option value="none">None</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option>
          </select></div>
        <div class="md:col-span-2"><label class="text-sm text-gray-600 mb-1 block">Link to Loan (optional)</label>
          <select name="loan_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
            <option value="0">None</option>
            <?php foreach ($loanRows as $L): ?>
            <option value="<?= $L['loan_id'] ?>">#<?= $L['loan_id'] ?> – <?= h($L['purpose']?:'Loan') ?> (Due: <?= $loanDueMap[$L['loan_id']] ?>)</option>
            <?php endforeach; ?>
          </select></div>
        <div class="md:col-span-2"><label class="text-sm text-gray-600 mb-1 block">Description</label>
          <textarea name="description" rows="2" placeholder="Optional notes..." class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary resize-none"></textarea></div>
      </div>
      <div class="flex gap-2">
        <button type="submit" class="flex-1 py-2.5 bg-primary text-white rounded-lg font-medium hover:bg-purple-700 transition-colors">Create Reminder</button>
        <button type="button" id="closeAddReminderFooter" class="px-4 py-2 border rounded-lg text-sm text-gray-600 hover:bg-gray-50">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleEdit(id){ const el=document.getElementById(id); if(el) el.classList.toggle('hidden'); }
(function(){
  const modal=document.getElementById('addReminderModal');
  const show=()=>{modal.classList.remove('hidden');document.body.style.overflow='hidden';};
  const hide=()=>{modal.classList.add('hidden');document.body.style.overflow='';};
  document.getElementById('openAddReminder')?.addEventListener('click',show);
  document.getElementById('closeAddReminder')?.addEventListener('click',hide);
  document.getElementById('closeAddReminderFooter')?.addEventListener('click',hide);
  modal?.addEventListener('click',e=>{if(e.target===modal)hide();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape')hide();});
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
