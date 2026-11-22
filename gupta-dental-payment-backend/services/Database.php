<?php
declare(strict_types=1);


require_once dirname(__DIR__) . '/config/config.php';

class Database
{
    private static ?PDO $connection = null;

    public static function connect(): PDO
    {
        if (self::$connection === null) {

            $dsn = 'mysql:host=' . DB_HOST .
                   ';dbname=' . DB_NAME .
                   ';charset=utf8mb4';

            try {
                self::$connection = new PDO(
                    $dsn,
                    DB_USER,
                    DB_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );

            } catch (PDOException $e) {
                error_log('Database connection failed: ' . $e->getMessage());

                throw new RuntimeException(
                    'Unable to connect to the database.'
                );
            }
        }

        return self::$connection;
    }
}
