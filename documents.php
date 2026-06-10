<?php
require __DIR__ . '/includes/auth_check.php';

function s($v): string { return trim((string)$v); }
function extIcon(string $ext): string {
    $e = strtolower($ext);
    if ($e==='pdf')                        return 'ri-file-pdf-line';
    if (in_array($e,['doc','docx']))       return 'ri-file-word-line';
    if (in_array($e,['xls','xlsx','csv'])) return 'ri-file-excel-line';
    if (in_array($e,['jpg','jpeg','png'])) return 'ri-image-line';
    return 'ri-file-3-line';
}
function extColor(string $ext): string {
    $e = strtolower($ext);
    if ($e==='pdf')                        return 'text-red-500 bg-red-50';
    if (in_array($e,['doc','docx']))       return 'text-blue-500 bg-blue-50';
    if (in_array($e,['xls','xlsx','csv'])) return 'text-green-600 bg-green-50';
    if (in_array($e,['jpg','jpeg','png'])) return 'text-purple-500 bg-purple-50';
    return 'text-gray-500 bg-gray-50';
}
function isImage(string $ext): bool { return in_array(strtolower($ext),['jpg','jpeg','png']); }

// S3 config (optional)
$S3_ENABLED = strtolower((string)getenv('S3_ENABLED'))==='true';
$AWS_BUCKET = getenv('AWS_BUCKET')?:''; $AWS_REGION=getenv('AWS_REGION')?:'';
$AWS_KEY=getenv('AWS_ACCESS_KEY_ID')?:''; $AWS_SECRET=getenv('AWS_SECRET_ACCESS_KEY')?:'';
$HAS_AWS=$S3_ENABLED&&$AWS_BUCKET&&$AWS_REGION&&$AWS_KEY&&$AWS_SECRET;
$S3=null; $HAS_S3_CLIENT=false;
if ($HAS_AWS && file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
    if (class_exists('Aws\\S3\\S3Client')) {
        try { $S3=new Aws\S3\S3Client(['version'=>'latest','region'=>$AWS_REGION,'credentials'=>['key'=>$AWS_KEY,'secret'=>$AWS_SECRET]]); $HAS_S3_CLIENT=true; } catch(Throwable $t){}
    }
}

$uploadDir = __DIR__.'/uploads';
if (!is_dir($uploadDir)) @mkdir($uploadDir,0777,true);

// ── Download handler ──────────────────────────────────────────────────────────
if (($_GET['action']??'')==='download') {
    $row = fetchOne($pdo,'SELECT file_path,file_type,doc_title FROM documents WHERE doc_id=? AND user_id=?',[(int)($_GET['doc_id']??0),$userId]);
    if (!$row) { http_response_code(404); exit('Not found'); }
    if ($HAS_S3_CLIENT && str_starts_with($row['file_path'],'s3://')) {
        $key=substr($row['file_path'],strlen('s3://'.$AWS_BUCKET.'/'));
        $cmd=$S3->getCommand('GetObject',['Bucket'=>$AWS_BUCKET,'Key'=>$key]);
        header('Location:'.(string)$S3->createPresignedRequest($cmd,'+10 minutes')->getUri()); exit;
    }
    $full=__DIR__.'/'.$row['file_path'];
    if (!is_file($full)) { http_response_code(404); exit('File not found'); }
    $fi=new finfo(FILEINFO_MIME_TYPE); $mime=$fi->file($full)?:'application/octet-stream';
    header('Content-Type: '.$mime);
    header('Content-Disposition: attachment; filename="'.basename($full).'"');
    header('Content-Length: '.filesize($full));
    readfile($full); exit;
}

