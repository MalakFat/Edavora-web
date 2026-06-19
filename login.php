<?php

session_start();

// --- 1. PHPMailer Configuration ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Ensure the path to PHPMailer files is correct
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

// --- 2. Database Configuration ---
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "edavora";

// --- 3. Handle AJAX Password Reset Requests ---
if (isset($_POST['action'])) {

    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die(json_encode(['status' => 'error', 'message' => 'Database connection failed.']));
    }

    header('Content-Type: application/json');

    // **A. Request Code (First Stage)**
    if ($_POST['action'] == 'request_code' && isset($_POST['email'])) {

        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

        $stmt = $conn->prepare("SELECT email FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Email address not registered.']);
            $stmt->close();
            $conn->close();
            exit;
        }
        $stmt->close();

        // Generate random code and store in session
        $resetCode = rand(100000, 999999);
        $_SESSION['reset_code'] = $resetCode;
        $_SESSION['reset_email'] = $email;
        $_SESSION['code_expiry'] = time() + (10 * 60); // Code valid for 10 minutes

        // Send the code using PHPMailer
        $mail = new PHPMailer(true);

        try {
            // PHPMailer Settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'stumal2905@gmail.com';
            $mail->Password   = 'cxzmtmvqlptcwbfj';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // Ignore certificate verification for XAMPP
            $mail->SMTPOptions = array(
                    'ssl' => array(
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                    )
            );

            $mail->CharSet = 'UTF-8';
            $mail->setFrom('stumal2905@gmail.com', 'Edavora Academy');
            $mail->addAddress($email);

            $mail->isHTML(false);
            $mail->Subject = 'Your Password Reset Code';
            $mail->Body    = "Your password reset code is: {$resetCode}. \nThis code is valid for 10 minutes.";

            $mail->send();

            echo json_encode(['status' => 'success', 'message' => 'Verification code sent successfully.']);

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => "Email sending failed: {$mail->ErrorInfo}"]);
        }

    }
    // **B. Update Password (Second Stage)**
    elseif ($_POST['action'] == 'update_password' && isset($_POST['email'], $_POST['code'], $_POST['new_password'])) {

        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $code = $_POST['code'];
        $newPassword = $_POST['new_password'];

        // 1. Verify the code (from session)
        if (!isset($_SESSION['reset_code']) || $_SESSION['reset_email'] !== $email || $_SESSION['reset_code'] != $code || time() > $_SESSION['code_expiry']) {
            echo json_encode(['status' => 'error', 'message' => 'Verification code is incorrect or expired.']);
            $conn->close();
            exit;
        }

        // 2. Update Password
        $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $updateStmt->bind_param("ss", $newHashedPassword, $email);

        if ($updateStmt->execute()) {
            // 3. Clear session data after successful use
            unset($_SESSION['reset_code']);
            unset($_SESSION['reset_email']);
            unset($_SESSION['code_expiry']);

            echo json_encode(['status' => 'success', 'message' => 'Password updated successfully.']);
            $updateStmt->close();
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update password in the database.']);
        }

    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid AJAX request.']);
    }

    $conn->close();
    exit;
}

// --- 4. Handle Original Login Request ---

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['action'])) {

    $email = $_POST["email"];
    $password = $_POST["password"];

    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $stmt = $conn->prepare("SELECT email, password, firstname, lastname FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();

        if (password_verify($password, $row["password"]))  {

            // Check if student
            $checkStudent = $conn->prepare("SELECT email FROM students WHERE email = ?");
            $checkStudent->bind_param("s", $email);
            $checkStudent->execute();
            $studentResult = $checkStudent->get_result();
            if ($studentResult->num_rows === 1) {
                $_SESSION["email"] = $email;
                $_SESSION['user_type']= 'student';
                $_SESSION['firstname'] = $row["firstname"];
                $_SESSION['lastname'] = $row["lastname"];
                header("Location: student_home.php");
                exit;
            }

            // Check if teacher
            $checkTeacher = $conn->prepare("SELECT email FROM teachers WHERE email = ?");
            $checkTeacher->bind_param("s", $email);
            $checkTeacher->execute();
            $teacherResult = $checkTeacher->get_result();
            if ($teacherResult->num_rows === 1) {
                $_SESSION["email"] = $email;
                $_SESSION['user_type']= 'teacher';
                $_SESSION['firstname'] = $row["firstname"];
                $_SESSION['lastname'] = $row["lastname"];
                header("Location: teacher_home.php");
                exit;
            }

            // Must be manager
            $_SESSION["email"] = $email;
            $_SESSION['user_type']= 'manager';
            $_SESSION['firstname'] = $row["firstname"];
            $_SESSION['lastname'] = $row["lastname"];
            header("Location: ManagerHome.php");
            exit;

        } else {
            $error = "Incorrect email or password!";
        }

    } else {
        $error = "Incorrect email or password!";
    }

    $stmt->close();
    $conn->close();
}

