<?php
/**
 * ITPMS — Login
 * Default account on first run: admin / admin123 (change it right
 * after logging in via the "Change password" link in the sidebar).
 */
require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    // Small delay-independent throttle isn't implemented here (no rate limiting) —
    // fine for a small internal tool, but worth adding (e.g. fail2ban or a
    // login_attempts table) before exposing this publicly at scale.
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        header('Location: index.php');
        exit;
    }

    $error = 'Incorrect username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Log in — ITPMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; }
:root { --bg:#F5F6F8; --surface:#FFFFFF; --ink:#1B2430; --ink-soft:#5B6472; --border:#DCE0E6; --accent:#2F5D8A; --danger:#C4483C; --danger-soft:#FBE7E5; }
html, body { margin:0; padding:0; height:100%; background:var(--bg); font-family:'Inter',sans-serif; color:var(--ink);
  background-image: repeating-linear-gradient(0deg, rgba(47,93,138,0.04) 0 1px, transparent 1px 28px), repeating-linear-gradient(90deg, rgba(47,93,138,0.04) 0 1px, transparent 1px 28px); }
.wrap { min-height:100%; display:flex; align-items:center; justify-content:center; padding:20px; }
.card { background:var(--surface); border:1px solid var(--border); border-radius:14px; width:100%; max-width:360px; padding:28px 26px; box-shadow:0 20px 60px rgba(27,36,48,0.08); }
.brand { display:flex; align-items:center; gap:10px; margin-bottom:22px; }
.brand-mark { width:34px; height:34px; border-radius:8px; background:var(--accent); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:13px; flex-shrink:0; }
.brand-name { font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:16px; }
.brand-sub { font-size:11px; color:var(--ink-soft); }
h1 { font-family:'Space Grotesk',sans-serif; font-size:19px; margin:0 0 4px; }
.lead { font-size:13px; color:var(--ink-soft); margin:0 0 20px; }
.field { display:flex; flex-direction:column; gap:6px; font-size:12.5px; font-weight:600; color:var(--ink-soft); margin-bottom:14px; }
.field input { font-family:'Inter',sans-serif; font-size:14px; font-weight:500; color:var(--ink); border:1px solid var(--border); border-radius:8px; padding:10px 12px; background:#FAFBFC; width:100%; }
.field input:focus { outline:2px solid rgba(47,93,138,0.2); border-color:var(--accent); }
.error { background:var(--danger-soft); color:var(--danger); font-size:12.5px; font-weight:600; padding:9px 12px; border-radius:8px; margin-bottom:14px; }
.btn { width:100%; padding:11px 16px; border-radius:8px; border:none; background:var(--accent); color:#fff; font-size:14px; font-weight:600; cursor:pointer; font-family:'Inter',sans-serif; }
.btn:hover { background:#274d74; }
.hint { margin-top:16px; font-size:11.5px; color:var(--ink-soft); text-align:center; line-height:1.5; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="brand">
      <div class="brand-mark">IT</div>
      <div><div class="brand-name">ITPMS</div><div class="brand-sub">Project Management</div></div>
    </div>
    <h1>Log in</h1>
    <p class="lead">Sign in to view and manage projects.</p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <label class="field"><span>Username</span><input type="text" name="username" autocomplete="username" required autofocus></label>
      <label class="field"><span>Password</span><input type="password" name="password" autocomplete="current-password" required></label>
      <button type="submit" class="btn">Log in</button>
    </form>
    <p class="hint">First time here? Default login is <b>admin</b> / <b>admin123</b> — change it right after signing in.</p>
  </div>
</div>
</body>
</html>
