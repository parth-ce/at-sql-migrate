<?php
// Database configuration
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'staff_db';

// Create connection
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>
