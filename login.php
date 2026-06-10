<?php
session_start();
if (isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
require __DIR__ . '/db.php';

$GOOGLE_CLIENT_ID = 'YOUR_GOOGLE_CLIENT_ID';
$errors  = [];
$success = '';

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function findUserByEmail(PDO $pdo, $email) {
    $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    return $st->fetch();
}
function verifyGoogleIdToken($idToken, $clientId) {
    $resp = @file_get_contents('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
    if (!$resp) return null;
    $data = json_decode($resp, true);
    if (!is_array($data) || ($data['aud'] ?? '') !== $clientId || ($data['email_verified'] ?? '') !== 'true') return null;
    return ['email'=>$data['email']??null,'given_name'=>$data['given_name']??null,'family_name'=>$data['family_name']??null,'picture'=>$data['picture']??null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'password_login') {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if ($password === '') $errors[] = 'Password is required.';
        if (empty($errors)) {
            $user = findUserByEmail($pdo, $email);
            if (!$user || !password_verify($password, $user['password_hash'])) {
                $errors[] = 'Invalid email or password.';
            } else {
                $_SESSION['user_id'] = $user['user_id'];
                header('Location: index.php'); exit;
            }
        }
    } elseif ($action === 'google') {
        $idToken = $_POST['id_token'] ?? '';
        $claims  = $idToken ? verifyGoogleIdToken($idToken, $GOOGLE_CLIENT_ID) : null;
        if (!$claims || !$claims['email']) {
            $errors[] = 'Google sign-in verification failed.';
        } else {
            $user = findUserByEmail($pdo, $claims['email']);
            if (!$user) {
                $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                try {
                    $pdo->prepare('INSERT INTO users (first_name, last_name, email, phone, password_hash, address, google_login, profile_pic) VALUES (?,?,?,NULL,?,NULL,1,?)')
                        ->execute([$claims['given_name']??'', $claims['family_name']??'', $claims['email'], $hash, $claims['picture']??null]);
                    $_SESSION['user_id'] = (int)$pdo->lastInsertId();
                    header('Location: index.php'); exit;
                } catch (PDOException $e) { $errors[] = 'Account creation failed.'; }
            } else {
                $_SESSION['user_id'] = $user['user_id'];
                header('Location: index.php'); exit;
            }
        }
    }
}

$emailVal = h(trim($_POST['email'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login — CIBIL Manager</title>
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
  </style>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <script>
    function handleCredentialResponse(response) {
      const fd = new FormData();
      fd.append('action','google');
      fd.append('id_token', response.credential);
      fetch('login.php', { method:'POST', body:fd })
        .then(r => r.text())
        .then(html => { document.open(); document.write(html); document.close(); })
        .catch(err => alert('Google sign-in failed: ' + err));
    }
  </script>
</head>
<body class="bg-gray-50 min-h-screen flex">

  <!-- Left branding panel -->
  <div class="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-violet-600 via-purple-700 to-indigo-800 flex-col justify-between p-12 relative overflow-hidden">
    <!-- Background decoration -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div class="absolute -top-24 -right-24 w-96 h-96 bg-white/5 rounded-full"></div>
      <div class="absolute -bottom-32 -left-16 w-80 h-80 bg-white/5 rounded-full"></div>
      <div class="absolute top-1/2 right-8 w-48 h-48 bg-white/5 rounded-full -translate-y-1/2"></div>
    </div>

    <div class="relative">
      <!-- Logo -->
      <div class="flex items-center gap-3 mb-16">
        <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center">
          <i class="ri-bar-chart-box-line text-white text-xl"></i>
        </div>
        <span class="text-white text-xl font-bold">CIBIL Manager</span>
      </div>

      <!-- Headline -->
      <h1 class="text-4xl font-extrabold text-white leading-tight mb-4">
        Your Credit Score,<br />Fully in Control
      </h1>
      <p class="text-violet-200 text-lg leading-relaxed mb-10">
        Track, analyse, and improve your CIBIL score with AI-powered insights tailored just for you.
      </p>

      <!-- Feature list -->
      <div class="space-y-4">
        <?php foreach ([
          ['ri-line-chart-line',   'AI-Powered Score Prediction',    'Get accurate CIBIL predictions with personalised advice'],
          ['ri-shield-check-line', 'Financial Health Monitoring',     'Real-time health score across 5 financial dimensions'],
          ['ri-robot-2-line',      'Kimi K2.5 AI Assistant',         'Ask anything about your loans, EMIs, and credit profile'],
          ['ri-focus-3-line',      'Goal-Based Credit Improvement',  'Set a target score and get a step-by-step action plan'],
        ] as [$icon, $title, $desc]): ?>
        <div class="flex items-start gap-4">
          <div class="w-10 h-10 bg-white/15 rounded-xl flex items-center justify-center flex-shrink-0 mt-0.5">
            <i class="<?= $icon ?> text-white text-base"></i>
          </div>
          <div>
            <p class="text-white font-semibold text-sm"><?= $title ?></p>
            <p class="text-violet-300 text-xs mt-0.5"><?= $desc ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Bottom quote -->
    <div class="relative bg-white/10 rounded-2xl p-5 border border-white/20">
      <i class="ri-double-quotes-l text-white/40 text-3xl absolute -top-2 left-4"></i>
      <p class="text-white/90 text-sm leading-relaxed pl-4">
        "Maintaining a good CIBIL score is not just about getting loans — it's about building financial freedom."
      </p>
    </div>
  </div>

  <!-- Right form panel -->
  <div class="w-full lg:w-1/2 flex items-center justify-center p-6 sm:p-12">
    <div class="w-full max-w-md fade-in">

      <!-- Mobile logo -->
      <div class="flex items-center gap-2 mb-8 lg:hidden">
        <div class="w-8 h-8 bg-primary rounded-lg flex items-center justify-center">
          <i class="ri-bar-chart-box-line text-white text-sm"></i>
        </div>
        <span class="text-gray-800 font-bold text-lg">CIBIL Manager</span>
      </div>

      <h2 class="text-2xl font-bold text-gray-900 mb-1">Welcome back</h2>
      <p class="text-gray-500 text-sm mb-8">Sign in to your account to continue</p>

      <!-- Errors -->
      <?php if (!empty($errors)): ?>
      <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 mb-6">
        <?php foreach ($errors as $e): ?>
        <div class="flex items-center gap-2 text-sm text-red-700">
          <i class="ri-error-warning-line flex-shrink-0"></i><?= h($e) ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Login form -->
      <form method="post" class="space-y-5">
        <input type="hidden" name="action" value="password_login" />

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Email Address</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><i class="ri-mail-line text-base"></i></span>
            <input type="email" name="email" value="<?= $emailVal ?>" required placeholder="you@example.com"
              class="input-field pl-9" />
          </div>
        </div>

        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="text-sm font-medium text-gray-700">Password</label>
          </div>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><i class="ri-lock-line text-base"></i></span>
            <input type="password" name="password" id="passwordInput" required placeholder="Enter your password"
              class="input-field pl-9 pr-10" />
            <button type="button" id="togglePwd" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
              <i class="ri-eye-off-line text-base" id="eyeIcon"></i>
            </button>
          </div>
        </div>

        <button type="submit"
          class="w-full py-3 bg-primary text-white rounded-xl font-semibold hover:bg-purple-700 active:scale-[.98] transition-all flex items-center justify-center gap-2">
          <i class="ri-login-circle-line"></i> Sign In
        </button>
      </form>

      <!-- Divider -->
      <div class="flex items-center gap-3 my-6">
        <div class="flex-1 h-px bg-gray-200"></div>
        <span class="text-xs text-gray-400 font-medium">OR</span>
        <div class="flex-1 h-px bg-gray-200"></div>
      </div>

      <!-- Google sign-in -->
      <div id="g_id_onload"
        data-client_id="<?= h($GOOGLE_CLIENT_ID) ?>"
        data-context="signin"
        data-callback="handleCredentialResponse"
        data-auto_select="false">
      </div>
      <div class="g_id_signin" data-type="standard" data-size="large" data-theme="outline"
        data-text="signin_with" data-shape="rectangular" data-width="400"></div>

      <!-- Register link -->
      <p class="text-center text-sm text-gray-500 mt-8">
        Don't have an account?
        <a href="register.php" class="text-primary font-semibold hover:underline ml-1">Create one free</a>
      </p>
    </div>
  </div>

<script>
  var pwd = document.getElementById('passwordInput');
  var eye = document.getElementById('eyeIcon');
  document.getElementById('togglePwd').addEventListener('click', function () {
    if (pwd.type === 'password') { pwd.type = 'text'; eye.className = 'ri-eye-line text-base'; }
    else { pwd.type = 'password'; eye.className = 'ri-eye-off-line text-base'; }
  });
</script>
</body>
</html>
