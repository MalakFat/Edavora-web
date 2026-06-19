<?php
/**
 * This file creates enrollment notifications for teachers
 * Call this after a student successfully enrolls in a course
 *
 * Usage in your enrollment code:
 * include 'create_enrollment_notification.php';
 * createEnrollmentNotification($student_email, $course_id, $conn);
 */

function createEnrollmentNotification($student_email, $course_id, $conn) {
    // Get student information
    $student_query = "SELECT firstname, lastname FROM users WHERE email = ?";
    $stmt = $conn->prepare($student_query);
    $stmt->bind_param("s", $student_email);
    $stmt->execute();
    $student_result = $stmt->get_result();

    if ($student_result->num_rows == 0) {
        return false;
    }

    $student = $student_result->fetch_assoc();
    $student_firstname = $student['firstname'];
    $student_lastname = $student['lastname'];

    // Get course and teacher information
    // The teacher field in courses table contains the full name
    $course_query = "SELECT c.name as course_name, c.teacher
                     FROM courses c
                     WHERE c.id = ?";
    $stmt = $conn->prepare($course_query);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $course_result = $stmt->get_result();

    if ($course_result->num_rows == 0) {
        return false;
    }

    $course = $course_result->fetch_assoc();
    $course_name = $course['course_name'];
    $teacher_full_name = $course['teacher'];

    // Find the teacher's email by matching their full name
    $teacher_query = "SELECT u.email 
                      FROM users u
                      INNER JOIN teachers t ON u.email = t.email
                      WHERE CONCAT(u.firstname, ' ', u.lastname) = ?";
    $stmt = $conn->prepare($teacher_query);
    $stmt->bind_param("s", $teacher_full_name);
    $stmt->execute();
    $teacher_result = $stmt->get_result();

    if ($teacher_result->num_rows == 0) {
        return false; // Teacher not found
    }

    $teacher = $teacher_result->fetch_assoc();
    $teacher_email = $teacher['email'];

    // Check if notification already exists
    $check_query = "SELECT COUNT(*) as count FROM notifications 
                    WHERE user_email = ? 
                    AND notification_text LIKE ?";
    $stmt = $conn->prepare($check_query);
    $like_pattern = "%{$student_firstname} {$student_lastname}%enrolled%{$course_name}%";
    $stmt->bind_param("ss", $teacher_email, $like_pattern);
    $stmt->execute();
    $check_result = $stmt->get_result();
    $check_data = $check_result->fetch_assoc();

    if ($check_data['count'] > 0) {
        return true; // Notification already exists
    }

    // Create notification
    $notification_text = "{$student_firstname} {$student_lastname} has enrolled in {$course_name}";
    $insert_query = "INSERT INTO notifications (user_email, notification_text, is_read, created_at) 
                     VALUES (?, ?, 0, NOW())";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("ss", $teacher_email, $notification_text);

    if ($stmt->execute()) {
        return true;
    } else {
        error_log("Failed to create enrollment notification: " . $conn->error);
        return false;
    }
}

// If this file is called directly via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_email']) && isset($_POST['course_id'])) {
    session_start();

    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "edavora";

    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'message' => 'Connection failed']);
        exit();
    }

    $student_email = $_POST['student_email'];
    $course_id = intval($_POST['course_id']);

    $result = createEnrollmentNotification($student_email, $course_id, $conn);

    echo json_encode(['success' => $result]);

    $conn->close();
}
?>