<?php
session_start();
require_once 'config/db_connection.php';

// التحقق من تسجيل دخول الطالب
if (!isset($_SESSION['email'])) {
    header("Location: logout.php");
    exit();
}

$student_email = $_SESSION['email'];
$student_name = $_SESSION['firstname'] . ' ' . $_SESSION['lastname'];

$student_query = "SELECT * FROM users WHERE email = ?";
$student_stmt = $conn->prepare($student_query);
$student_stmt->bind_param("s", $student_email);
$student_stmt->execute();
$student_result = $student_stmt->get_result();
$student_data = $student_result->fetch_assoc();

$profile_image = '';
if (!empty($student_data['student_profile'])) {
    $profile_image = $student_data['student_profile'];
} elseif (!empty($student_data['profileimage'])) {
    $profile_image = $student_data['profileimage'];
} else {
    $profile_image = 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';
}

// استعلام الإشعارات للطالب (مثل الكود الأول)
$notifications_query = "SELECT * FROM notifications
WHERE user_email = ?
ORDER BY created_at DESC";
$notifications_stmt = $conn->prepare($notifications_query);
$notifications_stmt->bind_param("s", $student_email);
$notifications_stmt->execute();
$notifications = $notifications_stmt->get_result();

// استعلام المحادثات (مثل الكود الأول)
$conversations_query = "SELECT DISTINCT u.* FROM messages m
JOIN users u ON (m.sender_email = u.email OR m.user_email = u.email)
WHERE (m.user_email = ? OR m.sender_email = ?)
AND u.email != ?
GROUP BY u.email
ORDER BY MAX(m.sent_at) DESC";
$conversations_stmt = $conn->prepare($conversations_query);
$conversations_stmt->bind_param("sss", $student_email, $student_email, $student_email);
$conversations_stmt->execute();
$conversations = $conversations_stmt->get_result();

// استعلام الإشعارات غير المقروءة
$unread_query = "SELECT COUNT(*) as count FROM notifications 
WHERE user_email = ? AND is_read = 0";
$unread_stmt = $conn->prepare($unread_query);
$unread_stmt->bind_param("s", $student_email);
$unread_stmt->execute();
$unread_result = $unread_stmt->get_result();
$unread_count = $unread_result->fetch_assoc()['count'];

// الكود الأصلي للدورة
$course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;

// محاولة الحصول على course_id من الجلسة إذا لم يكن في الرابط
if ($course_id == 0 && isset($_SESSION['course_id'])) {
    $course_id = $_SESSION['course_id'];
}

if ($course_id == 0) {
    die("Please select a course.");
}

// حفظ course_id في الجلسة للاستخدام المستقبلي
$_SESSION['course_id'] = $course_id;

// الحصول على معلومات الدورة
$course_query = "SELECT * FROM courses WHERE id = ?";
$course_stmt = $conn->prepare($course_query);
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course_result = $course_stmt->get_result();

if ($course_result->num_rows == 0) {
    die("Course not found.");
}

$course = $course_result->fetch_assoc();

// التحقق من تسجيل الطالب في هذه الدورة
$enrollment_query = "SELECT * FROM study WHERE student_email = ? AND course_id = ?";
$enrollment_stmt = $conn->prepare($enrollment_query);
$enrollment_stmt->bind_param("si", $student_email, $course_id);
$enrollment_stmt->execute();
$enrollment_result = $enrollment_stmt->get_result();

if ($enrollment_result->num_rows == 0) {
    die("You are not enrolled in this course.");
}

$enrollment = $enrollment_result->fetch_assoc();
$current_progress = $enrollment['progress'];

// الحصول على جميع الفصول والدروس لهذه الدورة
$chapters_query = "
    SELECT c.*, 
           (SELECT COUNT(*) FROM lessons l WHERE l.chapter_id = c.chapter_id) as lesson_count
    FROM chapters c 
    WHERE c.course_id = ? 
    ORDER BY c.chapter_order
";
$chapters_stmt = $conn->prepare($chapters_query);
$chapters_stmt->bind_param("i", $course_id);
$chapters_stmt->execute();
$chapters_result = $chapters_stmt->get_result();

// الحصول على جميع الدروس مع حالة إكمالها
$lessons_query = "
    SELECT l.*, 
           COALESCE(sp.is_completed, 0) as is_completed,
           COALESCE(sp.quiz_score, 0) as quiz_score
    FROM lessons l
    LEFT JOIN student_progress sp ON l.lesson_id = sp.lesson_id AND sp.student_email = ?
    WHERE l.course_id = ?
    ORDER BY l.lesson_order
