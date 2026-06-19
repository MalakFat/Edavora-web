<?php
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';
require_once 'PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
session_start();
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'edavora';

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');
$teacher_email = $_SESSION['email']; // <-- ننقله إلى هنا
$teacher_query = "SELECT u.firstname, u.lastname, u.profileimage, t.job_title 
                                FROM users u 
                                INNER JOIN teachers t ON u.email = t.email 
                                WHERE u.email = '$teacher_email'";
$teacher_result = mysqli_query($conn, $teacher_query);
$teacher = mysqli_fetch_assoc($teacher_result);
$teacher_name = $teacher['firstname'] . " " . $teacher['lastname'];

$teacher_image = $teacher['profileimage'] ?? 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';

// معالجة AJAX Requests
// ===== GENERATE AND SAVE NEW NOTIFICATIONS =====

// 1. Check for new student enrollments in teacher's courses
// We need to find courses where the teacher field matches this teacher's full name
$enrollment_query = "SELECT s.student_email, s.course_id, c.name as course_name, u.firstname, u.lastname,
                     (SELECT COUNT(*) FROM notifications n 
                      WHERE n.user_email = ? 
                      AND n.notification_text LIKE CONCAT('%', u.firstname, ' ', u.lastname, '%enrolled%', c.name, '%')
                     ) as notif_exists
                     FROM study s
                     INNER JOIN courses c ON s.course_id = c.id
                     INNER JOIN users u ON s.student_email = u.email
                     WHERE c.teacher = ?";
$stmt = $conn->prepare($enrollment_query);
$stmt->bind_param("ss", $teacher_email, $teacher_email);
$stmt->execute();
$enrollment_result = $stmt->get_result();

while ($enrollment = $enrollment_result->fetch_assoc()) {
    if ($enrollment['notif_exists'] == 0) {
        $notification_text = "{$enrollment['firstname']} {$enrollment['lastname']} has enrolled in {$enrollment['course_name']}";

        // Insert notification into database
        $insert_notif = "INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())";
        $stmt_insert = $conn->prepare($insert_notif);
        $stmt_insert->bind_param("ss", $teacher_email, $notification_text);
        $stmt_insert->execute();
    }
}

// 2. Check for upcoming courses (starting soon and 2 minutes before)
$current_time = date('H:i:s');
$current_day = date('l'); // Monday, Tuesday, etc.

$upcoming_courses_query = "SELECT id, name, start_time, days 
                           FROM courses 
                           WHERE teacher = ? 
                           AND FIND_IN_SET(?, days) > 0";
$stmt = $conn->prepare($upcoming_courses_query);
$stmt->bind_param("ss", $teacher_email, $current_day);
$stmt->execute();
$upcoming_result = $stmt->get_result();

while ($course = $upcoming_result->fetch_assoc()) {
    $start_time = $course['start_time'];
    $time_diff = strtotime($start_time) - strtotime($current_time);

    // Check if course starts in 2 minutes (between 0 and 120 seconds)
    if ($time_diff > 0 && $time_diff <= 120) {
        // Check if notification already exists for today
        $check_notif = "SELECT COUNT(*) as count FROM notifications 
                        WHERE user_email = ? 
                        AND notification_text LIKE ? 
                        AND DATE(created_at) = CURDATE()";
        $stmt_check = $conn->prepare($check_notif);
        $like_pattern = "%{$course['name']}%starts in 2 minutes%";
        $stmt_check->bind_param("ss", $teacher_email, $like_pattern);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();
        $check_data = $check_result->fetch_assoc();

        if ($check_data['count'] == 0) {
            $notification_text = "Course '{$course['name']}' starts in 2 minutes at {$start_time}";
            $insert_notif = "INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())";
            $stmt_insert = $conn->prepare($insert_notif);
            $stmt_insert->bind_param("ss", $teacher_email, $notification_text);
            $stmt_insert->execute();
        }
    }
}

// 3. Check for student pass/fail status
$status_query = "SELECT scs.student_email, scs.status, scs.course_id, c.name as course_name, u.firstname, u.lastname,
                 (SELECT COUNT(*) FROM notifications n 
                  WHERE n.user_email = ? 
                  AND n.notification_text LIKE CONCAT('%', u.firstname, ' ', u.lastname, '%', scs.status, '%', c.name, '%')
                 ) as notif_exists
                 FROM studentcoursestatus scs
                 INNER JOIN courses c ON scs.course_id = c.id
                 INNER JOIN users u ON scs.student_email = u.email
                 WHERE c.teacher = ? 
                 AND scs.status IN ('Passed', 'Failed')";
$stmt = $conn->prepare($status_query);
$stmt->bind_param("ss", $teacher_email, $teacher_email);
$stmt->execute();
$status_result = $stmt->get_result();

while ($status = $status_result->fetch_assoc()) {
    if ($status['notif_exists'] == 0) {
        $notification_text = "{$status['firstname']} {$status['lastname']} has {$status['status']} the course '{$status['course_name']}'";

        $insert_notif = "INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())";
        $stmt_insert = $conn->prepare($insert_notif);
        $stmt_insert->bind_param("ss", $teacher_email, $notification_text);
        $stmt_insert->execute();
    }
}

// 4. Check for missing attendance records
$missing_attendance_query = "SELECT l.course_id, l.lecture_date, c.name as course_name, c.start_time, c.end_time,
                             (SELECT COUNT(*) FROM notifications n 
                              WHERE n.user_email = ? 
                              AND n.notification_text LIKE CONCAT('%attendance%', c.name, '%', DATE_FORMAT(l.lecture_date, '%Y-%m-%d'), '%')
                              AND DATE(n.created_at) = CURDATE()
                             ) as notif_exists
                             FROM lectures l
                             INNER JOIN courses c ON l.course_id = c.id
                             WHERE c.teacher = ?
                             AND l.lecture_date = CURDATE()
                             AND TIME(NOW()) > c.end_time
                             AND NOT EXISTS (
                                 SELECT 1 FROM attendance a 
                                 WHERE a.course_id = l.course_id 
                                 AND a.lecture_date = l.lecture_date
                             )";
$stmt = $conn->prepare($missing_attendance_query);
$stmt->bind_param("ss", $teacher_email, $teacher_email);
$stmt->execute();
$missing_result = $stmt->get_result();

while ($missing = $missing_result->fetch_assoc()) {
    if ($missing['notif_exists'] == 0) {
        $notification_text = "Reminder: You haven't entered attendance for '{$missing['course_name']}' lecture on {$missing['lecture_date']}";

        $insert_notif = "INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())";
        $stmt_insert = $conn->prepare($insert_notif);
        $stmt_insert->bind_param("ss", $teacher_email, $notification_text);
        $stmt_insert->execute();
    }
}

