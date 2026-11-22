<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminCli.php';
require_once dirname(__DIR__) . '/services/AdminAuth.php';
require_once dirname(__DIR__) . '/services/Database.php';

AdminCli::requireCli();

try {
    $deleted = AdminAuth::pruneExpiredLoginAttempts(Database::connect());
    fwrite(STDOUT, 'Removed ' . $deleted . " expired administrator login attempts.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Administrator login-attempt cleanup failed.\n");
    exit(1);
}
