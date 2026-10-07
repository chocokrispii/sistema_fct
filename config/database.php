<?php

define('DB_HOST', getenv('FCT_DB_HOST') ?: 'localhost');
define('DB_USER', getenv('FCT_DB_USER') ?: 'root');
define('DB_PASS', getenv('FCT_DB_PASS') !== false ? getenv('FCT_DB_PASS') : '');
define('DB_NAME', getenv('FCT_DB_NAME') ?: 'sistema_fct');

function getPDOConnection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $connection = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}
