<?php
session_start();
require_once 'config/db_connection.php';

if (!isset($_SESSION['email'])) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$recipient_email = isset($data['recipient'])?$data['recipient']: '';

if (empty($recipient_email)) {
    header("HTTP/1.1 400 Bad Request");
    exit();
}

$user_email = $_SESSION['email'];
$host = "localhost";
$username = "root";
$password = "";
$database = "edavora";

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8mb4");
$query = "UPDATE messages SET is_read = 1 
          WHERE user_email = ? AND sender_email = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $user_email, $recipient_email);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['error' => 'Failed to update messages']);
}

$conn->close();
?>