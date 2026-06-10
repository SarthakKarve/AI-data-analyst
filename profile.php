<?php
require __DIR__ . '/includes/auth_check.php';

function s($v): string { return trim((string)$v); }
function isStrongPassword(string $p): bool {
    return strlen($p)>=8 && preg_match('/[A-Z]/',$p) && preg_match('/[a-z]/',$p) && preg_match('/[0-9]/',$p) && preg_match('/[\W_]/',$p);
}
function verifyGoogleIdToken(string $idToken, string $clientId): ?array {
    $resp=@file_get_contents('https://oauth2.googleapis.com/tokeninfo?id_token='.urlencode($idToken));
    if (!$resp) return null;
    $data=json_decode($resp,true);
    if (!is_array($data)||($data['aud']??'')!==$clientId||($data['email_verified']??'false')!=='true') return null;
    return ['email'=>$data['email']??null,'given_name'=>$data['given_name']??null,'family_name'=>$data['family_name']??null];
}

$GOOGLE_CLIENT_ID = getenv('GOOGLE_CLIENT_ID') ?: 'YOUR_GOOGLE_CLIENT_ID';
$csrf   = csrfToken();
$errors = []; $success = '';

// Load profile
$user = fetchOne($pdo, "SELECT user_id,first_name,last_name,email,phone,address,dob,employment_status,annual_income,marital_status,profile_pic,google_login,password_hash,
    COALESCE(notify_email_pref,1) AS notify_email_pref, COALESCE(notify_sms_pref,0) AS notify_sms_pref,
    COALESCE(pref_ai_suggestions,1) AS pref_ai_suggestions, COALESCE(pref_tutorial_tips,1) AS pref_tutorial_tips
    FROM users WHERE user_id=?", [$userId]) ?: [];

$first        = $user['first_name']    ?? '';
$last         = $user['last_name']     ?? '';
$email        = $user['email']         ?? '';
$phone        = $user['phone']         ?? '';
$address      = $user['address']       ?? '';
$dob          = $user['dob']           ?? '';
$empStatus    = $user['employment_status'] ?? '';
$annualInc    = $user['annual_income'] ?? '';
$marital      = $user['marital_status'] ?? '';
$googleLinked = (int)($user['google_login']??0)===1;
$hasPassword  = !empty($user['password_hash']);
$notifyEmail  = (int)($user['notify_email_pref']??1);
$notifySms    = (int)($user['notify_sms_pref']??0);
$prefAi       = (int)($user['pref_ai_suggestions']??1);
$prefTips     = (int)($user['pref_tutorial_tips']??1);

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act=$_POST['action']??'';
    if (!csrfVerify($_POST['csrf']??'')) { $errors[]='Invalid CSRF token.'; }
    else {
        if ($act==='update_details') {
            $newFirst = s($_POST['first_name']??'');
            $newLast  = s($_POST['last_name']??'');
            $newEmail = s($_POST['email']??'');
            $newPhone = s($_POST['phone']??'');
            $newAddr  = s($_POST['address']??'');
            $newDob   = s($_POST['dob']??'');
            $newEmp   = s($_POST['employment_status']??'');
            $newInc   = (float)($_POST['annual_income']??0);
            $newMar   = s($_POST['marital_status']??'');
            if ($newFirst==='') $errors[]='First name is required.';
            if (!filter_var($newEmail,FILTER_VALIDATE_EMAIL)) $errors[]='Valid email is required.';
            if ($newPhone!==''&&!preg_match('/^\+?[0-9]{7,15}$/',$newPhone)) $errors[]='Valid phone required.';
            if (empty($errors)) {
                if (fetchOne($pdo,'SELECT user_id FROM users WHERE email=? AND user_id<>?',[$newEmail,$userId])) $errors[]='Email already in use.';
            }
            if (empty($errors)&&$newPhone!=='') {
                if (fetchOne($pdo,'SELECT user_id FROM users WHERE phone=? AND user_id<>?',[$newPhone,$userId])) $errors[]='Phone already in use.';
            }
            if (empty($errors)) {
                $pdo->prepare("UPDATE users SET first_name=?,last_name=?,email=?,phone=?,address=?,dob=?,employment_status=?,annual_income=?,marital_status=? WHERE user_id=?")
                    ->execute([$newFirst,$newLast,$newEmail,$newPhone?:null,$newAddr?:null,$newDob?:null,$newEmp?:null,$newInc?:null,$newMar?:null,$userId]);
                logActivity($pdo,$userId,'profile_updated','Updated profile details');
                $success='Profile details updated.';
                $first=$newFirst; $last=$newLast; $email=$newEmail; $phone=$newPhone; $address=$newAddr; $dob=$newDob; $empStatus=$newEmp; $annualInc=$newInc; $marital=$newMar;
            }
        } elseif ($act==='change_password') {
            $current = $_POST['current_password']??'';
            $new     = $_POST['new_password']??'';
            $confirm = $_POST['confirm_password']??'';
            $u=fetchOne($pdo,'SELECT password_hash FROM users WHERE user_id=?',[$userId]);
            if (!password_verify($current,$u['password_hash']??'')) $errors[]='Current password is incorrect.';
            if (!isStrongPassword($new)) $errors[]='New password: 8+ chars with uppercase, lowercase, number, and symbol.';
            if ($new!==$confirm) $errors[]='Passwords do not match.';
            if (empty($errors)) {
                $pdo->prepare('UPDATE users SET password_hash=? WHERE user_id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$userId]);
                logActivity($pdo,$userId,'password_changed','Changed account password');
                $success='Password changed successfully.';
            }
        } elseif ($act==='unlink_google') {
            $pdo->prepare('UPDATE users SET google_login=0 WHERE user_id=?')->execute([$userId]);
            $success='Google account unlinked.'; $googleLinked=false;
        } elseif ($act==='link_google') {
            $idToken=$_POST['id_token']??'';
            if (!$idToken) { $errors[]='Missing Google ID token.'; }
            else {
                $claims=verifyGoogleIdToken($idToken,$GOOGLE_CLIENT_ID);
                if (!$claims||!$claims['email']) $errors[]='Google verification failed.';
                elseif (strcasecmp($claims['email'],$email)!==0) $errors[]='Google email must match account email ('.h($email).').';
                else { $pdo->prepare('UPDATE users SET google_login=1 WHERE user_id=?')->execute([$userId]); $success='Google account linked.'; $googleLinked=true; }
            }
        } elseif ($act==='update_prefs') {
            $ne=isset($_POST['notify_email_pref'])?1:0;
            $ns=isset($_POST['notify_sms_pref'])?1:0;
            $ai=isset($_POST['pref_ai_suggestions'])?1:0;
            $tp=isset($_POST['pref_tutorial_tips'])?1:0;
            $pdo->prepare("UPDATE users SET notify_email_pref=?,notify_sms_pref=?,pref_ai_suggestions=?,pref_tutorial_tips=? WHERE user_id=?")->execute([$ne,$ns,$ai,$tp,$userId]);
            $success='Preferences saved.'; $notifyEmail=$ne; $notifySms=$ns; $prefAi=$ai; $prefTips=$tp;
        }
    }
}

$firstName = $first;
$highPriorityReminders = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status='High Priority'",[$userId]);
$pageTitle  = 'Profile';
$activePage = 'profile';
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

      <!-- Avatar + Name header -->
      <div class="bg-gradient-to-r from-primary to-purple-700 text-white rounded-2xl p-6 flex items-center gap-5">
        <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center text-3xl font-bold">
          <?= strtoupper(substr(h($first),0,1)) ?>
        </div>
        <div>
          <h2 class="text-2xl font-bold"><?= h($first.' '.$last) ?></h2>
          <p class="text-purple-200 text-sm mt-0.5"><?= h($email) ?></p>
          <div class="flex gap-2 mt-2">
            <?php if ($googleLinked): ?><span class="bg-white/20 text-white text-xs px-2 py-0.5 rounded-full font-medium"><i class="ri-google-fill mr-1"></i>Google linked</span><?php endif; ?>
            <?php if ($hasPassword): ?><span class="bg-white/20 text-white text-xs px-2 py-0.5 rounded-full font-medium"><i class="ri-lock-line mr-1"></i>Password login</span><?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Profile Details + Password -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Details Form -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
          <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="ri-user-3-line text-primary"></i>Personal Details</h3>
          <form method="post" class="space-y-3">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="update_details">
            <div class="grid grid-cols-2 gap-3">
              <div><label class="text-xs text-gray-500 mb-1 block">First Name *</label>
                <input type="text" name="first_name" value="<?= h($first) ?>" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Last Name</label>
                <input type="text" name="last_name" value="<?= h($last) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Email *</label>
                <input type="email" name="email" value="<?= h($email) ?>" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Phone</label>
                <input type="tel" name="phone" value="<?= h($phone) ?>" placeholder="+91XXXXXXXXXX" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Date of Birth</label>
                <input type="date" name="dob" value="<?= h($dob) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Marital Status</label>
                <select name="marital_status" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
                  <option value="">Select...</option>
                  <?php foreach(['Single','Married','Divorced','Widowed'] as $m): ?>
                  <option <?= $marital===$m?'selected':'' ?>><?= $m ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Employment Status</label>
                <input type="text" name="employment_status" value="<?= h($empStatus) ?>" placeholder="e.g., Salaried" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Annual Income (₹)</label>
                <input type="number" step="1000" name="annual_income" value="<?= h($annualInc) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
            </div>
            <div><label class="text-xs text-gray-500 mb-1 block">Address</label>
              <textarea name="address" rows="2" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary resize-none"><?= h($address) ?></textarea></div>
            <button type="submit" class="w-full py-2.5 bg-primary text-white rounded-lg font-medium hover:bg-purple-700 transition-colors text-sm">Save Details</button>
          </form>
        </div>

        <!-- Password + Google + Prefs -->
        <div class="space-y-5">
          <!-- Change Password -->
          <div class="bg-white rounded-xl shadow-sm border p-6">
            <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="ri-lock-password-line text-primary"></i>Change Password</h3>
            <form method="post" class="space-y-3">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="change_password">
              <div><label class="text-xs text-gray-500 mb-1 block">Current Password</label>
                <input type="password" name="current_password" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">New Password</label>
                <input type="password" name="new_password" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <div><label class="text-xs text-gray-500 mb-1 block">Confirm New Password</label>
                <input type="password" name="confirm_password" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
              <p class="text-xs text-gray-400">Min 8 chars, must include uppercase, lowercase, number, and symbol.</p>
              <button type="submit" class="w-full py-2.5 bg-gray-800 text-white rounded-lg font-medium hover:bg-gray-900 transition-colors text-sm">Update Password</button>
            </form>
          </div>

          <!-- Google Linking -->
          <div class="bg-white rounded-xl shadow-sm border p-6">
            <h3 class="font-semibold text-gray-800 mb-3 flex items-center gap-2"><i class="ri-google-fill text-primary"></i>Google Account</h3>
            <?php if ($googleLinked): ?>
            <div class="flex items-center justify-between p-3 bg-green-50 rounded-xl border border-green-200 mb-3">
              <div class="flex items-center gap-2"><i class="ri-checkbox-circle-line text-green-600"></i><span class="text-sm text-green-700 font-medium">Google account linked</span></div>
            </div>
            <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="unlink_google">
              <button type="submit" class="w-full py-2 rounded-lg border border-red-300 text-red-600 text-sm hover:bg-red-50 transition-colors">Unlink Google</button>
            </form>
            <?php else: ?>
            <p class="text-sm text-gray-500 mb-3">Link your Google account for one-click sign-in.</p>
            <div id="g_id_onload" data-client_id="<?= h($GOOGLE_CLIENT_ID) ?>" data-callback="handleGoogleLink" data-auto_prompt="false"></div>
            <div class="g_id_signin" data-type="standard" data-size="large" data-theme="outline" data-text="signin_with" data-shape="rectangular" data-logo_alignment="left"></div>
            <script src="https://accounts.google.com/gsi/client" async defer></script>
            <form id="linkGoogleForm" method="post" class="hidden"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="link_google"><input type="hidden" name="id_token" id="g_link_token"></form>
            <script>function handleGoogleLink(r){ document.getElementById('g_link_token').value=r.credential; document.getElementById('linkGoogleForm').submit(); }</script>
            <?php endif; ?>
          </div>

          <!-- Preferences -->
          <div class="bg-white rounded-xl shadow-sm border p-6">
            <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="ri-settings-3-line text-primary"></i>Preferences</h3>
            <form method="post" class="space-y-3">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="update_prefs">
              <?php
              $prefs = [
                ['notify_email_pref','Email Notifications','Receive reminder alerts by email',$notifyEmail],
                ['notify_sms_pref','SMS Notifications','Receive reminder alerts by SMS',$notifySms],
                ['pref_ai_suggestions','AI Score Suggestions','Show AI-powered improvement tips',$prefAi],
                ['pref_tutorial_tips','Tutorial Tips','Show tutorial hints on dashboard',$prefTips],
              ];
              foreach ($prefs as [$name,$label,$desc,$val]):
              ?>
              <label class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 cursor-pointer transition-colors">
                <div>
                  <p class="text-sm font-medium text-gray-800"><?= $label ?></p>
                  <p class="text-xs text-gray-400 mt-0.5"><?= $desc ?></p>
                </div>
                <div class="relative flex-shrink-0 ml-4">
                  <input type="checkbox" name="<?= $name ?>" <?= $val?'checked':'' ?> class="sr-only peer">
                  <div class="w-10 h-5 bg-gray-200 peer-checked:bg-primary rounded-full transition-colors"></div>
                  <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
                </div>
              </label>
              <?php endforeach; ?>
              <button type="submit" class="w-full py-2.5 bg-primary text-white rounded-lg font-medium hover:bg-purple-700 transition-colors text-sm mt-2">Save Preferences</button>
            </form>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
