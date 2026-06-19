<?php
// notifications_functions.php

function createEnrollmentNotification($student_email, $course_id, $conn) {
    // نفس الدالة الموجودة في teacher_show.php
    // انسخ الدالة من teacher_show.php وضعها هنا

    // 1. الحصول على معلومات الطالب
    $student_query = "SELECT firstname, lastname FROM users WHERE email = ?";
    $stmt = $conn->prepare($student_query);
    $stmt->bind_param("s", $student_email);
    $stmt->execute();
    $student_result = $stmt->get_result();

    if ($student_result->num_rows == 0) return false;

    $student = $student_result->fetch_assoc();
    $student_name = $student['firstname'] . ' ' . $student['lastname'];

    // 2. الحصول على معلومات الدورة والمعلم
    $course_query = "SELECT c.name, c.teacher FROM courses c WHERE c.id = ?";
    $stmt = $conn->prepare($course_query);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $course_result = $stmt->get_result();

    if ($course_result->num_rows == 0) return false;

    $course = $course_result->fetch_assoc();
    $course_name = $course['name'];
    $teacher_name = $course['teacher'];

    // 3. الحصول على إيميل المعلم
    $teacher_query = "SELECT u.email FROM users u 
                     WHERE CONCAT(u.firstname, ' ', u.lastname) = ?";
    $stmt = $conn->prepare($teacher_query);
    $stmt->bind_param("s", $teacher_name);
    $stmt->execute();
    $teacher_result = $stmt->get_result();

    if ($teacher_result->num_rows == 0) return false;

    $teacher = $teacher_result->fetch_assoc();
    $teacher_email = $teacher['email'];

    // 4. إنشاء الإشعار
    $notification_text = "$student_name has enrolled in $course_name";
    $insert_query = "INSERT INTO notifications (user_email, notification_text, is_read, created_at) 
                    VALUES (?, ?, 0, NOW())";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("ss", $teacher_email, $notification_text);

    return $stmt->execute();
}
?><?php
// notifications_functions.php

function createEnrollmentNotification($student_email, $course_id, $conn) {
    // نفس الدالة الموجودة في teacher_show.php
    // انسخ الدالة من teacher_show.php وضعها هنا

    // 1. الحصول على معلومات الطالب
    $student_query = "SELECT firstname, lastname FROM users WHERE email = ?";
    $stmt = $conn->prepare($student_query);
    $stmt->bind_param("s", $student_email);
    $stmt->execute();
    $student_result = $stmt->get_result();

    if ($student_result->num_rows == 0) return false;

    $student = $student_result->fetch_assoc();
    $student_name = $student['firstname'] . ' ' . $student['lastname'];

    // 2. الحصول على معلومات الدورة والمعلم
    $course_query = "SELECT c.name, c.teacher FROM courses c WHERE c.id = ?";
    $stmt = $conn->prepare($course_query);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $course_result = $stmt->get_result();

    if ($course_result->num_rows == 0) return false;

    $course = $course_result->fetch_assoc();
    $course_name = $course['name'];
    $teacher_name = $course['teacher'];

    // 3. الحصول على إيميل المعلم
    $teacher_query = "SELECT u.email FROM users u 
                     WHERE CONCAT(u.firstname, ' ', u.lastname) = ?";
    $stmt = $conn->prepare($teacher_query);
    $stmt->bind_param("s", $teacher_name);
    $stmt->execute();
    $teacher_result = $stmt->get_result();

    if ($teacher_result->num_rows == 0) return false;

    $teacher = $teacher_result->fetch_assoc();
    $teacher_email = $teacher['email'];

    // 4. إنشاء الإشعار
    $notification_text = "$student_name has enrolled in $course_name";
    $insert_query = "INSERT INTO notifications (user_email, notification_text, is_read, created_at) 
                    VALUES (?, ?, 0, NOW())";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("ss", $teacher_email, $notification_text);

    return $stmt->execute();
}
?>