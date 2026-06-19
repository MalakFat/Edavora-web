// بيانات المعلمين والكورسات - إصدار منفصل
const eduTeachers = [
    {
        id: 1,
        name: "Ahmed Mohamed",
        courses: ["HTML/CSS", "JavaScript", "React"],
        image: "https://randomuser.me/api/portraits/men/32.jpg",
        position: "Senior Web Development Instructor"
    },
    {
        id: 2,
        name: "Fatima Ali",
        courses: ["Python", "Machine Learning", "Data Science"],
        image: "https://randomuser.me/api/portraits/women/44.jpg",
        position: "AI & Data Science Specialist"
    },
    {
        id: 3,
        name: "Khalid Ibrahim",
        courses: ["Java", "Android", "Kotlin"],
        image: "https://randomuser.me/api/portraits/men/22.jpg",
        position: "Mobile Development Expert"
    },
    {
        id: 4,
        name: "Sara Abdullah",
        courses: ["Python", "Data Analysis", "Statistics"],
        image: "https://randomuser.me/api/portraits/women/68.jpg",
        position: "Data Analytics Professor"
    },
    {
        id: 5,
        name: "Mohamed Hassan",
        courses: ["Network Security", "Ethical Hacking", "Cryptography"],
        image: "https://randomuser.me/api/portraits/men/75.jpg",
        position: "Cybersecurity Consultant"
    },
    {
        id: 6,
        name: "Noura Al-Kandari",
        courses: ["C#", "Unity", "Game Design"],
        image: "https://randomuser.me/api/portraits/women/26.jpg",
        position: "Game Development Lead"
    },
    {
        id: 7,
        name: "Yousef Ahmed",
        courses: ["HTML/CSS", "JavaScript", "React", "Node.js"],
        image: "https://randomuser.me/api/portraits/men/55.jpg",
        position: "Full-Stack Development Mentor"
    },
    {
        id: 8,
        name: "Layla Mohammed",
        courses: ["Java", "Kotlin", "Flutter"],
        image: "https://randomuser.me/api/portraits/women/63.jpg",
        position: "Cross-Platform Mobile Developer"
    }
];

// بيانات الطلاب - إصدار منفصل
const eduStudents = [
    {
        id: 1,
        name: "Ali Hassan",
        courses: ["HTML/CSS", "JavaScript"],
        image: "https://randomuser.me/api/portraits/men/41.jpg",
    },
    {
        id: 2,
        name: "Mona Salah",
        courses: ["Python", "Data Analysis", "Statistics"],
        image: "https://randomuser.me/api/portraits/women/33.jpg",
    },
    {
        id: 3,
        name: "Omar Khaled",
        courses: ["Java", "Android", "Kotlin", "Flutter"],
        image: "https://randomuser.me/api/portraits/men/67.jpg",

    },
    {
        id: 4,
        name: "Rana Ahmed",
        courses: ["HTML/CSS", "Python"],
        image: "https://randomuser.me/api/portraits/women/55.jpg",

    },
    {
        id: 5,
        name: "Hassan Mahmoud",
        courses: ["JavaScript", "React", "Node.js"],
        image: "https://randomuser.me/api/portraits/men/29.jpg",

    },
    {
        id: 6,
        name: "Lina Samir",
        courses: ["Machine Learning", "Data Science", "Python"],
        image: "https://randomuser.me/api/portraits/women/72.jpg",
    }
];

// بيانات الكورسات - إصدار منفصل
const eduCourses = [
    {
        id: 1,
        name: "HTML/CSS Fundamentals",
        duration: "4 weeks",
        students: 120,
        icon: "fas fa-code"
    },
    {
        id: 2,
        name: "JavaScript Mastery",
        duration: "6 weeks",
        students: 95,
        icon: "fab fa-js"
    },
    {
        id: 3,
        name: "Python for Beginners",
        duration: "5 weeks",

        students: 150,

        icon: "fab fa-python"
    },
    {
        id: 4,
        name: "React Development",

        duration: "6 weeks",

        students: 80,

        icon: "fab fa-react"
    },
    {
        id: 5,
        name: "Machine Learning",
        duration: "8 weeks",

        students: 65,

        icon: "fas fa-brain"
    },

];

// جميع الكورسات المتاحة
const allEduCourses = [...new Set(eduTeachers.flatMap(teacher => teacher.courses))];

// إنشاء فلتر الكورسات
function createEduCourseFilter() {
    const coursesFilter = document.getElementById('coursesFilter');
    if (!coursesFilter) return;

    coursesFilter.innerHTML = '';

    allEduCourses.forEach(course => {
        const courseCheckbox = document.createElement('div');
        courseCheckbox.className = 'course-checkbox';
        courseCheckbox.innerHTML = `
            <input type="checkbox" id="edu-${course}" class="course-checkbox-item">
            <label for="edu-${course}">${course}</label>
        `;
        coursesFilter.appendChild(courseCheckbox);
    });

    // إضافة مستمعي الأحداث
    const courseCheckboxes = document.querySelectorAll('.course-checkbox-item');
    courseCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', displayEduTeachers);
    });
}

