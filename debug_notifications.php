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
    echo "No session email found. Please log in first.<br>";
    echo "Session data: " . print_r($_SESSION, true);
    exit();
}

$teacher_email = $_SESSION['email'];

echo "<h2>Debugging Notifications System</h2>";
echo "<hr>";

// 1. Check teacher information
echo "<h3>1. Teacher Information</h3>";
$teacher_query = "SELECT u.firstname, u.lastname, u.email 
                  FROM users u 
                  INNER JOIN teachers t ON u.email = t.email 
                  WHERE u.email = ?";
$stmt = $conn->prepare($teacher_query);
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$teacher_result = $stmt->get_result();

if ($teacher_result->num_rows > 0) {
    $teacher_data = $teacher_result->fetch_assoc();
    $teacher_name = $teacher_data['firstname'] . ' ' . $teacher_data['lastname'];
    echo "✓ Teacher found: {$teacher_name} ({$teacher_email})<br>";
} else {
    echo "✗ Teacher not found in database!<br>";
    exit();
}

echo "<hr>";

// 2. Check notifications in database
echo "<h3>2. Notifications in Database</h3>";
$all_notif_query = "SELECT * FROM notifications WHERE user_email = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($all_notif_query);
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$all_notif_result = $stmt->get_result();

echo "Total notifications for {$teacher_email}: " . $all_notif_result->num_rows . "<br><br>";

if ($all_notif_result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Text</th><th>Is Read</th><th>Created At</th></tr>";
    while ($notif = $all_notif_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$notif['notification_id']}</td>";
        echo "<td>{$notif['notification_text']}</td>";
        echo "<td>" . ($notif['is_read'] ? 'Yes' : 'No') . "</td>";
        echo "<td>{$notif['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No notifications found for this teacher.<br>";
}

echo "<hr>";

// 3. Check courses taught by this teacher
echo "<h3>3. Courses Taught by Teacher</h3>";
$courses_query = "SELECT * FROM courses WHERE teacher = ?";
$stmt = $conn->prepare($courses_query);
$stmt->bind_param("s", $teacher_name);
$stmt->execute();
$courses_result = $stmt->get_result();

echo "Courses taught by {$teacher_name}: " . $courses_result->num_rows . "<br><br>";

if ($courses_result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Name</th><th>Teacher</th></tr>";
    while ($course = $courses_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$course['id']}</td>";
        echo "<td>{$course['name']}</td>";
        echo "<td>{$course['teacher']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No courses found for teacher: {$teacher_name}<br>";
    echo "Checking all courses in database:<br>";
    $all_courses = $conn->query("SELECT id, name, teacher FROM courses");
    while ($c = $all_courses->fetch_assoc()) {
        echo "Course {$c['id']}: {$c['name']} - Teacher: '{$c['teacher']}'<br>";
    }
}

echo "<hr>";

// 4. Check student enrollments in teacher's courses
echo "<h3>4. Student Enrollments in Teacher's Courses</h3>";
$enrollment_query = "SELECT s.student_email, c.id as course_id, c.name as course_name, 
                     u.firstname, u.lastname
                     FROM study s
                     INNER JOIN courses c ON s.course_id = c.id
                     INNER JOIN users u ON s.student_email = u.email
                     WHERE c.teacher = ?";
$stmt = $conn->prepare($enrollment_query);
$stmt->bind_param("s", $teacher_name);
$stmt->execute();
$enrollment_result = $stmt->get_result();

echo "Total enrollments: " . $enrollment_result->num_rows . "<br><br>";

if ($enrollment_result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Student</th><th>Email</th><th>Course</th><th>Notification Exists?</th></tr>";
    while ($enroll = $enrollment_result->fetch_assoc()) {
        // Check if notification exists
        $check_query = "SELECT COUNT(*) as count FROM notifications 
                        WHERE user_email = ? 
                        AND notification_text LIKE ?";
        $stmt_check = $conn->prepare($check_query);
        $like_pattern = "%{$enroll['firstname']} {$enroll['lastname']}%enrolled%{$enroll['course_name']}%";
        $stmt_check->bind_param("ss", $teacher_email, $like_pattern);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();
        $check_data = $check_result->fetch_assoc();

        echo "<tr>";
        echo "<td>{$enroll['firstname']} {$enroll['lastname']}</td>";
        echo "<td>{$enroll['student_email']}</td>";
        echo "<td>{$enroll['course_name']}</td>";
        echo "<td>" . ($check_data['count'] > 0 ? "Yes" : "No - WILL CREATE") . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No enrollments found.<br>";
}

echo "<hr>";

// 5. Try to create a test notification
echo "<h3>5. Test Notification Creation</h3>";
$test_notification = "TEST: Debug notification created at " . date('Y-m-d H:i:s');
$insert_test = "INSERT INTO notifications (user_email, notification_text, is_read, created_at) 
                VALUES (?, ?, 0, NOW())";
$stmt_test = $conn->prepare($insert_test);
$stmt_test->bind_param("ss", $teacher_email, $test_notification);

if ($stmt_test->execute()) {
    echo "✓ Test notification created successfully!<br>";
    echo "Notification ID: " . $conn->insert_id . "<br>";
} else {
    echo "✗ Failed to create test notification: " . $conn->error . "<br>";
}

echo "<hr>";

// 6. Check table structure
echo "<h3>6. Notifications Table Structure</h3>";
$structure = $conn->query("DESCRIBE notifications");
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
while ($col = $structure->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$col['Field']}</td>";
    echo "<td>{$col['Type']}</td>";
    echo "<td>{$col['Null']}</td>";
    echo "<td>{$col['Key']}</td>";
    echo "<td>{$col['Default']}</td>";
    echo "</tr>";
}
echo "</table>";

$conn->close();
?>

<style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    h2, h3 { color: #333; }
    table { border-collapse: collapse; margin: 10px 0; }
    th { background-color: #667eea; color: white; }
    td, th { padding: 8px; text-align: left; }
    tr:nth-child(even) { background-color: #f2f2f2; }
    hr { margin: 20px 0; border: 1px solid #ddd; }
</style>