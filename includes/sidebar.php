<?php
/**
 * Shared sidebar navigation.
 * Requires $activePage (string) to be set before including this file.
 * Example: $activePage = 'dashboard';
 */
$_nav = [
    'main' => [
        ['page' => 'dashboard',  'href' => 'index.php',     'icon' => 'ri-dashboard-3-line',   'label' => 'Dashboard'],
        ['page' => 'loans',      'href' => 'loans.php',      'icon' => 'ri-bank-line',           'label' => 'Loans'],
        ['page' => 'predictor',  'href' => 'predictor.php',  'icon' => 'ri-line-chart-line',     'label' => 'CIBIL Predictor'],
        ['page' => 'insurance',  'href' => 'insurance.php',  'icon' => 'ri-shield-check-line',   'label' => 'Insurance'],
    ],
    'tools' => [
        ['page' => 'chatbot',    'href' => 'chatbot.php',    'icon' => 'ri-robot-2-line',        'label' => 'ChatBot'],
        ['page' => 'reminders',  'href' => 'reminders.php',  'icon' => 'ri-notification-3-line', 'label' => 'Reminders'],
        ['page' => 'documents',  'href' => 'documents.php',  'icon' => 'ri-folder-3-line',       'label' => 'Documents'],
    ],
    'insights' => [
        ['page' => 'insights',   'href' => 'insights.php',   'icon' => 'ri-lightbulb-line',      'label' => 'My Insights'],
        ['page' => 'goals',      'href' => 'goals.php',      'icon' => 'ri-focus-3-line',        'label' => 'Goals'],
        ['page' => 'health',     'href' => 'health.php',     'icon' => 'ri-heart-pulse-line',    'label' => 'Health Score'],
    ],
    'learn' => [
        ['page' => 'tutorials',  'href' => 'tutorials.php',  'icon' => 'ri-graduation-cap-line', 'label' => 'Tutorials'],
        ['page' => 'activity',   'href' => 'activity.php',   'icon' => 'ri-history-line',        'label' => 'Activity Log'],
    ],
];
$_groupLabels = ['main' => 'Main Menu', 'tools' => 'Tools', 'insights' => 'Insights', 'learn' => 'Learn'];
$_activePage  = $activePage ?? '';
?>
<div class="w-64 bg-gradient-to-b from-gray-900 to-gray-800 shadow-2xl h-screen fixed left-0 top-0 border-r border-gray-700 flex flex-col overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:#4b5563 transparent">
  <div class="p-5 border-b border-gray-700 flex-shrink-0">
    <div class="flex items-center gap-3">
      <div class="w-8 h-8 bg-primary rounded-lg flex items-center justify-center flex-shrink-0">
        <i class="ri-store-2-line text-white text-sm"></i>
      </div>
      <div>
        <h2 class="text-sm font-bold text-white leading-tight">Credit Health</h2>
        <p class="text-xs text-gray-400">Management System</p>
      </div>
    </div>
  </div>
  <nav class="flex-1 py-4 px-3">
    <?php foreach ($_nav as $_group => $_items): ?>
    <div class="mb-5">
      <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2 px-3"><?= h($_groupLabels[$_group]) ?></p>
      <div class="space-y-0.5">
        <?php foreach ($_items as $_item):
          $_isActive = ($_item['page'] === $_activePage);
        ?>
        <a href="<?= h($_item['href']) ?>"
           class="<?= $_isActive ? 'bg-primary/20 text-primary border border-primary/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?> rounded-lg px-4 py-2.5 flex items-center gap-3 transition-all duration-200 relative text-sm">
          <div class="w-4 h-4 flex items-center justify-center flex-shrink-0">
            <i class="<?= h($_item['icon']) ?> text-base"></i>
          </div>
          <span class="font-<?= $_isActive ? 'medium' : 'normal' ?>"><?= h($_item['label']) ?></span>
          <?php if ($_isActive): ?>
          <div class="absolute right-1 top-1/2 -translate-y-1/2 w-1 h-5 bg-primary rounded-full"></div>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </nav>
  <div class="flex-shrink-0 border-t border-gray-700 px-3 py-3 space-y-0.5">
    <?php $_profileActive = ($_activePage === 'profile'); ?>
    <a href="profile.php" class="<?= $_profileActive ? 'bg-primary/20 text-primary border border-primary/30' : 'text-gray-300 hover:bg-gray-700/50 hover:text-white' ?> rounded-lg px-4 py-2.5 flex items-center gap-3 transition-all duration-200 text-sm">
      <div class="w-4 h-4 flex items-center justify-center"><i class="ri-user-3-line text-base"></i></div>
      <span>Profile</span>
    </a>
    <a href="logout.php" class="text-gray-300 hover:bg-red-900/30 hover:text-red-300 rounded-lg px-4 py-2.5 flex items-center gap-3 transition-all duration-200 text-sm">
      <div class="w-4 h-4 flex items-center justify-center"><i class="ri-logout-circle-r-line text-base"></i></div>
      <span>Logout</span>
    </a>
  </div>
</div>
