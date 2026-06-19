<?php
session_start();
require_once 'config/db_connection.php';

if (!isset($_SESSION['email']) || ($_SESSION['user_type'] ?? '') !== 'student') {
    header("Location: login.php");
    exit();
}
$user_email = $_SESSION['email'];
$student_name = $_SESSION['firstname'] . ' ' . $_SESSION['lastname'];

$student_query = "SELECT * FROM users WHERE email = ?";
$student_stmt = $conn->prepare($student_query);
$student_stmt->bind_param("s", $user_email);
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

// استعلام الكورسات المسجلة للطالب
$courses_query = "SELECT c.* FROM courses c
JOIN study s ON c.id = s.course_id
WHERE s.student_email = ?";
$courses_stmt = $conn->prepare($courses_query);
$courses_stmt->bind_param("s", $user_email);
$courses_stmt->execute();
$registered_courses = $courses_stmt->get_result();

// استعلام الإشعارات للطالب
$notifications_query = "SELECT * FROM notifications
WHERE user_email = ?
ORDER BY created_at DESC";
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

// استعلام صور المعرض
$gallery_query = "SELECT * FROM gallery ORDER BY id";
$gallery_result = $conn->query($gallery_query);
$gallery_items = $gallery_result->fetch_all(MYSQLI_ASSOC);

// =================== حساب الإحصائيات ديناميكياً ===================
// =================== حساب الإحصائيات ديناميكياً ===================

// 1. عدد الطلاب المسجلين (من جدول students)
$students_count_query = "SELECT COUNT(*) as count FROM students";
$students_count_result = $conn->query($students_count_query);
$students_count = $students_count_result->fetch_assoc()['count'] ?? 0;

// 2. حساب عدد الخريجين (الطلاب الذين اجتازوا جميع كورساتهم)
$graduates_count = 0;

// استعلام للطلاب الذين اجتازوا جميع كورساتهم
$graduates_query = "
    SELECT s.student_email 
    FROM studentcoursestatus s
    JOIN (
        SELECT student_email, COUNT(*) as total_courses
        FROM studentcoursestatus 
        WHERE status IN ('Passed', 'Failed', 'In Progress')
        GROUP BY student_email
    ) t ON s.student_email = t.student_email
    WHERE s.status = 'Passed'
    GROUP BY s.student_email
    HAVING COUNT(*) = MAX(t.total_courses)
";

$graduates_result = $conn->query($graduates_query);
if ($graduates_result && $graduates_result->num_rows > 0) {
    $graduates_count = $graduates_result->num_rows;
} else {
    // بديل: حساب عدد الطلاب الذين لديهم حالة 'Passed' في أي كورس
    $grad_query = "SELECT COUNT(DISTINCT student_email) as count 
                   FROM studentcoursestatus 
                   WHERE status = 'Passed'";
    $grad_result = $conn->query($grad_query);
    if ($grad_result) {
        $graduates_count = $grad_result->fetch_assoc()['count'] ?? 0;
    }
}

// 3. عدد الكورسات المتاحة (من جدول courses)
$courses_count_query = "SELECT COUNT(*) as count FROM courses";
$courses_count_result = $conn->query($courses_count_query);
$courses_count = $courses_count_result->fetch_assoc()['count'] ?? 0;

// 4. عدد الجوائز والإنجازات
$awards_count = 25;


// استعلام وصف الكورس
$course_info_query = "SELECT * FROM course_info ORDER BY id DESC LIMIT 1";
$course_info_result = $conn->query($course_info_query);
$course_info = $course_info_result->fetch_assoc();

