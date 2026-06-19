<?php
session_start();
require_once 'config/db_connection.php';

// التحقق من أن المستخدم طالب
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
    header("Location: login.php");
    exit();
}
$user_email = $_SESSION['email'];
$student_name = $_SESSION['firstname'] . ' ' . $_SESSION['lastname'];

// استعلام بيانات الطالب
$student_query = "SELECT s.balance, u.* FROM students s 
                  JOIN users u ON s.email = u.email 
                  WHERE s.email = ?";
$student_stmt = $conn->prepare($student_query);
$student_stmt->bind_param("s", $user_email);
$student_stmt->execute();
$student_result = $student_stmt->get_result();
$student_data = $student_result->fetch_assoc();

$student_balance = $student_data['balance'] ?? 150.00;
$sql = "SELECT COALESCE(SUM(c.price), 0) AS total_spent 
        FROM study s
        JOIN courses c ON s.course_id = c.id
        WHERE s.student_email = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $user_email);
$stmt->execute();
$result = $stmt->get_result();
$studentb = $result->fetch_assoc();
$total_spent = $studentb['total_spent'];
// استعلام الإشعارات للطالب
$notifications_query = "SELECT * FROM notifications
WHERE user_email = ?
ORDER BY created_at DESC LIMIT 5";
$notifications_stmt = $conn->prepare($notifications_query);
$notifications_stmt->bind_param("s", $user_email);
$notifications_stmt->execute();
$notifications = $notifications_stmt->get_result();

// استعلام المحادثات
$conversations_query = "SELECT DISTINCT u.* FROM messages m
JOIN users u ON (m.sender_email = u.email OR m.user_email = u.email)
WHERE (m.user_email = ? OR m.sender_email = ?)
AND u.email != ?
GROUP BY u.email
ORDER BY MAX(m.sent_at) DESC";
$conversations_stmt = $conn->prepare($conversations_query);
$conversations_stmt->bind_param("sss", $user_email, $user_email, $user_email);
$conversations_stmt->execute();
$conversations = $conversations_stmt->get_result();

// استعلام عدد الكورسات المسجلة
$stats_query = "SELECT COUNT(*) as courses_count FROM study WHERE student_email = ?";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("s", $user_email);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

