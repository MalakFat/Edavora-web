<?php
session_start();

// Database connection
$conn = new mysqli("localhost", "root", "", "edavora");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

// =============================
// Check login as teacher
// =============================
if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

$teacher_email = $_SESSION['email'];

// Verify user exists in teachers table
$check_teacher = $conn->prepare("SELECT email FROM teachers WHERE email = ?");
$check_teacher->bind_param("s", $teacher_email);
$check_teacher->execute();
$check_teacher->store_result();

if ($check_teacher->num_rows === 0) {
    // User is not a teacher
    header("Location: access_denied.php");
    exit();
}

// =============================
// 1. Get teacher data
// =============================
$stmt = $conn->prepare("SELECT u.firstname, u.lastname, u.birthdate, u.gender, u.profileimage, u.password, t.job_title, t.salary 
                       FROM users u 
                       INNER JOIN teachers t ON u.email = t.email 
                       WHERE u.email = ?");
$stmt->bind_param("s", $teacher_email);
$stmt->execute();
$teacher_result = $stmt->get_result();
$teacher = $teacher_result->fetch_assoc();

$teacher_name = $teacher['firstname'] . " " . $teacher['lastname'];

$teacher_image = $teacher['profileimage'] ?? 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';

// Set default image if empty
$profile_image = !empty($teacher['profileimage']) ? $teacher['profileimage'] : 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';

// =============================
// 2. Update profile + image (only when saving)
// =============================
if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $firstname = trim($_POST['firstname']);
    $lastname  = trim($_POST['lastname']);
    $birthdate = $_POST['birthdate'];
    $gender    = $_POST['gender'] === 'Male' ? 'Male' : 'Female';

    $profileimage = $teacher['profileimage'];

    // Handle image upload only if new image was selected
    if (isset($_FILES['profileimage']) && $_FILES['profileimage']['error'] === 0 && $_FILES['profileimage']['size'] > 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $ext = strtolower(pathinfo($_FILES['profileimage']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $dir = "uploads/profiles/";
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            // Generate unique filename
            $new_filename = uniqid() . "_" . $teacher_email . "." . $ext;
            $profileimage = $dir . $new_filename;

            // Delete old image if not default
            if (!empty($teacher['profileimage']) && $teacher['profileimage'] != 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png') {
                if (file_exists($teacher['profileimage'])) {
                    unlink($teacher['profileimage']);
                }
            }

            // Move uploaded file
            if (move_uploaded_file($_FILES['profileimage']['tmp_name'], $profileimage)) {
                // Image successfully uploaded
            } else {
                $profileimage = $teacher['profileimage']; // Keep old image if upload fails
            }
        }
    }

    $stmt = $conn->prepare("UPDATE users SET firstname=?, lastname=?, birthdate=?, gender=?, profileimage=? WHERE email=?");
    $stmt->bind_param("ssssss", $firstname, $lastname, $birthdate, $gender, $profileimage, $teacher_email);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully', 'newImage' => $profileimage]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating data']);
    }
    $stmt->close();
    exit();
}

// ===== GENERATE AND SAVE NEW NOTIFICATIONS =====

// 1. Check for new student enrollments in teacher's courses
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
$stmt->bind_param("ss", $teacher_email, $teacher_name);
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
$stmt->bind_param("ss", $teacher_name, $current_day);
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
$stmt->bind_param("ss", $teacher_email, $teacher_name);
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
$stmt->bind_param("ss", $teacher_email, $teacher_name);
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

