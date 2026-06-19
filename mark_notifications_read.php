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
$notification_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;

if ($notification_id > 0) {
    // Mark specific notification as read
    $update_query = "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_email = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("is", $notification_id, $teacher_email);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update notification']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
}

$conn->close();
?>