<?php
require_once __DIR__ . '/../config/env.php';

class Database
{
    private static ?PDO $connection = null;

    public static function get(): PDO
    {
        if (self::$connection === null) {
            $host = envValue('DB_HOST');
            $port = envValue('DB_PORT');
            $name = envValue('DB_NAME');
            $user = envValue('DB_USER');
            $password = envValue('DB_PASSWORD');

            if (!$host || !$name || !$user) {
                throw new RuntimeException(
                    'Database settings are missing. Create a file named ".env" (not ".env.txt") in '
                    . realpath(__DIR__ . '/..') . ' with DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD '
                    . '(copy .env.example), then restart the PHP server.'
                );
            }

            $dsn = "pgsql:host=$host;port=$port;dbname=$name";
            self::$connection = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // Keep NOW(), CURRENT_DATE and timestamptz output in the same
            // zone PHP uses (see config/env.php).
            self::$connection->exec("SET TIME ZONE '" . APP_TIMEZONE . "'");
        }
        return self::$connection;
    }
}