";
$lessons_stmt = $conn->prepare($lessons_query);
$lessons_stmt->bind_param("si", $student_email, $course_id);
$lessons_stmt->execute();
$lessons_result = $lessons_stmt->get_result();

$all_lessons = [];
$completed_lessons = 0;
$total_lessons = 0;

while ($lesson = $lessons_result->fetch_assoc()) {
    $all_lessons[$lesson['chapter_id']][] = $lesson;
    $total_lessons++;
    if ($lesson['is_completed']) {
        $completed_lessons++;
    }
}

// الحصول على معرف الدرس الحالي من الرابط أو تحديد أول درس غير مكتمل
$current_lesson_id = isset($_GET['lesson_id']) ? intval($_GET['lesson_id']) : 0;

// إذا لم يتم تحديد درس، ابحث عن أول درس غير مكتمل
if ($current_lesson_id == 0) {
    foreach ($all_lessons as $chapter_lessons) {
        foreach ($chapter_lessons as $lesson) {
            if (!$lesson['is_completed']) {
                $current_lesson_id = $lesson['lesson_id'];
                break 2;
            }
        }
    }
    // إذا كانت جميع الدروس مكتملة، اعرض الدرس الأخير
    if ($current_lesson_id == 0 && $total_lessons > 0) {
        $current_lesson_id = $all_lessons[array_key_last($all_lessons)][array_key_last($all_lessons[array_key_last($all_lessons)])]['lesson_id'];
    }
}

// الحصول على تفاصيل الدرس الحالي
$current_lesson_query = "
    SELECT l.*, c.chapter_name
    FROM lessons l
    JOIN chapters c ON l.chapter_id = c.chapter_id
    WHERE l.lesson_id = ?
";
$current_lesson_stmt = $conn->prepare($current_lesson_query);
$current_lesson_stmt->bind_param("i", $current_lesson_id);
$current_lesson_stmt->execute();
$current_lesson_result = $current_lesson_stmt->get_result();

if ($current_lesson_result->num_rows == 0) {
    die("Lesson not found.");
}

$current_lesson = $current_lesson_result->fetch_assoc();

// الحصول على محتويات الدرس
$contents_query = "
    SELECT * FROM lesson_contents 
    WHERE lesson_id = ? 
    ORDER BY content_order
";
$contents_stmt = $conn->prepare($contents_query);
$contents_stmt->bind_param("i", $current_lesson_id);
$contents_stmt->execute();
$contents_result = $contents_stmt->get_result();

// الحصول على الاختبار لهذا الدرس
$quiz_query = "SELECT * FROM lesson_quizzes WHERE lesson_id = ?";
$quiz_stmt = $conn->prepare($quiz_query);
$quiz_stmt->bind_param("i", $current_lesson_id);
$quiz_stmt->execute();
$quiz_result = $quiz_stmt->get_result();
$quiz = $quiz_result->num_rows > 0 ? $quiz_result->fetch_assoc() : null;

// التحقق مما إذا كان الطالب قد أجاب على هذا الاختبار مسبقاً
if ($quiz) {
    $quiz_answer_query = "
        SELECT * FROM studentanswer 
        WHERE student_email = ? AND quiz_id = ?
    ";
    $quiz_answer_stmt = $conn->prepare($quiz_answer_query);
    $quiz_answer_stmt->bind_param("si", $student_email, $quiz['quiz_id']);
    $quiz_answer_stmt->execute();
    $quiz_answer_result = $quiz_answer_stmt->get_result();
    $student_answer = $quiz_answer_result->num_rows > 0 ? $quiz_answer_result->fetch_assoc() : null;
} else {
    $student_answer = null;
}

