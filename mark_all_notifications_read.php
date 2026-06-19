<?php
session_start();
header('Content-Type: application/json');

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "edavora";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['email'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$teacher_email = $_SESSION['email'];

// Mark all notifications as read for this user
$update_query = "UPDATE notifications SET is_read = 1 WHERE user_email = ? AND is_read = 0";
$stmt = $conn->prepare($update_query);
$stmt->bind_param("s", $teacher_email);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'updated' => $stmt->affected_rows]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update notifications']);
}

$conn->close();
?>