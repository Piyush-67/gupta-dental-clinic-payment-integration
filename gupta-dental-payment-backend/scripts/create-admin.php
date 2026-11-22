<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminCli.php';
require_once dirname(__DIR__) . '/services/AdminUserService.php';
require_once dirname(__DIR__) . '/services/Database.php';

AdminCli::requireCli();

$password = '';

try {
    $username = AdminCli::readLine('Administrator username: ');
    $password = AdminCli::readConfirmedPassword();

    $adminId = AdminUserService::createFirstAdmin(
        Database::connect(),
        $username,
        $password
    );
    fwrite(STDOUT, 'Initial administrator created (ID ' . $adminId . ").\n");
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} catch (PDOException $e) {
    fwrite(
        STDERR,
        $e->getCode() === '23000'
            ? "That username already exists.\n"
            : "Administrator creation failed; verify database setup.\n"
    );
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "Administrator creation failed.\n");
    exit(1);
} finally {
    if ($password !== '') {
        AdminCli::clearPassword($password);
    }
}
