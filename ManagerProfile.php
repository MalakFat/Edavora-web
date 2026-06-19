<?php
session_start();

// Database connection
$conn = new mysqli("localhost", "root", "", "edavora");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

// =============================
// Check login as manager
// =============================
if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

$manager_email = $_SESSION['email'];

// Verify user exists in manager table
$check_manager = $conn->prepare("SELECT email FROM manager WHERE email = ?");
$check_manager->bind_param("s", $manager_email);
$check_manager->execute();
$check_manager->store_result();

if ($check_manager->num_rows === 0) {
    // User is not a manager
    header("Location: access_denied.php");
    exit();
}

// =============================
// 1. Get manager data
// =============================
$stmt = $conn->prepare("SELECT firstname, lastname, birthdate, gender, profileimage, password FROM users WHERE email = ?");
$stmt->bind_param("s", $manager_email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Set default image if empty
$profile_image = !empty($user['profileimage']) ? $user['profileimage'] : 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png';

// =============================
// 2. Update profile + image (only when saving)
// =============================
if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $firstname = trim($_POST['firstname']);
    $lastname  = trim($_POST['lastname']);
    $birthdate = $_POST['birthdate'];
    $gender    = $_POST['gender'] === 'Male' ? 'Male' : 'Female';

    $profileimage = $user['profileimage'];

    // Handle image upload only if new image was selected
    if (isset($_FILES['profileimage']) && $_FILES['profileimage']['error'] === 0 && $_FILES['profileimage']['size'] > 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $ext = strtolower(pathinfo($_FILES['profileimage']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $dir = "uploads/profiles/";
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            // Generate unique filename
            $new_filename = uniqid() . "_" . $manager_email . "." . $ext;
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
    $stmt->bind_param("ssssss", $firstname, $lastname, $birthdate, $gender, $profileimage, $manager_email);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully', 'newImage' => $profileimage]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating data']);
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
            $stmt->bind_param("ss", $hashed_password, $manager_email);

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

if (isset($_POST['action']) && $_POST['action'] === 'delete_loss' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    if ($conn->query("DELETE FROM additional_losses WHERE id = $id")) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error deleting']);
    }
    exit();
}

// =============================
// 5. Add or edit loss
// =============================
if (isset($_POST['action']) && $_POST['action'] === 'save_loss') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $desc = trim($_POST['description']);
    $amount = (float)$_POST['amount'];

    if ($id) {
        $stmt = $conn->prepare("UPDATE additional_losses SET description=?, amount=? WHERE id=?");
        $stmt->bind_param("sdi", $desc, $amount, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO additional_losses (description, amount) VALUES (?, ?)");
        $stmt->bind_param("sd", $desc, $amount);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error saving data']);
    }
    $stmt->close();
    exit();
}

// =============================
// 10. Mark all notifications as read
// =============================
if (isset($_POST['action']) && $_POST['action'] === 'mark_all_notifications_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_email = ?");
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
// 6. Course revenues
// =============================
$revenues_html = '';
$total_revenues = 0;

$courses = $conn->query("
    SELECT c.id, c.name, c.price, 
           COUNT(DISTINCT s.student_email) AS students
    FROM courses c
    LEFT JOIN study s ON c.id = s.course_id
    GROUP BY c.id
    ORDER BY c.name
");

if ($courses && $courses->num_rows > 0) {
    while ($c = $courses->fetch_assoc()) {
        $rev = $c['price'] * $c['students'];
        $total_revenues += $rev;
        $revenues_html .= "<div class='financial-item revenue-item'>
            <span class='course-name'>{$c['name']}</span>
            <span class='course-price'>$" . number_format($c['price'],2) . "</span>
            <span class='course-students'>{$c['students']}</span>
            <span class='revenue-amount'>$" . number_format($rev,2) . "</span>
        </div>";
    }
} else {
    $revenues_html .= '<div style="padding:20px;text-align:center;color:#999">There are no registered courses yet</div>';
}

// =============================
// 7. Teacher salaries
// =============================
$teacher_salaries = 0;
$salaries_result = $conn->query("SELECT SUM(salary) AS total FROM teachers");
if ($salaries_result && $row = $salaries_result->fetch_assoc()) {
    $teacher_salaries = isset($row['total'])?$row['total']: 0;
}

// =============================
// 8. Additional losses
// =============================
$losses_html = '';
$total_losses = 0;

$losses_result = $conn->query("SELECT id, description, amount FROM additional_losses ORDER BY id");

if ($losses_result && $losses_result->num_rows > 0) {
    while ($l = $losses_result->fetch_assoc()) {
        $total_losses += $l['amount'];
        $losses_html .= "<div class='financial-item editable' data-id='{$l['id']}'>
            <input type='text' class='item-input' value='" . htmlspecialchars($l['description']) . "'>
            <input type='number' step='0.01' class='amount-input' value='{$l['amount']}'>
            <button class='delete-btn' onclick='deleteLoss({$l['id']})'>Delete</button>
            <button class='save-btn' onclick='saveLoss({$l['id']})'>Save</button>
        </div>";
    }
}

// =============================
// 9. Get notifications
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
// 11. Final calculation
// =============================
$total_expenses = $teacher_salaries + $total_losses;
$net_profit = $total_revenues - $total_expenses;

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDVORA - Manager Profile</title>
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
            top: 100%;
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

        .save-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            width: 100px;
        }

        .save-btn:hover {
            background: #218838;
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

                <!-- Notifications dropdown -->
                <div class="notifications-dropdown">
                    <div class="notifications-header">
                        <h3>Notifications</h3>
                        <?php if ($unread_notifications_count > 0): ?>
                            <button class="mark-all-read" onclick="markAllNotificationsRead()">Mark all as read</button>
                        <?php endif; ?>
                    </div>
                    <div class="notifications-list">
                        <?php if (count($notifications) > 0): ?>
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $notification['id']; ?>">
                                    <div class="notification-icon-small info">
                                        <i class="fas fa-info-circle"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">Notification</div>
                                        <div class="notification-message"><?php echo htmlspecialchars($notification['text']); ?></div>
                                        <div class="notification-time"><?php echo $notification['time_ago']; ?></div>
                                    </div>
                                    <?php if (!$notification['is_read']): ?>
                                        <div class="notification-dot"></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
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
                <span class="username"><?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname']); ?></span>
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

<div class="settings-container">
    <aside class="sidebar">
        <h2><i class="fas fa-sliders-h"></i> Settings</h2>
        <ul>
            <li class="tab-link active" data-tab="profile">Profile Information</li>
            <li class="tab-link" data-tab="password">Password</li>
            <li class="tab-link" data-tab="profit-loss">Profit & Loss</li>
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
                    <button class="change-photo" onclick="document.getElementById('fileInput').click()">Change profile photo</button>
                </div>
                <form id="profileForm" class="info-form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-group"><label>Email:</label><input type="email" value="<?php echo htmlspecialchars($manager_email); ?>" disabled></div>
                    <div class="form-group"><label>First name:</label><input type="text" name="firstname" value="<?php echo htmlspecialchars($user['firstname']); ?>" required></div>
                    <div class="form-group"><label>Last name:</label><input type="text" name="lastname" value="<?php echo htmlspecialchars($user['lastname']); ?>" required></div>
                    <div class="form-group"><label>Date of birth:</label><input type="date" name="birthdate" value="<?php echo $user['birthdate']; ?>" required></div>
                    <div class="form-group full-width"><label>Gender:</label>
                        <div class="gender">
                            <label><input type="radio" name="gender" value="Male" <?php echo ($user['gender']=='Male')?'checked':''; ?>> Male</label>
                            <label><input type="radio" name="gender" value="Female" <?php echo ($user['gender']=='Female')?'checked':''; ?>> Female</label>
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

        <!-- Profit & Loss Tab -->
        <section id="profit-loss" class="tab-content">
            <div class="tab-header"><h3>Profit & Loss Analysis</h3></div>
            <div class="profit-loss-container">
                <!-- Revenues -->
                <div class="financial-section">
                    <div class="section-header"><h4>Revenues</h4></div>
                    <div class="financial-items">
                        <div class="revenue-header"><span>Course Name</span><span>Price</span><span>Students</span><span>Revenue</span></div>
                        <?php echo $revenues_html; ?>
                        <div class="financial-total"><span>Total Revenues</span><span>$<?php echo number_format($total_revenues,2); ?></span></div>
                    </div>
                </div>

                <!-- Teacher Salaries -->
                <div class="financial-section">
                    <div class="section-header"><h4>Teacher Salaries</h4></div>
                    <div class="financial-items">
                        <div class="financial-total"><span>Total Salaries</span><span>$<?php echo number_format($teacher_salaries,2); ?></span></div>
                    </div>
                </div>

                <!-- Additional Losses -->
                <div class="financial-section">
                    <div class="section-header">
                        <h4>Additional Losses</h4>
                        <button class="add-loss-btn" onclick="addLossRow()">Add Loss</button>
                    </div>
                    <div class="financial-items" id="lossesContainer">
                        <?php echo $losses_html; ?>
                        <div class="financial-total">
                            <span>Total Additional Losses</span>
                            <span id="totalLossesAmt">$<?php echo number_format($total_losses, 2); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Summary -->
                <div class="financial-summary">
                    <div class="summary-item"><span>Total Revenues</span><span class="revenue">$<?php echo number_format($total_revenues,2); ?></span></div>
                    <div class="summary-item"><span>Total Expenses</span><span class="expense">$<?php echo number_format($total_expenses,2); ?></span></div>
                    <div class="summary-item net-profit"><span>Net Profit</span><span class="profit">$<?php echo number_format($net_profit,2); ?></span></div>
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

    // Add new loss row
    function addLossRow() {
        const container = document.getElementById('lossesContainer');
        const div = document.createElement('div');
        div.className = 'financial-item editable';
        div.innerHTML = `
            <input type='text' class='item-input' placeholder='Description of loss'>
            <input type='number' step='0.01' class='amount-input' placeholder='0.00'>
            <button class='delete-btn' onclick='this.parentElement.remove(); recalculate()'>Delete</button>
            <button class='save-btn' onclick='saveLoss()'>Save</button>
        `;
        container.insertBefore(div, container.querySelector('.financial-total'));
    }

    // Save loss
    function saveLoss(id = null) {
        let row;
        if (id) {
            row = document.querySelector(`.editable[data-id="${id}"]`);
        } else {
            row = event.target.parentElement;
        }

        const desc = row.querySelector('.item-input').value.trim();
        const amount = parseFloat(row.querySelector('.amount-input').value) || 0;

        if (!desc || amount <= 0) {
            Swal.fire('Error!', 'Please enter valid data', 'warning');
            return;
        }

        const fd = new FormData();
        fd.append('action', 'save_loss');
        fd.append('description', desc);
        fd.append('amount', amount);
        if (id) fd.append('id', id);

        fetch('', {
            method: 'POST',
            body: fd
        })
            .then(r => r.json())
            .then(res => {
                if(res.success) {
                    Swal.fire('Success!', 'Loss saved successfully', 'success');
                    recalculate();
                } else {
                    Swal.fire('Error!', res.message || 'Error saving data', 'error');
                }
            })
            .catch(err => {
                Swal.fire('Error!', 'Connection error occurred', 'error');
            });
    }

    // Delete loss
    function deleteLoss(id) {
        Swal.fire({
            title: 'Confirm deletion?',
            text: 'Are you sure you want to delete this loss?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                const fd = new FormData();
                fd.append('action', 'delete_loss');
                fd.append('id', id);
                fetch('', {
                    method: 'POST',
                    body: fd
                })
                    .then(r => r.json())
                    .then(res => {
                        if(res.success) {
                            location.reload();
                        } else {
                            Swal.fire('Error!', 'Error occurred while deleting', 'error');
                        }
                    });
            }
        });
    }

    // Calculate statistics
    function recalculate() {
        // Calculate total additional losses
        let totalLosses = 0;
        document.querySelectorAll('#lossesContainer .amount-input').forEach(input => {
            const val = parseFloat(input.value) || 0;
            totalLosses += val;
        });

        // Update additional losses display
        document.getElementById('totalLossesAmt').textContent = '$' + totalLosses.toFixed(2);

        // Data from PHP
        const totalRevenues = <?php echo $total_revenues; ?>;
        const teacherSalaries = <?php echo $teacher_salaries; ?>;

        // Final calculations
        const totalExpenses = teacherSalaries + totalLosses;
        const netProfit = totalRevenues - totalExpenses;

        // Update location
        document.querySelector('.revenue').textContent = '$' + totalRevenues.toFixed(2);
        document.querySelector('.expense').textContent = '$' + totalExpenses.toFixed(2);
        document.querySelector('.profit').textContent = '$' + netProfit.toFixed(2);
    }

    // Call function on page load
    document.addEventListener('DOMContentLoaded', recalculate);

    // Add event listeners
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('amount-input')) {
            recalculate();
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

    // Mark all notifications as read
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
                    // Hide badge and dots
                    const badge = document.querySelector('.notification-badge');
                    if (badge) badge.remove();
                    document.querySelectorAll('.notification-dot').forEach(el => {
                        el.remove();
                    });
                    document.querySelectorAll('.notification-item').forEach(item => {
                        item.classList.remove('unread');
                    });
                    Swal.fire('Success!', 'All notifications marked as read', 'success');
                }
            });
    }
</script>

</body>
</html>