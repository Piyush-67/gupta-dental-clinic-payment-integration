<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminCli.php';
require_once dirname(__DIR__) . '/services/AdminUserService.php';
require_once dirname(__DIR__) . '/services/Database.php';

AdminCli::requireCli();

function showUsage(): void
{
    fwrite(
        STDERR,
        "Usage:\n" .
        "  php manage-admin.php rename <current-username> <new-username>\n" .
        "  php manage-admin.php reset-password <username>\n" .
        "  php manage-admin.php activate <username>\n" .
        "  php manage-admin.php deactivate <username>\n"
    );
}

$arguments = array_slice($argv, 1);
$action = $arguments[0] ?? '';
$password = '';

try {
    $db = Database::connect();

    switch ($action) {
        case 'rename':
            if (count($arguments) !== 3) {
                showUsage();
                exit(2);
            }
            $username = AdminUserService::changeUsername(
                $db,
                $arguments[1],
                $arguments[2]
            );
            fwrite(STDOUT, 'Administrator username changed to ' . $username . ".\n");
            break;

        case 'reset-password':
            if (count($arguments) !== 2) {
                showUsage();
                exit(2);
            }
            $password = AdminCli::readConfirmedPassword();
            AdminUserService::resetPassword($db, $arguments[1], $password);
            fwrite(STDOUT, "Administrator password reset; active sessions were invalidated.\n");
            break;

        case 'activate':
        case 'deactivate':
            if (count($arguments) !== 2) {
                showUsage();
                exit(2);
            }
            $changed = AdminUserService::setActive(
                $db,
                $arguments[1],
                $action === 'activate'
            );
            fwrite(
                STDOUT,
                $changed
                    ? 'Administrator ' . $action . "d.\n"
                    : 'Administrator already has the requested state.' . PHP_EOL
            );
            break;

        default:
            showUsage();
            exit(2);
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} catch (PDOException $e) {
    fwrite(
        STDERR,
        $e->getCode() === '23000'
            ? "That username already exists.\n"
            : "Administrator credential operation failed; verify database setup.\n"
    );
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "Administrator credential operation failed.\n");
    exit(1);
} finally {
    if ($password !== '') {
        AdminCli::clearPassword($password);
    }
}
