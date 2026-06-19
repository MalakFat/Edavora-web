<?php
session_start();
require("config/db_connection.php");

// Check login
if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

$user_email = $_SESSION['email'];

// =============================
// 1. Get student data
// =============================
$stmt = $conn->prepare("SELECT u.firstname, u.lastname, u.birthdate, u.gender, u.email, u.profileimage, u.password, s.balance 
                       FROM users u 
                       JOIN students s ON u.email = s.email 
                       WHERE u.email = ?");
if ($stmt === false) die("Error: " . $conn->error);
$stmt->bind_param("s", $user_email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Set default image if empty
$profile_image = !empty($user['profileimage']) ? $user['profileimage'] : 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';

// =============================
// 2. Update profile + image (only when saving)
// =============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $firstname = trim($_POST['firstname']);
    $lastname  = trim($_POST['lastname']);
    $birthdate = $_POST['birthdate'];
    $gender    = $_POST['gender'] === 'male' ? 'male' : 'female';

    $profileimage = $user['profileimage'];

    // Handle image upload only if new image was selected
    if (isset($_FILES['profileimage']) && $_FILES['profileimage']['error'] === 0 && $_FILES['profileimage']['size'] > 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $ext = strtolower(pathinfo($_FILES['profileimage']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $dir = "uploads/students/profiles/";
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            // Generate unique filename
            $new_filename = uniqid() . "_" . $user_email . "." . $ext;
            $profileimage = $dir . $new_filename;

            // Delete old image if not default
            if (!empty($user['profileimage']) && $user['profileimage'] != 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png') {
                if (file_exists($user['profileimage'])) {
                    unlink($user['profileimage']);
                }
            }

            // Move uploaded file
            if (move_uploaded_file($_FILES['profileimage']['tmp_name'], $profileimage)) {
                // Image successfully uploaded
            } else {
                $profileimage = $user['profileimage']; // Keep old image if upload fails
            }
        }
    }

    $stmt = $conn->prepare("UPDATE users SET firstname=?, lastname=?, birthdate=?, gender=?, profileimage=? WHERE email=?");
    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("ssssss", $firstname, $lastname, $birthdate, $gender, $profileimage, $user_email);

    if ($stmt->execute()) {
        $_SESSION['firstname'] = $firstname;
        $_SESSION['lastname'] = $lastname;

        echo json_encode(['success' => true, 'message' => 'Profile updated successfully!', 'newImage' => $profileimage]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $stmt->error]);
    }
    $stmt->close();
    exit();
}

// =============================
// 3. Change password with hash verification
// =============================
if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current = $_POST['current_password'];
    $new     = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    // Verify current password with hashed password in database
    if (password_verify($current, $user['password'])) {
        if ($new === $confirm && strlen($new) >= 8) {
            $hashed_password = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
            $stmt->bind_param("ss", $hashed_password, $user_email);

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

// =============================
// 4. Notifications query
// =============================
$notifications_query = "SELECT * FROM notifications WHERE user_email = ? ORDER BY created_at DESC";
$notifications_stmt = $conn->prepare($notifications_query);
$notifications_stmt->bind_param("s", $user_email);
$notifications_stmt->execute();
$notifications_result = $notifications_stmt->get_result();

// Count unread notifications
$unread_query = "SELECT COUNT(*) as count FROM notifications WHERE user_email = ? AND is_read = 0";
$unread_stmt = $conn->prepare($unread_query);
$unread_stmt->bind_param("s", $user_email);
$unread_stmt->execute();
$unread_result = $unread_stmt->get_result();
$unread_data = $unread_result->fetch_assoc();
$unread_count = $unread_data['count'] ?? 0;

// =============================
// 5. Get certificates from database
// =============================
$certificates = [];

// Get certificates from student_certificates or graduates table
$cert_stmt = $conn->prepare("
    SELECT 
        g.course_id,
        c.name as course_name,
        g.completion_date as issue_date,
        u.firstname,
        u.lastname
    FROM graduates g
    JOIN users u ON g.student_email = u.email
    JOIN courses c ON g.course_id = c.id
    WHERE g.student_email = ?
    ORDER BY g.completion_date DESC
");

if ($cert_stmt) {
    $cert_stmt->bind_param("s", $user_email);
    $cert_stmt->execute();
    $cert_result = $cert_stmt->get_result();

    while ($cert = $cert_result->fetch_assoc()) {
        $certificates[] = $cert;
    }
    $cert_stmt->close();
}

$conn->close();

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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDVORA - Student Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/head.css">
    <link rel="stylesheet" href="css/ManagerProfile.css">
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
            background: #665788;
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

        /* باقي الـ CSS كما هو */
        .balance-display {
            font-size: 18px;
            font-weight: bold;
            color: #28a745;
        }

        .readonly-field {
            background-color: #f5f5f5;
            cursor: not-allowed;
        }

        /* إضافة CSS للشهادات الجديدة */
        .certificates-list {
            display: grid;
            gap: 20px;
            margin-top: 20px;
        }

        .cert-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .cert-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .certificate-card {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .thumbnail-wrapper {
            width: 150px;
            height: 150px;
            background: #f5f5f5;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid #675788;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .cert-thumbnail {
            width: 100%;
            height: 100%;
            object-fit: cover;
            cursor: pointer;
        }

        .cert-info {
            flex-grow: 1;
        }

        .cert-info h4 {
            margin: 0 0 5px 0;
            font-size: 20px;
            font-weight: 600;
            color: #333;
        }

        .cert-info p {
            margin: 0;
            font-size: 15px;
            color: #6c757d;
        }

        .cert-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .view-btn, .download-btn {
            background-color: #675788;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background-color 0.3s;
        }

        .view-btn:hover, .download-btn:hover {
            background-color: #5a4a70;
        }

        .empty-state {
            text-align: center;
            padding: 50px;
            color: #666;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ddd;
        }

        .loading-spinner {
            font-size: 12px;
            color: #888;
            padding: 10px;
        }

        /* مودال الشهادة */
        .certificate-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.8);
            overflow: auto;
        }

        .close-modal {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            position: absolute;
            right: 20px;
            top: 10px;
            z-index: 1;
        }

        .close-modal:hover {
            color: #000;
        }

        .modal-body {
            padding: 20px;
            text-align: center;
            overflow: hidden;
        }

        .modal-footer {
            padding: 20px;
            text-align: center;
        }

        .modal-dialog {
            max-width: 90vw;
        }

        .modal-body {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            overflow-x: hidden;
        }

        #modalCertImage {
            max-width: 100%;
            width: auto;
            height: auto;
            display: block;
            margin: 0 auto;
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
                        window.location.href = "student_home.php";
                    });
                </script>
                <div class="logo-text">
                    <span class="logo-main">EDVORA</span>
                    <span class="logo-sub">EDUCATIONAL ACADEMY</span>
                </div>
            </div>
        </div>

        <nav class="main-menu">
            <a href="student_home.php">Home</a>
            <a href="student_mycourse.php">My Courses</a>
            <a href="student_add_course.php">Add Course</a>
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
                        <?php if($unread_count > 0): ?>
                            <button class="mark-all-read" onclick="markAllAsRead()">Mark all as read</button>
                        <?php endif; ?>
                    </div>
                    <div class="notifications-list">
                        <?php if($notifications_result->num_rows > 0): ?>
                            <?php while($notification = $notifications_result->fetch_assoc()): ?>
                                <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>"
                                     data-id="<?php echo $notification['notification_id']; ?>"
                                     onclick="markNotificationAsRead(<?php echo $notification['notification_id']; ?>)">
                                    <div class="notification-icon-small info">
                                        <i class="fas fa-bell"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">Notification</div>
                                        <div class="notification-message"><?php echo htmlspecialchars($notification['notification_text']); ?></div>
                                        <div class="notification-time"><?php echo time_ago($notification['created_at']); ?></div>
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

            <button class="user-btn" id="mybutton">
                <img src="<?php echo $profile_image; ?>" alt="User" id="headerProfileImage">
                <span class="username"><?php echo htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')); ?></span>
            </button>

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

<div class="settings-container">
    <aside class="sidebar">
        <h2><i class="fas fa-sliders-h"></i> Settings</h2>
        <ul>
            <li class="tab-link active" data-tab="profile">
                <i class="fas fa-user-circle"></i> Profile Information
            </li>
            <li class="tab-link" data-tab="password">
                <i class="fas fa-lock"></i> Password
            </li>
            <li class="tab-link" data-tab="Certificate">
                <i class="fa-solid fa-certificate"></i> Certificate
            </li>
        </ul>
    </aside>

    <main class="content">
        <!-- Profile Tab -->
        <section id="profile" class="tab-content active">
            <div class="tab-header">
                <h3>Profile Information</h3>
            </div>

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
                        <input type="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" disabled>
                    </div>

                    <div class="form-group">
                        <label>First name:</label>
                        <input type="text" name="firstname" value="<?php echo htmlspecialchars($user['firstname'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Last name:</label>
                        <input type="text" name="lastname" value="<?php echo htmlspecialchars($user['lastname'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Date of birth:</label>
                        <input type="date" name="birthdate" value="<?php echo htmlspecialchars($user['birthdate'] ?? ''); ?>" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group gender-group">
                            <label>Gender:</label>
                            <div class="gender">
                                <label><input type="radio" name="gender" value="male" <?php echo (($user['gender'] ?? '') === 'male') ? 'checked' : ''; ?>> Male</label>
                                <label><input type="radio" name="gender" value="female" <?php echo (($user['gender'] ?? '') === 'female') ? 'checked' : ''; ?>> Female</label>
                            </div>
                        </div>

                        <div class="form-group balance-group">
                            <label>Balance:</label>
                            <input type="text" value="<?php echo htmlspecialchars($user['balance'] ?? 0); ?>$" readonly class="balance-display readonly-field">
                        </div>
                    </div>

                    <button type="submit" class="update-btn"><i class="fas fa-save"></i> Update Profile</button>
                </form>
            </div>
        </section>

        <!-- Password Tab -->
        <section id="password" class="tab-content">
            <div class="tab-header">
                <h3>Change Password</h3>
            </div>

            <form id="passwordForm" class="simple-form">
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label>Current Password:</label>
                    <input type="password" name="current_password" required>
                </div>

                <div class="form-group">
                    <label>New Password:</label>
                    <input type="password" name="new_password" required minlength="8">
                </div>

                <div class="form-group">
                    <label>Confirm Password:</label>
                    <input type="password" name="confirm_password" required>
                </div>

                <button type="submit" class="update-btn">Save Changes</button>
            </form>
        </section>

        <!-- Certificate Tab -->
        <section id="Certificate" class="tab-content">
            <div class="tab-header">
                <h3><i class="fas fa-certificate"></i> My Certificates</h3>
            </div>

            <div class="certificates-list" id="certificatesContainer">
                <!-- سيتم ملؤه بواسطة JavaScript -->
            </div>

            <!-- Certificate Modal -->
            <div class="certificate-modal" id="certificateModal">
                <div class="modal-content">
                    <span class="close-modal" id="closeModal">&times;</span>
                    <div class="modal-body">
                        <img id="modalCertImage" src="" alt="Certificate Preview" style="width: 100%; height: auto; border-radius: 8px;">
                    </div>
                    <div class="modal-footer">
                        <a id="downloadBtn" class="update-btn" style="text-decoration: none; display: inline-block; text-align: center; padding: 10px 20px;" download="Certificate.png">
                            <i class="fas fa-download"></i> Download Certificate
                        </a>
                    </div>
                </div>
            </div>
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
    // =============================
    // Certificate generation and display system
    // =============================

    // Certificate settings
    const certificateConfig = {
        templateSrc: 'img/cer.png',
        fontBase: 'Palatino Linotype',
        textColor: '#2f2f2f',

        name:   { yRatio: .43, fontSize: 100, weight: 'bold' },
        course: { yRatio: 0.65, fontSize: 70, weight: 'normal' },
        date:   { yRatio: 0.83, fontSize: 30, weight: 'normal' }
    };

    // Certificate data from PHP
    const certificatesData = <?php echo json_encode($certificates); ?>;
    const studentFullName = "<?php echo htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')); ?>";

    // Function to generate certificate
    function generateCertificate(studentName, courseName, dateStr, callback) {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const img = new Image();

        img.src = certificateConfig.templateSrc + '?t=' + Date.now();
        img.onload = function () {
            canvas.width = img.width;
            canvas.height = img.height;

            ctx.drawImage(img, 0, 0);

            const centerX = canvas.width / 2;
            ctx.textAlign = 'center';
            ctx.fillStyle = certificateConfig.textColor;

            /* ===== Student Name ===== */
            ctx.font = `${certificateConfig.name.weight} ${certificateConfig.name.fontSize}px ${certificateConfig.fontBase}`;
            ctx.fillText(
                studentName,
                centerX,
                canvas.height * certificateConfig.name.yRatio
            );

            /* ===== Course Name ===== */
            ctx.font = `${certificateConfig.course.weight} ${certificateConfig.course.fontSize}px ${certificateConfig.fontBase}`;
            ctx.fillText(
                courseName,
                centerX,
                canvas.height * certificateConfig.course.yRatio
            );

            /* ===== Date ===== */
            ctx.font = `${certificateConfig.date.weight} ${certificateConfig.date.fontSize}px ${certificateConfig.fontBase}`;
            ctx.fillText(
                dateStr,
                centerX,
                canvas.height * certificateConfig.date.yRatio
            );

            callback(canvas.toDataURL('image/png'));
        };
    }

    // Function to load and display certificates
    function loadCertificates() {
        const container = document.getElementById('certificatesContainer');

        if (!container) return;

        container.innerHTML = '';

        if (!certificatesData || certificatesData.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-certificate"></i>
                    <p>No certificates available yet</p>
                    <p style="font-size: 14px; margin-top: 10px;">Complete courses to earn certificates</p>
                </div>
            `;
            return;
        }

        certificatesData.forEach((cert, index) => {
            // Create certificate card
            const certCard = document.createElement('div');
            certCard.className = 'cert-card';
            certCard.id = `cert-${index}`;

            certCard.innerHTML = `
                <div class="certificate-card">
                    <div class="thumbnail-wrapper">
                        <div class="loading-spinner">Generating...</div>
                    </div>

                    <div class="cert-info">
                        <h4>${cert.course_name}</h4>
                        <p>
                            <i class="far fa-calendar-alt"></i> ${cert.issue_date}
                        </p>
                    </div>

                    <div class="cert-actions">
                        <button class="view-btn" data-index="${index}">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button class="download-btn" data-index="${index}">
                            <i class="fas fa-download"></i> Download
                        </button>
                    </div>
                </div>
            `;

            container.appendChild(certCard);

            // Generate certificate
            generateCertificate(studentFullName, cert.course_name, cert.issue_date, (imgDataUrl) => {
                const thumbImg = document.createElement('img');
                thumbImg.src = imgDataUrl;
                thumbImg.className = 'cert-thumbnail';
                thumbImg.alt = `${cert.course_name} Certificate`;
                thumbImg.style.width = '100%';
                thumbImg.style.height = '100%';
                thumbImg.style.objectFit = 'cover';
                thumbImg.style.margin = 0;

                const thumbWrapper = certCard.querySelector('.thumbnail-wrapper');
                if (thumbWrapper) {
                    thumbWrapper.innerHTML = '';
                    thumbWrapper.appendChild(thumbImg);

                    // Add click event to thumbnail
                    thumbImg.addEventListener('click', () => {
                        viewCertificate(imgDataUrl, cert.course_name);
                    });
                }

                // Add button events
                const viewBtn = certCard.querySelector('.view-btn');
                const downloadBtn = certCard.querySelector('.download-btn');

                if (viewBtn) {
                    viewBtn.addEventListener('click', () => {
                        viewCertificate(imgDataUrl, cert.course_name);
                    });
                }

                if (downloadBtn) {
                    downloadBtn.addEventListener('click', () => {
                        downloadCertificate(imgDataUrl, cert.course_name);
                    });
                }
            });
        });
    }

    // Function to view certificate in modal
    function viewCertificate(imgDataUrl, courseName) {
        const modal = document.getElementById('certificateModal');
        const modalImg = document.getElementById('modalCertImage');
        const downloadBtn = document.getElementById('downloadBtn');

        modalImg.src = imgDataUrl;
        downloadBtn.href = imgDataUrl;
        downloadBtn.download = `Certificate-${courseName.replace(/\s+/g, '-')}.png`;
        modal.style.display = 'block';
    }

    // Function to download certificate
    function downloadCertificate(imgDataUrl, courseName) {
        const link = document.createElement('a');
        link.href = imgDataUrl;
        link.download = `Certificate-${courseName.replace(/\s+/g, '-')}.png`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Function to close modal
    function closeCertificateModal() {
        const modal = document.getElementById('certificateModal');
        modal.style.display = 'none';
    }

    // =============================
    // Profile image preview functionality
    // =============================
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

    // =============================
    // Main Initialization
    // =============================
    document.addEventListener('DOMContentLoaded', function() {
        // Enable notifications menu
        const notificationIcon = document.querySelector('.notification-icon');
        const notificationsDropdown = document.querySelector('.notifications-dropdown');

        if (notificationIcon && notificationsDropdown) {
            notificationIcon.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationsDropdown.style.display =
                    notificationsDropdown.style.display === 'block' ? 'none' : 'block';
            });

            // Close menu when clicking outside
            document.addEventListener('click', function() {
                notificationsDropdown.style.display = 'none';
            });

            // Prevent menu from closing when clicking inside
            notificationsDropdown.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }

        // Tab switching functionality
        const tabLinks = document.querySelectorAll('.tab-link');

        tabLinks.forEach(link => {
            link.addEventListener('click', function() {
                // Remove active class from all tabs
                tabLinks.forEach(tab => tab.classList.remove('active'));

                // Add active class to clicked tab
                this.classList.add('active');

                // Hide all tab content
                const tabContents = document.querySelectorAll('.tab-content');
                tabContents.forEach(content => content.classList.remove('active'));

                // Show the selected tab content
                const tabId = this.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');

                // If certificate tab is opened, load certificates
                if(tabId === 'Certificate') {
                    loadCertificates();
                }
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

        // Close modal
        const closeBtn = document.getElementById('closeModal');
        if (closeBtn) {
            closeBtn.onclick = function() {
                closeCertificateModal();
            }
        }

        window.onclick = function(event) {
            const modal = document.getElementById('certificateModal');
            if (event.target == modal) {
                closeCertificateModal();
            }
        }

        // Load certificates if certificate tab is open on load
        if (document.getElementById('Certificate').classList.contains('active')) {
            loadCertificates();
        }
    });

    // Function to mark notification as read
    function markNotificationAsRead(notificationId) {
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

    // Function to mark all notifications as read
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