?>


<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDVORA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/head.css">
    <link rel="icon" type="image/png" href="img/icon.png">

    <style>
        /* ... (Your CSS Styles remain here) ... */

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(rgba(0, 0, 0, 0.18), rgba(0,0,0,0.7)), url('img/main.png') center/cover no-repeat fixed;
            color: #fff;
            display: flex;
            justify-content:left;
            min-height: 100vh;
        }

        .container {
            background: rgba(203, 160, 203, 0.16);
            padding: 48px 40px;
            width: 500px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .title {
            font-size: 70px;
            font-weight: 700;
            margin: 0 0;
            text-align: center;
            background: linear-gradient(135deg, #fff 0%, #e2e8f0 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .subtitle {
            font-size: 18px;
            color: #94a3b8;
            text-align: center;
            margin: 0;
        }

        .forget-pass
        {
            text-align: center;
        }
        .forget-pass a {
            color: #64748b;
            font-size: 12px;
            text-decoration: none;
            font-weight: 500;
        }

        .social-links {
            text-align: center;
            margin-top: 100px;
        }
        .social-links a {
            color: #94a3b8;
            font-size: 20px;
            margin: 20px;
            text-decoration: none;
            transition: all 0.5s;
        }
        .social-links a:hover {
            color: #fff;
        }

        .input-wrapper {
            padding: 5px;
        }

        .group
        {
            margin-top:0px;
        }

        h4
        {
            color: #8b879e;
            align-items: center;
        }

        input {
            width: 100%;
            padding: 16px 16px 16px 40px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 16px;
            transition: all 0.7s;
        }

        input[type="password"] {
            padding-right: 50px;
        }

        input:focus {
            outline: none;
            border-color: #8e77c5;
            background: rgba(255, 255, 255, 0.08);
        }

        input::placeholder {
            color: #94a3b8;
        }

        .password-wrapper {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #94a3b8;
            font-size: 18px;
            user-select: none;
            transition: color 0.3s;
        }

        .toggle-password:hover {
            color: #fff;
        }

        .buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 24px;
        }

        .btn {
            padding: 14px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.5s;
            flex: 1;
            max-width: 200px;
        }

        .btn-create {
            background: #8e77c5;
            color: #fff;
        }

        .btn-create:hover {
            background: #665788;
        }
        .creat-link {
            text-align: center;
            color: #64748b;
            font-size: 14px;
            margin-top: 80px;
        }
        .creat-link a {
            color: #ffffff;
            text-decoration: none;
            font-weight: 500;
        }
        /* CSS specific to the Modal */
        #resetModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 30px;
            border: 1px solid #888;
            width: 80%;
            max-width: 400px;
            border-radius: 8px;
            color: #333;
            text-align: left; /* Changed alignment to LTR */
        }
        .modal-content input {
            width: 100%;
            margin-bottom: 10px;
            padding: 8px;
            border: 1px solid #ccc;
            color: #333;
            background: #fff;
            box-sizing: border-box;
        }
        .modal-content button {
            background-color: #8e77c5;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }
    </style>

</head>
<body>

<div class="container">
    <h1 class="title">EDVORA</h1>
    <p class="subtitle">Education Academy <br>Learn, grow, and step into a brighter future with us</p>
    <p class="creat-link"> Dont have an account? <a href="creat_account.php">Create account</a></p>

    <form action="" method="POST">
        <div class="group">
            <div class="input-wrapper">
                <input type="email" name="email" id="login_email" placeholder="Email" required
                       value="<?php echo isset($_POST['email']) ? $_POST['email'] : ''; ?>">
            </div>

            <div class="input-wrapper password-wrapper">
                <input type="password" name="password" placeholder="Password" id="password" required>
                <span class="toggle-password" onclick="togglePassword()">👁</span>
            </div>
        </div>

        <?php if (!empty($error)) { ?>
            <p style="color: #ff6b6b; text-align:center; font-size:14px;">
                <?php echo $error; ?>
            </p>
        <?php } ?>

        <div class="buttons">
            <button type="submit" class="btn btn-create">Login</button>
        </div>
    </form>

    <div class="forget-pass">
        <a href="#" onclick="requestPasswordReset(event)">Forgot your password? </a>
    </div>

    <div class="social-links">
        <a href="#"><i class="fab fa-facebook-f"></i></a>
        <a href="#"><i class="fab fa-instagram"></i></a>
        <a href="#"><i class="fab fa-linkedin-in"></i></a>
        <a href="#"><i class="fab fa-twitter"></i></a>
    </div>
</div>

<div id="resetModal">
    <div class="modal-content">
        <span onclick="document.getElementById('resetModal').style.display='none'" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
        <h3 style="text-align: center;">Reset Password</h3>
        <p id="modal_message" style="color: green; text-align: center; font-size: 14px;"></p>

        <form id="resetForm">
            <label for="reset_email">Email Address:</label>
            <input type="email" id="reset_email" name="email" required readonly>

            <label for="reset_code">Verification Code Sent to You:</label>
            <input type="text" id="reset_code" name="code" placeholder="Enter Code" required>

            <label for="new_password">New Password:</label>
            <input type="password" id="new_password" name="new_password" placeholder="New Password" required>

            <button type="submit">Update Password</button>
        </form>
    </div>
</div>


<script>

    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.querySelector('.toggle-password');
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.textContent = '👁';
        } else {
            passwordInput.type = 'password';
            toggleIcon.textContent = '👁';
        }
    }

    // --- Function to request code via AJAX ---
    function requestPasswordReset(event) {
        event.preventDefault();
        const emailInput = document.getElementById('login_email');
        const email = emailInput ? emailInput.value.trim() : '';

        if (!email) {
            alert("Please enter your email address in the login field first.");
            return;
        }

        const modalMessage = document.getElementById('modal_message');
        modalMessage.style.color = 'black';
        modalMessage.textContent = 'Sending verification code...';

        const xhr = new XMLHttpRequest();
        xhr.open("POST", "", true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

        xhr.onreadystatechange = function () {
            if (this.readyState === 4 && this.status === 200) {
                try {
                    const response = JSON.parse(this.responseText);
                    const modal = document.getElementById('resetModal');
                    const resetEmailInput = document.getElementById('reset_email');

                    if (response.status === 'success') {
                        modalMessage.style.color = 'green';
                        modalMessage.textContent = 'Verification code sent successfully to your email. (Valid for 10 minutes)';
                        resetEmailInput.value = email;
                        modal.style.display = 'flex';
                    } else {
                        modalMessage.style.color = 'red';
                        alert('Error: ' + response.message);
                    }
                } catch (e) {
                    modalMessage.style.color = 'red';
                    alert('An error occurred in server response. Please try again.');
                }
            }
        };
        xhr.send("email=" + encodeURIComponent(email) + "&action=request_code");
    }

    // --- Function to handle reset form submission (inside modal) ---
    document.getElementById('resetForm').addEventListener('submit', function(event) {
        event.preventDefault();

        const email = document.getElementById('reset_email').value;
        const code = document.getElementById('reset_code').value;
        const newPassword = document.getElementById('new_password').value;
        const modalMessage = document.getElementById('modal_message');

        modalMessage.style.color = 'black';
        modalMessage.textContent = 'Verifying and updating password...';

        const xhr = new XMLHttpRequest();
        xhr.open("POST", "", true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

        xhr.onreadystatechange = function () {
            if (this.readyState === 4 && this.status === 200) {
                try {
                    const response = JSON.parse(this.responseText);
                    if (response.status === 'success') {
                        modalMessage.style.color = 'green';
                        modalMessage.textContent = '✅ Password updated successfully! You can now log in.';
                        setTimeout(() => {
                            document.getElementById('resetModal').style.display = 'none';
                            window.location.reload();
                        }, 3000);
                    } else {
                        modalMessage.style.color = 'red';
                        modalMessage.textContent = '❌ Error: ' + response.message;
                    }
                } catch (e) {
                    modalMessage.style.color = 'red';
                    modalMessage.textContent = 'An error occurred in server response during update.';
                }
            }
        };
        xhr.send("email=" + encodeURIComponent(email) + "&code=" + encodeURIComponent(code) + "&new_password=" + encodeURIComponent(newPassword) + "&action=update_password");
    });
</script>

</body>
</html>