// ===== FETCH ALL NOTIFICATIONS FROM DATABASE =====
$notif_query = "SELECT notification_id, notification_text, created_at, is_read 
                FROM notifications 
                WHERE user_email = ? 
                ORDER BY created_at DESC 
                LIMIT 20";
$stmt = $conn->prepare($notif_query);
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$notif_result = $stmt->get_result();

$all_notifications = [];
while ($notif = $notif_result->fetch_assoc()) {
    $time_ago = time_ago($notif['created_at']);

    // Determine notification type and icon based on content
    $type = 'info';
    $icon = 'fa-bell';
    $title = 'Notification';

    if (stripos($notif['notification_text'], 'enrolled') !== false) {
        $type = 'info';
        $icon = 'fa-user-plus';
        $title = 'New Student Enrollment';
    } elseif (stripos($notif['notification_text'], 'starts in') !== false) {
        $type = 'warning';
        $icon = 'fa-clock';
        $title = 'Course Starting Soon';
    } elseif (stripos($notif['notification_text'], 'Passed') !== false) {
        $type = 'success';
        $icon = 'fa-check-circle';
        $title = 'Student Passed';
    } elseif (stripos($notif['notification_text'], 'Failed') !== false) {
        $type = 'error';
        $icon = 'fa-times-circle';
        $title = 'Student Failed';
    } elseif (stripos($notif['notification_text'], 'attendance') !== false) {
        $type = 'warning';
        $icon = 'fa-exclamation-triangle';
        $title = 'Missing Attendance';
    } elseif (stripos($notif['notification_text'], 'registered') !== false || stripos($notif['notification_text'], 'course') !== false) {
        $type = 'success';
        $icon = 'fa-check-circle';
        $title = 'Course Registration';
    }

    $all_notifications[] = [
            'id' => $notif['notification_id'],
            'title' => $title,
            'message' => $notif['notification_text'],
            'time' => $time_ago,
            'is_read' => $notif['is_read'],
            'icon' => $icon,
            'type' => $type
    ];
}

// Count unread notifications
$notification_count = count(array_filter($all_notifications, function($n) {
    return $n['is_read'] == 0;
}));

function time_ago($datetime) {
    $timestamp = strtotime($datetime);
    $difference = time() - $timestamp;

    if ($difference < 60) {
        return "Just now";
    } elseif ($difference < 3600) {
        $minutes = floor($difference / 60);
        return $minutes . " minute" . ($minutes > 1 ? "s" : "") . " ago";
    } elseif ($difference < 86400) {
        $hours = floor($difference / 3600);
        return $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
    } else {
        $days = floor($difference / 86400);
        return $days . " day" . ($days > 1 ? "s" : "") . " ago";
    }
}

$teacher_email = isset($_SESSION['email']) ? $_SESSION['email'] : 'teacher@edavora.com';
$teacher_name="";
$teacher_query = "SELECT u.firstname, u.lastname, u.profileimage, t.job_title 
                                FROM users u 
                                INNER JOIN teachers t ON u.email = t.email 
                                WHERE u.email = '$teacher_email'";
$teacher_result = mysqli_query($conn, $teacher_query);
$teacher = mysqli_fetch_assoc($teacher_result);
$teacher_name = $teacher['firstname'] . " " . $teacher['lastname'];

// معالجة AJAX Requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'get_lectures':
            $course_id = intval($_GET['course_id']);
            $query = "SELECT * FROM lectures WHERE course_id = $course_id ORDER BY lecture_date DESC";
            $result = mysqli_query($conn, $query);
            $lectures = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $lectures[] = $row;
            }
            echo json_encode($lectures);
            exit;

        case 'get_attendance':
            $course_id = intval($_GET['course_id']);
            $lecture_date = mysqli_real_escape_string($conn, $_GET['lecture_date']);
            $query = "SELECT u.firstname, u.lastname, u.email, u.profileimage, 
                             COALESCE(a.attendance_status, 'P') as attendance_status
                      FROM study s
                      INNER JOIN users u ON s.student_email = u.email
                      LEFT JOIN attendance a ON s.student_email = a.student_email 
                        AND a.course_id = s.course_id 
                        AND a.lecture_date = '$lecture_date'
                      WHERE s.course_id = $course_id
                      ORDER BY u.firstname";
            $result = mysqli_query($conn, $query);
            $attendance = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $attendance[] = $row;
            }
            echo json_encode($attendance);
            exit;

        case 'get_students':
            $course_id = intval($_GET['course_id']);
            $query = "SELECT u.firstname, u.lastname, u.email, u.profileimage,
                             (SELECT COUNT(*) FROM attendance a 
                              WHERE a.student_email = u.email 
                              AND a.course_id = $course_id 
                              AND a.attendance_status = 'P') as present_count,
                             (SELECT COUNT(*) FROM attendance a 
                              WHERE a.student_email = u.email 
                              AND a.course_id = $course_id 
                              AND a.attendance_status = 'A') as absent_count
                      FROM study s
                      INNER JOIN users u ON s.student_email = u.email
                      WHERE s.course_id = $course_id
                      ORDER BY u.firstname";
            $result = mysqli_query($conn, $query);
            $students = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $students[] = $row;
            }
            echo json_encode($students);
            exit;
    }
}

