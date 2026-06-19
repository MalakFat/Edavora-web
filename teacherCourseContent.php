<?php
session_start();
$course_id = isset($_SESSION['course_id']) ? $_SESSION['course_id'] : 0;

// ====================== قاعدة البيانات ======================
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'edavora';

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
$teacher_email = $_SESSION['email']; // <-- ننقله إلى هنا
mysqli_set_charset($conn, 'utf8mb4');
// Fetch teacher information
$teacher_query = "SELECT u.firstname, u.lastname, u.profileimage 
                  FROM users u 
                  INNER JOIN teachers t ON u.email = t.email 
                  WHERE u.email = ?";
$stmt = $conn->prepare($teacher_query);
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$teacher_result = $stmt->get_result();

if ($teacher_result->num_rows == 0) {
    die("Teacher not found");
}

$teacher_data = $teacher_result->fetch_assoc();
$teacher_name = $teacher_data['firstname'] . ' ' . $teacher_data['lastname'];
$teacher_image = $teacher_data['profileimage'] ?? 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';


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

$course_query = "SELECT * FROM courses WHERE id = $course_id";
$course_result = mysqli_query($conn, $course_query);
$course = mysqli_fetch_assoc($course_result);

if (!$course) {
    die("Course not found");
}

// معالجة AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'get_chapters':
            $chapters_query = "SELECT * FROM chapters WHERE course_id = $course_id ORDER BY chapter_order";
            $chapters_result = mysqli_query($conn, $chapters_query);
            $chapters = [];
            while ($row = mysqli_fetch_assoc($chapters_result)) {
                $chapters[] = $row;
            }
            echo json_encode($chapters);
            exit;

        case 'get_lessons':
            $chapter_id = intval($_GET['chapter_id']);
            $lessons_query = "SELECT * FROM lessons WHERE chapter_id = $chapter_id ORDER BY lesson_order";
            $lessons_result = mysqli_query($conn, $lessons_query);
            $lessons = [];
            while ($row = mysqli_fetch_assoc($lessons_result)) {
                $lessons[] = $row;
            }
            echo json_encode($lessons);
            exit;

        case 'get_lesson_content':
            $lesson_id = intval($_GET['lesson_id']);

            // جلب محتوى الدرس
            $content_query = "SELECT * FROM lesson_contents WHERE lesson_id = $lesson_id ORDER BY content_order";
            $content_result = mysqli_query($conn, $content_query);
            $contents = [];
            while ($row = mysqli_fetch_assoc($content_result)) {
                $contents[] = $row;
            }

            // جلب سؤال الاختبار
            $quiz_query = "SELECT * FROM lesson_quizzes WHERE lesson_id = $lesson_id";
            $quiz_result = mysqli_query($conn, $quiz_query);
            $quiz = mysqli_fetch_assoc($quiz_result);

            echo json_encode([
                'contents' => $contents,
'quiz' => $quiz
]);
exit;
case 'get_single_content':
            $content_id = intval($_GET['content_id']);
            $content_query = "SELECT * FROM lesson_contents WHERE content_id = $content_id";
            $content_result = mysqli_query($conn, $content_query);
            if ($content_result && mysqli_num_rows($content_result) > 0) {
                $content = mysqli_fetch_assoc($content_result);
                echo json_encode($content);
            } else {
                echo json_encode(['error' => 'Content not found']);
            }
            exit;
case 'get_lesson_info':
$lesson_id = intval($_GET['lesson_id']);
$lesson_query = "SELECT * FROM lessons WHERE lesson_id = $lesson_id";
$lesson_result = mysqli_query($conn, $lesson_query);
$lesson = mysqli_fetch_assoc($lesson_result);
echo json_encode($lesson);
exit;
}
}

// معالجة POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
header('Content-Type: application/json');

