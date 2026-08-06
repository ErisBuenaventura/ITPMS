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
<link rel="stylesheet" href="assets/css/change_password.css">
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