// عرض قائمة المعلمين بناءً على الفلتر
function displayEduTeachers() {
    const teachersList = document.getElementById('teachersList');
    if (!teachersList) return;

    teachersList.innerHTML = '';

    // الحصول على الكورسات المحددة
    const selectedCourses = Array.from(document.querySelectorAll('.course-checkbox-item:checked'))
        .map(checkbox => checkbox.id.replace('edu-', ''));

    // إذا لم يتم تحديد أي كورس، عرض جميع المعلمين
    if (selectedCourses.length === 0) {
        eduTeachers.forEach(teacher => {
            createEduTeacherCard(teacher);
        });
        return;
    }

    // تصفية المعلمين الذين يدرسون جميع الكورسات المحددة
    const filteredTeachers = eduTeachers.filter(teacher => {
        return selectedCourses.every(course => teacher.courses.includes(course));
    });

    // عرض المعلمين المصفى
    if (filteredTeachers.length > 0) {
        filteredTeachers.forEach(teacher => {
            createEduTeacherCard(teacher);
        });
    } else {
        teachersList.innerHTML = `
            <div class="no-results">
                <i class="fas fa-search"></i>
                <p>No teachers found teaching all selected courses.</p>
                <p>Try selecting fewer courses.</p>
            </div>
        `;
    }
}

// عرض قائمة الطلاب
function displayEduStudents() {
    const studentsList = document.getElementById('studentsList');
    if (!studentsList) return;

    studentsList.innerHTML = '';

    if (eduStudents.length > 0) {
        eduStudents.forEach(student => {
            createEduStudentCard(student);
        });
    } else {
        studentsList.innerHTML = `
            <div class="no-results">
                <i class="fas fa-user-graduate"></i>
                <p>No students found.</p>
            </div>
        `;
    }
}

// عرض قائمة الكورسات
function displayEduCourses() {
    const coursesList = document.getElementById('coursesList');
    if (!coursesList) return;

    coursesList.innerHTML = '';

    if (eduCourses.length > 0) {
        eduCourses.forEach(course => {
            createEduCourseCard(course);
        });
    } else {
        coursesList.innerHTML = `
            <div class="no-results">
                <i class="fas fa-book"></i>
                <p>No courses found.</p>
            </div>
        `;
    }
}

// إنشاء بطاقة المعلم
function createEduTeacherCard(teacher) {
    const teachersList = document.getElementById('teachersList');
    const teacherCard = document.createElement('div');
    teacherCard.className = 'teacher-card';
    teacherCard.innerHTML = `
        <img src="${teacher.image}" alt="${teacher.name}" class="teacher-image">
        <h3 class="teacher-name">${teacher.name}</h3>
        <h6 class="teacher-specialty">${teacher.position}</h6>
        <div class="teacher-courses">
            ${teacher.courses.map(course => `<span class="course-tag">${course}</span>`).join('')}
        </div>
    `;
    teachersList.appendChild(teacherCard);
}

// إنشاء بطاقة الطالب
function createEduStudentCard(student) {
    const studentsList = document.getElementById('studentsList');
    const studentCard = document.createElement('div');
    studentCard.className = 'student-card';
    studentCard.innerHTML = `
        <img src="${student.image}" alt="${student.name}" class="student-image">
        <h3 class="student-name">${student.name}</h3>
        
        <div class="student-progress">
          
        </div>
        <div class="student-courses">
            ${student.courses.map(course => `<span class="course-tag">${course}</span>`).join('')}
        </div>
    `;
    studentsList.appendChild(studentCard);
}

// إنشاء بطاقة الكورس
function createEduCourseCard(course) {
    const coursesList = document.getElementById('coursesList');
    const courseCard = document.createElement('div');
    courseCard.className = 'course-card';
    courseCard.innerHTML = `
        <div class="course-icon">
            <i class="${course.icon}"></i>
        </div>
        <h3 class="course-name">${course.name}</h3>
      
        <div class="course-info">
            <div class="info-item">
                <span class="info-label">Duration</span>
                <span class="info-value">${course.duration}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Students</span>
                <span class="info-value">${course.students}</span>
            </div>
          
        </div>
    `;
    coursesList.appendChild(courseCard);
}

// تبديل المحتوى بناءً على العنصر المحدد
function initEduNavigation() {
    const menuItems = document.querySelectorAll('.menu-item');
    const contentSections = document.querySelectorAll('.content-section');

    menuItems.forEach(item => {
        item.addEventListener('click', () => {
            // إزالة النشاط من جميع العناصر
            menuItems.forEach(i => i.classList.remove('active'));
            // إضافة النشاط للعنصر المحدد
            item.classList.add('active');

            // إخفاء جميع الأقسام
            contentSections.forEach(section => section.classList.add('hidden'));

            // إظهار القسم المحدد
            const targetId = item.getAttribute('data-target');
            const targetSection = document.getElementById(targetId);
            if (targetSection) {
                targetSection.classList.remove('hidden');

                // عرض المحتوى المناسب
                if (targetId === 'teachers') {
                    displayEduTeachers();
                } else if (targetId === 'students') {
                    displayEduStudents();
                } else if (targetId === 'courses') {
                    displayEduCourses();
                }
            }
        });
    });
}

// تهيئة الصفحة عند التحميل
document.addEventListener('DOMContentLoaded', () => {
    initEduNavigation();
    createEduCourseFilter();
    displayEduTeachers();
});