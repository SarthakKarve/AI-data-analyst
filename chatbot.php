<?php
require __DIR__ . '/includes/auth_check.php';

// Ensure chat history table
$pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  message_text TEXT,
  attachments_json TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// File text extraction
function extractDocxText(string $path): string {
    if (!class_exists('ZipArchive')) return '';
    $z = new ZipArchive();
    if ($z->open($path) !== true) return '';
    $xml = $z->getFromName('word/document.xml');
    $z->close();
    if (!$xml) return '';
    return substr(preg_replace('/\s+/', ' ', strip_tags($xml)), 0, 4000);
}
function extractPdfText(string $path): string {
    $raw = @file_get_contents($path);
    if (!$raw) return '';
    return substr(preg_replace('/\s+/', ' ', preg_replace('/[^(\x20-\x7F)\x0A\x0D]/', '', $raw)), 0, 4000);
}
function extractFileText(string $path, string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'txt')  return @file_get_contents($path) ?: '';
    if ($ext === 'pdf')  return extractPdfText($path);
    if ($ext === 'docx') return extractDocxText($path);
    return '';
}

// NVIDIA Kimi K2.5 call
function callKimi(string $apiKey, array $messages): string {
    $payload = [
        'model'       => 'moonshotai/kimi-k2.5',
        'messages'    => $messages,
        'temperature' => 1.00,
        'top_p'       => 1.00,
        'max_tokens'  => 1024,
        'stream'      => false,
    ];
    $ch = curl_init('https://integrate.api.nvidia.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || !$res) return "Service error: " . ($err ?: "HTTP $http with empty response");
    $j = json_decode($res, true);
    if (!$j) return "API error (HTTP $http): " . substr($res, 0, 300);
    if (!empty($j['error']['message'])) return "API error: " . $j['error']['message'];
    return $j['choices'][0]['message']['content'] ?? "Unexpected API response.";
}

// Build user data context
function buildDataContext(PDO $pdo, int $userId): string {
    $firstName     = fetchValue($pdo, "SELECT first_name FROM users WHERE user_id=?", [$userId]) ?? 'User';
    $latest        = fetchOne($pdo, "SELECT cibil_score, score_type, prediction_date FROM cibil_scores WHERE user_id=? ORDER BY prediction_date DESC LIMIT 1", [$userId]);
    $score         = $latest['cibil_score'] ?? null;
    $activeLoanSum = (float)fetchValue($pdo, "SELECT COALESCE(SUM(loan_amount),0) FROM loans WHERE user_id=? AND status='Active'", [$userId]);
    $monthlyEmiSum = (float)fetchValue($pdo, "SELECT COALESCE(SUM(emi_amount),0) FROM loans WHERE user_id=? AND status='Active'", [$userId]);
    $loans         = fetchAll($pdo, "SELECT loan_id, purpose, loan_amount, interest_rate, emi_amount, status FROM loans WHERE user_id=? ORDER BY loan_id DESC LIMIT 6", [$userId]);
    $reminders     = fetchAll($pdo, "SELECT title, due_date, status FROM reminders WHERE user_id=? AND status IN ('Upcoming','High Priority') ORDER BY due_date ASC LIMIT 6", [$userId]);
    $docsCount     = (int)fetchValue($pdo, "SELECT COUNT(*) FROM documents WHERE user_id=?", [$userId]);

    $lines = [
        "User: $firstName",
        "Latest CIBIL: " . ($score !== null ? $score . ($latest['score_type'] ? " ({$latest['score_type']})" : "") . ($latest['prediction_date'] ? " on {$latest['prediction_date']}" : "") : "—"),
        "Active Loan Outstanding: ₹" . number_format($activeLoanSum, 2),
        "Monthly EMI Total: ₹" . number_format($monthlyEmiSum, 2),
        "Documents on file: $docsCount",
    ];
    if ($loans) {
        $lines[] = "Loans (latest up to 6):";
        foreach ($loans as $L) {
            $lines[] = " • #{$L['loan_id']} · " . ($L['purpose'] ?: 'Loan') . " · ₹" . number_format((float)$L['loan_amount'], 2) . " · EMI ₹" . number_format((float)$L['emi_amount'], 2) . " · {$L['interest_rate']}% · {$L['status']}";
        }
    }
    if ($reminders) {
        $lines[] = "Pending Reminders:";
        foreach ($reminders as $R) {
            $lines[] = " • " . ($R['title'] ?: 'Reminder') . " · Due {$R['due_date']} · {$R['status']}";
        }
    }
    return implode("\n", $lines);
}

$apiKey = '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';

// ── AJAX endpoint ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    // Clear chat
    if (($_POST['action'] ?? '') === 'clear') {
        $pdo->prepare("DELETE FROM chat_messages WHERE user_id=?")->execute([$userId]);
        echo json_encode(['ok' => true]); exit;
    }

    $message   = trim($_POST['message'] ?? '');
    $uploads   = [];
    $fileTexts = [];

    // Handle file attachments
    if (!empty($_FILES['attachments']['name'][0])) {
        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $allowed = ['pdf', 'txt', 'docx', 'png', 'jpg', 'jpeg'];
        foreach ($_FILES['attachments']['name'] as $i => $name) {
            if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed) || $_FILES['attachments']['size'][$i] > 8 * 1024 * 1024) continue;
            $safe = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($name));
            $dest = __DIR__ . '/uploads/' . time() . '_' . mt_rand(1000, 9999) . '_' . $safe;
            if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $dest)) {
                $uploads[]   = ['name' => $safe, 'path' => '/uploads/' . basename($dest)];
                $txt = extractFileText($dest, $safe);
                if ($txt) $fileTexts[] = $txt;
            }
        }
    }

    // Store user message
    $pdo->prepare("INSERT INTO chat_messages (user_id, role, message_text, attachments_json) VALUES (?,?,?,?)")
        ->execute([$userId, 'user', $message, json_encode($uploads)]);

    // Build messages array for API
    $prev = array_reverse(fetchAll($pdo, "SELECT role, message_text FROM chat_messages WHERE user_id=? ORDER BY id DESC LIMIT 10", [$userId]));
    $dataCtx = buildDataContext($pdo, $userId);
    $system  = "You are the CIBIL AI Assistant — a helpful financial advisor specialised in Indian credit scores, loans, and financial health. Answer conversationally and accurately. When referring to the user's data use ONLY the User Data Context provided. Keep answers concise, use ₹ for amounts, and use short bullet lists where helpful.\n\nUSER DATA CONTEXT:\n$dataCtx";

    $msgs = [['role' => 'system', 'content' => $system]];
    foreach ($prev as $row) {
        $msgs[] = ['role' => $row['role'], 'content' => trim($row['message_text'])];
    }
    if ($fileTexts) {
        $last = count($msgs) - 1;
        $msgs[$last]['content'] .= "\n\nFILE EXCERPTS:\n" . implode("\n---\n", array_map(fn($t) => substr($t, 0, 1800), $fileTexts));
    }

    $reply = callKimi($apiKey, $msgs);

    $pdo->prepare("INSERT INTO chat_messages (user_id, role, message_text) VALUES (?,?,?)")
        ->execute([$userId, 'assistant', $reply]);

    echo json_encode(['reply' => $reply, 'time' => date('H:i')]); exit;
}

