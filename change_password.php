<?php
require_once __DIR__ . '/auth.php';
require_login();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new1    = (string) ($_POST['new_password'] ?? '');
    $new2    = (string) ($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([current_user_id()]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current, $user['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new1) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new1 !== $new2) {
        $error = 'New passwords do not match.';
    } else {
        $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->execute([password_hash($new1, PASSWORD_DEFAULT), current_user_id()]);
        $success = 'Password updated.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Change password — ITPMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; }
:root { --bg:#F5F6F8; --surface:#FFFFFF; --ink:#1B2430; --ink-soft:#5B6472; --border:#DCE0E6; --accent:#2F5D8A; --danger:#C4483C; --danger-soft:#FBE7E5; --ok:#2F9E6B; --ok-soft:#E4F5EC; }
html, body { margin:0; padding:0; height:100%; background:var(--bg); font-family:'Inter',sans-serif; color:var(--ink);
  background-image: repeating-linear-gradient(0deg, rgba(47,93,138,0.04) 0 1px, transparent 1px 28px), repeating-linear-gradient(90deg, rgba(47,93,138,0.04) 0 1px, transparent 1px 28px); }
.wrap { min-height:100%; display:flex; align-items:center; justify-content:center; padding:20px; }
.card { background:var(--surface); border:1px solid var(--border); border-radius:14px; width:100%; max-width:380px; padding:28px 26px; box-shadow:0 20px 60px rgba(27,36,48,0.08); }
h1 { font-family:'Space Grotesk',sans-serif; font-size:19px; margin:0 0 4px; }
.lead { font-size:13px; color:var(--ink-soft); margin:0 0 20px; }
.field { display:flex; flex-direction:column; gap:6px; font-size:12.5px; font-weight:600; color:var(--ink-soft); margin-bottom:14px; }
.field input { font-family:'Inter',sans-serif; font-size:14px; font-weight:500; color:var(--ink); border:1px solid var(--border); border-radius:8px; padding:10px 12px; background:#FAFBFC; width:100%; }
.field input:focus { outline:2px solid rgba(47,93,138,0.2); border-color:var(--accent); }
.error { background:var(--danger-soft); color:var(--danger); font-size:12.5px; font-weight:600; padding:9px 12px; border-radius:8px; margin-bottom:14px; }
.success { background:var(--ok-soft); color:var(--ok); font-size:12.5px; font-weight:600; padding:9px 12px; border-radius:8px; margin-bottom:14px; }
.btn { padding:10px 16px; border-radius:8px; border:none; background:var(--accent); color:#fff; font-size:13.5px; font-weight:600; cursor:pointer; font-family:'Inter',sans-serif; }
.btn:hover { background:#274d74; }
.btn-ghost { background:#EDEFF3; color:var(--ink); text-decoration:none; display:inline-flex; align-items:center; }
.actions { display:flex; gap:10px; margin-top:4px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Change password</h1>
    <p class="lead">Signed in as <b><?= htmlspecialchars(current_username()) ?></b></p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <label class="field"><span>Current password</span><input type="password" name="current_password" autocomplete="current-password" required></label>
      <label class="field"><span>New password</span><input type="password" name="new_password" autocomplete="new-password" minlength="8" required></label>
      <label class="field"><span>Confirm new password</span><input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></label>
      <div class="actions">
        <button type="submit" class="btn">Update password</button>
        <a class="btn btn-ghost" href="index.php">Back to dashboard</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
