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
    header("Location: login.php");
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

// Fetch gallery images
$gallery_query = "SELECT * FROM gallery ORDER BY id";
$gallery_result = $conn->query($gallery_query);
$gallery_items = [];
while ($row = $gallery_result->fetch_assoc()) {
    $gallery_items[] = $row;
}

// Fetch course description
$course_info_query = "SELECT course_description FROM course_info ORDER BY id DESC LIMIT 1";
$course_info_result = $conn->query($course_info_query);
$course_description = "";
if ($course_info_result->num_rows > 0) {
    $course_info_data = $course_info_result->fetch_assoc();
    $course_description = $course_info_data['course_description'];
} else {
    $course_description = "<h3>Welcome to EDVORA Educational Academy</h3>
        <p>We offer a distinguished collection of training courses specifically designed to meet the needs of the modern job market.</p>";
}

// Calculate statistics
$students_count_query = "SELECT COUNT(*) as count FROM students";
$students_result = $conn->query($students_count_query);
$students_count = $students_result->fetch_assoc()['count'];

$courses_count_query = "SELECT COUNT(*) as count FROM courses";
$courses_result = $conn->query($courses_count_query);
$courses_count = $courses_result->fetch_assoc()['count'];

$graduates_count_query = "SELECT COUNT(DISTINCT student_email) as count FROM studentcoursestatus WHERE status = 'Passed'";
$graduates_result = $conn->query($graduates_count_query);
$graduates_count = $graduates_result->fetch_assoc()['count'];

$awards_count = 18; // Default value or fetch from a table if exists

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

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDVORA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/head.css">
    <link rel="stylesheet" href="css/ManagerHome.css">
    <link rel="stylesheet" href="css/footor.css">
    <link rel="icon" type="image/png" href="img/icon.png">

    <style>
        .stat-card .text-editor-content{
            background: rgba(139, 135, 158, 0.18);
        }
        .thumbnail-container {
            display: none !important;
        }

        .editor-tools,
        .stat-input {
            display: none !important;
        }

        .auto-play-indicator i {
            margin-right: 5px;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .stat-card {
            position: relative;
        }

        .stat-card::after {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: transparent;
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
            <a href="teacher_home.php" class="active">Home</a>
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

<section class="slider-section">
    <h2 class="section-title">Course Gallery</h2>
    <div class="slider-container">
        <div class="main-slider" id="mainSlider">
            <?php if (count($gallery_items) > 0): ?>
                <?php foreach ($gallery_items as $index => $item): ?>
                    <div class="slide <?php echo $index === 0 ? 'active' : ''; ?>">
                        <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" onclick="openModal('<?php echo htmlspecialchars($item['image_url']); ?>')">
                        <div class="slide-content">
                            <div class="slide-title"><?php echo htmlspecialchars($item['title']); ?></div>
                            <div class="slide-description"><?php echo htmlspecialchars($item['description']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="slide active">
                    <div style="padding: 50px; text-align: center; color: #666;">
                        <i class="fas fa-image" style="font-size: 64px; margin-bottom: 20px;"></i>
                        <p>No gallery images available</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (count($gallery_items) > 1): ?>
            <button class="slider-nav prev" id="prevBtn">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button class="slider-nav next" id="nextBtn">
                <i class="fas fa-chevron-right"></i>
            </button>
        <?php endif; ?>
    </div>
</section>

<section class="text-editor-section">
    <div class="text-editor-header">
        <h2>Course Description</h2>
    </div>
    <div class="text-editor-content" id="textContent" contenteditable="false">
        <?php  echo nl2br(htmlspecialchars($course_description))??'<p>No course description available.</p>'; ?>
    </div>
</section>

<section class="stats-section">
    <h2 class="section-title">Academy Statistics</h2>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-number" id="studentsCount"><?php echo $students_count; ?></div>
            <div class="stat-label">Total Registered Students</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="stat-number" id="graduatesCount"><?php echo $graduates_count; ?></div>
            <div class="stat-label">Successful Graduates</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <div class="stat-number" id="coursesCount"><?php echo $courses_count; ?></div>
            <div class="stat-label">Available Courses</div>
        </div>

</section>

<div class="modal" id="imageModal">
    <div class="modal-content">
        <button class="close-modal" id="closeModal">
            <i class="fas fa-times"></i>
        </button>
        <img id="modalImage" src="" alt="">
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
    let currentSlide = 0;
    const totalSlides = <?php echo count($gallery_items) > 0 ? count($gallery_items) : 1; ?>;
    let autoPlayInterval;

    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const imageModal = document.getElementById('imageModal');
    const modalImage = document.getElementById('modalImage');
    const closeModal = document.getElementById('closeModal');

    function updateSlider() {
        const slides = document.querySelectorAll('.slide');
        slides.forEach((slide, index) => {
            slide.classList.toggle('active', index === currentSlide);
        });
    }

    function nextSlide() {
        currentSlide = (currentSlide + 1) % totalSlides;
        updateSlider();
    }

    function prevSlide() {
        currentSlide = (currentSlide - 1 + totalSlides) % totalSlides;
        updateSlider();
    }

    function startAutoPlay() {
        if (totalSlides > 1) {
            autoPlayInterval = setInterval(nextSlide, 5000);
        }
    }

    function openModal(imageSrc) {
        modalImage.src = imageSrc;
        imageModal.classList.add('active');
        clearInterval(autoPlayInterval);
    }

    function closeImageModal() {
        imageModal.classList.remove('active');
        startAutoPlay();
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