// معالجة إرسال الاختبار
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_answer']) && $quiz) {
    $chosen_option = $_POST['answer'];
    $is_correct = ($chosen_option == $quiz['correct_answer']) ? 1 : 0;

    // تخزين الإجابة في جدول studentanswer باستخدام quiz_id
    $insert_answer_query = "
        INSERT INTO studentanswer (student_email, quiz_id, chosen_option, is_correct) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE chosen_option = ?, is_correct = ?
    ";
    $insert_answer_stmt = $conn->prepare($insert_answer_query);
    $insert_answer_stmt->bind_param("sisssi",
            $student_email,
            $quiz['quiz_id'],
            $chosen_option,
            $is_correct,
            $chosen_option,
            $is_correct
    );

    if (!$insert_answer_stmt->execute()) {
        echo "Error saving answer: " . $conn->error;
        exit();
    }

    if ($is_correct) {
        // تحديث تقدم الطالب
        $update_progress_query = "
            INSERT INTO student_progress (student_email, lesson_id, is_completed, quiz_score, completed_at) 
            VALUES (?, ?, 1, 1, NOW())
            ON DUPLICATE KEY UPDATE is_completed = 1, quiz_score = 1, completed_at = NOW()
        ";
        $update_progress_stmt = $conn->prepare($update_progress_query);
        $update_progress_stmt->bind_param("si", $student_email, $current_lesson_id);

        if (!$update_progress_stmt->execute()) {
            echo "Error updating progress: " . $conn->error;
            exit();
        }

        // تحديث تقدم الدورة في جدول study
        $completed_lessons++;
        $new_progress = ($completed_lessons / $total_lessons) * 100;

        $update_course_progress_query = "
            UPDATE study SET progress = ? 
            WHERE student_email = ? AND course_id = ?
        ";
        $update_course_progress_stmt = $conn->prepare($update_course_progress_query);
        $update_course_progress_stmt->bind_param("dsi", $new_progress, $student_email, $course_id);

        if (!$update_course_progress_stmt->execute()) {
            echo "Error updating course progress: " . $conn->error;
            exit();
        }

        // التحقق مما إذا كانت الدورة مكتملة (التقدم = 100%)
        if ($new_progress >= 100) {
            // إضافة إلى جدول الخريجين
            $graduate_query = "
                INSERT IGNORE INTO graduates (student_email, course_id, completion_date) 
                VALUES (?, ?, CURDATE())
            ";
            $graduate_stmt = $conn->prepare($graduate_query);
            $graduate_stmt->bind_param("si", $student_email, $course_id);

            if (!$graduate_stmt->execute()) {
                echo "Error adding to graduates: " . $conn->error;
            }

            // تحديث حالة الدورة للطالب
            $status_query = "
                INSERT INTO studentcoursestatus (student_email, course_id, status, completion_date) 
                VALUES (?, ?, 'Passed', CURDATE())
                ON DUPLICATE KEY UPDATE status = 'Passed', completion_date = CURDATE()
            ";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("si", $student_email, $course_id);

            if (!$status_stmt->execute()) {
                echo "Error updating studentcoursestatus: " . $conn->error;
            }

            $notification_query = "
                INSERT INTO notifications (user_email, notification_text, is_read) 
                VALUES (?, ?, 0)
            ";
            $notification_text = "Congratulations! You have completed the course '" . $course['name'] . "'. Your certificate is now available.";
            $notification_stmt = $conn->prepare($notification_query);
            $notification_stmt->bind_param("ss", $student_email, $notification_text);

            if (!$notification_stmt->execute()) {
                echo "Error sending notification: " . $conn->error;
            }

            // إعادة التوجيه لعرض الاحتفال
            header("Location: CourseContent.php?course_id=$course_id&lesson_id=$current_lesson_id&completed=1");
            exit();
        }
        // البحث عن الدرس التالي
        $next_lesson_id = 0;
        $found_current = false;

        foreach ($all_lessons as $chapter_lessons) {
            foreach ($chapter_lessons as $lesson) {
                if ($found_current && !$lesson['is_completed']) {
                    $next_lesson_id = $lesson['lesson_id'];
                    break 2;
                }
                if ($lesson['lesson_id'] == $current_lesson_id) {
                    $found_current = true;
                }
            }
        }

        if ($next_lesson_id > 0) {
            header("Location: CourseContent.php?course_id=$course_id&lesson_id=$next_lesson_id");
            exit();
        }
    }

    // إعادة تحميل الصفحة لعرض الملاحظات
    header("Location: CourseContent.php?course_id=$course_id&lesson_id=$current_lesson_id");
    exit();
}

// حساب نسبة التقدم الحالية
$current_progress_percentage = ($completed_lessons / max(1, $total_lessons)) * 100;

