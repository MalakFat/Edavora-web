<?php
session_start();
require("config/db_connection.php");

// تحقق من تسجيل الدخول
if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

// استخدام البريد من الجلسة الصحيحة
$student_email = $_SESSION['email'];

// استعلام الإشعارات
$notifications_query = "SELECT * FROM notifications WHERE user_email = ? ORDER BY created_at DESC";
$notifications_stmt = $conn->prepare($notifications_query);
$notifications_stmt->bind_param("s", $student_email);
$notifications_stmt->execute();
$notifications = $notifications_stmt->get_result();

// عد الإشعارات غير المقروءة
$unread_query = "SELECT COUNT(*) as unread_count FROM notifications WHERE user_email = ? AND is_read = 0";
$unread_stmt = $conn->prepare($unread_query);
$unread_stmt->bind_param("s", $student_email);
$unread_stmt->execute();
$unread_result = $unread_stmt->get_result();
$unread_data = $unread_result->fetch_assoc();
$unread_count = $unread_data['unread_count'] ?? 0;

// استعلام لجلب الكورسات المسجلة للطالب
$sql = "
    SELECT 
        c.id,
        c.name,
        c.image,
        c.start_time,
        c.end_time,
        c.days,
        c.price,
        c.duration_number,
        c.duration_unit,
        c.description,
        u.firstname as teacher_firstname,
        u.lastname as teacher_lastname,
        u.profileimage as teacher_image,
        t.job_title as teacher_job,
        s.progress
    FROM study s
    JOIN courses c ON s.course_id = c.id
    LEFT JOIN teachers t ON c.teacher = t.email
    LEFT JOIN users u ON t.email = u.email
    WHERE s.student_email = ?
    ORDER BY c.name
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_email);
$stmt->execute();
$result = $stmt->get_result();
$courses = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// استعلام لجلب المحاضرات لكل كورس
function getLecturesForCourse($conn, $course_id, $student_email) {
    $sql = "
        SELECT 
            l.lecture_date,
            DATE_FORMAT(l.lecture_date, '%Y-%m-%d') as formatted_date,
            CONCAT(DATE_FORMAT(c.start_time, '%h:%i %p'), ' - ', DATE_FORMAT(c.end_time, '%h:%i %p')) as time_slot,
            a.attendance_status
        FROM lectures l
        JOIN courses c ON l.course_id = c.id
        LEFT JOIN attendance a ON l.course_id = a.course_id 
            AND l.lecture_date = a.lecture_date 
            AND a.student_email = ?
        WHERE l.course_id = ?
        ORDER BY l.lecture_date
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $student_email, $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $lectures = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $lectures;
}

