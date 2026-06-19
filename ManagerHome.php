<?php
session_start();


$manager_email = $_SESSION['email'];

$conn = new mysqli("localhost", "root", "", "edavora");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");


// التحقق من أن المستخدم مدير (نحتاج لإنشاء جدول managers)
$check_manager = $conn->prepare("
    SELECT email FROM teachers WHERE email = ? 
    UNION 
    SELECT ? as email FROM dual
    LIMIT 1
");
$check_manager->bind_param("ss", $manager_email, $manager_email);
$check_manager->execute();
$check_manager->store_result();

if ($check_manager->num_rows === 0) {

}
function sendManagerNotification($conn, $notification_text) {
    // Get all manager emails
    $manager_query = $conn->query("SELECT email FROM manager");

    if ($manager_query && $manager_query->num_rows > 0) {
        $stmt = $conn->prepare("INSERT INTO notifications (user_email, notification_text, is_read) VALUES (?, ?, 0)");

        while ($manager = $manager_query->fetch_assoc()) {
            $stmt->bind_param("ss", $manager['email'], $notification_text);
            $stmt->execute();
        }

        $stmt->close();
        return true;
    }

    return false;
}
$manager_image = $manager_data['profileimage'] ?? 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';

// 1. Student Course Registration Notification
function notifyStudentEnrollment($conn, $student_email, $course_id) {
    // Get student name
    $student_query = $conn->prepare("SELECT firstname, lastname FROM users WHERE email = ?");
    $student_query->bind_param("s", $student_email);
    $student_query->execute();
    $student_result = $student_query->get_result();
    $student = $student_result->fetch_assoc();
    $student_query->close();

    // Get course name
    $course_query = $conn->prepare("SELECT name FROM courses WHERE id = ?");
    $course_query->bind_param("i", $course_id);
    $course_query->execute();
    $course_result = $course_query->get_result();
    $course = $course_result->fetch_assoc();
    $course_query->close();

    if ($student && $course) {
        $student_name = $student['firstname'] . ' ' . $student['lastname'];
        $course_name = $course['name'];
        $notification = "Student {$student_name} has enrolled in the course '{$course_name}'";
        sendManagerNotification($conn, $notification);
    }
}

// 2. Student Absence Notification
function notifyStudentAbsence($conn, $student_email, $course_id, $lecture_date) {
    // Get student name
    $student_query = $conn->prepare("SELECT firstname, lastname FROM users WHERE email = ?");
    $student_query->bind_param("s", $student_email);
    $student_query->execute();
    $student_result = $student_query->get_result();
    $student = $student_result->fetch_assoc();
    $student_query->close();

    // Get course name
    $course_query = $conn->prepare("SELECT name FROM courses WHERE id = ?");
    $course_query->bind_param("i", $course_id);
    $course_query->execute();
    $course_result = $course_query->get_result();
    $course = $course_result->fetch_assoc();
    $course_query->close();

    // Count total absences for this student in this course
    $absence_query = $conn->prepare("
        SELECT COUNT(*) as total_absences 
        FROM attendance 
        WHERE student_email = ? AND course_id = ? AND attendance_status = 'A'
    ");
    $absence_query->bind_param("si", $student_email, $course_id);
    $absence_query->execute();
    $absence_result = $absence_query->get_result();
    $absence_data = $absence_result->fetch_assoc();
    $total_absences = $absence_data['total_absences'];
    $absence_query->close();

    if ($student && $course) {
        $student_name = $student['firstname'] . ' ' . $student['lastname'];
        $course_name = $course['name'];
        $notification = "Student {$student_name} was absent in course '{$course_name}'. Total absences: {$total_absences}";
        sendManagerNotification($conn, $notification);
    }
}

// 3. Course Status Change Notification
function notifyStatusChange($conn, $student_email, $course_id, $status) {
    // Get student name
    $student_query = $conn->prepare("SELECT firstname, lastname FROM users WHERE email = ?");
    $student_query->bind_param("s", $student_email);
    $student_query->execute();
    $student_result = $student_query->get_result();
    $student = $student_result->fetch_assoc();
    $student_query->close();

    // Get course name
    $course_query = $conn->prepare("SELECT name FROM courses WHERE id = ?");
    $course_query->bind_param("i", $course_id);
    $course_query->execute();
    $course_result = $course_query->get_result();
    $course = $course_result->fetch_assoc();
    $course_query->close();

    if ($student && $course) {
        $student_name = $student['firstname'] . ' ' . $student['lastname'];
        $course_name = $course['name'];
        $status_text = $status === 'Passed' ? 'passed' : 'failed';
        $notification = "Student {$student_name} has {$status_text} the course '{$course_name}'";
        sendManagerNotification($conn, $notification);
    }
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

// Helper function to compare old and new data
function getChangedFields($old_data, $new_data, $exclude_fields = ['email', 'password']) {
    $changed = [];

    foreach ($new_data as $key => $value) {
        if (in_array($key, $exclude_fields)) continue;

        if (isset($old_data[$key]) && $old_data[$key] != $value) {
            $changed[] = ucfirst(str_replace('_', ' ', $key));
        }
    }

    return $changed;
}
// =============================================
// 3. معالجة طلبات AJAX - إصلاح الكود
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {

            // وضع علامة على جميع الإشعارات كمقروءة
            case 'mark_all_notifications_read':
                $update_query = $conn->prepare("
                    UPDATE notifications 
                    SET is_read = 1 
                    WHERE user_email = ? AND is_read = 0
                ");
                $update_query->bind_param("s", $manager_email);

                if ($update_query->execute()) {
                    echo json_encode(['success' => true, 'message' => 'تم تحديث جميع الإشعارات']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء التحديث']);
                }
                $update_query->close();
                exit();

            // إرسال رسالة
            case 'send_message':
                if (!isset($_POST['recipient_email']) || !isset($_POST['message_text'])) {
                    echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
                    exit();
                }

                $recipient_email = trim($_POST['recipient_email']);
                $message_text = trim($_POST['message_text']);

                if (empty($recipient_email) || empty($message_text)) {
                    echo json_encode(['success' => false, 'message' => 'البيانات مطلوبة']);
                    exit();
                }

                // التحقق من وجود المستلم
                $check_user = $conn->prepare("SELECT email FROM users WHERE email = ?");
                $check_user->bind_param("s", $recipient_email);
                $check_user->execute();
                $check_user->store_result();

                if ($check_user->num_rows === 0) {
                    echo json_encode(['success' => false, 'message' => 'المستلم غير موجود']);
                    $check_user->close();
                    exit();
                }
                $check_user->close();

                // إرسال الرسالة
                $stmt = $conn->prepare("
                    INSERT INTO messages (user_email, sender_email, message_text) 
                    VALUES (?, ?, ?)
                ");
                $stmt->bind_param("sss", $recipient_email, $manager_email, $message_text);

                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'تم إرسال الرسالة بنجاح']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'خطأ في إرسال الرسالة: ' . $conn->error]);
                }
                $stmt->close();
                exit();

            // جلب رسائل محادثة محددة
            case 'get_conversation_messages':
                if (!isset($_POST['other_user_email'])) {
                    echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني مطلوب']);
                    exit();
                }

                $other_user_email = $_POST['other_user_email'];

                // جلب جميع الرسائل
                $messages_query = $conn->prepare("
                    SELECT m.message_id, m.message_text, m.sent_at, m.sender_email,
                           u.firstname, u.lastname, u.profileimage
                    FROM messages m
                    INNER JOIN users u ON m.sender_email = u.email
                    WHERE (m.user_email = ? AND m.sender_email = ?) 
                       OR (m.user_email = ? AND m.sender_email = ?)
                    ORDER BY m.sent_at ASC
                ");
                $messages_query->bind_param("ssss",
                        $manager_email, $other_user_email,
                        $other_user_email, $manager_email
                );

                if ($messages_query->execute()) {
                    $messages_result = $messages_query->get_result();

                    $messages = [];
                    while ($msg_row = $messages_result->fetch_assoc()) {
                        $messages[] = [
                                'id' => $msg_row['message_id'],
                                'text' => $msg_row['message_text'],
                                'time' => time_ago($msg_row['sent_at']),
                                'exact_time' => date('h:i A', strtotime($msg_row['sent_at'])),
                                'is_sender' => $msg_row['sender_email'] == $manager_email,
                                'sender_name' => $msg_row['firstname'] . ' ' . $msg_row['lastname'],
                                'sender_image' => $msg_row['profileimage'] ?: 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'
                        ];
                    }

                    // وضع علامة على الرسائل كمقروءة
                    $mark_read_query = $conn->prepare("
                        UPDATE messages 
                        SET is_read = 1 
                        WHERE user_email = ? 
                          AND sender_email = ? 
                          AND is_read = 0
                    ");
                    $mark_read_query->bind_param("ss", $manager_email, $other_user_email);
                    $mark_read_query->execute();
                    $mark_read_query->close();

                    echo json_encode(['success' => true, 'messages' => $messages]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'فشل في جلب الرسائل']);
                }

                $messages_query->close();
                exit();

            // إرسال رسالة جماعية
            case 'send_bulk_message':
                if (!isset($_POST['recipient_type']) || !isset($_POST['message_text'])) {
                    echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
                    exit();
                }

                $recipient_type = $_POST['recipient_type'];
                $message_text = trim($_POST['message_text']);
                $selected_users = isset($_POST['selected_users']) ? json_decode($_POST['selected_users'], true) : [];

                if (empty($message_text)) {
                    echo json_encode(['success' => false, 'message' => 'نص الرسالة مطلوب']);
                    exit();
                }

                $success_count = 0;
                $error_count = 0;
                $errors = [];

                if ($recipient_type == 'all') {
                    // إرسال للجميع حسب النوع
                    if ($_POST['type'] == 'teachers') {
                        $users_query = $conn->query("SELECT email FROM teachers");
                    } else {
                        $users_query = $conn->query("SELECT email FROM students");
                    }

                    if ($users_query) {
                        while ($user = $users_query->fetch_assoc()) {
                            $stmt = $conn->prepare("INSERT INTO messages (user_email, sender_email, message_text) VALUES (?, ?, ?)");
                            $stmt->bind_param("sss", $user['email'], $manager_email, $message_text);

                            if ($stmt->execute()) {
                                $success_count++;
                            } else {
                                $error_count++;
                                $errors[] = $user['email'] . ': ' . $conn->error;
                            }
                            $stmt->close();
                        }
                    }
                } else {
                    // إرسال للمستخدمين المحددين
                    if (!empty($selected_users)) {
                        foreach ($selected_users as $user_email) {
                            $stmt = $conn->prepare("INSERT INTO messages (user_email, sender_email, message_text) VALUES (?, ?, ?)");
                            $stmt->bind_param("sss", $user_email, $manager_email, $message_text);

                            if ($stmt->execute()) {
                                $success_count++;
                            } else {
                                $error_count++;
                                $errors[] = $user_email . ': ' . $conn->error;
                            }
                            $stmt->close();
                        }
                    }
                }

                $response = [
                        'success' => $success_count > 0,
                        'message' => "تم إرسال الرسالة إلى $success_count مستخدم" .
                                ($error_count > 0 ? "، فشل إرسال $error_count" : "")
                ];

                if (!empty($errors)) {
                    $response['errors'] = $errors;
                }

                echo json_encode($response);
                exit();

            // حفظ الصور
            case 'save_photos':
                if (!isset($_POST['photos'])) {
                    echo json_encode(['success' => false, 'message' => 'لا توجد صور']);
                    exit();
                }

                $photos = json_decode($_POST['photos'], true);

                // مسح البيانات القديمة
                $conn->query("DELETE FROM gallery");

                // إضافة الصور الجديدة
                if (is_array($photos)) {
                    foreach ($photos as $photo) {
                        $stmt = $conn->prepare("INSERT INTO gallery (image_url, title, description) VALUES (?, ?, ?)");
                        $stmt->bind_param("sss", $photo['image'], $photo['title'], $photo['description']);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                echo json_encode(['success' => true, 'message' => 'تم حفظ الصور بنجاح']);
                exit();

            // حفظ وصف الدورة
            case 'save_description':
                if (!isset($_POST['description'])) {
                    echo json_encode(['success' => false, 'message' => 'الوصف مطلوب']);
                    exit();
                }

                $description = $_POST['description'];

                // التحقق من وجود السجل
                $check = $conn->query("SELECT id FROM course_info WHERE id = 1");

                if ($check && $check->num_rows > 0) {
                    $stmt = $conn->prepare("UPDATE course_info SET course_description = ? WHERE id = 1");
                } else {
                    $stmt = $conn->prepare("INSERT INTO course_info (id, course_description) VALUES (1, ?)");
                }

                $stmt->bind_param("s", $description);

                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'تم حفظ وصف الدورة بنجاح']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'خطأ في حفظ وصف الدورة']);
                }
                $stmt->close();
                exit();

            // حفظ الإحصائيات
            case 'save_statistics':
                $students = isset($_POST['students']) ? (int)$_POST['students'] : 0;
                $graduates = isset($_POST['graduates']) ? (int)$_POST['graduates'] : 0;
                $courses = isset($_POST['courses']) ? (int)$_POST['courses'] : 0;
                $awards = isset($_POST['awards']) ? (int)$_POST['awards'] : 0;

                // التحقق من وجود السجل
                $check = $conn->query("SELECT id FROM statistics WHERE id = 1");

                if ($check && $check->num_rows > 0) {
                    $stmt = $conn->prepare("UPDATE statistics SET students_count = ?, graduates_count = ?, courses_count = ?, awards_count = ? WHERE id = 1");
                } else {
                    $stmt = $conn->prepare("INSERT INTO statistics (id, students_count, graduates_count, courses_count, awards_count) VALUES (1, ?, ?, ?, ?)");
                }

                $stmt->bind_param("iiii", $students, $graduates, $courses, $awards);

                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'تم حفظ الإحصائيات بنجاح']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'خطأ في حفظ الإحصائيات']);
                }
                $stmt->close();
                exit();

            // حذف صورة
            case 'delete_photo':
                if (!isset($_POST['photo_id'])) {
                    echo json_encode(['success' => false, 'message' => 'معرف الصورة مطلوب']);
                    exit();
                }

                $photo_id = (int)$_POST['photo_id'];
                $stmt = $conn->prepare("DELETE FROM gallery WHERE id = ?");
                $stmt->bind_param("i", $photo_id);

                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'تم حذف الصورة']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'خطأ في حذف الصورة']);
                }
                $stmt->close();
                exit();

            // تحديث الإحصائيات الحقيقية
            case 'refresh_stats':
                $stats = calculateRealStats($conn);
                echo json_encode(['success' => true, 'stats' => $stats]);
                exit();
        }
    }

    // معالجة رفع صورة جديدة
    if (isset($_FILES['new_image']) && $_FILES['new_image']['error'] === 0) {
        $title = $_POST['image_title'] ?? 'New Image';
        $description = $_POST['image_description'] ?? '';

        // رفع الصورة
        $target_dir = "uploads/gallery/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }

        $ext = strtolower(pathinfo($_FILES["new_image"]["name"], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($ext, $allowed)) {
            $new_filename = uniqid("gallery_") . "." . $ext;
            $target_file = $target_dir . $new_filename;

            if (move_uploaded_file($_FILES["new_image"]["tmp_name"], $target_file)) {
                $stmt = $conn->prepare("INSERT INTO gallery (image_url, title, description) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $target_file, $title, $description);

                if ($stmt->execute()) {
                    $new_id = $stmt->insert_id;

                    echo json_encode([
                            'success' => true,
                            'message' => 'تم رفع الصورة بنجاح',
                            'image_url' => $target_file,
                            'id' => $new_id
                    ]);
                } else {
                    echo json_encode([
                            'success' => false,
                            'message' => 'خطأ في حفظ الصورة في قاعدة البيانات'
                    ]);
                }
                $stmt->close();
            } else {
                echo json_encode([
                        'success' => false,
                        'message' => 'خطأ في رفع الصورة'
                ]);
            }
        } else {
            echo json_encode([
                    'success' => false,
                    'message' => 'نوع الملف غير مسموح به'
            ]);
        }
        exit();
    }
}

// =============================================
// 4. دالة مساعدة لتحويل الوقت
// =============================================
function time_ago($datetime) {
    if (empty($datetime)) return 'Just now';

    $time = strtotime($datetime);
    if ($time === false) return 'Just now';

    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $time);
    }
}