// معالجة POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_lecture':
                $course_id = intval($_POST['course_id']);
                $lecture_date = mysqli_real_escape_string($conn, $_POST['lecture_date']);

                // التحقق من وجود المحاضرة مسبقاً
                $check_query = "SELECT * FROM lectures WHERE course_id = $course_id AND lecture_date = '$lecture_date'";
                $check_result = mysqli_query($conn, $check_query);

                if (mysqli_num_rows($check_result) > 0) {
                    echo json_encode(['success' => false, 'message' => 'Lecture already exists for this date']);
                    exit;
                }

                // إضافة المحاضرة الجديدة
                $query = "INSERT INTO lectures (course_id, lecture_date) VALUES ($course_id, '$lecture_date')";

                if (mysqli_query($conn, $query)) {
                    // إضافة سجلات الحضور للطلاب
                    $students_query = "SELECT student_email FROM study WHERE course_id = $course_id";
                    $students_result = mysqli_query($conn, $students_query);

                    while ($student = mysqli_fetch_assoc($students_result)) {
                        $attendance_query = "INSERT INTO attendance (student_email, course_id, lecture_date, attendance_status) 
                                           VALUES ('{$student['student_email']}', $course_id, '$lecture_date', 'P')";
                        mysqli_query($conn, $attendance_query);
                    }

                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
                }
                exit;

            case 'update_attendance':
                $course_id = intval($_POST['course_id']);
                $lecture_date = mysqli_real_escape_string($conn, $_POST['lecture_date']);
                $student_email = mysqli_real_escape_string($conn, $_POST['student_email']);
                $attendance_status = mysqli_real_escape_string($conn, $_POST['attendance_status']);

                // التحقق من وجود السجل
                $check_query = "SELECT * FROM attendance 
                              WHERE course_id = $course_id 
                              AND lecture_date = '$lecture_date' 
                              AND student_email = '$student_email'";
                $check_result = mysqli_query($conn, $check_query);

                if (mysqli_num_rows($check_result) > 0) {
                    // تحديث السجل الموجود
                    $query = "UPDATE attendance 
                              SET attendance_status = '$attendance_status'
                              WHERE course_id = $course_id 
                              AND lecture_date = '$lecture_date' 
                              AND student_email = '$student_email'";
                } else {
                    // إضافة سجل جديد
                    $query = "INSERT INTO attendance (student_email, course_id, lecture_date, attendance_status)
                              VALUES ('$student_email', $course_id, '$lecture_date', '$attendance_status')";
                }

                if (mysqli_query($conn, $query)) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
                }
                exit;

            case 'update_zoom_link':
                $course_id = intval($_POST['course_id']);
                $zoom_link = mysqli_real_escape_string($conn, $_POST['zoom_link']);

                $query = "UPDATE courses SET course_link = '$zoom_link' WHERE id = $course_id";

                if (mysqli_query($conn, $query)) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
                }
                exit;

            case 'delete_lecture':
                $course_id = intval($_POST['course_id']);
                $lecture_date = mysqli_real_escape_string($conn, $_POST['lecture_date']);

                $query = "DELETE FROM lectures WHERE course_id = $course_id AND lecture_date = '$lecture_date'";

                if (mysqli_query($conn, $query)) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
                }
                exit;

            case 'send_reminder':
                $course_id = intval($_POST['course_id']);

                // جلب معلومات الكورس والطلاب
                $course_query = "SELECT c.name, c.course_link FROM courses c WHERE c.id = $course_id";
                $course_result = mysqli_query($conn, $course_query);
                $course = mysqli_fetch_assoc($course_result);

                // جلب الطلاب المشتركين في الكورس
                $students_query = "SELECT u.email, u.firstname, u.lastname FROM study s 
                                   INNER JOIN users u ON s.student_email = u.email 
                                   WHERE s.course_id = $course_id";
                $students_result = mysqli_query($conn, $students_query);

                $sent_count = 0;
                $failed_count = 0;



                while ($student = mysqli_fetch_assoc($students_result)) {
                    try {
                        $mail = new PHPMailer(true);

                        // إعدادات SMTP
                        $mail->isSMTP();
                        $mail->Host = 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = 'stumal2905@gmail.com';
                        $mail->Password = 'cxzmtmvqlptcwbfj';
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port = 587;

                        $mail->SMTPOptions = array(
                                'ssl' => array(
                                        'verify_peer' => false,
                                        'verify_peer_name' => false,
                                        'allow_self_signed' => true
                                )
                        );

                        $mail->setFrom('your_email@gmail.com', 'Edavora Academy');
                        $mail->addAddress($student['email'], $student['firstname'] . ' ' . $student['lastname']);

                        $mail->isHTML(true);
                        $mail->Subject = 'Reminder: Join Our Lecture Now - ' . $course['name'];

                        $email_body = "
                            <!DOCTYPE html>
                            <html>
                            <head>
                                <style>
                                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                    .header { background-color: #524473; color: white; padding: 20px; text-align: center; }
                                    .content { padding: 30px; background-color: #f9f9f9; }
                                    .button { display: inline-block; padding: 12px 24px; background-color: #675788; 
                                              color: white; text-decoration: none; border-radius: 5px; margin: 15px 0; }
                                    .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                                </style>
                            </head>
                            <body>
                                <div class='container'>
                                    <div class='header'>
                                        <h1>Edavora Academy</h1>
                                    </div>
                                    <div class='content'>
                                        <h2>Reminder: Join Our Lecture Now</h2>
                                        <p>Dear " . $student['firstname'] . " " . $student['lastname'] . ",</p>
                                        <p>Please join our lecture for the course: <strong>" . $course['name'] . "</strong></p>
                                        <p>Click the link below to join the Zoom meeting:</p>
                                        <p>
                                            <a style='color: white' href='" . $course['course_link'] . "' class='button'>
                                                Join Zoom Lecture Now
                                            </a>
                                        </p>
                                        <p>Or copy and paste this link: <br>" . $course['course_link'] . "</p>
                                        <p>Best regards,<br>Edavora Academy Team</p>
                                    </div>
                                    <div class='footer'>
                                        <p>&copy; " . date('Y') . " Edavora Academy. All rights reserved.</p>
                                    </div>
                                </div>
                            </body>
                            </html>
                        ";

                        $mail->Body = $email_body;

                        if ($mail->send()) {
                            $sent_count++;
                        } else {
                            $failed_count++;
                        }
                    } catch (Exception $e) {
                        $failed_count++;
                    }
                }

                if ($sent_count > 0) {
                    echo json_encode([
                            'success' => true,
                            'message' => 'Reminder sent to ' . $sent_count . ' students successfully. ' .
                                    ($failed_count > 0 ? 'Failed to send to ' . $failed_count . ' students.' : '')
                    ]);
                } else {
                    echo json_encode([
                            'success' => false,
                            'message' => 'Failed to send reminder to any student.'
                    ]);
                }
                exit;
        }
    }
}

// جلب كورسات المعلم
$courses_query = "SELECT c.* FROM courses c 
                  INNER JOIN teachers t ON c.teacher = t.email 
                  WHERE t.email = '$teacher_email'";