// الحصول على اسم الطالب
$student_sql = "SELECT firstname, lastname, profileimage FROM users WHERE email = ?";
$student_stmt = $conn->prepare($student_sql);
$student_stmt->bind_param("s", $student_email);
$student_stmt->execute();
$student_result = $student_stmt->get_result();
$student = $student_result->fetch_assoc();
$student_name = $student ? $student['firstname'] . ' ' . $student['lastname'] : 'Student';
$student_profile_image = $student && !empty($student['profileimage']) ? $student['profileimage'] : 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';
$student_stmt->close();
?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>EDVORA - My Courses</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="css/head.css">
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
                border: 2px solid white;
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
                max-height: 80vh; /* 80% من ارتفاع الشاشة */
                overflow-y: auto; /* يظهر الشريط فقط عند الحاجة */
            }

            .notifications-header {
                padding: 15px 20px;
                border-bottom: 1px solid #8e77c5;
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: rgb(102, 87, 136);
                border-radius: 10px 10px 0 0;
                position: sticky;
                top: 0;
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
                background: #665788;
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
                gap: 30px;
                margin-bottom: 40px;
            }

            .course-card {
                background: white;
                border-radius: var(--border-radius);
                box-shadow: var(--shadow);
                overflow: hidden;
                transition: var(--transition);
                border: 2px solid transparent;
                height: 550px;
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

            .course-progress {
                margin-bottom: 20px;
            }

            .progress-info {
                display: flex;
                justify-content: space-between;
                margin-bottom: 8px;
                font-size: 0.9rem;
                color: var(--gray);
            }

            .progress-bar {
                height: 6px;
                background: var(--gray-light);
                border-radius: 3px;
                overflow: hidden;
            }

            .progress-fill {
                height: 100%;
                background: linear-gradient(90deg, var(--primary), var(--primary-light));
                border-radius: 3px;
                transition: width 0.5s ease;
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

            /* Lectures Page Styles */
            .lectures-container {
                max-width: 1000px;
                margin: 0 auto;
                padding: 40px 20px;
            }

            .zoom-link-section {
                background: white;
                border-radius: var(--border-radius);
                padding: 25px;
                margin-bottom: 30px;
                box-shadow: var(--shadow);
                text-align: center;
            }

            .zoom-link {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                background: linear-gradient(135deg, var(--primary), var(--primary-dark));
                color: white;
                padding: 15px 25px;
                border-radius: var(--border-radius);
                text-decoration: none;
                font-weight: 600;
                transition: var(--transition);
            }

            .zoom-link:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(103, 87, 136, 0.3);
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
                grid-template-columns: 1fr 1fr 1fr auto;
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

            .attendance-status {
                padding: 6px 12px;
                border-radius: 20px;
                font-size: 0.8rem;
                font-weight: 600;
                text-transform: uppercase;
            }

            .status-present {
                background: rgba(76, 175, 80, 0.1);
                color: var(--success);
            }

            .status-absent {
                background: rgba(220, 53, 69, 0.1);
                color: #dc3545;
            }

            .status-upcoming {
                background: rgba(255, 193, 7, 0.1);
                color: #ffc107;
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

                .notifications-dropdown {
                    width: 300px;
                    right: -50px;
                }
            }

            @media (max-width: 480px) {
                .course-actions {
                    flex-direction: column;
                }

                .right-section {
                    gap: 10px;
                }

                .user-btn .username {
                    display: none;
                }
            }
        </style>
    </head>
    <body>
    <!-- Topbar -->
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
                            <?php if($notifications->num_rows > 0): ?>
                                <?php while($notification = $notifications->fetch_assoc()): ?>
                                    <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>"
                                         onclick="markNotificationAsRead(<?php echo $notification['notification_id']; ?>)">
                                        <div class="notification-icon-small info">
                                            <i class="fas fa-bell"></i>
                                        </div>
                                        <div class="notification-content">
                                            <div class="notification-title">Notification</div>
                                            <div class="notification-message"><?php echo htmlspecialchars($notification['notification_text']); ?></div>
                                            <div class="notification-time">
                                                <?php
                                                $time = strtotime($notification['created_at']);
                                                echo date('M j, Y - H:i', $time);
                                                ?>
                                            </div>
                                        </div>
                                        <?php if(!$notification['is_read']): ?>
                                            <div class="notification-dot"></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="no-notifications">
                                    <i class="fas fa-bell-slash"></i>
                                    <p>No notifications</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <button class="user-btn" onclick="window.location.href='StudentProfile.php'">
                    <img src="<?php echo htmlspecialchars($student_profile_image); ?>" alt="User Profile">
                    <span class="username"><?php echo htmlspecialchars($student_name); ?></span>
                </button>

                <button class="logout" onclick="window.location.href='logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>
    </header>

    <!-- Main Content - Courses Page -->
    <main class="main-content" id="coursesPage">
        <div class="courses-container">
            <div class="page-header">
                <h1 class="page-title">My Courses</h1>
                <p class="page-subtitle">Continue your learning journey with these enrolled programming courses</p>
            </div>

            <div class="courses-grid">
                <?php if (empty($courses)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 40px;">
                        <i class="fas fa-book-open fa-3x" style="color: #ccc; margin-bottom: 20px;"></i>
                        <h3>No courses enrolled yet</h3>
                        <p>Browse our courses to start learning!</p>
                        <a href="student_add_course.php" class="btn btn-primary" style="margin-top: 15px; display: inline-block;">
                            <i class="fas fa-plus"></i> Browse Courses
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($courses as $course): ?>
                        <?php
                        // جلب المحاضرات لهذا الكورس
                        $lectures = getLecturesForCourse($conn, $course['id'], $student_email);
                        $course_lectures_json = htmlspecialchars(json_encode($lectures), ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="course-card">
                            <div class="course-image">
                                <img src="<?php echo !empty($course['image']) ? htmlspecialchars($course['image']) : 'https://images.unsplash.com/photo-1555066931-4365d14bab8c?ixlib=rb-1.2.1&auto=format&fit=crop&w=500&q=80'; ?>" alt="<?php echo htmlspecialchars($course['name']); ?>">
                                <div class="course-duration">
                                    <?php echo htmlspecialchars($course['duration_number'] . ' ' . $course['duration_unit']); ?>
                                </div>
                            </div>
                            <div class="course-content">
                                <div class="course-info">
                                    <h3 class="course-title"><?php echo htmlspecialchars($course['name']); ?></h3>

                                    <div class="course-schedule">
                                        <i class="fas fa-clock schedule-icon"></i>
                                        <span class="schedule-time">
                                        <?php
                                        $start_time = date('h:i A', strtotime($course['start_time']));
                                        $end_time = date('h:i A', strtotime($course['end_time']));
                                        echo htmlspecialchars($start_time . ' - ' . $end_time);
                                        ?>
                                    </span>
                                        <span class="schedule-days"><?php echo htmlspecialchars($course['days']); ?></span>
                                    </div>

                                    <div class="instructor-info">
                                        <img src="<?php echo !empty($course['teacher_image']) ? htmlspecialchars($course['teacher_image']) : 'https://randomuser.me/api/portraits/men/32.jpg'; ?>" alt="Instructor" class="instructor-avatar">
                                        <div class="instructor-details">
                                            <h4><?php echo htmlspecialchars($course['teacher_firstname'] . ' ' . $course['teacher_lastname']); ?></h4>
                                            <p><?php echo htmlspecialchars($course['teacher_job'] ?? 'Instructor'); ?></p>
                                        </div>
                                    </div>

                                    <div class="course-progress">
                                        <div class="progress-info">
                                            <span>Progress</span>
                                            <span><?php echo htmlspecialchars($course['progress']); ?>%</span>
                                        </div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo htmlspecialchars($course['progress']); ?>%"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="course-actions">
                                    <button class="btn btn-primary" onclick="continueCourse(<?php echo $course['id']; ?>)">
                                        <i class="fas fa-play"></i> Continue
                                    </button>
                                    <button class="btn btn-outline" onclick="showLectures(<?php echo $course['id']; ?>, '<?php echo htmlspecialchars($course['name'], ENT_QUOTES); ?>', <?php echo $course_lectures_json; ?>)">
                                        <i class="fas fa-calendar-alt"></i> Lectures
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Lectures Page (Hidden by default) -->
    <main class="main-content" id="lecturesPage" style="display: none;">
        <div class="lectures-container">
            <a href="#" class="back-btn" onclick="showCourses()">
                <i class="fas fa-arrow-left"></i> Back to Courses
            </a>

            <div class="page-header">
                <h1 class="page-title" id="lectureCourseTitle">Course Lectures</h1>
                <p class="page-subtitle">View lecture schedule and attendance status</p>
            </div>

            <div class="zoom-link-section">
                <h3>Join Live Lectures</h3>
                <p style="margin-bottom: 15px; color: var(--gray);">Use this link to join all live lectures for this course:</p>
                <a href="#" class="zoom-link" target="_blank" id="zoomLink">
                    <i class="fas fa-video"></i> Join Zoom Meeting
                </a>
            </div>

            <div class="lectures-table">
                <div class="table-header">
                    <h3>Lecture Schedule</h3>
                </div>
                <div class="table-content" id="lecturesTable">
                    <!-- Lectures will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
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

    <script>
        // تفعيل قائمة الإشعارات
        document.addEventListener('DOMContentLoaded', function() {
            const notificationIcon = document.querySelector('.notification-icon');
            const notificationsDropdown = document.querySelector('.notifications-dropdown');

            if (notificationIcon && notificationsDropdown) {
                notificationIcon.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notificationsDropdown.style.display =
                        notificationsDropdown.style.display === 'block' ? 'none' : 'block';
                });

                // إغلاق القائمة عند النقر خارجها
                document.addEventListener('click', function() {
                    notificationsDropdown.style.display = 'none';
                });

                // منع إغلاق القائمة عند النقر داخلها
                notificationsDropdown.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }

            // تحريك أشرطة التقدم عند تحميل الصفحة
            const progressBars = document.querySelectorAll('.progress-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 300);
            });
        });

        let currentCourseId = null;
        let currentCourseName = '';

        // دالة للانتقال لمحتوى الكورس
        function continueCourse(courseId) {
            window.location.href = `CourseContent.php?course_id=${courseId}`;
        }

        // دالة لعرض صفحة المحاضرات
        function showLectures(courseId, courseName, lecturesData) {
            currentCourseId = courseId;
            currentCourseName = courseName;

            document.getElementById('coursesPage').style.display = 'none';
            document.getElementById('lecturesPage').style.display = 'block';

            // تعيين عنوان الكورس
            document.getElementById('lectureCourseTitle').textContent = courseName + ' - Lectures';

            // تعيين رابط الزوم
            document.getElementById('zoomLink').href = `https://zoom.us/j/${courseId}000`;

            // تعبئة جدول المحاضرات
            const table = document.getElementById('lecturesTable');
            table.innerHTML = '';

            if (lecturesData && lecturesData.length > 0) {
                lecturesData.forEach(lecture => {
                    const row = document.createElement('div');
                    row.className = 'lecture-row';

                    // تحديد حالة الحضور
                    let statusClass = '';
                    let statusText = '';
                    const lectureDate = new Date(lecture.formatted_date);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);

                    if (lecture.attendance_status === 'P') {
                        statusClass = 'status-present';
                        statusText = 'Present';
                    } else if (lecture.attendance_status === 'A') {
                        statusClass = 'status-absent';
                        statusText = 'Absent';
                    } else if (lectureDate > today) {
                        statusClass = 'status-upcoming';
                        statusText = 'Upcoming';
                    } else if (!lecture.attendance_status) {
                        statusClass = 'status-absent';
                        statusText = 'Absent';
                    }

                    row.innerHTML = `
                    <div class="lecture-date">${lecture.formatted_date}</div>
                    <div class="lecture-time">${lecture.time_slot}</div>
                    <div class="lecture-topic">Lecture</div>
                    <div class="attendance-status ${statusClass}">${statusText}</div>
                `;

                    table.appendChild(row);
                });
            } else {
                table.innerHTML = `
                <div style="text-align: center; padding: 40px; color: var(--gray);">
                    <i class="fas fa-calendar-times fa-3x" style="margin-bottom: 20px;"></i>
                    <h3>No lectures scheduled yet</h3>
                    <p>Check back later for lecture schedule</p>
                </div>
            `;
            }
        }

        // دالة للعودة لصفحة الكورسات
        function showCourses() {
            document.getElementById('lecturesPage').style.display = 'none';
            document.getElementById('coursesPage').style.display = 'block';
        }

        // دالة لتحديد الإشعار كمقروء
        function markNotificationAsRead(notificationId) {
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
                        // إزالة علامة "غير مقروء"
                        const notificationItem = document.querySelector(`.notification-item[onclick*="${notificationId}"]`);
                        if (notificationItem) {
                            notificationItem.classList.remove('unread');
                            notificationItem.querySelector('.notification-dot')?.remove();

                            // تحديث العداد
                            const badge = document.querySelector('.notification-badge');
                            if (badge) {
                                const currentCount = parseInt(badge.textContent);
                                if (currentCount > 1) {
                                    badge.textContent = currentCount - 1;
                                } else {
                                    badge.remove();
                                }
                            }
                        }
                    }
                });
        }

        // دالة لتحديد جميع الإشعارات كمقروءة
        function markAllAsRead() {
            fetch('mark_all_notifications_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // إزالة علامة "غير مقروء" من جميع الإشعارات
                        document.querySelectorAll('.notification-item.unread').forEach(item => {
                            item.classList.remove('unread');
                            item.querySelector('.notification-dot')?.remove();
                        });

                        // إزالة العداد
                        const badge = document.querySelector('.notification-badge');
                        if (badge) {
                            badge.remove();
                        }
                    }
                });
        }

    </script>
    </body>
    </html>
<?php
$conn->close();
?>