if (isset($_POST['action'])) {
switch ($_POST['action']) {
case 'save_chapter':
$chapter_name = mysqli_real_escape_string($conn, $_POST['chapter_name']);

// الحصول على آخر ترتيب
$order_query = "SELECT MAX(chapter_order) as max_order FROM chapters WHERE course_id = $course_id";
$order_result = mysqli_query($conn, $order_query);
$order_row = mysqli_fetch_assoc($order_result);
$new_order = $order_row['max_order'] ? $order_row['max_order'] + 1 : 1;

$insert_query = "INSERT INTO chapters (course_id, chapter_name, chapter_order)
VALUES ($course_id, '$chapter_name', $new_order)";

if (mysqli_query($conn, $insert_query)) {
echo json_encode([
'success' => true,
'chapter_id' => mysqli_insert_id($conn)
]);
} else {
echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
exit;

case 'save_lesson':
$chapter_id = intval($_POST['chapter_id']);
$lesson_title = mysqli_real_escape_string($conn, $_POST['lesson_title']);

// الحصول على آخر ترتيب
$order_query = "SELECT MAX(lesson_order) as max_order FROM lessons WHERE chapter_id = $chapter_id";
$order_result = mysqli_query($conn, $order_query);
$order_row = mysqli_fetch_assoc($order_result);
$new_order = $order_row['max_order'] ? $order_row['max_order'] + 1 : 1;

$insert_query = "INSERT INTO lessons (chapter_id, course_id, lesson_title, lesson_order)
VALUES ($chapter_id, $course_id, '$lesson_title', $new_order)";

if (mysqli_query($conn, $insert_query)) {
echo json_encode([
'success' => true,
'lesson_id' => mysqli_insert_id($conn)
]);
} else {
echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
exit;

case 'save_content':
$lesson_id = intval($_POST['lesson_id']);
$content_type = mysqli_real_escape_string($conn, $_POST['content_type']);
$content_title = mysqli_real_escape_string($conn, $_POST['content_title']);

// الحصول على آخر ترتيب
$order_query = "SELECT MAX(content_order) as max_order FROM lesson_contents WHERE lesson_id = $lesson_id";
$order_result = mysqli_query($conn, $order_query);
$order_row = mysqli_fetch_assoc($order_result);
$new_order = $order_row['max_order'] ? $order_row['max_order'] + 1 : 1;

if ($content_type === 'text') {
$content_text = mysqli_real_escape_string($conn, $_POST['content_text']);
$insert_query = "INSERT INTO lesson_contents (lesson_id, content_type, content_title, content_text, content_order)
VALUES ($lesson_id, 'text', '$content_title', '$content_text', $new_order)";
} else {
$code_content = mysqli_real_escape_string($conn, $_POST['code_content']);
$code_language = mysqli_real_escape_string($conn, $_POST['code_language']);
$insert_query = "INSERT INTO lesson_contents (lesson_id, content_type, content_title, code_content, code_language, content_order)
VALUES ($lesson_id, 'code', '$content_title', '$code_content', '$code_language', $new_order)";
}

if (mysqli_query($conn, $insert_query)) {
echo json_encode([
'success' => true,
'content_id' => mysqli_insert_id($conn)
]);
} else {
echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
exit;

case 'update_content':
$content_id = intval($_POST['content_id']);
$content_title = mysqli_real_escape_string($conn, $_POST['content_title']);

// التحقق من نوع المحتوى
$check_query = "SELECT content_type FROM lesson_contents WHERE content_id = $content_id";
$check_result = mysqli_query($conn, $check_query);
$check_row = mysqli_fetch_assoc($check_result);

if ($check_row['content_type'] === 'text') {
$content_text = mysqli_real_escape_string($conn, $_POST['content_text']);
$update_query = "UPDATE lesson_contents SET content_title = '$content_title', content_text = '$content_text' WHERE content_id = $content_id";
} else {
$code_content = mysqli_real_escape_string($conn, $_POST['code_content']);
$code_language = mysqli_real_escape_string($conn, $_POST['code_language']);
$update_query = "UPDATE lesson_contents SET content_title = '$content_title', code_content = '$code_content', code_language = '$code_language' WHERE content_id = $content_id";
}

if (mysqli_query($conn, $update_query)) {
echo json_encode(['success' => true]);
} else {
echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
exit;

case 'delete_content':
$content_id = intval($_POST['content_id']);
$delete_query = "DELETE FROM lesson_contents WHERE content_id = $content_id";

if (mysqli_query($conn, $delete_query)) {
echo json_encode(['success' => true]);
} else {
echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
exit;

case 'save_quiz':
$lesson_id = intval($_POST['lesson_id']);
$question_text = mysqli_real_escape_string($conn, $_POST['question_text']);
$option_a = mysqli_real_escape_string($conn, $_POST['option_a']);
$option_b = mysqli_real_escape_string($conn, $_POST['option_b']);
$option_c = mysqli_real_escape_string($conn, $_POST['option_c']);
$option_d = mysqli_real_escape_string($conn, $_POST['option_d']);
$correct_answer = mysqli_real_escape_string($conn, $_POST['correct_answer']);

// التحقق إذا كان هناك سؤال موجود
$check_query = "SELECT * FROM lesson_quizzes WHERE lesson_id = $lesson_id";
$check_result = mysqli_query($conn, $check_query);

if (mysqli_num_rows($check_result) > 0) {
// تحديث السؤال الموجود
$update_query = "UPDATE lesson_quizzes SET
question_text = '$question_text',
option_a = '$option_a',
option_b = '$option_b',
option_c = '$option_c',
option_d = '$option_d',
correct_answer = '$correct_answer'
WHERE lesson_id = $lesson_id";
$query = $update_query;
} else {
// إضافة سؤال جديد
$insert_query = "INSERT INTO lesson_quizzes (lesson_id, question_text, option_a, option_b, option_c, option_d, correct_answer)
VALUES ($lesson_id, '$question_text', '$option_a', '$option_b', '$option_c', '$option_d', '$correct_answer')";
$query = $insert_query;
}

if (mysqli_query($conn, $query)) {
echo json_encode(['success' => true]);
} else {
echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
exit;

case 'delete_chapter':
$chapter_id = intval($_POST['chapter_id']);
$delete_query = "DELETE FROM chapters WHERE chapter_id = $chapter_id";

if (mysqli_query($conn, $delete_query)) {
echo json_encode(['success' => true]);
} else {
echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
exit;

case 'delete_lesson':
$lesson_id = intval($_POST['lesson_id']);
$delete_query = "DELETE FROM lessons WHERE lesson_id = $lesson_id";

if (mysqli_query($conn, $delete_query)) {
echo json_encode(['success' => true]);
} else {
echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
exit;

case 'update_lesson_title':
$lesson_id = intval($_POST['lesson_id']);
$lesson_title = mysqli_real_escape_string($conn, $_POST['lesson_title']);
$update_query = "UPDATE lessons SET lesson_title = '$lesson_title' WHERE lesson_id = $lesson_id";

if (mysqli_query($conn, $update_query)) {
echo json_encode(['success' => true]);
} else {
echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
exit;

case 'update_chapter_title':
$chapter_id = intval($_POST['chapter_id']);
$chapter_name = mysqli_real_escape_string($conn, $_POST['chapter_name']);
$update_query = "UPDATE chapters SET chapter_name = '$chapter_name' WHERE chapter_id = $chapter_id";

if (mysqli_query($conn, $update_query)) {
echo json_encode(['success' => true]);
} else {
echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
exit;
}
}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edavora</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/head.css">
    <link rel="icon" type="image/png" href="img/icon.png">


    <style>
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

        <?php
        // حفظ جميع أنماط CSS من الملف الأصلي في متغير
        $css_styles = '
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;
        }

        :root {
            --primary: #675788;
            --primary-dark: #524473;
            --light-purple: #e6e1f7;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --border: #dee2e6;
        }

        body {
            background-color: #f5f7fb;
            color: var(--dark);
        }

        .course-container {
            display: flex;
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
            gap: 30px;
        }

        .course-sidebar {
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            width: 350px;
            padding: 25px;
            height: fit-content;
            position: sticky;
            top: 90px;
        }

        .sidebar-title {
            font-size: 1.8rem;
            color: var(--dark);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--primary);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-title i {
            color: var(--primary);
        }

        .sidebar-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .sidebar-btn {
            flex: 1;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.3s;
            font-size: 0.9rem;
        }

        .sidebar-btn:hover {
            background: var(--primary-dark);
        }

        .sidebar-btn.add-chapter {
            background: var(--primary-dark);
        }

        .sidebar-btn.add-chapter:hover {
            background: #473b6a;
        }

        .chapter-section {
            margin-bottom: 30px;
        }

        .chapter-title {
            font-size: 1.4rem;
            color: var(--dark);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border);
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chapter-actions {
            display: flex;
            gap: 8px;
        }

        .chapter-action {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            font-size: 0.9rem;
            padding: 5px;
            border-radius: 4px;
            transition: all 0.3s;
        }

        .chapter-action:hover {
            background: rgba(0, 0, 0, 0.05);
        }

        .chapter-action.edit:hover {
            color: var(--primary);
        }

        .chapter-action.delete:hover {
            color: var(--danger);
        }

        .lessons-list {
            list-style: none;
        }

        .lesson-item {
            padding: 12px 15px;
            margin-bottom: 8px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .lesson-item:hover {
            background: #f0f3ff;
        }

        .lesson-item.active {
            background: var(--light-purple);
            font-weight: 500;
        }

        .lesson-actions {
            display: flex;
            gap: 8px;
        }

        .lesson-action {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            font-size: 0.8rem;
            padding: 4px;
            border-radius: 3px;
            transition: all 0.3s;
        }

        .lesson-action:hover {
            background: rgba(0, 0, 0, 0.05);
        }

        .lesson-action.edit:hover {
            color: var(--primary);
        }

        .lesson-action.delete:hover {
            color: var(--danger);
        }

        .course-content {
            flex: 1;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            padding: 40px;
            min-height: 80vh;
        }

        .lesson-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid var(--primary);
        }

        .lesson-title {
            font-size: 2rem;
            color: var(--dark);
            font-weight: 700;
        }

        .save-btn {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 12px 25px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: background 0.3s;
        }

        .save-btn:hover {
            background: var(--primary-dark);
        }

        .content-editor {
            margin-bottom: 40px;
            padding: 30px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 2px dashed var(--border);
        }

        .editor-title {
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 20px;
            font-weight: 600;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
        }

        .form-group label {
            font-weight: 600;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .form-control {
            width: 100%;
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            transition: border 0.3s;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(103, 87, 136, 0.2);
        }

        .content-type-selector {
            display: flex;
            gap: 15px;
            margin-top: 15px;
        }

        .type-btn {
            flex: 1;
            padding: 15px;
            border: 2px solid var(--border);
            background: white;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .type-btn:hover {
            border-color: var(--primary);
            background: #f0f3ff;
        }

        .type-btn.active {
            border-color: var(--primary);
            background: #e7f1ff;
            color: var(--primary);
        }

        .code-editor-container {
            background: #1a202c;
            border-radius: 8px;
            overflow: hidden;
            margin-top: 10px;
        }

        .code-header {
            background: #2d3748;
            color: #e2e8f0;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .code-language {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .code-language select {
            background: #4a5568;
            color: white;
            border: 1px solid #718096;
            padding: 5px 15px;
            border-radius: 4px;
        }

        .code-area {
            width: 100%;
            min-height: 200px;
            padding: 20px;
            font-family: \'Courier New\', monospace;
            font-size: 0.95rem;
            background: #1a202c;
            color: #e2e8f0;
            border: none;
            resize: vertical;
            line-height: 1.5;
        }

        .code-area:focus {
            outline: none;
        }

        .content-section {
            margin-top: 30px;
            padding: 30px;
            border-radius: 12px;
            background: white;
            border: 1px solid var(--border);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            position: relative;
        }

        .content-section.editing {
            border: 2px solid var(--primary);
            background: #f8f9fa;
        }

        .content-section h3 {
            font-size: 1.4rem;
            color: var(--dark);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }

        .content-section p {
            line-height: 1.7;
            margin-bottom: 20px;
            color: #333;
            font-size: 1.05rem;
        }

        .content-section ul, .content-section ol {
            padding-left: 25px;
            margin: 15px 0;
        }

        .content-section li {
            margin-bottom: 8px;
            line-height: 1.6;
        }

        .code-example {
            background: #1a202c;
            color: #e2e8f0;
            padding: 25px;
            border-radius: 8px;
            font-family: \'Courier New\', monospace;
            font-size: 1rem;
            line-height: 1.6;
            margin: 20px 0;
            overflow-x: auto;
            border-left: 4px solid var(--primary);
        }

        .code-example code {
            display: block;
            white-space: pre;
        }

        .code-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            color: #a0aec0;
            font-size: 0.9rem;
        }

        .content-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .edit-form {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        .edit-form.active {
            display: block;
        }

        .edit-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 20px;
        }

        .action-btn {
            padding: 12px 25px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            font-size: 0.95rem;
        }

        .action-btn.primary {
            background: var(--primary);
            color: white;
        }

        .action-btn.primary:hover {
            background: var(--primary-dark);
        }

        .action-btn.secondary {
            background: var(--light-gray);
            color: var(--dark);
        }

        .action-btn.secondary:hover {
            background: #dde0e4;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
            display: block;
        }

        .empty-state.hidden {
            display: none;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: var(--light-gray);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: var(--dark);
        }

        .empty-state p {
            font-size: 1.1rem;
            max-width: 500px;
            margin: 0 auto;
        }

        .quiz-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 30px;
            margin-top: 40px;
            border: 1px solid var(--border);
        }

        .quiz-section h3 {
            font-size: 1.5rem;
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border);
        }

        .quiz-question {
            font-size: 1.2rem;
            margin-bottom: 25px;
            font-weight: 500;
            line-height: 1.5;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }

        .quiz-question.editing {
            display: none;
        }

        .question-edit-form {
            display: none;
            margin-bottom: 20px;
        }

        .question-edit-form.active {
            display: block;
        }

        .quiz-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 25px;
        }

        .quiz-options.hidden {
            display: none;
        }

        .quiz-option {
            padding: 18px;
            border: 2px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
        }

        .quiz-option:hover {
            border-color: var(--primary);
            background: #f0f3ff;
        }

        .quiz-option.selected {
            border-color: var(--primary);
            background: #e7f1ff;
        }

        .quiz-option.correct {
            border-color: var(--success);
            background: rgba(76, 201, 240, 0.1);
        }

        .quiz-option.incorrect {
            border-color: var(--danger);
            background: rgba(247, 37, 133, 0.1);
        }

        .option-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .correct-option {
            color: var(--success);
            font-weight: 500;
            font-size: 0.9rem;
            display: none;
        }

        .quiz-option.correct .correct-option {
            display: inline;
        }

        .option-edit-form {
            display: none;
            width: 100%;
            margin-top: 10px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }

        .option-edit-form.active {
            display: block;
        }

        .quiz-feedback {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
            display: none;
        }

        .quiz-feedback.correct {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
            display: block;
        }

        .quiz-feedback.incorrect {
            background: rgba(247, 37, 133, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
            display: block;
        }

        .quiz-actions {
            display: flex;
            gap: 15px;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            width: 500px;
            max-width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 1px solid var(--light-gray);
        }

        .modal-header h3 {
            font-size: 1.4rem;
            color: var(--dark);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray);
            cursor: pointer;
            line-height: 1;
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid var(--light-gray);
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        .btn {
            padding: 12px 25px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #dde0e4;
        }

        .message {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            background: var(--primary);
            color: white;
            border-radius: 6px;
            z-index: 3000;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            animation: slideIn 0.3s ease;
        }

        .message.error {
            background: var(--danger);
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }

        .no-question-state {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 2px dashed var(--border);
        }

        .no-question-state i {
            font-size: 3rem;
            color: var(--light-gray);
            margin-bottom: 15px;
        }

        .no-question-state h4 {
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 10px;
        }

        .no-question-state p {
            color: var(--gray);
            margin-bottom: 20px;
        }

        .add-first-question-btn {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 12px 25px;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.3s;
        }

        .add-first-question-btn:hover {
            background: var(--primary-dark);
        }

        .quiz-option.correct {
            background-color: rgba(40, 167, 69, 0.1);
            border-color: #28a745;
        }

        .quiz-option.correct .correct-option {
            display: inline-block !important;
            color: #28a745;
            font-weight: 500;
            margin-right: 10px;
        }

        .quiz-option:not(.correct) .correct-option {
            display: none !important;
        }

        .option-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .hidden {
            display: none !important;
        }

        @media (max-width: 1200px) {
            .course-container {
                flex-direction: column;
            }

            .course-sidebar {
                width: 100%;
                position: static;
            }
        }

        @media (max-width: 768px) {
            .lesson-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .sidebar-actions {
                flex-direction: column;
            }

            .content-type-selector {
                flex-direction: column;
            }

            .course-content {
                padding: 20px;
            }
        }';

        echo $css_styles;
        ?>
    </style>
</head>
<body>
<header class="topbar">
    <div class="topbar-container">
        <div class="logo-section">
            <div class="logo">
                <i class="fas fa-graduation-cap" id="mybutton3"></i>
                <script>
                    document.getElementById("mybutton3").addEventListener("click", function() {
                        window.location.href = "teacher_home.html";
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
            <a href="TeacherCourses.php" class="active">My Courses</a>
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

<div class="course-container">
    <aside class="course-sidebar">
        <h1 class="sidebar-title">
            <i class="fas fa-book"></i> <?php echo htmlspecialchars($course['name']); ?>
        </h1>

        <div class="sidebar-actions">
            <button class="sidebar-btn add-chapter" id="addChapterBtn">
                <i class="fas fa-plus"></i> Add Chapter
            </button>
            <button class="sidebar-btn" id="addLessonBtn">
                <i class="fas fa-plus-circle"></i> Add Lesson
            </button>
        </div>

        <div id="chaptersContainer">
            <!-- سيتم تحميل الفصول والدروس هنا عبر JavaScript -->
            <div class="empty-state" id="loadingChapters">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading chapters...</p>
            </div>
        </div>
    </aside>

    <main class="course-content">
        <div class="lesson-header">
            <h1 class="lesson-title" id="currentLessonTitle">Select a Lesson</h1>
            <button class="save-btn" id="saveAllBtn" style="display: none;">
                <i class="fas fa-save"></i> Save All
            </button>
        </div>

        <div class="content-editor" id="contentEditor" style="display: none;">
            <div class="editor-title">Add New Content</div>

            <div class="form-group">
                <label>Content Type</label>
                <div class="content-type-selector">
                    <button class="type-btn active" data-type="text">
                        <i class="fas fa-paragraph"></i> Text Content
                    </button>
                    <button class="type-btn" data-type="code">
                        <i class="fas fa-code"></i> Code Example
                    </button>
                </div>
            </div>

            <div class="form-group" id="textEditor">
                <label for="contentTitle">Content Title</label>
                <input type="text" id="contentTitle" class="form-control" placeholder="e.g., What are Variables?">

                <label for="contentText">Text Content</label>
                <textarea id="contentText" class="form-control" rows="6" placeholder="Write your lesson content here..."></textarea>
            </div>

            <div class="form-group" id="codeEditor" style="display: none;">
                <label for="codeTitle">Code Title</label>
                <input type="text" id="codeTitle" class="form-control" placeholder="e.g., Variable Declaration Example">

                <label for="codeLanguage">Programming Language</label>
                <select id="codeLanguage" class="form-control">
                    <option value="java">Java</option>
                    <option value="javascript">JavaScript</option>
                    <option value="python">Python</option>
                    <option value="html">HTML</option>
                    <option value="css">CSS</option>
                    <option value="php">PHP</option>
                    <option value="sql">SQL</option>
                    <option value="c">C</option>
                    <option value="cpp">C++</option>
                    <option value="csharp">C#</option>
                </select>

                <label for="codeContent">Code Content</label>
                <textarea id="codeContent" class="form-control code-area" rows="6" placeholder="Write your code here..."></textarea>
            </div>

            <div class="content-actions">
                <button class="action-btn secondary" id="clearContentBtn">
                    <i class="fas fa-times"></i> Clear
                </button>
                <button class="action-btn primary" id="addContentBtn">
                    <i class="fas fa-plus"></i> Add to Lesson
                </button>
            </div>
        </div>

        <div id="contentDisplay">
            <div class="empty-state" id="emptyState">
                <i class="fas fa-book-open"></i>
                <h3>Select a Lesson to View Content</h3>
                <p>Choose a lesson from the sidebar to view or edit its content.</p>
            </div>
        </div>

        <div class="quiz-section" id="quizSection" style="display: none;">
            <h3>Lesson Quiz</h3>

            <div class="no-question-state" id="noQuestionState">
                <i class="fas fa-question-circle"></i>
                <h4>No Quiz Question Yet</h4>
                <p>Add a quiz question to test your students\' understanding.</p>
                <button class="add-first-question-btn" id="addFirstQuestionBtn">
                    <i class="fas fa-plus"></i> Add Question
                </button>
            </div>

            <div id="questionContainer" style="display: none;">
                <div class="quiz-question" id="questionDisplay"></div>

                <div class="question-edit-form" id="questionEditForm">
                    <div class="form-group">
                        <label for="editQuestionText">Question Text</label>
                        <textarea id="editQuestionText" class="form-control" rows="3" placeholder="Enter your question here..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Options</label>
                        <input type="text" id="editOptionA" class="form-control" placeholder="Option A">
                        <input type="text" id="editOptionB" class="form-control" placeholder="Option B">
                        <input type="text" id="editOptionC" class="form-control" placeholder="Option C">
                        <input type="text" id="editOptionD" class="form-control" placeholder="Option D">
                    </div>

                    <div class="form-group">
                        <label for="editCorrectAnswer">Correct Answer</label>
                        <select id="editCorrectAnswer" class="form-control">
                            <option value="A">Option A</option>
                            <option value="B">Option B</option>
                            <option value="C">Option C</option>
                            <option value="D">Option D</option>
                        </select>
                    </div>

                    <div class="edit-actions">
                        <button class="action-btn secondary" id="cancelQuestionEdit">
                            Cancel
                        </button>
                        <button class="action-btn primary" id="saveQuestionEdit">
                            Save Question
                        </button>
                    </div>
                </div>

                <div class="quiz-actions">
                    <button class="action-btn primary" id="editQuestionBtn">
                        <i class="fas fa-edit"></i> Edit Question
                    </button>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Modals -->
<div class="modal" id="chapterModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Chapter</h3>
            <button class="close-modal" id="closeChapterModal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="modalChapterName">Chapter Name</label>
                <input type="text" id="modalChapterName" class="form-control" placeholder="e.g., Chapter 1: Basics">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="cancelChapterBtn">Cancel</button>
            <button class="btn btn-primary" id="saveChapterBtn">Save Chapter</button>
        </div>
    </div>
</div>

<div class="modal" id="lessonModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Lesson</h3>
            <button class="close-modal" id="closeLessonModal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="modalLessonName">Lesson Name</label>
                <input type="text" id="modalLessonName" class="form-control" placeholder="e.g., Variables and Data Types">
            </div>
            <div class="form-group">
                <label for="modalLessonChapter">Select Chapter</label>
                <select id="modalLessonChapter" class="form-control">
                    <!-- سيتم تعبئة الفصول هنا -->
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="cancelLessonBtn">Cancel</button>
            <button class="btn btn-primary" id="saveLessonBtn">Save Lesson</button>
        </div>
    </div>
</div>

<script>
    // المتغيرات العامة
    let currentCourseId = <?php echo $course_id; ?>;
    let currentLessonId = null;
    let currentChapterId = null;
    let chapters = [];
    let lessons = [];

    // التهيئة الأولية عند تحميل الصفحة
    document.addEventListener('DOMContentLoaded', function() {
        loadChapters();
        setupEventListeners();
    });

    // تحميل الفصول من قاعدة البيانات
    async function loadChapters() {
        try {
            const response = await fetch(`teacherCourseContent.php?action=get_chapters&course_id=${currentCourseId}`);
            chapters = await response.json();

            displayChapters(chapters);
            updateLessonChapterDropdown();
        } catch (error) {
            console.error('Error loading chapters:', error);
            showMessage('Error loading chapters', 'error');
        }
    }

    // عرض الفصول في الشريط الجانبي
    function displayChapters(chapters) {
        const container = document.getElementById('chaptersContainer');

        if (chapters.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-book"></i>
                    <h3>No Chapters Yet</h3>
                    <p>Add your first chapter to get started.</p>
                </div>
            `;
            return;
        }

        let html = '';

        chapters.forEach(chapter => {
            html += `
                <div class="chapter-section" data-chapter-id="${chapter.chapter_id}">
                    <div class="chapter-title">
                        <span>${chapter.chapter_name}</span>
                        <div class="chapter-actions">
                            <button class="chapter-action edit" onclick="editChapterTitle(${chapter.chapter_id}, '${chapter.chapter_name.replace(/'/g, "\\'")}')">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="chapter-action delete" onclick="deleteChapter(${chapter.chapter_id})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <ul class="lessons-list" id="lessons-${chapter.chapter_id}">
                        <div class="empty-state" style="padding: 20px;">
                            <i class="fas fa-spinner fa-spin"></i>
                            <p>Loading lessons...</p>
                        </div>
                    </ul>
                </div>
            `;
        });

        container.innerHTML = html;

        // تحميل الدروس لكل فصل
        chapters.forEach(chapter => {
            loadLessonsForChapter(chapter.chapter_id);
        });
    }

    // تحميل الدروس لفصل معين
    async function loadLessonsForChapter(chapterId) {
        try {
            const response = await fetch(`teacherCourseContent.php?action=get_lessons&chapter_id=${chapterId}`);
            const lessons = await response.json();

            displayLessons(chapterId, lessons);
        } catch (error) {
            console.error('Error loading lessons:', error);
        }
    }

    // عرض الدروس داخل الفصل
    function displayLessons(chapterId, lessons) {
        const container = document.getElementById(`lessons-${chapterId}`);

        if (lessons.length === 0) {
            container.innerHTML = `
                <div class="empty-state" style="padding: 15px; font-size: 0.9rem;">
                    <i class="fas fa-book-open"></i>
                    <p>No lessons in this chapter</p>
                </div>
            `;
            return;
        }

        let html = '';

        lessons.forEach(lesson => {
            html += `
                <li class="lesson-item" data-lesson-id="${lesson.lesson_id}">
                    <span>${lesson.lesson_title}</span>
                    <div class="lesson-actions">
                        <button class="lesson-action edit" onclick="editLessonTitle(${lesson.lesson_id}, '${lesson.lesson_title.replace(/'/g, "\\'")}')">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="lesson-action delete" onclick="deleteLesson(${lesson.lesson_id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </li>
            `;
        });

        container.innerHTML = html;

        // إضافة مستمعات الأحداث للدروس
        document.querySelectorAll(`#lessons-${chapterId} .lesson-item`).forEach(item => {
            item.addEventListener('click', function(e) {
                if (!e.target.closest('.lesson-action')) {
                    const lessonId = this.getAttribute('data-lesson-id');
                    loadLessonContent(lessonId);
                }
            });
        });
    }

    // تحديث القائمة المنسدلة للفصول في مودال إضافة الدرس
    function updateLessonChapterDropdown() {
        const select = document.getElementById('modalLessonChapter');
        select.innerHTML = '';

        chapters.forEach(chapter => {
            const option = document.createElement('option');
            option.value = chapter.chapter_id;
            option.textContent = chapter.chapter_name;
            select.appendChild(option);
        });
    }

    // تحميل محتوى الدرس
    async function loadLessonContent(lessonId) {
        try {
            // تحديث الواجهة
            document.querySelectorAll('.lesson-item').forEach(item => {
                item.classList.remove('active');
            });
            document.querySelector(`[data-lesson-id="${lessonId}"]`).classList.add('active');

            currentLessonId = lessonId;

            // إظهار محرر المحتوى
            document.getElementById('contentEditor').style.display = 'block';
            document.getElementById('saveAllBtn').style.display = 'block';
            document.getElementById('quizSection').style.display = 'block';

            // جلب معلومات الدرس
            const lessonResponse = await fetch(`teacherCourseContent.php?action=get_lesson_info&lesson_id=${lessonId}`);
            const lesson = await lessonResponse.json();

            document.getElementById('currentLessonTitle').textContent = lesson.lesson_title;

            // جلب محتوى الدرس
            const contentResponse = await fetch(`teacherCourseContent.php?action=get_lesson_content&lesson_id=${lessonId}`);
            const data = await contentResponse.json();

            displayLessonContent(data.contents);
            displayQuiz(data.quiz);
        } catch (error) {
            console.error('Error loading lesson content:', error);
            showMessage('Error loading lesson content', 'error');
        }
    }

    // عرض محتوى الدرس
    function displayLessonContent(contents) {
        const container = document.getElementById('contentDisplay');

        if (contents.length === 0) {
            container.innerHTML = `
                <div class="empty-state" id="emptyState">
                    <i class="fas fa-book-open"></i>
                    <h3>No Content Yet</h3>
                    <p>Add content to this lesson using the editor above.</p>
                </div>
            `;
            return;
        }

        let html = '';

        contents.forEach(content => {
            if (content.content_type === 'text') {
                html += `
                    <div class="content-section" data-content-id="${content.content_id}">
                        <div class="content-display">
                            ${content.content_title ? `<h3>${content.content_title}</h3>` : ''}
                            <p>${content.content_text.replace(/\n/g, '<br>')}</p>
                            <div class="content-actions">
                                <button class="action-btn secondary edit-content-btn" onclick="editContent(${content.content_id}, 'text')">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="action-btn" style="background: var(--danger); color: white;" onclick="deleteContent(${content.content_id})">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            } else if (content.content_type === 'code') {
                html += `
                    <div class="content-section" data-content-id="${content.content_id}">
                        <div class="content-display">
                            ${content.content_title ? `<h3>${content.content_title}</h3>` : ''}
                            <div class="code-example">
                                <div class="code-meta">
                                    <span>${content.code_language || 'Code'} Example</span>
                                </div>
                                <code>${content.code_content}</code>
                            </div>
                            <div class="content-actions">
                                <button class="action-btn secondary edit-content-btn" onclick="editContent(${content.content_id}, 'code')">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="action-btn" style="background: var(--danger); color: white;" onclick="deleteContent(${content.content_id})">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            }
        });

        container.innerHTML = html;
    }

    // عرض اختبار الدرس
    function displayQuiz(quiz) {
        const questionContainer = document.getElementById('questionContainer');
        const noQuestionState = document.getElementById('noQuestionState');

        if (quiz) {
            noQuestionState.style.display = 'none';
            questionContainer.style.display = 'block';

            document.getElementById('questionDisplay').textContent = quiz.question_text;

            // تعبئة حقول التعديل
            document.getElementById('editQuestionText').value = quiz.question_text;
            document.getElementById('editOptionA').value = quiz.option_a || '';
            document.getElementById('editOptionB').value = quiz.option_b || '';
            document.getElementById('editOptionC').value = quiz.option_c || '';
            document.getElementById('editOptionD').value = quiz.option_d || '';
            document.getElementById('editCorrectAnswer').value = quiz.correct_answer;
        } else {
            noQuestionState.style.display = 'block';
            questionContainer.style.display = 'none';
        }
    }

    // إعداد مستمعات الأحداث
    function setupEventListeners() {
        // أزرار إضافة الفصول والدروس
        document.getElementById('addChapterBtn').addEventListener('click', () => {
            document.getElementById('modalChapterName').value = '';
            document.getElementById('chapterModal').classList.add('active');
        });

        document.getElementById('addLessonBtn').addEventListener('click', () => {
            document.getElementById('modalLessonName').value = '';
            document.getElementById('lessonModal').classList.add('active');
        });

        // إغلاق المودالات
        document.getElementById('closeChapterModal').addEventListener('click', () => {
            document.getElementById('chapterModal').classList.remove('active');
        });

        document.getElementById('closeLessonModal').addEventListener('click', () => {
            document.getElementById('lessonModal').classList.remove('active');
        });

        document.getElementById('cancelChapterBtn').addEventListener('click', () => {
            document.getElementById('chapterModal').classList.remove('active');
        });

        document.getElementById('cancelLessonBtn').addEventListener('click', () => {
            document.getElementById('lessonModal').classList.remove('active');
        });

        // حفظ الفصل
        document.getElementById('saveChapterBtn').addEventListener('click', async () => {
            const chapterName = document.getElementById('modalChapterName').value.trim();

            if (!chapterName) {
                showMessage('Please enter chapter name', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'save_chapter');
            formData.append('chapter_name', chapterName);

            try {
                const response = await fetch('teacherCourseContent.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    document.getElementById('chapterModal').classList.remove('active');
                    showMessage('Chapter added successfully');
                    loadChapters();
                } else {
                    showMessage('Error: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Error saving chapter:', error);
                showMessage('Error saving chapter', 'error');
            }
        });

        // حفظ الدرس
        document.getElementById('saveLessonBtn').addEventListener('click', async () => {
            const lessonName = document.getElementById('modalLessonName').value.trim();
            const chapterId = document.getElementById('modalLessonChapter').value;

            if (!lessonName) {
                showMessage('Please enter lesson name', 'error');
                return;
            }

            if (!chapterId) {
                showMessage('Please select a chapter', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'save_lesson');
            formData.append('lesson_title', lessonName);
            formData.append('chapter_id', chapterId);

            try {
                const response = await fetch('teacherCourseContent.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    document.getElementById('lessonModal').classList.remove('active');
                    showMessage('Lesson added successfully');
                    loadLessonsForChapter(chapterId);
                } else {
                    showMessage('Error: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Error saving lesson:', error);
                showMessage('Error saving lesson', 'error');
            }
        });

        // نوع المحتوى
        document.querySelectorAll('.type-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                const type = this.getAttribute('data-type');
                if (type === 'text') {
                    document.getElementById('textEditor').style.display = 'block';
                    document.getElementById('codeEditor').style.display = 'none';
                } else {
                    document.getElementById('textEditor').style.display = 'none';
                    document.getElementById('codeEditor').style.display = 'block';
                }
            });
        });

        // إضافة محتوى
        document.getElementById('addContentBtn').addEventListener('click', async () => {
            if (!currentLessonId) {
                showMessage('Please select a lesson first', 'error');
                return;
            }

            const type = document.querySelector('.type-btn.active').getAttribute('data-type');

            if (type === 'text') {
                const contentTitle = document.getElementById('contentTitle').value.trim();
                const contentText = document.getElementById('contentText').value.trim();

                if (!contentText) {
                    showMessage('Please enter text content', 'error');
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'save_content');
                formData.append('lesson_id', currentLessonId);
                formData.append('content_type', 'text');
                formData.append('content_title', contentTitle);
                formData.append('content_text', contentText);

                await saveContent(formData);

            } else {
                const codeTitle = document.getElementById('codeTitle').value.trim();
                const codeLanguage = document.getElementById('codeLanguage').value;
                const codeContent = document.getElementById('codeContent').value.trim();

                if (!codeContent) {
                    showMessage('Please enter code content', 'error');
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'save_content');
                formData.append('lesson_id', currentLessonId);
                formData.append('content_type', 'code');
                formData.append('content_title', codeTitle);
                formData.append('code_language', codeLanguage);
                formData.append('code_content', codeContent);

                await saveContent(formData);
            }
        });

        // مسح المحرر
        document.getElementById('clearContentBtn').addEventListener('click', () => {
            document.getElementById('contentTitle').value = '';
            document.getElementById('contentText').value = '';
            document.getElementById('codeTitle').value = '';
            document.getElementById('codeContent').value = '';
        });

        // اختبار الدرس
        document.getElementById('addFirstQuestionBtn').addEventListener('click', () => {
            document.getElementById('editQuestionText').value = '';
            document.getElementById('editOptionA').value = '';
            document.getElementById('editOptionB').value = '';
            document.getElementById('editOptionC').value = '';
            document.getElementById('editOptionD').value = '';
            document.getElementById('editCorrectAnswer').value = 'A';

            document.getElementById('noQuestionState').style.display = 'none';
            document.getElementById('questionContainer').style.display = 'block';
            document.getElementById('questionDisplay').style.display = 'none';
            document.getElementById('questionEditForm').style.display = 'block';
        });

        document.getElementById('editQuestionBtn').addEventListener('click', () => {
            document.getElementById('questionDisplay').style.display = 'none';
            document.getElementById('questionEditForm').style.display = 'block';
        });

        document.getElementById('cancelQuestionEdit').addEventListener('click', () => {
            document.getElementById('questionDisplay').style.display = 'block';
            document.getElementById('questionEditForm').style.display = 'none';
        });

        document.getElementById('saveQuestionEdit').addEventListener('click', async () => {
            const questionText = document.getElementById('editQuestionText').value.trim();
            const optionA = document.getElementById('editOptionA').value.trim();
            const optionB = document.getElementById('editOptionB').value.trim();
            const optionC = document.getElementById('editOptionC').value.trim();
            const optionD = document.getElementById('editOptionD').value.trim();
            const correctAnswer = document.getElementById('editCorrectAnswer').value;

            if (!questionText) {
                showMessage('Please enter question text', 'error');
                return;
            }

            if (!optionA || !optionB) {
                showMessage('Please at least options A and B', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'save_quiz');
            formData.append('lesson_id', currentLessonId);
            formData.append('question_text', questionText);
            formData.append('option_a', optionA);
            formData.append('option_b', optionB);
            formData.append('option_c', optionC);
            formData.append('option_d', optionD);
            formData.append('correct_answer', correctAnswer);

            try {
                const response = await fetch('teacherCourseContent.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    document.getElementById('questionDisplay').style.display = 'block';
                    document.getElementById('questionEditForm').style.display = 'none';

                    document.getElementById('questionDisplay').textContent = questionText;
                    showMessage('Quiz question saved successfully');

                    // تحديث عرض الاختبار
                    const quiz = {
                        question_text: questionText,
                        option_a: optionA,
                        option_b: optionB,
                        option_c: optionC,
                        option_d: optionD,
                        correct_answer: correctAnswer
                    };
                    displayQuiz(quiz);
                } else {
                    showMessage('Error: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Error saving quiz:', error);
                showMessage('Error saving quiz', 'error');
            }
        });

        // حفظ الكل
        document.getElementById('saveAllBtn').addEventListener('click', () => {
            showMessage('All changes saved successfully');
        });
    }

    // وظائف مساعدة
    async function saveContent(formData) {
        try {
            const response = await fetch('teacherCourseContent.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showMessage('Content added successfully');

                // مسح الحقول
                document.getElementById('contentTitle').value = '';
                document.getElementById('contentText').value = '';
                document.getElementById('codeTitle').value = '';
                document.getElementById('codeContent').value = '';

                // إعادة تحميل المحتوى
                loadLessonContent(currentLessonId);
            } else {
                showMessage('Error: ' + result.message, 'error');
            }
        } catch (error) {
            console.error('Error saving content:', error);
            showMessage('Error saving content', 'error');
        }
    }

    function editChapterTitle(chapterId, currentName) {
        const newName = prompt('Edit chapter name:', currentName);

        if (newName !== null && newName.trim() !== '' && newName !== currentName) {
            const formData = new FormData();
            formData.append('action', 'update_chapter_title');
            formData.append('chapter_id', chapterId);
            formData.append('chapter_name', newName.trim());

            fetch('teacherCourseContent.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showMessage('Chapter updated successfully');
                        loadChapters();
                    } else {
                        showMessage('Error: ' + result.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error updating chapter:', error);
                    showMessage('Error updating chapter', 'error');
                });
        }
    }

    function editLessonTitle(lessonId, currentName) {
        const newName = prompt('Edit lesson name:', currentName);

        if (newName !== null && newName.trim() !== '' && newName !== currentName) {
            const formData = new FormData();
            formData.append('action', 'update_lesson_title');
            formData.append('lesson_id', lessonId);
            formData.append('lesson_title', newName.trim());

            fetch('teacherCourseContent.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showMessage('Lesson updated successfully');

                        // تحديث العنوان إذا كان هذا الدرس مفتوح
                        if (currentLessonId == lessonId) {
                            document.getElementById('currentLessonTitle').textContent = newName.trim();
                        }

                        // إعادة تحميل الفصول
                        loadChapters();
                    } else {
                        showMessage('Error: ' + result.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error updating lesson:', error);
                    showMessage('Error updating lesson', 'error');
                });
        }
    }

    function deleteChapter(chapterId) {
        if (!confirm('Are you sure you want to delete this chapter and all its lessons?')) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'delete_chapter');
        formData.append('chapter_id', chapterId);

        fetch('teacherCourseContent.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showMessage('Chapter deleted successfully');
                    loadChapters();
                } else {
                    showMessage('Error: ' + result.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error deleting chapter:', error);
                showMessage('Error deleting chapter', 'error');
            });
    }

    function deleteLesson(lessonId) {
        if (!confirm('Are you sure you want to delete this lesson?')) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'delete_lesson');
        formData.append('lesson_id', lessonId);

        fetch('teacherCourseContent.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showMessage('Lesson deleted successfully');

                    // إذا كان الدرس المحذوف هو المفتوح حاليًا
                    if (currentLessonId == lessonId) {
                        document.getElementById('contentEditor').style.display = 'none';
                        document.getElementById('saveAllBtn').style.display = 'none';
                        document.getElementById('quizSection').style.display = 'none';
                        document.getElementById('currentLessonTitle').textContent = 'Select a Lesson';
                        document.getElementById('contentDisplay').innerHTML = `
                        <div class="empty-state" id="emptyState">
                            <i class="fas fa-book-open"></i>
                            <h3>Select a Lesson to View Content</h3>
                            <p>Choose a lesson from the sidebar to view or edit its content.</p>
                        </div>
                    `;
                        currentLessonId = null;
                    }

                    // إعادة تحميل الفصول
                    loadChapters();
                } else {
                    showMessage('Error: ' + result.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error deleting lesson:', error);
                showMessage('Error deleting lesson', 'error');
            });
    }

    async function editContent(contentId, type) {
        // جلب بيانات المحتوى الحالية
        try {
            const response = await fetch(`teacherCourseContent.php?action=get_single_content&content_id=${contentId}`);
            const content = await response.json();

            if (content.error) {
                showMessage(content.error, 'error');
                return;
            }

            // فتح مودال التعديل
            openEditContentModal(content, type);
        } catch (error) {
            console.error('Error loading content:', error);
            showMessage('Error loading content', 'error');
        }
    }

    function openEditContentModal(content, type) {
        // إغلاق أي مودال مفتوح مسبقاً
        closeEditContentModal();

        // إنشاء HTML للمودال
        const modalHTML = `
    <div class="modal active" id="editContentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Content</h3>
                <button class="close-modal" onclick="closeEditContentModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Content Type: <strong>${type === 'text' ? 'Text' : 'Code'}</strong></label>
                </div>
                <div class="form-group">
                    <label for="editContentTitle">Content Title</label>
                    <input type="text" id="editContentTitle" class="form-control"
                           value="${content.content_title ? content.content_title.replace(/"/g, '&quot;').replace(/'/g, '&#039;') : ''}"
                           placeholder="Enter content title">
                </div>

                ${type === 'text' ? `
                    <div class="form-group">
                        <label for="editContentText">Text Content</label>
                        <textarea id="editContentText" class="form-control" rows="6"
                                  placeholder="Enter text content">${content.content_text ? content.content_text.replace(/"/g, '&quot;').replace(/'/g, '&#039;') : ''}</textarea>
                    </div>
                ` : `
                    <div class="form-group">
                        <label for="editCodeLanguage">Programming Language</label>
                        <select id="editCodeLanguage" class="form-control">
                            <option value="java" ${content.code_language === 'java' ? 'selected' : ''}>Java</option>
                            <option value="javascript" ${content.code_language === 'javascript' ? 'selected' : ''}>JavaScript</option>
                            <option value="python" ${content.code_language === 'python' ? 'selected' : ''}>Python</option>
                            <option value="html" ${content.code_language === 'html' ? 'selected' : ''}>HTML</option>
                            <option value="css" ${content.code_language === 'css' ? 'selected' : ''}>CSS</option>
                            <option value="php" ${content.code_language === 'php' ? 'selected' : ''}>PHP</option>
                            <option value="sql" ${content.code_language === 'sql' ? 'selected' : ''}>SQL</option>
                            <option value="c" ${content.code_language === 'c' ? 'selected' : ''}>C</option>
                            <option value="cpp" ${content.code_language === 'cpp' ? 'selected' : ''}>C++</option>
                            <option value="csharp" ${content.code_language === 'csharp' ? 'selected' : ''}>C#</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editCodeContent">Code Content</label>
                        <textarea id="editCodeContent" class="form-control code-area" rows="6"
                                  placeholder="Enter code">${content.code_content ? content.code_content.replace(/"/g, '&quot;').replace(/'/g, '&#039;') : ''}</textarea>
                    </div>
                `}

                <input type="hidden" id="editContentId" value="${content.content_id}">
                <input type="hidden" id="editContentType" value="${type}">
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeEditContentModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveEditedContent()">Save Changes</button>
            </div>
        </div>
    </div>
    `;

        // إضافة المودال إلى الصفحة
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }

    function closeEditContentModal() {
        const modal = document.getElementById('editContentModal');
        if (modal) {
            modal.remove();
        }
    }

    async function saveEditedContent() {
        const contentId = document.getElementById('editContentId').value;
        const contentType = document.getElementById('editContentType').value;
        const contentTitle = document.getElementById('editContentTitle').value.trim();

        const formData = new FormData();
        formData.append('action', 'update_content');
        formData.append('content_id', contentId);
        formData.append('content_title', contentTitle);

        if (contentType === 'text') {
            const contentText = document.getElementById('editContentText').value.trim();
            if (!contentText) {
                showMessage('Please enter text content', 'error');
                return;
            }
            formData.append('content_text', contentText);
        } else {
            const codeContent = document.getElementById('editCodeContent').value.trim();
            const codeLanguage = document.getElementById('editCodeLanguage').value;

            if (!codeContent) {
                showMessage('Please enter code content', 'error');
                return;
            }
            formData.append('code_content', codeContent);
            formData.append('code_language', codeLanguage);
        }

        try {
            const response = await fetch('teacherCourseContent.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showMessage('Content updated successfully');
                closeEditContentModal();

                // إعادة تحميل محتوى الدرس لرؤية التغييرات
                if (currentLessonId) {
                    await loadLessonContent(currentLessonId);
                }
            } else {
                showMessage('Error: ' + result.message, 'error');
            }
        } catch (error) {
            console.error('Error updating content:', error);
            showMessage('Error updating content', 'error');
        }
    }

    async function deleteContent(contentId) {

        const formData = new FormData();
        formData.append('action', 'delete_content');
        formData.append('content_id', contentId);

        try {
            const response = await fetch('teacherCourseContent.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showMessage('Content deleted successfully');
                loadLessonContent(currentLessonId);
            } else {
                showMessage('Error: ' + result.message, 'error');
            }
        } catch (error) {
            console.error('Error deleting content:', error);
            showMessage('Error deleting content', 'error');
        }
    }

    function showMessage(message, type = 'success') {
        // إزالة أي رسائل سابقة
        document.querySelectorAll('.message').forEach(msg => msg.remove());

        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${type === 'error' ? 'error' : ''}`;
        messageDiv.textContent = message;
        document.body.appendChild(messageDiv);

        setTimeout(() => {
            messageDiv.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    document.body.removeChild(messageDiv);
                }
            }, 300);
        }, 3000);
    }
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