$courses_result = mysqli_query($conn, $courses_query);
$courses = [];
while ($row = mysqli_fetch_assoc($courses_result)) {
    $courses[] = $row;
}
// ====================== نهاية جزء PHP ======================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edavora</title>
    <link rel="stylesheet" href="css/head.css">
    <link rel="stylesheet" href="css/footor.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="img/icon.png">
    <style>
        :root {
            --primary: #675788;
            --primary-dark: #524473;
            --primary-light: #887bae;
            --secondary: #FF6584;
            --dark: #675788;
            --light: #F8F9FF;
            --gray: #A0A4B8;
            --gray-light: #EFF0F7;
            --success: #4CAF50;
            --border-radius: 12px;
            --shadow: 0 10px 30px rgba(108, 99, 255, 0.1);
            --transition: all 0.3s ease;
        }
        /* Header CSS */
        .right-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .notification-container {
            position: relative;
        }

        .notification-icon {
            position: relative;
            cursor: pointer;
            color: white;
            font-size: 20px;
            padding: 8px;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #e74c3c;
            color: white;
            font-size: 12px;
            font-weight: bold;
            min-width: 20px;
            height: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
        }

        .user-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 25px;
            padding: 8px 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.3s;
            color: white;
        }

        .user-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .user-btn img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }

        .username {
            font-weight: 500;
        }

        .logout {
            background: rgba(231, 76, 60, 0.8);
            border: none;
            border-radius: 20px;
            padding: 8px 20px;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: background 0.3s;
        }

        .logout:hover {
            background: rgba(231, 76, 60, 1);
        }

        /* Notifications Dropdown */
        .notifications-dropdown {
            display: none;
            position: absolute;
            top: 50px;
            right: 0;
            width: 400px;
            max-width: 90vw;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            z-index: 1001;
        }

        .notification-container:hover .notifications-dropdown {
            display: block;
        }

        .notifications-header {
            padding: 15px 20px;
            border-bottom: 1px solid #8e77c5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgb(102, 87, 136);
            border-radius: 10px 10px 0 0;
        }

        .notifications-header h3 {
            margin: 0;
            color: white;
            font-size: 16px;
        }

        .mark-all-read {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            cursor: pointer;
            font-size: 13px;
            padding: 5px 12px;
            border-radius: 15px;
            transition: background 0.3s;
        }

        .mark-all-read:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .notifications-list {
            max-height: 450px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transition: background 0.2s;
            cursor: pointer;
        }

        .notification-item:hover {
            background: #f8f9fa;
        }

        .notification-item.unread {
            background: #e8f5e9;
        }

        .notification-item.unread:hover {
            background: #d4edda;
        }

        .notification-icon-small {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
            font-size: 18px;
        }

        .notification-icon-small.info {
            background: #665788
        }

        .notification-icon-small.success {
            background: green;
        }

        .notification-icon-small.warning {
            background: red;
        }

        .notification-icon-small.error {
            background:red;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 4px;
            font-size: 14px;
        }

        .notification-message {
            color: #555;
            font-size: 13px;
            margin-bottom: 4px;
            line-height: 1.5;
        }

        .notification-time {
            color: #999;
            font-size: 11px;
        }

        .notification-dot {
            width: 10px;
            height: 10px;
            background: #4caf50;
            border-radius: 50%;
            margin-top: 15px;
            flex-shrink: 0;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }

        .no-notifications {
            padding: 40px 20px;
            text-align: center;
            color: #999;
        }

        .no-notifications i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 15px;
        }

        .no-notifications p {
            margin: 0;
            font-size: 14px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light);
            color: var(--dark);
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            padding: 20px;
        }


        /* Courses Section */
        .courses-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .page-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .page-title {
            color: var(--primary);
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .page-subtitle {
            color: var(--gray);
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto;
        }

        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 15px;                    /* المسافة صغيرة وواضحة الآن */
            margin-bottom: 40px;
        }

        .course-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: var(--transition);
            border: 2px solid transparent;
            /* width: 420px; */         /* ← محذوف */
            /* height: 620px; */        /* ← محذوف */
            display: flex;
            flex-direction: column;
        }

        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(108, 99, 255, 0.15);
            border-color: var(--primary-light);
        }

        .course-image {
            height: 160px;
            overflow: hidden;
            position: relative;
            flex-shrink: 0;
        }

        .course-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .course-card:hover .course-image img {
            transform: scale(1.05);
        }

        .course-duration {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255, 255, 255, 0.9);
            color: var(--primary);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .course-content {
            padding: 25px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .course-title {
            font-size: 1.4rem;
            color: var(--dark);
            margin-bottom: 15px;
            font-weight: 700;
            line-height: 1.3;
            text-align: center;
        }

        .course-info {
            margin-bottom: 20px;
        }

        /* Course Schedule */
        .course-schedule {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding: 12px;
            background: var(--light);
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary);
        }

        .schedule-icon {
            color: var(--primary);
            font-size: 1.1rem;
        }

        .schedule-time {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .schedule-days {
            color: var(--gray);
            font-size: 0.9rem;
            margin-left: auto;
        }

        .instructor-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding: 15px;
            background: var(--gray-light);
            border-radius: var(--border-radius);
        }

        .instructor-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-light);
        }

        .instructor-details h4 {
            color: var(--dark);
            margin-bottom: 4px;
            font-size: 1rem;
        }

        .instructor-details p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .course-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 10px;
            background: var(--light);
            border-radius: var(--border-radius);
        }

        .stat-number {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 4px;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.8rem;
        }

        .course-actions {
            display: flex;
            gap: 10px;
            margin-top: auto;
        }

        .btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(103, 87, 136, 0.3);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        /* Teacher Lectures Page Styles */
        .lectures-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--gray-light);
            color: var(--dark);
            padding: 10px 20px;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            margin-bottom: 20px;
        }

        .back-btn:hover {
            background: var(--primary);
            color: white;
        }

        .teacher-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .zoom-control {
            display: flex;
            align-items: center;
            gap: 15px;
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            flex: 1;
            min-width: 300px;
        }

        .zoom-input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
        }

        .zoom-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .add-lecture-btn {
            background: var(--success);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: var(--border-radius);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: var(--transition);
        }

        .add-lecture-btn:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .lectures-table {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .table-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            margin: 0;
            font-size: 1.4rem;
        }

        .table-content {
            padding: 0;
        }

        .lecture-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto auto;
            gap: 15px;
            padding: 20px;
            border-bottom: 1px solid var(--gray-light);
            align-items: center;
        }

        .lecture-row:last-child {
            border-bottom: none;
        }

        .lecture-row:hover {
            background: var(--light);
        }

        .lecture-date {
            font-weight: 600;
            color: var(--dark);
        }

        .lecture-time {
            color: var(--gray);
        }

        .lecture-topic {
            color: var(--dark);
        }

        .attendance-controls {
            display: flex;
            gap: 8px;
        }

        .attendance-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-present {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .btn-present:hover {
            background: var(--success);
            color: white;
        }

        .btn-absent {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid #dc3545;
        }

        .btn-absent:hover {
            background: #dc3545;
            color: white;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 6px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .edit-btn {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid #ffc107;
        }

        .edit-btn:hover {
            background: #ffc107;
            color: white;
        }

        .delete-btn {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid #dc3545;
        }

        .delete-btn:hover {
            background: #dc3545;
            color: white;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--border-radius);
            padding: 30px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        /* تحسين مظهر قائمة الحضور */
        .attendance-list {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .attendance-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px;
            border-bottom: 1px solid var(--gray-light);
            transition: background-color 0.3s ease;
        }

        .attendance-item:hover {
            background-color: rgba(103, 87, 136, 0.05);
        }

        .attendance-item:last-child {
            border-bottom: none;
        }

        .attendance-select {
            padding: 8px 12px;
            border-radius: 6px;
            border: 2px solid #ddd;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 120px;
            text-align: center;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 15px;
        }

        .attendance-select:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(103, 87, 136, 0.2);
        }

        .attendance-select option {
            background-color: white;
            color: #333;
            padding: 8px;
        }

        .attendance-select option[value="P"] {
            color: #4CAF50;
            font-weight: bold;
        }

        .attendance-select option[value="A"] {
            color: #dc3545;
            font-weight: bold;
        }

        /* تأثير عند تغيير الحالة */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .attendance-select.changed {
            animation: pulse 0.5s ease;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            color: var(--primary);
            margin: 0;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-group input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }


        @media (max-width: 768px) {
            .courses-grid {
                grid-template-columns: 1fr;
            }

            .course-card {
                height: auto;
                min-height: 480px;
            }

            .lecture-row {
                grid-template-columns: 1fr;
                gap: 10px;
                text-align: center;
            }

            .teacher-controls {
                flex-direction: column;
            }

            .zoom-control {
                min-width: 100%;
            }

            .attendance-controls, .action-buttons {
                justify-content: center;
            }

        }

        @media (max-width: 480px) {
            .topbar-container {
                padding: 8px 2%;
            }

            .logo i {
                font-size: 24px;
                padding: 6px;
            }

            .logo-main {
                font-size: 18px;
            }

            .logo-sub {
                font-size: 8px;
            }

            .right-section {
                flex-direction: column;
                gap: 8px;
            }

            .user-btn {
                padding: 6px 10px;
            }

            .user-btn img {
                width: 32px;
                height: 32px;
            }

            .username {
                font-size: 12px;
            }

            .course-actions {
                flex-direction: column;
            }
        }

        .course-content-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-top: 30px;
        }

        .modules-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 25px;
        }

        .module-item {
            padding: 20px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            margin-bottom: 15px;
            cursor: pointer;
            transition: var(--transition);
        }

        .module-item:hover {
            border-color: var(--primary);
            background: var(--light);
        }

        .module-item.active {
            border-color: var(--primary);
            background: var(--primary-light);
            color: white;
        }

        .module-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .module-title {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .module-duration {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .module-content {
            display: none;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--gray-light);
        }

        .module-item.active .module-content {
            display: block;
        }

        .lesson-item {
            padding: 10px 15px;
            margin-bottom: 8px;
            background: var(--light);
            border-radius: var(--border-radius);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .lesson-title {
            flex: 1;
            color: #49386e;
        }

        .lesson-duration {
            color: var(--gray);
            font-size: 0.8rem;
        }

        /* Students Modal Styles */
        .students-modal .modal-content {
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .student-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            margin-bottom: 10px;
            background: white;
        }

        .student-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .student-email {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .student-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            text-align: center;
        }

        .stat-value {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.8rem;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--gray-light);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        /* Lecture Details Page Styles */
        .lecture-details-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .attendance-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 30px;
        }

        .attendance-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 25px;
        }

        .attendance-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .attendance-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px;
            border-bottom: 1px solid var(--gray-light);
        }

        .attendance-item:last-child {
            border-bottom: none;
        }

        .attendance-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-present {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success);!important;
        }

        .status-absent {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545; !important;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 20px;
        }

        /* Course Stats */
        .course-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            text-align: center;
        }

        .stat-icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 15px;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }

        /* Additional buttons in course cards */
        .course-actions-extended {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: auto;
        }
    </style>

    <script>
        function goToContent(courseId) {
            console.log(courseId);
            window.location.href = "save_course_session.php?course_id=" + courseId;
        }
    </script>