// =============================================
// 5. دالة حساب الإحصائيات الحقيقية
// =============================================
function calculateRealStats($conn) {
    $stats = [
            'students' => 0,
            'graduates' => 0,
            'courses' => 0,
            'awards' => 0
    ];

    // عدد الطلاب المسجلين
    $result = $conn->query("SELECT COUNT(*) as count FROM students");
    if ($result && $row = $result->fetch_assoc()) {
        $stats['students'] = (int)$row['count'];
    }

    // عدد الخريجين
    $result = $conn->query("
        SELECT COUNT(DISTINCT student_email) as count 
        FROM studentcoursestatus 
        WHERE status = 'Passed'
    ");
    if ($result && $row = $result->fetch_assoc()) {
        $stats['graduates'] = (int)$row['count'];
    }

    // عدد الدورات
    $result = $conn->query("SELECT COUNT(*) as count FROM courses");
    if ($result && $row = $result->fetch_assoc()) {
        $stats['courses'] = (int)$row['count'];
    }

    // عدد الجوائز
    $result = $conn->query("SELECT SUM(AwardsAchievements) as total FROM gallery");
    if ($result && $row = $result->fetch_assoc()) {
        $stats['awards'] = $row['total'] ? (int)$row['total'] : 0;
    }

    return $stats;
}

// =============================================
// 6. جلب البيانات من قاعدة البيانات
// =============================================

// جلب الصور
$photos = [];
$gallery_result = $conn->query("SELECT * FROM gallery WHERE image_url IS NOT NULL ORDER BY id");
if ($gallery_result) {
    while ($row = $gallery_result->fetch_assoc()) {
        $photos[] = [
                'id' => $row['id'],
                'image' => $row['image_url'],
                'title' => $row['title'],
                'description' => $row['description']
        ];
    }
}

// إذا لم توجد صور، استخدم الصور الافتراضية
if (empty($photos)) {
    $photos = [
            [
                    'id' => 1,
                    'image' => "https://images.unsplash.com/photo-1555066931-4365d14bab8c?ixlib=rb-1.2.1&auto=format&fit=crop&w=1000&q=80",
                    'title' => "Advanced Web Development Course",
                    'description' => "Learn the latest web development technologies including React, Node.js, and databases"
            ],
            [
                    'id' => 2,
                    'image' => "https://images.unsplash.com/photo-1555949963-aa79dcee981c?ixlib=rb-1.2.1&auto=format&fit=crop&w=1000&q=80",
                    'title' => "Data Science and Analysis Course",
                    'description' => "Master data analysis using Python, machine learning, and data visualization tools"
            ],
            [
                    'id' => 3,
                    'image' => "https://images.unsplash.com/photo-1544890225-2f3faec4cd60?ixlib=rb-1.2.1&auto=format&fit=crop&w=1000&q=80",
                    'title' => "Comprehensive Cybersecurity Course",
                    'description' => "Learn cybersecurity fundamentals, network protection, and vulnerability detection"
            ],
            [
                    'id' => 4,
                    'image' => "https://images.unsplash.com/photo-1561070791-2526d30994b5?ixlib=rb-1.2.1&auto=format&fit=crop&w=1000&q=80",
                    'title' => "Graphic Design and Creativity Course",
                    'description' => "Master Adobe Photoshop, Illustrator, and InDesign. Learn design fundamentals and visual creativity"
            ]
    ];
}

// جلب وصف الدورة
$course_description = "Welcome to EDVORA Educational Academy\n\nWe offer a distinguished collection of training courses specifically designed to meet the needs of the modern job market.\n\n🎯 Our Goals:\n• Develop technical and practical skills\n• Keep up with the latest technologies and methodologies\n• Prepare students for the competitive job market\n\n📚 Available Courses:\nYou can browse the images above to learn about the various courses we offer, including:\n• Web and Application Development\n• Data Science and Artificial Intelligence\n• Cybersecurity\n• Graphic Design\n\n📞 Contact Us:\nFor inquiries and registration, please contact us via email or phone.";

$desc_result = $conn->query("SELECT course_description FROM course_info WHERE id = 1");
if ($desc_result && $row = $desc_result->fetch_assoc()) {
    $course_description = $row['course_description'];
}

// جلب الإحصائيات الحقيقية
$stats = calculateRealStats($conn);

// جلب بيانات المستخدم
$user_info = [];
$user_result = $conn->prepare("SELECT firstname, lastname, profileimage FROM users WHERE email = ?");
$user_result->bind_param("s", $manager_email);
$user_result->execute();
$firstname="";
$lastname="";
$profileimage="";
$user_result->bind_result($firstname, $lastname, $profileimage);
if ($user_result->fetch()) {
    $user_info = [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'profileimage' => $profileimage
    ];
}
$user_result->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDVORA - Manager Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/head.css">
    <link rel="stylesheet" href="css/ManagerHome.css">
    <link rel="stylesheet" href="css/footor.css">
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

        /* Success Message */
        .success-message {
            background: rgba(76, 175, 80, 0.38);
            color: #2e7d32;
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 15px;
            display: none;
            font-weight: 500;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .success-message.show {
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeInOut 3s ease-in-out;
        }

        .success-message i {
            font-size: 18px;
        }

        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(-10px); }
            10% { opacity: 1; transform: translateY(0); }
            90% { opacity: 1; transform: translateY(0); }
            100% { opacity: 0; transform: translateY(-10px); }
        }

        /* SweetAlert2 Customizations */
        .swal2-popup {
            font-family: inherit;
        }

        .swal2-input, .swal2-textarea {
            border: 1px solid #ddd !important;
            border-radius: 5px !important;
            font-size: 1rem !important;
        }

        .swal2-input:focus, .swal2-textarea:focus {
            border-color: #4a00e0 !important;
            box-shadow: 0 0 0 1px rgba(74, 0, 224, 0.2) !important;
        }

        .swal2-confirm {
            background: linear-gradient(to right, #4a00e0, #8e2de2) !important;
        }

        /* Text Editor Styles */
        .text-editor-content {
            min-height: 200px;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: white;
            line-height: 1.6;
            width: 100%;
            font-family: inherit;
            font-size: 16px;
        }

        .text-editor-content:focus {
            outline: none;
            border-color: #4a00e0;
            box-shadow: 0 0 0 2px rgba(74, 0, 224, 0.1);
        }

        /* Smooth scrolling */
        html {
            scroll-behavior: smooth;
        }

        /* Stats Section */
        .refresh-btn {
            background: linear-gradient(to right, #4a00e0, #8e2de2);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-left: 10px;
        }

        .refresh-btn:hover {
            opacity: 0.9;
        }

        /* Message Modal */
        .message-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .message-modal.active {
            display: flex;
        }

        .message-modal-content {
            background: white;
            width: 90%;
            max-width: 500px;
            border-radius: 10px;
            overflow: hidden;
        }

        .message-modal-header {
            padding: 15px 20px;
            background: #4a00e0;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .message-modal-body {
            padding: 20px;
            max-height: 400px;
            overflow-y: auto;
        }

        .message-modal-footer {
            padding: 15px 20px;
            background: #f5f5f5;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
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
            <a href="ManagerHome.php" class="active">Home</a>
            <a href="creat_TSC.php">Create</a>
            <a href="show_TSC.php">Show</a>
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
                <img src="<?php echo htmlspecialchars($user_info['profileimage'] ?? 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'); ?>" alt="User">
                <span class="username">
                    <?php echo htmlspecialchars(($user_info['firstname'] ?? 'Manager') . ' ' . ($user_info['lastname'] ?? 'User')); ?>
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

<section class="slider-section">
    <h2 class="section-title">Course Gallery</h2>
    <div class="slider-container">
        <div class="main-slider" id="mainSlider">
            <!-- Slides will be added dynamically -->
        </div>

        <button class="slider-nav prev" id="prevBtn">
            <i class="fas fa-chevron-left"></i>
        </button>
        <button class="slider-nav next" id="nextBtn">
            <i class="fas fa-chevron-right"></i>
        </button>
    </div>

    <!-- Thumbnails -->
    <div class="thumbnail-container" id="thumbnailContainer">
        <!-- Thumbnails will be added dynamically -->
    </div>
</section>

<!-- Text Editor Section -->
<section class="text-editor-section">
    <div class="text-editor-header">
        <h2>Course Description</h2>
        <div class="editor-tools">
            <button class="save-btn" onclick="saveText()">
                <i class="fas fa-save"></i> Save Text
            </button>
        </div>
    </div>
    <textarea class="text-editor-content" id="textContent" rows="15" wrap="soft">
        <?php echo htmlspecialchars($course_description); ?>
    </textarea>
    <div class="success-message" id="textSuccessMessage">
        <i class="fas fa-check-circle"></i> Text saved successfully!
    </div>
</section>

<!-- Statistics Section -->
<section class="stats-section">
    <h2 class="section-title">
        <span>Academy Statistics</span>
    </h2>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-number" id="studentsCount">
                <?php echo number_format($stats['students'] ?? 0); ?>
            </div>
            <div class="stat-label">Total Registered Students</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="stat-number" id="graduatesCount">
                <?php echo number_format($stats['graduates'] ?? 0); ?>
            </div>
            <div class="stat-label">Successful Graduates</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <div class="stat-number" id="coursesCount">
                <?php echo number_format($stats['courses'] ?? 0); ?>
            </div>
            <div class="stat-label">Available Courses</div>
        </div>

    <div class="success-message" id="statsSuccessMessage">
        <i class="fas fa-check-circle"></i> Statistics refreshed successfully!
    </div>
</section>

<!-- Modal for Full Image View -->
<div class="modal" id="imageModal">
    <div class="modal-content">
        <button class="close-modal" id="closeModal">
            <i class="fas fa-times"></i>
        </button>
        <img id="modalImage" src="" alt="">
    </div>
</div>

<!-- Image Upload Input -->
<input type="file" id="imageUpload" accept="image/*" multiple style="display: none;">

<!-- Alert Message -->
<div class="alert" id="alert"></div>

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
    // بيانات الصور من PHP
    let photos = <?php echo json_encode($photos); ?>;
    let currentSlide = 0;
    let photoToDelete = null;
    let currentConversationUser = null;
    let currentRecipientType = 'teachers';

    // DOM Elements
    const mainSlider = document.getElementById('mainSlider');
    const thumbnailContainer = document.getElementById('thumbnailContainer');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const imageUpload = document.getElementById('imageUpload');
    const imageModal = document.getElementById('imageModal');
    const modalImage = document.getElementById('modalImage');
    const closeModal = document.getElementById('closeModal');
    const textContent = document.getElementById('textContent');
    const textSuccessMessage = document.getElementById('textSuccessMessage');
    const statsSuccessMessage = document.getElementById('statsSuccessMessage');
    const messageModal = document.getElementById('messageModal');

    // Initialize the page
    document.addEventListener('DOMContentLoaded', function() {
        loadSlider();
        setupEventListeners();
    });

    // Load slider and thumbnails
    function loadSlider() {
        mainSlider.innerHTML = '';
        thumbnailContainer.innerHTML = '';

        // Add add button first in thumbnails
        const addThumbnail = document.createElement('div');
        addThumbnail.className = 'add-thumbnail';
        addThumbnail.innerHTML = '<i class="fas fa-plus"></i>';
        addThumbnail.addEventListener('click', () => imageUpload.click());
        thumbnailContainer.appendChild(addThumbnail);

        photos.forEach((photo, index) => {
            // Add main slide
            const slide = document.createElement('div');
            slide.className = `slide ${index === 0 ? 'active' : ''}`;
            slide.innerHTML = `
                <img src="${photo.image}" alt="${photo.title}" onclick="openModal('${photo.image}')">
                <div class="slide-content">
                    <div class="slide-title">${photo.title}</div>
                    <div class="slide-description">${photo.description}</div>
                </div>
            `;
            mainSlider.appendChild(slide);

            // Add thumbnail
            const thumbnail = document.createElement('div');
            thumbnail.className = `thumbnail ${index === 0 ? 'active' : ''}`;
            thumbnail.innerHTML = `
                <img src="${photo.image}" alt="${photo.title}" onclick="goToSlide(${index})">
                <button class="delete-thumbnail" onclick="showDeleteConfirmation(${photo.id})">
                    <i class="fas fa-times"></i>
                </button>
            `;
            thumbnailContainer.appendChild(thumbnail);
        });
    }

    // Set up event listeners
    function setupEventListeners() {
        prevBtn.addEventListener('click', prevSlide);
        nextBtn.addEventListener('click', nextSlide);
        closeModal.addEventListener('click', closeImageModal);
        imageUpload.addEventListener('change', handleImageUpload);
        imageModal.addEventListener('click', function(e) {
            if (e.target === this) closeImageModal();
        });
    }

    // Upload image to database
    function uploadImageToDatabase(file, title, description) {
        const formData = new FormData();
        formData.append('new_image', file);
        formData.append('image_title', title);
        formData.append('image_description', description);

        return fetch('ManagerHome.php', {
            method: 'POST',
            body: formData
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            });
    }

    // Handle image upload
    function handleImageUpload(event) {
        const files = event.target.files;
        if (files.length === 0) return;

        const file = files[0];
        if (!file.type.match('image.*')) {
            Swal.fire('Error', 'The selected file is not an image!', 'error');
            event.target.value = '';
            return;
        }

        // Show preview and get data
        const reader = new FileReader();
        reader.onload = function(e) {
            Swal.fire({
                title: 'Add New Image',
                html: `
                    <div style="text-align: left;">
                        <div style="margin-bottom: 15px; text-align: center;">
                            <img src="${e.target.result}" style="width: 100%; max-height: 200px; object-fit: cover; border-radius: 5px; border: 1px solid #ddd;">
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500; color: #333;">Image Title *</label>
                            <input id="swalTitle" type="text" class="swal2-input" placeholder="Enter image title" value="New Image" required>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500; color: #333;">Image Description *</label>
                            <textarea id="swalDescription" class="swal2-textarea" placeholder="Enter image description" rows="3" required></textarea>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Upload Image',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#4a00e0',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    const title = document.getElementById('swalTitle').value.trim();
                    const description = document.getElementById('swalDescription').value.trim();

                    if (!title) {
                        Swal.showValidationMessage('Please enter an image title');
                        return false;
                    }

                    if (!description) {
                        Swal.showValidationMessage('Please enter an image description');
                        return false;
                    }

                    return uploadImageToDatabase(file, title, description);
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    const response = result.value;
                    if (response.success) {
                        // Add the new photo to local array
                        photos.push({
                            id: response.id || photos.length + 1,
                            image: response.image_url || URL.createObjectURL(file),
                            title: document.getElementById('swalTitle').value.trim(),
                            description: document.getElementById('swalDescription').value.trim()
                        });

                        loadSlider();
                        Swal.fire('Success!', 'Image added successfully', 'success');
                    } else {
                        Swal.fire('Error!', response.message || 'Failed to upload image', 'error');
                    }
                }
            }).finally(() => {
                // Reset upload field
                event.target.value = '';
            });
        };
        reader.readAsDataURL(file);
    }

    // Show delete confirmation modal
    function showDeleteConfirmation(photoId) {
        photoToDelete = photoId;
        Swal.fire({
            title: 'Delete Image?',
            text: "Are you sure you want to delete this image? This action cannot be undone.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ff6b6b',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                deleteConfirmedPhoto();
            }
        });
    }

    // Delete confirmed photo from database
    function deleteConfirmedPhoto() {
        if (photos.length <= 1) {
            Swal.fire('Error', 'Cannot delete all photos! At least one photo must remain.', 'error');
            return;
        }

        fetch('ManagerHome.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=delete_photo&photo_id=${photoToDelete}`
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    photos = photos.filter(photo => photo.id !== photoToDelete);

                    if (currentSlide >= photos.length) {
                        currentSlide = photos.length - 1;
                    }

                    loadSlider();
                    Swal.fire('Deleted!', 'Image has been deleted.', 'success');
                } else {
                    Swal.fire('Error!', 'Failed to delete image.', 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error!', 'Failed to delete image.', 'error');
            });
    }

    // Go to specific slide
    function goToSlide(index) {
        currentSlide = index;
        updateSlider();
    }

    // Update slider
    function updateSlider() {
        const slides = document.querySelectorAll('.slide');
        const thumbnails = document.querySelectorAll('.thumbnail');

        slides.forEach((slide, index) => {
            slide.classList.toggle('active', index === currentSlide);
        });

        thumbnails.forEach((thumbnail, index) => {
            thumbnail.classList.toggle('active', index === currentSlide);
        });
    }

    // Next slide
    function nextSlide() {
        currentSlide = (currentSlide + 1) % photos.length;
        updateSlider();
    }

    // Previous slide
    function prevSlide() {
        currentSlide = (currentSlide - 1 + photos.length) % photos.length;
        updateSlider();
    }

    // Open image in full view
    function openModal(imageSrc) {
        modalImage.src = imageSrc;
        imageModal.classList.add('active');
    }

    // Close full view
    function closeImageModal() {
        imageModal.classList.remove('active');
    }

    // Save text to database
    function saveText() {
        const description = document.getElementById('textContent').value;

        Swal.fire({
            title: 'Saving...',
            text: 'Please wait while saving your changes',
            allowOutsideClick: false,
            showConfirmButton: false,
            willOpen: () => {
                Swal.showLoading();
            }
        });

        fetch('ManagerHome.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=save_description&description=${encodeURIComponent(description)}`
        })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    textSuccessMessage.classList.add('show');

                    setTimeout(() => {
                        textSuccessMessage.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    }, 100);

                    setTimeout(() => {
                        textSuccessMessage.classList.remove('show');
                    }, 3000);

                    Swal.fire({
                        icon: 'success',
                        title: 'Saved!',
                        text: 'Course description has been saved successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: data.message || 'Failed to save text'
                    });
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Failed to save: ' + error.message
                });
            });
    }

    // Refresh statistics
    function refreshStats() {
        Swal.fire({
            title: 'Refreshing...',
            text: 'Please wait while we update statistics',
            allowOutsideClick: false,
            showConfirmButton: false,
            willOpen: () => {
                Swal.showLoading();
            }
        });

        fetch('ManagerHome.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=refresh_stats'
        })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    // Update numbers
                    document.getElementById('studentsCount').textContent =
                        data.stats.students.toLocaleString();
                    document.getElementById('graduatesCount').textContent =
                        data.stats.graduates.toLocaleString();
                    document.getElementById('coursesCount').textContent =
                        data.stats.courses.toLocaleString();
                    document.getElementById('awardsCount').textContent =
                        data.stats.awards.toLocaleString();

                    statsSuccessMessage.classList.add('show');
                    setTimeout(() => {
                        statsSuccessMessage.classList.remove('show');
                    }, 3000);

                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: 'Statistics have been refreshed.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Failed to refresh statistics'
                    });
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Failed to refresh statistics'
                });
            });
    }

    // =============================================
    // Messaging Functions
    // =============================================

    function openMessageModal() {
        messageModal.classList.add('active');
        loadRecipients('teachers');
    }

    function closeMessageModal() {
        messageModal.classList.remove('active');
    }

    function changeRecipientType(type) {
        currentRecipientType = type;
        document.querySelectorAll('.type-btn').forEach(btn => {
            btn.classList.remove('active');
            if (btn.dataset.type === type) {
                btn.classList.add('active');
            }
        });
        loadRecipients(type);
    }

    function loadRecipients(type) {
        fetch(`get_recipients_manegar.php?type=${type}`)
            .then(response => response.json())
            .then(data => {
                const container = document.getElementById('recipientsContainer');
                container.innerHTML = '';

                if (data.success && data.users.length > 0) {
                    data.users.forEach(user => {
                        const div = document.createElement('div');
                        div.className = 'recipient-item';
                        div.innerHTML = `
                            <input type="checkbox" id="user_${user.email}" value="${user.email}">
                            <label for="user_${user.email}">
                                <img src="${user.profileimage}" alt="${user.name}">
                                <span>${user.name}</span>
                            </label>
                        `;
                        container.appendChild(div);
                    });
                } else {
                    container.innerHTML = '<div style="text-align: center; color: #888; padding: 20px;">No users found</div>';
                }
            });
    }

    function selectAllRecipients() {
        document.querySelectorAll('#recipientsContainer input[type="checkbox"]').forEach(checkbox => {
            checkbox.checked = true;
        });
    }

    function sendBulkMessage() {
        const messageText = document.getElementById('messageText').value.trim();
        if (!messageText) {
            Swal.fire('Error', 'Please enter a message', 'error');
            return;
        }

        const selectedUsers = [];
        document.querySelectorAll('#recipientsContainer input[type="checkbox"]:checked').forEach(checkbox => {
            selectedUsers.push(checkbox.value);
        });

        if (selectedUsers.length === 0) {
            Swal.fire('Error', 'Please select at least one recipient', 'error');
            return;
        }

        Swal.fire({
            title: 'Sending...',
            text: 'Please wait while we send your message',
            allowOutsideClick: false,
            showConfirmButton: false,
            willOpen: () => {
                Swal.showLoading();
            }
        });

        fetch('ManagerHome.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=send_bulk_message&recipient_type=selected&selected_users=${JSON.stringify(selectedUsers)}&message_text=${encodeURIComponent(messageText)}`
        })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    document.getElementById('successMessage').classList.add('show');
                    document.getElementById('messageText').value = '';

                    setTimeout(() => {
                        document.getElementById('successMessage').classList.remove('show');
                        closeMessageModal();
                    }, 2000);

                    Swal.fire({
                        icon: 'success',
                        title: 'Sent!',
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: data.message || 'Failed to send message'
                    });
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Failed to send message: ' + error.message
                });
            });
    }

    function markAllNotificationsRead() {
        fetch('ManagerHome.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=mark_all_notifications_read'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.notification-badge, .notification-dot').forEach(el => {
                        el.remove();
                    });
                    document.querySelectorAll('.notification-item').forEach(item => {
                        item.classList.remove('unread');
                    });
                }
            });
    }

    function openConversation(userEmail, userName) {
        currentConversationUser = userEmail;
        document.getElementById('conversationUserName').textContent = userName;
        document.getElementById('conversationsList').style.display = 'none';
        document.getElementById('conversationView').style.display = 'block';

        loadConversationMessages(userEmail);
    }

    function backToConversations() {
        document.getElementById('conversationView').style.display = 'none';
        document.getElementById('conversationsList').style.display = 'block';
        currentConversationUser = null;
    }

    function loadConversationMessages(userEmail) {
        fetch('ManagerHome.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=get_conversation_messages&other_user_email=${encodeURIComponent(userEmail)}`
        })
            .then(response => response.json())
            .then(data => {
                const container = document.getElementById('messagesContainer');
                container.innerHTML = '';

                if (data.success && data.messages.length > 0) {
                    data.messages.forEach(msg => {
                        const messageDiv = document.createElement('div');
                        messageDiv.className = `message ${msg.is_sender ? 'sent' : 'received'}`;
                        messageDiv.innerHTML = `
                            <div class="message-content">${msg.text}</div>
                            <div class="message-time">${msg.time}</div>
                        `;
                        container.appendChild(messageDiv);
                    });
                    container.scrollTop = container.scrollHeight;
                }
            });
    }

    function sendMessage() {
        const input = document.getElementById('messageInput');
        const message = input.value.trim();

        if (!message || !currentConversationUser) return;

        fetch('ManagerHome.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=send_message&recipient_email=${encodeURIComponent(currentConversationUser)}&message_text=${encodeURIComponent(message)}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    input.value = '';
                    loadConversationMessages(currentConversationUser);
                }
            });
    }
</script>

</body>
</html>