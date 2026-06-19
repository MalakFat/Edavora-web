<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "edavora";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if teacher is logged in
if (!isset($_SESSION['email'])) {
    die("Please log in first");
}

$teacher_email = $_SESSION['email'];

echo "<h2>Simple Notification Test</h2>";

// Create a simple test notification
$notification_text = "Test notification created at " . date('Y-m-d H:i:s');

$insert_query = "INSERT INTO notifications (user_email, notification_text, is_read, created_at) 
                 VALUES (?, ?, 0, NOW())";
$stmt = $conn->prepare($insert_query);
$stmt->bind_param("ss", $teacher_email, $notification_text);

if ($stmt->execute()) {
    echo "<p style='color: green;'>✓ Notification created successfully!</p>";
    echo "<p>Notification ID: " . $conn->insert_id . "</p>";
    echo "<p>For user: {$teacher_email}</p>";
    echo "<p>Text: {$notification_text}</p>";

    // Now fetch it back
    echo "<hr>";
    echo "<h3>Fetching notification back:</h3>";

    $fetch_query = "SELECT * FROM notifications WHERE notification_id = ?";
    $stmt_fetch = $conn->prepare($fetch_query);
    $notif_id = $conn->insert_id;
    $stmt_fetch->bind_param("i", $notif_id);
    $stmt_fetch->execute();
    $result = $stmt_fetch->get_result();

    if ($result->num_rows > 0) {
        $notif = $result->fetch_assoc();
        echo "<p style='color: green;'>✓ Notification fetched successfully!</p>";
        echo "<pre>" . print_r($notif, true) . "</pre>";
    } else {
        echo "<p style='color: red;'>✗ Could not fetch notification back</p>";
    }

    echo "<br><a href='teacher_show.php'>Go to Teacher Show page to see it</a>";

} else {
    echo "<p style='color: red;'>✗ Failed to create notification</p>";
    echo "<p>Error: " . $conn->error . "</p>";
}

$conn->close();
?>