$csrf   = csrfToken();
$errors = []; $success = '';

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act=$_POST['action']??'';
    if (!csrfVerify($_POST['csrf']??'')) { $errors[]='Invalid CSRF token.'; }
    else {
        if ($act==='upload') {
            $doc_title = s($_POST['doc_title']??'');
            $category  = s($_POST['category']??'General');
            $doc_date  = s($_POST['doc_date']??'');
            if ($doc_title==='') $errors[]='Document title is required.';
            $f=$_FILES['file']??null;
            if (!$f||($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) $errors[]='File upload error — please select a file.';
            if (empty($errors)) {
                $orig=$f['name']; $tmp=$f['tmp_name']; $size=(int)($f['size']??0);
                $ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION));
                if (!in_array($ext,['pdf','doc','docx','jpg','jpeg','png'],true)) $errors[]='Unsupported file type. Allowed: PDF, DOC, DOCX, JPG, PNG.';
                if ($size>10*1024*1024) $errors[]='File too large (max 10 MB).';
            }
            if (empty($errors)) {
                $safe=preg_replace('/[^A-Za-z0-9_\-\.]/','_',$orig);
                $fname=uniqid('doc_').'_'.$safe;
                $local=$uploadDir.'/'.$fname; $storagePath='uploads/'.$fname;
                if (!move_uploaded_file($tmp,$local)) { $errors[]='Failed to save file.'; }
                else {
                    $sizeMb=round(filesize($local)/(1024*1024),4);
                    if ($HAS_S3_CLIENT) {
                        try {
                            $key='users/'.$userId.'/documents/'.$fname;
                            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($local)?:'application/octet-stream';
                            $S3->putObject(['Bucket'=>$AWS_BUCKET,'Key'=>$key,'Body'=>fopen($local,'rb'),'ContentType'=>$mime,'ACL'=>'private']);
                            @unlink($local); $storagePath='s3://'.$AWS_BUCKET.'/'.$key;
                        } catch(Throwable $t) {}
                    }
                    $pdo->prepare("INSERT INTO documents (user_id,doc_title,category,file_path,file_type,storage_size_mb,doc_date) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$userId,$doc_title,$category,$storagePath,$ext,$sizeMb,$doc_date?:null]);
                    logActivity($pdo,$userId,'doc_uploaded',"Uploaded: $doc_title ($ext)");
                    $success='Document uploaded successfully.';
                }
            }
        } elseif ($act==='delete') {
            $docId=(int)($_POST['doc_id']??0);
            $row=fetchOne($pdo,'SELECT file_path,doc_title FROM documents WHERE doc_id=? AND user_id=?',[$docId,$userId]);
            if ($row) {
                if (!str_starts_with($row['file_path'],'s3://')) @unlink(__DIR__.'/'.$row['file_path']);
                $pdo->prepare("DELETE FROM documents WHERE doc_id=? AND user_id=?")->execute([$docId,$userId]);
                logActivity($pdo,$userId,'doc_deleted',"Deleted: ".$row['doc_title']);
                $success='Document deleted.';
            }
        }
    }
}

// ── Page data ─────────────────────────────────────────────────────────────────
$allDocs  = fetchAll($pdo, "SELECT * FROM documents WHERE user_id=? ORDER BY uploaded_at DESC", [$userId]);
$totalDocs = count($allDocs);
$totalSize = array_sum(array_column($allDocs,'storage_size_mb'));
$latestDoc = $allDocs[0]['uploaded_at'] ?? null;

// Group by category
$grouped = [];
foreach ($allDocs as $d) {
    $cat = $d['category'] ?: 'General';
    $grouped[$cat][] = $d;
}
ksort($grouped);

$categories = ['General','Loan Documents','ID Proof','Income Proof','Insurance','Bank Statements','Other'];

$userRow   = fetchOne($pdo,"SELECT first_name FROM users WHERE user_id=?",[$userId]);
$firstName = $userRow['first_name'] ?? 'User';
$highPriorityReminders = (int)fetchValue($pdo,"SELECT COUNT(*) FROM reminders WHERE user_id=? AND status='High Priority'",[$userId]);

