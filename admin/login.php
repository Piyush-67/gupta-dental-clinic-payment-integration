<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/gupta-dental-payment-backend/services/AdminAuth.php';
require_once dirname(__DIR__) . '/gupta-dental-payment-backend/services/Database.php';

AdminAuth::start();
AdminAuth::setSecurityHeaders();
if (AdminAuth::hasAdminSession()) {
    try {
        if (AdminAuth::isAuthenticated(Database::connect())) {
            header('Location: index.php', true, 303);
            exit;
        }
    } catch (Throwable $e) {
        error_log('Administrator session validation failed.');
    }
}

$csrfToken = htmlspecialchars(AdminAuth::csrfToken(), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= $csrfToken ?>">
  <meta name="theme-color" content="#082b52">
  <title>Admin Sign In | Gupta Dental Clinic</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/admin.css">
  <script src="assets/admin.js" defer></script>
</head>
<body class="login-page">
  <main class="login-card">
    <a class="admin-brand login-brand" href="../index.html">
      <img src="../gupta-dental-clinic-logo.jpg" alt="">
      <span>Gupta Dental Clinic<small>Administration</small></span>
    </a>
    <h1>Administrator sign in</h1>
    <p class="login-intro">Sign in with your authorized clinic administrator account.</p>
    <form id="adminLoginForm" novalidate>
      <label for="adminUsername">Username</label>
      <input id="adminUsername" name="username" type="text" autocomplete="username" minlength="3" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9._\x2d]{2,63}" required>
      <label for="adminPassword">Password</label>
      <input id="adminPassword" name="password" type="password" autocomplete="current-password" maxlength="72" required>
      <button class="button button-primary login-submit" type="submit">Sign in</button>
      <p class="form-message" id="loginMessage" role="status" aria-live="polite"></p>
    </form>
    <p class="privacy-note">Patient and payment details are available only to authenticated administrators.</p>
  </main>
</body>
</html>
