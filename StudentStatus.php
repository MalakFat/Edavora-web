<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "edavora";

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// استلام الإيميل (الطالب) والكورس من GET
$student_email = isset($_SESSION['email'])?$_SESSION['email']: null;
$course_id = isset($_GET['course_id'])?$_GET['course_id']: null;

if (!$student_email || !$course_id) {
    echo "Missing parameters.";
    exit;
}

$sql = "SELECT progress FROM study WHERE student_email = ? AND course_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $student_email, $course_id);
$stmt->execute();
$result = $stmt->get_result();

$status = "In Progress"; // Default

if ($row = $result->fetch_assoc()) {
    if ((int)$row['progress'] === 100) {
        $status = "Pass";
    }
}

echo $status;

$conn->close();
?>
