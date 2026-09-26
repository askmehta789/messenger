<?php
/** Sales-team lead board: see new leads, tap to call, update status. */
require_once __DIR__ . '/../lib/handler.php';
$cfg = nb_config();
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
$db = nb_db($cfg);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function ago(string $dt): string {
    $s = time() - strtotime($dt);
    if ($s < 60) return 'just now';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' h ago';
    return date('d M, g:i A', strtotime($dt));
}
const STATUSES = ['new' => 'New', 'called' => 'Called', 'no_answer' => 'No answer', 'ordered' => 'Ordered',
                  'not_interested' => 'Not interested', 'invalid' => 'Invalid'];

// ---------- auth ----------
if (isset($_GET['logout'])) { session_destroy(); header('Location: ./'); exit; }
if (empty($_SESSION['user'])) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = (string)($_POST['u'] ?? ''); $hash = $cfg['admin_users'][$u] ?? null;
        if ($hash && password_verify((string)($_POST['p'] ?? ''), $hash)) {
            session_regenerate_id(true); $_SESSION['user'] = $u; header('Location: ./'); exit;
        }
        $err = 'Wrong username or password'; usleep(400000);
    } ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Leads — Login</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh"><form method="post" class="card p-4 mx-auto shadow-sm" style="max-width:340px;width:100%">
<h5 class="mb-3">Messenger Leads</h5><?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif ?>
<input name="u" class="form-control mb-2" placeholder="Username" autocomplete="username" required>
<input name="p" type="password" class="form-control mb-3" placeholder="Password" autocomplete="current-password" required>
<button class="btn btn-primary w-100">Log in</button></form></body></html>
<?php exit; }

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Bad token'); }
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['act'] ?? '') === 'lead' && isset(STATUSES[$_POST['status'] ?? ''])) {
        $db->prepare("UPDATE mb_leads SET status=?, notes=?, assigned_to=COALESCE(assigned_to, ?) WHERE id=?")
           ->execute([$_POST['status'], mb_substr((string)$_POST['notes'], 0, 5000), $_SESSION['user'], $id]);
    } elseif (($_POST['act'] ?? '') === 'resume_bot') {
        $db->prepare("UPDATE mb_conversations SET needs_human=0, human_until=NULL, ask_count=0, invalid_count=0, handover_sent=0 WHERE id=?")->execute([$id]);
    } elseif (($_POST['act'] ?? '') === 'dismiss') {
        $db->prepare("UPDATE mb_conversations SET needs_human=0 WHERE id=?")->execute([$id]);
    }
    header('Location: ' . ($_POST['back'] ?? './')); exit;
}

// ---------- filters ----------
$status = $_GET['status'] ?? 'new';
$q      = trim((string)($_GET['q'] ?? ''));
$where = []; $args = [];
if ($status !== 'all' && isset(STATUSES[$status])) { $where[] = 'l.status = ?'; $args[] = $status; }
if ($q !== '') {
    $where[] = '(l.phone LIKE ? OR l.alt_phones LIKE ? OR l.customer_name LIKE ? OR l.context LIKE ?)';
    $like = '%' . preg_replace('/\D+/', '', np_ascii_digits($q)) . '%';
    $txt  = '%' . $q . '%';
    array_push($args, $like === '%%' ? $txt : $like, $like === '%%' ? $txt : $like, $txt, $txt);
}
$sql = 'SELECT l.* FROM mb_leads l' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY l.created_at DESC LIMIT 500';

// CSV export of the current filter
if (isset($_GET['export'])) {
    $st = $db->prepare($sql); $st->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="messenger-leads-' . date('Ymd-Hi') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Name', 'Phone', 'Alt phones', 'Status', 'Context', 'Notes', 'Page ID', 'Messenger PSID']);
    foreach ($st as $r) fputcsv($out, [$r['created_at'], $r['customer_name'], $r['phone'], $r['alt_phones'], STATUSES[$r['status']], $r['context'], $r['notes'], $r['page_id'], $r['psid']]);
    exit;
}

