<?php

namespace App\Facades;

use PDO;

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dbPath = __DIR__ . '/../database/database.sqlite';

            if (!file_exists($dbPath)) {
                touch($dbPath);
            }

            self::$instance = new PDO(
                "sqlite:$dbPath",
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        }
        return self::$instance;
    }
}
