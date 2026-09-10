<?php
$DB_HOST = '127.0.0.1';
$DB_NAME = 'legalease_eproject';
$DB_USER = 'root';
$DB_PASS = '';
try {
    $pdo = new PDO(
    "mysql:host={$DB_HOST
};dbname={$DB_NAME
};charset=utf8mb4",
$DB_USER,
$DB_PASS,
[
PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
PDO::ATTR_EMULATE_PREPARES => false,]);
$pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Import database/00_all_in_one.sql and check config/database.php.');
}