</head>
<body>
<header class="topbar">
    <div class="topbar-container">
        <div class="logo-section">
            <div class="logo">
                <i class="fas fa-graduation-cap" id="mybutton3"></i>
                <script>
                    document.getElementById("mybutton3").addEventListener("click", function() {
                        window.location.href = "teacher_home.php";
                    });
                </script>
                <div class="logo-text">
                    <span class="logo-main">EDVORA</span>
                    <span class="logo-sub">EDUCATIONAL ACADEMY</span>
                </div>
            </div>
        </div>

        <nav class="main-menu">
            <a href="teacher_home.php" >Home</a>
            <a href="TeacherCourses.php" class="active" >My Courses</a>
            <a href="teacher_show.php" >Show</a>
        </nav>

        <div class="right-section">
            <div class="notification-container">
                <div class="notification-icon">
                    <i class="fas fa-bell"></i>
                    <?php if ($notification_count > 0): ?>
                        <div class="notification-badge"><?php echo $notification_count; ?></div>
                    <?php endif; ?>
                </div>
                <div class="notifications-dropdown">
                    <div class="notifications-header">
                        <h3><i class="fas fa-bell"></i> Notifications</h3>
                        <?php if (count($all_notifications) > 0): ?>
                            <button class="mark-all-read" onclick="markAllAsRead()">Mark all read</button>
                        <?php endif; ?>
                    </div>
                    <div class="notifications-list">
                        <?php if (count($all_notifications) > 0): ?>
                            <?php foreach ($all_notifications as $notif): ?>
                                <div class="notification-item <?php echo $notif['is_read'] == 0 ? 'unread' : ''; ?>"
                                     data-id="<?php echo $notif['id']; ?>"
                                     onclick="markAsRead(<?php echo $notif['id']; ?>)">
                                    <div class="notification-icon-small <?php echo $notif['type']; ?>">
                                        <i class="fas <?php echo $notif['icon']; ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                        <div class="notification-time"><?php echo $notif['time']; ?></div>
                                    </div>
                                    <?php if ($notif['is_read'] == 0): ?>
                                        <div class="notification-dot"></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-notifications">
                                <i class="fas fa-bell-slash"></i>
                                <p>No notifications yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <button class="user-btn" id="mybutton">
                <img src="<?php echo htmlspecialchars($teacher_image); ?>" alt="User">
                <span class="username"><?php echo htmlspecialchars($teacher_name); ?></span>
            </button>
            <script>
                document.getElementById("mybutton").addEventListener("click", function() {
                    window.location.href ="TeacherProfile.php";
                });
            </script>
            <button class="logout" id="mybutton1">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
            <script>
                document.getElementById("mybutton1").addEventListener("click", function() {
                    window.location.href ="logout.php";
                });
            </script>
        </div>
    </div>
</header>