// تحديث التقدم في جدول study إذا لزم الأمر (في حالة تغير التقدم)
if ($current_progress_percentage != $current_progress) {
    $update_progress_query = "UPDATE study SET progress = ? WHERE student_email = ? AND course_id = ?";
    $update_progress_stmt = $conn->prepare($update_progress_query);
    $update_progress_stmt->bind_param("dsi", $current_progress_percentage, $student_email, $course_id);
    $update_progress_stmt->execute();
}
?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($course['name']); ?> - EDVORA</title>
        <link rel="stylesheet" href="css/head.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="icon" type="image/png" href="img/icon.png">

        <style>
            /* Header CSS من الكود الأول */
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

            /* الأنماط الأصلية للصفحة الثانية */
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

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            .topbar {
                box-sizing: border-box; /* هذا السطر الجديد */
                width: 100%;
                background: linear-gradient(135deg, var(--primary), var(--primary-dark));
                color: white;
                padding: 0;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                position: sticky;
                top: 0;
                z-index: 1000;
                backdrop-filter: blur(10px);
            }

            .topbar-container {
                box-sizing: border-box; /* هذا السطر الجديد */
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 5%;
                max-width: 1400px;
                margin: 0 auto;
            }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background-color: var(--light);
                color: var(--dark);
                line-height: 1.6;
            }

            .course-container {
                display: flex;
                min-height: calc(100vh - 70px);
                max-width: 1400px;
                margin: 0 auto;
            }

            .course-sidebar {
                width: 300px;
                background-color: white;
                padding: 30px 20px;
                box-shadow: var(--shadow);
                border-radius: 0 0 var(--border-radius) 0;
                overflow-y: auto;
            }

            .course-sidebar h2 {
                color: var(--dark);
                font-size: 24px;
                margin-bottom: 20px;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .chapter {
                margin-bottom: 20px;
            }

            .chapter-title {
                font-weight: 600;
                color: var(--primary);
                padding: 10px 15px;
                background: var(--gray-light);
                border-radius: 8px;
                margin-bottom: 10px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: space-between;
                border-left: 4px solid var(--primary);
                transition: var(--transition);
            }

            .chapter-title:hover {
                background: #eef5ff;
            }

            .chapter-title i {
                transition: transform 0.3s;
            }

            .lessons {
                list-style: none;
                padding-left: 20px;
                margin-top: 10px;
                display: block;
            }

            .lesson {
                padding: 12px 15px;
                margin-bottom: 5px;
                border-radius: var(--border-radius);
                cursor: pointer;
                color: var(--dark);
                transition: var(--transition);
                display: flex;
                align-items: center;
                gap: 10px;
                position: relative;
            }

            .lesson.active,
            .lesson:hover {
                background-color: var(--primary);
                color: white;
            }

            .lesson.completed {
                color: var(--success);
                padding-left: 30px;
            }

            .lesson.completed::before {
                content: '✓';
                position: absolute;
                left: 10px;
                font-weight: bold;
            }

            .course-content {
                flex: 1;
                padding: 40px;
                background: white;
                margin: 20px;
                border-radius: var(--border-radius);
                box-shadow: var(--shadow);
            }

            .lesson-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 2px solid var(--gray-light);
            }

            .lesson-title {
                font-size: 28px;
                color: var(--dark);
            }

            .progress {
                background: var(--gray-light);
                padding: 8px 15px;
                border-radius: 20px;
                font-size: 14px;
                font-weight: 500;
            }

            .lesson-body {
                margin-bottom: 40px;
            }

            .code-example {
                background: #2d2d2d;
                color: #f8f8f2;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
                font-family: 'Courier New', monospace;
                overflow-x: auto;
                border-left: 4px solid var(--primary);
            }

            .text-content {
                background: var(--gray-light);
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
                line-height: 1.7;
                border-left: 4px solid var(--success);
            }

            .quiz-section {
                background: var(--gray-light);
                padding: 25px;
                border-radius: var(--border-radius);
                margin-top: 30px;
                border: 2px solid var(--gray-light);
            }

            .quiz-section h3 {
                color: var(--dark);
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 2px solid var(--primary);
            }

            .quiz-question {
                font-size: 18px;
                font-weight: 600;
                margin-bottom: 20px;
                color: var(--dark);
            }

            .quiz-options {
                display: flex;
                flex-direction: column;
                gap: 12px;
                margin-bottom: 20px;
            }

            .quiz-option {
                padding: 15px;
                background: white;
                border: 2px solid var(--gray-light);
                border-radius: 8px;
                cursor: pointer;
                transition: var(--transition);
            }

            .quiz-option:hover {
                border-color: var(--primary);
                background: #f0f7ff;
            }

            .quiz-option.selected {
                border-color: var(--primary);
                background: rgba(103, 87, 136, 0.1);
            }

            .quiz-option.correct {
                border-color: var(--success);
                background: rgba(76, 175, 80, 0.1);
                color: #155724;
            }

            .quiz-option.incorrect {
                border-color: var(--secondary);
                background: rgba(255, 101, 132, 0.1);
                color: #721c24;
            }

            .quiz-feedback {
                padding: 15px;
                border-radius: 8px;
                margin-top: 15px;
                display: none;
            }

            .quiz-feedback.correct {
                background: rgba(76, 175, 80, 0.1);
                color: var(--success);
                border: 1px solid var(--success);
                display: block;
            }

            .quiz-feedback.incorrect {
                background: rgba(255, 101, 132, 0.1);
                color: var(--secondary);
                border: 1px solid var(--secondary);
                display: block;
            }

            .submit-btn {
                background-color: var(--primary);
                color: white;
                border: none;
                padding: 12px 30px;
                border-radius: 8px;
                cursor: pointer;
                font-size: 16px;
                font-weight: 600;
                transition: var(--transition);
            }

            .submit-btn:hover {
                background-color: var(--primary-dark);
            }

            .next-lesson {
                background-color: var(--success);
                color: white;
                border: none;
                padding: 12px 30px;
                border-radius: 8px;
                cursor: pointer;
                font-size: 16px;
                font-weight: 600;
                transition: var(--transition);
                margin-top: 20px;
            }

            .next-lesson:hover {
                background-color: #45a049;
            }

            /* تأثيرات الاحتفال */
            .confetti {
                position: fixed;
                width: 15px;
                height: 15px;
                opacity: 0;
                pointer-events: none;
                z-index: 1999;
            }

            .balloon {
                position: fixed;
                width: 60px;
                height: 80px;
                border-radius: 50%;
                opacity: 0;
                pointer-events: none;
                z-index: 1999;
                animation: floatUp 8s ease-in forwards;
            }

            @keyframes floatUp {
                0% {
                    transform: translateY(100vh) rotate(0deg);
                    opacity: 1;
                }
                100% {
                    transform: translateY(-100px) rotate(360deg);
                    opacity: 0;
                }
            }

            /* مودال الاحتفال */
            .celebration-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.8);
                z-index: 2000;
                justify-content: center;
                align-items: center;
            }

            .celebration-content {
                background: white;
                padding: 50px;
                border-radius: 15px;
                text-align: center;
                max-width: 600px;
                animation: popIn 0.5s ease-out;
                box-shadow: var(--shadow);
            }

            @keyframes popIn {
                0% { transform: scale(0.5); opacity: 0; }
                100% { transform: scale(1); opacity: 1; }
            }

            .celebration-content i {
                font-size: 5rem;
                color: var(--primary);
                margin-bottom: 20px;
                animation: bounce 1s infinite;
            }

            @keyframes bounce {
                0%, 100% { transform: translateY(0); }
                50% { transform: translateY(-20px); }
            }

            .celebration-content h2 {
                color: var(--dark);
                margin-bottom: 20px;
            }

            .celebration-content p {
                color: #555;
                margin-bottom: 30px;
                line-height: 1.6;
            }

            .celebration-btn {
                background: linear-gradient(135deg, var(--primary), var(--primary-dark));
                color: white;
                border: none;
                padding: 12px 40px;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 600;
                font-size: 1rem;
                transition: var(--transition);
            }

            .celebration-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(103, 87, 136, 0.3);
            }

            @media (max-width: 768px) {
                .course-container {
                    flex-direction: column;
                }

                .course-sidebar {
                    width: 100%;
                    border-radius: 0;
                }

                .course-content {
                    margin: 10px;
                    padding: 20px;
                }

                .lesson-header {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 15px;
                }

                .progress {
                    align-self: flex-start;
                }
            }
        </style>
    </head>
    <body>
    <!-- الشريط العلوي الجديد (مطابق للكود الأول) -->
    <header class="topbar">
        <div class="topbar-container">
            <div class="logo-section">
                <div class="logo" onclick="window.location.href='student_home.php'">
                    <i class="fas fa-graduation-cap"></i>
                    <div class="logo-text">
                        <span class="logo-main">EDVORA</span>
                        <span class="logo-sub">EDUCATIONAL ACADEMY</span>
                    </div>
                </div>
            </div>

            <nav class="main-menu">
                <a href="student_home.php">Home</a>
                <a href="student_mycourse.php" class="active">My Courses</a>
                <a href="student_add_course.php">Add Courses</a>
            </nav>

            <div class="right-section">
                <div class="notification-container">
                    <div class="notification-icon">
                        <i class="fas fa-bell"></i>
                        <?php if($unread_count > 0): ?>
                            <div class="notification-badge"><?php echo $unread_count; ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="notifications-dropdown">
                        <div class="notifications-header">
                            <h3>Notifications</h3>
                            <button class="mark-all-read" onclick="markAllAsRead()">Mark all as read</button>
                        </div>
                        <div class="notifications-list">
                            <?php while($notification = $notifications->fetch_assoc()): ?>
                                <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>"
                                     data-id="<?php echo $notification['notification_id']; ?>">
                                    <div class="notification-icon-small info">
                                        <i class="fas fa-bell"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">Notification</div>
                                        <div class="notification-message"><?php echo $notification['notification_text']; ?></div>
                                        <div class="notification-time"><?php echo time_ago($notification['created_at']); ?></div>
                                    </div>
                                    <?php if(!$notification['is_read']): ?>
                                        <div class="notification-dot"></div>
                                    <?php endif; ?>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>

                <button class="user-btn" onclick="window.location.href='StudentProfile.php'">
                    <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="User Profile">
                    <span class="username"><?php echo htmlspecialchars($student_data['firstname'] . ' ' . $student_data['lastname']); ?></span>
                </button>

                <button class="logout" onclick="window.location.href='logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>
    </header>

    <div class="course-container">
        <aside class="course-sidebar">
            <h2><i class="fas fa-book"></i> <?php echo htmlspecialchars($course['name']); ?></h2>

            <?php while ($chapter = $chapters_result->fetch_assoc()): ?>
                <div class="chapter">
                    <div class="chapter-title">
                        <span><?php echo htmlspecialchars($chapter['chapter_name']); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <ul class="lessons">
                        <?php if (isset($all_lessons[$chapter['chapter_id']])): ?>
                            <?php foreach ($all_lessons[$chapter['chapter_id']] as $lesson): ?>
                                <li class="lesson
                                <?php echo $lesson['lesson_id'] == $current_lesson_id ? 'active' : ''; ?>
                                <?php echo $lesson['is_completed'] ? 'completed' : ''; ?>"
                                    data-lesson-id="<?php echo $lesson['lesson_id']; ?>"
                                    onclick="window.location.href='CourseContent.php?course_id=<?php echo $course_id; ?>&lesson_id=<?php echo $lesson['lesson_id']; ?>'">
                                    <?php echo htmlspecialchars($lesson['lesson_title']); ?>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endwhile; ?>
        </aside>

        <main class="course-content">
            <div class="lesson-header">
                <h1 class="lesson-title">
                    <?php echo htmlspecialchars($current_lesson['lesson_title']); ?>
                    <small style="font-size: 1rem; color: var(--gray); display: block; margin-top: 5px;">
                        Chapter: <?php echo htmlspecialchars($current_lesson['chapter_name']); ?>
                    </small>
                </h1>
                <div class="progress">Progress: <?php echo round($current_progress_percentage); ?>%</div>
            </div>

            <div class="lesson-body">
                <?php if ($contents_result->num_rows > 0): ?>
                    <?php while ($content = $contents_result->fetch_assoc()): ?>
                        <?php if ($content['content_type'] == 'text'): ?>
                            <div class="text-content">
                                <?php if ($content['content_title']): ?>
                                    <h4><?php echo htmlspecialchars($content['content_title']); ?></h4>
                                <?php endif; ?>
                                <p><?php echo nl2br(htmlspecialchars($content['content_text'])); ?></p>
                            </div>
                        <?php elseif ($content['content_type'] == 'code'): ?>
                            <div class="code-example">
                                <?php if ($content['content_title']): ?>
                                    <h4 style="color: var(--primary); margin-bottom: 10px;"><?php echo htmlspecialchars($content['content_title']); ?></h4>
                                <?php endif; ?>
                                <pre><code class="language-<?php echo htmlspecialchars($content['code_language']); ?>"><?php echo htmlspecialchars($content['code_content']); ?></code></pre>
                                <?php if ($content['code_language']): ?>
                                    <div style="color: var(--gray); font-size: 0.9rem; margin-top: 10px;">
                                        Language: <?php echo htmlspecialchars($content['code_language']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="color: var(--gray); text-align: center; padding: 40px;">No content available for this lesson yet.</p>
                <?php endif; ?>
            </div>

            <?php if ($quiz): ?>
                <div class="quiz-section">
                    <h3>Test Your Understanding</h3>
                    <div class="quiz-question"><?php echo htmlspecialchars($quiz['question_text']); ?></div>

                    <div class="quiz-options">
                        <?php if ($quiz['option_a']): ?>
                            <div class="quiz-option" data-value="A" data-correct="<?php echo $quiz['correct_answer'] == 'A' ? 'true' : 'false'; ?>">
                                <?php echo htmlspecialchars($quiz['option_a']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($quiz['option_b']): ?>
                            <div class="quiz-option" data-value="B" data-correct="<?php echo $quiz['correct_answer'] == 'B' ? 'true' : 'false'; ?>">
                                <?php echo htmlspecialchars($quiz['option_b']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($quiz['option_c']): ?>
                            <div class="quiz-option" data-value="C" data-correct="<?php echo $quiz['correct_answer'] == 'C' ? 'true' : 'false'; ?>">
                                <?php echo htmlspecialchars($quiz['option_c']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($quiz['option_d']): ?>
                            <div class="quiz-option" data-value="D" data-correct="<?php echo $quiz['correct_answer'] == 'D' ? 'true' : 'false'; ?>">
                                <?php echo htmlspecialchars($quiz['option_d']); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="quiz-feedback"></div>

                    <?php if (!$student_answer || !$student_answer['is_correct']): ?>
                        <form method="POST" id="quizForm">
                            <input type="hidden" name="answer" id="selectedAnswer">
                            <input type="hidden" name="submit_answer" value="1">
                            <button type="button" class="submit-btn" id="submitBtn">Submit Answer</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($student_answer && $student_answer['is_correct'] && $current_progress_percentage < 100): ?>
                        <button class="next-lesson" onclick="findNextLesson()">Continue to Next Lesson</button>
                    <?php elseif ($current_progress_percentage >= 100): ?>
                        <div style="background: rgba(76, 175, 80, 0.1); color: #155724; padding: 20px; border-radius: 8px; border: 1px solid rgba(76, 175, 80, 0.3); margin-top: 20px;">
                            <h4>🎉 Course Completed!</h4>
                            <p>Congratulations! You have successfully completed this course. Check your notifications for certificate information.</p>
                            <button class="next-lesson" onclick="window.location.href='student_mycourse.php'">Back to My Courses</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- مودال الاحتفال -->
    <div class="celebration-modal" id="celebrationModal">
        <div class="celebration-content">
            <i class="fas fa-trophy"></i>
            <h2>🎉 Course Completed! 🎉</h2>
            <p>Congratulations! You have successfully completed the course "<strong><?php echo htmlspecialchars($course['name']); ?></strong>"</p>
            <p>Your certificate is now available. Check your notifications for more information.</p>
            <button class="celebration-btn" onclick="closeCelebration()">Continue</button>
        </div>
    </div>

    <script>
        // وظائف الإشعارات من الكود الأول
        // Mark all notifications as read
        async function markAllAsRead() {
            try {
                const response = await fetch('mark_notifications_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                });

                if (response.ok) {
                    document.querySelectorAll('.notification-item').forEach(item => {
                        item.classList.remove('unread');
                        item.querySelector('.notification-dot')?.remove();
                    });
                    document.querySelector('.notification-badge')?.remove();
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // اختيار خيار الاختبار
        document.querySelectorAll('.quiz-option').forEach(option => {
            option.addEventListener('click', function() {
                // إزالة الصنف المحدد من جميع الخيارات
                document.querySelectorAll('.quiz-option').forEach(opt => {
                    opt.classList.remove('selected');
                });

                // إضافة الصنف المحدد للخيار المنقرة عليه
                this.classList.add('selected');

                // تخزين القيمة المختارة
                document.getElementById('selectedAnswer').value = this.getAttribute('data-value');
            });
        });

        // إرسال الإجابة
        document.getElementById('submitBtn')?.addEventListener('click', function() {
            const selectedOption = document.querySelector('.quiz-option.selected');
            const selectedAnswer = document.getElementById('selectedAnswer').value;
            const feedback = document.querySelector('.quiz-feedback');
            const quizForm = document.getElementById('quizForm');

            if (!selectedOption) {
                feedback.textContent = 'Please select an answer!';
                feedback.className = 'quiz-feedback incorrect';
                return;
            }

            // عرض الملاحظات فوراً
            const isCorrect = selectedOption.getAttribute('data-correct') === 'true';

            // مسح الأنماط السابقة
            document.querySelectorAll('.quiz-option').forEach(opt => {
                opt.classList.remove('correct', 'incorrect');
            });

            // عرض الإجابات الصحيحة/الخاطئة
            document.querySelectorAll('.quiz-option').forEach(opt => {
                if (opt.getAttribute('data-correct') === 'true') {
                    opt.classList.add('correct');
                }
            });

            if (!isCorrect) {
                selectedOption.classList.add('incorrect');
                feedback.textContent = 'Incorrect. Try again!';
                feedback.className = 'quiz-feedback incorrect';
                return; // لا ترسل النموذج، دع المستخدم يحاول مرة أخرى
            }

            // إذا كانت الإجابة صحيحة، أرسل النموذج
            feedback.textContent = 'Correct! Well done!';
            feedback.className = 'quiz-feedback correct';

            // إرسال النموذج بعد تأخير
            setTimeout(() => {
                quizForm.submit();
            }, 1500);
        });

        // البحث عن الدرس التالي
        function findNextLesson() {
            window.location.href = 'CourseContent.php?course_id=<?php echo $course_id; ?>&find_next=1';
        }

        // وظائف الاحتفال
        function createConfetti() {
            const colors = ['#f44336', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5', '#2196f3', '#03a9f4', '#00bcd4', '#009688', '#4CAF50'];
            for (let i = 0; i < 150; i++) {
                const confetti = document.createElement('div');
                confetti.className = 'confetti';
                confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.left = Math.random() * 100 + 'vw';
                confetti.style.top = '-10px';
                confetti.style.transform = `rotate(${Math.random() * 360}deg)`;

                const animation = confetti.animate([
                    { transform: `translateY(0px) rotate(0deg)`, opacity: 1 },
                    { transform: `translateY(${window.innerHeight + 100}px) rotate(${Math.random() * 360}deg)`, opacity: 0 }
                ], {
                    duration: Math.random() * 3000 + 2000,
                    easing: 'cubic-bezier(0.215, 0.610, 0.355, 1)'
                });

                document.body.appendChild(confetti);
                animation.onfinish = () => confetti.remove();
            }
        }

        function createBalloons() {
            const colors = ['#ff6b6b', '#4ecdc4', '#45b7d1', '#96ceb4', '#ffeaa7', '#dfe6e9', '#a29bfe', '#fd79a8'];
            for (let i = 0; i < 20; i++) {
                const balloon = document.createElement('div');
                balloon.className = 'balloon';
                balloon.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                balloon.style.left = Math.random() * 100 + 'vw';
                balloon.style.width = (Math.random() * 40 + 40) + 'px';
                balloon.style.height = (Math.random() * 60 + 60) + 'px';

                document.body.appendChild(balloon);
                setTimeout(() => balloon.remove(), 8000);
            }
        }

        function showCelebration() {
            createConfetti();
            createBalloons();
            document.getElementById('celebrationModal').style.display = 'flex';
        }

        function closeCelebration() {
            document.getElementById('celebrationModal').style.display = 'none';
            window.location.href = 'student_mycourse.php';
        }

        // التهيئة
        document.addEventListener('DOMContentLoaded', function() {
            // عرض الاحتفال إذا تم إكمال الدورة للتو
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('completed') === '1') {
                showCelebration();
            }

            // تبديل ظهور الفصول
            document.querySelectorAll('.chapter-title').forEach(title => {
                title.addEventListener('click', function() {
                    const lessons = this.nextElementSibling;
                    const icon = this.querySelector('i');

                    if (lessons.style.display === 'none' || lessons.style.display === '') {
                        lessons.style.display = 'block';
                        icon.className = 'fas fa-chevron-down';
                    } else {
                        lessons.style.display = 'none';
                        icon.className = 'fas fa-chevron-right';
                    }
                });
            });

            // إذا أجاب الطالب بالفعل بشكل خاطئ، عرض الملاحظات
            <?php if ($student_answer && !$student_answer['is_correct'] && $quiz): ?>
            const feedback = document.querySelector('.quiz-feedback');
            feedback.textContent = 'Your previous answer was incorrect. Please try again!';
            feedback.className = 'quiz-feedback incorrect';

            // عرض الإجابات الصحيحة
            document.querySelectorAll('.quiz-option').forEach(opt => {
                if (opt.getAttribute('data-correct') === 'true') {
                    opt.classList.add('correct');
                }
            });
            <?php endif; ?>
        });
    </script>
    </body>
    </html>
<?php
// Function to display time ago (من الكود الأول)
function time_ago($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins == 1 ? '' : 's') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours == 1 ? '' : 's') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days == 1 ? '' : 's') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}
?>