<?php
require __DIR__ . '/includes/auth_check.php';

$type = strtolower(trim($_GET['type'] ?? 'all'));
if (!in_array($type,['video','audio','all'],true)) $type='all';

// Seed tutorials if table is empty
$count = (int)fetchValue($pdo,"SELECT COUNT(*) FROM tutorials_library",[]);
if ($count === 0) {
    $seeds = [
        ['Video','What is a CIBIL Score?','Understand what a CIBIL score is and why it matters to lenders.','#'],
        ['Video','How to Improve Your Score','5 proven strategies to boost your score in 3–6 months.','#'],
        ['Video','Understanding Loan EMIs','How EMI is calculated and how to plan repayments.','#'],
        ['Video','Credit Utilization Explained','Why keeping usage below 30% is the key to a good score.','#'],
        ['Audio','Managing Multiple Loans','Expert tips for juggling multiple loan accounts.','#'],
        ['Audio','Debt Consolidation Basics','When and how to consolidate debt to reduce EMI burden.','#'],
        ['Audio','Building Credit from Scratch','Practical steps for first-time borrowers.','#'],
        ['Audio','Emergency Fund vs Loan Prepayment','When to save and when to pay off debt.','#'],
    ];
    $ins=$pdo->prepare("INSERT IGNORE INTO tutorials_library (type,title,description,url) VALUES (?,?,?,?)");
    foreach ($seeds as $s) $ins->execute($s);
}

// Fetch tutorials
$where = $type==='all' ? '' : "WHERE type=?";
$params = $type==='all' ? [] : [ucfirst($type)];
$tutorials = fetchAll($pdo, "SELECT * FROM tutorials_library $where ORDER BY type, tutorial_id ASC", $params);

$videoTuts = array_filter($tutorials, fn($t)=>$t['type']==='Video');
$audioTuts = array_filter($tutorials, fn($t)=>$t['type']==='Audio');

$userRow   = fetchOne($pdo,"SELECT first_name FROM users WHERE user_id=?",[$userId]);
$firstName = $userRow['first_name'] ?? 'User';
$highPriorityReminders = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status='High Priority'",[$userId]);

$pageTitle  = 'Tutorials & Learning';
$activePage = 'tutorials';

