<?php
session_start();

if (isset($_GET['course_id'])) {
    $_SESSION['course_id'] = intval($_REQUEST['course_id']);
    header("Location:CourseContent.php");
    exit;
}

echo "Course ID not found!";
?>
