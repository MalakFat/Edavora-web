<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

$manager_email = $_SESSION['email'];

$conn = new mysqli("localhost", "root", "", "edavora");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

$user_info = [
        'firstname' => 'Manager',
        'lastname' => 'User',
        'profileimage' => 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'
];

$user_query = $conn->prepare("SELECT firstname, lastname, profileimage FROM users WHERE email = ?");
if ($user_query) {
    $user_query->bind_param("s", $manager_email);
    $user_query->execute();
    $firstname = "";
    $lastname = "";
    $profileimage = "";
    $user_query->bind_result($firstname, $lastname, $profileimage);
    if ($user_query->fetch()) {
        $user_info = [
                'firstname' => $firstname,
                'lastname' => $lastname,
                'profileimage' => $profileimage ?: 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'
        ];
    }
    $user_query->close();
}

// Fetch notifications
$notifications = [];
$unread_notifications_count = 0;

$notifications_query = $conn->prepare("
    SELECT notification_id, notification_text, is_read, created_at 
    FROM notifications 
    WHERE user_email = ? 
    ORDER BY created_at DESC 
    LIMIT 50
");

if ($notifications_query) {
    $notifications_query->bind_param("s", $manager_email);
    $notifications_query->execute();
    $result = $notifications_query->get_result();

    while ($row = $result->fetch_assoc()) {
        $created = new DateTime($row['created_at']);
        $now = new DateTime();
        $diff = $now->diff($created);

        if ($diff->d > 0) {
            $time_ago = $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
        } elseif ($diff->h > 0) {
            $time_ago = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        } elseif ($diff->i > 0) {
            $time_ago = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        } else {
            $time_ago = 'Just now';
        }

        $notifications[] = [
                'id' => $row['notification_id'],
                'text' => $row['notification_text'],
                'is_read' => $row['is_read'],
                'time_ago' => $time_ago
        ];

        if (!$row['is_read']) {
            $unread_notifications_count++;
        }
    }
    $notifications_query->close();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'mark_all_notifications_read') {
        $update_query = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_email = ?");
        $update_query->bind_param("s", $manager_email);
        $success = $update_query->execute();
        $update_query->close();

        echo json_encode(['success' => $success]);
        exit;
    }

    // جلب الطلاب المسجلين في دورات
    if ($_POST['action'] === 'get_students_with_courses') {
        $query = "SELECT DISTINCT u.email, u.firstname, u.lastname, u.profileimage
                  FROM users u
                  INNER JOIN study s ON u.email = s.student_email
                  ORDER BY u.firstname, u.lastname";
        $result = $conn->query($query);
        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = [
                    'email' => $row['email'],
                    'firstname' => $row['firstname'],
                    'lastname' => $row['lastname'],
                    'profileimage' => $row['profileimage'] ?: 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'
            ];
        }
        echo json_encode(['success' => true, 'students' => $students]);
        exit;
    }

    // جلب دورات الطالب مع التقدم والغيابات
    if ($_POST['action'] === 'get_student_courses_with_details') {
        $student_email = $_POST['student_email'] ?? '';

        // جلب الدورات مع progress
        $query = $conn->prepare("
            SELECT c.id, c.name, s.progress, c.price
            FROM study s
            INNER JOIN courses c ON s.course_id = c.id
            WHERE s.student_email = ?
        ");
        $query->bind_param("s", $student_email);
        $query->execute();
        $result = $query->get_result();
        $courses = [];

        while ($row = $result->fetch_assoc()) {
            // حساب الغيابات
            $absences_query = $conn->prepare("
                SELECT COUNT(*) as absences_count
                FROM attendance
                WHERE student_email = ? AND course_id = ? AND attendance_status = 'A'
            ");
            $absences_query->bind_param("si", $student_email, $row['id']);
            $absences_query->execute();
            $absences_result = $absences_query->get_result();
            $absences_row = $absences_result->fetch_assoc();
            $absences = $absences_row['absences_count'] ?? 0;
            $absences_query->close();

            $courses[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'progress' => $row['progress'],
                    'absences' => $absences,
                    'price' => $row['price']
            ];
        }
        $query->close();

        echo json_encode(['success' => true, 'courses' => $courses]);
        exit;
    }

    // حذف الطالب من دورة مع استرداد المبلغ
    if ($_POST['action'] === 'remove_student_from_course') {
        $student_email = $_POST['student_email'] ?? '';
        $course_id = $_POST['course_id'] ?? '';

        $conn->begin_transaction();
        try {
            // جلب سعر الدورة
            $course_query = $conn->prepare("SELECT price, name FROM courses WHERE id = ?");
            $course_query->bind_param("i", $course_id);
            $course_query->execute();
            $course_result = $course_query->get_result();
            $course_row = $course_result->fetch_assoc();
            $course_price = $course_row['price'];
            $course_name = $course_row['name'];
            $course_query->close();

            // جلب رصيد الطالب الحالي
            $student_query = $conn->prepare("SELECT balance FROM students WHERE email = ?");
            $student_query->bind_param("s", $student_email);
            $student_query->execute();
            $student_result = $student_query->get_result();
            $student_row = $student_result->fetch_assoc();
            $current_balance = $student_row['balance'];
            $student_query->close();

            // تحديث رصيد الطالب (إضافة سعر الدورة)
            $new_balance = $current_balance + $course_price;
            $update_balance = $conn->prepare("UPDATE students SET balance = ? WHERE email = ?");
            $update_balance->bind_param("ds", $new_balance, $student_email);
            $update_balance->execute();
            $update_balance->close();

            // حذف الطالب من الدورة (من جدول study)
            $delete_study = $conn->prepare("DELETE FROM study WHERE student_email = ? AND course_id = ?");
            $delete_study->bind_param("si", $student_email, $course_id);
            $delete_study->execute();
            $delete_study->close();

            // حذف الحضور الخاص بالطالب في هذه الدورة
            $delete_attendance = $conn->prepare("DELETE FROM attendance WHERE student_email = ? AND course_id = ?");
            $delete_attendance->bind_param("si", $student_email, $course_id);
            $delete_attendance->execute();
            $delete_attendance->close();

            // حذف التقدم الدراسي
            $delete_progress = $conn->prepare("DELETE FROM student_progress WHERE student_email = ? AND lesson_id IN (SELECT lesson_id FROM lessons WHERE course_id = ?)");
            $delete_progress->bind_param("si", $student_email, $course_id);
            $delete_progress->execute();
            $delete_progress->close();

            // حذف إجابات الاختبارات
            $delete_answers = $conn->prepare("DELETE FROM studentanswer WHERE student_email = ? AND quiz_id IN (SELECT q.quiz_id FROM lesson_quizzes q INNER JOIN lessons l ON q.lesson_id = l.lesson_id WHERE l.course_id = ?)");
            $delete_answers->bind_param("si", $student_email, $course_id);
            $delete_answers->execute();
            $delete_answers->close();

            // حذف حالة الدورة
            $delete_status = $conn->prepare("DELETE FROM studentcoursestatus WHERE student_email = ? AND course_id = ?");
            $delete_status->bind_param("si", $student_email, $course_id);
            $delete_status->execute();
            $delete_status->close();

            // حذف الشهادات
            $delete_certificates = $conn->prepare("DELETE FROM student_certificates WHERE student_email = ? AND course_id = ?");
            $delete_certificates->bind_param("si", $student_email, $course_id);
            $delete_certificates->execute();
            $delete_certificates->close();

            // إضافة إشعار للطالب
            $student_notif = $conn->prepare("INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $notif_text = "You have been removed from course \"$course_name\". Course fee of $$course_price has been refunded to your balance.";
            $student_notif->bind_param("ss", $student_email, $notif_text);
            $student_notif->execute();
            $student_notif->close();

            // إضافة إشعار للمدير
            $manager_notif = $conn->prepare("INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $manager_notif_text = "Student $student_email has been removed from course \"$course_name\". Course fee of $$course_price has been refunded.";
            $manager_notif->bind_param("ss", $manager_email, $manager_notif_text);
            $manager_notif->execute();
            $manager_notif->close();

            $conn->commit();
            echo json_encode(['success' => true, 'refunded' => $course_price]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // جلب دورات الطالب
    if ($_POST['action'] === 'get_student_courses') {
        $email = $_POST['email'] ?? '';

        $query = $conn->prepare("
            SELECT c.id, c.name, c.teacher, u.firstname as teacher_firstname, u.lastname as teacher_lastname
            FROM study sc
            INNER JOIN courses c ON sc.course_id = c.id
            LEFT JOIN users u ON c.teacher = u.email
            WHERE sc.student_email = ?
            ORDER BY c.name
        ");
        $query->bind_param("s", $email);
        $query->execute();
        $result = $query->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $courses[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'teacher' => $row['teacher_firstname'] . ' ' . $row['teacher_lastname']
            ];
        }
        $query->close();

        echo json_encode(['success' => true, 'courses' => $courses]);
        exit;
    }

    // إرسال إشعار الدورة للطالب
    if ($_POST['action'] === 'send_course_notification') {
        $student_email = $_POST['student_email'] ?? '';
        $course_name = $_POST['course_name'] ?? '';
        $custom_message = $_POST['custom_message'] ?? '';

        // الحصول على اسم الطالب
        $name_query = $conn->prepare("SELECT CONCAT(firstname, ' ', lastname) as name FROM users WHERE email = ?");
        if (!$name_query) {
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit;
        }

        $name_query->bind_param("s", $student_email);
        if (!$name_query->execute()) {
            echo json_encode(['success' => false, 'error' => $name_query->error]);
            $name_query->close();
            exit;
        }

        $name_result = $name_query->get_result();
        $name_row = $name_result->fetch_assoc();
        $student_name = $name_row['name'] ?? 'Student';
        $name_query->close();

        // استخدام الرسالة المخصصة أو الافتراضية
        $notif_text = $custom_message ?: "Your teacher is following your progress in the course: \"$course_name\". We encourage you to complete all lessons and assignments.";

        // إنشاء إشعار للطالب
        $notif_stmt = $conn->prepare("INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())");
        if (!$notif_stmt) {
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit;
        }

        $notif_stmt->bind_param("ss", $student_email, $notif_text);
        $success = $notif_stmt->execute();
        $notif_stmt->close();

        // إنشاء إشعار للمدير
        if ($success) {
            $manager_notif_text = "Course notification sent to \"$student_name\" for course \"$course_name\"";
            $manager_notif_stmt = $conn->prepare("INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())");
            if ($manager_notif_stmt) {
                $manager_notif_stmt->bind_param("ss", $manager_email, $manager_notif_text);
                $manager_notif_stmt->execute();
                $manager_notif_stmt->close();
            }
        }

        echo json_encode(['success' => $success]);
        exit;
    }

    // حذف المعلم مع إعادة تعيين المقررات - تم التصحيح
    if ($_POST['action'] === 'delete_teacher_with_reassign') {
        $teacher_email = $_POST['teacher_email'] ?? '';
        $reassignments_json = $_POST['reassignments'] ?? '[]';
        $reassignments = json_decode($reassignments_json, true);

        $conn->begin_transaction();
        try {
            // تحديث المقررات بالمعلمين الجدد
            foreach ($reassignments as $course_id => $new_teacher_email) {
                if (!empty($new_teacher_email)) {
                    $update_stmt = $conn->prepare("UPDATE courses SET teacher = ? WHERE id = ?");
                    $update_stmt->bind_param("si", $new_teacher_email, $course_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }

            // حذف المعلم
            $delete_stmt = $conn->prepare("DELETE FROM users WHERE email = ?");
            $delete_stmt->bind_param("s", $teacher_email);
            $success = $delete_stmt->execute();
            $delete_stmt->close();

            if ($success) {
                $name_query = $conn->prepare("SELECT CONCAT(firstname, ' ', lastname) as name FROM users WHERE email = ?");
                $name_query->bind_param("s", $teacher_email);
                $name_query->execute();
                $name_result = $name_query->get_result();
                $name_row = $name_result->fetch_assoc();
                $teacher_name = $name_row['name'] ?? 'Unknown';
                $name_query->close();

                $notif_stmt = $conn->prepare("INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())");
                $notif_text = "Teacher \"$teacher_name\" has been deleted and courses reassigned";
                $notif_stmt->bind_param("ss", $manager_email, $notif_text);
                $notif_stmt->execute();
                $notif_stmt->close();
            }

            $conn->commit();
            echo json_encode(['success' => $success]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // جلب المقررات التي يدرسها المعلم
    if ($_POST['action'] === 'get_teacher_courses') {
        $teacher_email = $_POST['teacher_email'] ?? '';

        $query = $conn->prepare("
            SELECT id, name, teacher
            FROM courses
            WHERE teacher = ?
            ORDER BY name
        ");
        $query->bind_param("s", $teacher_email);
        $query->execute();
        $result = $query->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $courses[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'teacher' => $row['teacher']
            ];
        }
        $query->close();

        echo json_encode(['success' => true, 'courses' => $courses]);
        exit;
    }

    // جلب معلومات حذف المقرر مع تفاصيل الاسترداد
    if ($_POST['action'] === 'get_course_deletion_info') {
        $course_id = $_POST['course_id'] ?? '';

        $query = $conn->prepare("
            SELECT c.id, c.name, c.price,
                   (SELECT COUNT(*) FROM study WHERE course_id = c.id) as student_count,
                   (SELECT COUNT(*) * c.price FROM study WHERE course_id = c.id) as total_refund
            FROM courses c
            WHERE c.id = ?
        ");
        $query->bind_param("i", $course_id);
        $query->execute();
        $result = $query->get_result();

        if ($row = $result->fetch_assoc()) {
            $query2 = $conn->prepare("
                SELECT u.firstname, u.lastname, u.email, s.balance
                FROM study sc
                INNER JOIN users u ON sc.student_email = u.email
                INNER JOIN students s ON sc.student_email = s.email
                WHERE sc.course_id = ?
                ORDER BY u.firstname, u.lastname
            ");
            $query2->bind_param("i", $course_id);
            $query2->execute();
            $result2 = $query2->get_result();

            $students = [];
            while ($student_row = $result2->fetch_assoc()) {
                $students[] = [
                        'name' => $student_row['firstname'] . ' ' . $student_row['lastname'],
                        'email' => $student_row['email'],
                        'current_balance' => $student_row['balance'],
                        'new_balance' => $student_row['balance'] + $row['price']
                ];
            }
            $query2->close();

            echo json_encode([
                    'success' => true,
                    'course' => [
                            'id' => $row['id'],
                            'name' => $row['name'],
                            'price' => $row['price'],
                            'student_count' => $row['student_count'],
                            'total_refund' => $row['total_refund'] ?: 0,
                            'students' => $students
                    ]
            ]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    // حذف المقرر مع استرداد الأموال للطلاب
    if ($_POST['action'] === 'delete_course') {
        $course_id = $_POST['course_id'] ?? '';

        $conn->begin_transaction();
        try {
            // جلب معلومات المقرر والسعر
            $course_query = $conn->prepare("SELECT name, price FROM courses WHERE id = ?");
            $course_query->bind_param("i", $course_id);
            $course_query->execute();
            $course_result = $course_query->get_result();
            $course_row = $course_result->fetch_assoc();

            if (!$course_row) {
                throw new Exception("Course not found");
            }

            $course_name = $course_row['name'];
            $course_price = $course_row['price'];

            // جلب الطلاب المسجلين في المقرر
            $students_query = $conn->prepare("
                SELECT sc.student_email, s.balance, u.firstname, u.lastname
                FROM study sc
                INNER JOIN students s ON sc.student_email = s.email
                INNER JOIN users u ON sc.student_email = u.email
                WHERE sc.course_id = ?
            ");
            $students_query->bind_param("i", $course_id);
            $students_query->execute();
            $students_result = $students_query->get_result();

            $students_count = $students_result->num_rows;
            $refunded_students = [];

            // إعادة السعر لكل طالب
            while ($student = $students_result->fetch_assoc()) {
                $new_balance = $student['balance'] + $course_price;
                $update_balance = $conn->prepare("UPDATE students SET balance = ? WHERE email = ?");
                $update_balance->bind_param("ds", $new_balance, $student['student_email']);
                $update_balance->execute();
                $update_balance->close();

                $refunded_students[] = $student['student_email'];

                // إضافة إشعار للطالب
                $student_notif = $conn->prepare("INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())");
                $notif_text = "Course \"$course_name\" has been deleted. Your balance has been refunded with $$course_price";
                $student_notif->bind_param("ss", $student['student_email'], $notif_text);
                $student_notif->execute();
                $student_notif->close();
            }

            // حذف المقرر وجميع البيانات المرتبطة به
            // حذف التسجيلات من جدول study
            $delete_study = $conn->prepare("DELETE FROM study WHERE course_id = ?");
            $delete_study->bind_param("i", $course_id);
            $delete_study->execute();
            $delete_study->close();

            // حذف الحضور
            $delete_attendance = $conn->prepare("DELETE FROM attendance WHERE course_id = ?");
            $delete_attendance->bind_param("i", $course_id);
            $delete_attendance->execute();
            $delete_attendance->close();

            // حذف الدروس والمحتوى
            $delete_content = $conn->prepare("DELETE FROM lesson_contents WHERE lesson_id IN (SELECT lesson_id FROM lessons WHERE course_id = ?)");
            $delete_content->bind_param("i", $course_id);
            $delete_content->execute();
            $delete_content->close();

            $delete_quizzes = $conn->prepare("DELETE FROM lesson_quizzes WHERE lesson_id IN (SELECT lesson_id FROM lessons WHERE course_id = ?)");
            $delete_quizzes->bind_param("i", $course_id);
            $delete_quizzes->execute();
            $delete_quizzes->close();

            $delete_lessons = $conn->prepare("DELETE FROM lessons WHERE course_id = ?");
            $delete_lessons->bind_param("i", $course_id);
            $delete_lessons->execute();
            $delete_lessons->close();

            // حذف الفصول
            $delete_chapters = $conn->prepare("DELETE FROM chapters WHERE course_id = ?");
            $delete_chapters->bind_param("i", $course_id);
            $delete_chapters->execute();
            $delete_chapters->close();

            // حذف المحاضرات
            $delete_lectures = $conn->prepare("DELETE FROM lectures WHERE course_id = ?");
            $delete_lectures->bind_param("i", $course_id);
            $delete_lectures->execute();
            $delete_lectures->close();

            // حذف الشهادات
            $delete_certificates = $conn->prepare("DELETE FROM student_certificates WHERE course_id = ?");
            $delete_certificates->bind_param("i", $course_id);
            $delete_certificates->execute();
            $delete_certificates->close();

            // حذف سجل التخرج
            $delete_graduates = $conn->prepare("DELETE FROM graduates WHERE course_id = ?");
            $delete_graduates->bind_param("i", $course_id);
            $delete_graduates->execute();
            $delete_graduates->close();

            // حذف سجل حالة الدورة للطلاب
            $delete_status = $conn->prepare("DELETE FROM studentcoursestatus WHERE course_id = ?");
            $delete_status->bind_param("i", $course_id);
            $delete_status->execute();
            $delete_status->close();

            // حذف التقدم الدراسي
            $delete_progress = $conn->prepare("DELETE FROM student_progress WHERE lesson_id IN (SELECT lesson_id FROM lessons WHERE course_id = ?)");
            $delete_progress->bind_param("i", $course_id);
            $delete_progress->execute();
            $delete_progress->close();

            // حذف إجابات الاختبارات
            $delete_answers = $conn->prepare("DELETE FROM studentanswer WHERE quiz_id IN (SELECT q.quiz_id FROM lesson_quizzes q INNER JOIN lessons l ON q.lesson_id = l.lesson_id WHERE l.course_id = ?)");
            $delete_answers->bind_param("i", $course_id);
            $delete_answers->execute();
            $delete_answers->close();

            // حذف المقرر
            $delete_stmt = $conn->prepare("DELETE FROM courses WHERE id = ?");
            $delete_stmt->bind_param("i", $course_id);
            $success = $delete_stmt->execute();
            $delete_stmt->close();

            if ($success) {
                // إشعار للمدير
                $total_refund = $course_price * $students_count;
                $notif_stmt = $conn->prepare("INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())");
                $notif_text = "Course \"$course_name\" has been deleted. $$total_refund refunded to $students_count students.";
                $notif_stmt->bind_param("ss", $manager_email, $notif_text);
                $notif_stmt->execute();
                $notif_stmt->close();
            }

            $conn->commit();
            echo json_encode([
                    'success' => $success,
                    'refunded_amount' => $course_price,
                    'students_count' => $students_count,
                    'total_refund' => $course_price * $students_count
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'delete_student') {
        $email = $_POST['email'] ?? '';

        $name_query = $conn->prepare("SELECT CONCAT(firstname, ' ', lastname) as name FROM users WHERE email = ?");
        $name_query->bind_param("s", $email);
        $name_query->execute();
        $name_result = $name_query->get_result();
        $name_row = $name_result->fetch_assoc();
        $student_name = $name_row['name'] ?? 'Unknown';
        $name_query->close();

        $stmt = $conn->prepare("DELETE FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            $notif_stmt = $conn->prepare("INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $notif_text = "Student \"$student_name\" has been deleted from the system";
            $notif_stmt->bind_param("ss", $manager_email, $notif_text);
            $notif_stmt->execute();
            $notif_stmt->close();
        }

        echo json_encode(['success' => $success]);
        exit;
    }

    if ($_POST['action'] === 'update_teacher') {
        $email = $_POST['email'] ?? '';
        $firstname = $_POST['firstname'] ?? '';
        $lastname = $_POST['lastname'] ?? '';
        $birthdate = $_POST['birthdate'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $job_title = $_POST['job_title'] ?? '';
        $salary = $_POST['salary'] ?? '';

        $conn->begin_transaction();

        try {
            $stmt1 = $conn->prepare("UPDATE users SET firstname = ?, lastname = ?, birthdate = ?, gender = ? WHERE email = ?");
            $stmt1->bind_param("sssss", $firstname, $lastname, $birthdate, $gender, $email);
            $stmt1->execute();
            $stmt1->close();

            $stmt2 = $conn->prepare("UPDATE teachers SET job_title = ?, salary = ? WHERE email = ?");
            $stmt2->bind_param("sds", $job_title, $salary, $email);
            $stmt2->execute();
            $stmt2->close();

            $notif_stmt = $conn->prepare("INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $notif_text = "Teacher \"$firstname $lastname\" profile has been updated";
            $notif_stmt->bind_param("ss", $manager_email, $notif_text);
            $notif_stmt->execute();
            $notif_stmt->close();

            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'update_student') {
        $email = $_POST['email'] ?? '';
        $firstname = $_POST['firstname'] ?? '';
        $lastname = $_POST['lastname'] ?? '';
        $birthdate = $_POST['birthdate'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $balance = $_POST['balance'] ?? '';

        $conn->begin_transaction();

        try {
            $stmt1 = $conn->prepare("UPDATE users SET firstname = ?, lastname = ?, birthdate = ?, gender = ? WHERE email = ?");
            $stmt1->bind_param("sssss", $firstname, $lastname, $birthdate, $gender, $email);
            $stmt1->execute();
            $stmt1->close();

            $stmt2 = $conn->prepare("UPDATE students SET balance = ? WHERE email = ?");
            $stmt2->bind_param("is", $balance, $email);
            $stmt2->execute();
            $stmt2->close();

            $notif_stmt = $conn->prepare("INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $notif_text = "Student \"$firstname $lastname\" profile has been updated";
            $notif_stmt->bind_param("ss", $manager_email, $notif_text);
            $notif_stmt->execute();
            $notif_stmt->close();

            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'update_course') {
        $course_id = $_POST['course_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $days = $_POST['days'] ?? '';
        $teacher = $_POST['teacher'] ?? '';
        $price = $_POST['price'] ?? '';
        $max_students = $_POST['max_students'] ?? '';
        $duration_number = $_POST['duration_number'] ?? null;
        $duration_unit = $_POST['duration_unit'] ?? null;
        $description = $_POST['description'] ?? '';

        try {
            $stmt = $conn->prepare("UPDATE courses SET name = ?, start_time = ?, end_time = ?, days = ?, teacher = ?, price = ?, max_students = ?, duration_number = ?, duration_unit = ?, description = ? WHERE id = ?");
            $stmt->bind_param("sssssdiissi", $name, $start_time, $end_time, $days, $teacher, $price, $max_students, $duration_number, $duration_unit, $description, $course_id);
            $success = $stmt->execute();
            $stmt->close();

            if ($success) {
                $notif_stmt = $conn->prepare("INSERT INTO notifications (user_email, notification_text, is_read, created_at) VALUES (?, ?, 0, NOW())");
                $notif_text = "Course \"$name\" has been updated";
                $notif_stmt->bind_param("ss", $manager_email, $notif_text);
                $notif_stmt->execute();
                $notif_stmt->close();
            }

            echo json_encode(['success' => $success]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'get_teacher_details') {
        $email = $_POST['email'] ?? '';

        $query = $conn->prepare("
            SELECT u.firstname, u.lastname, u.email, u.birthdate, u.gender, t.job_title, t.salary
            FROM users u
            INNER JOIN teachers t ON u.email = t.email
            WHERE u.email = ?
        ");
        $query->bind_param("s", $email);
        $query->execute();
        $result = $query->get_result();

        if ($row = $result->fetch_assoc()) {
            echo json_encode(['success' => true, 'data' => $row]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    if ($_POST['action'] === 'get_student_details') {
        $email = $_POST['email'] ?? '';

        $query = $conn->prepare("
            SELECT u.firstname, u.lastname, u.email, u.birthdate, u.gender, s.balance
            FROM users u
            INNER JOIN students s ON u.email = s.email
            WHERE u.email = ?
        ");
        $query->bind_param("s", $email);
        $query->execute();
        $result = $query->get_result();

        if ($row = $result->fetch_assoc()) {
            echo json_encode(['success' => true, 'data' => $row]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    if ($_POST['action'] === 'get_course_details') {
        $course_id = $_POST['course_id'] ?? '';

        $query = $conn->prepare("
            SELECT id, name, start_time, end_time, days, teacher, price, max_students, duration_number, duration_unit, description
            FROM courses
            WHERE id = ?
        ");
        $query->bind_param("i", $course_id);
        $query->execute();
        $result = $query->get_result();

        if ($row = $result->fetch_assoc()) {
            echo json_encode(['success' => true, 'data' => $row]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    if ($_POST['action'] === 'get_all_teachers_list') {
        $query = "SELECT email, CONCAT(firstname, ' ', lastname) as fullname FROM users WHERE email IN (SELECT email FROM teachers) ORDER BY firstname";
        $result = $conn->query($query);
        $teachers = [];

        while ($row = $result->fetch_assoc()) {
            $teachers[] = $row;
        }

        echo json_encode(['success' => true, 'teachers' => $teachers]);
        exit;
    }

    if ($_POST['action'] === 'get_teachers') {
        $query = "SELECT
                    u.firstname,
                    u.lastname,
                    u.email,
                    u.profileimage,
                    u.gender,
                    u.birthdate,
                    t.job_title,
                    t.salary,
                    (SELECT COUNT(DISTINCT c.id)
                     FROM courses c
                     WHERE c.teacher = u.email) as course_count
                  FROM users u
                  INNER JOIN teachers t ON u.email = t.email
                  ORDER BY u.firstname, u.lastname";

        $result = $conn->query($query);
        $teachers = [];

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $courses_query = $conn->prepare("
                    SELECT name
                    FROM courses
                    WHERE teacher = ?
                    ORDER BY name
                ");
                $courses_query->bind_param("s", $row['email']);
                $courses_query->execute();
                $courses_result = $courses_query->get_result();

                $courses = [];
                while ($course = $courses_result->fetch_assoc()) {
                    $courses[] = $course['name'];
                }

                $birthdate = new DateTime($row['birthdate']);
                $today = new DateTime();
                $age = $today->diff($birthdate)->y;

                $teachers[] = [
                        'firstname' => $row['firstname'],
                        'lastname' => $row['lastname'],
                        'fullname' => $row['firstname'] . ' ' . $row['lastname'],
                        'email' => $row['email'],
                        'profileimage' => $row['profileimage'] ?: 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png',
                        'gender' => $row['gender'],
                        'age' => $age,
                        'birthdate' => $row['birthdate'],
                        'job_title' => $row['job_title'],
                        'salary' => number_format($row['salary'], 2),
                        'salary_raw' => $row['salary'],
                        'course_count' => $row['course_count'],
                        'courses' => $courses
                ];
            }
        }

        echo json_encode([
                'success' => true,
                'teachers' => $teachers
        ]);
        exit;

    } elseif ($_POST['action'] === 'get_all_courses_names') {
        $query = "SELECT DISTINCT name FROM courses ORDER BY name";
        $result = $conn->query($query);
        $courses = [];

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $courses[] = $row['name'];
            }
        }

        echo json_encode([
                'success' => true,
                'courses' => $courses
        ]);
        exit;

    } elseif ($_POST['action'] === 'filter_teachers_by_course') {
        $course_name = isset($_POST['course_name']) ? $_POST['course_name'] : '';

        if ($course_name === 'all' || empty($course_name)) {
            $query = "SELECT 
                        u.firstname, 
                        u.lastname, 
                        u.email, 
                        u.profileimage,
                        u.gender,
                        u.birthdate,
                        t.job_title, 
                        t.salary,
                        (SELECT COUNT(DISTINCT c.id) 
                         FROM courses c 
                         WHERE c.teacher = u.email) as course_count
                      FROM users u
                      INNER JOIN teachers t ON u.email = t.email
                      ORDER BY u.firstname, u.lastname";

            $result = $conn->query($query);
        } else {
            $query = "SELECT 
                        u.firstname, 
                        u.lastname, 
                        u.email, 
                        u.profileimage,
                        u.gender,
                        u.birthdate,
                        t.job_title, 
                        t.salary,
                        (SELECT COUNT(DISTINCT c.id) 
                         FROM courses c 
                         WHERE c.teacher= u.email) as course_count
                      FROM users u
                      INNER JOIN teachers t ON u.email = t.email
                      WHERE u.email IN (
                          SELECT DISTINCT teacher
                          FROM courses 
                          WHERE name = ?
                      )
                      ORDER BY u.firstname, u.lastname";

            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $course_name);
            $stmt->execute();
            $result = $stmt->get_result();
        }

        $teachers = [];

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $courses_query = $conn->prepare("
                    SELECT name 
                    FROM courses 
                    WHERE teacher= ? 
                    ORDER BY name
                ");
                $courses_query->bind_param("s", $row['email']);
                $courses_query->execute();
                $courses_result = $courses_query->get_result();

                $courses = [];
                while ($course = $courses_result->fetch_assoc()) {
                    $courses[] = $course['name'];
                }

                $birthdate = new DateTime($row['birthdate']);
                $today = new DateTime();
                $age = $today->diff($birthdate)->y;

                $teachers[] = [
                        'firstname' => $row['firstname'],
                        'lastname' => $row['lastname'],
                        'fullname' => $row['firstname'] . ' ' . $row['lastname'],
                        'email' => $row['email'],
                        'profileimage' => $row['profileimage'] ?: 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png',
                        'gender' => $row['gender'],
                        'age' => $age,
                        'birthdate' => $row['birthdate'],
                        'job_title' => $row['job_title'],
                        'salary' => number_format($row['salary'], 2),
                        'salary_raw' => $row['salary'],
                        'course_count' => $row['course_count'],
                        'courses' => $courses
                ];
            }
        }

        echo json_encode([
                'success' => true,
                'teachers' => $teachers
        ]);
        exit;

    } elseif ($_POST['action'] === 'get_students') {
        $query = "SELECT
                    u.firstname,
                    u.lastname,
                    u.email,
                    u.profileimage,
                    u.gender,
                    u.birthdate,
                    s.balance,
                    (SELECT COUNT(DISTINCT sc.course_id)
                     FROM study sc
                     WHERE sc.student_email = u.email) as course_count
                  FROM users u
                  INNER JOIN students s ON u.email = s.email
                  ORDER BY u.firstname, u.lastname";

        $result = $conn->query($query);
        $students = [];

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $courses_query = $conn->prepare("
                    SELECT c.name
                    FROM study sc
                    INNER JOIN courses c ON sc.course_id = c.id
                    WHERE sc.student_email = ?
                    ORDER BY c.name
                ");
                $courses_query->bind_param("s", $row['email']);
                $courses_query->execute();
                $courses_result = $courses_query->get_result();

                $courses = [];
                while ($course = $courses_result->fetch_assoc()) {
                    $courses[] = $course['name'];
                }

                $birthdate = new DateTime($row['birthdate']);
                $today = new DateTime();
                $age = $today->diff($birthdate)->y;

                $completed_query = $conn->prepare("
                    SELECT COUNT(*) as completed_count
                    FROM studentcoursestatus
                    WHERE student_email = ? AND status = 'Passed'
                ");
                $completed_query->bind_param("s", $row['email']);
                $completed_query->execute();
                $completed_result = $completed_query->get_result();
                $completed_row = $completed_result->fetch_assoc();

                $students[] = [
                        'firstname' => $row['firstname'],
                        'lastname' => $row['lastname'],
                        'fullname' => $row['firstname'] . ' ' . $row['lastname'],
                        'email' => $row['email'],
                        'profileimage' => $row['profileimage'] ?: 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png',
                        'gender' => $row['gender'],
                        'age' => $age,
                        'birthdate' => $row['birthdate'],
                        'balance' => number_format($row['balance'], 2),
                        'balance_raw' => $row['balance'],
                        'course_count' => $row['course_count'],
                        'completed_courses' => isset($completed_row['completed_count']) ? $completed_row['completed_count'] : 0,
                        'courses' => $courses
                ];
            }
        }

        echo json_encode([
                'success' => true,
                'students' => $students
        ]);
        exit;

    } elseif ($_POST['action'] === 'get_courses') {
        $query = "SELECT
                    c.id,
                    c.name,
                    c.image,
                    c.start_time,
                    c.end_time,
                    c.days,
                    c.teacher,
                    c.price,
                    c.max_students,
                    c.duration_number,
                    c.duration_unit,
                    c.description,
                    u.firstname as teacher_firstname,
                    u.lastname as teacher_lastname,
                    (SELECT COUNT(DISTINCT sc.student_email)
                     FROM study sc
                     WHERE sc.course_id = c.id) as student_count
                  FROM courses c
                  LEFT JOIN users u ON c.teacher = u.email
                  ORDER BY c.name";

        $result = $conn->query($query);
        $courses = [];
        $course_categories = [];

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $days_array = explode(',', $row['days']);
                $days_display = array_map('ucfirst', $days_array);

                $start_time = date('g:i A', strtotime($row['start_time']));
                $end_time = date('g:i A', strtotime($row['end_time']));

                $duration = '';
                if ($row['duration_number'] && $row['duration_unit']) {
                    $duration = $row['duration_number'] . ' ' . $row['duration_unit'];
                }

                $students_query = $conn->prepare("
                    SELECT sc.student_email, u.firstname, u.lastname
                    FROM study sc
                    INNER JOIN users u ON sc.student_email = u.email
                    WHERE sc.course_id = ?
                    ORDER BY u.firstname, u.lastname
                ");
                $students_query->bind_param("i", $row['id']);
                $students_query->execute();
                $students_result = $students_query->get_result();

                $students = [];
                while ($student = $students_result->fetch_assoc()) {
                    $students[] = [
                            'email' => $student['student_email'],
                            'name' => $student['firstname'] . ' ' . $student['lastname']
                    ];
                }

                $course_name = strtolower($row['name']);
                $category = 'other';

                if (strpos($course_name, 'web') !== false || strpos($course_name, 'html') !== false ||
                        strpos($course_name, 'css') !== false || strpos($course_name, 'javascript') !== false) {
                    $category = 'web';
                } elseif (strpos($course_name, 'python') !== false) {
                    $category = 'python';
                } elseif (strpos($course_name, 'java') !== false) {
                    $category = 'java';
                } elseif (strpos($course_name, 'c++') !== false || strpos($course_name, 'c#') !== false) {
                    $category = 'c';
                } elseif (strpos($course_name, 'database') !== false || strpos(strtolower($course_name), 'sql') !== false) {
                    $category = 'database';
                }

                if (!in_array($category, $course_categories)) {
                    $course_categories[] = $category;
                }

                $courses[] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'image' => $row['image'] ?: 'img/default-course.jpg',
                        'start_time' => $start_time,
                        'start_time_raw' => $row['start_time'],
                        'end_time' => $end_time,
                        'end_time_raw' => $row['end_time'],
                        'days' => $days_display,
                        'days_raw' => $row['days'],
                        'teacher' => $row['teacher'],
                        'teacher_name' => $row['teacher_firstname'] . ' ' . $row['teacher_lastname'],
                        'price' => number_format($row['price'], 2),
                        'price_raw' => $row['price'],
                        'max_students' => $row['max_students'],
                        'student_count' => $row['student_count'],
                        'duration' => $duration,
                        'duration_number' => $row['duration_number'],
                        'duration_unit' => $row['duration_unit'],
                        'description' => $row['description'] ?: 'No description available',
                        'category' => $category,
                        'students' => $students
                ];
            }
        }

        echo json_encode([
                'success' => true,
                'courses' => $courses,
                'categories' => $course_categories
        ]);
        exit;
    }
}

$conn->close();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Programming Courses</title>
    <link rel="stylesheet" href="css/footor.css">
    <link rel="stylesheet" href="css/list.css">
    <link rel="stylesheet" href="css/head.css">
    <link rel="icon" type="image/png" href="img/icon.png">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>

        .card-actions {
            position: absolute;
            top: 15px;
            right: 15px;
            display: flex;
            gap: 8px;
            z-index: 10;
        }

        .action-btn {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .notify-btn {
            background: #27ae60;
            color: white;
        }

        .notify-btn:hover {
            background: #229954;
            transform: scale(1.1);
        }

        .edit-btn {
            background: #3498db;
            color: white;
        }

        .edit-btn:hover {
            background: #2980b9;
            transform: scale(1.1);
        }

        .delete-btn {
            background: #e74c3c;
            color: white;
        }

        .delete-btn:hover {
            background: #c0392b;
            transform: scale(1.1);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
            overflow-y: auto;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 800px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            animation: slideDown 0.3s;
        }

        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            padding: 20px 30px;
            background: linear-gradient(135deg, #665788, #49386e);
            color: white;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s;
        }

        .close:hover {
            color: #ddd;
        }

        .modal-body {
            padding: 30px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #665788;
        }

        .form-group input:disabled {
            background-color: #f5f5f5;
            cursor: not-allowed;
        }

        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #665788, #49386e);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 87, 136, 0.3);
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .confirm-dialog {
            text-align: center;
        }

        .confirm-dialog i {
            font-size: 60px;
            color: #e74c3c;
            margin-bottom: 20px;
        }

        .confirm-dialog h3 {
            margin-bottom: 15px;
            color: #333;
        }

        .confirm-dialog p {
            color: #666;
            margin-bottom: 30px;
        }

        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: white;
            padding: 20px 25px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            display: none;
            align-items: center;
            gap: 15px;
            z-index: 2000;
            animation: slideUp 0.3s;
        }

        @keyframes slideUp {
            from { transform: translateY(100px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .toast.success {
            border-left: 4px solid #27ae60;
        }

        .toast.error {
            border-left: 4px solid #e74c3c;
        }

        .toast i {
            font-size: 24px;
        }

        .toast.success i {
            color: #27ae60;
        }

        .toast.error i {
            color: #e74c3c;
        }

        .days-checkboxes {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .day-checkbox {
            display: flex;
            align-items: center;
            background: #f8f9fa;
            padding: 8px 15px;
            border-radius: 20px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .day-checkbox:hover {
            background: #e9ecef;
        }

        .day-checkbox input {
            margin-right: 8px;
            cursor: pointer;
        }

        .teacher-card, .student-card, .course-card {
            position: relative;
        }

        .course-item {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .course-item:hover {
            border-color: #665788;
            box-shadow: 0 5px 15px rgba(102, 87, 136, 0.2);
            transform: translateY(-2px);
        }

        .course-item.selected {
            border-color: #27ae60;
            background-color: #e8f5e9;
        }

        .course-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .course-item-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }

        .course-item-icon {
            color: #665788;
            font-size: 1.3rem;
        }

        .course-item-teacher {
            color: #666;
            font-size: 0.9rem;
        }

        .no-courses-message {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .no-courses-message i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 15px;
        }

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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            min-height: 100vh;
            background: #f8f9fa;
        }

        .main-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            max-width: 1400px;
            margin-left: auto;
            margin-right: auto;
            gap: 20px;
            padding: 0 20px;
            flex: 1;
            width: 100%;
            border-radius: 25px;
        }

        .content {
            padding: 30px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            width: 100%;
            min-height: 600px;
            margin: 20px auto;
        }

        .content-title {
            color: #675788;
            margin-bottom: 25px;
            font-size: 2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
        }

        .content-title i {
            margin-right: 15px;
            color: #675788;
        }

        .course-filter {
            margin-bottom: 30px;
            padding: 25px;
            background: rgba(139, 135, 158, 0.18);
            border-radius: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }

        .filter-title {
            margin-bottom: 20px;
            color: #675788;
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .filter-title i {
            margin-right: 10px;
            color: #675788;
        }

        .courses-checkboxes {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .course-checkbox {
            display: flex;
            align-items: center;
            background: #f8f9fa;
            padding: 12px 20px;
            border-radius: 25px;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            cursor: pointer;
        }

        .course-checkbox:hover {
            background: #f0edf5;
            border-color: #675788;
            transform: translateY(-2px);
        }

        .course-checkbox input {
            margin-right: 10px;
            transform: scale(1.2);
            accent-color: #675788;
            cursor: pointer;
        }

        .course-checkbox label {
            cursor: pointer;
            font-weight: 500;
            color: #555;
            user-select: none;
        }

        .teachers-list, .students-list, .courses-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
        }

        .teacher-card, .student-card, .course-card {
            background: rgba(139, 135, 158, 0.18);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .teacher-card::before, .student-card::before, .course-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(to right, #675788, #49386e);
        }

        .teacher-card:hover, .student-card:hover, .course-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            border: 2px solid #49386e;
        }

        .teacher-image, .student-image {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 20px;
            border: 4px solid #f8f9fa;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .course-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #665788, #49386e);
            color: white;
            font-size: 2rem;
            box-shadow: 0 5px 15px rgba(102, 87, 136, 0.3);
        }

        .teacher-name, .student-name, .course-name {
            font-size: 1.4rem;
            color: #333;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .teacher-specialty, .student-level, .course-category {
            color: #7f8c8d;
            font-size: 1rem;
            margin-bottom: 15px;
            font-weight: 500;
        }

        .teacher-courses, .student-courses, .course-details {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
            margin-top: 15px;
        }

        .course-tag, .level-tag {
            background: linear-gradient(135deg, #665788, #49386e);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .course-info {
            display: flex;
            justify-content: space-between;
            width: 100%;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #f1f2f6;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }

        .info-label {
            font-size: 0.8rem;
            color: #7f8c8d;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #49386e;
        }

        .no-results {
            text-align: center;
            padding: 60px 40px;
            color: #7f8c8d;
            font-size: 1.2rem;
            grid-column: 1 / -1;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .hidden {
            display: none;
        }

        .filter-message {
            margin-top: 10px;
            color: #666;
            font-size: 0.9rem;
            font-style: italic;
        }

        @media (max-width: 1024px) {
            .main-container {
                display: block;
            }
        }

        .reassign-course-item {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .reassign-course-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .reassign-course-name {
            font-weight: 600;
            color: #333;
        }

        .reassign-course-select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }

        .students-list-item {
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .student-info {
            display: flex;
            flex-direction: column;
        }

        .student-name {
            font-weight: 600;
            color: #333;
            font-size: 1rem;
        }

        .student-email {
            color: #666;
            font-size: 0.85rem;
        }

        .course-price {
            font-size: 1.2rem;
            font-weight: bold;
            color: #27ae60;
            margin: 10px 0;
        }

        .course-student-count {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 15px;
        }

        .refund-info {
            background-color: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
        }

        .refund-amount {
            color: #2e7d32;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .student-balance-change {
            font-size: 0.8rem;
            color: #666;
            margin-top: 3px;
        }

        /* Student Enrollments Styles */
        .students-courses-container {
            display: flex;
            gap: 30px;
        }

        .students-list-container {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }

        .courses-details-container {
            flex: 2;
            background: rgba(139, 135, 158, 0.1);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .student-enrollment-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            text-align: center;
            cursor: pointer;
            border: 2px solid transparent;
        }

        .student-enrollment-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            border-color: #49386e;
        }

        .student-enrollment-card.selected {
            border-color: #27ae60;
            background-color: #e8f5e9;
        }

        .student-enrollment-card img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 15px;
            border: 3px solid #f8f9fa;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .student-enrollment-card h3 {
            font-size: 1.2rem;
            color: #333;
            margin-bottom: 5px;
        }

        .student-enrollment-card p {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 15px;
        }

        .course-enrollment-item {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .course-enrollment-item:hover {
            border-color: #665788;
            box-shadow: 0 5px 15px rgba(102, 87, 136, 0.2);
        }

        .course-enrollment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .course-enrollment-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
        }

        .course-enrollment-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }

        .enrollment-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }

        .enrollment-stat-label {
            font-size: 0.8rem;
            color: #7f8c8d;
            margin-bottom: 5px;
        }

        .enrollment-stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #49386e;
        }

        .enrollment-stat-value.progress {
            color: #27ae60;
        }

        .enrollment-stat-value.absences {
            color: #e74c3c;
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background-color: #ecf0f1;
            border-radius: 5px;
            overflow: hidden;
            margin: 10px 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #27ae60, #2ecc71);
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .remove-enrollment-btn {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }

        .remove-enrollment-btn:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

    .search-box-container {
            margin: 20px 0;
            position: relative;
        }

        .search-box {
            position: relative;
            max-width: 600px;
            margin: 0 auto;
        }
        /* تنسيقات Student Enrollments */
        .student-enrollment-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            text-align: center;
            transition: all 0.3s;
            background-color: white;
        }

        .student-enrollment-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .student-enrollment-card.selected {
            border-color: #49386e;
            background-color: #f0edf5;
        }

        .student-enrollment-card img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 10px;
            border: 3px solid #eee;
        }

        .student-enrollment-card h3 {
            margin: 10px 0 5px 0;
            color: #333;
            font-size: 18px;
        }

        .student-enrollment-card p {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
        }

        .student-enrollment-card .btn {
            width: 100%;
            padding: 8px 15px;
            background-color: #49386e;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }

        .student-enrollment-card .btn:hover {
            background-color: #3a2d56;
        }

        /* تنسيقات الدورات في Student Enrollments */
        .course-enrollment-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background-color: white;
        }

        .course-enrollment-header {
            margin-bottom: 10px;
        }

        .course-enrollment-name {
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }

        .course-enrollment-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }

        .enrollment-stat {
            text-align: center;
        }

        .enrollment-stat-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }

        .enrollment-stat-value {
            font-size: 18px;
            font-weight: bold;
        }

        .enrollment-stat-value.progress {
            color: #27ae60;
        }

        .enrollment-stat-value.absences {
            color: #e74c3c;
        }

        .progress-bar {
            height: 8px;
            background-color: #eee;
            border-radius: 4px;
            margin-bottom: 15px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background-color: #49386e;
            border-radius: 4px;
            transition: width 0.3s;
        }

        .remove-enrollment-btn {
            width: 100%;
            padding: 8px 15px;
            background-color: #e74c3c;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }

        .remove-enrollment-btn:hover {
            background-color: #c0392b;
        }

        /* تنسيقات search box */
        .search-box-container {
            margin: 20px 0;
            position: relative;
        }

        .search-box {
            position: relative;
            max-width: 600px;
            margin: 0 auto;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            z-index: 1;
        }

        .search-box input {
            width: 100%;
            padding: 12px 20px 12px 45px;
            border: 2px solid #ddd;
            border-radius: 30px;
            font-size: 16px;
            transition: all 0.3s;
            background-color: white;
        }

        .search-box input:focus {
            outline: none;
            border-color: #49386e;
            box-shadow: 0 0 10px rgba(73, 56, 110, 0.2);
        }
        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            z-index: 1;
        }

        .search-box input {
            width: 100%;
            padding: 12px 20px 12px 45px;
            border: 2px solid #ddd;
            border-radius: 30px;
            font-size: 16px;
            transition: all 0.3s;
            background-color: white;
        }

        .search-box input:focus {
            outline: none;
            border-color: #49386e;
            box-shadow: 0 0 10px rgba(73, 56, 110, 0.2);
        }

        .notification-icon-large {
            font-size: 48px;
            color: #49386e;
            margin-bottom: 15px;
        }

        .courses-list-notification {
            max-height: 300px;
            overflow-y: auto;
            margin: 20px 0;
        }

        .course-notification-item {
            margin-bottom: 10px;
        }

        .course-notification-card {
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .course-notification-card:hover {
            border-color: #49386e;
            background-color: #f9f9f9;
        }

        .course-notification-card.selected {
            border-color: #49386e;
            background-color: #f0edf5;
        }

        .course-notification-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }

        .course-notification-header i {
            color: #49386e;
        }

        .course-notification-name {
            font-weight: bold;
            font-size: 16px;
            color: #333;
        }

        .course-notification-teacher {
            font-size: 14px;
            color: #666;
            margin-left: 25px;
        }

        textarea#notificationText {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            resize: vertical;
        }

        textarea#notificationText:focus {
            outline: none;
            border-color: #49386e;
        }
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
                        window.location.href = "ManagerHome.php";
                    });
                </script>
                <div class="logo-text">
                    <span class="logo-main">EDVORA</span>
                    <span class="logo-sub">EDUCATIONAL ACADEMY</span>
                </div>
            </div>
        </div>

        <nav class="main-menu">
            <a href="ManagerHome.php">Home</a>
            <a href="creat_TSC.php">Create</a>
            <a href="show_TSC.php" class="active">Show</a>
        </nav>

        <div class="right-section">
            <div class="notification-container">
                <div class="notification-icon">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread_notifications_count > 0): ?>
                        <div class="notification-badge"><?php echo $unread_notifications_count; ?></div>
                    <?php endif; ?>
                </div>
                <div class="notifications-dropdown">
                    <div class="notifications-header">
                        <h3>Notifications</h3>
                        <button class="mark-all-read" onclick="markAllNotificationsRead()">Mark all as read</button>
                    </div>
                    <div class="notifications-list" id="notificationsList">
                        <?php if (empty($notifications)): ?>
                            <div class="notification-item">
                                <div class="notification-content" style="text-align: center; color: #888; padding: 20px;">
                                    No notifications
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                                    <div class="notification-icon-small
                                    <?php
                                    if (strpos(strtolower($notification['text']), 'submitted') !== false) echo 'success';
                                    elseif (strpos(strtolower($notification['text']), 'deadline') !== false) echo 'warning';
                                    else echo 'info';
                                    ?>">
                                        <i class="fas
                                        <?php
                                        if (strpos(strtolower($notification['text']), 'submitted') !== false) echo 'fa-check-circle';
                                        elseif (strpos(strtolower($notification['text']), 'deadline') !== false) echo 'fa-exclamation-triangle';
                                        else echo 'fa-book';
                                        ?>">
                                        </i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">
                                            <?php
                                            if (strpos(strtolower($notification['text']), 'assignment') !== false) echo 'Assignment';
                                            elseif (strpos(strtolower($notification['text']), 'submitted') !== false) echo 'Assignment Submitted';
                                            elseif (strpos(strtolower($notification['text']), 'deadline') !== false) echo 'Deadline Reminder';
                                            else echo 'Notification';
                                            ?>
                                        </div>
                                        <div class="notification-message"><?php echo htmlspecialchars($notification['text']); ?></div>
                                        <div class="notification-time"><?php echo $notification['time_ago']; ?></div>
                                    </div>
                                    <?php if (!$notification['is_read']): ?>
                                        <div class="notification-dot"></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <button class="user-btn" id="mybutton">
                <img src="<?php echo htmlspecialchars($user_info['profileimage']); ?>" alt="User">
                <span class="username">
                    <?php echo htmlspecialchars($user_info['firstname'] . ' ' . $user_info['lastname']); ?>
                </span>
            </button>
            <script>
                document.getElementById("mybutton").addEventListener("click", function() {
                    window.location.href = "ManagerProfile.php";
                });
            </script>

            <button class="logout" id="mybutton1">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
            <script>
                document.getElementById("mybutton1").addEventListener("click", function() {
                    window.location.href = "logout.php";
                });
            </script>
        </div>
    </div>
</header>

<div class="main-container">
    <!-- القائمة الجانبية -->
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-users"></i>
            <div class="sidebar-title">Show</div>
        </div>
        <ul class="sidebar-menu">
            <div class="menu-section">
                <li class="menu-item active" data-target="teachers">
                    <i class="fas fa-chalkboard-teacher"></i> Show Teacher
                </li>
                <li class="menu-item" data-target="students">
                    <i class="fas fa-user-graduate"></i> Show Student
                </li>
                <li class="menu-item" data-target="courses">
                    <i class="fas fa-book"></i> Show Course
                </li>
                <li class="menu-item" data-target="student-courses">
                    <i class="fas fa-book-reader"></i> Student Enrollments
                </li>
            </div>
        </ul>
    </div>

    <div class="content">
        <!-- قسم عرض المعلمين -->
        <div id="teachers" class="content-section">
            <h2 class="content-title">
                <i class="fas fa-chalkboard-teacher"></i> All Teachers
            </h2>

            <!-- إضافة خانة البحث للمعلمين -->
            <div class="search-box-container">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchTeacherInput" placeholder="Search teachers by name, email, or job title..."
                           onkeyup="searchTeachers()">
                </div>
            </div>

            <!-- فلتر الدورات للمعلمين -->
            <div class="course-filter">
                <div class="filter-title">
                    <i class="fas fa-filter"></i> Filter by Course
                </div>
                <div class="courses-checkboxes" id="coursesFilter">
                    <!-- سيتم ملؤها بالجافاسكريبت -->
                </div>
                <p class="filter-message" id="filterMessage">Loading courses...</p>
            </div>

            <!-- قائمة المعلمين -->
            <div class="teachers-list" id="teachersList">
                <div class="no-results">
                    <i class="fas fa-spinner fa-spin"></i>
                    <h3>Loading teachers...</h3>
                </div>
            </div>
        </div>

        <!-- قسم عرض الطلاب -->
        <div id="students" class="content-section hidden">
            <h2 class="content-title">
                <i class="fas fa-user-graduate"></i> All Students
            </h2>

            <!-- إضافة خانة البحث للطلاب -->
            <div class="search-box-container">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchStudentInput" placeholder="Search students by name, email, or courses..."
                           onkeyup="searchStudents()">
                </div>
            </div>

            <div class="students-list" id="studentsList">
                <div class="no-results">
                    <i class="fas fa-spinner fa-spin"></i>
                    <h3>Loading students...</h3>
                </div>
            </div>
        </div>

        <!-- قسم عرض الدورات -->
        <div id="courses" class="content-section hidden">
            <h2 class="content-title">
                <i class="fas fa-book"></i> All Courses
            </h2>

            <!-- إضافة خانة البحث للدورات -->
            <div class="search-box-container">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchCourseInput" placeholder="Search courses by name, teacher, or description..."
                           onkeyup="searchCourses()">
                </div>
            </div>

            <div class="courses-list" id="coursesList">
                <div class="no-results">
                    <i class="fas fa-spinner fa-spin"></i>
                    <h3>Loading courses...</h3>
                </div>
            </div>
        </div>

        <!-- قسم Student Enrollments -->
        <!-- قسم Student Enrollments -->
        <div id="student-courses" class="content-section hidden">
            <h2 class="content-title">
                <i class="fas fa-book-reader"></i> Student Enrollments
            </h2>

            <div class="search-box-container">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchEnrollmentInput" placeholder="Search enrolled students by name or email..."
                           onkeyup="searchEnrollments()">
                </div>
            </div>

            <div class="students-courses-container">
                <div class="students-list-container" id="studentsListContainer">
                    <!-- قائمة الطلاب المسجلين ستظهر هنا -->
                </div>

                <div class="courses-details-container" id="coursesDetailsContainer" style="display: none;">
                    <!-- تفاصيل دورات الطالب -->
                </div>
            </div>
        </div>
        <!-- Edit Teacher Modal -->
        <div id="editTeacherModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-edit"></i> Edit Teacher</h2>
                    <span class="close" onclick="closeModal('editTeacherModal')">&times;</span>
                </div>

                <div class="modal-body">
                    <form id="editTeacherForm">
                        <input type="hidden" id="teacher_email" name="email">
                        <div class="form-group">
                            <label>Email (Cannot be changed)</label>
                            <input type="email" id="teacher_email_display" disabled>
                        </div>
                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" id="teacher_firstname" name="firstname" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" id="teacher_lastname" name="lastname" required>
                        </div>
                        <div class="form-group">
                            <label>Birth Date</label>
                            <input type="date" id="teacher_birthdate" name="birthdate" required>
                        </div>
                        <div class="form-group">
                            <label>Gender</label>
                            <select id="teacher_gender" name="gender" required>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Job Title</label>
                            <input type="text" id="teacher_job_title" name="job_title" required>
                        </div>
                        <div class="form-group">
                            <label>Salary</label>
                            <input type="number" step="0.01" id="teacher_salary" name="salary" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal('editTeacherModal')">Cancel</button>
                    <button class="btn btn-primary" onclick="updateTeacher()">Save Changes</button>
                </div>
            </div>
        </div>

        <!-- Edit Student Modal -->
        <div id="editStudentModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-edit"></i> Edit Student</h2>
                    <span class="close" onclick="closeModal('editStudentModal')">&times;</span>
                </div>
                <div class="modal-body">
                    <form id="editStudentForm">
                        <input type="hidden" id="student_email" name="email">
                        <div class="form-group">
                            <label>Email (Cannot be changed)</label>
                            <input type="email" id="student_email_display" disabled>
                        </div>
                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" id="student_firstname" name="firstname" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" id="student_lastname" name="lastname" required>
                        </div>
                        <div class="form-group">
                            <label>Birth Date</label>
                            <input type="date" id="student_birthdate" name="birthdate" required>
                        </div>
                        <div class="form-group">
                            <label>Gender</label>
                            <select id="student_gender" name="gender" required>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Balance</label>
                            <input type="number" id="student_balance" name="balance" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal('editStudentModal')">Cancel</button>
                    <button class="btn btn-primary" onclick="updateStudent()">Save Changes</button>
                </div>
            </div>
        </div>

        <!-- Edit Course Modal -->
        <div id="editCourseModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-edit"></i> Edit Course</h2>
                    <span class="close" onclick="closeModal('editCourseModal')">&times;</span>
                </div>
                <div class="modal-body">
                    <form id="editCourseForm">
                        <input type="hidden" id="course_id" name="course_id">
                        <div class="form-group">
                            <label>Course Name</label>
                            <input type="text" id="course_name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label>Start Time</label>
                            <input type="time" id="course_start_time" name="start_time" required>
                        </div>
                        <div class="form-group">
                            <label>End Time</label>
                            <input type="time" id="course_end_time" name="end_time" required>
                        </div>
                        <div class="form-group">
                            <label>Days</label>
                            <div class="days-checkboxes" id="course_days">
                                <label class="day-checkbox">
                                    <input type="checkbox" name="days[]" value="sunday"> Sunday
                                </label>
                                <label class="day-checkbox">
                                    <input type="checkbox" name="days[]" value="monday"> Monday
                                </label>
                                <label class="day-checkbox">
                                    <input type="checkbox" name="days[]" value="tuesday"> Tuesday
                                </label>
                                <label class="day-checkbox">
                                    <input type="checkbox" name="days[]" value="wednesday"> Wednesday
                                </label>
                                <label class="day-checkbox">
                                    <input type="checkbox" name="days[]" value="thursday"> Thursday
                                </label>
                                <label class="day-checkbox">
                                    <input type="checkbox" name="days[]" value="friday"> Friday
                                </label>
                                <label class="day-checkbox">
                                    <input type="checkbox" name="days[]" value="saturday"> Saturday
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Teacher</label>
                            <select id="course_teacher" name="teacher" required>
                                <option value="">Select Teacher</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Price</label>
                            <input type="number" step="0.01" id="course_price" name="price" required>
                        </div>
                        <div class="form-group">
                            <label>Max Students</label>
                            <input type="number" id="course_max_students" name="max_students" required>
                        </div>
                        <div class="form-group">
                            <label>Duration Number</label>
                            <input type="number" id="course_duration_number" name="duration_number">
                        </div>
                        <div class="form-group">
                            <label>Duration Unit</label>
                            <select id="course_duration_unit" name="duration_unit">
                                <option value="">Select Unit</option>
                                <option value="weeks">Weeks</option>
                                <option value="months">Months</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea id="course_description" name="description" rows="4"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal('editCourseModal')">Cancel</button>
                    <button class="btn btn-primary" onclick="updateCourse()">Save Changes</button>
                </div>
            </div>
        </div>

        <!-- Student Courses Modal جديد -->
        <div id="courseNotificationModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-bell"></i> Send Course Notification</h2>
                    <span class="close" onclick="closeModal('courseNotificationModal')">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="confirm-dialog">
                        <i class="fas fa-bell notification-icon-large"></i>
                        <h3 id="courseNotificationTitle">Send Notification to Student</h3>
                        <p id="courseNotificationMessage">Select a course to send notification to the student</p>

                        <div id="courseNotificationList" class="courses-list-notification">
                            <!-- قائمة الدورات ستظهر هنا -->
                        </div>

                        <div id="noCoursesNotificationMessage" class="no-courses-message" style="display: none;">
                            <i class="fas fa-book"></i>
                            <p>This student is not enrolled in any courses.</p>
                        </div>

                        <div class="notification-message-box" id="notificationMessageBox" style="margin-top: 20px; display: none;">
                            <label for="notificationText">Custom Message (Optional):</label>
                            <textarea id="notificationText" rows="3" placeholder="You are encouraged to complete your lessons for the selected course..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal('courseNotificationModal')">Cancel</button>
                    <button class="btn btn-primary" id="sendNotificationBtn" onclick="sendCourseNotification()" disabled>
                        <i class="fas fa-paper-plane"></i> Send Notification
                    </button>
                </div>
            </div>
        </div>

        <!-- Teacher Delete Confirmation Modal -->
        <div id="teacherDeleteConfirmModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-exclamation-triangle"></i> Confirm Teacher Deletion</h2>
                    <span class="close" onclick="closeModal('teacherDeleteConfirmModal')">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="confirm-dialog">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Are you sure you want to delete this teacher?</h3>
                        <p id="teacherDeleteConfirmMessage">This action cannot be undone. You will need to reassign their courses to other teachers.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal('teacherDeleteConfirmModal')">Cancel</button>
                    <button class="btn btn-danger" onclick="showTeacherReassignModal()">Continue to Reassign</button>
                </div>
            </div>
        </div>

        <!-- Teacher Reassign Modal -->
        <div id="teacherReassignModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-exchange-alt"></i> Reassign Teacher Courses</h2>
                    <span class="close" onclick="closeModal('teacherReassignModal')">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="confirm-dialog">
                        <i class="fas fa-exchange-alt"></i>
                        <h3 id="teacherReassignTitle">Reassign Courses</h3>
                        <p id="teacherReassignMessage">Please select a new teacher for each course currently taught by this teacher.</p>

                        <div id="teacherCoursesList">
                            <!-- سيتم ملؤها بالجافاسكريبت -->
                        </div>

                        <div id="noTeacherCoursesMessage" class="no-courses-message" style="display: none;">
                            <i class="fas fa-book"></i>
                            <p>This teacher has no courses to reassign.</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal('teacherReassignModal')">Cancel</button>
                    <button class="btn btn-primary" onclick="deleteTeacherWithReassign()">Delete Teacher and Reassign</button>
                </div>
            </div>
        </div>

        <!-- Course Delete Confirmation Modal -->
        <div id="courseDeleteConfirmModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-exclamation-triangle"></i> Confirm Course Deletion</h2>
                    <span class="close" onclick="closeModal('courseDeleteConfirmModal')">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="confirm-dialog">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Are you sure you want to delete this course?</h3>
                        <div id="courseDeleteDetails">
                            <!-- سيتم ملؤها بالجافاسكريبت -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal('courseDeleteConfirmModal')">Cancel</button>
                    <button class="btn btn-danger" onclick="confirmCourseDelete()">Delete Course</button>
                </div>
            </div>
        </div>

        <!-- Remove Student from Course Modal -->
        <div id="removeFromCourseModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-user-times"></i> Remove Student from Course</h2>
                    <span class="close" onclick="closeModal('removeFromCourseModal')">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="confirm-dialog">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3 id="removeCourseTitle">Are you sure?</h3>
                        <p id="removeCourseMessage"></p>
                        <div id="refundInfo" style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 8px; text-align: left;">
                            <p><strong>Note:</strong> The course fee will be refunded to the student's balance.</p>
                            <p id="coursePriceInfo" style="color: #27ae60; font-weight: bold; margin-top: 10px;"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal('removeFromCourseModal')">Cancel</button>
                    <button class="btn btn-danger" onclick="removeStudentFromCourse()">Remove Student</button>
                </div>
            </div>
        </div>

        <!-- Toast Notification -->
        <div id="toast" class="toast">
            <i class="fas fa-check-circle"></i>
            <span id="toastMessage"></span>
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
    let currentStudentEmail = '';
    let currentStudentName = '';
    let currentTeacherEmail = '';
    let currentTeacherName = '';
    let currentCourseId = '';
    let currentCourseName = '';
    let allTeachers = [];
    let currentStudentForRemoval = '';
    let currentCourseForRemoval = '';
    let currentStudentNameForRemoval = '';
    let currentCourseNameForRemoval = '';
    let currentCoursePriceForRemoval = 0;

    // متغيرات جديدة للميزة الأولى
    let currentNotificationStudent = { email: '', name: '' };
    let selectedCourseForNotification = null;

    function escapeHTML(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // دوال البحث المضافة
    function searchTeachers() {
        const searchTerm = document.getElementById('searchTeacherInput').value.toLowerCase();
        const teacherCards = document.querySelectorAll('#teachersList .teacher-card');

        teacherCards.forEach(card => {
            const name = card.querySelector('.teacher-name').textContent.toLowerCase();
            const email = card.querySelector('.teacher-details p:nth-child(1)').textContent.toLowerCase();
            const jobTitle = card.querySelector('.teacher-specialty').textContent.toLowerCase();
            const courses = Array.from(card.querySelectorAll('.course-tag')).map(tag => tag.textContent.toLowerCase());

            const matches = name.includes(searchTerm) ||
                email.includes(searchTerm) ||
                jobTitle.includes(searchTerm) ||
                courses.some(course => course.includes(searchTerm));

            card.style.display = matches ? 'block' : 'none';
        });
    }

    function searchStudents() {
        const searchTerm = document.getElementById('searchStudentInput').value.toLowerCase();
        const studentCards = document.querySelectorAll('#studentsList .student-card');

        studentCards.forEach(card => {
            const name = card.querySelector('.student-name').textContent.toLowerCase();
            const email = card.querySelector('.student-details p:nth-child(1)').textContent.toLowerCase();
            const courses = Array.from(card.querySelectorAll('.course-tag')).map(tag => tag.textContent.toLowerCase());

            const matches = name.includes(searchTerm) ||
                email.includes(searchTerm) ||
                courses.some(course => course.includes(searchTerm));

            card.style.display = matches ? 'block' : 'none';
        });
    }

    function searchCourses() {
        const searchTerm = document.getElementById('searchCourseInput').value.toLowerCase();
        const courseCards = document.querySelectorAll('#coursesList .course-card');

        courseCards.forEach(card => {
            const name = card.querySelector('.course-name').textContent.toLowerCase();
            const teacher = card.querySelector('.course-category').textContent.toLowerCase();
            const description = card.querySelector('.course-description p').textContent.toLowerCase();

            const matches = name.includes(searchTerm) ||
                teacher.includes(searchTerm) ||
                description.includes(searchTerm);

            card.style.display = matches ? 'block' : 'none';
        });
    }

    // دالة البحث في Student Enrollments
    function searchEnrollments() {
        const searchTerm = document.getElementById('searchEnrollmentInput').value.toLowerCase();
        const enrollmentCards = document.querySelectorAll('#studentsListContainer .student-enrollment-card');

        if (searchTerm.trim() === '') {
            // إذا كان البحث فارغًا، عرض جميع الطلاب
            enrollmentCards.forEach(card => {
                card.style.display = 'block';
            });
            return;
        }

        enrollmentCards.forEach(card => {
            const name = card.querySelector('h3').textContent.toLowerCase();
            const email = card.querySelector('p').textContent.toLowerCase();

            const matches = name.includes(searchTerm) || email.includes(searchTerm);

            card.style.display = matches ? 'block' : 'none';
        });
    }

    // عند تحميل صفحة Student Enrollments، نقوم بتحميل الطلاب
    function loadStudentsForEnrollments() {
        const container = document.getElementById('studentsListContainer');
        container.innerHTML = '<div class="no-results"><i class="fas fa-spinner fa-spin"></i><h3>Loading students...</h3></div>';
        document.getElementById('coursesDetailsContainer').style.display = 'none';

        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_students_with_courses'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.students.length > 0) {
                    displayStudentsForEnrollments(data.students);
                } else {
                    container.innerHTML = '<div class="no-results"><i class="fas fa-user-graduate"></i><h3>No students with courses found</h3></div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                container.innerHTML = '<div class="no-results"><i class="fas fa-exclamation-circle"></i><h3>Error loading students</h3></div>';
            });
    }

    // عرض الطلاب في Student Enrollments
    function displayStudentsForEnrollments(students) {
        const container = document.getElementById('studentsListContainer');
        container.innerHTML = '';

        students.forEach(student => {
            const studentCard = document.createElement('div');
            studentCard.className = 'student-enrollment-card';
            studentCard.setAttribute('data-email', student.email);
            studentCard.setAttribute('data-name', `${student.firstname} ${student.lastname}`);

            studentCard.innerHTML = `
<img src="${escapeHTML(student.profileimage)}" alt="${escapeHTML(student.firstname)}">
<h3>${escapeHTML(student.firstname)} ${escapeHTML(student.lastname)}</h3>
<p>${escapeHTML(student.email)}</p>
<button class="btn btn-primary" onclick="loadStudentEnrollments('${escapeHTML(student.email)}', '${escapeHTML(student.firstname)} ${escapeHTML(student.lastname)}')">
    View Enrollments
</button>
`;

            container.appendChild(studentCard);
        });
    }

    // تحميل تفاصيل دورات الطالب
    function loadStudentEnrollments(email, name) {
        currentStudentForRemoval = email;
        currentStudentNameForRemoval = name;

        const container = document.getElementById('coursesDetailsContainer');
        container.style.display = 'block';
        container.innerHTML = '<div class="no-results"><i class="fas fa-spinner fa-spin"></i><h3>Loading enrollments...</h3></div>';

        // Deselect all student cards
        document.querySelectorAll('.student-enrollment-card').forEach(card => {
            card.classList.remove('selected');
        });

        // Select current student card
        const selectedCard = document.querySelector(`.student-enrollment-card[data-email="${email}"]`);
        if (selectedCard) {
            selectedCard.classList.add('selected');
        }

        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get_student_courses_with_details&student_email=${encodeURIComponent(email)}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.courses.length > 0) {
                    let coursesHTML = '';
                    data.courses.forEach(course => {
                        coursesHTML += `
<div class="course-enrollment-item">
    <div class="course-enrollment-header">
        <div class="course-enrollment-name">${escapeHTML(course.name)}</div>
    </div>
    <div class="course-enrollment-stats">
        <div class="enrollment-stat">
            <div class="enrollment-stat-label">Progress</div>
            <div class="enrollment-stat-value progress">${course.progress}%</div>
        </div>
        <div class="enrollment-stat">
            <div class="enrollment-stat-label">Absences</div>
            <div class="enrollment-stat-value absences">${course.absences}</div>
        </div>
    </div>
    <div class="progress-bar">
        <div class="progress-fill" style="width: ${course.progress}%"></div>
    </div>
    <button class="remove-enrollment-btn" onclick="confirmRemoveFromCourse('${escapeHTML(email)}', '${escapeHTML(name)}', ${course.id}, '${escapeHTML(course.name)}', ${course.price})">
        <i class="fas fa-user-times"></i> Remove from Course
    </button>
</div>
`;
                    });

                    container.innerHTML = `
<h3 style="color: #49386e; margin-bottom: 25px;">
    <i class="fas fa-user-graduate"></i> Enrollments for ${escapeHTML(name)}
</h3>
${coursesHTML}
<div style="margin-top: 30px;">
    <button class="btn btn-secondary" onclick="backToStudentsList()">
        <i class="fas fa-arrow-left"></i> Back to Students
    </button>
</div>
`;
                } else {
                    container.innerHTML = `
<h3 style="color: #49386e; margin-bottom: 25px;">
    <i class="fas fa-user-graduate"></i> Enrollments for ${escapeHTML(name)}
</h3>
<div class="no-results">
    <i class="fas fa-book"></i>
    <h3>No courses found for this student</h3>
</div>
<div style="margin-top: 30px;">
    <button class="btn btn-secondary" onclick="backToStudentsList()">
        <i class="fas fa-arrow-left"></i> Back to Students
    </button>
</div>
`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                container.innerHTML = `
<h3 style="color: #49386e; margin-bottom: 25px;">
    <i class="fas fa-user-graduate"></i> Enrollments for ${escapeHTML(name)}
</h3>
<div class="no-results">
    <i class="fas fa-exclamation-circle"></i>
    <h3>Error loading enrollments</h3>
</div>
<div style="margin-top: 30px;">
    <button class="btn btn-secondary" onclick="backToStudentsList()">
        <i class="fas fa-arrow-left"></i> Back to Students
    </button>
</div>
`;
            });
    }

    function backToStudentsList() {
        document.getElementById('coursesDetailsContainer').style.display = 'none';
        document.querySelectorAll('.student-enrollment-card').forEach(card => card.classList.remove('selected'));
    }
    // دوال جديدة للميزة الأولى (إرسال التنبيهات)
    function showStudentCourses(email, name) {
        currentNotificationStudent = { email, name };
        selectedCourseForNotification = null;

        const modal = document.getElementById('courseNotificationModal');
        const coursesList = document.getElementById('courseNotificationList');
        const noCoursesMessage = document.getElementById('noCoursesNotificationMessage');
        const title = document.getElementById('courseNotificationTitle');
        const message = document.getElementById('courseNotificationMessage');
        const sendBtn = document.getElementById('sendNotificationBtn');
        const messageBox = document.getElementById('notificationMessageBox');

        // تحديث النصوص
        title.textContent = `Send Notification to ${escapeHTML(name)}`;
        message.textContent = 'Select a course to send notification';

        // إعادة تعيين المحتوى
        coursesList.innerHTML = '<div class="no-courses-message"><i class="fas fa-spinner fa-spin"></i><p>Loading courses...</p></div>';
        noCoursesMessage.style.display = 'none';
        messageBox.style.display = 'none';
        sendBtn.disabled = true;

        // جلب دورات الطالب
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get_student_courses&email=${encodeURIComponent(email)}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.courses.length > 0) {
                    coursesList.innerHTML = '';
                    data.courses.forEach(course => {
                        const courseItem = document.createElement('div');
                        courseItem.className = 'course-notification-item';
                        courseItem.innerHTML = `
    <div class="course-notification-card" onclick="selectCourseForNotification(${course.id}, '${escapeHTML(course.name)}')">
        <div class="course-notification-header">
            <i class="fas fa-book"></i>
            <div class="course-notification-name">${escapeHTML(course.name)}</div>
        </div>
        <div class="course-notification-teacher">Teacher: ${escapeHTML(course.teacher)}</div>
    </div>
    `;
                        coursesList.appendChild(courseItem);
                    });
                } else {
                    coursesList.innerHTML = '';
                    noCoursesMessage.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                coursesList.innerHTML = '';
                noCoursesMessage.innerHTML = '<i class="fas fa-exclamation-circle"></i><p>Error loading courses</p>';
                noCoursesMessage.style.display = 'block';
            });

        openModal('courseNotificationModal');
    }

    function selectCourseForNotification(courseId, courseName) {
        selectedCourseForNotification = { id: courseId, name: courseName };

        // إزالة التحديد من جميع العناصر
        document.querySelectorAll('.course-notification-card').forEach(card => {
            card.classList.remove('selected');
        });

        // إضافة التحديد للعنصر المختار
        event.currentTarget.classList.add('selected');

        // تفعيل زر الإرسال وإظهار مربع الرسالة
        document.getElementById('sendNotificationBtn').disabled = false;
        document.getElementById('notificationMessageBox').style.display = 'block';

        // تحديث الرسالة الافتراضية
        document.getElementById('notificationText').value =
            `Dear student, we encourage you to continue your progress in the course "${courseName}". Your teacher is following your achievements. Keep up the good work!`;
    }

    function sendCourseNotification() {
        if (!selectedCourseForNotification || !currentNotificationStudent.email) {
            showToast('Please select a course first', 'error');
            return;
        }

        const customMessage = document.getElementById('notificationText').value.trim();
        const notificationMessage = customMessage ||
            `Your teacher is following your progress in the course "${selectedCourseForNotification.name}". We encourage you to complete all lessons and assignments.`;

        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=send_course_notification&student_email=${encodeURIComponent(currentNotificationStudent.email)}&course_name=${encodeURIComponent(selectedCourseForNotification.name)}&custom_message=${encodeURIComponent(notificationMessage)}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(`Notification sent to ${currentNotificationStudent.name} for course: ${selectedCourseForNotification.name}`);
                    closeModal('courseNotificationModal');

                    // إرسال إشعار إضافي للمدير
                    setTimeout(() => {
                        showToast(`Notification sent successfully to ${currentNotificationStudent.name}`, 'success');
                    }, 500);
                } else {
                    showToast('Failed to send notification', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error sending notification', 'error');
            });
    }

    // Student Enrollments Functions
    function loadStudentEnrollments(email, name) {
        currentStudentForRemoval = email;
        currentStudentNameForRemoval = name;

        const container = document.getElementById('coursesDetailsContainer');
        container.style.display = 'block';
        container.innerHTML = '<div class="no-results"><i class="fas fa-spinner fa-spin"></i><h3>Loading enrollments...</h3></div>';

        // Deselect all student cards
        document.querySelectorAll('.student-enrollment-card').forEach(card => {
            card.classList.remove('selected');
        });

        // Select current student card
        const selectedCard = document.querySelector(`.student-enrollment-card[data-email="${email}"]`);
        if (selectedCard) {
            selectedCard.classList.add('selected');
        }

        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get_student_courses_with_details&student_email=${encodeURIComponent(email)}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.courses.length > 0) {
                    let coursesHTML = '';
                    data.courses.forEach(course => {
                        coursesHTML += `
<div class="course-enrollment-item">
    <div class="course-enrollment-header">
        <div class="course-enrollment-name">${escapeHTML(course.name)}</div>
    </div>
    <div class="course-enrollment-stats">
        <div class="enrollment-stat">
            <div class="enrollment-stat-label">Progress</div>
            <div class="enrollment-stat-value progress">${course.progress}%</div>
        </div>
        <div class="enrollment-stat">
            <div class="enrollment-stat-label">Absences</div>
            <div class="enrollment-stat-value absences">${course.absences}</div>
        </div>
    </div>
    <div class="progress-bar">
        <div class="progress-fill" style="width: ${course.progress}%"></div>
    </div>
    <button class="remove-enrollment-btn" onclick="confirmRemoveFromCourse('${escapeHTML(email)}', '${escapeHTML(name)}', ${course.id}, '${escapeHTML(course.name)}', ${course.price})">
        <i class="fas fa-user-times"></i> Remove from Course
    </button>
</div>
`;
                    });

                    container.innerHTML = `
<h3 style="color: #49386e; margin-bottom: 25px;">
    <i class="fas fa-user-graduate"></i> Enrollments for ${escapeHTML(name)}
</h3>
${coursesHTML}
<div style="margin-top: 30px;">
    <button class="btn btn-secondary" onclick="backToStudentsList()">
        <i class="fas fa-arrow-left"></i> Back to Students
    </button>
</div>
`;
                } else {
                    container.innerHTML = `
<h3 style="color: #49386e; margin-bottom: 25px;">
    <i class="fas fa-user-graduate"></i> Enrollments for ${escapeHTML(name)}
</h3>
<div class="no-results">
    <i class="fas fa-book"></i>
    <h3>No courses found for this student</h3>
</div>
<div style="margin-top: 30px;">
    <button class="btn btn-secondary" onclick="backToStudentsList()">
        <i class="fas fa-arrow-left"></i> Back to Students
    </button>
</div>
`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                container.innerHTML = `
<h3 style="color: #49386e; margin-bottom: 25px;">
    <i class="fas fa-user-graduate"></i> Enrollments for ${escapeHTML(name)}
</h3>
<div class="no-results">
    <i class="fas fa-exclamation-circle"></i>
    <h3>Error loading enrollments</h3>
</div>
<div style="margin-top: 30px;">
    <button class="btn btn-secondary" onclick="backToStudentsList()">
        <i class="fas fa-arrow-left"></i> Back to Students
    </button>
</div>
`;
            });
    }

    function backToStudentsList() {
        document.getElementById('coursesDetailsContainer').style.display = 'none';
        document.querySelectorAll('.student-enrollment-card').forEach(card => card.classList.remove('selected'));
    }

    function confirmRemoveFromCourse(student_email, student_name, course_id, course_name, course_price) {
        currentStudentForRemoval = student_email;
        currentStudentNameForRemoval = student_name;
        currentCourseForRemoval = course_id;
        currentCourseNameForRemoval = course_name;
        currentCoursePriceForRemoval = course_price;

        document.getElementById('removeCourseTitle').textContent = `Remove ${escapeHTML(student_name)} from course?`;
        document.getElementById('removeCourseMessage').innerHTML = `
Are you sure you want to remove <strong>${escapeHTML(student_name)}</strong> from the course:
<strong>"${escapeHTML(course_name)}"</strong>?
`;
        document.getElementById('coursePriceInfo').textContent = `Course fee: $${course_price} will be refunded to the student's balance.`;

        openModal('removeFromCourseModal');
    }

    function removeStudentFromCourse() {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=remove_student_from_course&student_email=${encodeURIComponent(currentStudentForRemoval)}&course_id=${currentCourseForRemoval}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(`Student removed from course. Course fee of $${data.refunded} has been refunded.`);
                    closeModal('removeFromCourseModal');
                    // إعادة تحميل دورات الطالب
                    loadStudentEnrollments(currentStudentForRemoval, currentStudentNameForRemoval);
                } else {
                    showToast('Failed to remove student from course', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error removing student from course', 'error');
            });
    }

    // Modal Functions
    function openModal(modalId) {
        document.getElementById(modalId).style.display = 'block';
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    function showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        const toastMessage = document.getElementById('toastMessage');
        const icon = toast.querySelector('i');

        toastMessage.textContent = message;
        toast.className = `toast ${type}`;

        if (type === 'success') {
            icon.className = 'fas fa-check-circle';
        } else {
            icon.className = 'fas fa-exclamation-circle';
        }

        toast.style.display = 'flex';

        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }

    // Teacher Functions
    function editTeacher(email) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get_teacher_details&email=${encodeURIComponent(email)}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('teacher_email').value = data.data.email;
                    document.getElementById('teacher_email_display').value = data.data.email;
                    document.getElementById('teacher_firstname').value = data.data.firstname;
                    document.getElementById('teacher_lastname').value = data.data.lastname;
                    document.getElementById('teacher_birthdate').value = data.data.birthdate;
                    document.getElementById('teacher_gender').value = data.data.gender;
                    document.getElementById('teacher_job_title').value = data.data.job_title;
                    document.getElementById('teacher_salary').value = data.data.salary;
                    openModal('editTeacherModal');
                }
            });
    }

    function updateTeacher() {
        const formData = new FormData(document.getElementById('editTeacherForm'));
        formData.append('action', 'update_teacher');

        fetch('', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Teacher updated successfully!');
                    closeModal('editTeacherModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Failed to update teacher', 'error');
                }
            });
    }

    function confirmDeleteTeacher(email, name) {
        currentTeacherEmail = email;
        currentTeacherName = name;

        document.getElementById('teacherDeleteConfirmMessage').textContent =
            `Are you sure you want to delete teacher "${escapeHTML(name)}"? This action cannot be undone.`;
        openModal('teacherDeleteConfirmModal');
    }

    function showTeacherReassignModal() {
        closeModal('teacherDeleteConfirmModal');

        document.getElementById('teacherReassignTitle').textContent =
            `Reassign courses for teacher: ${escapeHTML(currentTeacherName)}`;
        document.getElementById('teacherReassignMessage').textContent =
            'Please select a new teacher for each course currently taught by this teacher.';

        const coursesList = document.getElementById('teacherCoursesList');
        const noCoursesMessage = document.getElementById('noTeacherCoursesMessage');

        coursesList.innerHTML = '<div class="no-courses-message"><i class="fas fa-spinner fa-spin"></i><p>Loading courses...</p></div>';
        noCoursesMessage.style.display = 'none';

        // تحميل قائمة المعلمين أولاً
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_all_teachers_list'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    allTeachers = data.teachers.filter(teacher => teacher.email !== currentTeacherEmail);

                    // تحميل مقررات المعلم
                    fetch('', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=get_teacher_courses&teacher_email=${encodeURIComponent(currentTeacherEmail)}`
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.courses.length > 0) {
                                coursesList.innerHTML = '';

                                data.courses.forEach(course => {
                                    const courseItem = document.createElement('div');
                                    courseItem.className = 'reassign-course-item';

                                    let teachersOptions = '<option value="">-- Select Teacher --</option>';
                                    allTeachers.forEach(teacher => {
                                        teachersOptions += `<option value="${escapeHTML(teacher.email)}">${escapeHTML(teacher.fullname)}</option>`;
                                    });

                                    courseItem.innerHTML = `
<div class="reassign-course-header">
    <div class="reassign-course-name">${escapeHTML(course.name)}</div>
</div>
<select class="reassign-course-select" data-course-id="${course.id}">
    ${teachersOptions}
</select>
`;

                                    coursesList.appendChild(courseItem);
                                });
                            } else {
                                coursesList.innerHTML = '';
                                noCoursesMessage.style.display = 'block';
                            }
                        })
                        .catch(error => {
                            console.error('Error loading courses:', error);
                            coursesList.innerHTML = '';
                            noCoursesMessage.style.display = 'block';
                        });
                }
            });

        openModal('teacherReassignModal');
    }

    function deleteTeacherWithReassign() {
        const reassignments = {};
        let allAssigned = true;
        const selectElements = document.querySelectorAll('.reassign-course-select');

        selectElements.forEach(select => {
            const courseId = select.getAttribute('data-course-id');
            const newTeacherEmail = select.value;

            if (!newTeacherEmail) {
                allAssigned = false;
                showToast(`Please select a teacher for course ${courseId}`, 'error');
            } else {
                reassignments[courseId] = newTeacherEmail;
            }
        });

        if (!allAssigned) return;

        // استخدام FormData بدلاً من JSON.stringify
        const formData = new FormData();
        formData.append('action', 'delete_teacher_with_reassign');
        formData.append('teacher_email', currentTeacherEmail);
        formData.append('reassignments', JSON.stringify(reassignments));

        fetch('', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Teacher deleted and courses reassigned successfully!');
                    closeModal('teacherReassignModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Failed to delete teacher: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error deleting teacher', 'error');
            });
    }

    // Student Functions
    function editStudent(email) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get_student_details&email=${encodeURIComponent(email)}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('student_email').value = data.data.email;
                    document.getElementById('student_email_display').value = data.data.email;
                    document.getElementById('student_firstname').value = data.data.firstname;
                    document.getElementById('student_lastname').value = data.data.lastname;
                    document.getElementById('student_birthdate').value = data.data.birthdate;
                    document.getElementById('student_gender').value = data.data.gender;
                    document.getElementById('student_balance').value = data.data.balance;
                    openModal('editStudentModal');
                }
            });
    }

    function updateStudent() {
        const formData = new FormData(document.getElementById('editStudentForm'));
        formData.append('action', 'update_student');

        fetch('', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Student updated successfully!');
                    closeModal('editStudentModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Failed to update student', 'error');
                }
            });
    }

    function confirmDeleteStudent(email, name) {
        currentStudentEmail = email;
        currentStudentName = name;

        document.getElementById('studentDeleteConfirmMessage').textContent =
            `Are you sure you want to delete student "${escapeHTML(name)}"?`;
        openModal('studentDeleteConfirmModal');
    }

    function confirmStudentDelete() {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=delete_student&email=${encodeURIComponent(currentStudentEmail)}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Student deleted successfully!');
                    closeModal('studentDeleteConfirmModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Failed to delete student', 'error');
                }
            });
    }

    // Course Functions
    function editCourse(courseId) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_all_teachers_list'
        })
            .then(response => response.json())
            .then(data => {
                const teacherSelect = document.getElementById('course_teacher');
                teacherSelect.innerHTML = '<option value="">Select Teacher</option>';
                data.teachers.forEach(teacher => {
                    teacherSelect.innerHTML += `<option value="${escapeHTML(teacher.email)}">${escapeHTML(teacher.fullname)}</option>`;
                });

                return fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=get_course_details&course_id=${courseId}`
                });
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('course_id').value = data.data.id;
                    document.getElementById('course_name').value = data.data.name;
                    document.getElementById('course_start_time').value = data.data.start_time;
                    document.getElementById('course_end_time').value = data.data.end_time;
                    document.getElementById('course_teacher').value = data.data.teacher;
                    document.getElementById('course_price').value = data.data.price;
                    document.getElementById('course_max_students').value = data.data.max_students;
                    document.getElementById('course_duration_number').value = data.data.duration_number || '';
                    document.getElementById('course_duration_unit').value = data.data.duration_unit || '';
                    document.getElementById('course_description').value = data.data.description || '';

                    const days = data.data.days.split(',');
                    document.querySelectorAll('#course_days input[type="checkbox"]').forEach(cb => {
                        cb.checked = days.includes(cb.value);
                    });

                    openModal('editCourseModal');
                }
            });
    }

    function updateCourse() {
        const formData = new FormData(document.getElementById('editCourseForm'));

        const days = Array.from(document.querySelectorAll('#course_days input[type="checkbox"]:checked'))
            .map(cb => cb.value);
        formData.set('days', days.join(','));
        formData.append('action', 'update_course');

        fetch('', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Course updated successfully!');
                    closeModal('editCourseModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Failed to update course', 'error');
                }
            });
    }

    function confirmDeleteCourse(courseId, name) {
        currentCourseId = courseId;
        currentCourseName = name;

        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get_course_deletion_info&course_id=${courseId}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const course = data.course;
                    const deleteDetails = document.getElementById('courseDeleteDetails');

                    deleteDetails.innerHTML = `
<div style="margin-bottom: 20px;">
    <p style="font-size: 1.2rem; color: #333; margin-bottom: 10px;">
        <strong>Course:</strong> ${escapeHTML(course.name)}
    </p>
    <p class="course-price" style="color: #27ae60; font-size: 1.3rem; font-weight: bold;">
        Course Price: <span style="color: #2c3e50;">$${course.price}</span>
    </p>
    <p class="course-student-count" style="color: #666;">
        Enrolled Students: <strong>${course.student_count}</strong>
    </p>
    <div class="refund-info">
        <p style="color: #e67e22; font-weight: bold; margin: 10px 0;">
            Total Refund Amount: <span class="refund-amount">$${course.total_refund}</span>
        </p>
        <p style="color: #7f8c8d; font-size: 0.9rem;">
            Each student will receive: <strong>$${course.price}</strong> refund
        </p>
    </div>
</div>

${course.students.length > 0 ? `
<div style="max-height: 250px; overflow-y: auto; margin: 15px 0; border: 1px solid #e0e0e0; border-radius: 5px; padding: 10px; text-align: ">
    <h4 style="margin-bottom: 15px; color: #2c3e50;">Students to be refunded:</h4>
    ${course.students.map(student => `
    <div class="students-list-item" style="padding: 10px; border-bottom: 1px solid #f0f0f0;">
        <div class="student-info">
            <div class="student-name" style="font-weight: 600; color: #333;">${escapeHTML(student.name)}</div>
            <div class="student-email" style="color: #666; font-size: 0.85rem;">${escapeHTML(student.email)}</div>
            <div class="student-balance-change" style="font-size: 0.8rem; color: #888; margin-top: 3px;">
                Current Balance: $${student.current_balance} →
                <span style="color: #27ae60; font-weight: bold;">New Balance: $${student.new_balance}</span>
            </div>
        </div>
    </div>
    `).join('')}
</div>

<div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 15px;">
    <p style="color: #e74c3c; font-weight: bold; margin-bottom: 10px;">
        ⚠️ Important: Deleting this course will:
    </p>
    <ul style="color: #666; margin-left: 20px;">
        <li>Refund $${course.price} to each student</li>
        <li>Remove all course data permanently</li>
        <li>Remove student enrollments for this course</li>
        <li>Send notification to each student about the refund</li>
    </ul>
</div>
` : '<p style="color: #666; text-align: center; padding: 20px;">No students enrolled in this course.</p>'}
`;

                    openModal('courseDeleteConfirmModal');
                } else {
                    showToast('Failed to load course details', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error loading course details', 'error');
            });
    }

    function confirmCourseDelete() {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=delete_course&course_id=${currentCourseId}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const refundMsg = data.refunded_amount ?
                        ` Students have been refunded $${data.refunded_amount} each.` : '';
                    const studentCountMsg = data.students_count ?
                        ` (${data.students_count} students refunded)` : '';
                    const totalRefundMsg = data.total_refund ?
                        ` Total refund amount: $${data.total_refund}` : '';

                    showToast(`Course deleted successfully!${refundMsg}${studentCountMsg}${totalRefundMsg}`);
                    closeModal('courseDeleteConfirmModal');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Failed to delete course: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error deleting course', 'error');
            });
    }

    function markAllNotificationsRead() {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=mark_all_notifications_read'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
    }

    // بقية كود DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function() {
        const menuItems = document.querySelectorAll('.menu-item');
        const contentSections = document.querySelectorAll('.content-section');
        let allDatabaseCourses = [];

        menuItems.forEach(item => {
            item.addEventListener('click', () => {
                menuItems.forEach(i => i.classList.remove('active'));
                item.classList.add('active');

                contentSections.forEach(section => section.classList.add('hidden'));
                const targetId = item.getAttribute('data-target');
                document.getElementById(targetId).classList.remove('hidden');

                switch(targetId) {
                    case 'teachers':
                        loadAllCoursesFromDatabase();
                        break;
                    case 'students':
                        loadStudents();
                        break;
                    case 'courses':
                        loadCourses();
                        break;
                    case 'student-courses':
                        loadStudentsForEnrollments();
                        break;
                }
            });
        });

        function loadAllCoursesFromDatabase() {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_all_courses_names'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        allDatabaseCourses = data.courses;
                        populateCourseFilter(allDatabaseCourses);
                        loadTeachers();
                    } else {
                        console.error('Failed to load courses');
                        loadTeachers();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    loadTeachers();
                });
        }

        function populateCourseFilter(courses) {
            const coursesFilter = document.getElementById('coursesFilter');
            const filterMessage = document.getElementById('filterMessage');

            coursesFilter.innerHTML = '';

            const allOption = document.createElement('div');
            allOption.className = 'course-checkbox';
            allOption.innerHTML = `
<input type="checkbox" id="filter-all" name="course" value="all" checked>
<label for="filter-all">All Courses</label>
`;
            coursesFilter.appendChild(allOption);

            if (courses.length > 0) {
                courses.forEach(course => {
                    const courseOption = document.createElement('div');
                    courseOption.className = 'course-checkbox';
                    const id = 'filter-' + course.replace(/\s+/g, '-').toLowerCase();
                    courseOption.innerHTML = `
<input type="checkbox" id="${id}" name="course" value="${escapeHTML(course)}" checked>
<label for="${id}">${escapeHTML(course)}</label>
`;
                    coursesFilter.appendChild(courseOption);
                });
                filterMessage.textContent = `${courses.length} courses available`;
            } else {
                filterMessage.textContent = 'No courses available in database';
            }

            const allCheckbox = document.getElementById('filter-all');
            const courseCheckboxes = document.querySelectorAll('#coursesFilter input[name="course"]:not([value="all"])');

            allCheckbox.addEventListener('change', function() {
                courseCheckboxes.forEach(cb => cb.checked = this.checked);
                filterTeachersByCourse();
            });

            courseCheckboxes.forEach(cb => {
                cb.addEventListener('change', function() {
                    const allChecked = Array.from(courseCheckboxes).every(c => c.checked);
                    allCheckbox.checked = allChecked;
                    filterTeachersByCourse();
                });
            });
        }

        function filterTeachersByCourse() {
            const selectedCourses = Array.from(
                document.querySelectorAll('#coursesFilter input[name="course"]:checked:not([value="all"])')
            ).map(cb => cb.value);

            const allCheckbox = document.getElementById('filter-all');
            const filterMessage = document.getElementById('filterMessage');

            if (allCheckbox.checked || selectedCourses.length === 0) {
                loadTeachers();
                filterMessage.textContent = 'Showing all teachers';
                return;
            }

            if (selectedCourses.length === 1) {
                filterMessage.textContent = `Showing teachers for: ${selectedCourses[0]}`;
            } else {
                filterMessage.textContent = `Showing teachers for ${selectedCourses.length} courses`;
            }

            const filteredTeachers = [];
            const fetchPromises = selectedCourses.map(course => {
                return fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=filter_teachers_by_course&course_name=${encodeURIComponent(course)}`
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.teachers.length > 0) {
                            data.teachers.forEach(teacher => {
                                if (!filteredTeachers.find(t => t.email === teacher.email)) {
                                    filteredTeachers.push(teacher);
                                }
                            });
                        }
                    });
            });

            Promise.all(fetchPromises).then(() => {
                if (filteredTeachers.length > 0) {
                    displayTeachers(filteredTeachers);
                } else {
                    showNoResults('teachersList', 'No teachers found for selected courses');
                }
            }).catch(error => {
                console.error('Error filtering teachers:', error);
                showNoResults('teachersList', 'Error filtering teachers');
            });
        }

        function loadTeachers() {
            const teachersList = document.getElementById('teachersList');
            teachersList.innerHTML = '<div class="no-results"><i class="fas fa-spinner fa-spin"></i><h3>Loading teachers...</h3></div>';

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_teachers'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.teachers.length > 0) {
                        displayTeachers(data.teachers);
                        document.getElementById('filterMessage').textContent = `Showing ${data.teachers.length} teachers`;
                    } else {
                        showNoResults('teachersList', 'No teachers found');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNoResults('teachersList', 'Error loading teachers');
                });
        }

        function displayTeachers(teachers) {
            const teachersList = document.getElementById('teachersList');

            if (teachers.length === 0) {
                showNoResults('teachersList', 'No teachers found');
                return;
            }

            teachersList.innerHTML = '';

            teachers.forEach(teacher => {
                const teacherCard = document.createElement('div');
                teacherCard.className = 'teacher-card';

                const teacherCourses = teacher.courses.filter(course =>
                    allDatabaseCourses.includes(course)
                );

                const coursesHTML = teacherCourses.length > 0
                    ? teacherCourses.map(course =>
                        `<span class="course-tag">${escapeHTML(course)}</span>`
                    ).join('')
                    : '<span class="course-tag">No courses assigned</span>';

                teacherCard.innerHTML = `
<div class="card-actions">
    <button class="action-btn edit-btn" onclick="editTeacher('${escapeHTML(teacher.email)}')" title="Edit">
        <i class="fas fa-edit"></i>
    </button>
    <button class="action-btn delete-btn" onclick="confirmDeleteTeacher('${escapeHTML(teacher.email)}', '${escapeHTML(teacher.fullname)}')" title="Delete">
        <i class="fas fa-trash"></i>
    </button>
</div>
<img src="${escapeHTML(teacher.profileimage)}" alt="${escapeHTML(teacher.fullname)}" class="teacher-image">
<h3 class="teacher-name">${escapeHTML(teacher.fullname)}</h3>
<p class="teacher-specialty">${escapeHTML(teacher.job_title)}</p>
<div class="teacher-courses">
    ${coursesHTML}
</div>
<div class="course-info">
    <div class="info-item">
        <span class="info-label">Age</span>
        <span class="info-value">${teacher.age}</span>
    </div>
    <div class="info-item">
        <span class="info-label">Courses</span>
        <span class="info-value">${teacher.course_count}</span>
    </div>
    <div class="info-item">
        <span class="info-label">Salary</span>
        <span class="info-value">$${teacher.salary}</span>
    </div>
</div>
<div class="teacher-details" style="margin-top: 15px; font-size: 0.9rem; color: #666;">
    <p><strong>Email:</strong> ${escapeHTML(teacher.email)}</p>
    <p><strong>Gender:</strong> ${escapeHTML(teacher.gender)}</p>
</div>
`;

                teachersList.appendChild(teacherCard);
            });
        }

        function loadStudents() {
            const studentsList = document.getElementById('studentsList');
            studentsList.innerHTML = '<div class="no-results"><i class="fas fa-spinner fa-spin"></i><h3>Loading students...</h3></div>';

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_students'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.students.length > 0) {
                        displayStudents(data.students);
                    } else {
                        showNoResults('studentsList', 'No students found');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNoResults('studentsList', 'Error loading students');
                });
        }

        function displayStudents(students) {
            const studentsList = document.getElementById('studentsList');

            if (students.length === 0) {
                showNoResults('studentsList', 'No students found');
                return;
            }

            studentsList.innerHTML = '';

            students.forEach(student => {
                const studentCard = document.createElement('div');
                studentCard.className = 'student-card';

                const studentCourses = student.courses.filter(course =>
                    allDatabaseCourses.includes(course)
                );

                const coursesHTML = studentCourses.length > 0
                    ? studentCourses.map(course =>
                        `<span class="course-tag">${escapeHTML(course)}</span>`
                    ).join('')
                    : '<span class="course-tag">No courses enrolled</span>';

                studentCard.innerHTML = `
<div class="card-actions">
    <button class="action-btn edit-btn" onclick="editStudent('${escapeHTML(student.email)}')" title="Edit">
        <i class="fas fa-edit"></i>
    </button>

    <button class="action-btn notify-btn" onclick="showStudentCourses('${escapeHTML(student.email)}', '${escapeHTML(student.fullname)}')" title="Send Course Notification">
        <i class="fas fa-bell"></i>
    </button>
</div>
<img src="${escapeHTML(student.profileimage)}" alt="${escapeHTML(student.fullname)}" class="student-image">
<h3 class="student-name">${escapeHTML(student.fullname)}</h3>
<p class="student-level">${student.age} years old</p>
<div class="student-courses">
    ${coursesHTML}
</div>
<div class="course-info">
    <div class="info-item">
        <span class="info-label">Balance</span>
        <span class="info-value">$${student.balance}</span>
    </div>
    <div class="info-item">
        <span class="info-label">Courses</span>
        <span class="info-value">${student.course_count}</span>
    </div>
    <div class="info-item">
        <span class="info-label">Completed</span>
        <span class="info-value">${student.completed_courses}</span>
    </div>
</div>
<div class="student-details" style="margin-top: 15px; font-size: 0.9rem; color: #666;">
    <p><strong>Email:</strong> ${escapeHTML(student.email)}</p>
    <p><strong>Gender:</strong> ${escapeHTML(student.gender)}</p>
</div>
`;

                studentsList.appendChild(studentCard);
            });
        }

        function loadCourses() {
            const coursesList = document.getElementById('coursesList');
            coursesList.innerHTML = '<div class="no-results"><i class="fas fa-spinner fa-spin"></i><h3>Loading courses...</h3></div>';

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_courses'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.courses.length > 0) {
                        displayCourses(data.courses);
                    } else {
                        showNoResults('coursesList', 'No courses found');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNoResults('coursesList', 'Error loading courses');
                });
        }

        function displayCourses(courses) {
            const coursesList = document.getElementById('coursesList');

            if (courses.length === 0) {
                showNoResults('coursesList', 'No courses found');
                return;
            }

            coursesList.innerHTML = '';

            courses.forEach(course => {
                const courseCard = document.createElement('div');
                courseCard.className = 'course-card';

                const daysHTML = course.days.map(day =>
                    `<span class="course-tag">${escapeHTML(day)}</span>`
                ).join('');

                let iconClass = 'fas fa-book';
                if (course.category === 'web') {
                    iconClass = 'fas fa-code';
                } else if (course.category === 'python') {
                    iconClass = 'fab fa-python';
                } else if (course.category === 'java') {
                    iconClass = 'fab fa-java';
                } else if (course.category === 'database') {
                    iconClass = 'fas fa-database';
                }

                courseCard.innerHTML = `
<div class="card-actions">
    <button class="action-btn edit-btn" onclick="editCourse(${course.id})" title="Edit">
        <i class="fas fa-edit"></i>
    </button>
    <button class="action-btn delete-btn" onclick="confirmDeleteCourse(${course.id}, '${escapeHTML(course.name)}')" title="Delete">
        <i class="fas fa-trash"></i>
    </button>
</div>
<div class="course-icon">
    <i class="${iconClass}"></i>
</div>
<h3 class="course-name">${escapeHTML(course.name)}</h3>
<p class="course-category">${escapeHTML(course.teacher_name)}</p>
<div class="course-details">
    ${daysHTML}
</div>
<div class="course-info">
    <div class="info-item">
        <span class="info-label">Time</span>
        <span class="info-value">${escapeHTML(course.start_time)} - ${escapeHTML(course.end_time)}</span>
    </div>
    <div class="info-item">
        <span class="info-label">Students</span>
        <span class="info-value">${course.student_count}/${course.max_students}</span>
    </div>
    <div class="info-item">
        <span class="info-label">Price</span>
        <span class="info-value">$${course.price}</span>
    </div>
</div>
<div class="course-description" style="margin-top: 15px; font-size: 0.9rem; color: #666;">
    <p>${escapeHTML(course.description)}</p>
    ${course.duration ? `<p><strong>Duration:</strong> ${escapeHTML(course.duration)}</p>` : ''}
    <p><strong>Teacher:</strong> ${escapeHTML(course.teacher_name)}</p>
</div>
`;

                coursesList.appendChild(courseCard);
            });
        }

        // Student Enrollments Functions - الوظائف التي تستخدم داخلياً فقط
        function loadStudentsForEnrollments() {
            const container = document.getElementById('studentsListContainer');
            container.innerHTML = '<div class="no-results"><i class="fas fa-spinner fa-spin"></i><h3>Loading students...</h3></div>';
            document.getElementById('coursesDetailsContainer').style.display = 'none';

            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_students_with_courses'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.students.length > 0) {
                        displayStudentsForEnrollments(data.students);
                    } else {
                        container.innerHTML = '<div class="no-results"><i class="fas fa-user-graduate"></i><h3>No students with courses found</h3></div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    container.innerHTML = '<div class="no-results"><i class="fas fa-exclamation-circle"></i><h3>Error loading students</h3></div>';
                });
        }

        function displayStudentsForEnrollments(students) {
            const container = document.getElementById('studentsListContainer');
            container.innerHTML = '';

            students.forEach(student => {
                const studentCard = document.createElement('div');
                studentCard.className = 'student-enrollment-card';
                studentCard.setAttribute('data-email', student.email);
                studentCard.setAttribute('data-name', `${student.firstname} ${student.lastname}`);

                studentCard.innerHTML = `
<img src="${escapeHTML(student.profileimage)}" alt="${escapeHTML(student.firstname)}">
<h3>${escapeHTML(student.firstname)} ${escapeHTML(student.lastname)}</h3>
<p>${escapeHTML(student.email)}</p>
<button class="btn btn-primary" onclick="loadStudentEnrollments('${escapeHTML(student.email)}', '${escapeHTML(student.firstname)} ${escapeHTML(student.lastname)}')">View Enrollments</button>
`;

                container.appendChild(studentCard);
            });
        }

        function showNoResults(containerId, message) {
            const container = document.getElementById(containerId);
            container.innerHTML = `
<div class="no-results">
    <i class="fas fa-search"></i>
    <h3>${message}</h3>
</div>
`;
        }

        loadAllCoursesFromDatabase();
    });

    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }
</script>
</body>
</html>