// =============================
// 3. Change password with hash verification
// =============================
if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current = $_POST['current_password'];
    $new     = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    // Verify current password with hashed password in database
    if (password_verify($current, $teacher['password'])) {
        if ($new === $confirm && strlen($new) >= 8) {
            $hashed_password = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
            $stmt->bind_param("ss", $hashed_password, $teacher_email);

            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error updating password']);
            }
            $stmt->close();
        } else {
            if (strlen($new) < 8) {
                echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters']);
            } else {
                echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
            }
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
    }
    exit();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDVORA - Teacher Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/ManagerProfile.css">
    <link rel="stylesheet" href="css/head.css">
    <link rel="stylesheet" href="css/footor.css">
    <link rel="icon" type="image/png" href="img/icon.png">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            background: red;
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
        .password-error {
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 5px;
            display: none;
        }
        .field-error {
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 5px;
        }
        .error {
            border-color: #dc3545 !important;
        }
        .readonly-field {
            background-color: #f5f5f5;
            cursor: not-allowed;
        }
        /* Profile image preview styles */
        #profileImage {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #665788;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .photo-section {
            position: relative;
            text-align: center;
        }

        .change-photo {
            margin-top: 15px;
            background: #665788;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .change-photo:hover {
            background: #8e77c5;
        }

        .image-preview-container {
            position: relative;
            display: inline-block;
        }

        .preview-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
            cursor: pointer;
        }

        .image-preview-container:hover .preview-overlay {
            opacity: 1;
        }

        .preview-overlay i {
            color: white;
            font-size: 24px;
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
            <a href="teacher_home.php">Home</a>
            <a href="TeacherCourses.php">My Courses</a>
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
                            <button class="mark-all-read" onclick="markAllAsRead()">Mark all as read</button>
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
                <img src="<?php echo htmlspecialchars($teacher_image); ?>" alt="User" id="headerProfileImage">
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

<div class="settings-container">
    <aside class="sidebar">
        <h2><i class="fas fa-sliders-h"></i> Settings</h2>
        <ul>
            <li class="tab-link active" data-tab="profile">Profile Information</li>
            <li class="tab-link" data-tab="password">Password</li>
        </ul>
    </aside>

    <main class="content">
        <!-- Profile Tab -->
        <section id="profile" class="tab-content active">
            <div class="tab-header"><h3>Profile Information</h3></div>
            <div class="profile-card">
                <div class="photo-section">
                    <div class="image-preview-container">
                        <img id="profileImage" src="<?php echo $profile_image; ?>" alt="Profile" onclick="document.getElementById('fileInput').click()">
                        <div class="preview-overlay" onclick="document.getElementById('fileInput').click()">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                    <input type="file" id="fileInput" accept="image/*" hidden>
                </div>
                <form id="profileForm" class="info-form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" value="<?php echo htmlspecialchars($teacher_email); ?>" readonly class="readonly-field">
                    </div>
                    <div class="form-group">
                        <label>First name:</label>
                        <input type="text" name="firstname" value="<?php echo htmlspecialchars($teacher['firstname']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last name:</label>
                        <input type="text" name="lastname" value="<?php echo htmlspecialchars($teacher['lastname']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Job Title:</label>
                        <input type="text" value="<?php echo htmlspecialchars($teacher['job_title']); ?>" readonly class="readonly-field">
                    </div>
                    <div class="form-group">
                        <label>Salary:</label>
                        <input type="text" value="$<?php echo number_format($teacher['salary'], 2); ?>" readonly class="readonly-field">
                    </div>
                    <div class="form-group">
                        <label>Date of birth:</label>
                        <input type="date" name="birthdate" value="<?php echo $teacher['birthdate']; ?>" required>
                    </div>
                    <div class="form-group full-width">
                        <label>Gender:</label>
                        <div class="gender">
                            <label><input type="radio" name="gender" value="Male" <?php echo ($teacher['gender']=='Male')?'checked':''; ?>> Male</label>
                            <label><input type="radio" name="gender" value="Female" <?php echo ($teacher['gender']=='Female')?'checked':''; ?>> Female</label>
                        </div>
                    </div>
                    <button type="submit" class="update-btn">Update Profile</button>
                </form>
            </div>
        </section>

        <!-- Password Tab -->
        <section id="password" class="tab-content">
            <div class="tab-header"><h3>Change Password</h3></div>
            <form id="passwordForm" class="simple-form">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group"><label>Current Password:</label><input type="password" name="current_password" required></div>
                <div class="form-group"><label>New Password:</label><input type="password" name="new_password" required minlength="8"></div>
                <div class="form-group"><label>Confirm Password:</label><input type="password" name="confirm_password" required></div>
                <button type="submit" class="update-btn">Save Changes</button>
            </form>
        </section>
    </main>
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
    // Profile image preview functionality
    document.getElementById('fileInput').addEventListener('change', function(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();

            reader.onload = function(e) {
                // Update profile image preview
                document.getElementById('profileImage').src = e.target.result;
            }

            reader.readAsDataURL(file);
        }
    });

    // Tab switching
    document.addEventListener('DOMContentLoaded', function() {
        const tabLinks = document.querySelectorAll('.tab-link');
        const tabContents = document.querySelectorAll('.tab-content');

        tabLinks.forEach(link => {
            link.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');

                // Remove active from all tabs
                tabLinks.forEach(l => l.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));

                // Add active to selected tab
                this.classList.add('active');
                document.getElementById(tabId).classList.add('active');
            });
        });
    });

    // Update profile
    document.getElementById('profileForm').onsubmit = function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        if (document.getElementById('fileInput').files[0]) {
            fd.append('profileimage', document.getElementById('fileInput').files[0]);
        }

        fetch('', {
            method: 'POST',
            body: fd
        })
            .then(r => r.json())
            .then(res => {
                if(res.success) {
                    Swal.fire('Success!', res.message, 'success').then(() => {
                        // Update header image if new image was uploaded
                        if (res.newImage) {
                            document.getElementById('profileImage').src = res.newImage;
                            document.getElementById('headerProfileImage').src = res.newImage;
                        }
                        location.reload();
                    });
                } else {
                    Swal.fire('Error!', res.message, 'error');
                }
            })
            .catch(err => {
                Swal.fire('Error!', 'An error occurred while updating profile', 'error');
            });
    };

    // Change password with hash verification
    document.getElementById('passwordForm').onsubmit = function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fetch('', {
            method: 'POST',
            body: fd
        })
            .then(r => r.json())
            .then(res => {
                if(res.success){
                    Swal.fire('Success!', res.message, 'success');
                    this.reset();
                } else {
                    Swal.fire('Error!', res.message, 'error');
                }
            })
            .catch(err => {
                Swal.fire('Error!', 'An error occurred while changing password', 'error');
            });
    };

    // Update image when new file is selected
    document.getElementById('fileInput').addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('profileImage').src = e.target.result;
            };
            reader.readAsDataURL(this.files[0]);
        }
    });

    // Mark single notification as read
    function markAsRead(notificationId) {
        // Note: You need to create mark_notification_read.php file for this to work
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
        // Note: You need to create mark_all_notifications_read.php file for this to work
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
</script>

</body>
</html>