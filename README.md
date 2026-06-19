# Edavora Web

Edavora Web is a PHP and MySQL educational platform for managing programming courses, students, teachers, course content, attendance, notifications, and communication inside an academy-style learning environment.

The system provides separate experiences for managers, teachers, and students. Managers can manage users, courses, gallery content, platform statistics, and financial information. Teachers can manage their courses, lectures, attendance, course content, quizzes, and student progress. Students can browse available courses, enroll using their balance, view their courses, study lessons, answer quizzes, track progress, and receive notifications.

Developed by **Malak Fatayer** and **Yaqeen Shataat**.

## Features

- Role-based login for managers, teachers, and students
- Student registration and secure password hashing
- Password reset using email verification codes
- Manager dashboard for academy overview and statistics
- Teacher and student account management
- Course creation, editing, deletion, and teacher assignment
- Student enrollment with balance-based payment
- Balance recharge for students
- Course gallery and academy description management
- Teacher course dashboard
- Lecture scheduling and attendance tracking
- Zoom/course link management
- Email reminders for lectures using PHPMailer
- Course content builder with chapters, lessons, text/code content, and quizzes
- Student lesson progress tracking
- Quiz answer checking and course completion status
- Pass/fail course status tracking
- Notifications for enrollments, attendance, course status, and platform events
- Internal messaging between users
- Profile pages for managers, teachers, and students
- Upload support for profile images, course images, and gallery images
- Financial summary features for manager profile pages

## User Roles

### Manager

Managers can:

- Add teachers, students, and courses
- Update or delete teachers, students, and courses
- Reassign courses before deleting a teacher
- Remove students from courses and refund balances
- Send course notifications
- Update homepage gallery, statistics, and course description
- View financial summaries, salaries, income, and additional losses
- Manage their profile and password

Main manager pages:

- `ManagerHome.php`
- `ManagerProfile.php`
- `creat_TSC.php`
- `show_TSC.php`

### Teacher

Teachers can:

- View assigned courses
- Create lectures and track attendance
- Add and update Zoom/course meeting links
- Send lecture reminders by email
- Build course content using chapters and lessons
- Add text content, code content, and lesson quizzes
- Monitor student enrollment and status notifications
- Manage their profile and password

Main teacher pages:

- `teacher_home.php`
- `TeacherCourses.php`
- `teacherCourseContent.php`
- `teacher_show.php`
- `TeacherProfile.php`

### Student

Students can:

- Register and log in
- Browse available programming courses
- Enroll in courses using account balance
- Recharge balance
- View enrolled courses
- Open lessons and course content
- Submit quiz answers
- Track course progress
- Receive course and system notifications
- Message teachers/managers
- Manage their profile and password

Main student pages:

- `student_home.php`
- `student_add_course.php`
- `student_mycourse.php`
- `CourseContent.php`
- `studentprofile.php`

## Project Structure

```text
Edavora-web/
|-- config/
|   `-- db_connection.php
|-- css/
|-- img/
|-- js/
|-- PHPMailer/
|-- uploads/
|-- login.php
|-- register.php
|-- ManagerHome.php
|-- ManagerProfile.php
|-- TeacherCourses.php
|-- teacherCourseContent.php
|-- CourseContent.php
|-- student_home.php
|-- student_add_course.php
|-- student_mycourse.php
`-- README.md
```

## Technologies Used

- PHP
- MySQL
- HTML
- CSS
- JavaScript
- PHPMailer
- Apache/XAMPP-style local server environment

## Requirements

- PHP 8 or newer
- MySQL or MariaDB
- Apache server, such as XAMPP
- A browser
- SMTP account/app password if email features are enabled

## Installation and Setup

Clone the repository:

```bash
git clone https://github.com/MalakFat/Edavora-web.git
```

Move the project folder into your local web server directory, for example:

```text
xampp/htdocs/Edavora-web
```

Create a MySQL database named:

```sql
CREATE DATABASE edavora CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

Update the database connection if needed in:

```text
config/db_connection.php
```

Default local configuration:

```php
$host = "localhost";
$username = "root";
$password = "";
$database = "edavora";
```

Import or create the required database tables before running the platform. The current code expects tables such as:

- `users`
- `students`
- `teachers`
- `manager`
- `courses`
- `study`
- `studentcoursestatus`
- `notifications`
- `messages`
- `gallery`
- `course_info`
- `statistics`
- `lectures`
- `attendance`
- `chapters`
- `lessons`
- `lesson_contents`
- `lesson_quizzes`
- `studentanswer`
- `student_progress`
- `student_certificates`
- `additional_losses`

Start Apache and MySQL, then open:

```text
http://localhost/Edavora-web/login.php
```

## Email Configuration

The project uses PHPMailer for:

- Password reset verification codes
- Lecture reminder emails

Before deploying or sharing the project publicly, configure SMTP credentials in the email-related PHP files and keep sensitive credentials outside the source code when possible.

Relevant files include:

- `login.php`
- `TeacherCourses.php`

## Main Workflow

1. A user logs in from `login.php`.
2. The system checks whether the email belongs to a student, teacher, or manager.
3. The user is redirected to the correct dashboard.
4. Managers create teachers, students, and courses.
5. Students browse and enroll in courses.
6. Teachers manage lectures, attendance, and course content.
7. Students complete lessons and quizzes.
8. Notifications and messages keep users updated.

## File Uploads

The application stores uploaded images in the `uploads/` directory, including:

- Student profile images
- Teacher/manager profile images
- Course images
- Gallery images

Make sure the web server has permission to write to the upload folders.

## Security Notes

- Passwords are stored using PHP password hashing.
- Sessions are used to protect logged-in pages.
- Database credentials and SMTP credentials should be protected in production.
- Public deployments should move secrets to environment variables or private configuration files.
- Validate uploaded files and restrict upload types before production use.

## Authors

- Malak Fatayer
- Yaqeen Shataat

## License

This project was created for academic and educational purposes.
