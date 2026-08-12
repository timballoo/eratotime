<?php

/**
 * Test bootstrap. Requires the lib modules directly (not just via Composer
 * autoload) so the tests run even before `composer dump-autoload`.
 */

require_once __DIR__ . '/../availability_lib.php';
require_once __DIR__ . '/../tenant_lib.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
