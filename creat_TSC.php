<?php
session_start();

// التحقق من تسجيل الدخول كمدير
if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

$manager_email = $_SESSION['email'];

// الاتصال بالداتابيز
$conn = new mysqli("localhost", "root", "", "edavora");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// =============================
// 1. معالجة إنشاء معلم
// =============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_teacher') {
    $firstname = trim($_POST['firstname']);
    $lastname  = trim($_POST['lastname']);
    $birthdate = $_POST['birthdate'];
    $gender    = $_POST['gendert'];
    $email     = trim($_POST['email']);
    $password  = $_POST['password'];
    $salary    = $_POST['salary'];
    $job_title = trim($_POST['job_title']);

    // تحقق من العمر (يجب أن يكون 18 سنة على الأقل)
    $birthDateObj = new DateTime($birthdate);
    $today = new DateTime();
    $age = $today->diff($birthDateObj)->y;

    if ($age < 18) {
        echo json_encode([
                "success" => false,
                "message" => "Teacher must be at least 18 years old.",
                "field" => "birthdate"
        ]);
        exit;
    }

    $conn->begin_transaction();
    try {
        $stmt1 = $conn->prepare("INSERT INTO users (firstname, lastname, birthdate, gender, email, password) VALUES (?, ?, ?, ?, ?, ?)");
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt1->bind_param("ssssss", $firstname, $lastname, $birthdate, $gender, $email, $hashed_password);
        $stmt1->execute();

        $stmt2 = $conn->prepare("INSERT INTO teachers (email, job_title, salary) VALUES (?, ?, ?)");
        $stmt2->bind_param("ssd", $email, $job_title, $salary);
        $stmt2->execute();

        $conn->commit();
        echo json_encode(["success" => true, "message" => "Teacher created successfully!"]);
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ($e->getCode() === 1062) {
            echo json_encode(["success" => false, "duplicate_email" => true, "message" => "This email is already registered. Please use a unique email."]);
        } else {
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
    }
    $conn->close();
    exit;
}

// =============================
// 2. معالجة إنشاء طالب
// =============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_student') {
    $firstname = trim($_POST['firstname']);
    $lastname  = trim($_POST['lastname']);
    $birthdate = $_POST['birthdate'];
    $gender    = $_POST['gender'];
    $email     = trim($_POST['email']);
    $password  = $_POST['password'];

    $conn->begin_transaction();
    try {
        $stmt1 = $conn->prepare("INSERT INTO users (firstname, lastname, birthdate, gender, email, password) VALUES (?, ?, ?, ?, ?, ?)");
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt1->bind_param("ssssss", $firstname, $lastname, $birthdate, $gender, $email, $hashed_password);        $stmt1->execute();

        $stmt2 = $conn->prepare("INSERT INTO students (email, balance) VALUES (?, 150)");
        $stmt2->bind_param("s", $email);
        $stmt2->execute();

        $conn->commit();
        echo json_encode(["success" => true, "message" => "Student created successfully!"]);
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ($e->getCode() === 1062) {
            echo json_encode(["success" => false, "duplicate_email" => true, "message" => "This email is already registered. Please use a unique email."]);
        } else {
            echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
        }
    }
    $conn->close();
    exit;
}

