<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$host     = 'localhost';
$dbname   = 'fittech';
$username = 'root';  // Change if needed
$password = '';      // Change if needed

try {
    
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,   // connection timeout (seconds)
            PDO::MYSQL_ATTR_INIT_COMMAND =>
                "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
                 SET innodb_lock_wait_timeout = 5;
                 SET wait_timeout = 30;
                 SET interactive_timeout = 30"
        ]
    );
    // Ensure autocommit is ON so no stale transactions block future requests
    $pdo->exec("SET autocommit = 1");
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed. Please try again.']);
    exit;
}
?>