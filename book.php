<?php

/**
 * book.php — public booking page (spec 2.3 / Phase 4).
 *
 * Route: /t/{tenant-slug}/book/{meeting-type-slug}  (rewritten to ?tenant=&type=,
 * or parsed from the path as a fallback for PHP built-in server / direct hits).
 *
 * Renders a brand-consistent shell (Ink/Paper/Brass/Verdigris, Fraunces + IBM
 * Plex — see css/eratotime.css) and hands the booking widget its config as JSON.
 * Availability rendering is driven by js/booking.js against api/slots.php; the
 * request-submission endpoint (Phase 5) is deliberately not wired yet.
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/security_lib.php';

$config = require __DIR__ . '/config.php';

// App base path derived from the request, so assets/APIs work when the app is
// served at the web root (/t/...) or under a subdirectory (/eratotime/t/...).
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$basePath = '/';
if (preg_match('#^(.*?)/t/[^/]+/book/[^/]+/?$#', $reqPath, $m) && $m[1] !== '') {
    $basePath = rtrim($m[1], '/') . '/';
}

$tenantSlug = (string) ($_GET['tenant'] ?? '');
$typeSlug = (string) ($_GET['type'] ?? '');
if ($tenantSlug === '' || $typeSlug === '') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (preg_match('#^/t/([^/]+)/book/([^/]+)/?$#', $path ?? '', $m)) {
        $tenantSlug = urldecode($m[1]);
        $typeSlug = urldecode($m[2]);
    }
}

http_response_code(404);
$title = 'Page not found';
$payload = null;

if ($tenantSlug !== '' && $typeSlug !== '' && isset($config['db']['name']) && $config['db']['name'] !== '') {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['db']['host'],
        (int) ($config['db']['port'] ?? 3306),
        $config['db']['name'],
        $config['db']['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $payload = booking_config_build($pdo, $config, $tenantSlug, $typeSlug, $basePath);
    if ($payload !== null) {
        http_response_code(200);
        $title = $payload['type']['name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0F1B2B">
<title><?= htmlspecialchars($title) ?></title>
<meta name="description" content="Request a time to talk with Dr Stephen D. Jones.">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath) ?>css/eratotime.css">
</head>
<body class="booking-body">
<header class="masthead">
    <div class="masthead-inner">
        <a class="masthead-mark" href="https://www.meertec.ltd">
            <img class="masthead-logo" src="https://www.meertec.ltd/assets/meertec-logo-disk.png" alt="" width="34" height="34">
            Meertec
        </a>
        <span class="masthead-label">Book a conversation</span>
    </div>
</header>

<main id="main" class="booking-main">
    <div class="section-inner booking-wrap">
        <?php if ($payload === null): ?>
            <div class="booking-card">
                <p class="eyebrow">Meertec</p>
                <h1 class="booking-heading">That booking link doesn't exist</h1>
                <p class="booking-lede">The page may have moved or the link was mistyped. Back to <a href="https://www.meertec.ltd">meertec.ltd</a>.</p>
            </div>
        <?php else: ?>
            <div id="eratotime-app" class="booking-app" data-config="<?= htmlspecialchars(json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
                <noscript><div class="booking-card"><p class="booking-lede">This booking page needs JavaScript enabled.</p></div></noscript>
            </div>
        <?php endif; ?>
    </div>
</main>

<footer class="site-footer">
    <div class="section-inner footer-inner">
        <p>&copy; <span id="year"><?= date('Y') ?></span> Dr Stephen D. Jones.</p>
        <p>Oxford, UK</p>
    </div>
</footer>

<script src="<?= htmlspecialchars($basePath) ?>js/embed-resize.js"></script>
<?php if ($payload !== null): ?>
<?php if ($payload['altcha_enabled']): ?>
<script src="https://cdn.jsdelivr.net/npm/altcha@0/altcha.min.js" defer></script>
<?php endif; ?>
<script type="module" src="<?= htmlspecialchars($basePath) ?>js/booking.js"></script>
<?php endif; ?>
</body>
</html>
