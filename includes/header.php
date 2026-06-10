<?php
/**
 * Shared top header bar.
 * Requires: $pageTitle (string), $firstName (string), $highPriorityReminders (int)
 * All three should be set before including this file.
 */
$_notifyCount = (int)($highPriorityReminders ?? 0);
?>
<header class="bg-gray-800 text-white px-8 py-4 flex justify-between items-center shadow-lg sticky top-0 z-10">
  <div>
    <h1 class="text-base font-semibold text-white"><?= h($pageTitle ?? 'Dashboard') ?></h1>
    <p class="text-xs text-gray-400">Credit Health Management System</p>
  </div>
  <div class="flex items-center gap-4">
    <!-- Notification bell -->
    <a href="reminders.php" class="relative w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-700 transition-colors">
      <i class="ri-notification-3-line text-lg"></i>
      <?php if ($_notifyCount > 0): ?>
      <span class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 rounded-full text-xs flex items-center justify-center font-bold">
        <?= $_notifyCount > 9 ? '9+' : $_notifyCount ?>
      </span>
      <?php endif; ?>
    </a>
    <!-- User avatar -->
    <a href="profile.php" class="flex items-center gap-2 hover:bg-gray-700 px-3 py-1.5 rounded-lg transition-colors">
      <div class="w-7 h-7 bg-primary rounded-full flex items-center justify-center text-xs font-bold">
        <?= strtoupper(substr(h($firstName ?? 'U'), 0, 1)) ?>
      </div>
      <span class="text-sm"><?= h($firstName ?? 'User') ?></span>
    </a>
  </div>
</header>
