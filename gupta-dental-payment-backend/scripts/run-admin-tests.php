<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$testDatabase = 'gdc_admin_dash_test_' . bin2hex(random_bytes(6));
$databaseCreated = false;
$exitCode = 0;
$server = null;
$testConnection = null;

try {
    $server = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $exists = $server->prepare(
        'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
    $exists->execute([$testDatabase]);
    if ($exists->fetchColumn()) {
        throw new RuntimeException('Unable to allocate a unique test database.');
    }

    $server->exec(
        'CREATE DATABASE ' . $testDatabase .
        ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $databaseCreated = true;

    $testConnection = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $testDatabase . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $databaseDirectory = dirname(__DIR__) . '/database';
    foreach ([
        $databaseDirectory . '/schema.sql',
        $databaseDirectory . '/migrations/20261122_zz_admin_dashboard.sql',
    ] as $schemaFile) {
        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new RuntimeException('Unable to read disposable test schema.');
        }

        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $testConnection->exec($statement);
            }
        }
    }

    $testConnection = null;
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['GDC_ADMIN_TEST_DB'] = $testDatabase;
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/tests/AdminDashboardIntegrationTest.php'],
        [
            0 => ['file', 'NUL', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        $environment
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the admin integration tests.');
    }

    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $testExitCode = proc_close($process);
    if ($output !== '') {
        fwrite(STDOUT, $output);
    }
    if ($errors !== '') {
        fwrite(STDERR, $errors);
    }
    if ($testExitCode !== 0) {
        $exitCode = 1;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Admin integration tests failed: ' . $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($databaseCreated && $server instanceof PDO) {
        try {
            $server->exec('DROP DATABASE ' . $testDatabase);
        } catch (Throwable $e) {
            fwrite(
                STDERR,
                'Failed to remove disposable test database ' . $testDatabase . '.' . PHP_EOL
            );
            $exitCode = 1;
        }
    }
}

exit($exitCode);