// معالجة إضافة كورس
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['course_id'])) {
    $course_id = intval($_POST['course_id']);

    // استعلام بيانات الكورس
    $course_query = "SELECT price, name, max_students FROM courses WHERE id = ?";
    $course_stmt = $conn->prepare($course_query);
    $course_stmt->bind_param("i", $course_id);
    $course_stmt->execute();
    $course_result = $course_stmt->get_result();

    if ($course_result->num_rows === 0) {
        echo "<script>alert('Course not found!');</script>";
    } else {
        $course_data = $course_result->fetch_assoc();
        $course_price = $course_data['price'];
        $course_name = $course_data['name'];
        $max_students = $course_data['max_students'];

        // التحقق من الرصيد
        if ($student_balance >= $course_price) {
            // التحقق من عدد الطلاب المسجلين
            $enrolled_count_query = "SELECT COUNT(*) as enrolled FROM study WHERE course_id = ?";
            $enrolled_stmt = $conn->prepare($enrolled_count_query);
            $enrolled_stmt->bind_param("i", $course_id);
            $enrolled_stmt->execute();
            $enrolled_result = $enrolled_stmt->get_result();
            $enrolled_data = $enrolled_result->fetch_assoc();
            $enrolled_count = $enrolled_data['enrolled'];

            // التحقق من أن الطالب غير مسجل مسبقاً (تحقق إضافي)
            $already_enrolled_query = "SELECT * FROM study WHERE course_id = ? AND student_email = ?";
            $already_stmt = $conn->prepare($already_enrolled_query);
            $already_stmt->bind_param("is", $course_id, $user_email);
            $already_stmt->execute();
            $already_result = $already_stmt->get_result();

            if ($already_result->num_rows > 0) {
                echo "<script>Swal.fire('Already Enrolled', 'You are already enrolled in this course!', 'info');</script>";
            } elseif ($max_students > 0 && $enrolled_count >= $max_students) {
                echo "<script>Swal.fire('Course Full', 'Course is full! Maximum students reached.', 'warning');</script>";
            } else {
                // بدء المعاملة (transaction)
                $conn->begin_transaction();

                try {
                    // تسجيل الطالب في الكورس
                    $insert_query = "INSERT INTO study (course_id, student_email) VALUES (?, ?)";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bind_param("is", $course_id, $user_email);

                    if ($insert_stmt->execute()) {
                        // خصم السعر من رصيد الطالب
                        $new_balance = $student_balance - $course_price;
                        $update_query = "UPDATE students SET balance = ? WHERE email = ?";
                        $update_stmt = $conn->prepare($update_query);
                        $update_stmt->bind_param("ds", $new_balance, $user_email);

                        if ($update_stmt->execute()) {
                            $student_balance = $new_balance;
                            $_SESSION['balance'] = $new_balance;

                            // تحديث حالة الطالب في الكورس
                            $status_query = "INSERT INTO studentcoursestatus (student_email, course_id, status) 
                                           VALUES (?, ?, 'In Progress') 
                                           ON DUPLICATE KEY UPDATE status = 'In Progress'";
                            $status_stmt = $conn->prepare($status_query);
                            $status_stmt->bind_param("si", $user_email, $course_id);
                            $status_stmt->execute();

                            // إضافة إشعار
                            $notification_query = "INSERT INTO notifications (user_email, notification_text) 
                                                  VALUES (?, ?)";
                            $notification_stmt = $conn->prepare($notification_query);
                            $notification_text = "You have successfully registered for a new course ($course_name)";
                            $notification_stmt->bind_param("ss", $user_email, $notification_text);
                            $notification_stmt->execute();

                            // تأكيد المعاملة
                            $conn->commit();

                            // رسالة النجاح باستخدام SweetAlert
                            echo "<script>
                                Swal.fire({
                                    title: 'Success!',
                                    html: 'Course <b>$course_name</b> added successfully!<br>Remaining balance: <b>$" . number_format($new_balance, 2) . "</b>',
                                    icon: 'success',
                                    confirmButtonText: 'OK'
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        window.location.reload();
                                    }
                                });
                            </script>";
                        } else {
                            throw new Exception("Failed to update balance");
                        }
                    } else {
                        throw new Exception("Failed to enroll in course");
                    }
                } catch (Exception $e) {
                    // التراجع عن المعاملة في حالة الخطأ
                    $conn->rollback();
                    echo "<script>
                        Swal.fire({
                            title: 'Error!',
                            text: 'Failed to add course: " . addslashes($e->getMessage()) . "',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    </script>";
                }
            }
        } else {
            // رسالة عدم كفاية الرصيد مع SweetAlert
            $needed_amount = number_format($course_price - $student_balance, 2);
            echo "<script>
                Swal.fire({
                    title: 'Insufficient Balance!',
                    html: '<div style=\"text-align: left; padding: 10px;\">' +
                          '<p style=\"margin-bottom: 15px; font-size: 16px;\">You don\\'t have enough balance to add this course.</p>' +
                          '<div style=\"background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;\">' +
                          '<p style=\"margin-bottom: 5px;\"><strong>Course:</strong> $course_name</p>' +
                          '<p style=\"margin-bottom: 5px;\"><strong>Course Price:</strong> $" . number_format($course_price, 2) . "</p>' +
                          '<p style=\"margin-bottom: 5px;\"><strong>Your Balance:</strong> $" . number_format($student_balance, 2) . "</p>' +
                          '<p style=\"margin-bottom: 0;\"><strong>Additional Needed:</strong> <span style=\"color: #e74c3c; font-weight: bold;\">$" . $needed_amount . "</span></p>' +
                          '</div>' +
                          '</div>',
                    icon: 'warning',
                    showCloseButton: true,
                    showConfirmButton: false,
                    width: '500px'
                });
            </script>";
        }
    }
}

// استعلام الكورسات المتاحة (غير المسجلة لدى الطالب) للعرض
$courses_query = "SELECT c.*, u.firstname, u.lastname, u.profileimage as teacher_image,
                (SELECT COUNT(*) FROM study WHERE course_id = c.id) as enrolled_count
FROM courses c
JOIN teachers t ON c.teacher = t.email
JOIN users u ON t.email = u.email
WHERE c.id NOT IN (
    SELECT course_id FROM study WHERE student_email = ?
)
HAVING (c.max_students = 0 OR enrolled_count < c.max_students)
ORDER BY c.id DESC";

// تنفيذ الاستعلام للعرض
$courses_stmt = $conn->prepare($courses_query);
$courses_stmt->bind_param("s", $user_email);
$courses_stmt->execute();
$courses_result = $courses_stmt->get_result();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edavora</title>
    <link rel="stylesheet" href="css/footor.css">
    <link rel="stylesheet" href="css/head.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="img/icon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        html, body {
            height: 100%;
        }

        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #49386e;
            line-height: 1.6;
            display: flex;
            flex-direction: column;
        }

        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            flex: 1;
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 25px;
        }

        .courses-section {
            padding: 20px;
        }

        .sidebar-section {
            padding: 20px 0;
        }

        .balance-card {
            background: linear-gradient(135deg, #675788 0%, #49386e 100%);
            color: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(103, 87, 136, 0.2);
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }

        .balance-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .balance-card h2 {
            font-size: 1.5rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .balance-card h2 i {
            font-size: 1.3rem;
        }

        .balance-info {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .balance-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .balance-item:last-child {
            border-bottom: none;
        }

        .balance-label {
            font-size: 0.95rem;
            opacity: 0.9;
        }

        .balance-value {
            font-weight: 700;
            font-size: 1.1rem;
        }

        .page-title {
            text-align: center;
            margin-bottom: 40px;
            color: #49386e;
            position: relative;
        }

        .page-title h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .page-title p {
            color: #7f8c8d;
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto;
        }


        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }

        .course-card {
            background-color: #fff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            position: relative;
            border: 1px solid rgba(103, 87, 136, 0.1);
        }

        .course-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }

        .course-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #27ae60;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            z-index: 2;
        }

        .course-image-container {
            height: 180px;
            overflow: hidden;
            position: relative;
        }

        .course-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .course-card:hover .course-image {
            transform: scale(1.05);
        }

        .course-content {
            padding: 20px;
        }

        .course-header {
            margin-bottom: 15px;
        }

        .course-title {
            font-size: 1.3rem;
            margin-bottom: 8px;
            color: #49386e;
            font-weight: 700;
            line-height: 1.3;
        }

        .course-teacher {
            color: #7f8c8d;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* صورة المعلم الدائرية الصغيرة */
        .teacher-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #675788;
        }

        .course-details {
            margin-bottom: 20px;
        }

        .course-detail-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            color: #555;
            font-size: 0.9rem;
        }

        .course-detail-item i {
            width: 20px;
            color: #675788;
            margin-right: 10px;
        }

        .course-description {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 20px;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .course-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
        }

        .course-price {
            font-weight: 700;
            font-size: 1.4rem;
            color: #e74c3c;
        }

        .add-btn {
            background: linear-gradient(to right, #675788, #49386e);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 10px rgba(103, 87, 136, 0.3);
        }

        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(103, 87, 136, 0.4);
        }

        .add-btn:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            padding: 30px;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }

        .modal-title {
            font-size: 1.5rem;
            color: #49386e;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            transition: color 0.3s;
        }

        .close-btn:hover {
            color: #e74c3c;
        }

        .payment-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: #49386e;
        }

        .form-group input {
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: border 0.3s;
        }

        .form-group input:focus {
            border-color: #675788;
            outline: none;
            box-shadow: 0 0 0 2px rgba(103, 87, 136, 0.2);
        }

        .form-row {
            display: flex;
            gap: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .payment-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 20px;
        }

        .cancel-btn {
            background-color: #95a5a6;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }

        .cancel-btn:hover {
            background-color: #7f8c8d;
        }

        .pay-btn {
            background: linear-gradient(to right, #27ae60, #2ecc71);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 10px rgba(39, 174, 96, 0.3);
        }

        .pay-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(39, 174, 96, 0.4);
        }

        .insufficient-balance {
            color: #e74c3c;
            font-weight: bold;
            margin-top: 10px;
            text-align: center;
        }

        /* Stats Section */
        .stats-section {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
        }

        .stats-title {
            font-size: 1.2rem;
            margin-bottom: 15px;
            color: #49386e;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .stat-item {
            text-align: center;
            padding: 15px;
            border-radius: 10px;
            background: #f8f9fa;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #675788;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #7f8c8d;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .main-container {
                grid-template-columns: 1fr;
            }

            .sidebar-section {
                order: -1;
            }
        }

        @media (max-width: 768px) {
            .courses-grid {
                grid-template-columns: 1fr;
            }

            .page-title h1 {
                font-size: 2rem;
            }

            .form-row {
                flex-direction: column;
                gap: 10px;
            }
        }

        @media (max-width: 480px) {
            .main-container {
                padding: 10px;
            }

            .course-footer {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .add-btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Custom Alert */
        .custom-alert {
            position: fixed;
            top: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(-20px);
            background: rgba(76,175,80,0.9);
            color: white;
            padding: 15px 30px;
            border-radius: 8px;
            z-index: 10000;
            transition: all 0.3s ease-in-out;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            font-family: Arial, sans-serif;
            font-size: 16px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            opacity: 0;
            max-width: 90%;
            text-align: center;
            cursor: pointer;
        }

        .custom-alert.error {
            background: rgba(244,67,54,0.9);
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

        /* Messages Dropdown */
        .messages-dropdown {
            display: none;
            position: absolute;
            top: 50px;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            z-index: 1001;
        }

        .message-container:hover .messages-dropdown {
            display: block;
        }

        .messages-header {
            padding: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .messages-header h3 {
            margin: 0;
            color: #49386e;
        }

        .send-message-btn {
            background: #675788;
            color: white;
            border: none;
            border-radius: 20px;
            padding: 6px 12px;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .conversations-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .conversation-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .conversation-item:hover {
            background: #f8f9fa;
        }

        .conversation-item img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .conversation-info {
            flex: 1;
        }

        .conversation-name {
            font-weight: bold;
            color: #49386e;
        }

        .conversation-last-message {
            color: #666;
            font-size: 13px;
            margin-top: 3px;
        }

        /* Message Modal */
        .message-modal {
            display: none;
            position: fixed;
            top: 80px;
            left: 700px;
            width: 100%;
            height: 100%;
            z-index: 1002;
            justify-content: center;
            align-items: center;
        }

        .message-modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow: hidden;
        }

        .message-modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .message-modal-header h3 {
            margin: 0;
            color: #49386e;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }

        .message-modal-body {
            padding: 20px;
            overflow-y: auto;
            max-height: 50vh;
        }

        .recipient-type {
            margin-bottom: 20px;
        }

        .recipient-type h4 {
            margin-bottom: 10px;
            color: #49386e;
        }

        .type-buttons {
            display: flex;
            gap: 10px;
        }

        .type-btn {
            flex: 1;
            padding: 8px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 5px;
            cursor: pointer;
            color: #666;
        }

        .type-btn.active {
            background: #675788;
            color: white;
            border-color: #675788;
        }

        .recipients-list h4 {
            margin-bottom: 10px;
            color: #49386e;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .select-all {
            background: none;
            border: none;
            color: #675788;
            cursor: pointer;
            font-size: 14px;
        }

        .recipients-container {
            max-height: 150px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 5px;
            padding: 10px;
        }

        .recipient-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 5px;
            border-bottom: 1px solid #f5f5f5;
        }

        .recipient-item:last-child {
            border-bottom: none;
        }

        .recipient-item img {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
        }

        .recipient-item label {
            flex: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .message-input h4 {
            margin: 15px 0 10px 0;
            color: #49386e;
        }

        .message-input textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }

        .message-modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .cancel-btn, .send-btn {
            padding: 10px 20px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-weight: 500;
        }

        .cancel-btn {
            background: #f8f9fa;
            color: #666;
        }

        .send-btn {
            background: #675788;
            color: white;
        }

        .send-btn:hover {
            background: #49386e;
        }

        /* SweetAlert Custom Styles */
        .swal2-popup.insufficient-balance-popup {
            border-radius: 16px;
            padding: 2rem;
        }

        .swal2-title {
            color: #e74c3c;
            font-size: 24px !important;
            margin-bottom: 20px !important;
        }

        .swal2-close {
            font-size: 24px;
            color: #666;
        }

        /* Recharge Button */
        .recharge-btn {
            background: linear-gradient(to right, #27ae60, #2ecc71);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 10px rgba(39, 174, 96, 0.3);
            width: 100%;
            margin-top: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .recharge-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(39, 174, 96, 0.4);
        }
    </style>
</head>
<body>
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
            <a href="student_mycourse.php">My Courses</a>
            <a href="student_add_course.php" class="active">Add Courses</a>
        </nav>

        <div class="right-section">
            <div class="notification-container">
                <div class="notification-icon">
                    <i class="fas fa-bell"></i>
                    <?php
                    $unread_query = "SELECT COUNT(*) as count FROM notifications 
                                    WHERE user_email = ? AND is_read = 0";
                    $unread_stmt = $conn->prepare($unread_query);
                    $unread_stmt->bind_param("s", $user_email);
                    $unread_stmt->execute();
                    $unread_result = $unread_stmt->get_result();
                    $unread_count = $unread_result->fetch_assoc()['count'];
                    ?>
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
                <img src="<?php echo $student_data['profileimage']??'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'; ?>" alt="User Profile">
                <span class="username"><?php echo htmlspecialchars($student_data['firstname'] . ' ' . $student_data['lastname']); ?></span>
            </button>


            <button class="logout" onclick="window.location.href='login.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>
    </div>

</header>

<div class="main-container">
    <main class="courses-section">
        <div class="page-title">
            <h1>Available Programming Courses</h1>
            <p>Explore our wide range of programming courses and enhance your skills</p>
        </div>

        <div class="courses-grid" id="courses-container">
            <?php if(isset($courses_result) && $courses_result->num_rows > 0): ?>
                <?php while($course = $courses_result->fetch_assoc()): ?>
                    <div class="course-card">
                        <?php if($course['max_students'] && $course['max_students'] > 0): ?>
                            <div class="course-badge">Limited Seats</div>
                        <?php endif; ?>

                        <div class="course-image-container">
                            <img src="<?php echo $course['image'] ?: 'https://images.unsplash.com/photo-1555066931-4365d14bab8c?ixlib=rb-1.2.1&auto=format&fit=crop&w=500&q=60'; ?>"
                                 alt="<?php echo $course['name']; ?>" class="course-image">
                        </div>

                        <div class="course-content">
                            <div class="course-header">
                                <h3 class="course-title"><?php echo htmlspecialchars($course['name']); ?></h3>
                                <div class="course-teacher">
                                    <img src="<?php echo $course['teacher_image'] ?: 'https://randomuser.me/api/portraits/men/32.jpg'; ?>"
                                         alt="<?php echo $course['firstname'] . ' ' . $course['lastname']; ?>" class="teacher-avatar">
                                    <span><?php echo $course['firstname'] . ' ' . $course['lastname']; ?></span>
                                </div>
                            </div>

                            <div class="course-details">
                                <div class="course-detail-item">
                                    <i class="far fa-clock"></i>
                                    <span><?php echo date("g:i A", strtotime($course['start_time'])); ?> - <?php echo date("g:i A", strtotime($course['end_time'])); ?></span>
                                </div>
                                <div class="course-detail-item">
                                    <i class="far fa-calendar-alt"></i>
                                    <span><?php echo htmlspecialchars($course['days']); ?></span>
                                </div>
                                <?php if($course['duration_number']): ?>
                                    <div class="course-detail-item">
                                        <i class="fas fa-hourglass-half"></i>
                                        <span>Duration: <?php echo $course['duration_number'] . ' ' . $course['duration_unit']; ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <p class="course-description"><?php echo htmlspecialchars($course['description'] ?: 'No description available.'); ?></p>

                            <div class="course-footer">
                                <div class="course-price">$<?php echo number_format($course['price'], 2); ?></div>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                                    <button type="submit" class="add-btn">
                                        <i class="fas fa-plus"></i>
                                        Add Course
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 40px;">
                    <h3>No courses available at the moment.</h3>
                    <p>Check back later for new courses!</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <aside class="sidebar-section">
        <div class="balance-card">
            <h2><i class="fas fa-wallet"></i> Balance Information</h2>
            <div class="balance-info">
                <div class="balance-item">
                    <span class="balance-label">Current Balance</span>
                    <span class="balance-value" id="balance">$<?php echo number_format($student_balance, 2); ?></span>
                </div>
                <button class="recharge-btn" onclick="showRechargeModal()">
                    <i class="fas fa-plus-circle"></i>
                    Recharge Balance
                </button>
            </div>
        </div>

        <div class="stats-section">
            <h3 class="stats-title"><i class="fas fa-chart-bar"></i> Your Statistics</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $stats['courses_count']; ?></div>
                    <div class="stat-label">Courses Enrolled</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">$<?php echo number_format( $total_spent, 2); ?></div>
                    <div class="stat-label">Total Spent</div>
                </div>
            </div>
        </div>
    </aside>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-credit-card"></i> Complete Payment</h2>
            <button class="close-btn">&times;</button>
        </div>
        <div class="payment-form">
            <div class="form-group">
                <label for="cardNumber">Card Number</label>
                <input type="text" id="cardNumber" placeholder="1234 5678 9012 3456">
            </div>
            <div class="form-group">
                <label for="cardName">Cardholder Name</label>
                <input type="text" id="cardName" placeholder="John Doe">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="expiryDate">Expiry Date</label>
                    <input type="text" id="expiryDate" placeholder="MM/YY">
                </div>
                <div class="form-group">
                    <label for="cvv">CVV</label>
                    <input type="text" id="cvv" placeholder="123">
                </div>
            </div>
            <div class="payment-actions">
                <button class="cancel-btn">Cancel</button>
                <button class="pay-btn">Pay Now</button>
            </div>
        </div>
    </div>
</div>

<!-- Recharge Modal -->
<div id="rechargeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-credit-card"></i> Recharge Balance</h2>
            <button class="close-btn" onclick="closeRechargeModal()">&times;</button>
        </div>
        <div class="payment-form">
            <div class="form-group">
                <label for="rechargeAmount">Amount to Recharge ($)</label>
                <input type="number" id="rechargeAmount" placeholder="Enter amount" min="10" max="1000" step="10" value="50">
            </div>
            <div class="form-group">
                <label for="rechargeCardNumber">Card Number</label>
                <input type="text" id="rechargeCardNumber" placeholder="1234 5678 9012 3456">
            </div>
            <div class="form-group">
                <label for="rechargeCardName">Cardholder Name</label>
                <input type="text" id="rechargeCardName" placeholder="John Doe">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="rechargeExpiryDate">Expiry Date</label>
                    <input type="text" id="rechargeExpiryDate" placeholder="MM/YY">
                </div>
                <div class="form-group">
                    <label for="rechargeCVV">CVV</label>
                    <input type="text" id="rechargeCVV" placeholder="123">
                </div>
            </div>
            <div class="payment-actions">
                <button class="cancel-btn" onclick="closeRechargeModal()">Cancel</button>
                <button class="pay-btn" onclick="processRecharge()">Recharge Now</button>
            </div>
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
        <p style="margin-top: 5px;"> &copy; <?php echo date('Y'); ?> Coding Courses Platform. All rights reserved.</p>
    </div>
</footer>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
    // التحقق من الرصيد وعرض تأكيد عند النقر على زر Add Course
    document.querySelectorAll('.add-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            const form = this.closest('form');
            const priceElement = this.closest('.course-card').querySelector('.course-price');
            const price = parseFloat(priceElement.textContent.replace('$', ''));
            const currentBalance = <?php echo $student_balance; ?>;

            if (currentBalance < price) {
                e.preventDefault(); // منع إرسال النموذج

                const courseName = this.closest('.course-card').querySelector('.course-title').textContent;
                const neededAmount = (price - currentBalance).toFixed(2);

                Swal.fire({
                    title: 'Insufficient Balance!',
                    html: `<div style="text-align: left; padding: 10px;">
                       <p style="margin-bottom: 15px; font-size: 16px;">You don't have enough balance to add this course.</p>
                       <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                       <p style="margin-bottom: 5px;"><strong>Course:</strong> ${courseName}</p>
                       <p style="margin-bottom: 5px;"><strong>Course Price:</strong> $${price.toFixed(2)}</p>
                       <p style="margin-bottom: 5px;"><strong>Your Balance:</strong> $${currentBalance.toFixed(2)}</p>
                       <p style="margin-bottom: 0;"><strong>Additional Needed:</strong> <span style="color: #e74c3c; font-weight: bold;">$${neededAmount}</span></p>
                       </div>
                       <p style="font-size: 16px; color: #49386e; font-weight: bold; margin-bottom: 15px;">Please recharge your balance first.</p>
                       </div>`,
                    icon: 'warning',
                    showCloseButton: true,
                    showConfirmButton: false,
                    width: '500px'
                });
            } else {
                e.preventDefault(); // منع الإرسال المباشر لعرض التأكيد

                const courseName = this.closest('.course-card').querySelector('.course-title').textContent;

                Swal.fire({
                    title: 'Confirm Course Enrollment',
                    html: `Are you sure you want to enroll in the course <b>"${courseName}"</b> for <b>$${price.toFixed(2)}</b>?<br><br>
                       Your current balance: <b>$${currentBalance.toFixed(2)}</b><br>
                       Balance after enrollment: <b>$${(currentBalance - price).toFixed(2)}</b>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, enroll me!',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#675788',
                    cancelButtonColor: '#7f8c8d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit(); // تأكيد، إرسال النموذج
                    }
                });
            }
        });
    });

    // Show payment modal
    function showPaymentModal(coursePrice) {
        const modal = document.getElementById('paymentModal');
        modal.style.display = 'flex';

        // Set up event listeners for modal
        document.querySelector('.close-btn').addEventListener('click', closeModal);
        document.querySelector('.cancel-btn').addEventListener('click', closeModal);
        document.querySelector('.pay-btn').addEventListener('click', processPayment);

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    }

    // Close payment modal
    function closeModal() {
        const modal = document.getElementById('paymentModal');
        modal.style.display = 'none';
    }

    // Process payment (simulated)
    function processPayment() {
        const cardNumber = document.getElementById('cardNumber').value;
        const cardName = document.getElementById('cardName').value;
        const expiryDate = document.getElementById('expiryDate').value;
        const cvv = document.getElementById('cvv').value;

        // Basic validation
        if (!cardNumber || !cardName || !expiryDate || !cvv) {
            Swal.fire({
                title: 'Error!',
                text: 'Please fill in all payment details',
                icon: 'error',
                confirmButtonText: 'OK'
            });
            return;
        }

        // Simulate payment processing
        Swal.fire({
            title: 'Processing Payment...',
            text: 'Please wait while we process your payment',
            icon: 'info',
            showConfirmButton: false,
            allowOutsideClick: false,
            timer: 2000
        });

        // Simulate successful payment
        setTimeout(() => {
            Swal.fire({
                title: 'Success!',
                text: 'Payment successful! Your balance has been updated.',
                icon: 'success',
                confirmButtonText: 'Great!'
            }).then((result) => {
                if (result.isConfirmed) {
                    closeModal();
                    // Reload page to update balance
                    window.location.reload();
                }
            });
        }, 2000);
    }

    // Alert Function
    function showAlert(message, isError = false) {
        // Remove existing alerts
        const existingAlerts = document.querySelectorAll('.custom-alert');
        existingAlerts.forEach(alert => alert.remove());

        // Create alert element
        const alert = document.createElement('div');
        alert.className = 'custom-alert' + (isError ? ' error' : '');
        alert.style.cssText = `
            position: fixed;
            top: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(-20px);
            background: ${isError ? 'rgba(244,67,54,0.9)' : 'rgba(76,175,80,0.9)'};
            color: white;
            padding: 15px 30px;
            border-radius: 8px;
            z-index: 10000;
            transition: all 0.3s ease-in-out;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            font-family: Arial, sans-serif;
            font-size: 16px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            opacity: 0;
            max-width: 90%;
            text-align: center;
            cursor: pointer;
        `;

        alert.innerHTML = `
            <i class="fas ${isError ? 'fa-exclamation-circle' : 'fa-check-circle'}" style="font-size: 18px;"></i>
            ${message}
        `;

        document.body.appendChild(alert);

        // Animate in
        setTimeout(() => {
            alert.style.opacity = '1';
            alert.style.transform = 'translateX(-50%) translateY(0)';
        }, 100);

        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateX(-50%) translateY(-20px)';
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.parentNode.removeChild(alert);
                }
            }, 300);
        }, 3000);

        alert.addEventListener('click', function() {
            alert.style.opacity = '0';
            alert.style.transform = 'translateX(-50%) translateY(-20px)';
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.parentNode.removeChild(alert);
                }
            }, 300);
        });
    }

    // Message functionality
    document.addEventListener('DOMContentLoaded', function() {
        const messageModal = document.getElementById('messageModal');
        const openMessageModal = document.getElementById('openMessageModal');
        const closeMessageModal = document.getElementById('closeMessageModal');
        const cancelMessage = document.getElementById('cancelMessage');

        openMessageModal?.addEventListener('click', () => {
            messageModal.style.display = 'block';
            loadRecipients('teachers');
        });

        closeMessageModal?.addEventListener('click', () => {
            messageModal.style.display = 'none';
        });

        cancelMessage?.addEventListener('click', () => {
            messageModal.style.display = 'none';
        });

        // Type buttons
        document.querySelectorAll('.type-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const type = this.dataset.type;
                loadRecipients(type);
            });
        });

        // Send message
        document.getElementById('sendMessage')?.addEventListener('click', sendMessageFromModal);

        // Conversations
        document.querySelectorAll('.conversation-item').forEach(item => {
            item.addEventListener('click', function() {
                const email = this.dataset.email;
                const name = this.querySelector('.conversation-name').textContent;
                openConversation(email, name);
            });
        });

        document.getElementById('backToConversations')?.addEventListener('click', () => {
            document.getElementById('conversationsList').style.display = 'block';
            document.getElementById('conversationView').style.display = 'none';
        });

        document.getElementById('sendMessageBtn')?.addEventListener('click', sendMessageInConversation);
    });

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

    async function loadRecipients(type) {
        try {
            const response = await fetch(`get_recipients.php?type=${type}`);
            const recipients = await response.json();

            const container = document.getElementById('recipientsContainer');
            container.innerHTML = '';

            recipients.forEach(recipient => {
                const div = document.createElement('div');
                div.className = 'recipient-item';
                div.innerHTML = `
                    <input type="checkbox" id="recipient_${recipient.email}" value="${recipient.email}">
                    <label for="recipient_${recipient.email}">
                        <img src="${recipient.profileimage || 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'}"
                             alt="${recipient.firstname} ${recipient.lastname}">
                        <span>${recipient.firstname} ${recipient.lastname}</span>
                    </label>
                `;
                container.appendChild(div);
            });

            document.getElementById('selectAll').onclick = function() {
                container.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                    checkbox.checked = true;
                });
            };
        } catch (error) {
            console.error('Error loading recipients:', error);
        }
    }

    async function sendMessageFromModal() {
        const recipients = [];
        document.querySelectorAll('#recipientsContainer input:checked').forEach(checkbox => {
            recipients.push(checkbox.value);
        });

        const messageText = document.getElementById('messageText').value.trim();

        if (recipients.length === 0 || !messageText) {
            Swal.fire({
                title: 'Error!',
                text: 'Please select at least one recipient and enter a message.',
                icon: 'error',
                confirmButtonText: 'OK'
            });
            return;
        }

        try {
            const response = await fetch('send_message.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    recipients: recipients,
                    message: messageText
                })
            });

            if (response.ok) {
                document.getElementById('successMessage').style.display = 'block';
                document.getElementById('messageText').value = '';

                document.querySelectorAll('#recipientsContainer input:checked').forEach(checkbox => {
                    checkbox.checked = false;
                });

                setTimeout(() => {
                    document.getElementById('successMessage').style.display = 'none';
                    document.getElementById('messageModal').style.display = 'none';
                }, 2000);
            }
        } catch (error) {
            console.error('Error sending message:', error);
            Swal.fire({
                title: 'Error!',
                text: 'Error sending message. Please try again.',
                icon: 'error',
                confirmButtonText: 'OK'
            });
        }
    }

    async function openConversation(email, name) {
        document.getElementById('conversationsList').style.display = 'none';
        document.getElementById('conversationView').style.display = 'block';
        document.getElementById('conversationUserName').textContent = name;

        document.getElementById('conversationView').dataset.recipientEmail = email;

        await loadMessages(email);
    }

    async function loadMessages(recipientEmail) {
        try {
            const response = await fetch(`get_messages.php?recipient=${recipientEmail}`);
            const messages = await response.json();

            const container = document.getElementById('messagesContainer');
            container.innerHTML = '';

            messages.forEach(msg => {
                const isSender = msg.sender_email === '<?php echo $user_email; ?>';
                const messageDiv = document.createElement('div');
                messageDiv.className = `message ${isSender ? 'sent' : 'received'}`;
                messageDiv.innerHTML = `
                    <div class="message-content">${msg.message_text}</div>
                    <div class="message-time">${formatTime(msg.sent_at)}</div>
                `;
                container.appendChild(messageDiv);
            });

            container.scrollTop = container.scrollHeight;

            await fetch('mark_messages_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ recipient: recipientEmail })
            });
        } catch (error) {
            console.error('Error loading messages:', error);
        }
    }

    async function sendMessageInConversation() {
        const input = document.getElementById('messageInput');
        const message = input.value.trim();
        const recipientEmail = document.getElementById('conversationView').dataset.recipientEmail;

        if (!message) return;

        try {
            const response = await fetch('send_message.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    recipients: [recipientEmail],
                    message: message
                })
            });

            if (response.ok) {
                input.value = '';
                await loadMessages(recipientEmail);
            }
        } catch (error) {
            console.error('Error sending message:', error);
        }
    }

    function formatTime(timestamp) {
        const date = new Date(timestamp);
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    // Recharge Balance Functions
    function showRechargeModal() {
        const modal = document.getElementById('rechargeModal');
        modal.style.display = 'flex';

        // Clear previous inputs
        document.getElementById('rechargeAmount').value = '50';
        document.getElementById('rechargeCardNumber').value = '';
        document.getElementById('rechargeCardName').value = '';
        document.getElementById('rechargeExpiryDate').value = '';
        document.getElementById('rechargeCVV').value = '';

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeRechargeModal();
            }
        });
    }

    function closeRechargeModal() {
        const modal = document.getElementById('rechargeModal');
        modal.style.display = 'none';
    }

    async function processRecharge() {
        const amount = document.getElementById('rechargeAmount').value;
        const cardNumber = document.getElementById('rechargeCardNumber').value;
        const cardName = document.getElementById('rechargeCardName').value;
        const expiryDate = document.getElementById('rechargeExpiryDate').value;
        const cvv = document.getElementById('rechargeCVV').value;

        // Basic validation
        if (!amount || amount < 10 || amount > 1000) {
            Swal.fire({
                title: 'Error!',
                text: 'Please enter a valid amount between $10 and $1000',
                icon: 'error',
                confirmButtonText: 'OK'
            });
            return;
        }

        if (!cardNumber || !cardName || !expiryDate || !cvv) {
            Swal.fire({
                title: 'Error!',
                text: 'Please fill in all payment details',
                icon: 'error',
                confirmButtonText: 'OK'
            });
            return;
        }

        // Simulate payment processing
        Swal.fire({
            title: 'Processing Recharge...',
            text: 'Please wait while we process your payment',
            icon: 'info',
            showConfirmButton: false,
            allowOutsideClick: false,
            timer: 2000
        });

        // Simulate successful recharge
        setTimeout(async () => {
            try {
                const response = await fetch('recharge_balance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        amount: parseFloat(amount),
                        student_email: '<?php echo $user_email; ?>'
                    })
                });

                const data = await response.json();

                if (data.success) {
                    closeRechargeModal();

                    Swal.fire({
                        title: 'Success!',
                        text: data.message,
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        title: 'Error!',
                        text: data.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                }
            } catch (error) {
                Swal.fire({
                    title: 'Error!',
                    text: 'Failed to recharge balance. Please try again.',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            }
        }, 2000);
    }
</script>

<?php
// Function to display time ago
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

</body>
</html>