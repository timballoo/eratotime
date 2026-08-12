<?php

/**
 * admin.php — admin panel entry point (spec 2.6).
 *
 * Single shared passphrase (config['admin']). Renders either the login form or
 * the admin app shell; all data flows through /api/admin/*.php endpoints.
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/security_lib.php';
require __DIR__ . '/admin_lib.php';

$config = require __DIR__ . '/config.php';

admin_session_start();
$loggedIn = admin_is_logged_in();
$csrf = $loggedIn ? admin_csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Eratotime Admin — Meertec</title>
<link rel="stylesheet" href="css/eratotime.css">
<link rel="stylesheet" href="css/admin.css">
</head>
<body class="booking-body admin-body">
<header class="masthead">
    <div class="masthead-inner">
        <a class="masthead-mark" href="admin.php">
            <img class="masthead-logo" src="https://www.meertec.ltd/assets/meertec-logo-disk.png" alt="" width="34" height="34">
            Meertec
        </a>
        <?php if ($loggedIn): ?>
        <div class="admin-top-actions">
            <span class="masthead-label">Eratotime Admin</span>
            <button type="button" class="btn btn-ghost btn-small" id="admin-logout">Sign out</button>
        </div>
        <?php else: ?>
        <span class="masthead-label">Admin</span>
        <?php endif; ?>
    </div>
</header>

<main id="main" class="booking-main admin-main">
    <div class="section-inner admin-wrap">
        <?php if (!$loggedIn): ?>
            <div class="booking-card admin-login-card">
                <p class="eyebrow">Eratotime</p>
                <h1 class="booking-heading">Admin sign in</h1>
                <form id="admin-login-form" class="booking-form">
                    <div class="field">
                        <label class="field-label" for="a-user">Username</label>
                        <input class="field-input" id="a-user" name="username" type="text" autocomplete="username" required>
                    </div>
                    <div class="field">
                        <label class="field-label" for="a-pass">Password</label>
                        <input class="field-input" id="a-pass" name="password" type="password" autocomplete="current-password" required>
                    </div>
                    <div id="login-status" class="status" role="status"></div>
                    <button type="submit" class="btn btn-primary">Sign in</button>
                </form>
            </div>
        <?php else: ?>
            <div id="admin-app" data-csrf="<?= htmlspecialchars($csrf) ?>" data-tenant="meertec">
                <noscript><div class="booking-card"><p class="booking-lede">The admin panel needs JavaScript.</p></div></noscript>
            </div>
        <?php endif; ?>
    </div>
</main>

<footer class="site-footer">
    <div class="section-inner footer-inner">
        <p>&copy; <span><?= date('Y') ?></span> Meertec. Eratotime admin.</p>
        <p><a href="https://www.meertec.ltd">meertec.ltd</a></p>
    </div>
</footer>

<script src="js/embed-resize.js"></script>
<?php if ($loggedIn): ?>
<script type="module" src="js/admin.js"></script>
<?php else: ?>
<script type="module" src="js/admin.js"></script>
<?php endif; ?>
</body>
</html>
