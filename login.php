<?php
// The session only (no database): the page below needs no database, so a busy database can no longer make the POS entry page fail.
// (This page is what the Help page and the browser open first; a refused connection used to turn it into "Server is busy".)
require __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user'])) {
    header('Location: ' . (!empty($_SESSION['user']['dispatch']) ? 'restock_dispatch.php' : 'restock.php'));
    exit;
}

// The POS no longer has its own sign-in: it opens from the HRMS (Store -> POS) and signs you in automatically (sso.php).
// The old form is kept only as an emergency fallback at login.php?legacy=1.
if (!isset($_GET['legacy'])) {
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TM Perfume House POS</title><link rel="stylesheet" href="assets/ui.css">
<style>body{display:flex;align-items:center;justify-content:center;min-height:100vh}.card{max-width:380px;margin:20px;padding:34px 30px;text-align:center}</style></head>
<body><div class="card panel"><h1 style="font-size:22px">Opening the POS…</h1>
<p class="sub" style="margin:10px 0 22px">The POS opens from the HRMS — go to <b>Store → POS</b>. No separate sign-in is needed.</p>
<a class="btn big" style="display:block" href="../hub.html?w=dispatch&t=dept_restock" target="_top">Open Store orders</a></div>
<script>try{if(window.top!==window.self){window.parent.postMessage({tmposReauth:1},location.origin);}}catch(e){}</script>
</body></html><?php
    exit;
}

// Only the emergency form below (login.php?legacy=1) reads the database.
require __DIR__ . '/includes/db.php';

$__logo = __DIR__ . '/assets/ui-logo.png'; $__has_logo = file_exists($__logo);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $st = $pdo->prepare('SELECT * FROM users WHERE username = ? AND status = 1');
    $st->execute([$username]);
    $user = $st->fetch();

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'    => (int)$user['id'],
            'name'  => $user['name'],
            'role'  => $user['role'],
            'store' => $user['store_name'],
        ];
        require_once __DIR__ . '/includes/restock_lib.php';
        $isDisp = ($user['role'] !== 'admin') && rs_is_dispatcher($pdo, (int)$user['id']);
        if ($isDisp) $_SESSION['user']['dispatch'] = true;
        header('Location: ' . ($isDisp ? 'restock_dispatch.php' : 'restock.php'));
        exit;
    }
    $error = 'Invalid username or password';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — TM Perfume House POS</title>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#eef0f3">
<link rel="stylesheet" href="assets/ui.css">
<style>
body { display:flex; align-items:center; justify-content:center; overflow:hidden; }
.orb { position:fixed; border-radius:50%; filter:blur(70px); opacity:.5; pointer-events:none; }
.orb.a { width:480px; height:480px; background:radial-gradient(circle, rgba(255,122,47,.4) 0%, rgba(255,122,47,.3) 45%, transparent 70%); top:-130px; right:-90px; animation:drift 14s ease-in-out infinite alternate; }
.orb.b { width:340px; height:340px; background:radial-gradient(circle, rgba(143,16,48,.25), transparent 70%); bottom:-100px; left:-60px; animation:drift 18s ease-in-out infinite alternate-reverse; }
@keyframes drift { to { transform:translate(-40px, 30px) scale(1.08); } }

.card {
  width:100%; max-width:400px; margin:20px; padding:44px 36px;
  position:relative; z-index:2;
  animation:cardIn .7s var(--ease) both;
}
@keyframes cardIn { from { opacity:0; transform:translateY(22px) scale(.98); } to { opacity:1; transform:none; } }
.logo-lg {
  width:72px; height:72px; margin:0 auto 18px; border-radius:20px;
  background:linear-gradient(140deg, var(--gold-2), var(--gold) 55%, var(--gold-deep));
  color:#ffffff; display:flex; align-items:center; justify-content:center;
  font-weight:600; font-size:26px;
  box-shadow:0 14px 34px -10px rgba(255,122,47,.6);
  animation:logoFloat 5s ease-in-out infinite;
}
@keyframes logoFloat { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-6px); } }
h1 { text-align:center; font-size:25px; font-weight:600; letter-spacing:.3px; }
.sub { text-align:center; font-size:13px; color:var(--mut); margin:6px 0 30px; font-weight:300; letter-spacing:.4px; }
.fld { margin-bottom:16px; }
.err {
  margin-top:16px; padding:12px 15px; font-size:13.5px; text-align:center;
  background:rgba(255,122,47,.08); border:1px solid rgba(255,122,47,.35);
  border-radius:11px; color:var(--bad);
  animation:shake .4s var(--ease);
}
@keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-6px)} 75%{transform:translateX(6px)} }
.foot { text-align:center; font-size:11.5px; color:var(--dim); margin-top:26px; letter-spacing:1px; }
</style>
</head>
<body>
<div class="orb a"></div>
<div class="orb b"></div>

<form class="card panel" method="post" action="login.php?legacy=1" autocomplete="off">
  <?php if ($__has_logo): ?><img src="assets/ui-logo.png" alt="TM Perfume House" style="max-width:150px; max-height:80px; margin:0 auto 16px; display:block;"><?php else: ?><div class="logo-lg">TM</div><?php endif; ?>
  <h1>TM Perfume House</h1>
  <p class="sub">Retail Point of Sale — sign in to start billing</p>

  <div class="fld">
    <label class="lbl" for="username">Username</label>
    <input class="inp" type="text" id="username" name="username" required autofocus>
  </div>
  <div class="fld">
    <label class="lbl" for="password">Password</label>
    <input class="inp" type="password" id="password" name="password" required>
  </div>

  <button class="btn big" type="submit" style="width:100%; margin-top:8px;">Sign in</button>

  <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <p class="foot">FOUR PIRATES &nbsp;·&nbsp; SURAT</p>
</form>
</body>
</html>
