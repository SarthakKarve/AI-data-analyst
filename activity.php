<?php
require __DIR__ . '/includes/auth_check.php';

// ── Page data ─────────────────────────────────────────────────────────────────
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset= ($page - 1) * $limit;

$total    = (int)fetchValue($pdo,"SELECT COUNT(*) FROM activity_log WHERE user_id=?",[$userId]);
$pages    = max(1, (int)ceil($total/$limit));
$activities = fetchAll($pdo, "SELECT action, description, created_at FROM activity_log WHERE user_id=? ORDER BY created_at DESC LIMIT $limit OFFSET $offset", [$userId]);

// Stats
$todayCount  = (int)fetchValue($pdo,"SELECT COUNT(*) FROM activity_log WHERE user_id=? AND DATE(created_at)=CURDATE()",[$userId]);
$weekCount   = (int)fetchValue($pdo,"SELECT COUNT(*) FROM activity_log WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)",[$userId]);

// Icon/color map for action types
function activityMeta(string $action): array {
    return match(true) {
        str_contains($action,'loan_added')     => ['ri-bank-line',       'bg-blue-100 text-blue-600',   'Loan Added'],
        str_contains($action,'loan_updated')   => ['ri-edit-line',       'bg-indigo-100 text-indigo-600','Loan Updated'],
        str_contains($action,'loan_deleted')   => ['ri-delete-bin-line', 'bg-red-100 text-red-600',     'Loan Deleted'],
        str_contains($action,'doc_uploaded')   => ['ri-upload-cloud-line','bg-green-100 text-green-600', 'Document Uploaded'],
        str_contains($action,'doc_deleted')    => ['ri-file-reduce-line', 'bg-red-100 text-red-600',     'Document Deleted'],
        str_contains($action,'score_predicted')=> ['ri-line-chart-line',  'bg-purple-100 text-purple-600','Score Predicted'],
        str_contains($action,'reminder_added') => ['ri-alarm-line',       'bg-orange-100 text-orange-600','Reminder Added'],
        str_contains($action,'profile_updated')=> ['ri-user-line',        'bg-teal-100 text-teal-600',   'Profile Updated'],
        str_contains($action,'password_changed')=>['ri-lock-line',        'bg-gray-100 text-gray-600',   'Password Changed'],
        str_contains($action,'insurance')      => ['ri-shield-check-line','bg-yellow-100 text-yellow-600','Insurance Calculated'],
        str_contains($action,'goal_set')       => ['ri-focus-3-line',     'bg-pink-100 text-pink-600',   'Goal Set'],
        default                                => ['ri-information-line', 'bg-gray-100 text-gray-600',   ucfirst(str_replace('_',' ',$action))],
    };
}

// Group by date
$grouped = [];
foreach ($activities as $a) {
    $date = date('Y-m-d', strtotime($a['created_at']));
    $grouped[$date][] = $a;
}

$userRow   = fetchOne($pdo,"SELECT first_name FROM users WHERE user_id=?",[$userId]);
$firstName = $userRow['first_name'] ?? 'User';
$highPriorityReminders = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status='High Priority'",[$userId]);

$pageTitle  = 'Activity Log';
$activePage = 'activity';
?>
<?php require __DIR__ . '/includes/page_head.php'; ?>
<div class="flex">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <div class="ml-64 flex-1 min-h-screen">
    <?php require __DIR__ . '/includes/header.php'; ?>
    <div class="p-8 space-y-6 fade-in">

      <!-- Stats -->
      <div class="grid grid-cols-3 gap-5">
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-history-line text-blue-600 text-lg"></i></div>
          <div class="text-2xl font-bold"><?= $total ?></div>
          <div class="text-sm text-gray-500">Total Actions</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-calendar-check-line text-green-600 text-lg"></i></div>
          <div class="text-2xl font-bold"><?= $todayCount ?></div>
          <div class="text-sm text-gray-500">Actions Today</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-bar-chart-line text-purple-600 text-lg"></i></div>
          <div class="text-2xl font-bold"><?= $weekCount ?></div>
          <div class="text-sm text-gray-500">This Week</div>
        </div>
      </div>

      <!-- Timeline -->
      <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold text-gray-800 mb-6">Activity Timeline</h3>
        <?php if (empty($activities)): ?>
        <div class="flex flex-col items-center justify-center py-14 text-center">
          <div class="w-16 h-16 bg-gray-100 rounded-2xl flex items-center justify-center mb-4"><i class="ri-history-line text-2xl text-gray-400"></i></div>
          <p class="text-gray-500 font-medium">No activity recorded yet</p>
          <p class="text-sm text-gray-400 mt-1">Actions like adding loans, uploading documents, and predicting scores will appear here.</p>
        </div>
        <?php else: ?>
        <div class="space-y-8">
          <?php foreach ($grouped as $date => $dayActs): ?>
          <div>
            <!-- Date separator -->
            <div class="flex items-center gap-3 mb-4">
              <div class="h-px flex-1 bg-gray-100"></div>
              <span class="text-xs font-semibold text-gray-400 bg-gray-50 px-3 py-1 rounded-full">
                <?php
                $ts = strtotime($date);
                $today = strtotime(date('Y-m-d'));
                if ($ts===$today) echo 'Today';
                elseif ($ts===$today-86400) echo 'Yesterday';
                else echo date('d M Y',$ts);
                ?>
              </span>
              <div class="h-px flex-1 bg-gray-100"></div>
            </div>
            <!-- Activities for this date -->
            <div class="space-y-3">
              <?php foreach ($dayActs as $a):
                [$icon,$cls,$label] = activityMeta($a['action']);
              ?>
              <div class="flex items-start gap-4">
                <div class="w-9 h-9 <?= $cls ?> rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm">
                  <i class="<?= $icon ?> text-sm"></i>
                </div>
                <div class="flex-1 min-w-0 bg-gray-50 rounded-xl px-4 py-3">
                  <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-gray-800"><?= h($label) ?></p>
                    <span class="text-xs text-gray-400 flex-shrink-0 ml-2"><?= date('H:i', strtotime($a['created_at'])) ?></span>
                  </div>
                  <?php if ($a['description']): ?>
                  <p class="text-xs text-gray-500 mt-0.5 truncate"><?= h($a['description']) ?></p>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <div class="flex items-center justify-center gap-2 mt-8 pt-6 border-t">
          <?php if ($page>1): ?>
          <a href="?page=<?= $page-1 ?>" class="px-3 py-1.5 rounded-lg border text-sm text-gray-600 hover:bg-gray-50 transition-colors"><i class="ri-arrow-left-line"></i></a>
          <?php endif; ?>
          <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?>
          <a href="?page=<?= $i ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $i===$page?'bg-primary text-white':'border text-gray-600 hover:bg-gray-50' ?> transition-colors"><?= $i ?></a>
          <?php endfor; ?>
          <?php if ($page<$pages): ?>
          <a href="?page=<?= $page+1 ?>" class="px-3 py-1.5 rounded-lg border text-sm text-gray-600 hover:bg-gray-50 transition-colors"><i class="ri-arrow-right-line"></i></a>
          <?php endif; ?>
          <span class="text-xs text-gray-400 ml-2">Page <?= $page ?> of <?= $pages ?></span>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