<main class="main-content" id="coursesPage">
    <div class="courses-container">
        <div class="page-header">
            <h1 class="page-title">My Teaching Courses</h1>
            <p class="page-subtitle">Manage your courses and lecture schedules</p>
        </div>

        <div class="courses-grid">
            <!-- عرض الكورسات من قاعدة البيانات -->
            <?php foreach ($courses as $course):
                // جلب عدد الطلاب لهذا الكورس
                $student_count_query = "SELECT COUNT(*) as count FROM study WHERE course_id = " . $course['id'];
                $student_count_result = mysqli_query($conn, $student_count_query);
                $student_count = mysqli_fetch_assoc($student_count_result);

                // جلب عدد المحاضرات لهذا الكورس
                $lecture_count_query = "SELECT COUNT(*) as count FROM lectures WHERE course_id = " . $course['id'];
                $lecture_count_result = mysqli_query($conn, $lecture_count_query);
                $lecture_count = mysqli_fetch_assoc($lecture_count_result);

                // جلب معلومات المعلم

                ?>
                <div class="course-card">
                    <div class="course-image">
                        <img src="<?php echo $course['image'] ?: 'https://images.unsplash.com/photo-1555066931-4365d14bab8c?ixlib=rb-1.2.1&auto=format&fit=crop&w=500&q=80'; ?>" alt="<?php echo htmlspecialchars($course['name']); ?>">
                        <div class="course-duration">
                            <?php echo $course['duration_number'] . ' ' . $course['duration_unit']; ?>
                        </div>
                    </div>
                    <div class="course-content">
                        <div class="course-info">
                            <h3 class="course-title"><?php echo htmlspecialchars($course['name']); ?></h3>

                            <div class="course-schedule">
                                <i class="fas fa-clock schedule-icon"></i>
                                <span class="schedule-time">
                                <?php echo date('h:i A', strtotime($course['start_time'])) . ' - ' . date('h:i A', strtotime($course['end_time'])); ?>
                            </span>
                                <span class="schedule-days"><?php echo htmlspecialchars($course['days']); ?></span>
                            </div>

                            <div class="instructor-info">
                                <img src="<?php echo $teacher['profileimage'] ?: 'https://randomuser.me/api/portraits/men/32.jpg'; ?>" alt="Instructor" class="instructor-avatar">
                                <div class="instructor-details">
                                    <h4><?php echo htmlspecialchars($teacher['firstname'] . ' ' . $teacher['lastname']); ?></h4>
                                    <p><?php echo htmlspecialchars($teacher['job_title']); ?></p>
                                </div>
                            </div>

                            <div class="course-stats">
                                <div class="stat-item" onclick="showStudentsModal(<?php echo $course['id']; ?>)" style="cursor: pointer;">
                                    <div class="stat-number"><?php echo $student_count['count']; ?></div>
                                    <div class="stat-label">Students</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo $lecture_count['count']; ?></div>
                                    <div class="stat-label">Lectures</div>
                                </div>
                            </div>
                        </div>

                        <div class="course-actions-extended">
                            <button class="btn btn-primary" onclick="showLectures(<?php echo $course['id']; ?>, '<?php echo htmlspecialchars($course['name']); ?>')">
                                <i class="fas fa-calendar-alt"></i> Lectures
                            </button>
                            <button class="btn btn-outline"
                                    onclick="goToContent(<?php echo $course['id']; ?>)">
                                <i class="fas fa-folder-open"></i> Content
                            </button>

                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<!-- Teacher Lectures Page -->
<main class="main-content" id="lecturesPage" style="display: none;">
    <div class="lectures-container">
        <a href="#" class="back-btn" onclick="showCourses()">
            <i class="fas fa-arrow-left"></i> Back to Courses
        </a>

        <div class="page-header">
            <h1 class="page-title" id="lectureCourseTitle">Manage Course Lectures</h1>
            <p class="page-subtitle">Add, edit, and manage lecture schedules and attendance</p>
        </div>

        <div class="teacher-controls">
            <div class="zoom-control">
                <input type="text" class="zoom-input" id="zoomLink" placeholder="Zoom Meeting Link">
                <button class="btn btn-primary" onclick="updateZoomLink()">
                    <i class="fas fa-save"></i> Update Link
                </button>
            </div>
            <button class="add-lecture-btn" onclick="openAddLectureModal()">
                <i class="fas fa-plus"></i> Add New Lecture
            </button>
        </div>

        <div class="lectures-table">
            <div class="table-header">
                <h3>Lecture Schedule</h3>
                <span id="studentsCount">0 Students</span>
            </div>
            <div class="table-content" id="lecturesTable">
                <!-- Lectures will be populated by JavaScript -->
            </div>
        </div>
    </div>
</main>

<!-- Lecture Details Page -->
<main class="main-content" id="lectureDetailsPage" style="display: none;">
    <div class="lecture-details-container">
        <a href="#" class="back-btn" onclick="backToLectures()">
            <i class="fas fa-arrow-left"></i> Back to Lectures
        </a>

        <div class="page-header">
            <h1 class="page-title" id="lectureDetailsTitle">Lecture Details</h1>
            <p class="page-subtitle">Manage attendance and lecture materials</p>
        </div>

        <div class="attendance-grid">
            <div class="attendance-section">
                <h3>Student Attendance</h3>
                <div class="attendance-list" id="attendanceList">
                    <!-- Attendance list will be loaded here -->
                </div>
            </div>

            <div class="attendance-section">
                <h3>Quick Actions</h3>
                <div class="quick-actions">
                    <button class="btn btn-primary" onclick="markAllPresent()">
                        <i class="fas fa-check-circle"></i> Mark All Present
                    </button>
                    <button class="btn btn-outline" onclick="markAllAbsent()">
                        <i class="fas fa-times-circle"></i> Mark All Absent
                    </button>
                    <button class="btn btn-primary" onclick="saveAttendance()">
                        <i class="fas fa-save"></i> Save Attendance
                    </button>
                    <button class="btn btn-outline" onclick="sendReminder()">
                        <i class="fas fa-bell"></i> Send Reminder
                    </button>
                </div>

                <div style="margin-top: 20px;">
                    <h4>Attendance Summary</h4>
                    <div class="course-stats">
                        <div class="stat-item">
                            <div class="stat-number" id="presentCount">0</div>
                            <div class="stat-label">Present</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number" id="absentCount">0</div>
                            <div class="stat-label">Absent</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number" id="attendanceRate">0%</div>
                            <div class="stat-label">Attendance Rate</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Modals -->
<!-- Add Lecture Modal -->
<div class="modal" id="addLectureModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Lecture</h3>
            <button class="close-modal" onclick="closeAddLectureModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="form-group">
            <label for="lectureDate">Date</label>
            <input type="date" id="lectureDate" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="form-group">
            <label for="lectureTime">Time</label>
            <input type="text" id="lectureTime" value="10:00-12:00" readonly>
        </div>
        <div class="form-actions">
            <button class="btn btn-outline" onclick="closeAddLectureModal()">Cancel</button>
            <button class="btn btn-primary" onclick="addNewLecture()">Add Lecture</button>
        </div>
    </div>
</div>

<!-- Students Modal -->
<div class="modal students-modal" id="studentsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Students List</h3>
            <button class="close-modal" onclick="closeStudentsModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="studentsList">
            <!-- Students will be loaded here -->
        </div>
    </div>
</div>


<footer>
    <div class="containerf">
        <div class="contact-info">
            <a href="tel:0591234566"><i class="fas fa-phone"></i> : 0591234566</a>
        </div>
        <div class="social-links">
            <a href="#"><i class="fab fa-facebook-f"></i></a>
            <a href="#"><i class="fab fa-instagram"></i></a>
            <a href="#"><i class="fab fa-linkedin-in"></i></a>
            <a href="#"><i class="fab fa-twitter"></i></a>
        </div>
        <p style="margin-top: 5px;"> &copy; 2025 Coding Courses Platform. All rights reserved.</p>
    </div>