$pageTitle  = 'Documents';
$activePage = 'documents';
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

      <!-- Stats + Upload -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Stats -->
        <div class="lg:col-span-2 grid grid-cols-3 gap-5">
          <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-folder-3-line text-blue-600 text-lg"></i></div>
            <div class="text-2xl font-bold"><?= $totalDocs ?></div>
            <div class="text-sm text-gray-500">Total Documents</div>
          </div>
          <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-hard-drive-line text-purple-600 text-lg"></i></div>
            <div class="text-2xl font-bold"><?= round($totalSize,2) ?></div>
            <div class="text-sm text-gray-500">Total Size (MB)</div>
          </div>
          <div class="bg-white rounded-xl shadow-sm border p-5 card-hover">
            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mb-3"><i class="ri-folder-chart-line text-green-600 text-lg"></i></div>
            <div class="text-2xl font-bold"><?= count($grouped) ?></div>
            <div class="text-sm text-gray-500">Categories</div>
          </div>
          <?php if ($latestDoc): ?>
          <div class="col-span-3 bg-primary/5 border border-primary/20 rounded-xl px-4 py-3 text-sm text-gray-600">
            <i class="ri-time-line text-primary mr-1"></i>Last upload: <strong><?= date('d M Y, H:i', strtotime($latestDoc)) ?></strong>
          </div>
          <?php endif; ?>
        </div>
        <!-- Upload Card -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
          <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="ri-upload-cloud-line text-primary"></i>Upload Document</h3>
          <form method="post" enctype="multipart/form-data" class="space-y-3">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="upload">
            <div><label class="text-xs text-gray-500 mb-1 block">Title *</label>
              <input type="text" name="doc_title" required placeholder="e.g., Aadhaar Card" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
            <div><label class="text-xs text-gray-500 mb-1 block">Category</label>
              <select name="category" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary">
                <?php foreach ($categories as $cat): ?>
                <option><?= h($cat) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div><label class="text-xs text-gray-500 mb-1 block">Document Date</label>
              <input type="date" name="doc_date" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-primary"></div>
            <div>
              <label class="text-xs text-gray-500 mb-1 block">File * <span class="text-gray-400">(PDF/DOC/DOCX/JPG/PNG, max 10MB)</span></label>
              <div id="dropZone" class="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center hover:border-primary/50 cursor-pointer transition-colors">
                <i class="ri-upload-cloud-2-line text-3xl text-gray-300 mb-2 block"></i>
                <p class="text-sm text-gray-400" id="dropLabel">Drop file here or click to browse</p>
                <input type="file" name="file" id="fileInput" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="hidden" required>
              </div>
            </div>
            <button type="submit" class="w-full py-2.5 bg-primary text-white rounded-lg font-medium hover:bg-purple-700 transition-colors text-sm">
              <i class="ri-upload-line mr-1"></i>Upload Document
            </button>
          </form>
        </div>
      </div>

      <!-- Documents grouped by category -->
      <?php if (empty($allDocs)): ?>
      <div class="bg-white rounded-xl shadow-sm border p-14 flex flex-col items-center text-center">
        <div class="w-16 h-16 bg-gray-100 rounded-2xl flex items-center justify-center mb-4"><i class="ri-folder-open-line text-2xl text-gray-400"></i></div>
        <p class="text-gray-500 font-medium">No documents yet</p>
        <p class="text-sm text-gray-400 mt-1">Upload your KYC and financial documents to get started.</p>
      </div>
      <?php else: ?>
      <div class="space-y-5">
        <?php foreach ($grouped as $category => $docs): ?>
        <div class="bg-white rounded-xl shadow-sm border">
          <!-- Category Header (collapsible) -->
          <button onclick="toggleSection('cat-<?= md5($category) ?>')" class="w-full flex items-center justify-between px-6 py-4 hover:bg-gray-50 transition-colors rounded-t-xl">
            <div class="flex items-center gap-3">
              <div class="w-8 h-8 bg-primary/10 rounded-lg flex items-center justify-center"><i class="ri-folder-2-line text-primary text-sm"></i></div>
              <span class="font-semibold text-gray-800"><?= h($category) ?></span>
              <span class="bg-gray-100 text-gray-600 text-xs font-medium px-2 py-0.5 rounded-full"><?= count($docs) ?> file<?= count($docs)>1?'s':'' ?></span>
            </div>
            <i class="ri-arrow-down-s-line text-gray-400 text-lg transition-transform" id="chevron-<?= md5($category) ?>"></i>
          </button>
          <!-- Category Documents -->
          <div id="cat-<?= md5($category) ?>" class="border-t">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-5">
              <?php foreach ($docs as $d):
                $ext = strtolower($d['file_type']??'');
                $isImg = isImage($ext);
                $localPath = !str_starts_with($d['file_path'],'s3://') ? __DIR__.'/'.$d['file_path'] : null;
              ?>
              <div class="border border-gray-200 rounded-xl overflow-hidden hover:border-primary/30 hover:shadow-sm transition-all group">
                <!-- Preview area -->
                <div class="h-28 bg-gray-50 flex items-center justify-center relative">
                  <?php if ($isImg && $localPath && is_file($localPath)): ?>
                  <img src="<?= h($d['file_path']) ?>" alt="<?= h($d['doc_title']) ?>" class="h-full w-full object-cover">
                  <?php else: ?>
                  <div class="flex flex-col items-center">
                    <div class="w-12 h-12 rounded-xl <?= extColor($ext) ?> flex items-center justify-center mb-1">
                      <i class="<?= extIcon($ext) ?> text-2xl"></i>
                    </div>
                    <span class="text-xs text-gray-400 uppercase font-bold"><?= h($ext) ?: 'FILE' ?></span>
                  </div>
                  <?php endif; ?>
                </div>
                <!-- Info -->
                <div class="p-4">
                  <p class="font-semibold text-gray-800 text-sm truncate" title="<?= h($d['doc_title']) ?>"><?= h($d['doc_title']) ?></p>
                  <p class="text-xs text-gray-400 mt-1"><?= date('d M Y', strtotime($d['uploaded_at'])) ?> &nbsp;·&nbsp; <?= round((float)$d['storage_size_mb'],2) ?> MB</p>
                  <?php if ($d['doc_date']): ?>
                  <p class="text-xs text-gray-400">Doc date: <?= date('d M Y',strtotime($d['doc_date'])) ?></p>
                  <?php endif; ?>
                  <div class="flex gap-2 mt-3">
                    <a href="?action=download&doc_id=<?= $d['doc_id'] ?>" class="flex-1 flex items-center justify-center gap-1 py-1.5 rounded-lg border text-xs text-gray-600 hover:bg-gray-50 transition-colors">
                      <i class="ri-download-line"></i> Download
                    </a>
                    <form method="post" onsubmit="return confirm('Delete this document?')" class="flex-1">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="doc_id" value="<?= $d['doc_id'] ?>">
                      <button type="submit" class="w-full flex items-center justify-center gap-1 py-1.5 rounded-lg border border-red-200 text-xs text-red-500 hover:bg-red-50 transition-colors">
                        <i class="ri-delete-bin-line"></i> Delete
                      </button>
                    </form>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
function toggleSection(id) {
  const el=document.getElementById(id);
  const chevron=document.getElementById('chevron-'+id.replace('cat-',''));
  if(el){ el.classList.toggle('hidden'); if(chevron) chevron.style.transform = el.classList.contains('hidden') ? 'rotate(-90deg)' : ''; }
}
// Drag & drop upload zone
(function(){
  const zone=document.getElementById('dropZone');
  const input=document.getElementById('fileInput');
  const label=document.getElementById('dropLabel');
  if(!zone||!input) return;
  zone.addEventListener('click',()=>input.click());
  zone.addEventListener('dragover',e=>{e.preventDefault();zone.classList.add('border-primary','bg-primary/5');});
  zone.addEventListener('dragleave',()=>zone.classList.remove('border-primary','bg-primary/5'));
  zone.addEventListener('drop',e=>{
    e.preventDefault(); zone.classList.remove('border-primary','bg-primary/5');
    if(e.dataTransfer.files.length){ input.files=e.dataTransfer.files; label.textContent=e.dataTransfer.files[0].name; }
  });
  input.addEventListener('change',()=>{ if(input.files.length) label.textContent=input.files[0].name; });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
