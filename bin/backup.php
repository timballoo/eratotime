#!/usr/bin/env php
<?php

/**
 * bin/backup.php — off-box backup helper (called by .github/workflows/backup.yml).
 *
 * Pure-PHP (Hostinger disables shell_exec), so:
 *  1. Dumps the Eratotime database as a gzipped .sql to ~/backups/eratotime/
 *     via PDO (no mysqldump binary needed).
 * The GitHub workflow rsyncs the dumps off the box into a 14-day artifact and
 * also pulls Baïkal's config + Specific dirs directly (their data lives there).
 *
 * Prunes dumps older than 14 days on the box (matches the artifact window).
 */

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config.php';
if (!isset($config['db']['name']) || $config['db']['name'] === '') {
    fwrite(STDERR, "DB not configured — fill .env (ERATO_DB_*)\n");
    exit(1);
}

$home = getenv('HOME') ?: sys_get_temp_dir();
$eratoDir = $home . '/backups/eratotime';
@mkdir($eratoDir, 0700, true);

$stamp = date('Ymd-Hi');
$dump = $eratoDir . '/erato-' . $stamp . '.sql.gz';

$pdo = new PDO(sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db']['host'],
    (int) ($config['db']['port'] ?? 3306),
    $config['db']['name'],
    $config['db']['charset'] ?? 'utf8mb4'
), $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$out = "-- Eratotime backup: " . $config['db']['name'] . ' @ ' . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_NUM);
    if ($rows === []) {
        continue;
    }
    $out .= "INSERT INTO `{$table}` VALUES\n";
    $lines = [];
    foreach ($rows as $row) {
        $vals = [];
        foreach ($row as $v) {
            $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
        }
        $lines[] = '(' . implode(',', $vals) . ')';
    }
    $out .= implode(",\n", $lines) . ";\n\n";
}
$out .= "SET FOREIGN_KEY_CHECKS=1;\n";

if (file_put_contents($dump, gzencode($out, 6)) === false) {
    fwrite(STDERR, "Could not write {$dump}\n");
    exit(1);
}
echo "DB dump: {$dump} (" . filesize($dump) . " bytes)\n";

// Prune to 14 generations on the box (matches the artifact retention window).
foreach (glob($eratoDir . '/erato-*.sql.gz') ?: [] as $f) {
    if (filemtime($f) < time() - 14 * 86400) {
        @unlink($f);
    }
}
exit(0);
