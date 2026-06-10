<?php
session_start();
if (isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
require __DIR__ . '/db.php';

$ENABLE_PHONE_OTP = true;

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function sanitize($s) { return trim($s ?? ''); }
function isStrongPassword($pwd) {
    return strlen($pwd) >= 8
        && preg_match('/[A-Z]/', $pwd)
        && preg_match('/[a-z]/', $pwd)
        && preg_match('/[0-9]/', $pwd)
        && preg_match('/[\W_]/', $pwd);
}
function findUserByEmail(PDO $pdo, $e) { $s=$pdo->prepare('SELECT user_id FROM users WHERE email=?');$s->execute([$e]);return $s->fetch(); }
function findUserByPhone(PDO $pdo, $p) { $s=$pdo->prepare('SELECT user_id FROM users WHERE phone=?');$s->execute([$p]);return $s->fetch(); }

$errors      = [];
$success     = '';
$showOtpForm = false;
$formData    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'register';

    if ($action === 'register') {
        $formData = [
            'first_name' => sanitize($_POST['first_name'] ?? ''),
            'last_name'  => sanitize($_POST['last_name']  ?? ''),
            'phone'      => sanitize($_POST['phone']      ?? ''),
            'email'      => sanitize($_POST['email']      ?? ''),
            'address'    => sanitize($_POST['address']    ?? ''),
        ];
        $password = $_POST['password'] ?? '';

        if ($formData['first_name'] === '') $errors[] = 'First name is required.';
        if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!preg_match('/^\+?[0-9]{7,15}$/', $formData['phone'])) $errors[] = 'Valid phone number is required (7–15 digits).';
        if (!isStrongPassword($password)) $errors[] = 'Password must be 8+ characters with uppercase, lowercase, number, and symbol.';

        if (empty($errors)) {
            if (findUserByEmail($pdo, $formData['email'])) $errors[] = 'This email is already registered.';
            if (findUserByPhone($pdo, $formData['phone']))  $errors[] = 'This phone number is already registered.';
        }

        if (empty($errors)) {
            if ($ENABLE_PHONE_OTP) {
                $_SESSION['reg_otp']      = random_int(100000, 999999);
                $_SESSION['otp_sent_to']  = $formData['phone'];
                $_SESSION['pending_user'] = array_merge($formData, ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                $showOtpForm = true;
            } else {
                try {
                    $pdo->prepare('INSERT INTO users (first_name, last_name, email, phone, password_hash, address, google_login) VALUES (?,?,?,?,?,?,0)')
                        ->execute([$formData['first_name'], $formData['last_name'], $formData['email'], $formData['phone'], password_hash($password, PASSWORD_DEFAULT), $formData['address']]);
                    $success = 'Account created successfully! You can now sign in.';
                } catch (PDOException $e) { $errors[] = 'Registration failed. Please try again.'; }
            }
        }

    } elseif ($action === 'verify_otp') {
        $showOtpForm = true;
        $userOtp = trim($_POST['otp'] ?? '');
        if (!isset($_SESSION['reg_otp'], $_SESSION['pending_user'])) {
            $errors[] = 'Session expired. Please register again.';
            $showOtpForm = false;
        } elseif ($userOtp === (string)$_SESSION['reg_otp']) {
            $u = $_SESSION['pending_user'];
            try {
                $pdo->prepare('INSERT INTO users (first_name, last_name, email, phone, password_hash, address, google_login) VALUES (?,?,?,?,?,?,0)')
                    ->execute([$u['first_name'], $u['last_name'], $u['email'], $u['phone'], $u['password_hash'], $u['address']]);
                $success = 'Phone verified! Your account is ready. Sign in to continue.';
                unset($_SESSION['reg_otp'], $_SESSION['pending_user'], $_SESSION['otp_sent_to']);
                $showOtpForm = false;
            } catch (PDOException $e) { $errors[] = 'Registration failed. Please try again.'; }
        } else {
            $errors[] = 'Incorrect OTP. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Create Account — CIBIL Manager</title>
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
  <script>
    tailwind.config = {
      theme: { extend: { colors: { primary: '#7c3aed' }, fontFamily: { sans: ['Inter','sans-serif'] } } }
    };
  </script>
  <style>
    body { font-family: 'Inter', sans-serif; }
    .input-field {
      width: 100%; padding: 0.625rem 1rem;
      border: 1px solid #e5e7eb; border-radius: 0.75rem;
      font-size: 0.875rem; outline: none; transition: border-color .15s, box-shadow .15s;
    }
    .input-field:focus { border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,.1); }
    .fade-in { animation: fadeIn .4s ease; }
    @keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }
    .otp-input { letter-spacing: .5em; text-align: center; font-size: 1.5rem; font-weight: 700; }
  </style>
</head>
<body class="bg-gray-50 min-h-screen flex">

  <!-- Left branding panel -->
  <div class="hidden lg:flex lg:w-2/5 bg-gradient-to-br from-violet-600 via-purple-700 to-indigo-800 flex-col justify-between p-12 relative overflow-hidden">
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div class="absolute -top-24 -right-24 w-96 h-96 bg-white/5 rounded-full"></div>
      <div class="absolute -bottom-32 -left-16 w-80 h-80 bg-white/5 rounded-full"></div>
    </div>
    <div class="relative">
      <div class="flex items-center gap-3 mb-12">
        <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center">
          <i class="ri-bar-chart-box-line text-white text-xl"></i>
        </div>
        <span class="text-white text-xl font-bold">CIBIL Manager</span>
      </div>
      <h1 class="text-3xl font-extrabold text-white leading-tight mb-4">
        Start your journey to a better credit score
      </h1>
      <p class="text-violet-200 leading-relaxed mb-10">
        Join thousands of users who track, manage, and improve their CIBIL score with ease.
      </p>
      <!-- Steps -->
      <div class="space-y-5">
        <?php foreach ([
          ['1', 'Create your account',    'Takes less than 2 minutes'],
          ['2', 'Add your loans',          'Connect your existing loan data'],
          ['3', 'Get your health score',   'See your full financial picture'],
          ['4', 'Follow the action plan',  'AI-driven steps to reach your goal'],
        ] as [$num, $title, $sub]): ?>
        <div class="flex items-center gap-4">
          <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center flex-shrink-0">
            <span class="text-white text-xs font-bold"><?= $num ?></span>
          </div>
          <div>
            <p class="text-white font-semibold text-sm"><?= $title ?></p>
            <p class="text-violet-300 text-xs"><?= $sub ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="relative bg-white/10 rounded-2xl p-5 border border-white/20">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center flex-shrink-0">
          <i class="ri-user-smile-line text-white text-base"></i>
        </div>
        <div>
          <p class="text-white text-sm font-medium">Free forever for personal use</p>
          <p class="text-violet-300 text-xs">No credit card required</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Right form panel -->
  <div class="w-full lg:w-3/5 flex items-center justify-center p-6 sm:p-10 overflow-y-auto">
    <div class="w-full max-w-lg fade-in">

      <!-- Mobile logo -->
      <div class="flex items-center gap-2 mb-8 lg:hidden">
        <div class="w-8 h-8 bg-primary rounded-lg flex items-center justify-center">
          <i class="ri-bar-chart-box-line text-white text-sm"></i>
        </div>
        <span class="text-gray-800 font-bold text-lg">CIBIL Manager</span>
      </div>

      <?php if ($showOtpForm && !$success): ?>
      <!-- OTP Verification -->
      <div class="text-center mb-8">
        <div class="w-16 h-16 bg-violet-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
          <i class="ri-smartphone-line text-primary text-2xl"></i>
        </div>
        <h2 class="text-2xl font-bold text-gray-900 mb-1">Verify Your Phone</h2>
        <p class="text-gray-500 text-sm">
          We sent a 6-digit code to <span class="font-semibold text-gray-700"><?= h($_SESSION['otp_sent_to'] ?? 'your phone') ?></span>
        </p>
        <?php if (isset($_SESSION['reg_otp'])): ?>
        <div class="mt-3 inline-flex items-center gap-2 bg-amber-50 border border-amber-200 text-amber-700 rounded-xl px-4 py-2 text-sm">
          <i class="ri-information-line"></i>
          Demo mode — OTP is <strong><?= h($_SESSION['reg_otp']) ?></strong>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!empty($errors)): ?>
      <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 mb-6">
        <?php foreach ($errors as $e): ?>
        <div class="flex items-center gap-2 text-sm text-red-700"><i class="ri-error-warning-line flex-shrink-0"></i><?= h($e) ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="post" class="space-y-5">
        <input type="hidden" name="action" value="verify_otp" />
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5 text-center">Enter 6-Digit OTP</label>
          <input type="text" name="otp" pattern="\d{6}" maxlength="6" required placeholder="••••••"
            class="input-field otp-input tracking-widest" autocomplete="one-time-code" />
        </div>
        <button type="submit"
          class="w-full py-3 bg-primary text-white rounded-xl font-semibold hover:bg-purple-700 active:scale-[.98] transition-all flex items-center justify-center gap-2">
          <i class="ri-shield-check-line"></i> Verify & Create Account
        </button>
      </form>
      <p class="text-center text-sm text-gray-500 mt-4">
        <a href="register.php" class="text-primary hover:underline font-medium">Start over</a>
      </p>

      <?php elseif ($success): ?>
      <!-- Success state -->
      <div class="text-center">
        <div class="w-20 h-20 bg-green-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
          <i class="ri-checkbox-circle-line text-green-500 text-4xl"></i>
        </div>
        <h2 class="text-2xl font-bold text-gray-900 mb-2">Account Created!</h2>
        <p class="text-gray-500 mb-8"><?= h($success) ?></p>
        <a href="login.php"
          class="inline-flex items-center gap-2 px-8 py-3 bg-primary text-white rounded-xl font-semibold hover:bg-purple-700 transition-colors">
          <i class="ri-login-circle-line"></i> Sign In Now
        </a>
      </div>

      <?php else: ?>
      <!-- Registration form -->
      <h2 class="text-2xl font-bold text-gray-900 mb-1">Create your account</h2>
      <p class="text-gray-500 text-sm mb-8">Fill in your details to get started — it's free</p>

      <?php if (!empty($errors)): ?>
      <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 mb-6">
        <?php foreach ($errors as $e): ?>
        <div class="flex items-center gap-2 text-sm text-red-700"><i class="ri-error-warning-line flex-shrink-0"></i><?= h($e) ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="post" novalidate class="space-y-5">
        <input type="hidden" name="action" value="register" />

        <!-- Name row -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">First Name <span class="text-red-400">*</span></label>
            <div class="relative">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><i class="ri-user-line text-base"></i></span>
              <input type="text" name="first_name" required placeholder="Rahul"
                value="<?= h($formData['first_name'] ?? '') ?>"
                class="input-field pl-9" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Last Name</label>
            <input type="text" name="last_name" placeholder="Sharma"
              value="<?= h($formData['last_name'] ?? '') ?>"
              class="input-field" />
          </div>
        </div>

        <!-- Email -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Email Address <span class="text-red-400">*</span></label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><i class="ri-mail-line text-base"></i></span>
            <input type="email" name="email" required placeholder="you@example.com"
              value="<?= h($formData['email'] ?? '') ?>"
              class="input-field pl-9" />
          </div>
        </div>

        <!-- Phone -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Phone Number <span class="text-red-400">*</span></label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><i class="ri-smartphone-line text-base"></i></span>
            <input type="tel" name="phone" required placeholder="+919876543210"
              value="<?= h($formData['phone'] ?? '') ?>"
              class="input-field pl-9" />
          </div>
        </div>

        <!-- Password -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Password <span class="text-red-400">*</span></label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><i class="ri-lock-line text-base"></i></span>
            <input type="password" name="password" id="passwordInput" required placeholder="Min 8 chars, upper, lower, number, symbol"
              class="input-field pl-9 pr-10" />
            <button type="button" id="togglePwd" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
              <i class="ri-eye-off-line text-base" id="eyeIcon"></i>
            </button>
          </div>
          <!-- Password strength indicators -->
          <div class="flex gap-1.5 mt-2" id="strengthBars">
            <div class="h-1 flex-1 rounded-full bg-gray-200" id="bar1"></div>
            <div class="h-1 flex-1 rounded-full bg-gray-200" id="bar2"></div>
            <div class="h-1 flex-1 rounded-full bg-gray-200" id="bar3"></div>
            <div class="h-1 flex-1 rounded-full bg-gray-200" id="bar4"></div>
          </div>
          <p class="text-xs text-gray-400 mt-1" id="strengthLabel">Enter a password</p>
        </div>

        <!-- Address -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Address <span class="text-gray-400 font-normal">(optional)</span></label>
          <div class="relative">
            <span class="absolute left-3 top-3 text-gray-400"><i class="ri-map-pin-line text-base"></i></span>
            <textarea name="address" rows="2" placeholder="Your residential address"
              class="input-field pl-9 resize-none"><?= h($formData['address'] ?? '') ?></textarea>
          </div>
        </div>

        <button type="submit"
          class="w-full py-3 bg-primary text-white rounded-xl font-semibold hover:bg-purple-700 active:scale-[.98] transition-all flex items-center justify-center gap-2">
          <i class="ri-user-add-line"></i> Create Account
        </button>
      </form>

      <p class="text-center text-sm text-gray-500 mt-6">
        Already have an account?
        <a href="login.php" class="text-primary font-semibold hover:underline ml-1">Sign in</a>
      </p>
      <?php endif; ?>

    </div>
  </div>

<script>
// Password toggle
var pwd = document.getElementById('passwordInput');
var eye = document.getElementById('eyeIcon');
var toggleBtn = document.getElementById('togglePwd');
if (toggleBtn) {
  toggleBtn.addEventListener('click', function () {
    if (pwd.type === 'password') { pwd.type = 'text'; eye.className = 'ri-eye-line text-base'; }
    else { pwd.type = 'password'; eye.className = 'ri-eye-off-line text-base'; }
  });
}

// Password strength meter
if (pwd) {
  pwd.addEventListener('input', function () {
    var v = this.value;
    var score = 0;
    if (v.length >= 8) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[\W_]/.test(v)) score++;
    var colors = ['bg-red-400','bg-orange-400','bg-yellow-400','bg-green-500'];
    var labels = ['Too weak','Weak','Fair','Strong'];
    var bars = ['bar1','bar2','bar3','bar4'];
    bars.forEach(function (id, i) {
      var el = document.getElementById(id);
      el.className = 'h-1 flex-1 rounded-full ' + (i < score ? colors[score - 1] : 'bg-gray-200');
    });
    document.getElementById('strengthLabel').textContent = v.length ? labels[score - 1] || 'Too weak' : 'Enter a password';
  });
}
</script>
</body>
</html>