$st = $db->prepare($sql); $st->execute($args); $leads = $st->fetchAll();
$counts = $db->query("SELECT status, COUNT(*) c FROM mb_leads GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$human  = $db->query("SELECT * FROM mb_conversations WHERE needs_human=1 ORDER BY last_seen DESC LIMIT 50")->fetchAll();
$today  = (int)$db->query("SELECT COUNT(*) FROM mb_leads WHERE created_at >= CURDATE()")->fetchColumn();
$back   = $_SERVER['REQUEST_URI'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Messenger Leads</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
 body{background:#f4f5f7}.lead-card{border-left:4px solid #0d6efd}.lead-card.s-called{border-color:#6c757d}
 .lead-card.s-ordered{border-color:#198754}.lead-card.s-no_answer{border-color:#fd7e14}.lead-card.s-not_interested,.lead-card.s-invalid{border-color:#dc3545}
 .phone{font-size:1.35rem;font-weight:700;letter-spacing:.5px}.ctx{white-space:pre-wrap;font-size:.875rem;color:#495057}
 .nav-pills .nav-link{padding:.35rem .75rem}
</style></head><body>
<nav class="navbar bg-white border-bottom sticky-top"><div class="container-fluid" style="max-width:900px">
 <span class="navbar-brand fw-semibold">📱 Messenger Leads <span class="badge text-bg-primary"><?= $today ?> today</span></span>
 <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="?<?= h(http_build_query(['status' => $status, 'q' => $q, 'export' => 1])) ?>">CSV</a>
 <a class="btn btn-sm btn-outline-danger" href="?logout=1">Log out</a></div></div></nav>
<main class="container py-3" style="max-width:900px">

<?php if ($human): ?>
<div class="card border-warning mb-3"><div class="card-header bg-warning-subtle fw-semibold">Needs a person (<?= count($human) ?>)</div>
<ul class="list-group list-group-flush">
<?php foreach ($human as $c): ?>
 <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div><strong><?= h($c['customer_name'] ?: 'Customer') ?></strong> <small class="text-muted"><?= ago($c['last_seen']) ?></small>
   <div class="small text-muted"><?= h(mb_strimwidth((string)$c['last_message'], 0, 120, '…')) ?></div></div>
  <div class="d-flex gap-1">
   <a class="btn btn-sm btn-primary" target="_blank" rel="noopener" href="https://business.facebook.com/latest/inbox/all?asset_id=<?= h($c['page_id']) ?>">Open Inbox</a>
   <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button name="act" value="resume_bot" class="btn btn-sm btn-outline-success">Resume bot</button>
    <button name="act" value="dismiss" class="btn btn-sm btn-outline-secondary">Done</button></form>
  </div></li>
<?php endforeach ?></ul></div>
<?php endif ?>

<form class="d-flex gap-2 mb-2"><input type="hidden" name="status" value="<?= h($status) ?>">
 <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search phone, name or product…"><button class="btn btn-dark">Search</button></form>
<ul class="nav nav-pills flex-nowrap overflow-auto mb-3 small">
<?php foreach (['new' => 'New'] + STATUSES + ['all' => 'All'] as $k => $label): $n = $k === 'all' ? array_sum($counts) : ($counts[$k] ?? 0); ?>
 <li class="nav-item"><a class="nav-link text-nowrap <?= $status === $k ? 'active' : '' ?>" href="?status=<?= $k ?>"><?= $label ?> <span class="badge text-bg-light"><?= $n ?></span></a></li>
<?php endforeach ?></ul>

<?php if (!$leads): ?><p class="text-center text-muted py-5">No leads here yet.</p><?php endif ?>
<?php foreach ($leads as $l): ?>
<div class="card lead-card s-<?= h($l['status']) ?> mb-2 shadow-sm"><div class="card-body py-2">
 <div class="d-flex justify-content-between flex-wrap gap-2">
  <div><div class="fw-semibold"><?= h($l['customer_name'] ?: 'Messenger customer') ?></div>
   <a class="phone text-decoration-none" href="tel:+977<?= h($l['phone']) ?>"><?= h(np_format_mobile($l['phone'])) ?></a>
   <?php if ($l['alt_phones']): ?><div class="small text-muted">Also: <?= h($l['alt_phones']) ?></div><?php endif ?></div>
  <div class="text-end small text-muted"><?= ago($l['created_at']) ?><br><?= STATUSES[$l['status']] ?><?= $l['assigned_to'] ? ' · ' . h($l['assigned_to']) : '' ?></div>
 </div>
 <div class="d-flex gap-2 my-2">
  <a class="btn btn-success btn-sm flex-fill" href="tel:+977<?= h($l['phone']) ?>">📞 Call</a>
  <a class="btn btn-outline-success btn-sm flex-fill" target="_blank" rel="noopener" href="https://wa.me/977<?= h($l['phone']) ?>">WhatsApp</a>
  <a class="btn btn-outline-primary btn-sm flex-fill" href="viber://chat?number=%2B977<?= h($l['phone']) ?>">Viber</a>
 </div>
 <?php if ($l['context']): ?><div class="ctx mb-2"><?= h($l['context']) ?></div><?php endif ?>
 <form method="post" class="row g-2 align-items-start">
  <input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="act" value="lead">
  <input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
  <div class="col-12 col-sm-4"><select name="status" class="form-select form-select-sm">
   <?php foreach (STATUSES as $k => $label): ?><option value="<?= $k ?>" <?= $l['status'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></div>
  <div class="col-12 col-sm-6"><textarea name="notes" rows="1" class="form-control form-control-sm" placeholder="Notes"><?= h($l['notes']) ?></textarea></div>
  <div class="col-12 col-sm-2"><button class="btn btn-sm btn-dark w-100">Save</button></div>
 </form>
</div></div>
<?php endforeach ?>
</main>
<script>setTimeout(()=>{ if(!document.querySelector('textarea:focus,select:focus,input:focus')) location.reload(); }, 60000);</script>
</body></html>
