<?php
// Minimal PDO connector for the cibil database
$host = '127.0.0.1';
$db   = 'cibil';
$user = 'root';
$pass = ''; // Set your MySQL password if you have one
$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    exit('DB connection failed: ' . htmlspecialchars($e->getMessage()));
}
?>