// إذا لم تكن هناك إحصائيات، أنشئ مصفوفة فارغة
$stats = [
        'students_count' => $students_count,
        'graduates_count' => $graduates_count,
        'courses_count' => $courses_count,
        'awards_count' => $awards_count
];
?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Edavora</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="css/ManagerHome.css">
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

            /* تحديث أرقام الإحصائيات مع تأثير */
            .stat-number {
                font-size: 2.5rem;
                font-weight: bold;
                color: #49386e;
                transition: all 0.5s ease;
            }

            .stat-card:hover .stat-number {
                transform: scale(1.1);
                color: #675788;
            }

            .stat-label {
                font-size: 1rem;
                color: #666;
                margin-top: 10px;
            }

            .stat-icon {
                width: 60px;
                height: 60px;
                background: linear-gradient(135deg, #675788, #49386e);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 15px;
            }

            .stat-icon i {
                font-size: 1.8rem;
                color: white;
            }


            @keyframes countUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .counting {
                animation: countUp 1s ease-out;
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
                <a href="student_home.php" class="active">Home</a>
                <a href="student_mycourse.php">My Courses</a>
                <a href="student_add_course.php" >Add Courses</a>
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

                <!-- الكود الصحيح -->
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

    <section class="slider-section">
        <h2 class="section-title">Course Gallery</h2>
        <div class="slider-container">
            <div class="main-slider" id="mainSlider">
                <?php foreach($gallery_items as $index => $item): ?>
                    <div class="slide <?php echo $index === 0 ? 'active' : ''; ?>">
                        <img src="<?php echo $item['image_url']; ?>" alt="<?php echo $item['title']; ?>"
                             onclick="openModal('<?php echo $item['image_url']; ?>')">
                        <div class="slide-content">
                            <div class="slide-title"><?php echo $item['title']; ?></div>
                            <div class="slide-description"><?php echo $item['description']; ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
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
            <?php foreach($gallery_items as $index => $item): ?>
                <div class="thumbnail <?php echo $index === 0 ? 'active' : ''; ?>">
                    <img src="<?php echo $item['image_url']; ?>" alt="<?php echo $item['title']; ?>"
                         onclick="goToSlide(<?php echo $index; ?>)">
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Text Editor Section (Read Only) -->
    <section class="text-editor-section">
        <div class="text-editor-header">
            <h2>Course Description</h2>
            <div class="editor-tools"></div>
        </div>
        <div class="text-editor-content" id="textContent" contenteditable="false">
            <?php  echo nl2br(htmlspecialchars($course_info['course_description']))??'<p>No course description available.</p>'; ?>
        </div>
    </section>

    <!-- Statistics Section (Read Only) -->
    <section class="stats-section">
        <h2 class="section-title">Academy Statistics</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number" id="studentsCount"><?php echo number_format($stats['students_count']); ?></div>
                <div class="stat-label">Total Registered Students</div>
                <input type="number" class="stat-input" id="studentsInput" value="<?php echo $stats['students_count']; ?>" min="0" readonly>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="stat-number" id="graduatesCount"><?php echo number_format($stats['graduates_count']); ?></div>
                <div class="stat-label">Successful Graduates</div>
                <input type="number" class="stat-input" id="graduatesInput" value="<?php echo $stats['graduates_count']; ?>" min="0" readonly>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-number" id="coursesCount"><?php echo number_format($stats['courses_count']); ?></div>
                <div class="stat-label">Available Courses</div>
                <input type="number" class="stat-input" id="coursesInput" value="<?php echo $stats['courses_count']; ?>" min="0" readonly>
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
        // Slider functionality
        let currentSlide = 0;
        let autoPlayInterval;
        const slides = document.querySelectorAll('.slide');
        const totalSlides = slides.length;

        // DOM Elements
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const imageModal = document.getElementById('imageModal');
        const modalImage = document.getElementById('modalImage');
        const closeModal = document.getElementById('closeModal');

        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            startAutoPlay();
            animateStats(); // تحريك أرقام الإحصائيات
        });

        // تحريك أرقام الإحصائيات
        function animateStats() {
            const stats = document.querySelectorAll('.stat-number');
            stats.forEach(stat => {
                const finalValue = parseInt(stat.textContent.replace(/,/g, ''));
                let currentValue = 0;
                const duration = 2000; // 2 ثانية
                const increment = Math.ceil(finalValue / (duration / 30)); // 30 إطار في الثانية

                const timer = setInterval(() => {
                    currentValue += increment;
                    if (currentValue >= finalValue) {
                        currentValue = finalValue;
                        clearInterval(timer);
                    }
                    stat.textContent = currentValue.toLocaleString();
                    stat.classList.add('counting');
                }, 30);
            });
        }

        // Set up event listeners
        function setupEventListeners() {
            prevBtn.addEventListener('click', prevSlide);
            nextBtn.addEventListener('click', nextSlide);
            closeModal.addEventListener('click', closeImageModal);

            // Message modal functionality
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
            document.getElementById('sendMessage')?.addEventListener('click', sendMessage);

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
        }

        // Start auto-play
        function startAutoPlay() {
            autoPlayInterval = setInterval(nextSlide, 5000);
        }

        // Stop auto-play (optional)
        function stopAutoPlay() {
            clearInterval(autoPlayInterval);
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
            currentSlide = (currentSlide + 1) % totalSlides;
            updateSlider();
        }

        // Previous slide
        function prevSlide() {
            currentSlide = (currentSlide - 1 + totalSlides) % totalSlides;
            updateSlider();
        }

        // Open full image view
        function openModal(imageUrl) {
            modalImage.src = imageUrl;
            imageModal.classList.add('active');
            stopAutoPlay();
        }

        // Close full view
        function closeImageModal() {
            imageModal.classList.remove('active');
            startAutoPlay();
        }

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

        // Load recipients for message modal
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

                // Select all functionality
                document.getElementById('selectAll').onclick = function() {
                    container.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                        checkbox.checked = true;
                    });
                };
            } catch (error) {
                console.error('Error loading recipients:', error);
            }
        }

        // Send message from modal
        async function sendMessage() {
            const recipients = [];
            document.querySelectorAll('#recipientsContainer input:checked').forEach(checkbox => {
                recipients.push(checkbox.value);
            });

            const messageText = document.getElementById('messageText').value.trim();

            if (recipients.length === 0 || !messageText) {
                alert('Please select at least one recipient and enter a message.');
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

                    // Clear recipients selection
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
            }
        }

        // Open conversation
        async function openConversation(email, name) {
            document.getElementById('conversationsList').style.display = 'none';
            document.getElementById('conversationView').style.display = 'block';
            document.getElementById('conversationUserName').textContent = name;

            // Store current conversation email
            document.getElementById('conversationView').dataset.recipientEmail = email;

            await loadMessages(email);
        }

        // Load messages for conversation
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

                // Mark messages as read
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

        // Send message in conversation
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

        // Format time
        function formatTime(timestamp) {
            const date = new Date(timestamp);
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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
<?php
$conn->close();
?>