// =============================
// 3. معالجة إنشاء كورس
// =============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_course') {
    error_log("=== COURSE SUBMISSION START ===");

    try {
        // الحصول على البيانات
        $name = isset($_POST['courseName']) ? trim($_POST['courseName']) : '';
        $start_time = isset($_POST['start_time']) ? $_POST['start_time'] : '';
        $end_time = isset($_POST['end_time']) ? $_POST['end_time'] : '';
        $teacher = isset($_POST['teacher']) ? trim($_POST['teacher']) : '';
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
        $max_students = isset($_POST['max_students']) ? intval($_POST['max_students']) : 0;
        $duration_number = isset($_POST['duration_number']) ? intval($_POST['duration_number']) : null;
        $duration_unit = isset($_POST['duration_unit']) ? $_POST['duration_unit'] : null;
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        // جمع الأيام
        $days = '';
        if (isset($_POST['days']) && is_array($_POST['days'])) {
            $days = implode(",", $_POST['days']);
        }

        // التحقق الأساسي
        if (empty($name)) {
            echo json_encode(["success" => false, "message" => "Course name is required."]);
            exit;
        }

        if (empty($teacher)) {
            echo json_encode(["success" => false, "message" => "Please select a teacher."]);
            exit;
        }

        if (empty($days)) {
            echo json_encode(["success" => false, "message" => "Please select at least one day."]);
            exit;
        }

        // تحويل الأوقات إلى كائنات DateTime للمقارنة
        $new_start_time = DateTime::createFromFormat('H:i', $start_time);
        $new_end_time = DateTime::createFromFormat('H:i', $end_time);

        if (!$new_start_time || !$new_end_time) {
            echo json_encode(["success" => false, "message" => "Invalid time format."]);
            exit;
        }

        // التحقق من أن وقت النهاية بعد وقت البداية
        if ($new_start_time >= $new_end_time) {
            echo json_encode(["success" => false, "message" => "End time must be after start time."]);
            exit;
        }

        // تحقق من وجود المعلم
        $check_teacher = $conn->prepare("SELECT email FROM teachers WHERE email = ?");
        $check_teacher->bind_param("s", $teacher);
        $check_teacher->execute();
        $check_teacher->store_result();

        if ($check_teacher->num_rows === 0) {
            echo json_encode(["success" => false, "message" => "Selected teacher does not exist. Please add teacher first."]);
            exit;
        }

        // التحقق من تعارض الجدول الزمني للمعلم
        $conflict_check = $conn->prepare("
            SELECT c.name, c.days, c.start_time, c.end_time 
            FROM courses c 
            WHERE c.teacher = ? 
        ");
        $conflict_check->bind_param("s", $teacher);
        $conflict_check->execute();
        $result = $conflict_check->get_result();

        $new_days_array = explode(",", $days);
        $has_conflict = false;
        $conflicting_course = "";

        while ($row = $result->fetch_assoc()) {
            $existing_days = explode(",", $row['days']);

            // تحقق إذا كان هناك أيام مشتركة
            $common_days = array_intersect($new_days_array, $existing_days);

            if (!empty($common_days)) {
                // تحقق من تعارض الوقت في الأيام المشتركة
                $existing_start = DateTime::createFromFormat('H:i:s', $row['start_time']);
                $existing_end = DateTime::createFromFormat('H:i:s', $row['end_time']);

                if ($existing_start && $existing_end) {
                    // تحقق من التداخل الزمني
                    if (($new_start_time < $existing_end) && ($new_end_time > $existing_start)) {
                        $has_conflict = true;
                        $conflicting_course = $row['name'];
                        break;
                    }
                }
            }
        }

        if ($has_conflict) {
            echo json_encode([
                    "success" => false,
                    "message" => "This teacher has a scheduling conflict with course: '$conflicting_course'. Please choose a different time or days."
            ]);
            exit;
        }

        // رفع الصورة
        $image_path = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $target_dir = "uploads/courses/";
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            $ext = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($ext, $allowed_ext)) {
                $image_name = uniqid("course_") . "." . $ext;
                $target_file = $target_dir . $image_name;

                if (move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
                    $image_path = $target_file;
                }
            }
        }

        // إعداد الاستعلام - استخدام `teacher` فقط (ليس `teacher_email`)
        $stmt = $conn->prepare("INSERT INTO courses 
            (name, image, start_time, end_time, days, teacher, price, max_students, duration_number, duration_unit, description) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if (!$stmt) {
            echo json_encode(["success" => false, "message" => "Database prepare error: " . $conn->error]);
            exit;
        }

        // ربط المعاملات - المتغير $teacher_email سيتم حفظه في عمود `teacher`
        $stmt->bind_param("ssssssdiiss",
                $name,
                $image_path,
                $start_time,
                $end_time,
                $days,
                $teacher, // هذا البريد الإلكتروني سيتم حفظه في عمود
                $price,
                $max_students,
                $duration_number,
                $duration_unit,
                $description
        );

        // التنفيذ
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Course added successfully!"]);
        } else {
            echo json_encode(["success" => false, "message" => "Database error: " . $stmt->error]);
        }

        $stmt->close();

    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// =============================
// 4. تحديد جميع الإشعارات كمقروءة
// =============================
if (isset($_POST['action']) && $_POST['action'] === 'mark_all_notifications_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE email = ?");
    $stmt->bind_param("s", $manager_email);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update notifications']);
    }
    $stmt->close();
    exit();
}

// =============================
// 5. جلب بيانات المستخدم
// =============================
$user_info = [
        'firstname' => 'Manager',
        'lastname' => 'User',
        'profileimage' => 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'
];

$user_query = $conn->prepare("SELECT firstname, lastname, profileimage FROM users WHERE email = ?");
if ($user_query) {
    $user_query->bind_param("s", $manager_email);
    $user_query->execute();
    $user_query->store_result();
    $user_query->bind_result($firstname, $lastname, $profileimage);

    if ($user_query->fetch()) {
        $user_info = [
                'firstname' => $firstname ?: 'Manager',
                'lastname' => $lastname ?: 'User',
                'profileimage' => $profileimage ?: 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'
        ];
    }
    $user_query->close();
} else {
    echo "<!-- Debug: User query failed: " . $conn->error . " -->\n";
}

// =============================
// 6. جلب الإشعارات
// =============================
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

