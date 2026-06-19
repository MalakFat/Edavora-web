<?php
session_start();

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (isset($_SESSION['user_email'])) {
    $user_email = $_SESSION['user_email'];
    error_log("User $user_email logged out at " . date('Y-m-d H:i:s'));
}

// إلغاء جميع متغيرات الجلسة
$_SESSION = array();

// إذا تم استخدام كوكي الجلسة، قم بحذفه
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 86400, '/');
}

// تدمير الجلسة
session_destroy();

// الانتظار قليلاً قبل التوجيه للتأكد من تدمير الجلسة
sleep(1);

// توجيه إلى صفحة تسجيل الدخول
header("Location: login.php");
exit();
?>