</footer>

<script>
    // المتغيرات العامة
    let currentCourseId = null;
    let currentCourseName = '';
    let currentLectureDate = '';
    let attendanceData = {};

    // دوال التنقل بين الصفحات
    function showCourses() {
        document.getElementById('coursesPage').style.display = 'block';
        document.getElementById('lecturesPage').style.display = 'none';
        document.getElementById('lectureDetailsPage').style.display = 'none';
    }

    function showLectures(courseId, courseName) {
        currentCourseId = courseId;
        currentCourseName = courseName;

        document.getElementById('coursesPage').style.display = 'none';
        document.getElementById('lecturesPage').style.display = 'block';
        document.getElementById('lectureDetailsPage').style.display = 'none';

        document.getElementById('lectureCourseTitle').textContent = `Manage Lectures - ${courseName}`;

        // جلب المحاضرات من قاعدة البيانات
        loadLectures(courseId);
        // جلب عدد الطلاب
        loadStudentsCount(courseId);
        // جلب رابط Zoom
        loadZoomLink(courseId);
    }

    function backToLectures() {
        document.getElementById('lecturesPage').style.display = 'block';
        document.getElementById('lectureDetailsPage').style.display = 'none';
    }

    // دوال إدارة المحاضرات
    async function loadLectures(courseId) {
        try {
            const response = await fetch(`TeacherCourses.php?action=get_lectures&course_id=${courseId}`);
            const lectures = await response.json();

            const table = document.getElementById('lecturesTable');
            table.innerHTML = '';

            if (lectures.length === 0) {
                table.innerHTML = '<div style="padding: 20px; text-align: center; color: var(--gray);">No lectures scheduled yet</div>';
                return;
            }

            lectures.forEach(lecture => {
                const lectureRow = document.createElement('div');
                lectureRow.className = 'lecture-row';

                const date = new Date(lecture.lecture_date);
                const formattedDate = date.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });

                lectureRow.innerHTML = `
                    <div class="lecture-date">${formattedDate}</div>
                    <div class="lecture-time">10:00 AM - 12:00 PM</div>
                    <div class="lecture-topic">Lecture on ${formattedDate}</div>
                    <div class="attendance-controls">
                        <button class="attendance-btn btn-present" onclick="showLectureDetails('${lecture.lecture_date}')">
                            <i class="fas fa-users"></i> Attendance
                        </button>
                    </div>
                    <div class="action-buttons">
                        <button class="action-btn delete-btn" onclick="deleteLecture('${lecture.lecture_date}')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                `;

                table.appendChild(lectureRow);
            });
        } catch (error) {
            console.error('Error loading lectures:', error);
        }
    }

    async function loadStudentsCount(courseId) {
        try {
            const response = await fetch(`TeacherCourses.php?action=get_students&course_id=${courseId}`);
            const students = await response.json();
            document.getElementById('studentsCount').textContent = `${students.length} Students`;
        } catch (error) {
            console.error('Error loading students count:', error);
        }
    }

    async function loadZoomLink(courseId) {
        try {
            const response = await fetch(`TeacherCourses.php?action=get_course_info&course_id=${courseId}`);
            const course = await response.json();
            document.getElementById('zoomLink').value = course.course_link || '';
        } catch (error) {
            console.error('Error loading zoom link:', error);
            document.getElementById('zoomLink').value = '';
        }
    }

    // دوال إدارة الحضور
    async function showLectureDetails(lectureDate) {
        currentLectureDate = lectureDate;

        document.getElementById('lecturesPage').style.display = 'none';
        document.getElementById('lectureDetailsPage').style.display = 'block';

        const date = new Date(lectureDate);
        const formattedDate = date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        document.getElementById('lectureDetailsTitle').textContent = `Lecture: ${formattedDate}`;

        await loadAttendanceData(lectureDate);
    }

    async function loadAttendanceData(lectureDate) {
        try {
            const response = await fetch(`TeacherCourses.php?action=get_attendance&course_id=${currentCourseId}&lecture_date=${lectureDate}`);
            const attendance = await response.json();

            const attendanceList = document.getElementById('attendanceList');
            attendanceList.innerHTML = '';

            let presentCount = 0;
            let absentCount = 0;

            attendance.forEach(student => {
                const isPresent = student.attendance_status === 'P';
                if (isPresent) presentCount++;
                else absentCount++;

                const attendanceItem = document.createElement('div');
                attendanceItem.className = 'attendance-item';

                // إنشاء معرف فريد لكل طالب لتجنب مشاكل الاقتباسات
                const studentId = student.email.replace(/[^a-zA-Z0-9]/g, '_');

                attendanceItem.innerHTML = `
                <img src="${student.profileimage || 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'}"
                     alt="${student.firstname}" class="student-avatar">
                <div class="student-info">
                    <div class="student-name">${student.firstname} ${student.lastname}</div>
                    <div class="student-email">${student.email}</div>
                </div>
                <select class="attendance-select" id="attendance_${studentId}" data-email="${student.email}"
                        onchange="updateAttendanceStatus('${student.email}', this.value)">
                    <option value="P" ${isPresent ? 'selected' : ''}>Present</option>
                    <option value="A" ${!isPresent ? 'selected' : ''}>Absent</option>
                </select>
            `;
                attendanceList.appendChild(attendanceItem);

                // تطبيق اللون بعد إنشاء العنصر
                const selectElement = document.getElementById(`attendance_${studentId}`);
                updateAttendanceColor(selectElement);
            });

            const totalStudents = attendance.length;
            const attendanceRate = totalStudents > 0 ? Math.round((presentCount / totalStudents) * 100) : 0;

            document.getElementById('presentCount').textContent = presentCount;
            document.getElementById('absentCount').textContent = absentCount;
            document.getElementById('attendanceRate').textContent = `${attendanceRate}%`;

            // حفظ البيانات محلياً
            attendanceData = {};
            attendance.forEach(student => {
                attendanceData[student.email] = student.attendance_status;
            });

        } catch (error) {
            console.error('Error loading attendance:', error);
        }
    }

    // دالة لتحديث لون select الحضور
    function updateAttendanceColor(selectElement) {
        if (selectElement.value === 'P') {
            selectElement.style.backgroundColor = 'rgba(76, 175, 80, 0.1)';
            selectElement.style.color = '#4CAF50';
            selectElement.style.border = '1px solid #4CAF50';
            selectElement.style.fontWeight = 'bold';
        } else {
            selectElement.style.backgroundColor = 'rgba(220, 53, 69, 0.1)';
            selectElement.style.color = '#dc3545';
            selectElement.style.border = '1px solid #dc3545';
            selectElement.style.fontWeight = 'bold';
        }
    }

    // تعديل دالة updateAttendanceStatus لتطبيق اللون
    async function updateAttendanceStatus(studentEmail, status) {
        attendanceData[studentEmail] = status;

        // تحديث اللون مباشرة
        const selectElement = document.querySelector(`[data-email="${studentEmail}"]`);
        if (selectElement) {
            updateAttendanceColor(selectElement);
        }

        const formData = new FormData();
        formData.append('action', 'update_attendance');
        formData.append('course_id', currentCourseId);
        formData.append('lecture_date', currentLectureDate);
        formData.append('student_email', studentEmail);
        formData.append('attendance_status', status);

        try {
            const response = await fetch('TeacherCourses.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                updateAttendanceSummary();
            } else {
                // في حالة الخطأ، ارجع للحالة السابقة
                const previousStatus = attendanceData[studentEmail] === 'P' ? 'A' : 'P';
                if (selectElement) {
                    selectElement.value = previousStatus;
                    updateAttendanceColor(selectElement);
                }
            }
        } catch (error) {
            console.error('Error updating attendance:', error);
        }
    }

    // تعديل دوال markAllPresent و markAllAbsent
    function markAllPresent() {
        const selects = document.querySelectorAll('.attendance-select');
        selects.forEach(select => {
            select.value = 'P';
            updateAttendanceStatus(select.dataset.email, 'P');
            updateAttendanceColor(select);
        });
    }

    function markAllAbsent() {
        const selects = document.querySelectorAll('.attendance-select');
        selects.forEach(select => {
            select.value = 'A';
            updateAttendanceStatus(select.dataset.email, 'A');
            updateAttendanceColor(select);
        });
    }

    function updateAttendanceSummary() {
        let presentCount = 0;
        let absentCount = 0;

        Object.values(attendanceData).forEach(status => {
            if (status === 'P') presentCount++;
            else absentCount++;
        });

        const totalStudents = Object.keys(attendanceData).length;
        const attendanceRate = totalStudents > 0 ? Math.round((presentCount / totalStudents) * 100) : 0;

        document.getElementById('presentCount').textContent = presentCount;
        document.getElementById('absentCount').textContent = absentCount;
        document.getElementById('attendanceRate').textContent = `${attendanceRate}%`;
    }

    function saveAttendance() {

    }

    async function sendReminder() {
        if (!currentCourseId) {
            return;
        }


        const formData = new FormData();
        formData.append('action', 'send_reminder');
        formData.append('course_id', currentCourseId);

        try {
            const response = await fetch('TeacherCourses.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
            } else {
            }
        } catch (error) {
            console.error('Error sending reminder:', error);
        }
    }

    async function showStudentsModal(courseId) {
        try {
            const response = await fetch(`TeacherCourses.php?action=get_students&course_id=${courseId}`);
            const students = await response.json();

            const studentsList = document.getElementById('studentsList');
            studentsList.innerHTML = '';

            students.forEach(student => {
                const studentCard = document.createElement('div');
                studentCard.className = 'student-card';

                const totalLectures = parseInt(student.present_count) + parseInt(student.absent_count);
                const attendancePercentage = totalLectures > 0
                    ? Math.round((parseInt(student.present_count) / totalLectures) * 100)
                    : 0;

                studentCard.innerHTML = `
                    <img src="${student.profileimage || 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'}"
                         alt="${student.firstname}" class="student-avatar">
                    <div class="student-info">
                        <div class="student-name">${student.firstname} ${student.lastname}</div>
                        <div class="student-email">${student.email}</div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: ${attendancePercentage}%"></div>
                        </div>
                    </div>
                    <div class="student-stats">
                        <div>
                            <div class="stat-value">${student.present_count || 0}</div>
                            <div class="stat-label">Present</div>
                        </div>
                        <div>
                            <div class="stat-value">${student.absent_count || 0}</div>
                            <div class="stat-label">Absent</div>
                        </div>
                        <div>
                            <div class="stat-value">${attendancePercentage}%</div>
                            <div class="stat-label">Attendance</div>
                        </div>
                    </div>
                `;
                studentsList.appendChild(studentCard);
            });

            document.getElementById('studentsModal').classList.add('active');
        } catch (error) {
            console.error('Error loading students:', error);
        }
    }

    function closeStudentsModal() {
        document.getElementById('studentsModal').classList.remove('active');
    }

    // دوال إدارة مودال إضافة المحاضرات
    function openAddLectureModal() {
        if (!currentCourseId) return;
        document.getElementById('addLectureModal').classList.add('active');
    }

    function closeAddLectureModal() {
        document.getElementById('addLectureModal').classList.remove('active');
    }

    async function addNewLecture() {
        const lectureDate = document.getElementById('lectureDate').value;

        if (!lectureDate) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'add_lecture');
        formData.append('course_id', currentCourseId);
        formData.append('lecture_date', lectureDate);

        try {
            const response = await fetch('TeacherCourses.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                closeAddLectureModal();
                loadLectures(currentCourseId);
            } else {
            }
        } catch (error) {
            console.error('Error adding lecture:', error);
        }
    }

    async function deleteLecture(lectureDate) {


        const formData = new FormData();
        formData.append('action', 'delete_lecture');
        formData.append('course_id', currentCourseId);
        formData.append('lecture_date', lectureDate);

        try {
            const response = await fetch('TeacherCourses.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                loadLectures(currentCourseId);
            } else {
            }
        } catch (error) {
            console.error('Error deleting lecture:', error);
        }
    }

    async function updateZoomLink() {
        const zoomLink = document.getElementById('zoomLink').value;

        if (!zoomLink) {
            alert('Please enter a Zoom link');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'update_zoom_link');
        formData.append('course_id', currentCourseId);
        formData.append('zoom_link', zoomLink);

        try {
            const response = await fetch('TeacherCourses.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
            } else {
            }
        } catch (error) {
            console.error('Error updating zoom link:', error);
        }
    }

    // التهيئة الأولية
    document.addEventListener('DOMContentLoaded', function() {
        console.log('EDVORA Teacher Dashboard loaded');
        // إخفاء الصفحات غير المطلوبة
        document.getElementById('lecturesPage').style.display = 'none';
        document.getElementById('lectureDetailsPage').style.display = 'none';
    });
    // Mark single notification as read
    function markAsRead(notificationId) {
        fetch('mark_notification_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'notification_id=' + notificationId
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => console.error('Error:', error));
    }

    // Mark all notifications as read
    function markAllAsRead() {
        fetch('mark_all_notifications_read.php', {
            method: 'POST'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => console.error('Error:', error));
    }

    if (prevBtn) prevBtn.addEventListener('click', prevSlide);
    if (nextBtn) nextBtn.addEventListener('click', nextSlide);
    if (closeModal) closeModal.addEventListener('click', closeImageModal);

    startAutoPlay();
</script>
</body>
</html>