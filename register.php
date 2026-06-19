<?php
session_start();

$Dataname = 'edavora';
$User = 'root';
$password = '';

$conn = new mysqli("localhost", $User, $password, $Dataname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // استلام البيانات
    $fname = $_POST['fname'];
    $lname = $_POST['lname'];
    $birth = $_POST['birth'];
    $gender = $_POST['gender'];
    $email = $_POST['email'];
    $pass = $_POST['password'];
    $conpass = $_POST['conpassword'];

    // تحقق من الصيغة
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: register.html?error=" . urlencode("Invalid email format"));
        exit;
    }

    // تحقق من تطابق الباسوورد
    if ($pass !== $conpass) {
        header("Location: creat_account.php?error=" . urlencode("Passwords do not match"));
        exit;
    }

    // تحقق من وجود الإيميل
    $check = $conn->prepare("SELECT email FROM users WHERE email=?");
    $check->bind_param("s", $email);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        header("Location: register.html?error=" . urlencode("This email is already registered"));
        exit;
    }

    // إدخال المستخدم
    $hashed = password_hash($pass, PASSWORD_DEFAULT);

    $insertUser = $conn->prepare("
        INSERT INTO users (firstname, lastname, birthdate, gender, email, password, profileimage)
        VALUES (?, ?, ?, ?, ?, ?, NULL)
    ");
    $insertUser->bind_param("ssssss", $fname, $lname, $birth, $gender, $email, $hashed);

    if ($insertUser->execute()) {

        // إضافة الطالب
        $insertStudent = $conn->prepare("INSERT INTO students (email, balance) VALUES (?, 150)");
        $insertStudent->bind_param("s", $email);
        $insertStudent->execute();

        $_SESSION['email'] = $email;

        header("Location: student_home.php");
        exit;

    } else {
        header("Location: creat_account.php?error=" . urlencode("Something went wrong"));
        exit;
    }
}
?>
