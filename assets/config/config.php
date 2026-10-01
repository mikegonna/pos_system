<?php
declare(strict_types=1);

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'pos-system';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPassword = getenv('DB_PASSWORD');
$dbPassword = $dbPassword === false ? '' : $dbPassword;

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

$pdo = new PDO($dsn, $dbUser, $dbPassword, [
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	PDO::ATTR_EMULATE_PREPARES => false,
]);
