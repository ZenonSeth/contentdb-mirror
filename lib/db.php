<?php

function cdb_mirror_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/../config.php';
        if (!file_exists($path)) {
            fwrite(STDERR, "Missing config.php - copy config.php.example to config.php and fill it in.\n");
            exit(1);
        }
        $config = require $path;
    }
    return $config;
}

function cdb_mirror_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $db = cdb_mirror_config()['db'];
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['name']);
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
