<?php
session_start();
require_once 'config/db_connection.php';



if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $amount = floatval($input['amount']);
    $student_email = $_SESSION['email'];

    if ($amount <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid amount"]);
        exit();
    }

    // تحديث الرصيد
    $update_query = "UPDATE students SET balance = balance + ? WHERE email = ?";
    $host = "localhost";
    $username = "root";
    $password = "";
    $database = "edavora";

// Create connection
    $conn = new mysqli($host, $username, $password, $database);

// Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

// Set charset to UTF-8
    $conn->set_charset("utf8mb4");
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("ds", $amount, $student_email);

    if ($stmt->execute()) {
        // الحصول على الرصيد الجديد
        $balance_query = "SELECT balance FROM students WHERE email = ?";
        $balance_stmt = $conn->prepare($balance_query);
        $balance_stmt->bind_param("s", $student_email);
        $balance_stmt->execute();
        $balance_result = $balance_stmt->get_result();
        $new_balance = $balance_result->fetch_assoc()['balance'];

        // تحديث الجلسة
        $_SESSION['balance'] = $new_balance;

        // إضافة إشعار
        $notification_query = "INSERT INTO notifications (user_email, notification_text) 
                              VALUES (?, ?)";
        $notification_stmt = $conn->prepare($notification_query);
        $notification_text = "Your balance has been recharged with $" . number_format($amount, 2);
        $notification_stmt->bind_param("ss", $student_email, $notification_text);
        $notification_stmt->execute();

        echo json_encode([
            "success" => true,
            "message" => "Balance recharged successfully! New balance: $" . number_format($new_balance, 2)
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to recharge balance"]);
    }
}
?>