// =============================
// 7. جلب قائمة المعلمين للـ select
// =============================
$teachers = [];
try {
    $teachers_result = $conn->query("SELECT u.email, u.firstname, u.lastname FROM users u JOIN teachers t ON u.email = t.email ORDER BY u.firstname");

    if ($teachers_result && $teachers_result->num_rows > 0) {
        while ($row = $teachers_result->fetch_assoc()) {
            $teachers[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching teachers: " . $e->getMessage());
    $teachers = [];
}

error_log("Number of teachers fetched: " . count($teachers));

// إغلاق اتصال قاعدة البيانات - بعد كل الاستعلامات
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coding Courses Platform</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/footor.css">
    <link rel="stylesheet" href="css/list.css">
    <link rel="stylesheet" href="css/head.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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
        .registration-form, .course-form {
            padding: 30px;
            background: rgba(139, 135, 158, 0.18);
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            width: 100%;
        }
        .form-group {
            margin-bottom: 20px;
            width: 100%;
        }
        .form-row {
            display: flex;
            gap: 15px;
            width: 100%;
        }
        .form-row .form-group {
            flex: 1;
        }
        .error-message {
            color: red;
            font-size: 14px;
            margin-top: 5px;
            display: none;
        }
        .password-container {
            position: relative;
            width: 100%;
        }
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #7f8c8d;
            font-size: 18px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #675788;
            width: 100%;
        }
        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border 0.3s;
        }
        input:focus, select:focus, textarea:focus {
            border-color: #675788;
            outline: none;
            box-shadow: 0 0 0 2px rgba(73, 56, 110, 0.2);
        }
        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 5px;
            flex-wrap: wrap;
        }
        .radio-option {
            display: flex;
            align-items: center;
            accent-color: #675788;
        }
        .radio-option input {
            width: auto;
            margin-right: 8px;
        }
        .password-requirements {
            font-size: 14px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        .submit-btn {
            background: #675788;
            color: white;
            border: none;
            padding: 14px 20px;
            font-size: 18px;
            border-radius: 5px;
            cursor: pointer;
            width: 100%;
            transition: background 0.3s;
            font-weight: 600;
        }
        .submit-btn:hover {
            background: rgba(73, 56, 110, 0.93);
            transform: scale(1.02);
            transition: 0.3s;
        }
        .image-section {
            text-align: center;
            margin-bottom: 30px;
            width: 100%;
        }
        .image-preview-container {
            width: 400px;
            height: 200px;
            border: 3px solid #665788;
            margin: 0 auto 20px;
            overflow: hidden;
            position: relative;
            background: #f8f9fa;
        }
        .image-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
        }
        .placeholder-icon {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 50px;
            color: #7f8c8d;
        }
        .file-input-container {
            margin-top: 15px;
            width: 100%;
        }
        .file-input-wrapper {
            display: inline-block;
            position: relative;
            overflow: hidden;
        }
        .file-input-wrapper input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        .file-input-btn {
            background: #49386e;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background 0.3s;
            display: inline-block;
        }
        .file-input-btn:hover {
            background: #675788;
        }
        .file-name {
            margin-top: 8px;
            color: #7f8c8d;
            font-size: 14px;
        }
        .days-selection {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 10px;
            width: 100%;
        }
        .day-option {
            display: flex;
            align-items: center;
        }
        .day-option input {
            width: auto;
            margin-right: 8px;
        }
        .days-error {
            text-align: center;
            margin-top: 10px;
            width: 100%;
        }
        .time-inputs {
            display: flex;
            gap: 15px;
            width: 100%;
        }
        .time-inputs .form-group {
            flex: 1;
        }
        .hidden {
            display: none;
        }
        .main-container {
            display: grid;
            grid-template-columns: minmax(250px, 280px) 1fr;
            gap: 20px;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        @media (max-width: 1024px) {
            .main-container {
                display: block;
            }
        }
        .form-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .form-row .form-group {
            flex: 1 1 calc(50% - 15px);
            min-width: 250px;
        }
        .days-selection {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
            gap: 10px;
        }
        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            transition: all 0.3s ease;
        }
        input, select, textarea {
            width: 100%;
            max-width: 100%;
        }
        .submit-btn {
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
            display: block;
        }
        .success-message {
            display: none;
            background-color: #d4edda;
            color: #155724;
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }
        .error-message {
            color: #dc3545;
            font-size: 14px;
            margin-top: 5px;
            display: none;
            padding: 8px 12px;
            border-radius: 4px;
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
            <a href="creat_TSC.php" class="active" >Create</a>
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
                        <?php if ($unread_notifications_count > 0): ?>
                            <button class="mark-all-read" onclick="markAllNotificationsRead()">Mark all as read</button>
                        <?php endif; ?>
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
                <img src="<?php echo htmlspecialchars($user_info['profileimage']); ?>"
                     alt="User"
                     onerror="this.onerror=null; this.src='https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'">
                <span class="username">
        <?php echo htmlspecialchars($user_info['firstname'] . ' ' . $user_info['lastname']); ?>
    </span>
            </button>
            <script>
                document.getElementById("mybutton").addEventListener("click", function() {
                    window.location.href ="ManagerProfile.php";
                });
            </script>

            <button class="logout" id="mybutton1">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
            <script>
                document.getElementById("mybutton1").addEventListener("click", function() {
                    if (confirm('Are you sure you want to logout?')) {
                        window.location.href = "logout.php";
                    }
                });
            </script>
        </div>
    </div>
</header>

<!-- التخطيط الرئيسي -->
<div class="main-container">
    <!-- القائمة الجانبية -->
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-plus"></i>
            <div class="sidebar-title">Creat</div>
        </div>
        <ul class="sidebar-menu">
            <div class="menu-section">
                <li class="menu-item active" data-target="teacher-registration">
                    <i class="fas fa-chalkboard-teacher"></i> Create Teacher
                </li>
                <li class="menu-item" data-target="student-registration">
                    <i class="fas fa-user-graduate"></i> Create Student
                </li>
                <li class="menu-item" data-target="course-registration">
                    <i class="fas fa-book"></i> Create Course
                </li>
            </div>
        </ul>
    </div>

    <!-- المحتوى الرئيسي -->
    <div class="content">
        <!-- قسم تسجيل المعلم -->
        <div id="teacher-registration" class="content-section">
            <h2 class="content-title">
                <i class="fas fa-chalkboard-teacher"></i> Create Teacher Account
            </h2>
            <div class="success-message" id="teacherSuccessMessage">
                Teacher account created successfully!
            </div>
            <div class="error-message" id="teacherServerError" style="display: none;"></div>
            <form class="registration-form" id="teacherForm" autocomplete="off">
                <div class="form-row">
                    <div class="form-group">
                        <label for="teacherFirstName">First Name</label>
                        <input type="text" id="teacherFirstName" name="firstname" required pattern="[A-Za-z]+">
                        <div class="error-message" id="teacherFirstNameError">Please enter a valid first name (letters only)</div>
                    </div>
                    <div class="form-group">
                        <label for="teacherLastName">Last Name</label>
                        <input type="text" id="teacherLastName" name="lastname" required pattern="[A-Za-z]+">
                        <div class="error-message" id="teacherLastNameError">Please enter a valid last name (letters only)</div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="teacherBirthDate">Date of Birth</label>
                    <input type="date" id="teacherBirthDate" name="birthdate" required>
                    <div class="error-message" id="teacherBirthDateError">Please enter your date of birth</div>
                </div>
                <div class="form-group">
                    <label>Gender</label>
                    <div class="radio-group">
                        <div class="radio-option">
                            <input type="radio" id="teacherMale" name="gendert" value="male" required>
                            <label for="teacherMale">Male</label>
                        </div>
                        <div class="radio-option">
                            <input type="radio" id="teacherFemale" name="gendert" value="female">
                            <label for="teacherFemale">Female</label>
                        </div>
                    </div>
                    <div class="error-message" id="teacherGenderError">Please select your gender</div>
                </div>
                <div class="form-group">
                    <label for="teacherEmail">Email Address</label>
                    <input type="email" id="teacherEmail" name="email" required autocomplete="off">
                    <div class="error-message" id="teacherEmailError">Please enter a valid email address</div>
                </div>
                <div class="form-group">
                    <label for="teacherPassword">Password</label>
                    <div class="password-container">
                        <input type="password" id="teacherPassword" name="password" autocomplete="new-password" minlength="8" required>
                        <button type="button" class="password-toggle" id="toggleTeacherPassword">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                    <div class="error-message" id="teacherPasswordError">Password must contain at least 8 characters</div>
                    <div class="password-requirements">Password must contain at least 8 characters</div>
                </div>
                <div class="form-group">
                    <label for="teacherConfirmPassword">Confirm Password</label>
                    <div class="password-container">
                        <input type="password" id="teacherConfirmPassword" name="confirmPassword" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" id="toggleTeacherConfirmPassword">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                    <div class="error-message" id="teacherConfirmPasswordError">Passwords do not match</div>
                </div>
                <div class="form-group">
                    <label for="teacherSalary">Expected Salary (USD)</label>
                    <input type="number" id="teacherSalary" name="salary" min="0" step="0.01" required>
                    <div class="error-message" id="teacherSalaryError">Please enter a valid salary</div>
                </div>
                <div class="form-group">
                    <label for="teacherjob">Job title</label>
                    <input type="text" id="teacherjob" name="job_title" required>
                    <div class="error-message" id="teacherJobTitleError">Please enter job title</div>
                </div>
                <div class="form-group">
                    <button type="submit" class="submit-btn">Create Teacher Account</button>
                </div>
            </form>
        </div>

        <!-- قسم تسجيل الطالب -->
        <div id="student-registration" class="content-section hidden">
            <h2 class="content-title">
                <i class="fas fa-user-graduate"></i> Create Student Account
            </h2>
            <div class="success-message" id="studentSuccessMessage">
                Student account created successfully!
            </div>
            <div class="error-message" id="studentServerError" style="display: none;"></div>
            <form class="registration-form" id="studentForm" autocomplete="off">
                <div class="form-row">
                    <div class="form-group">
                        <label for="studentFirstName">First Name</label>
                        <input type="text" id="studentFirstName" name="firstname" required pattern="[A-Za-z]+">
                        <div class="error-message" id="studentFirstNameError">Please enter your first name</div>
                    </div>
                    <div class="form-group">
                        <label for="studentLastName">Last Name</label>
                        <input type="text" pattern="[A-Za-z]+" id="studentLastName" name="lastname" required>
                        <div class="error-message" id="studentLastNameError">Please enter your last name</div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="studentBirthDate">Date of Birth</label>
                    <input type="date" id="studentBirthDate" name="birthdate" required>
                    <div class="error-message" id="studentBirthDateError">Please enter your date of birth</div>
                </div>
                <div class="form-group">
                    <label>Gender</label>
                    <div class="radio-group">
                        <div class="radio-option">
                            <input type="radio" id="studentMale" name="gender" value="male" required>
                            <label for="studentMale">Male</label>
                        </div>
                        <div class="radio-option">
                            <input type="radio" id="studentFemale" name="gender" value="female">
                            <label for="studentFemale">Female</label>
                        </div>
                    </div>
                    <div class="error-message" id="studentGenderError">Please select your gender</div>
                </div>
                <div class="form-group">
                    <label for="studentEmail">Email Address</label>
                    <input type="email" id="studentEmail" name="email" autocomplete="off" required>
                    <div class="error-message" id="studentEmailError">Please enter a valid email address</div>
                </div>
                <div class="form-group">
                    <label for="studentPassword">Password</label>
                    <div class="password-container">
                        <input type="password" id="studentPassword" name="password" autocomplete="new-password" minlength="8" required>
                        <button type="button" class="password-toggle" id="toggleStudentPassword">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                    <div class="error-message" id="studentPasswordError">Password must contain at least 8 characters</div>
                    <div class="password-requirements">Password must contain at least 8 characters</div>
                </div>
                <div class="form-group">
                    <label for="studentConfirmPassword">Confirm Password</label>
                    <div class="password-container">
                        <input type="password" id="studentConfirmPassword" name="confirmPassword" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" id="toggleStudentConfirmPassword">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                    <div class="error-message" id="studentConfirmPasswordError">Passwords do not match</div>
                </div>
                <div class="form-group">
                    <button type="submit" class="submit-btn">Create Student Account</button>
                </div>
            </form>
        </div>

        <!-- قسم إنشاء الكورس -->
        <div id="course-registration" class="content-section hidden">
            <h2 class="content-title">
                <i class="fas fa-book"></i> Add New Course
            </h2>
            <div class="success-message" id="courseSuccessMessage">
                Course added successfully!
            </div>
            <div class="error-message" id="courseServerError" style="display: none;"></div>
            <form class="course-form" id="courseForm" autocomplete="off" enctype="multipart/form-data">
                <!-- Course Image -->
                <div class="image-section">
                    <div class="image-preview-container">
                        <img id="imagePreview" class="image-preview" alt="Image Preview">
                        <div class="placeholder-icon">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                    <div class="file-input-container">
                        <div class="file-input-wrapper">
                            <button type="button" class="file-input-btn">
                                <i class="fas fa-upload"></i> Choose Course Image
                            </button>
                            <input type="file" id="courseImage" accept="image/*" name="image">
                        </div>
                        <div class="file-name" id="fileName">No file chosen</div>
                    </div>
                </div>

                <!-- Course Name -->
                <div class="form-group">
                    <label for="courseName">Course Name</label>
                    <input type="text" id="courseName" name="courseName" placeholder="Enter course name" required>
                    <div class="error-message" id="courseNameError">Please enter course name</div>
                </div>
                <!-- Start and End Time -->
                <div class="form-group">
                    <div class="form-row time-inputs">
                        <div class="form-group">
                            <label for="startTime">Start Time</label>
                            <input type="time" id="startTime" name="start_time" required>
                            <div class="error-message" id="startTimeError"></div>
                        </div>
                        <div class="form-group">
                            <label for="endTime">End Time</label>
                            <input type="time" id="endTime" name="end_time" required>
                            <div class="error-message" id="endTimeError"></div>
                        </div>
                    </div>
                </div>

                <!-- Course Days -->
                <div class="form-group">
                    <label>Course Days</label>
                    <div class="days-selection">
                        <div class="day-option">
                            <input type="checkbox" id="saturday" name="days[]" value="saturday">
                            <label for="saturday">Saturday</label>
                        </div>
                        <div class="day-option">
                            <input type="checkbox" id="sunday" name="days[]" value="sunday">
                            <label for="sunday">Sunday</label>
                        </div>
                        <div class="day-option">
                            <input type="checkbox" id="monday" name="days[]" value="monday">
                            <label for="monday">Monday</label>
                        </div>
                        <div class="day-option">
                            <input type="checkbox" id="tuesday" name="days[]" value="tuesday">
                            <label for="tuesday">Tuesday</label>
                        </div>
                        <div class="day-option">
                            <input type="checkbox" id="wednesday" name="days[]" value="wednesday">
                            <label for="wednesday">Wednesday</label>
                        </div>
                        <div class="day-option">
                            <input type="checkbox" id="thursday" name="days[]" value="thursday">
                            <label for="thursday">Thursday</label>
                        </div>
                        <div class="day-option">
                            <input type="checkbox" id="friday" name="days[]" value="friday">
                            <label for="friday">Friday</label>
                        </div>
                    </div>
                    <div class="error-message days-error" id="daysError">Please select at least one day for the course</div>
                </div>

                <!-- Course Teacher -->
                <div class="form-group">
                    <label for="courseTeacher">Course Teacher</label>
                    <select id="courseTeacher" name="teacher" required>
                        <option value="">Select Teacher</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo htmlspecialchars($teacher['email']); ?>">
                                <?php echo htmlspecialchars($teacher['firstname'] . ' ' . $teacher['lastname'] . ' (' . $teacher['email'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="error-message" id="courseTeacherError">Please select a teacher</div>
                </div>

                <!-- Course Price -->
                <div class="form-group">
                    <label for="coursePrice">Course Price ($)</label>
                    <input type="number" id="coursePrice" name="price" min="0" step="0.01" placeholder="Enter course price" required>
                    <div class="error-message" id="coursePriceError">Please enter a valid price</div>
                </div>

                <!-- Maximum Students -->
                <div class="form-group">
                    <label for="maxStudents">Maximum Students</label>
                    <input type="number" id="maxStudents" name="max_students" min="1" placeholder="Enter maximum number of students" required>
                    <div class="error-message" id="maxStudentsError">Please enter maximum number of students</div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="durationNumber">Duration</label>
                        <input type="number" id="durationNumber" name="duration_number" min="1" max="52" placeholder="e.g., 4">
                    </div>
                    <div class="form-group">
                        <label for="durationUnit">Unit</label>
                        <select id="durationUnit" name="duration_unit">
                            <option value="days">Days</option>
                            <option value="weeks">Weeks</option>
                            <option value="months">Months</option>
                            <option value="years">Years</option>
                        </select>
                    </div>
                </div>

                <!-- Course Description -->
                <div class="form-group">
                    <label for="courseDescription">Course Description</label>
                    <textarea id="courseDescription" name="description" placeholder="Enter course description"></textarea>
                </div>

                <div class="form-group">
                    <button type="submit" class="submit-btn">Add Course</button>
                </div>
            </form>
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
<script src="js/head.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // منع autocomplete عند تحميل الصفحة
    window.addEventListener('load', function() {
        document.getElementById('teacherForm').reset();
        document.getElementById('studentForm').reset();
        document.getElementById('courseForm').reset();

        document.querySelectorAll('input[type="password"]').forEach(input => {
            input.value = '';
        });
    });

    // تبديل المحتوى بناءً على العنصر المحدد
    const menuItems = document.querySelectorAll('.menu-item');
    const contentSections = document.querySelectorAll('.content-section');

    menuItems.forEach(item => {
        item.addEventListener('click', () => {
            menuItems.forEach(i => i.classList.remove('active'));
            item.classList.add('active');

            contentSections.forEach(section => section.classList.add('hidden'));
            const targetId = item.getAttribute('data-target');
            document.getElementById(targetId).classList.remove('hidden');

            // إعادة تعيين النماذج عند التبديل بين الأقسام
            document.getElementById('teacherForm').reset();
            document.getElementById('studentForm').reset();
            document.getElementById('courseForm').reset();

            // إخفاء معاينة الصورة
            document.getElementById('imagePreview').style.display = 'none';
            document.querySelector('.placeholder-icon').style.display = 'block';
            document.getElementById('fileName').textContent = 'No file chosen';

            // إخفاء رسائل الأخطاء
            document.querySelectorAll('.error-message').forEach(el => {
                el.style.display = 'none';
            });
        });
    });

    // دالة لإخفاء جميع الأخطاء
    function hideAllErrors(formType) {
        document.querySelectorAll(`#${formType}Form .error-message`).forEach(el => {
            el.style.display = 'none';
        });
        document.getElementById(`${formType}ServerError`).style.display = 'none';
    }

    // دالة لإظهار الخطأ
    function showError(errorId) {
        const errorElement = document.getElementById(errorId);
        if (errorElement) {
            errorElement.style.display = 'block';
        }
    }

    // دالة للتحقق من مطابقة كلمة المرور
    function validatePasswordMatch(passwordId, confirmPasswordId, errorId) {
        const password = document.getElementById(passwordId).value;
        const confirmPassword = document.getElementById(confirmPasswordId).value;
        const errorElement = document.getElementById(errorId);

        if (password !== confirmPassword) {
            errorElement.textContent = 'Passwords do not match';
            errorElement.style.display = 'block';
            return false;
        } else {
            errorElement.style.display = 'none';
            return true;
        }
    }

    // التحقق من مطابقة كلمة المرور عند تغييرها
    document.getElementById('teacherPassword').addEventListener('input', function() {
        validatePasswordMatch('teacherPassword', 'teacherConfirmPassword', 'teacherConfirmPasswordError');
    });

    document.getElementById('teacherConfirmPassword').addEventListener('input', function() {
        validatePasswordMatch('teacherPassword', 'teacherConfirmPassword', 'teacherConfirmPasswordError');
    });

    document.getElementById('studentPassword').addEventListener('input', function() {
        validatePasswordMatch('studentPassword', 'studentConfirmPassword', 'studentConfirmPasswordError');
    });

    document.getElementById('studentConfirmPassword').addEventListener('input', function() {
        validatePasswordMatch('studentPassword', 'studentConfirmPassword', 'studentConfirmPasswordError');
    });

    // التحقق من صحة النموذج قبل الإرسال
    function validateForm(formType) {
        let isValid = true;

        if (formType === 'teacher') {
            const firstName = document.getElementById('teacherFirstName').value.trim();
            if (!/^[A-Za-z]+$/.test(firstName)) {
                showError('teacherFirstNameError');
                isValid = false;
            }

            const lastName = document.getElementById('teacherLastName').value.trim();
            if (!/^[A-Za-z]+$/.test(lastName)) {
                showError('teacherLastNameError');
                isValid = false;
            }

            const email = document.getElementById('teacherEmail').value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showError('teacherEmailError');
                isValid = false;
            }

            const password = document.getElementById('teacherPassword').value;
            if (password.length < 8) {
                showError('teacherPasswordError');
                isValid = false;
            }

            if (!validatePasswordMatch('teacherPassword', 'teacherConfirmPassword', 'teacherConfirmPasswordError')) {
                isValid = false;
            }

            const salary = document.getElementById('teacherSalary').value;
            if (salary <= 0) {
                showError('teacherSalaryError');
                isValid = false;
            }

            const jobTitle = document.getElementById('teacherjob').value.trim();
            if (!jobTitle) {
                showError('teacherJobTitleError');
                isValid = false;
            }
        }

        if (formType === 'student') {
            const firstName = document.getElementById('studentFirstName').value.trim();
            if (!/^[A-Za-z]+$/.test(firstName)) {
                showError('studentFirstNameError');
                isValid = false;
            }

            const lastName = document.getElementById('studentLastName').value.trim();
            if (!/^[A-Za-z]+$/.test(lastName)) {
                showError('studentLastNameError');
                isValid = false;
            }

            const email = document.getElementById('studentEmail').value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showError('studentEmailError');
                isValid = false;
            }

            const password = document.getElementById('studentPassword').value;
            if (password.length < 8) {
                showError('studentPasswordError');
                isValid = false;
            }

            if (!validatePasswordMatch('studentPassword', 'studentConfirmPassword', 'studentConfirmPasswordError')) {
                isValid = false;
            }
        }

        return isValid;
    }

    // التحقق من صحة نموذج الكورس
    function validateCourseForm() {
        let isValid = true;

        const courseName = document.getElementById('courseName').value.trim();
        if (!courseName) {
            showError('courseNameError');
            isValid = false;
        } else {
            document.getElementById('courseNameError').style.display = 'none';
        }

        if (!validateTimes()) {
            isValid = false;
        }

        if (!validateDays()) {
            isValid = false;
        }

        const teacher = document.getElementById('courseTeacher').value;
        if (!teacher) {
            showError('courseTeacherError');
            isValid = false;
        } else {
            document.getElementById('courseTeacherError').style.display = 'none';
        }

        const price = document.getElementById('coursePrice').value;
        if (!price || price <= 0) {
            showError('coursePriceError');
            isValid = false;
        } else {
            document.getElementById('coursePriceError').style.display = 'none';
        }

        const maxStudents = document.getElementById('maxStudents').value;
        if (!maxStudents || maxStudents < 1) {
            showError('maxStudentsError');
            isValid = false;
        } else {
            document.getElementById('maxStudentsError').style.display = 'none';
        }

        return isValid;
    }

    // تسجيل المعلم
    document.getElementById('teacherForm').addEventListener('submit', function(e) {
        e.preventDefault();

        if (!validateForm('teacher')) {
            const firstError = document.querySelector('#teacherForm .error-message[style*="block"]');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        hideAllErrors('teacher');
        document.getElementById('teacherSuccessMessage').style.display = 'none';

        const formData = new FormData(this);
        formData.append('action', 'create_teacher');

        fetch('', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('teacherSuccessMessage').style.display = 'block';
                    document.getElementById('teacherSuccessMessage').textContent = data.message;
                    this.reset();
                    window.scrollTo({top: 0, behavior: 'smooth'});
                    setTimeout(() => {
                        document.getElementById('teacherSuccessMessage').style.display = 'none';
                    }, 5000);
                } else {
                    if (data.duplicate_email) {
                        showError('teacherEmailError');
                        document.getElementById('teacherEmailError').textContent = data.message;
                    } else if (data.field === 'birthdate') {
                        showError('teacherBirthDateError');
                        document.getElementById('teacherBirthDateError').textContent = data.message;
                    } else {
                        showError('teacherServerError');
                        document.getElementById('teacherServerError').textContent = data.message || 'An error occurred';
                    }

                    const firstError = document.querySelector('#teacherForm .error-message[style*="block"]');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            })
            .catch(error => {
                showError('teacherServerError');
                document.getElementById('teacherServerError').textContent = 'Connection error';
                document.getElementById('teacherServerError').scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
    });

    // تسجيل الطالب
    document.getElementById('studentForm').addEventListener('submit', function(e) {
        e.preventDefault();

        if (!validateForm('student')) {
            const firstError = document.querySelector('#studentForm .error-message[style*="block"]');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        hideAllErrors('student');
        document.getElementById('studentSuccessMessage').style.display = 'none';

        const formData = new FormData(this);
        formData.append('action', 'create_student');

        fetch('', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('studentSuccessMessage').style.display = 'block';
                    document.getElementById('studentSuccessMessage').textContent = data.message;
                    this.reset();
                    window.scrollTo({top: 0, behavior: 'smooth'});
                    setTimeout(() => {
                        document.getElementById('studentSuccessMessage').style.display = 'none';
                    }, 5000);
                } else {
                    if (data.duplicate_email) {
                        showError('studentEmailError');
                        document.getElementById('studentEmailError').textContent = data.message;
                    } else {
                        showError('studentServerError');
                        document.getElementById('studentServerError').textContent = data.message || 'An error occurred';
                    }

                    const firstError = document.querySelector('#studentForm .error-message[style*="block"]');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            })
            .catch(error => {
                showError('studentServerError');
                document.getElementById('studentServerError').textContent = 'Connection error';
                document.getElementById('studentServerError').scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
    });

    // إنشاء الكورس
    document.getElementById('courseForm').addEventListener('submit', function(e) {
        e.preventDefault();

        console.log("Submitting course form...");

        let isValid = true;

        const courseName = document.getElementById('courseName').value.trim();
        if (!courseName) {
            showError('courseNameError');
            isValid = false;
        }

        const daysSelected = document.querySelectorAll('input[name="days[]"]:checked');
        if (daysSelected.length === 0) {
            showError('daysError');
            isValid = false;
        }

        const teacher = document.getElementById('courseTeacher').value;
        if (!teacher) {
            showError('courseTeacherError');
            isValid = false;
        }

        if (!isValid) {
            const firstError = document.querySelector('#courseForm .error-message[style*="block"]');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        hideAllErrors('course');
        document.getElementById('courseSuccessMessage').style.display = 'none';

        const formData = new FormData(this);
        formData.append('action', 'create_course');

        fetch('', {
            method: 'POST',
            body: formData
        })
            .then(r => {
                console.log("Response status (course):", r.status);
                return r.json();
            })
            .then(data => {
                console.log("Response data (course):", data);

                if (data.success) {
                    document.getElementById('courseSuccessMessage').style.display = 'block';
                    document.getElementById('courseSuccessMessage').textContent = data.message;

                    this.reset();
                    document.getElementById('imagePreview').style.display = 'none';
                    document.querySelector('.placeholder-icon').style.display = 'block';
                    document.getElementById('fileName').textContent = 'No file chosen';

                    window.scrollTo({top: 0, behavior: 'smooth'});

                    setTimeout(() => {
                        document.getElementById('courseSuccessMessage').style.display = 'none';
                    }, 5000);
                } else {
                    showError('courseServerError');
                    document.getElementById('courseServerError').textContent = data.message || 'An error occurred';
                    document.getElementById('courseServerError').scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            })
            .catch(error => {
                console.error('Fetch error (course):', error);
                showError('courseServerError');
                document.getElementById('courseServerError').textContent = 'Connection error: ' + error.message;
                document.getElementById('courseServerError').scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
    });

    // معالجة صورة الكورس
    document.getElementById('courseImage').addEventListener('change', function(e) {
        const file = e.target.files[0];
        const fileName = document.getElementById('fileName');

        if (file) {
            fileName.textContent = file.name;

            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('imagePreview');
                preview.src = e.target.result;
                preview.style.display = 'block';
                document.querySelector('.placeholder-icon').style.display = 'none';
            };
            reader.readAsDataURL(file);
        } else {
            fileName.textContent = 'No file chosen';
            document.getElementById('imagePreview').style.display = 'none';
            document.querySelector('.placeholder-icon').style.display = 'block';
        }
    });

    // التحقق من الأوقات
    function validateTimes() {
        const startTime = document.getElementById('startTime').value;
        const endTime = document.getElementById('endTime').value;
        const startError = document.getElementById('startTimeError');
        const endError = document.getElementById('endTimeError');

        startError.style.display = 'none';
        endError.style.display = 'none';

        if (startTime && endTime && startTime >= endTime) {
            startError.textContent = 'Start time must be before end time';
            endError.textContent = 'End time must be after start time';
            startError.style.display = 'block';
            endError.style.display = 'block';
            return false;
        }

        return true;
    }

    // التحقق من الأيام
    function validateDays() {
        const daysSelected = document.querySelectorAll('input[name="days[]"]:checked');
        const daysError = document.getElementById('daysError');

        if (daysSelected.length === 0) {
            daysError.style.display = 'block';
            return false;
        } else {
            daysError.style.display = 'none';
            return true;
        }
    }

    // إضافة مستمعين للأحداث
    document.getElementById('startTime').addEventListener('change', validateTimes);
    document.getElementById('endTime').addEventListener('change', validateTimes);

    const dayCheckboxes = document.querySelectorAll('input[name="days[]"]');
    dayCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', validateDays);
    });

    // تفعيل/تعطيل عرض كلمة المرور
    const toggleButtons = [
        { toggle: 'toggleTeacherPassword', input: 'teacherPassword' },
        { toggle: 'toggleTeacherConfirmPassword', input: 'teacherConfirmPassword' },
        { toggle: 'toggleStudentPassword', input: 'studentPassword' },
        { toggle: 'toggleStudentConfirmPassword', input: 'studentConfirmPassword' }
    ];

    toggleButtons.forEach(button => {
        const toggleElement = document.getElementById(button.toggle);
        const inputElement = document.getElementById(button.input);

        if (toggleElement && inputElement) {
            toggleElement.addEventListener('click', function() {
                const type = inputElement.getAttribute('type') === 'password' ? 'text' : 'password';
                inputElement.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="far fa-eye"></i>' : '<i class="far fa-eye-slash"></i>';
            });
        }
    });

    // تحديد جميع الإشعارات كمقروءة
    function markAllNotificationsRead() {
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=mark_all_notifications_read'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // إخفاء العلامة (badge) والنقاط
                    const badge = document.querySelector('.notification-badge');
                    if (badge) badge.remove();
                    document.querySelectorAll('.notification-dot').forEach(el => {
                        el.remove();
                    });
                    document.querySelectorAll('.notification-item').forEach(item => {
                        item.classList.remove('unread');
                    });
                    // إظهار رسالة نجاح
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to connect to server'
                });
            });
    }
</script>
</body>
</html>