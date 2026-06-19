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
    header("Location: login.html");
    exit();
}

$teacher_email = $_SESSION['email'];
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

/*==================================================
    2) جلب جميع الكورسات الخاصة بالمعلم
==================================================*/
$coursesQuery = "SELECT id, name FROM courses WHERE teacher = ?";
$stmt = $conn->prepare($coursesQuery);
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$coursesResult = $stmt->get_result();

$courses = [];
while ($row = $coursesResult->fetch_assoc()) {
    $courses[] = $row;
}

/*==================================================
    3) جلب الطلاب وتقدمهم في كل كورس
==================================================*/
$studentsData = [];

foreach ($courses as $course) {
    $courseId = $course['id'];

    $query = "
        SELECT study.student_email, study.progress,
               users.firstname, users.lastname,
               COALESCE(scs.status, 'In Progress') as status
        FROM study
        INNER JOIN users ON users.email = study.student_email
        LEFT JOIN studentcoursestatus scs ON scs.student_email = study.student_email 
                                           AND scs.course_id = study.course_id
        WHERE study.course_id = ?
    ";

    $stmt2 = $conn->prepare($query);
    $stmt2->bind_param("i", $courseId);
    $stmt2->execute();
    $result2 = $stmt2->get_result();

    while ($row = $result2->fetch_assoc()) {
        $studentsData[$courseId][] = [
                "course_name" => $course["name"],
                "email" => $row["student_email"],
                "name" => $row["firstname"] . " " . $row["lastname"],
                "progress" => (int)$row["progress"],
                "status" => $row["status"] // 'Passed', 'Failed', 'In Progress'
        ];
    }
}
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edavora</title>
    <link rel="stylesheet" href="css/head.css">
    <link rel="stylesheet" href="css/footor.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="img/icon.png">

    <style>
        .student-card {border:1px solid #ddd;padding:15px;border-radius:10px;margin:10px;width:300px;}
        .student-avatar {width:50px;height:50px;background:#444;color:#fff;display:flex;align-items:center;justify-content:center;border-radius:50%;font-size:20px;}
        .passed-student {border-left:5px solid green;}
        .failed-student {border-left:5px solid red;}
        .inprogress-student {border-left:5px solid #f39c12;}
        .passed-grade {color:green;font-weight:bold;}
        .failed-grade {color:red;font-weight:bold;}
        .inprogress-grade {color: #f39c12; font-weight:bold;}
        .students-grid {display:flex;flex-wrap:wrap;gap:15px;}
        .title-section {font-size:22px;font-weight:bold;margin-top:25px;margin-bottom:10px;}
        :root {
            --primary-color: #675788;
            --success-color: rgba(75, 154, 42, 0.7);
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
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
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .subject-selector {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .subject-selector h2 {
            margin-bottom: 15px;
            color: var(--dark-color);
        }

        .subject-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
        }

        .subject-btn {
            padding: 12px 20px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s;
            margin: 10px;
        }

        .subject-btn:hover {
            transform: translateY(-2px);
        }

        .subject-btn.active {
            background: var(--success-color);
        }

        .subjectb.active {
            background: rgb(82, 68, 115);
        }

        .course-info {
            margin-top: 15px;
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 1.1rem;
        }

        .tabs {
            display: flex;
            margin-bottom: 25px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .tab {
            flex: 1;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s;
        }

        .tab.active {
            background: var(--primary-color);
            color: white;
        }

        .tab:not(.active):hover {
            background: #e9ecef;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-card h3 {
            font-size: 1.1rem;
            margin-bottom: 10px;
            color: var(--dark-color);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
        }

        .passed-count {
            color: var(--success-color);
        }

        .failed-count {
            color: var(--danger-color);
        }

        .total-count {
            color: var(--primary-color);
        }

        .students-section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 1.5rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
            color: var(--dark-color);
        }

        .passed-title {
            color: var(--success-color);
            border-bottom-color: var(--success-color);
        }

        .failed-title {
            color: var(--danger-color);
            border-bottom-color: var(--danger-color);
        }

        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .student-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 5px solid;
        }

        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .passed-student {
            border-left-color: var(--success-color);
        }

        .failed-student {
            border-left-color: var(--danger-color);
        }

        .inprogress-student {
            border-left-color: var(--warning-color);
        }

        .student-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .student-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #495057;
            margin-right: 15px;
        }

        .student-info h3 {
            margin-bottom: 5px;
            color: var(--dark-color);
        }

        .student-id {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .student-grade {
            margin-top: 10px;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .passed-grade {
            color: var(--success-color);
        }

        .failed-grade {
            color: var(--danger-color);
        }

        .inprogress-grade {
            color: var(--warning-color);
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            font-size: 1.2rem;
            grid-column: 1 / -1;
        }

        @media (max-width: 768px) {
            .tabs {
                flex-direction: column;
            }

            .students-grid {
                grid-template-columns: 1fr;
            }

            .course-info {
                flex-direction: column;
                gap: 10px;
            }

            .subject-buttons {
                flex-direction: column;
            }
        }


        .student-card {border:1px solid #ddd;padding:15px;border-radius:10px;margin:10px;}
        .student-avatar {width:50px;height:50px;background:#444;color:#fff;display:flex;align-items:center;justify-content:center;border-radius:50%;font-size:20px;}
        .passed-student {border-left:5px solid green;}
        .failed-student {border-left:5px solid red;}
        .inprogress-student {border-left:5px solid #f39c12;}
        .passed-grade {color:green;font-weight:bold;}
        .failed-grade {color: red;font-weight:bold;}
        .inprogress-grade {color: #f39c12;font-weight:bold;}
        .students-grid {display:flex;flex-wrap:wrap;gap:15px;}
        .no-data {color:#666;margin:10px 0;}

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
            <a href="TeacherCourses.php">My Courses</a>
            <a href="teacher_show.php" class="active">Show</a>
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

<div class="container">

    <h2>Select Course</h2>

    <div class="subject-buttons">
        <?php foreach ($courses as $i => $course): ?>
            <button class="subject-btn subjectb <?php echo $i==0?'active':''; ?>"
                    data-course="<?php echo $course['id']; ?>">
                <?php echo $course['name']; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div id="statsSection"></div>

    <div class="title-section">Passed Students</div>
    <div id="passedContainer" class="students-grid"></div>

    <div class="title-section">Failed Students</div>
    <div id="failedContainer" class="students-grid"></div>

    <div class="title-section">In Progress Students</div>
    <div id="inProgressContainer" class="students-grid"></div>

</div>

<script>
    const studentsData = <?php echo json_encode($studentsData); ?>;

    let currentCourse = Object.keys(studentsData)[0];

    function renderStudents() {
        const passedBox = document.getElementById("passedContainer");
        const failedBox = document.getElementById("failedContainer");
        const progressBox = document.getElementById("inProgressContainer");
        const statsBox = document.getElementById("statsSection");

        passedBox.innerHTML = "";
        failedBox.innerHTML = "";
        progressBox.innerHTML = "";

        if (!studentsData[currentCourse] || studentsData[currentCourse].length === 0) {
            passedBox.innerHTML = "<div class='no-data'>No students</div>";
            failedBox.innerHTML = "<div class='no-data'>No students</div>";
            progressBox.innerHTML = "<div class='no-data'>No students</div>";
            return;
        }

        let total = studentsData[currentCourse].length;
        let passed = studentsData[currentCourse].filter(s => s.status === 'Passed').length;
        let failed = studentsData[currentCourse].filter(s => s.status === 'Failed').length;
        let inProgress = studentsData[currentCourse].filter(s => s.status === 'In Progress').length;

        statsBox.innerHTML = `
        <div class="stats-cards">
            <div class="stat-card"><h3>Total Students</h3><div class="stat-number">${total}</div></div>
            <div class="stat-card"><h3>Passed</h3><div class="stat-number passed-count">${passed}</div></div>
            <div class="stat-card"><h3>Failed</h3><div class="stat-number failed-count">${failed}</div></div>
            <div class="stat-card"><h3>In Progress</h3><div class="stat-number">${inProgress}</div></div>
            <div class="stat-card"><h3>Success Rate</h3><div class="stat-number">${total > 0 ? ((passed/total)*100).toFixed(1) : 0}%</div></div>
        </div>
        `;

        studentsData[currentCourse].forEach(s => {
            let card = document.createElement("div");

            let statusClass = '';
            let gradeClass = '';
            if (s.status === 'Passed') {
                statusClass = "passed-student";
                gradeClass = "passed-grade";
            } else if (s.status === 'Failed') {
                statusClass = "failed-student";
                gradeClass = "failed-grade";
            } else {
                statusClass = "inprogress-student";
                gradeClass = "inprogress-grade";
            }

            card.className = `student-card ${statusClass}`;

            let firstChar = s.name.charAt(0);

            let html = `
            <div class="student-header">
                <div class="student-avatar">${firstChar}</div>
                <div class="student-info">
                    <h3>${s.name}</h3>
                    <div class="student-id">${s.email}</div>
                </div>
            </div>
            <div class="student-grade ${gradeClass}">
                ${s.progress}% - ${s.status}
            </div>
            `;

            card.innerHTML = html;

            if (s.status === 'Passed') {
                passedBox.appendChild(card);
            } else if (s.status === 'Failed') {
                failedBox.appendChild(card);
            } else {
                progressBox.appendChild(card);
            }
        });
    }

    document.querySelectorAll(".subject-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            document.querySelectorAll(".subject-btn").forEach(b => b.classList.remove("active"));
            btn.classList.add("active");
            currentCourse = btn.getAttribute("data-course");
            renderStudents();
        });
    });

    renderStudents();
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