// ── Page load ─────────────────────────────────────────────────────────────────
$userRow   = fetchOne($pdo, "SELECT first_name FROM users WHERE user_id=?", [$userId]);
$firstName = $userRow['first_name'] ?? 'User';
$scoreRow  = fetchOne($pdo, "SELECT cibil_score FROM cibil_scores WHERE user_id=? ORDER BY prediction_date DESC LIMIT 1", [$userId]);
$scoreVal  = $scoreRow ? (int)$scoreRow['cibil_score'] : null;
$activeLoansCount      = (int)fetchValue($pdo, "SELECT COUNT(*) FROM loans WHERE user_id=? AND status='Active'", [$userId]);
$upcomingReminders     = (int)fetchValue($pdo, "SELECT COUNT(*) FROM reminders WHERE user_id=? AND status IN ('Upcoming','High Priority')", [$userId]);
$highPriorityReminders = (int)fetchValue($pdo, "SELECT COUNT(*) FROM reminders WHERE user_id=? AND status='High Priority'", [$userId]);
$history   = fetchAll($pdo, "SELECT role, message_text, attachments_json, created_at FROM chat_messages WHERE user_id=? ORDER BY id ASC", [$userId]);
$pageTitle  = 'AI Assistant';
$activePage = 'chatbot';
?>
<?php require __DIR__ . '/includes/page_head.php'; ?>
<div class="flex">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <div class="ml-64 flex-1 min-h-screen flex flex-col">
    <?php require __DIR__ . '/includes/header.php'; ?>
    <div class="p-8 flex-1 flex flex-col space-y-6 fade-in">

      <!-- Page Header -->
      <div class="bg-gradient-to-br from-violet-600 to-purple-700 text-white rounded-2xl p-8">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-violet-200 text-sm font-medium mb-1">Powered by Kimi K2.5 · NVIDIA NIM</p>
            <h2 class="text-3xl font-bold mb-2">AI Financial Assistant</h2>
            <p class="text-violet-100 leading-relaxed">Ask anything about your credit score, loans, EMIs, and financial health.</p>
          </div>
          <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center">
            <i class="ri-robot-2-line text-4xl text-white"></i>
          </div>
        </div>
      </div>

      <!-- Stats Row -->
      <div class="grid grid-cols-3 gap-5">
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-line-chart-line text-purple-600 text-lg"></i></div>
          <div class="text-2xl font-bold text-gray-800"><?= $scoreVal !== null ? $scoreVal : '—' ?></div>
          <div class="text-sm text-gray-500">Latest CIBIL Score</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-bank-line text-blue-600 text-lg"></i></div>
          <div class="text-2xl font-bold text-gray-800"><?= $activeLoansCount ?></div>
          <div class="text-sm text-gray-500">Active Loans</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
          <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-alarm-line text-orange-600 text-lg"></i></div>
          <div class="text-2xl font-bold text-gray-800"><?= $upcomingReminders ?></div>
          <div class="text-sm text-gray-500">Pending Reminders</div>
        </div>
      </div>

      <!-- Chat + Side Panel -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 flex-1">

        <!-- Chat Panel -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border flex flex-col" style="min-height:540px">

          <!-- Chat header -->
          <div class="px-6 py-4 border-b flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 bg-violet-100 rounded-xl flex items-center justify-center">
                <i class="ri-robot-2-line text-violet-600 text-base"></i>
              </div>
              <div>
                <p class="font-semibold text-gray-800 text-sm">Kimi K2.5 Assistant</p>
                <p class="text-xs text-green-500 flex items-center gap-1">
                  <span class="w-1.5 h-1.5 bg-green-500 rounded-full inline-block"></span>Online
                </p>
              </div>
            </div>
            <button id="clearBtn" class="text-xs text-red-500 hover:text-red-700 flex items-center gap-1 px-3 py-1.5 rounded-lg border border-red-200 hover:bg-red-50 transition-colors">
              <i class="ri-delete-bin-line"></i> Clear Chat
            </button>
          </div>

          <!-- Messages -->
          <div id="chatMessages" class="flex-1 px-6 py-4 overflow-y-auto space-y-4" style="max-height:400px">

            <?php if (empty($history)): ?>
            <div class="flex gap-3">
              <div class="w-8 h-8 bg-violet-100 rounded-xl flex items-center justify-center flex-shrink-0 mt-1">
                <i class="ri-robot-2-line text-violet-600 text-sm"></i>
              </div>
              <div class="bg-violet-50 border border-violet-100 rounded-2xl rounded-tl-sm px-4 py-3 max-w-lg">
                <p class="text-sm text-gray-800 leading-relaxed">
                  Hello <?= h($firstName) ?>! I'm your AI Financial Assistant powered by Kimi K2.5. I can help with:
                </p>
                <ul class="mt-2 space-y-1 text-sm text-gray-700">
                  <li class="flex items-center gap-2"><i class="ri-checkbox-circle-line text-violet-500 text-xs"></i>Your CIBIL score <?= $scoreVal !== null ? '(' . $scoreVal . ')' : '' ?></li>
                  <li class="flex items-center gap-2"><i class="ri-checkbox-circle-line text-violet-500 text-xs"></i>Loan management &amp; EMI calculations</li>
                  <li class="flex items-center gap-2"><i class="ri-checkbox-circle-line text-violet-500 text-xs"></i>Credit improvement strategies</li>
                  <li class="flex items-center gap-2"><i class="ri-checkbox-circle-line text-violet-500 text-xs"></i>Financial health analysis</li>
                </ul>
                <p class="text-sm text-gray-600 mt-2">How can I help you today?</p>
                <p class="text-xs text-gray-400 mt-1"><?= date('H:i') ?></p>
              </div>
            </div>
            <?php endif; ?>

            <?php foreach ($history as $m):
              $att    = $m['attachments_json'] ? json_decode($m['attachments_json'], true) : null;
              $isUser = $m['role'] === 'user';
            ?>
            <div class="flex gap-3 <?= $isUser ? 'flex-row-reverse' : '' ?>">
              <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0 mt-1 <?= $isUser ? 'bg-primary/10' : 'bg-violet-100' ?>">
                <i class="<?= $isUser ? 'ri-user-3-line text-primary' : 'ri-robot-2-line text-violet-600' ?> text-sm"></i>
              </div>
              <div class="flex-1 max-w-lg flex flex-col <?= $isUser ? 'items-end' : 'items-start' ?>">
                <div class="<?= $isUser ? 'bg-primary text-white rounded-2xl rounded-tr-sm' : 'bg-gray-50 border border-gray-100 text-gray-800 rounded-2xl rounded-tl-sm' ?> px-4 py-3">
                  <p class="text-sm leading-relaxed whitespace-pre-wrap"><?= h($m['message_text']) ?></p>
                  <?php if ($att && is_array($att) && $isUser): ?>
                  <div class="mt-2 flex flex-wrap gap-1">
                    <?php foreach ($att as $a): ?>
                    <a href="<?= h($a['path']) ?>" target="_blank" class="inline-flex items-center gap-1 text-xs bg-white/20 hover:bg-white/30 px-2 py-0.5 rounded-full">
                      <i class="ri-attachment-line text-xs"></i><?= h($a['name']) ?>
                    </a>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
                </div>
                <p class="text-xs text-gray-400 mt-1 px-1"><?= date('H:i', strtotime($m['created_at'])) ?></p>
              </div>
            </div>
            <?php endforeach; ?>

            <!-- Typing indicator (hidden by default) -->
            <div id="typingIndicator" class="hidden flex gap-3">
              <div class="w-8 h-8 bg-violet-100 rounded-xl flex items-center justify-center flex-shrink-0 mt-1">
                <i class="ri-robot-2-line text-violet-600 text-sm"></i>
              </div>
              <div class="bg-gray-50 border border-gray-100 rounded-2xl rounded-tl-sm px-4 py-3">
                <div class="flex items-center gap-1">
                  <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:0ms"></span>
                  <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:150ms"></span>
                  <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:300ms"></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Quick prompts -->
          <div class="px-6 pt-3 border-t flex flex-wrap gap-2">
            <?php foreach ([
              "What's my CIBIL score?",
              "Show my active loans",
              "How can I improve my score?",
              "Check upcoming reminders",
              "Calculate EMI for ₹5L at 10% for 5 years",
            ] as $qp): ?>
            <button type="button" class="quick-fill text-xs px-3 py-1.5 rounded-full border bg-white text-gray-600 hover:border-primary hover:text-primary hover:bg-primary/5 transition-all"
              data-value="<?= h($qp) ?>"><?= h($qp) ?></button>
            <?php endforeach; ?>
          </div>

          <!-- Input area -->
          <div class="px-6 py-4">
            <div class="flex items-center gap-3">
              <button id="attachBtn" type="button" title="Attach file (PDF, DOCX, TXT, Image)"
                class="w-10 h-10 flex items-center justify-center rounded-xl border text-gray-500 hover:border-primary hover:text-primary hover:bg-primary/5 transition-all flex-shrink-0">
                <i class="ri-attachment-2 text-lg"></i>
              </button>
              <input type="text" id="messageInput" placeholder="Ask about your credit score, loans, EMI..." autocomplete="off"
                class="flex-1 border rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary/20 transition-colors" />
              <input id="fileInput" type="file" multiple class="hidden" accept=".pdf,.txt,.docx,.png,.jpg,.jpeg" />
              <button id="sendBtn" type="button"
                class="h-10 px-5 bg-primary text-white rounded-xl font-medium hover:bg-purple-700 transition-colors flex items-center gap-2 flex-shrink-0">
                <i class="ri-send-plane-2-line"></i><span class="text-sm">Send</span>
              </button>
            </div>
            <div id="selectedFiles" class="text-xs text-gray-400 mt-1.5 px-1"></div>
          </div>
        </div>

        <!-- Side Panel -->
        <div class="space-y-5">

          <!-- Quick Actions -->
          <div class="bg-white rounded-xl shadow-sm border p-5">
            <h3 class="font-semibold text-gray-800 mb-4 text-sm">Quick Actions</h3>
            <div class="space-y-2">
              <?php foreach ([
                ['predictor.php', 'ri-line-chart-line',  'purple', 'Predict CIBIL Score'],
                ['loans.php',     'ri-bank-line',         'blue',   'Manage Loans'],
                ['reminders.php', 'ri-alarm-line',        'orange', 'View Reminders'],
                ['documents.php', 'ri-folder-3-line',     'green',  'Upload Documents'],
                ['health.php',    'ri-heart-pulse-line',  'red',    'Financial Health'],
                ['goals.php',     'ri-focus-3-line',      'indigo', 'Set a Goal'],
              ] as [$href, $icon, $c, $lbl]): ?>
              <a href="<?= $href ?>" class="flex items-center gap-3 p-2.5 rounded-xl border hover:border-primary/30 hover:bg-primary/5 transition-all">
                <div class="w-8 h-8 bg-<?= $c ?>-100 rounded-lg flex items-center justify-center flex-shrink-0">
                  <i class="<?= $icon ?> text-<?= $c ?>-600 text-sm"></i>
                </div>
                <span class="text-sm text-gray-700 font-medium"><?= $lbl ?></span>
                <i class="ri-arrow-right-line text-gray-300 ml-auto text-sm"></i>
              </a>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Suggested Questions -->
          <div class="bg-white rounded-xl shadow-sm border p-5">
            <h3 class="font-semibold text-gray-800 mb-4 text-sm">Ask Me About</h3>
            <div class="space-y-1">
              <?php foreach ([
                'How is my CIBIL score calculated?',
                'What affects credit utilization?',
                'How to reduce EMI burden?',
                'What documents do lenders need?',
                'How long to improve score by 100 pts?',
              ] as $q): ?>
              <button type="button" class="quick-fill w-full text-left text-xs text-gray-600 hover:text-primary flex items-start gap-2 p-2 rounded-lg hover:bg-primary/5 transition-colors"
                data-value="<?= h($q) ?>">
                <i class="ri-question-line text-gray-400 mt-0.5 flex-shrink-0"></i><?= h($q) ?>
              </button>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Model badge -->
          <div class="bg-gradient-to-br from-violet-50 to-purple-50 border border-violet-200 rounded-xl p-4">
            <div class="flex items-center gap-2 mb-2">
              <div class="w-6 h-6 bg-violet-100 rounded-lg flex items-center justify-center">
                <i class="ri-cpu-line text-violet-600 text-xs"></i>
              </div>
              <p class="text-xs font-semibold text-violet-700">Powered by NVIDIA NIM</p>
            </div>
            <p class="text-xs text-violet-600 leading-relaxed">
              Running <span class="font-bold">Kimi K2.5</span> by Moonshot AI — a frontier reasoning model optimized for agentic tasks and long-context understanding.
            </p>
          </div>

        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function () {
  var chat      = document.getElementById('chatMessages');
  var input     = document.getElementById('messageInput');
  var sendBtn   = document.getElementById('sendBtn');
  var clearBtn  = document.getElementById('clearBtn');
  var attachBtn = document.getElementById('attachBtn');
  var fileInput = document.getElementById('fileInput');
  var selFiles  = document.getElementById('selectedFiles');
  var typing    = document.getElementById('typingIndicator');

  // Auto-scroll
  function scrollDown() { if (chat) chat.scrollTop = chat.scrollHeight; }
  scrollDown();

  // Quick-fill buttons
  document.querySelectorAll('.quick-fill').forEach(function (btn) {
    btn.addEventListener('click', function () {
      input.value = this.getAttribute('data-value');
      input.focus();
    });
  });

  // File picker
  attachBtn.addEventListener('click', function () { fileInput.click(); });
  fileInput.addEventListener('change', function () {
    selFiles.textContent = this.files.length
      ? Array.from(this.files).map(function (f) { return f.name; }).join(', ')
      : '';
  });

  // Append a message bubble to the chat
  function appendBubble(role, text, time) {
    var isUser = role === 'user';
    var wrap = document.createElement('div');
    wrap.className = 'flex gap-3' + (isUser ? ' flex-row-reverse' : '');

    var avatar = document.createElement('div');
    avatar.className = 'w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0 mt-1 ' + (isUser ? 'bg-primary/10' : 'bg-violet-100');
    avatar.innerHTML = '<i class="' + (isUser ? 'ri-user-3-line text-primary' : 'ri-robot-2-line text-violet-600') + ' text-sm"></i>';

    var inner = document.createElement('div');
    inner.className = 'flex-1 max-w-lg flex flex-col ' + (isUser ? 'items-end' : 'items-start');

    var bubble = document.createElement('div');
    bubble.className = (isUser
      ? 'bg-primary text-white rounded-2xl rounded-tr-sm'
      : 'bg-gray-50 border border-gray-100 text-gray-800 rounded-2xl rounded-tl-sm')
      + ' px-4 py-3';
    var p = document.createElement('p');
    p.className = 'text-sm leading-relaxed whitespace-pre-wrap';
    p.textContent = text;
    bubble.appendChild(p);

    var ts = document.createElement('p');
    ts.className = 'text-xs text-gray-400 mt-1 px-1';
    ts.textContent = time || '';

    inner.appendChild(bubble);
    inner.appendChild(ts);
    wrap.appendChild(avatar);
    wrap.appendChild(inner);
    chat.appendChild(wrap);
    scrollDown();
  }

  // Send message via AJAX
  function sendMessage() {
    var msg = input.value.trim();
    if (!msg) return;

    // Show user bubble immediately
    var now = new Date();
    var hhmm = now.getHours().toString().padStart(2,'0') + ':' + now.getMinutes().toString().padStart(2,'0');
    appendBubble('user', msg, hhmm);
    input.value = '';

    // Show typing indicator
    typing.classList.remove('hidden');
    scrollDown();
    sendBtn.disabled = true;
    input.disabled   = true;

    // Build FormData
    var fd = new FormData();
    fd.append('ajax', '1');
    fd.append('message', msg);
    Array.from(fileInput.files).forEach(function (f) { fd.append('attachments[]', f); });
    fileInput.value = '';
    selFiles.textContent = '';

    fetch('chatbot.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        typing.classList.add('hidden');
        if (data.reply) {
          appendBubble('assistant', data.reply, data.time || hhmm);
        } else {
          appendBubble('assistant', 'Something went wrong. Please try again.', hhmm);
        }
      })
      .catch(function () {
        typing.classList.add('hidden');
        appendBubble('assistant', 'Network error — please check your connection and try again.', hhmm);
      })
      .finally(function () {
        sendBtn.disabled = false;
        input.disabled   = false;
        input.focus();
      });
  }

  sendBtn.addEventListener('click', sendMessage);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
  });

  // Clear chat
  clearBtn.addEventListener('click', function () {
    if (!confirm('Clear all chat history?')) return;
    var fd = new FormData();
    fd.append('ajax', '1');
    fd.append('action', 'clear');
    fetch('chatbot.php', { method: 'POST', body: fd })
      .then(function () {
        chat.innerHTML = '';
        appendBubble('assistant',
          'Chat cleared. How can I help you, <?= addslashes(h($firstName)) ?>?',
          new Date().getHours().toString().padStart(2,'0') + ':' + new Date().getMinutes().toString().padStart(2,'0')
        );
      });
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