$tips = [
    ['icon'=>'ri-time-line','title'=>'Pay on Time, Every Time','body'=>"Payment history accounts for 35% of your CIBIL score. Even one missed payment can drop your score by 50–100 points."],
    ['icon'=>'ri-percent-line','title'=>'Keep Utilization Below 30%','body'=>"If your total credit limit is ₹5L, keep your balance under ₹1.5L. High utilization signals financial stress."],
    ['icon'=>'ri-calendar-check-line','title'=>'Don\'t Close Old Accounts','body'=>"Credit age matters (15%). Closing an old credit card shortens your history — keep it open even if unused."],
    ['icon'=>'ri-search-line','title'=>'Limit Hard Inquiries','body'=>"Each loan application triggers a hard inquiry. Multiple applications in a short span lower your score. Space them out."],
    ['icon'=>'ri-shuffle-line','title'=>'Diversify Your Credit Mix','body'=>"Having a mix of secured loans (home, auto) and unsecured loans (personal, credit card) improves your score."],
    ['icon'=>'ri-arrow-down-line','title'=>'Reduce Outstanding Balances','body'=>"Actively paying down balances — even above the minimum — shows responsible credit behaviour."],
    ['icon'=>'ri-file-list-3-line','title'=>'Check Your Report Regularly','body'=>"Errors in credit reports are common. Monitor your report every 6 months and dispute inaccuracies promptly."],
    ['icon'=>'ri-bank-card-line','title'=>'Use a Secured Credit Card','body'=>"If you have no credit history, a secured credit card (backed by a fixed deposit) is the easiest way to start."],
    ['icon'=>'ri-hand-coin-line','title'=>'Prepay When Possible','body'=>"Making part-prepayments reduces the principal faster, lowers total interest paid, and improves your debt ratio."],
    ['icon'=>'ri-robot-2-line','title'=>'Set Up Autopay','body'=>"Never miss a due date. Autopay for at least the minimum amount ensures your payment history stays clean."],
];
?>
<?php require __DIR__ . '/includes/page_head.php'; ?>
<div class="flex">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <div class="ml-64 flex-1 min-h-screen">
    <?php require __DIR__ . '/includes/header.php'; ?>
    <div class="p-8 space-y-8 fade-in">

      <!-- Hero Banner -->
      <div class="bg-gradient-to-br from-blue-600 to-indigo-700 text-white rounded-2xl p-8">
        <div class="max-w-2xl">
          <p class="text-blue-200 text-sm font-medium mb-1">Learn & Grow</p>
          <h2 class="text-3xl font-bold mb-3">Credit Education Hub</h2>
          <p class="text-blue-100 leading-relaxed">Everything you need to understand your CIBIL score, manage loans wisely, and build a strong credit profile.</p>
        </div>
      </div>

      <!-- Tab Filter -->
      <div class="flex items-center gap-1 bg-gray-100 rounded-xl p-1 w-fit">
        <?php foreach(['all'=>'All','video'=>'Videos','audio'=>'Audio Guides'] as $tk=>$tl): ?>
        <a href="?type=<?= $tk ?>" class="px-5 py-2 rounded-lg text-sm font-medium transition-all <?= $type===$tk ? 'bg-white text-primary shadow-sm' : 'text-gray-500 hover:text-gray-700' ?>"><?= $tl ?></a>
        <?php endforeach; ?>
      </div>

      <!-- Video Tutorials -->
      <?php if ($type==='all'||$type==='video'): ?>
      <div>
        <div class="flex items-center gap-3 mb-5">
          <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center"><i class="ri-play-circle-line text-blue-600 text-lg"></i></div>
          <div><h3 class="font-bold text-gray-800 text-lg">Video Tutorials</h3><p class="text-sm text-gray-400">Watch and learn at your own pace</p></div>
        </div>
        <?php if (empty($videoTuts)): ?>
        <div class="bg-white rounded-xl shadow-sm border p-8 text-center text-gray-400">No video tutorials available yet.</div>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
          <?php foreach ($videoTuts as $i=>$t): ?>
          <div class="bg-white rounded-xl shadow-sm border hover:shadow-md transition-all card-hover overflow-hidden">
            <div class="h-28 bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center relative">
              <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center">
                <i class="ri-play-fill text-white text-2xl ml-1"></i>
              </div>
              <span class="absolute top-2 right-2 bg-black/40 text-white text-xs px-2 py-0.5 rounded-full">Video</span>
            </div>
            <div class="p-4">
              <p class="font-semibold text-gray-800 text-sm leading-tight"><?= h($t['title']) ?></p>
              <p class="text-xs text-gray-500 mt-1.5 line-clamp-2"><?= h($t['description']) ?></p>
              <a href="<?= h($t['url']) ?>" target="_blank" rel="noopener" class="mt-3 flex items-center gap-1 text-xs text-blue-600 font-medium hover:underline">
                <i class="ri-external-link-line"></i>Watch Now
              </a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Audio Guides -->
      <?php if ($type==='all'||$type==='audio'): ?>
      <div>
        <div class="flex items-center gap-3 mb-5">
          <div class="w-10 h-10 bg-green-100 rounded-xl flex items-center justify-center"><i class="ri-headphone-line text-green-600 text-lg"></i></div>
          <div><h3 class="font-bold text-gray-800 text-lg">Audio Guides</h3><p class="text-sm text-gray-400">Listen on the go</p></div>
        </div>
        <?php if (empty($audioTuts)): ?>
        <div class="bg-white rounded-xl shadow-sm border p-8 text-center text-gray-400">No audio guides available yet.</div>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <?php foreach ($audioTuts as $t): ?>
          <a href="<?= h($t['url']) ?>" target="_blank" rel="noopener" class="bg-white rounded-xl shadow-sm border p-5 flex items-center gap-4 hover:border-green-300 hover:shadow-md transition-all card-hover">
            <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center flex-shrink-0">
              <i class="ri-headphone-line text-green-600 text-xl"></i>
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-semibold text-gray-800 text-sm"><?= h($t['title']) ?></p>
              <p class="text-xs text-gray-500 mt-0.5 line-clamp-2"><?= h($t['description']) ?></p>
            </div>
            <i class="ri-external-link-line text-gray-400 flex-shrink-0"></i>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Credit Tips -->
      <?php if ($type==='all'): ?>
      <div>
        <div class="flex items-center gap-3 mb-5">
          <div class="w-10 h-10 bg-purple-100 rounded-xl flex items-center justify-center"><i class="ri-lightbulb-flash-line text-purple-600 text-lg"></i></div>
          <div><h3 class="font-bold text-gray-800 text-lg">Top 10 Credit Tips</h3><p class="text-sm text-gray-400">Actionable advice to improve your score</p></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <?php foreach ($tips as $i=>$tip): ?>
          <div class="bg-white rounded-xl shadow-sm border p-5 flex items-start gap-4 card-hover">
            <div class="w-10 h-10 bg-primary/10 rounded-xl flex items-center justify-center flex-shrink-0">
              <i class="<?= h($tip['icon']) ?> text-primary text-base"></i>
            </div>
            <div>
              <div class="flex items-center gap-2 mb-1">
                <span class="text-xs font-bold text-gray-400">#{<?= $i+1 ?>}</span>
                <p class="font-semibold text-gray-800 text-sm"><?= h($tip['title']) ?></p>
              </div>
              <p class="text-xs text-gray-500 leading-relaxed"><?= h($tip['body']) ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
