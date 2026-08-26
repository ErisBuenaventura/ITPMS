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
<link rel="stylesheet" href="assets/css/login.css">
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
    <p class="hint"><b>Login here.</b></p>
  </div>
</div>
</body>
</html>
