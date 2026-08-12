<?php
// api/admin/settings.php — global settings GET/POST + organizer photo upload.
require __DIR__ . '/_guard.php';

try {
    $tenantId = admin_guard_tenant();
    $pdo = admin_db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $settings = admin_settings_load($pdo, $tenantId);
        $settings['organizer_photo_path'] = $settings['organizer_photo_path'] ?? null;
        admin_json(['ok' => true, 'settings' => $settings]);
    }

    // Photo upload (multipart) or JSON settings save.
    if (($_SERVER['CONTENT_TYPE'] ?? '') === 'application/json' || str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $data = admin_guard_body();
        admin_guard_csrf($data);
        $existing = admin_settings_load($pdo, $tenantId);
        $data['organizer_photo_path'] = $existing['organizer_photo_path'] ?? null; // keep photo unless replaced
        admin_settings_save($pdo, $tenantId, $data);
        admin_json(['ok' => true]);
    }

    admin_guard_csrf($_POST);
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        admin_json(['ok' => false, 'error' => 'No photo uploaded'], 400);
    }
    $tmp = (string) $_FILES['photo']['tmp_name'];
    $mime = (string) ($_FILES['photo']['type'] ?? '');
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => null,
    };
    if ($ext === null) {
        admin_json(['ok' => false, 'error' => 'Photo must be JPG, PNG or WebP'], 400);
    }
    $size = filesize($tmp);
    if ($size === false || $size > 2 * 1024 * 1024) {
        admin_json(['ok' => false, 'error' => 'Photo must be under 2MB'], 400);
    }
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $tenantId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $path = "uploads/{$tenantId}/organizer-photo.{$ext}";
    if (!move_uploaded_file($tmp, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path))) {
        admin_json(['ok' => false, 'error' => 'Could not save photo'], 500);
    }
    admin_settings_save($pdo, $tenantId, array_merge(admin_settings_load($pdo, $tenantId), ['organizer_photo_path' => $path]));
    admin_json(['ok' => true, 'path' => $path]);
} catch (Throwable $e) {
    admin_json_out($e);
}
